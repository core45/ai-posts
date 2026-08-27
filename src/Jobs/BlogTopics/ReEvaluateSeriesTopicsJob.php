<?php

declare(strict_types=1);

namespace Core45\AiPosts\Jobs\BlogTopics;

use Core45\AiCore\Enums\AiSurface;
use Core45\AiCore\Exceptions\AiRateLimitedException;
use Core45\AiCore\Exceptions\AiUnavailableException;
use Core45\AiCore\Services\AiClientFactory;
use Core45\AiPosts\Enums\BlogTopicCandidateStatus;
use Core45\AiPosts\Enums\BlogTopicLlmStep;
use Core45\AiPosts\Jobs\BlogTopics\Concerns\NotifiesUser;
use Core45\AiPosts\Models\BlogProductSeries;
use Core45\AiPosts\Services\BlogTopics\LlmAuditService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ReEvaluateSeriesTopicsJob implements ShouldQueue
{
    use NotifiesUser;
    use Queueable;

    /**
     * The phasea-owned prompt this job runs (v2.6 design §2.5). The instruction
     * text lives in phasea's `BlogTopicsSeriesReevaluationPrompt`; this host
     * names it and sends structured parameters only.
     */
    private const SERIES_REEVALUATION_TEMPLATE = 'blog_topics.series_reevaluation';

    public int $tries = 10;

    public int $maxExceptions = 3;

    /** @var array<int, int> */
    public array $backoff = [10, 30, 60];

    public int $timeout = 300;

    public readonly string $operationId;

    public function __construct(
        public BlogProductSeries $series,
        public ?int $userId = null,
    ) {
        $this->onQueue('ai-bulk');
        $this->operationId = (string) Str::uuid();
    }

    /**
     * The Phase-8 SEAM-08 contract gap is resolved via the per-surface factory:
     * `AiClientFactory::for(AiSurface::BlogTopics)` honors `AI_SURFACE_BLOG_TOPICS_DRIVER`
     * (local = identical Prism path; remote = phasea). `operationId` is minted
     * once per dispatch and forwarded into the routing call, so a retry replays
     * the same id and phasea's metering does not re-bill.
     *
     * v2.6 design §2.5: the call runs `AiClient::runPrompt()` against a
     * phasea-OWNED template. No instruction text leaves this host.
     */
    public function handle(
        LlmAuditService $auditService,
    ): void {
        Log::info('ReEvaluateSeriesTopicsJob: Starting', [
            'series_id' => $this->series->id,
            'operation_id' => $this->operationId,
        ]);

        // Gather context: generated posts so far
        $generatedCandidates = $this->series->candidates()
            ->where('status', BlogTopicCandidateStatus::Generated)
            ->with('post')
            ->get();

        $generatedTitles = $generatedCandidates->pluck('title')->toArray();

        // Remaining proposed candidates
        $remainingCandidates = $this->series->candidates()
            ->where('status', BlogTopicCandidateStatus::Proposed)
            ->get();

        if ($remainingCandidates->isEmpty()) {
            Log::info('ReEvaluateSeriesTopicsJob: No remaining candidates', [
                'series_id' => $this->series->id,
            ]);

            return;
        }

        try {
            // v2.6: the host cannot know which model ran — phasea routes the task and
            // AiTextResult carries only text + token counts. Record the truthful
            // provider ('phasea') and an explicit unknown rather than a config guess.
            // FOLLOW-UP: have phasea return provider/model on AiTextResult so these
            // audit rows become accurate instead of merely honest.
            $provider = 'phasea';
            $model = 'unknown';

            // Both lists ride as structured DATA. The `id` is load-bearing:
            // the reply is applied with `firstWhere('id', ...)` against this
            // very collection further down.
            $parameters = [
                'published_titles' => $generatedTitles,
                'remaining_topics' => $remainingCandidates->map(fn ($c) => [
                    'id' => $c->id,
                    'title' => $c->title,
                    'outline' => $c->outline_json,
                ])->values()->toArray(),
            ];

            $startTime = microtime(true);

            // Phase-8 SEAM-08 gap resolved: route the completion through the
            // per-surface factory (local = identical Prism path; remote = phasea).
            $client = app(AiClientFactory::class)->for(AiSurface::BlogTopics);
            $result = $client->runPrompt(
                self::SERIES_REEVALUATION_TEMPLATE,
                $parameters,
                $this->operationId,
                // Transport-only, and NOT optional: re-evaluation has always
                // run on the 300s assistant budget. Dropping it here would
                // silently move this job onto the 30s `AI_SERVICE_TIMEOUT`
                // default.
                [
                    'timeout' => config('ai.assistant.timeout', 300),
                ],
            );

            $durationMs = (int) ((microtime(true) - $startTime) * 1000);

            $auditService->log(
                step: BlogTopicLlmStep::SeriesReEvaluation,
                provider: $provider,
                model: $model,
                // D-48-6: the prompt lives in phasea now, so there is none to
                // record here. The template key plus the parameters IS the
                // reproducible input — and recording the whole structure keeps
                // `prompt_hash` discriminating between runs, which the bare
                // template key would collapse to a single digest per surface.
                prompt: json_encode([
                    'template' => self::SERIES_REEVALUATION_TEMPLATE,
                    'parameters' => $parameters,
                ], JSON_UNESCAPED_UNICODE) ?: self::SERIES_REEVALUATION_TEMPLATE,
                response: $result->text,
                candidateId: null,
                batchId: null,
                tokensIn: $result->promptTokens,
                tokensOut: $result->completionTokens,
                durationMs: $durationMs,
            );

            // Parse and apply updates
            $cleaned = preg_replace('/```(?:json)?\s*/', '', $result->text);
            $cleaned = trim((string) $cleaned);
            $updates = [];
            if (preg_match('/\[[\s\S]*\]/', $cleaned, $matches)) {
                $decoded = json_decode($matches[0], true);
                if (is_array($decoded)) {
                    $updates = $decoded;
                }
            }

            foreach ($updates as $update) {
                $candidateId = $update['id'] ?? null;
                if ($candidateId === null) {
                    continue;
                }

                $candidate = $remainingCandidates->firstWhere('id', $candidateId);
                if ($candidate === null) {
                    continue;
                }

                if (($update['keep'] ?? true) === false) {
                    $candidate->update(['status' => BlogTopicCandidateStatus::Rejected]);
                } else {
                    $candidate->update([
                        'title' => $update['title'] ?? $candidate->title,
                        'outline_json' => $update['outline'] ?? $candidate->outline_json,
                    ]);
                }
            }

            Log::info('ReEvaluateSeriesTopicsJob: Completed', [
                'series_id' => $this->series->id,
                'updates_applied' => count($updates),
            ]);

            $this->notifyUser(
                __('Series topics re-evaluated'),
                __('Topics re-evaluated for series ":name". Please review updated topics.', [
                    'name' => $this->series->name,
                ]),
                'info',
            );
        } catch (AiRateLimitedException $e) {
            $retryAfter = $e->retryAfter > 0 ? $e->retryAfter : 60;
            $this->release($retryAfter);
        } catch (AiUnavailableException $e) {
            Log::warning('ReEvaluateSeriesTopicsJob: Provider overloaded', [
                'series_id' => $this->series->id,
            ]);
            $this->release(60);
        }
    }
}
