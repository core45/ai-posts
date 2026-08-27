<?php

declare(strict_types=1);

namespace Core45\AiPosts\Services\BlogTopics;

use Core45\AiCore\Contracts\AiClient;
use Core45\AiCore\Services\RemoteAiClient;
use Core45\AiPosts\Contracts\BlogTopics\SearchResearchProvider;
use Core45\AiPosts\DTOs\BlogTopics\SearchResults;
use Illuminate\Support\Str;

/**
 * Phasea-grounded blog research search — a phasea seam consumer (Phase 32,
 * audit C1/C2): the host no longer calls generativelanguage.googleapis.com
 * or api.perplexity.ai directly, and holds no Gemini/Perplexity key.
 * `RemoteAiClient::groundedSearch()` posts phasea's
 * `api/v1/ai/search/grounded`; phasea owns the vendor key, resolves its own
 * default upstream backend, runs the grounding call, enforces budget, and
 * meters usage.
 *
 * v2.6: the host does not choose a search backend. This class replaces the
 * former `GeminiGroundedSearchProvider`/`PerplexitySearchProvider` pair —
 * once the `provider` argument was dropped from `AiClient::groundedSearch()`
 * (phasea decides the vendor server-side), the two classes issued an
 * identical request and became redundant. `getProviderName()` returns the
 * fixed `'phasea'` audit label (the same convention `GeneratesResearchText`/
 * `BlogPostWriterService`/`AiLinkSuggestionService` already use) rather than
 * a vendor guess the host can no longer make.
 */
class GroundedSearchProvider implements SearchResearchProvider
{
    private const DEFAULT_MAX_RESULTS = 10;

    public function __construct(
        private readonly AiClient $client,
    ) {}

    /**
     * @param  array<string, mixed>  $options
     */
    public function search(string $query, array $options = []): SearchResults
    {
        $maxResults = $options['max_results'] ?? null;

        $results = $this->client->groundedSearch(
            $query,
            is_int($maxResults) && $maxResults > 0 ? $maxResults : self::DEFAULT_MAX_RESULTS,
            (string) Str::uuid(),
        );

        return new SearchResults(
            query: $query,
            results: $results,
            provider: $this->getProviderName(),
        );
    }

    public function getProviderName(): string
    {
        return 'phasea';
    }
}
