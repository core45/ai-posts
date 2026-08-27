<?php

declare(strict_types=1);

namespace Core45\AiPosts\Jobs\BlogPipeline;

use Core45\AiPosts\Contracts\Seo\ProvidesBingQueryStats;
use Core45\AiPosts\Contracts\Seo\ProvidesSearchAnalytics;
use Core45\AiPosts\DTOs\Seo\BingQueryStatsRow;
use Core45\AiPosts\DTOs\Seo\SearchAnalyticsRow;
use Core45\AiPosts\Models\BlogPostPipelineRun;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class SearchConsoleInsightsJob implements ShouldQueue
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

    public function handle(ProvidesSearchAnalytics $gsc, ProvidesBingQueryStats $bing): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $keyword = $this->run->candidate->title ?? 'untitled';
        $gscData = [];
        $bingData = [];

        if ($gsc->isConnected()) {
            try {
                $siteUrl = (string) config('seo.integrations.google_search_console.site_url', '');

                if ($siteUrl !== '') {
                    $analytics = $gsc->fetchSearchAnalytics(
                        siteUrl: $siteUrl,
                        startDate: now()->subDays(90)->toDateString(),
                        endDate: now()->toDateString(),
                        dimensions: ['query'],
                        rowLimit: 50,
                    );

                    $filtered = $analytics->filter(function (SearchAnalyticsRow $row) use ($keyword): bool {
                        $query = mb_strtolower($row->query);
                        $kw = mb_strtolower($keyword);

                        return str_contains($query, $kw) || str_contains($kw, $query);
                    });

                    $gscData = $filtered->map(fn (SearchAnalyticsRow $row): array => [
                        'query' => $row->query,
                        'clicks' => $row->clicks,
                        'impressions' => $row->impressions,
                        'ctr' => $row->ctr,
                        'position' => $row->position,
                    ])->values()->all();
                }
            } catch (\Throwable $e) {
                Log::warning('SearchConsoleInsightsJob: GSC failed', [
                    'pipeline_run_id' => $this->run->id,
                    'error' => $e->getMessage(),
                ]);
            }
        } else {
            Log::info('SearchConsoleInsightsJob: GSC not connected, skipping', [
                'pipeline_run_id' => $this->run->id,
            ]);
        }

        if ($bing->isEnabled()) {
            try {
                $bingSiteUrl = $bing->getSiteUrl();

                if ($bingSiteUrl !== null) {
                    $bingStats = $bing->fetchQueryStats($bingSiteUrl);

                    $bingData = $bingStats->filter(function (BingQueryStatsRow $row) use ($keyword): bool {
                        $query = mb_strtolower($row->query);
                        $kw = mb_strtolower($keyword);

                        return str_contains($query, $kw) || str_contains($kw, $query);
                    })->map(fn (BingQueryStatsRow $row): array => [
                        'query' => $row->query,
                        'impressions' => $row->impressions,
                        'clicks' => $row->clicks,
                    ])->values()->all();
                }
            } catch (\Throwable $e) {
                Log::warning('SearchConsoleInsightsJob: Bing failed', [
                    'pipeline_run_id' => $this->run->id,
                    'error' => $e->getMessage(),
                ]);
            }
        } else {
            Log::info('SearchConsoleInsightsJob: Bing not enabled, skipping', [
                'pipeline_run_id' => $this->run->id,
            ]);
        }

        $this->run->storeResearchData('search_console', [
            'gsc_queries' => $gscData,
            'bing_queries' => $bingData,
            'gsc_connected' => $gsc->isConnected(),
            'bing_enabled' => $bing->isEnabled(),
        ]);

        Log::info('SearchConsoleInsightsJob: completed', [
            'pipeline_run_id' => $this->run->id,
            'gsc_results' => count($gscData),
            'bing_results' => count($bingData),
        ]);
    }
}
