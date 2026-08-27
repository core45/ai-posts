<?php

declare(strict_types=1);

namespace Core45\AiPosts\Jobs\BlogTopics;

use Core45\AiPosts\Models\BlogTopicBatch;
use Core45\AiPosts\Services\BlogTopics\ResearchService;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ExecuteResearchJob implements ShouldQueue
{
    use Batchable;
    use Queueable;

    public int $tries = 5;

    public int $maxExceptions = 3;

    /** @var array<int, int> */
    public array $backoff = [15, 30, 60];

    public int $timeout = 120;

    public function __construct(
        public BlogTopicBatch $topicBatch,
        public string $query,
    ) {
        $this->onQueue('ai-bulk');
    }

    public function handle(ResearchService $service): void
    {
        // Check if the Bus batch (from Batchable trait) was cancelled
        if ($this->batch() !== null && $this->batch()->cancelled()) {
            return;
        }

        Log::info('ExecuteResearchJob: Executing search', [
            'batch_id' => $this->topicBatch->id,
            'query' => $this->query,
        ]);

        try {
            $service->executeSearch($this->topicBatch, $this->query);
        } catch (\Throwable $e) {
            Log::error('ExecuteResearchJob: Search failed', [
                'batch_id' => $this->topicBatch->id,
                'query' => $this->query,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
