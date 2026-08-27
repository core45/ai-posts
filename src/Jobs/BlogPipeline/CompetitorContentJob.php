<?php

declare(strict_types=1);

namespace Core45\AiPosts\Jobs\BlogPipeline;

use Core45\AiPosts\Contracts\Seo\SearchesSerp;
use Core45\AiPosts\Models\BlogPostPipelineRun;
use Core45\AiPosts\Services\BlogPipeline\CompetitorContentScraperService;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class CompetitorContentJob implements ShouldQueue
{
    use Batchable;
    use Queueable;

    public int $tries = 2;

    public int $timeout = 180;

    private const int MAX_PAGES_TO_SCRAPE = 5;

    public function __construct(
        public BlogPostPipelineRun $run,
    ) {
        $this->onQueue('ai-bulk');
    }

    public function handle(SearchesSerp $serpApi, CompetitorContentScraperService $scraper): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $keyword = $this->run->candidate->title ?? 'untitled';
        $urls = [];

        try {
            $response = $serpApi->search($keyword);

            if ($response !== null) {
                /** @var array<int, array<string, mixed>> $organicResults */
                $organicResults = $response['organic_results'] ?? [];
                foreach (array_slice($organicResults, 0, self::MAX_PAGES_TO_SCRAPE) as $result) {
                    if (isset($result['link']) && is_string($result['link'])) {
                        $urls[] = $result['link'];
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::warning('CompetitorContentJob: SERP search failed', [
                'pipeline_run_id' => $this->run->id,
                'error' => $e->getMessage(),
            ]);
        }

        if ($urls === []) {
            $this->run->storeResearchData('competitors', [
                'avg_word_count' => 0,
                'common_topics' => [],
                'content_gaps' => [],
                'pages_analyzed' => 0,
                'pages_failed' => 0,
                'pages' => [],
            ]);

            return;
        }

        $scrapedPages = [];
        foreach ($urls as $url) {
            $scrapedPages[] = $scraper->scrape($url);
        }

        $analysis = $scraper->analyze($scrapedPages);

        $this->run->storeResearchData('competitors', array_merge($analysis, [
            'pages' => array_map(fn (array $page): array => [
                'url' => $page['url'],
                'title' => $page['title'],
                'word_count' => $page['word_count'],
                'headings_count' => count($page['headings']),
                'error' => $page['error'],
            ], $scrapedPages),
        ]));

        Log::info('CompetitorContentJob: completed', [
            'pipeline_run_id' => $this->run->id,
            'pages_analyzed' => $analysis['pages_analyzed'],
            'avg_word_count' => $analysis['avg_word_count'],
        ]);
    }
}
