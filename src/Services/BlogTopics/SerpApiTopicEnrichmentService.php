<?php

declare(strict_types=1);

namespace Core45\AiPosts\Services\BlogTopics;

use Core45\AiPosts\Contracts\Seo\ProvidesTopicTrends;
use Core45\AiPosts\Models\BlogTopicBatch;
use Illuminate\Support\Facades\Log;

class SerpApiTopicEnrichmentService
{
    public function __construct(
        private readonly ProvidesTopicTrends $trends,
    ) {}

    /**
     * Expand profile seed keywords via Google Autocomplete.
     * Writes results to batch config_json.expanded_keywords.
     * No-op when SerpAPI is disabled or profile enrichment is off.
     */
    public function expandKeywords(BlogTopicBatch $batch): void
    {
        if (!$this->shouldEnrich($batch)) {
            return;
        }

        $profile = $batch->profile;
        /** @var array<string> $seedKeywords */
        $seedKeywords = $profile->seed_keywords ?? [];

        if ($seedKeywords === []) {
            return;
        }

        $expanded = [];

        foreach ($seedKeywords as $keyword) {
            $suggestions = $this->trends->getAutocompleteSuggestions((string) $keyword);

            foreach ($suggestions as $suggestion) {
                $expanded[] = $suggestion->value;
            }
        }

        if ($expanded === []) {
            return;
        }

        /** @var array<string, mixed> $configJson */
        $configJson = $batch->getAttribute('config_json') ?? [];
        $configJson['expanded_keywords'] = array_values(array_unique($expanded));
        $batch->update(['config_json' => $configJson]);

        Log::info('SerpApiTopicEnrichmentService: Keywords expanded', [
            'batch_id' => $batch->id,
            'seed_count' => count($seedKeywords),
            'expanded_count' => count($configJson['expanded_keywords']),
        ]);
    }

    /**
     * Inject trending topics into batch config_json.trending_topics.
     * No-op when SerpAPI is disabled or profile enrichment is off.
     */
    public function injectTrends(BlogTopicBatch $batch): void
    {
        if (!$this->shouldEnrich($batch)) {
            return;
        }

        $topics = $this->trends->getTrendingTopics();

        if ($topics->isEmpty()) {
            return;
        }

        /** @var array<string, mixed> $configJson */
        $configJson = $batch->getAttribute('config_json') ?? [];
        $configJson['trending_topics'] = $topics->map(fn ($t) => [
            'query' => $t->query,
            'volume' => $t->searchVolume,
        ])->toArray();
        $batch->update(['config_json' => $configJson]);

        Log::info('SerpApiTopicEnrichmentService: Trends injected', [
            'batch_id' => $batch->id,
            'trend_count' => count($configJson['trending_topics']),
        ]);
    }

    private function shouldEnrich(BlogTopicBatch $batch): bool
    {
        if (!$this->trends->isEnabled()) {
            return false;
        }

        $profile = $batch->profile;
        /** @var array<string, mixed> $configJson */
        $configJson = $profile->getAttribute('config_json') ?? [];

        return ($configJson['serpapi_enrichment'] ?? false) === true;
    }
}
