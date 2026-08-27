<?php

declare(strict_types=1);

namespace Core45\AiPosts\Jobs\BlogTopics;

use Core45\AiCore\Contracts\AiClient;
use Core45\AiCore\Contracts\NotifiesOperator;
use Core45\AiCore\Enums\NotificationSeverity;
use Core45\AiCore\Exceptions\AiRateLimitedException;
use Core45\AiCore\Exceptions\AiUnavailableException;
use Core45\AiCore\ModelRegistry;
use Core45\AiPosts\Enums\BlogTopicBatchStatus;
use Core45\AiPosts\Jobs\BlogTopics\Concerns\NotifiesUser;
use Core45\AiPosts\Models\BlogTopicBatch;
use Core45\AiPosts\Services\BlogTopics\ResearchService;
use Core45\AiPosts\Services\BlogTopics\SerpApiTopicEnrichmentService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class GenerateSearchQueriesJob implements ShouldQueue
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
     * NOTE: `AiClient` is injected as a documented seam placeholder.
     * `ResearchService::generateQueries()` owns its own seam call (routes
     * through `completeText()`); `operationId` is minted once per dispatch
     * and logged, but not forwarded into the service.
     */
    public function handle(ResearchService $service, SerpApiTopicEnrichmentService $enrichment, AiClient $client): void
    {
        Log::info('GenerateSearchQueriesJob: Starting', [
            'batch_id' => $this->batch->id,
            'operation_id' => $this->operationId,
        ]);

        $this->batch->update([
            'status' => BlogTopicBatchStatus::Researching,
            'started_at' => now(),
        ]);

        try {
            $enrichment->expandKeywords($this->batch);

            $queries = $service->generateQueries($this->batch);

            $this->batch->update([
                'queries_json' => $queries,
            ]);

            Log::info('GenerateSearchQueriesJob: Queries generated', [
                'batch_id' => $this->batch->id,
                'query_count' => count($queries),
            ]);

            // Extract scalar values so batch closures don't capture $this (non-serializable)
            $batchId = $this->batch->id;
            $userId = $this->userId;

            // Dispatch ExecuteResearchJob for each query via Bus::batch,
            // then SynthesizeTopicsJob after all searches complete
            $searchJobs = array_map(
                fn (string $query) => new ExecuteResearchJob($this->batch, $query),
                $queries,
            );

            Bus::batch($searchJobs)
                ->then(function () use ($batchId, $userId) {
                    $batch = BlogTopicBatch::findOrFail($batchId);
                    SynthesizeTopicsJob::dispatch($batch, $userId);
                })
                ->catch(function () use ($batchId, $userId) {
                    Log::error('GenerateSearchQueriesJob: Search batch failed', [
                        'batch_id' => $batchId,
                    ]);

                    $batch = BlogTopicBatch::findOrFail($batchId);
                    $batch->update([
                        'status' => BlogTopicBatchStatus::Failed,
                        'error_message' => 'One or more search queries failed',
                        'finished_at' => now(),
                    ]);

                    if ($userId !== null) {
                        $userClass = app(ModelRegistry::class)->getModelClassOrFail('user');
                        $user = $userClass::find($userId);
                        if ($user !== null) {
                            app(NotifiesOperator::class)->notifyOperator(
                                $user,
                                __('Research failed'),
                                __('Research batch #:id failed during search execution.', [
                                    'id' => $batchId,
                                ]),
                                NotificationSeverity::Danger,
                            );
                        }
                    }
                })
                ->onQueue('ai-bulk')
                ->dispatch();
        } catch (AiRateLimitedException $e) {
            $retryAfter = $e->retryAfter > 0 ? $e->retryAfter : 60;

            Log::warning('GenerateSearchQueriesJob: Rate limited', [
                'batch_id' => $this->batch->id,
                'retry_after_seconds' => $retryAfter,
            ]);

            $this->release($retryAfter);
        } catch (AiUnavailableException $e) {
            Log::warning('GenerateSearchQueriesJob: Provider overloaded', [
                'batch_id' => $this->batch->id,
            ]);

            $this->release(60);
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('GenerateSearchQueriesJob: Permanently failed', [
            'batch_id' => $this->batch->id,
            'exception' => $exception::class,
            'error' => $exception->getMessage(),
        ]);

        $this->batch->update([
            'status' => BlogTopicBatchStatus::Failed,
            'error_message' => $this->failureReason($exception),
            'finished_at' => now(),
        ]);

        $this->notifyUser(
            __('Research failed'),
            __('Failed to generate search queries for batch #:id: :error', [
                'id' => $this->batch->id,
                'error' => $this->failureReason($exception),
            ]),
            'danger',
        );
    }

    /**
     * Translate the raw failure into an actionable, user-facing reason.
     *
     * A MaxAttemptsExceededException here can only come from the
     * rate-limit / overload release loop in handle(): any genuine error is
     * thrown and trips $maxExceptions long before $tries is exhausted, so it
     * surfaces its own message. The opaque "attempted too many times" string
     * therefore always means the research provider stayed unavailable for the
     * whole retry window — say so instead.
     */
    private function failureReason(\Throwable $exception): string
    {
        if (!$exception instanceof MaxAttemptsExceededException) {
            return $exception->getMessage();
        }

        // v2.6: names the seam, not a vendor. The host cannot know which vendor
        // phasea routed to, so the old config-derived name could contradict what
        // actually rate-limited. The message key is unchanged, so no translation
        // churn across the six locales.
        return __('the AI provider (:provider) was rate-limited or overloaded and did not recover after :tries attempts. Please try again in a few minutes.', [
            'provider' => 'phasea',
            'tries' => $this->tries,
        ]);
    }
}
