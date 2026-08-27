<?php

declare(strict_types=1);

namespace Core45\AiPosts\Jobs\BlogPipeline;

use Core45\AiCore\Models\AIKnowledgeGap;
use Core45\AiPosts\Contracts\Seo\SearchesSerp;
use Core45\AiPosts\Models\BlogPostPipelineRun;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class UserIntentResearchJob implements ShouldQueue
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
        $userQuestions = [];
        $intentDistribution = [
            'informational' => 0,
            'navigational' => 0,
            'transactional' => 0,
            'commercial' => 0,
        ];

        /** @var array<string, mixed> $rc */
        $rc = $this->run->config_snapshot['research_config'] ?? [];
        $serpEnabled = !empty($rc['enable_serp_analysis']);

        try {
            $response = $serpEnabled ? $serpApi->search($keyword) : null;

            if ($response !== null) {
                /** @var array<int, array<string, mixed>> $relatedQuestions */
                $relatedQuestions = $response['related_questions'] ?? [];
                foreach ($relatedQuestions as $question) {
                    if (isset($question['question']) && is_string($question['question'])) {
                        $userQuestions[] = $question['question'];
                    }
                }

                $intentDistribution = $this->classifyIntent($response);
            }
        } catch (\Throwable $e) {
            Log::warning('UserIntentResearchJob: SERP search failed', [
                'pipeline_run_id' => $this->run->id,
                'error' => $e->getMessage(),
            ]);
        }

        $knowledgeGaps = $this->fetchKnowledgeGaps($keyword);

        $this->run->storeResearchData('user_intent', [
            'user_questions' => $userQuestions,
            'knowledge_gaps' => $knowledgeGaps,
            'intent_distribution' => $intentDistribution,
        ]);

        Log::info('UserIntentResearchJob: completed', [
            'pipeline_run_id' => $this->run->id,
            'questions_found' => count($userQuestions),
            'gaps_found' => count($knowledgeGaps),
        ]);
    }

    /**
     * Classify search intent based on SERP features.
     *
     * @param  array<string, mixed>  $serpResponse
     * @return array{informational: int, navigational: int, transactional: int, commercial: int}
     */
    private function classifyIntent(array $serpResponse): array
    {
        $distribution = [
            'informational' => 0,
            'navigational' => 0,
            'transactional' => 0,
            'commercial' => 0,
        ];

        if (isset($serpResponse['answer_box']) || isset($serpResponse['knowledge_graph'])) {
            $distribution['informational'] += 2;
        }

        if (isset($serpResponse['related_questions'])) {
            $distribution['informational'] += 1;
        }

        if (isset($serpResponse['shopping_results']) || isset($serpResponse['ads'])) {
            $distribution['transactional'] += 2;
            $distribution['commercial'] += 1;
        }

        if (isset($serpResponse['local_results'])) {
            $distribution['navigational'] += 1;
        }

        /** @var array<int, array<string, mixed>> $organicResults */
        $organicResults = $serpResponse['organic_results'] ?? [];
        foreach (array_slice($organicResults, 0, 5) as $result) {
            $snippet = mb_strtolower($result['snippet'] ?? '');
            if (str_contains($snippet, 'how to') || str_contains($snippet, 'what is') || str_contains($snippet, 'guide')) {
                $distribution['informational']++;
            }
            if (str_contains($snippet, 'buy') || str_contains($snippet, 'price') || str_contains($snippet, 'shop')) {
                $distribution['transactional']++;
            }
            if (str_contains($snippet, 'best') || str_contains($snippet, 'review') || str_contains($snippet, 'compare')) {
                $distribution['commercial']++;
            }
        }

        return $distribution;
    }

    /**
     * Fetch relevant knowledge gaps from AIKnowledgeGap model.
     *
     * @return array<int, array{question: string, occurrence_count: int}>
     */
    private function fetchKnowledgeGaps(string $keyword): array
    {
        try {
            if (!Schema::hasTable('ai_knowledge_gaps')) {
                return [];
            }

            return AIKnowledgeGap::query()
                ->open()
                ->where('question', 'like', '%'.$keyword.'%')
                ->orderByDesc('occurrence_count')
                ->limit(10)
                ->get()
                ->map(fn (AIKnowledgeGap $gap): array => [
                    'question' => $gap->question,
                    'occurrence_count' => $gap->occurrence_count,
                ])
                ->all();
        } catch (\Throwable $e) {
            Log::warning('UserIntentResearchJob: knowledge gaps query failed', [
                'pipeline_run_id' => $this->run->id,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }
}
