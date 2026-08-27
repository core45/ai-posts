<?php

declare(strict_types=1);

namespace Core45\AiPosts\Jobs\BlogTopics;

use Core45\AiPosts\Enums\BlogProductSeriesStatus;
use Core45\AiPosts\Enums\BlogTopicCandidateStatus;
use Core45\AiPosts\Jobs\BlogTopics\Concerns\NotifiesUser;
use Core45\AiPosts\Models\BlogProductSeries;
use Core45\AiPosts\Services\BlogTopics\ProductSeriesService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class GenerateSeriesBatchJob implements ShouldQueue
{
    use NotifiesUser;
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(
        public BlogProductSeries $series,
        public int $batchSize = 5,
        public ?int $userId = null,
    ) {
        $this->onQueue('ai-bulk');
    }

    public function handle(ProductSeriesService $seriesService): void
    {
        Log::info('GenerateSeriesBatchJob: Starting', [
            'series_id' => $this->series->id,
            'batch_size' => $this->batchSize,
        ]);

        $candidates = $seriesService->getNextBatchForGeneration($this->series, $this->batchSize);

        if ($candidates->isEmpty()) {
            Log::info('GenerateSeriesBatchJob: No more candidates to generate', [
                'series_id' => $this->series->id,
            ]);

            // Check if series is complete
            $remaining = $this->series->candidates()
                ->where('status', BlogTopicCandidateStatus::Proposed)
                ->count();

            if ($remaining === 0) {
                $this->series->update(['status' => BlogProductSeriesStatus::Completed]);
            }

            return;
        }

        // Assign scheduled publish dates
        $seriesService->schedulePublishDates($this->series, $candidates);

        // Update series status
        if ($this->series->status === BlogProductSeriesStatus::Planned) {
            $this->series->update(['status' => BlogProductSeriesStatus::InProgress]);
        }

        // Dispatch generation for each candidate
        foreach ($candidates as $candidate) {
            $candidate->update(['status' => BlogTopicCandidateStatus::Approved]);
            GenerateBlogPostJob::dispatch($candidate, $this->userId);
        }

        // Check if re-evaluation is needed (after every 5th post)
        $totalGenerated = $this->series->posts_generated + $candidates->count();
        if ($totalGenerated > 0 && $totalGenerated % 5 === 0) {
            ReEvaluateSeriesTopicsJob::dispatch($this->series, $this->userId);
        }

        Log::info('GenerateSeriesBatchJob: Dispatched generation', [
            'series_id' => $this->series->id,
            'candidates_dispatched' => $candidates->count(),
        ]);

        $this->notifyUser(
            __('Series batch started'),
            __(':count posts queued for generation in series ":name"', [
                'count' => $candidates->count(),
                'name' => $this->series->name,
            ]),
            'success',
        );
    }
}
