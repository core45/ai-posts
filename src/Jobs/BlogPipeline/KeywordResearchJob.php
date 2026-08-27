<?php

declare(strict_types=1);

namespace Core45\AiPosts\Jobs\BlogPipeline;

use Core45\AiPosts\Contracts\Seo\ProvidesKeywordMetrics;
use Core45\AiPosts\Contracts\Seo\SearchesSerp;
use Core45\AiPosts\Models\BlogPostPipelineRun;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class KeywordResearchJob implements ShouldQueue
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

    public function handle(SearchesSerp $serpApi, ProvidesKeywordMetrics $semrush): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $keyword = $this->resolvePrimaryKeyword();

        $secondaryKeywords = [];
        $longTailQueries = [];
        $keywordDifficulty = [];
        $searchVolume = [];

        /** @var array<string, mixed> $rc */
        $rc = $this->run->config_snapshot['research_config'] ?? [];
        $serpEnabled = !empty($rc['enable_serp_analysis']);

        try {
            $serpResults = $serpEnabled ? $serpApi->search($keyword) : null;

            if ($serpResults !== null) {
                /** @var array<int, array<string, mixed>> $relatedSearches */
                $relatedSearches = $serpResults['related_searches'] ?? [];
                foreach ($relatedSearches as $related) {
                    if (isset($related['query']) && is_string($related['query'])) {
                        $secondaryKeywords[] = $related['query'];
                    }
                }

                /** @var array<int, array<string, mixed>> $relatedQuestions */
                $relatedQuestions = $serpResults['related_questions'] ?? [];
                foreach ($relatedQuestions as $question) {
                    if (isset($question['question']) && is_string($question['question'])) {
                        $longTailQueries[] = $question['question'];
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::warning('KeywordResearchJob: SERP search failed', [
                'pipeline_run_id' => $this->run->id,
                'keyword' => $keyword,
                'error' => $e->getMessage(),
            ]);
        }

        try {
            if ($semrush->isEnabled()) {
                $difficulty = $semrush->getKeywordDifficulty($keyword);
                if ($difficulty !== []) {
                    $keywordDifficulty = $difficulty;
                    $searchVolume['primary'] = $difficulty['volume'] ?? 0;
                }
            }
        } catch (\Throwable $e) {
            Log::warning('KeywordResearchJob: Semrush failed', [
                'pipeline_run_id' => $this->run->id,
                'error' => $e->getMessage(),
            ]);
        }

        $this->run->storeResearchData('keywords', [
            'primary_keyword' => $keyword,
            'secondary_keywords' => array_slice($secondaryKeywords, 0, 20),
            'long_tail_queries' => array_slice($longTailQueries, 0, 10),
            'keyword_difficulty' => $keywordDifficulty,
            'search_volume' => $searchVolume,
        ]);

        Log::info('KeywordResearchJob: completed', [
            'pipeline_run_id' => $this->run->id,
            'secondary_count' => count($secondaryKeywords),
        ]);
    }

    private function resolvePrimaryKeyword(): string
    {
        $candidate = $this->run->candidate;

        return $candidate->title ?? 'untitled';
    }
}
