<?php

declare(strict_types=1);

namespace Core45\AiPosts\Services\BlogTopics;

use Core45\AiCore\DTOs\SearchResult;
use Core45\AiPosts\Contracts\BlogTopics\SearchResearchProvider;
use Core45\AiPosts\DTOs\BlogTopics\SearchResults;
use Illuminate\Support\Facades\Log;
use Throwable;

class CompositeSearchProvider implements SearchResearchProvider
{
    /**
     * @param  array<SearchResearchProvider>  $providers
     */
    public function __construct(
        private readonly array $providers,
    ) {}

    /**
     * @param  array<string, mixed>  $options
     */
    public function search(string $query, array $options = []): SearchResults
    {
        /** @var array<string, SearchResult> $uniqueResults */
        $uniqueResults = [];

        foreach ($this->providers as $provider) {
            try {
                $providerResults = $provider->search($query, $options);

                foreach ($providerResults->results as $result) {
                    $urlHash = hash('sha256', $result->url);

                    if (!isset($uniqueResults[$urlHash])) {
                        $uniqueResults[$urlHash] = $result;
                    }
                }
            } catch (Throwable $e) {
                Log::warning('Search provider failed, continuing with remaining providers', [
                    'provider' => $provider->getProviderName(),
                    'error' => $e->getMessage(),
                    'query' => $query,
                ]);
            }
        }

        return new SearchResults(
            query: $query,
            results: array_values($uniqueResults),
            provider: $this->getProviderName(),
        );
    }

    public function getProviderName(): string
    {
        return implode('+', array_map(
            fn (SearchResearchProvider $provider): string => $provider->getProviderName(),
            $this->providers,
        ));
    }
}
