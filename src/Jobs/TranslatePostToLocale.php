<?php

declare(strict_types=1);

namespace Core45\AiPosts\Jobs;

use Astrotomic\Translatable\Contracts\Translatable;
use Core45\AiCore\Contracts\AiClient;
use Core45\AiCore\Contracts\NotifiesOperator;
use Core45\AiCore\Enums\AiSurface;
use Core45\AiCore\Enums\NotificationSeverity;
use Core45\AiCore\Exceptions\AiBudgetExceededException;
use Core45\AiCore\Exceptions\AiRateLimitedException;
use Core45\AiCore\ModelRegistry;
use Core45\AiCore\Services\AiClientFactory;
use Core45\AiPosts\Services\PostContentRewriteService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;

class TranslatePostToLocale implements ShouldQueue
{
    use Queueable;

    public int $tries = 10;

    public int $maxExceptions = 3;

    /** @var array<int, int> */
    public array $backoff = [10, 30, 60];

    public int $timeout = 3600;

    public readonly string $operationId;

    public function __construct(
        public Model $post,
        public string $sourceLocale,
        public string $targetLocale,
        public ?int $userId = null,
        public ?string $lockToken = null,
    ) {
        $this->onQueue('ai-bulk');
        $this->operationId = (string) Str::uuid();
    }

    /**
     * NOTE (08-08 deviation, plan-documented): `$client` is resolved via method
     * injection to satisfy SEAM-08's AiClient seam gate, but is not yet called.
     * This job's actual AI work runs entirely through
     * `PostContentRewriteService::translateToLocale()`, which is model-bound
     * (Eloquent `Post` + multi-field results) and has no analog on the flat
     * `AiClient::translate(TranslationRequest)` contract (text-in/text-out
     * only, IDs + plain data). Routing the rewrite-service's internal call
     * through `AiClient` is owned by plan 08-15 (content-rewrite hierarchy).
     * `$this->operationId` is minted here (once, at dispatch, reused across
     * retries) so 08-15 can thread it into the rewrite service without a
     * second refactor of this job.
     */
    /**
     * NOTE (13-05, CONTRACT-03; superseded by 48-11): the per-FAQ translation
     * in `translatePostFaqs()` now runs through `runPrompt('translate.post_faqs')`
     * on `AiSurface::BlogTopics` (v2.6 design §2.5 — phasea owns the prompt),
     * still the synchronous surface this job's immediate parse-the-JSON flow
     * needs. It was previously `completeText()` with a host-authored instruction
     * string; that string moved to phasea. The model-bound bulk BODY translation
     * still delegates to `PostContentRewriteService::translateToLocale()`, which
     * stays on `completeText()` (the `AiSurface::Translate` chunking path owned by
     * phases 41–43) under the D-48-4 carve-out — intentionally out of scope here.
     *
     * Per-FAQ operation IDs (13-REVIEW IN-08) are DETERMINISTIC UUIDv5s
     * derived from `$this->operationId` (namespace) + the FAQ id, so job
     * retries (tries=10) replay the same IDs and phasea's idempotency cache
     * answers already-translated FAQs from cache instead of re-billing the
     * LLM — restoring the "minted once, reused across retries" property the
     * class-level operationId docblock promises. Unparseable LLM responses
     * are skipped-and-logged, never persisted (the old fallback wrote the
     * SOURCE-locale text into the target translation).
     */
    public function handle(AiClientFactory $factory, PostContentRewriteService $service): void
    {
        $lockClass = app(ModelRegistry::class)->getModelClassOrFail('translation_lock');

        /** @phpstan-ignore staticMethod.notFound */
        if ($this->lockToken && $lockClass::isCancelledByToken($this->lockToken)) {
            return;
        }

        Log::info('TranslatePostToLocale: Starting', [
            'post_id' => $this->post->getKey(),
            'source' => $this->sourceLocale,
            'target' => $this->targetLocale,
            'user_id' => $this->userId,
        ]);

        $service->forTranslation();

        // Thread the job's operationId (minted once at dispatch, stable
        // across retries) so the service derives deterministic per-field
        // UUIDv5 ids — retries replay identical ids and phasea's usage
        // metering does not re-bill already-translated fields (audit 5.4).
        $service->setOperationId($this->operationId);

        if ($this->userId !== null) {
            $service->setUserId($this->userId);
        }

        if ($this->lockToken) {
            /** @phpstan-ignore staticMethod.notFound */
            $service->onCancellation(fn (): bool => $lockClass::isCancelledByToken($this->lockToken));
        }

        try {
            $result = $service->translateToLocale(
                $this->post,
                $this->sourceLocale,
                $this->targetLocale,
                overwrite: true,
            );

            $hasErrors = $result['errors'] !== [];
            $hasProcessed = $result['processed_fields'] !== [];

            Log::info('TranslatePostToLocale: Completed', [
                'post_id' => $this->post->getKey(),
                'processed_fields' => count($result['processed_fields']),
                'errors' => count($result['errors']),
            ]);

            // The body is the critical field. If it failed (e.g. an HTTP timeout on a long
            // post), throw so the job's backoff/retries run instead of silently leaving the
            // target locale without a body. Minor field errors fall through to the partial
            // notification below.
            if ($this->bodyTranslationFailed($result['errors'])) {
                throw new \RuntimeException(
                    "Body translation failed for post {$this->post->getKey()} ({$this->sourceLocale} -> {$this->targetLocale})"
                );
            }

            // Detect when body was silently skipped (e.g. due to cancellation between
            // fields) — the source has content but main_text is absent from both
            // processed_fields and errors. Surface it as a partial-completion warning
            // instead of a misleading success notification.
            if ($this->bodySkippedButExpected($result)) {
                $result['errors'][] = "main_text:{$this->targetLocale} - Body translation was skipped";
                $hasErrors = true;
            }

            // Translate attached FAQs independently of post field errors
            $this->translatePostFaqs($factory->for(AiSurface::BlogTopics));

            $postTitle = $this->getPostTitle();

            if ($hasErrors && !$hasProcessed) {
                $this->notifyUser(
                    __('Translation failed'),
                    __('Failed to translate post ":title" from :source to :target.', [
                        'title' => $postTitle,
                        'source' => $this->sourceLocale,
                        'target' => $this->targetLocale,
                    ]),
                    'danger',
                );

                return;
            }

            if ($hasErrors) {
                $fields = $this->extractFieldNames($result['errors']);
                $this->notifyUser(
                    __('Translation partially completed'),
                    __('Post ":title" was partially translated to :target. Failed fields: :fields', [
                        'title' => $postTitle,
                        'target' => $this->targetLocale,
                        'fields' => $fields,
                    ]),
                    'warning',
                );

                return;
            }

            if ($this->lockToken) {
                /** @phpstan-ignore staticMethod.notFound */
                $lockClass::releaseByToken($this->lockToken);
            }

            $this->notifyUser(
                __('Translation completed'),
                __('Post ":title" has been translated from :source to :target.', [
                    'title' => $postTitle,
                    'source' => $this->sourceLocale,
                    'target' => $this->targetLocale,
                ]),
                'success',
            );
        } catch (AiRateLimitedException $e) {
            $retryAfter = $e->retryAfter > 0 ? $e->retryAfter : 60;

            Log::warning('TranslatePostToLocale: Rate limited, releasing back to queue', [
                'post_id' => $this->post->getKey(),
                'target_locale' => $this->targetLocale,
                'retry_after_seconds' => $retryAfter,
            ]);

            $this->release($retryAfter);
        } catch (AiBudgetExceededException $e) {
            // Budget exhaustion will not clear within this job's backoff
            // window — retrying burns attempts for nothing. Fail immediately
            // so failed() notifies the user right away (audit §6).
            Log::warning('TranslatePostToLocale: AI budget exceeded, failing immediately', [
                'post_id' => $this->post->getKey(),
                'target_locale' => $this->targetLocale,
            ]);

            $this->fail($e);
        }
    }

    public function failed(?\Throwable $exception): void
    {
        if ($this->lockToken) {
            $lockClass = app(ModelRegistry::class)->getModelClassOrFail('translation_lock');
            /** @phpstan-ignore staticMethod.notFound */
            $lockClass::restoreSnapshot($this->lockToken);
            /** @phpstan-ignore staticMethod.notFound */
            $lockClass::releaseByToken($this->lockToken);
        }

        Log::error('TranslatePostToLocale: Permanently failed', [
            'post_id' => $this->post->getKey(),
            'source' => $this->sourceLocale,
            'target' => $this->targetLocale,
            'error' => $exception?->getMessage(),
        ]);

        $this->notifyUser(
            __('Translation failed'),
            __('Failed to translate post ":title" from :source to :target: :error', [
                'title' => $this->getPostTitle(),
                'source' => $this->sourceLocale,
                'target' => $this->targetLocale,
                'error' => $exception?->getMessage() ?? 'Unknown error',
            ]),
            'danger',
        );
    }

    private function getPostTitle(): string
    {
        $translation = $this->translation($this->sourceLocale);

        return $translation->title ?? "#{$this->post->getKey()}";
    }

    /**
     * Guard the Astrotomic `translate()` call behind the seam — the model is
     * typed against `Model`, so callers cannot assume Translatable.
     */
    private function translation(string $locale): ?Model
    {
        if (!$this->post instanceof Translatable) {
            return null;
        }

        return $this->post->translate($locale);
    }

    /**
     * Translate each attached FAQ's source-locale question/answer to the
     * target locale via `runPrompt('translate.post_faqs', …)` on the BlogTopics
     * surface (v2.6 design §2.5) — phasea now owns the translator instruction;
     * this host sends only `source_locale`, `target_locale` and the FAQ as a
     * single-item structured list. The instruction string that used to live
     * here is gone.
     *
     * D-48-4 BOUNDARY: only THIS short, synchronous FAQ translation migrated.
     * The long post BODY translation stays on `completeText()` via
     * `PostContentRewriteService` (the `AiSurface::Translate` chunking path
     * owned by phases 41–43) — see `handle()`'s `translateToLocale()` call.
     *
     * The per-FAQ operation id (13-05 / IN-08) is preserved verbatim: a
     * deterministic UUIDv5 derived from the job's `operationId`, so retries
     * replay identical ids and phasea's idempotency cache answers
     * already-translated FAQs from cache instead of re-billing. The
     * single-object response parse (`{question, answer}`) is unchanged.
     */
    private function translatePostFaqs(AiClient $translateClient): void
    {
        $faqs = $this->post->faqs()->get(); // @phpstan-ignore method.notFound

        if ($faqs->isEmpty()) {
            return;
        }

        foreach ($faqs as $faq) {
            $sourceTranslation = $faq->translate($this->sourceLocale);
            if ($sourceTranslation === null) {
                continue;
            }

            $question = $sourceTranslation->question ?? '';
            $answer = $sourceTranslation->answer ?? '';

            if ($question === '' && $answer === '') {
                continue;
            }

            try {
                $result = $translateClient->runPrompt(
                    'translate.post_faqs',
                    [
                        'source_locale' => $this->sourceLocale,
                        'target_locale' => $this->targetLocale,
                        // One FAQ per call: the single-object response parser
                        // below and the deterministic per-FAQ id require it.
                        'faq_items' => [
                            ['question' => $question, 'answer' => $answer],
                        ],
                    ],
                    // Deterministic per-FAQ id (IN-08): same across retries so
                    // phasea's idempotency cache prevents re-billing; unique
                    // per FAQ so entries never collide within one job run.
                    Uuid::uuid5($this->operationId, "faq:{$faq->id}")->toString(),
                    // Transport-only: the per-FAQ 300s assistant budget the
                    // completeText path carried, kept as a runPrompt option and
                    // never serialised into the request body.
                    ['timeout' => (int) config('ai.assistant.timeout', 300)],
                );

                $cleaned = preg_replace('/```(?:json)?\s*/', '', $result->text);
                $cleaned = trim((string) $cleaned);
                $data = [];
                if (preg_match('/\{[\s\S]*\}/', $cleaned, $matches)) {
                    $decoded = json_decode($matches[0], true);
                    if (is_array($decoded)) {
                        $data = $decoded;
                    }
                }

                $translatedQuestion = $data['question'] ?? null;
                $translatedAnswer = $data['answer'] ?? null;

                // Unparseable response (IN-08): skip-and-log. The old
                // `$data['question'] ?? $question` fallback persisted the
                // SOURCE-locale text as the target translation.
                if (!is_string($translatedQuestion) || !is_string($translatedAnswer)) {
                    Log::warning('TranslatePostToLocale: Unparseable FAQ translation response — not persisted', [
                        'faq_id' => $faq->id,
                        'target_locale' => $this->targetLocale,
                        'response_length' => strlen($result->text),
                    ]);

                    continue;
                }

                $targetTranslation = $faq->translateOrNew($this->targetLocale);
                $targetTranslation->question = $translatedQuestion;
                $targetTranslation->answer = $translatedAnswer;
                $faq->save();
            } catch (AiBudgetExceededException $e) {
                // Tenant-global condition — remaining FAQs cannot succeed
                // either; propagate to the handle()-level fail-fast (audit §6).
                throw $e;
            } catch (\Throwable $e) {
                Log::warning('TranslatePostToLocale: Failed to translate FAQ', [
                    'faq_id' => $faq->id,
                    'target_locale' => $this->targetLocale,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Whether the main_text (body) field is among the reported field errors.
     *
     * @param  array<int, string>  $errors
     */
    private function bodyTranslationFailed(array $errors): bool
    {
        foreach ($errors as $error) {
            if (str_starts_with($error, 'main_text:')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the source has body content but main_text was neither processed nor errored.
     *
     * @param  array{processed_fields: array<int, string>, errors: array<int, string>}  $result
     */
    private function bodySkippedButExpected(array $result): bool
    {
        $bodyProcessed = in_array("main_text:{$this->targetLocale}", $result['processed_fields'], true);

        if ($bodyProcessed || $this->bodyTranslationFailed($result['errors'])) {
            return false;
        }

        $sourceTranslation = $this->translation($this->sourceLocale);

        return $sourceTranslation !== null && !empty($sourceTranslation->main_text);
    }

    /**
     * @param  array<int, string>  $errors
     */
    private function extractFieldNames(array $errors): string
    {
        return collect($errors)
            ->map(function (string $error): string {
                $parts = explode(' - ', $error, 2);

                return trim($parts[0]);
            })
            ->unique()
            ->implode(', ');
    }

    private function notifyUser(string $title, string $body, string $status): void
    {
        if ($this->userId === null) {
            return;
        }

        $userClass = app(ModelRegistry::class)->getModelClassOrFail('user');
        $user = $userClass::find($this->userId);
        if ($user === null) {
            return;
        }

        app(NotifiesOperator::class)->notifyOperator(
            $user,
            $title,
            $body,
            NotificationSeverity::fromStatus($status),
        );
    }
}
