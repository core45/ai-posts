<?php

declare(strict_types=1);

namespace Core45\AiPosts\Services\BlogPipeline;

use Core45\AiCore\Enums\AiSurface;
use Core45\AiCore\Exceptions\AiRateLimitedException;
use Core45\AiCore\ModelRegistry;
use Core45\AiCore\Services\AiClientFactory;
use Core45\AiCore\Traits\LogsAIOperations;
use Core45\AiPosts\Contracts\Seo\ScansInternalLinks;
use Core45\AiPosts\DTOs\BlogPipeline\ModuleContext;
use Core45\AiPosts\DTOs\BlogPipeline\ModuleOutput;
use Core45\AiPosts\DTOs\BlogPipeline\QualityResult;
use Core45\AiPosts\Enums\BlogTopicLlmStep;
use Core45\AiPosts\Enums\QualityDecision;
use Core45\AiPosts\Models\BlogPostPipelineRun;
use Core45\AiPosts\Models\ContentModule;
use Core45\AiPosts\Services\BlogPipeline\Modules\AbstractContentModule;
use Core45\AiPosts\Services\BlogTopics\LlmAuditService;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Throwable;

class ModuleExecutor
{
    use LogsAIOperations;

    /**
     * v2.6: phasea routes every blog-pipeline call and selects its own model —
     * the host never chooses, so `provider`/`model` are honest audit labels,
     * not a resolved setting, and are never forwarded to `completeText()`.
     */
    private const string PROVIDER_LABEL = 'phasea';

    private const string MODEL_LABEL = 'unknown';

    /**
     * v2.6 (design §2.5, plan 48-15): the module instruction lives in phasea. The
     * host names this template and posts a slug + placeholder VALUES + any tenant
     * override; it assembles no prompt text itself.
     */
    private const string MODULE_TEMPLATE = 'blog_pipeline.module';

    /**
     * v2.6 (plan 48-15): the fast module summariser instruction lives in phasea
     * too. The host sends the bounded word count and the content as parameters.
     */
    private const string SUMMARY_TEMPLATE = 'blog_pipeline.module_summary';

    public function __construct(
        private readonly ModuleResolver $moduleResolver,
        private readonly LlmAuditService $auditService,
        private readonly QualityGateService $qualityGate,
        private readonly PipelineCallMemo $callMemo,
        private readonly ModulePayloadGuard $payloadGuard,
    ) {}

    /**
     * Execute a single content module within a pipeline run.
     *
     * @param  array<string, mixed>|null  $moduleConfig  Per-module config from profile (reserved for future per-module overrides)
     */
    public function execute(BlogPostPipelineRun $run, string $moduleSlug, ?array $moduleConfig = null): ModuleOutput
    {
        $module = $this->moduleResolver->resolve($moduleSlug);
        $context = $module->prepare($run);
        $payload = $module->buildPayload($run, $context);

        $output = $this->executeOnce($run, $module, $moduleSlug, $payload, self::PROVIDER_LABEL, self::MODEL_LABEL, 1);
        $output = $this->extractCitationsIfEnabled($output, $context);

        $contentModule = ContentModule::query()
            ->where('slug', $moduleSlug)
            ->first();

        $qualityResult = null;
        $qualityScore = null;
        $qualityDecision = QualityDecision::Pass->value;

        if ($contentModule instanceof ContentModule) {
            $qualityResult = $this->qualityGate->evaluate($output, $contentModule, $run);
            $qualityScore = $qualityResult->overallScore;
            $qualityDecision = $qualityResult->decision->value;

            $run->appendQualityLog([
                'module_slug' => $moduleSlug,
                'timestamp' => now()->toIso8601String(),
                'scores' => $qualityResult->scores,
                'overall_score' => $qualityResult->overallScore,
                'issues' => $qualityResult->issues,
                'decision' => $qualityDecision,
                'attempt' => 1,
                'provider' => $output->provider,
                'model' => $output->model,
            ]);
        }

        $this->persistOutput($run, $moduleSlug, $output, $output->provider, $output->model, $qualityScore, $qualityDecision);

        return $output;
    }

    /**
     * Execute a module with automatic retry on quality failures.
     *
     * There is no host-side "escalation" to a better model anymore — phasea
     * owns model selection end to end. What used to be a switch to a
     * stronger LLM is now one additional plain retry (same call path, same
     * accumulated feedback) before the run is paused for human review.
     *
     * @param  array<string, mixed>|null  $moduleConfig  Per-module config from profile (reserved for future per-module overrides)
     */
    public function executeWithEscalation(BlogPostPipelineRun $run, string $moduleSlug, ?array $moduleConfig = null): ModuleOutput
    {
        $module = $this->moduleResolver->resolve($moduleSlug);
        $contentModule = ContentModule::query()
            ->where('slug', $moduleSlug)
            ->first();

        $maxRetries = $this->resolveMaxRetryAttempts($run);
        $attempt = 1;

        $provider = self::PROVIDER_LABEL;
        $model = self::MODEL_LABEL;
        $context = $module->prepare($run);
        $payload = $module->buildPayload($run, $context);
        $feedbackHistory = [];

        $output = $this->executeOnce($run, $module, $moduleSlug, $payload, $provider, $model, $attempt);
        $output = $this->extractCitationsIfEnabled($output, $context);

        $qualityResult = $this->evaluateIfPossible($output, $contentModule, $run, $moduleSlug, $attempt, $output->provider, $output->model);

        if ($qualityResult === null || $qualityResult->decision === QualityDecision::Pass) {
            $this->persistFinalOutput($run, $moduleSlug, $output, $output->provider, $output->model, null, $qualityResult, $attempt);

            return $output;
        }

        if ($qualityResult->decision === QualityDecision::RetrySame) {
            $feedbackHistory = array_merge($feedbackHistory, $qualityResult->issues);

            for ($retryAttempt = 2; $retryAttempt <= $maxRetries; $retryAttempt++) {
                $attempt = $retryAttempt;
                $retryPayload = $this->withQualityFeedback($payload, $feedbackHistory);

                $output = $this->executeOnce($run, $module, $moduleSlug, $retryPayload, $provider, $model, $attempt);
                $output = $this->extractCitationsIfEnabled($output, $context);
                $qualityResult = $this->evaluateIfPossible($output, $contentModule, $run, $moduleSlug, $attempt, $output->provider, $output->model);

                if ($qualityResult === null || $qualityResult->decision === QualityDecision::Pass) {
                    $this->persistFinalOutput($run, $moduleSlug, $output, $output->provider, $output->model, null, $qualityResult, $attempt);

                    return $output;
                }

                $feedbackHistory = array_merge($feedbackHistory, $qualityResult->issues);
            }
        }

        $attempt++;

        Log::info('ModuleExecutor: final retry attempt after quality failures', [
            'pipeline_run_id' => $run->id,
            'module_slug' => $moduleSlug,
            'attempt' => $attempt,
        ]);

        $this->notifyFinalRetry($run, $moduleSlug);

        $finalPayload = $this->withQualityFeedback($payload, $feedbackHistory);
        $output = $this->executeOnce($run, $module, $moduleSlug, $finalPayload, $provider, $model, $attempt);
        $output = $this->extractCitationsIfEnabled($output, $context);
        $qualityResult = $this->evaluateIfPossible($output, $contentModule, $run, $moduleSlug, $attempt, $output->provider, $output->model);

        if ($qualityResult === null || $qualityResult->decision === QualityDecision::Pass) {
            $this->persistFinalOutput($run, $moduleSlug, $output, $output->provider, $output->model, null, $qualityResult, $attempt);

            return $output;
        }

        $decision = QualityDecision::Pause;
        $this->persistFinalOutput($run, $moduleSlug, $output, $output->provider, $output->model, $decision, $qualityResult, $attempt);

        return $output;
    }

    /**
     * Execute a single LLM call. Under `remote`, phasea owns resilience; the
     * job's own queue-level retry/backoff (ReleaseOnProviderRateLimit) handles
     * transient failures, so no client-side provider walk is needed here.
     *
     * @param  array<string, mixed>  $payload  The `blog_pipeline.module` parameter payload.
     */
    private function executeOnce(
        BlogPostPipelineRun $run,
        AbstractContentModule $module,
        string $moduleSlug,
        array $payload,
        string $provider,
        string $model,
        int $attempt,
    ): ModuleOutput {
        return $this->callLlm($run, $module, $moduleSlug, $payload, $provider, $model, $attempt);
    }

    /**
     * @param  array<string, mixed>  $payload  The `blog_pipeline.module` parameter payload.
     */
    private function callLlm(
        BlogPostPipelineRun $run,
        AbstractContentModule $module,
        string $moduleSlug,
        array $payload,
        string $provider,
        string $model,
        int $attempt,
    ): ModuleOutput {
        // THE BOUNDARY, AND IT IS INSIDE THIS METHOD BECAUSE THIS IS THE ONLY
        // PLACE THAT SEES THE PAYLOAD AS SENT (plan 48-24, F4/F5).
        //
        // The first draft asserted on the BASE payload up in the two public
        // methods, reasoning that every retry reuses it verbatim apart from
        // `quality_feedback`. That is true of the COMPLETENESS half and false of
        // the SIZE half: `$feedbackHistory` accumulates across quality attempts,
        // so the body on the third attempt is strictly larger than the one that
        // was measured — and the guard's own claim to be the last thing that looks
        // at a payload before it is billed was not true, because
        // `withQualityFeedback()` ran after it. Here it holds for both halves, on
        // every attempt, for every module class `ModuleResolver` can produce.
        $this->payloadGuard->assertBillable($payload, $module);

        $startTime = microtime(true);

        // THE OPERATION ID IS DERIVED, NOT MINTED (plan 48-24, F2). This line used
        // to read `(string) Str::uuid()` inline in the `runPrompt()` argument list,
        // so each of `ExecuteModuleJob`'s five queue attempts sent a DIFFERENT id
        // for the same logical call and phasea's ledger could not see the second
        // as a duplicate of the first. The provider answers BEFORE any bookkeeping
        // below, so any failure in that window re-billed a completion that had
        // already been generated and paid for.
        //
        // THE ATTEMPT NUMBER BELONGS IN THE SCOPE KEY. Quality retries are
        // genuinely separate billed calls and must not collide; a JOB retry
        // re-enters at attempt 1 and therefore re-derives attempt 1's id.
        //
        // {@see PipelineCallMemo} explains why the durable memo — not phasea's
        // ledger — is what actually makes the retry succeed: phasea REFUSES a
        // duplicate (409), it does not hand back the earlier completion.
        $call = $this->callMemo->remember(
            $run,
            $moduleSlug,
            "module:{$moduleSlug}:attempt:{$attempt}",
            function (string $operationId) use ($payload): array {
                // v2.6 (design §2.5, plan 48-15): phasea owns the module
                // instruction. The host names the template and posts a slug +
                // placeholder VALUES + any tenant override — no `system`/`user`
                // prompt string leaves the host. The per-module timeout rides
                // `runPrompt`'s transport-only options, so this surface keeps its
                // own budget instead of the 30s default. Route through the
                // per-surface factory so AI_SURFACE_BLOG_PIPELINE_DRIVER is honored.
                $client = app(AiClientFactory::class)->for(AiSurface::BlogPipeline);
                $result = $client->runPrompt(
                    self::MODULE_TEMPLATE,
                    $payload,
                    $operationId,
                    [
                        'timeout' => config('ai.assistant.timeouts.module', config('ai.assistant.timeout', 300)),
                    ],
                );

                return [
                    'text' => $result->text,
                    'prompt_tokens' => $result->promptTokens,
                    'completion_tokens' => $result->completionTokens,
                ];
            },
        );

        $text = (string) ($call['result']['text'] ?? '');
        $promptTokens = (int) ($call['result']['prompt_tokens'] ?? 0);
        $completionTokens = (int) ($call['result']['completion_tokens'] ?? 0);

        $durationSeconds = microtime(true) - $startTime;
        $cost = $this->calculateEstimatedCost($provider, $model, $promptTokens, $completionTokens);

        // BOOKKEEPING RUNS ONCE PER PROVIDER CALL, NOT ONCE PER ATTEMPT TO MAKE
        // ONE. On a replay the audit row and the cost were already recorded by the
        // pass that actually paid, so repeating them would double-count a single
        // completion in `total_cost` and file a second audit row for one call.
        if ($call['fresh']) {
            $this->recordAudit($run, $payload, $text, $provider, $model, $promptTokens, $completionTokens, $durationSeconds);
            $run->addCost($cost);
        }

        return new ModuleOutput(
            content: $text,
            summary: $this->generateSummary($run, $moduleSlug, $text),
            citations: [],
            provider: $provider,
            model: $model,
            tokensUsed: $promptTokens + $completionTokens,
            durationSeconds: round($durationSeconds, 2),
            costEstimate: round($cost, 6),
        );
    }

    /**
     * File the reproducible-input audit row, and NEVER let filing it cost a second
     * completion (plan 48-24, F2).
     *
     * ─────────────────────────────────────────────────────────────────────────
     * TWO CHANGES, BOTH ABOUT THE SAME 16 MiB COLUMN.
     *
     * 1. THE AUDIT NO LONGER CARRIES BOTH WIRE COPIES. 48-23's dual-write puts two
     *    complete placeholder maps on every request, and this row serialised the
     *    payload whole — so the largest single write in the post-provider window
     *    DOUBLED, into a `MEDIUMTEXT` behind whatever `max_allowed_packet` the
     *    deployment happens to have. The legacy twin is replaced by a digest and a
     *    byte count: enough to prove in hindsight WHICH bytes were sent and to
     *    detect drift between the shapes, at ~100 bytes instead of megabytes. The
     *    typed map is recorded in full because it is the contract that survives the
     *    window.
     *
     *    THE PRECISE RECONSTRUCTION CLAIM, STATED NARROWLY. 48-24's cross-shape
     *    check makes the two shapes equal for every marker the render actually
     *    references, so for those the digest loses nothing — the recorded typed map
     *    reproduces them. A key the render does NOT reference may still differ
     *    between the shapes and is recoverable from this row only as a digest.
     *    That is the intended trade: bytes nothing read, versus a write large
     *    enough to fail and buy a second completion.
     *
     * 2. A FAILURE HERE IS LOGGED, NOT THROWN. The completion is already paid for
     *    and already memoised by the time this runs. Letting a DB error propagate
     *    would fail the job, and a failed job retries — which is precisely the path
     *    that used to buy a second completion. An audit row is worth strictly less
     *    than a provider call, so it loses.
     * ─────────────────────────────────────────────────────────────────────────
     *
     * @param  array<string, mixed>  $payload
     */
    private function recordAudit(
        BlogPostPipelineRun $run,
        array $payload,
        string $text,
        string $provider,
        string $model,
        int $promptTokens,
        int $completionTokens,
        float $durationSeconds,
    ): void {
        try {
            $this->auditService->log(
                step: BlogTopicLlmStep::Writing,
                provider: $provider,
                model: $model,
                // The prompt lives in phasea now; the template key plus the parameters
                // IS the reproducible input recorded here (D-48-6).
                prompt: json_encode([
                    'template' => self::MODULE_TEMPLATE,
                    'parameters' => $this->auditableParameters($payload),
                ], JSON_UNESCAPED_UNICODE) ?: self::MODULE_TEMPLATE,
                response: $text,
                batchId: $run->candidate->batch_id,
                candidateId: $run->candidate_id,
                tokensIn: $promptTokens,
                tokensOut: $completionTokens,
                durationMs: (int) ($durationSeconds * 1000),
            );
        } catch (Throwable $exception) {
            Log::error('ModuleExecutor: audit persistence failed AFTER a paid completion; not retrying, the completion stands', [
                'pipeline_run_id' => $run->id,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * The payload as recorded, with the dual-write twin reduced to a digest.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function auditableParameters(array $payload): array
    {
        if (!array_key_exists('placeholders', $payload)) {
            return $payload;
        }

        $legacy = json_encode($payload['placeholders'], JSON_UNESCAPED_UNICODE);
        $legacy = is_string($legacy) ? $legacy : '';

        // Deleted with the transition window, together with the key it describes.
        $payload['placeholders'] = [
            'omitted' => 'transition-window duplicate of placeholder_data (plan 48-24, F2)',
            'sha256' => hash('sha256', $legacy),
            'bytes' => strlen($legacy),
        ];

        return $payload;
    }

    /**
     * Evaluate quality if ContentModule is available. Returns null when no module record exists.
     */
    private function evaluateIfPossible(
        ModuleOutput $output,
        ?ContentModule $contentModule,
        BlogPostPipelineRun $run,
        string $moduleSlug,
        int $attempt,
        string $provider,
        string $model,
    ): ?QualityResult {
        if (!$contentModule instanceof ContentModule) {
            return null;
        }

        $qualityResult = $this->qualityGate->evaluate($output, $contentModule, $run);

        $run->appendQualityLog([
            'module_slug' => $moduleSlug,
            'timestamp' => now()->toIso8601String(),
            'scores' => $qualityResult->scores,
            'overall_score' => $qualityResult->overallScore,
            'issues' => $qualityResult->issues,
            'decision' => $qualityResult->decision->value,
            'attempt' => $attempt,
            'provider' => $provider,
            'model' => $model,
        ]);

        return $qualityResult;
    }

    /**
     * Persist the final output (used by executeWithEscalation).
     */
    private function persistFinalOutput(
        BlogPostPipelineRun $run,
        string $moduleSlug,
        ModuleOutput $output,
        string $provider,
        string $model,
        ?QualityDecision $overrideDecision,
        ?QualityResult $qualityResult,
        int $attempts,
    ): void {
        $qualityScore = $qualityResult?->overallScore;
        $decision = $overrideDecision !== null
            ? $overrideDecision->value
            : ($qualityResult?->decision->value ?? QualityDecision::Pass->value);

        $run->storeModuleOutput($moduleSlug, [
            'content' => $output->content,
            'summary' => $output->summary,
            'citations_json' => $output->citations,
            'quality_score' => $qualityScore,
            'quality_decision' => $decision,
            'llm_used' => "{$provider}/{$model}",
            'attempts' => $attempts,
        ]);

        $this->trackLinkedUrls($run, $moduleSlug, $output->content);

        Log::info("ModuleExecutor: completed {$moduleSlug}", [
            'pipeline_run_id' => $run->id,
            'provider' => $provider,
            'model' => $model,
            'tokens' => $output->tokensUsed,
            'cost' => $output->costEstimate,
            'duration_s' => $output->durationSeconds,
            'quality_score' => $qualityScore,
            'quality_decision' => $decision,
            'attempts' => $attempts,
        ]);
    }

    /**
     * Store module output on the run and log completion.
     */
    private function persistOutput(
        BlogPostPipelineRun $run,
        string $moduleSlug,
        ModuleOutput $output,
        string $provider,
        string $model,
        ?float $qualityScore = null,
        string $qualityDecision = 'pass',
    ): void {
        $run->storeModuleOutput($moduleSlug, [
            'content' => $output->content,
            'summary' => $output->summary,
            'citations_json' => $output->citations,
            'quality_score' => $qualityScore,
            'quality_decision' => $qualityDecision,
            'llm_used' => "{$provider}/{$model}",
            'attempts' => 1,
        ]);

        $this->trackLinkedUrls($run, $moduleSlug, $output->content);

        Log::info("ModuleExecutor: completed {$moduleSlug}", [
            'pipeline_run_id' => $run->id,
            'provider' => $provider,
            'model' => $model,
            'tokens' => $output->tokensUsed,
            'cost' => $output->costEstimate,
            'duration_s' => $output->durationSeconds,
            'quality_score' => $qualityScore,
            'quality_decision' => $qualityDecision,
        ]);
    }

    /**
     * Attach the quality-gate issues as a `quality_feedback` DATA parameter.
     *
     * phasea's `blog_pipeline.module` template wraps them in the authored
     * "previous attempt" retry directive (plan 48-15) AND formats the bullets
     * (plan 48-21, F5). This method used to build `"- {$issue}\n"` lines here —
     * which is model-visible presentation, so it was host-held prompt formatting
     * wearing a parameter's name. The issue STRINGS are data; how they are
     * rendered to the model is not the host's call.
     *
     * THE SHAPE IS READ OFF THE PAYLOAD, NOT PROBED (plan 48-22), AND THAT DESIGN
     * IS WHY 48-23 CHANGED NOTHING HERE. A second, independent capability read
     * would have had to be found and updated when dual-write landed; deriving the
     * shape from what `DynamicContentModule::buildPayload()` actually handed over
     * means this method simply follows.
     *
     * WHAT IT NOW RESOLVES TO, AND WHY THAT IS THE SAFE ANSWER. Under 48-23 the
     * payload carries BOTH placeholder shapes on every render, so the branch below
     * always takes the RETIRED STRING form. That is not a degradation, it is the
     * only shape both builds accept: `quality_feedback` is a single key that
     * cannot be dual-written, an OLD phasea types it as a STRING and 422s an array
     * — on a quality RETRY, i.e. a run already paid for once — while a CURRENT
     * phasea accepts the string and appends it verbatim, delivering bytes
     * identical to the ones it would have formatted from the list
     * (`ModulePlaceholderResolver::formatQualityFeedback()` was transcribed from
     * the very loop below). One shape is correct against both; the other against
     * one. When the legacy half is deleted at the end of the window, the `array`
     * branch takes over on its own with no edit here.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string>  $feedback
     * @return array<string, mixed>
     */
    private function withQualityFeedback(array $payload, array $feedback): array
    {
        if ($feedback === []) {
            return $payload;
        }

        if (array_key_exists('placeholders', $payload)) {
            // The RETIRED shape — TAKEN ON EVERY RENDER while dual-write is on,
            // because it is the one shape both phasea builds render identically.
            // An old phasea appends this string verbatim under its own retry
            // framing, so the host formats the bullets — including the trailing
            // newline on the final line, which is what `ModuleExecutor` produced
            // before 48-21 (`git show 302fd4b^` — the `$bullets .= "- {$issue}\n"`
            // loop). Deleted with the transition window.
            $bullets = '';
            foreach ($feedback as $issue) {
                $bullets .= "- {$issue}\n";
            }

            $payload['quality_feedback'] = $bullets;

            return $payload;
        }

        $payload['quality_feedback'] = array_values($feedback);

        return $payload;
    }

    /**
     * Extract citation markers [N] from content and map to research sources.
     */
    private function extractCitationsIfEnabled(ModuleOutput $output, ModuleContext $context): ModuleOutput
    {
        if (!$context->citationsEnabled || $context->researchSources === []) {
            return $output;
        }

        $sourceMap = [];
        foreach ($context->researchSources as $source) {
            $sourceMap[$source['id']] = $source;
        }

        $citations = [];

        if (preg_match_all('/\[(\d+)\]/', $output->content, $matches)) {
            $seen = [];
            foreach ($matches[1] as $marker) {
                $markerId = (int) $marker;
                if (isset($sourceMap[$markerId]) && !isset($seen[$markerId])) {
                    $seen[$markerId] = true;
                    $citations[] = [
                        'marker' => $markerId,
                        'title' => $sourceMap[$markerId]['title'],
                        'url' => $sourceMap[$markerId]['url'],
                    ];
                }
            }
        }

        if ($citations === []) {
            return $output;
        }

        return new ModuleOutput(
            content: $output->content,
            summary: $output->summary,
            citations: $citations,
            provider: $output->provider,
            model: $output->model,
            tokensUsed: $output->tokensUsed,
            durationSeconds: $output->durationSeconds,
            costEstimate: $output->costEstimate,
        );
    }

    /**
     * Resolve max retry attempts: per-profile override (quality_config), then global config.
     */
    private function resolveMaxRetryAttempts(BlogPostPipelineRun $run): int
    {
        $snapshot = $run->config_snapshot ?? [];

        return (int) ($snapshot['quality_config']['max_retry_attempts']
            ?? config('blog-pipeline.max_retry_attempts', 2));
    }

    /**
     * Generate a summary of the content using a fast LLM, or return stripped content if short.
     */
    private function generateSummary(BlogPostPipelineRun $run, string $moduleSlug, string $content): string
    {
        $maxWords = (int) config('blog-pipeline.summarization_max_words', 300);
        $stripped = strip_tags($content);

        if (str_word_count($stripped) <= $maxWords) {
            return $stripped;
        }

        try {
            return $this->callSummaryLlm($run, $moduleSlug, $stripped, $maxWords);
        } catch (AiRateLimitedException) {
            Log::info('ModuleExecutor: summary rate-limited, retrying after backoff');
            sleep(5);

            try {
                return $this->callSummaryLlm($run, $moduleSlug, $stripped, $maxWords);
            } catch (Throwable) {
                // fall through to truncation
            }
        } catch (Throwable) {
            // fall through to truncation
        }

        Log::warning('ModuleExecutor: summary generation failed, using truncation');

        $words = explode(' ', $stripped);

        return implode(' ', array_slice($words, 0, $maxWords)).'...';
    }

    private function callSummaryLlm(BlogPostPipelineRun $run, string $moduleSlug, string $content, int $maxWords): string
    {
        // THE SECOND BILLED CALL IN THE SAME WINDOW, AND IT HAD THE SAME DEFECT
        // (plan 48-24, F2). It also minted `(string) Str::uuid()` inline, so a job
        // retry re-summarised — and re-paid for — content the first attempt had
        // already summarised.
        //
        // KEYED ON THE CONTENT, NOT ON AN ATTEMPT NUMBER, because that is what the
        // call is actually a function of: the module text is replayed verbatim from
        // the memo, so the same text derives the same id, and a genuinely different
        // module output (a quality retry) derives a different one on its own.
        $call = $this->callMemo->remember(
            $run,
            $moduleSlug,
            'module-summary:'.hash('sha256', $content),
            function (string $operationId) use ($content, $maxWords): array {
                // v2.6 (plan 48-15): phasea owns the summariser instruction. The host
                // names the blog_pipeline.module_summary template and sends the bounded
                // word count and the content as parameters — no instruction string leaves
                // the host. The existing 30s budget is preserved explicitly on runPrompt's
                // transport-only options rather than inheriting the default.
                $client = app(AiClientFactory::class)->for(AiSurface::BlogPipeline);
                $result = $client->runPrompt(
                    self::SUMMARY_TEMPLATE,
                    [
                        'max_words' => $maxWords,
                        'content' => $content,
                    ],
                    $operationId,
                    [
                        'timeout' => 30,
                    ],
                );

                return ['text' => $result->text];
            },
        );

        return (string) ($call['result']['text'] ?? '');
    }

    /**
     * Extract internal link URLs from module output and store per-module in context_json.
     */
    private function trackLinkedUrls(BlogPostPipelineRun $run, string $moduleSlug, string $content): void
    {
        $appUrl = (string) config('app.url');

        /** @var ScansInternalLinks $scanner */
        $scanner = app(ScansInternalLinks::class);
        $scanResult = $scanner->scanContent($content, $appUrl);

        $urls = collect($scanResult->internalLinks)
            ->pluck('url')
            ->unique()
            ->values()
            ->toArray();

        $run->setContextValue("linked_urls.{$moduleSlug}", $urls);
    }

    /**
     * Notify the approving user that a module needed a final retry attempt
     * after repeated quality failures (no model switch — phasea owns
     * selection).
     */
    private function notifyFinalRetry(BlogPostPipelineRun $run, string $moduleSlug): void
    {
        $userId = $run->candidate->approved_by ?? null;

        if ($userId === null) {
            return;
        }

        $userClass = app(ModelRegistry::class)->getModelClassOrFail('user');

        /** @var Model|null $user */
        $user = $userClass::find($userId);

        if ($user === null) {
            return;
        }

        Notification::make()
            ->title(__('Quality Retry'))
            ->body(__('Module ":module" needed a final retry after quality failures for ":topic".', [
                'module' => $moduleSlug,
                'topic' => $run->candidate->title,
            ]))
            ->warning()
            ->sendToDatabase($user);
    }
}
