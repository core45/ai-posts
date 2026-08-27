<?php

declare(strict_types=1);

namespace Core45\AiPosts\Jobs;

use Core45\AiCore\Contracts\AIContentable;
use Core45\AiCore\Models\AIContentBatch;
use Core45\AiCore\Services\AIBatchCircuitBreaker;
use Core45\AiPosts\Services\PostContentRewriteService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;

class ProcessPostAIContent implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [10, 30, 60];

    public int $timeout = 600;

    /**
     * @param  array<string, mixed>  $mediaOptions
     */
    public function __construct(
        public AIContentable&Model $post,
        public AIContentBatch $batch,
        public ?int $userId = null,
        public array $mediaOptions = [],
    ) {
        $this->onQueue('ai-bulk');
    }

    /**
     * @return array<object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping("post:{$this->post->getKey()}:batch:{$this->batch->id}"))
                ->releaseAfter(300)
                ->expireAfter(900),
        ];
    }

    public function handle(PostContentRewriteService $service): void
    {
        $this->batch->refresh();

        if ($this->batch->isItemProcessed($this->post->getKey()) || $this->batch->isItemFailed($this->post->getKey()) || $this->batch->isSettled()) {
            Log::info('ProcessPostAIContent: Skipping already-handled item', ['post_id' => $this->post->getKey(), 'batch_id' => $this->batch->id]);

            return;
        }

        $breaker = app(AIBatchCircuitBreaker::class);
        $breaker->recordExecution($this->batch);

        if ($breaker->hasTripped($this->batch)) {
            $breaker->tripAndAbort($this->batch);

            return;
        }

        Log::info('ProcessPostAIContent: Starting', [
            'post_id' => $this->post->getKey(),
            'batch_id' => $this->batch->id,
            'user_id' => $this->userId,
            'has_media_options' => !empty($this->mediaOptions),
        ]);

        if ($this->userId !== null) {
            $service->setUserId($this->userId);
        }

        if (!empty($this->mediaOptions)) {
            $service->withMediaGeneration($this->mediaOptions);
        }

        try {
            $result = $service->processPost($this->post);

            $hasErrors = !empty($result['errors']);
            $hasProcessed = !empty($result['processed_fields']);

            Log::info('ProcessPostAIContent: Completed', [
                'post_id' => $this->post->getKey(),
                'processed_fields' => count($result['processed_fields']),
                'errors' => count($result['errors']),
            ]);

            if ($hasErrors && !$hasProcessed) {
                Log::error('ProcessPostAIContent: Complete failure', [
                    'post_id' => $this->post->getKey(),
                    'errors' => $result['errors'],
                ]);
                $this->batch->markItemFailed($this->post->getKey());
                $this->batch->addErrorMessage($this->post->getKey(), implode('; ', $result['errors']));
            } else {
                $this->post->setAttribute('ai_rewritten_at', now()->toDateTimeString());
                $this->post->save();
                $this->batch->markItemProcessed($this->post->getKey());

                if ($hasErrors) {
                    $this->batch->addErrorMessage($this->post->getKey(), implode('; ', $result['errors']));
                }
            }
        } catch (\Exception $e) {
            Log::error('ProcessPostAIContent: Failed', [
                'post_id' => $this->post->getKey(),
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }

        $this->checkBatchCompletion();
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('ProcessPostAIContent: Permanently failed', [
            'post_id' => $this->post->getKey(),
            'batch_id' => $this->batch->id,
            'error' => $exception->getMessage(),
        ]);

        $this->batch->markItemFailed($this->post->getKey());
        $this->batch->addErrorMessage($this->post->getKey(), $exception->getMessage());
        $this->checkBatchCompletion();
    }

    /**
     * Finalize the batch once every item has settled.
     *
     * The version of this job that shipped in the blog template had its
     * finalize dispatch stripped, because the host job it named did not exist
     * there. Claiming without dispatching leaves a completed batch parked in
     * `finalizing` forever: no notification is sent and nothing reports an
     * error, so the batch simply looks stuck.
     *
     * `finalizeIfClaimed()` keeps the atomic claim and adds the hand-off to
     * whatever the consuming app configured as `ai.finalize_job`. An app that
     * configures none still completes the batch — that is a supported setup, not
     * the accident this replaced.
     */
    protected function checkBatchCompletion(): void
    {
        $this->batch->refresh();

        if ($this->batch->isComplete() && $this->batch->finalizeIfClaimed()) {
            Log::info('ProcessPostAIContent: Batch finalized', [
                'batch_id' => $this->batch->id,
            ]);
        }
    }
}
