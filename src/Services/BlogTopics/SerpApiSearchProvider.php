<?php

declare(strict_types=1);

namespace Core45\AiPosts\Services\BlogTopics;

use Core45\AiCore\DTOs\SearchResult;
use Core45\AiPosts\Contracts\BlogTopics\SearchResearchProvider;
use Core45\AiPosts\Contracts\Seo\SearchesSerp;
use Core45\AiPosts\DTOs\BlogTopics\SearchResults;
use Core45\AiPosts\Models\BlogTopicBatch;
use Illuminate\Support\Facades\Log;

class SerpApiSearchProvider implements SearchResearchProvider
{
    public function __construct(
        private readonly SearchesSerp $searchService,
    ) {}

    /**
     * @param  array<string, mixed>  $options
     */
    public function search(string $query, array $options = []): SearchResults
    {
        $response = $this->searchService->search($query, 'google', $options);

        if ($response === null) {
            return new SearchResults(query: $query, results: [], provider: $this->getProviderName());
        }

        $results = [];

        // Map organic results to SearchResult DTOs
        $organicResults = $response['organic_results'] ?? [];
        if (is_array($organicResults)) {
            foreach ($organicResults as $item) {
                if (!is_array($item) || !isset($item['link'])) {
                    continue;
                }

                $results[] = new SearchResult(
                    url: (string) $item['link'],
                    domain: (string) (parse_url($item['link'], PHP_URL_HOST) ?? ''),
                    title: (string) ($item['title'] ?? ''),
                    snippet: (string) ($item['snippet'] ?? ''),
                    publishedAt: isset($item['date']) ? (string) $item['date'] : null,
                    reliabilityScore: $this->calculateReliabilityScore($item),
                );
            }
        }

        // Map People Also Ask to additional SearchResult entries
        $relatedQuestions = $response['related_questions'] ?? [];
        if (is_array($relatedQuestions)) {
            foreach ($relatedQuestions as $question) {
                if (!is_array($question) || !isset($question['link'])) {
                    continue;
                }

                $results[] = new SearchResult(
                    url: (string) $question['link'],
                    domain: (string) (parse_url($question['link'], PHP_URL_HOST) ?? ''),
                    title: (string) ($question['question'] ?? ''),
                    snippet: (string) ($question['snippet'] ?? ''),
                    reliabilityScore: 0.6,
                );
            }
        }

        // Store related_searches in batch config_json if batch_id provided
        $relatedSearches = $response['related_searches'] ?? [];
        if (is_array($relatedSearches) && $relatedSearches !== [] && isset($options['batch_id'])) {
            $this->storeRelatedSearches((int) $options['batch_id'], $relatedSearches);
        }

        return new SearchResults(
            query: $query,
            results: $results,
            provider: $this->getProviderName(),
        );
    }

    public function getProviderName(): string
    {
        return 'serpapi';
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function calculateReliabilityScore(array $item): float
    {
        $score = 0.5;

        // Has a date = more likely a recent, relevant source
        if (isset($item['date'])) {
            $score += 0.1;
        }

        // Longer snippet = more content context
        $snippetLength = mb_strlen($item['snippet'] ?? '');
        if ($snippetLength > 100) {
            $score += 0.1;
        }

        // Has sitelinks = authoritative domain
        if (isset($item['sitelinks'])) {
            $score += 0.2;
        }

        return min($score, 1.0);
    }

    /**
     * @param  array<int, array<string, mixed>>  $relatedSearches
     */
    private function storeRelatedSearches(int $batchId, array $relatedSearches): void
    {
        try {
            $batch = BlogTopicBatch::find($batchId);
            if ($batch === null) {
                return;
            }

            /** @var array<string, mixed> $configJson */
            $configJson = $batch->getAttribute('config_json') ?? [];

            /** @var array<string> $existing */
            $existing = $configJson['related_searches'] ?? [];

            $newSearches = [];
            foreach ($relatedSearches as $search) {
                if (isset($search['query'])) {
                    $newSearches[] = (string) $search['query'];
                }
            }

            $configJson['related_searches'] = array_values(array_unique(array_merge($existing, $newSearches)));
            $batch->update(['config_json' => $configJson]);
        } catch (\Throwable $e) {
            Log::warning('SerpApiSearchProvider: Failed to store related searches', [
                'batch_id' => $batchId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
