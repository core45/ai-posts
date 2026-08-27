<?php

declare(strict_types=1);

namespace Core45\AiPosts\Jobs\BlogPipeline;

use Core45\AiPosts\Contracts\Seo\SearchesSerp;
use Core45\AiPosts\Models\BlogPostPipelineRun;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class SerpAnalysisJob implements ShouldQueue
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

    public function handle(SearchesSerp $serpApi): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $keyword = $this->run->candidate->title ?? 'untitled';
        $serpFeatures = [];
        $topResults = [];
        $relatedSearches = [];
        $paaQuestions = [];

        try {
            $response = $serpApi->search($keyword);

            if ($response !== null) {
                if (isset($response['answer_box'])) {
                    $serpFeatures[] = 'featured_snippet';
                }
                if (isset($response['knowledge_graph'])) {
                    $serpFeatures[] = 'knowledge_graph';
                }
                if (isset($response['local_results'])) {
                    $serpFeatures[] = 'local_pack';
                }

                /** @var array<int, array<string, mixed>> $organicResults */
                $organicResults = $response['organic_results'] ?? [];
                foreach (array_slice($organicResults, 0, 10) as $result) {
                    $topResults[] = [
                        'position' => $result['position'] ?? null,
                        'title' => $result['title'] ?? '',
                        'url' => $result['link'] ?? '',
                        'snippet' => $result['snippet'] ?? '',
                        'domain' => is_string($result['link'] ?? null) ? parse_url($result['link'], PHP_URL_HOST) : '',
                    ];
                }

                /** @var array<int, array<string, mixed>> $related */
                $related = $response['related_searches'] ?? [];
                foreach ($related as $item) {
                    if (isset($item['query']) && is_string($item['query'])) {
                        $relatedSearches[] = $item['query'];
                    }
                }

                /** @var array<int, array<string, mixed>> $questions */
                $questions = $response['related_questions'] ?? [];
                foreach ($questions as $question) {
                    if (isset($question['question']) && is_string($question['question'])) {
                        $paaQuestions[] = $question['question'];
                        $serpFeatures[] = 'people_also_ask';
                    }
                }

                if ($relatedSearches !== []) {
                    $serpFeatures[] = 'related_searches';
                }
            }
        } catch (\Throwable $e) {
            Log::warning('SerpAnalysisJob: search failed', [
                'pipeline_run_id' => $this->run->id,
                'keyword' => $keyword,
                'error' => $e->getMessage(),
            ]);
        }

        $this->run->storeResearchData('serp', [
            'keyword' => $keyword,
            'serp_features' => array_values(array_unique($serpFeatures)),
            'top_results' => $topResults,
            'paa_questions' => $paaQuestions,
            'related_searches' => $relatedSearches,
        ]);

        Log::info('SerpAnalysisJob: completed', [
            'pipeline_run_id' => $this->run->id,
            'top_results_count' => count($topResults),
            'features_found' => count($serpFeatures),
        ]);
    }
}
