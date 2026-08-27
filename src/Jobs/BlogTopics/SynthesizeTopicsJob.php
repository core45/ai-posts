<?php

declare(strict_types=1);

namespace Core45\AiPosts\Jobs\BlogTopics;

use Core45\AiCore\Contracts\AiClient;
use Core45\AiCore\Exceptions\AiRateLimitedException;
use Core45\AiCore\Exceptions\AiUnavailableException;
use Core45\AiPosts\Enums\BlogTopicBatchStatus;
use Core45\AiPosts\Jobs\BlogTopics\Concerns\NotifiesUser;
use Core45\AiPosts\Models\BlogTopicBatch;
use Core45\AiPosts\Services\BlogTopics\SerpApiTopicEnrichmentService;
use Core45\AiPosts\Services\BlogTopics\TopicSynthesisService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SynthesizeTopicsJob implements ShouldQueue
{
    use NotifiesUser;
    use Queueable;

    public int $tries = 10;

    public int $maxExceptions = 3;

    /** @var array<int, int> */
    public array $backoff = [10, 30, 60];

    public int $timeout = 300;

    public readonly string $operationId;

    public function __construct(
        public BlogTopicBatch $batch,
        public ?int $userId = null,
    ) {
        $this->onQueue('ai-bulk');
        $this->operationId = (string) Str::uuid();
    }

    /**
     * NOTE (SEAM-08 contract gap): `AiClient` is injected as a documented seam
     * placeholder. `TopicSynthesisService::synthesize()` owns its own Prism
     * call and is out of this plan's file ownership; `operationId` is minted
     * once per dispatch and logged, but not forwarded into the service.
     */
    public function handle(TopicSynthesisService $service, SerpApiTopicEnrichmentService $enrichment, AiClient $client): void
    {
        Log::info('SynthesizeTopicsJob: Starting', [
            'batch_id' => $this->batch->id,
            'operation_id' => $this->operationId,
        ]);

        $this->batch->update([
            'status' => BlogTopicBatchStatus::Synthesizing,
        ]);

        try {
            $enrichment->injectTrends($this->batch);

            $service->synthesize($this->batch);

            $candidateCount = $this->batch->candidates()->count();

            $this->batch->update([
                'status' => BlogTopicBatchStatus::Completed,
                'finished_at' => now(),
            ]);

            Log::info('SynthesizeTopicsJob: Completed', [
                'batch_id' => $this->batch->id,
                'candidates' => $candidateCount,
            ]);

            $this->notifyUser(
                __(':count topics ready for review', ['count' => $candidateCount]),
                __('Research batch #:id completed with :count topic candidates.', [
                    'id' => $this->batch->id,
                    'count' => $candidateCount,
                ]),
                'success',
            );
        } catch (AiRateLimitedException $e) {
            $retryAfter = $e->retryAfter > 0 ? $e->retryAfter : 60;

            Log::warning('SynthesizeTopicsJob: Rate limited', [
                'batch_id' => $this->batch->id,
                'retry_after_seconds' => $retryAfter,
            ]);

            $this->release($retryAfter);
        } catch (AiUnavailableException) {
            Log::warning('SynthesizeTopicsJob: Provider overloaded', [
                'batch_id' => $this->batch->id,
            ]);

            $this->release(60);
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('SynthesizeTopicsJob: Permanently failed', [
            'batch_id' => $this->batch->id,
            'error' => $exception->getMessage(),
        ]);

        $this->batch->update([
            'status' => BlogTopicBatchStatus::Failed,
            'error_message' => $exception->getMessage(),
            'finished_at' => now(),
        ]);

        $this->notifyUser(
            __('Topic synthesis failed'),
            __('Failed to synthesize topics for batch #:id: :error', [
                'id' => $this->batch->id,
                'error' => $exception->getMessage(),
            ]),
            'danger',
        );
    }
}
