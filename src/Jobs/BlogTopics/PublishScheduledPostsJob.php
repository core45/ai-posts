<?php

declare(strict_types=1);

namespace Core45\AiPosts\Jobs\BlogTopics;

use Core45\AiPosts\Enums\BlogTopicCandidateStatus;
use Core45\AiPosts\Models\BlogTopicCandidate;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PublishScheduledPostsJob implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        $candidates = BlogTopicCandidate::query()
            ->where('status', BlogTopicCandidateStatus::Generated)
            ->whereNotNull('scheduled_publish_at')
            ->where('scheduled_publish_at', '<=', now())
            ->whereNotNull('post_id')
            ->whereHas('post', fn ($q) => $q->where('active', false))
            ->with(['series', 'post'])
            ->get();

        foreach ($candidates as $candidate) {
            $series = $candidate->series;
            $post = $candidate->post;

            if ($post === null) {
                continue;
            }

            if ($series !== null && $series->auto_publish) {
                // Auto-publish: activate post, mark candidate as published
                DB::transaction(function () use ($post, $candidate, $series): void {
                    $post->update(['active' => true]);
                    $candidate->update(['status' => BlogTopicCandidateStatus::Published]);
                    $series->increment('posts_generated');
                });

                Log::info('PublishScheduledPostsJob: Auto-published post', [
                    'post_id' => $post->id,
                    'series_id' => $series->id,
                ]);
            } else {
                // Mark as published (processed) so we don't re-notify (errata E10)
                $candidate->update(['status' => BlogTopicCandidateStatus::Published]);

                if ($series !== null) {
                    $series->increment('posts_generated');
                }

                Log::info('PublishScheduledPostsJob: Post ready for manual publish', [
                    'post_id' => $post->id,
                    'series_id' => $series?->id,
                ]);
            }
        }
    }
}
