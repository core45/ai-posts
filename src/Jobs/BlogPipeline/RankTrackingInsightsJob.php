<?php

declare(strict_types=1);

namespace Core45\AiPosts\Jobs\BlogPipeline;

use Core45\AiPosts\Contracts\Seo\TracksKeywordRankings;
use Core45\AiPosts\DTOs\Seo\WincherKeywordPosition;
use Core45\AiPosts\Models\BlogPostPipelineRun;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class RankTrackingInsightsJob implements ShouldQueue
{
    use Batchable;
    use Queueable;

    public int $tries = 2;

    public int $timeout = 120;

    public function __construct(
        public BlogPostPipelineRun $run,
    ) {
        $this->onQueue('ai-bulk');
    }

    public function handle(TracksKeywordRankings $wincher): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $keyword = $this->run->candidate->title ?? 'untitled';

        if (!$wincher->isEnabled()) {
            Log::info('RankTrackingInsightsJob: Wincher not enabled, skipping', [
                'pipeline_run_id' => $this->run->id,
            ]);

            $this->run->storeResearchData('rank_tracking', [
                'enabled' => false,
                'positions' => [],
                'history' => [],
            ]);

            return;
        }

        $positions = [];
        $history = [];

        try {
            $allPositions = $wincher->fetchKeywordPositions();

            $matched = $allPositions->filter(function (WincherKeywordPosition $position) use ($keyword): bool {
                $kw = mb_strtolower($position->keyword);
                $target = mb_strtolower($keyword);

                return str_contains($kw, $target) || str_contains($target, $kw);
            });

            $positions = $matched->map(fn (WincherKeywordPosition $pos): array => [
                'keyword' => $pos->keyword,
                'position' => $pos->currentPosition,
                'url' => $pos->url,
                'change' => $pos->change,
            ])->values()->all();

            $history = $wincher->getKeywordHistory($keyword, 90);
        } catch (\Throwable $e) {
            Log::warning('RankTrackingInsightsJob: Wincher failed', [
                'pipeline_run_id' => $this->run->id,
                'error' => $e->getMessage(),
            ]);
        }

        $this->run->storeResearchData('rank_tracking', [
            'enabled' => true,
            'positions' => $positions,
            'history' => $history,
        ]);

        Log::info('RankTrackingInsightsJob: completed', [
            'pipeline_run_id' => $this->run->id,
            'positions_found' => count($positions),
        ]);
    }
}
