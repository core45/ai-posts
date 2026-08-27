<?php

declare(strict_types=1);

namespace Core45\AiPosts\Services\BlogTopics;

use Core45\AiPosts\Enums\BlogProductSeriesStatus;
use Core45\AiPosts\Enums\BlogTopicCandidateStatus;
use Core45\AiPosts\Models\BlogProductSeries;
use Core45\AiPosts\Models\BlogTopicCandidate;
use Core45\AiPosts\Models\BlogTopicProfile;
use Illuminate\Support\Collection;

class ProductSeriesService
{
    /**
     * Create a new product blog series.
     *
     * @param  array<int>  $targetIds
     */
    public function createSeries(
        BlogTopicProfile $profile,
        string $name,
        string $targetType,
        array $targetIds,
        int $totalPosts,
        int $intervalDays,
        bool $autoPublish,
    ): BlogProductSeries {
        return BlogProductSeries::create([
            'profile_id' => $profile->id,
            'name' => $name,
            'target_type' => $targetType,
            'target_ids' => $targetIds,
            'total_posts' => $totalPosts,
            'interval_days' => $intervalDays,
            'auto_publish' => $autoPublish,
            'status' => BlogProductSeriesStatus::Planning,
            'posts_generated' => 0,
        ]);
    }

    /**
     * Get the next batch of proposed candidates for generation.
     *
     * @return Collection<int, BlogTopicCandidate>
     */
    public function getNextBatchForGeneration(BlogProductSeries $series, int $count = 5): Collection
    {
        return BlogTopicCandidate::query()
            ->where('series_id', $series->id)
            ->where('status', BlogTopicCandidateStatus::Proposed)
            ->orderBy('rank')
            ->limit($count)
            ->get();
    }

    /**
     * Calculate scheduled publish dates for a batch of candidates.
     *
     * @param  Collection<int, BlogTopicCandidate>  $candidates
     */
    public function schedulePublishDates(BlogProductSeries $series, Collection $candidates): void
    {
        $baseDate = $series->next_publish_at ?? now();
        $intervalDays = $series->interval_days;

        foreach ($candidates as $index => $candidate) {
            $publishAt = $baseDate->copy()->addDays($index * $intervalDays);
            $candidate->update(['scheduled_publish_at' => $publishAt]);
        }

        $lastPublishAt = $baseDate->copy()->addDays(count($candidates) * $intervalDays);
        $series->update(['next_publish_at' => $lastPublishAt]);
    }
}
