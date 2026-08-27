<?php

declare(strict_types=1);

namespace Core45\AiPosts\Support\Seo;

use Core45\AiPosts\Contracts\Seo\ProvidesTopicTrends;
use Illuminate\Support\Collection;

/**
 * The default when the host has not bound its own trending-topics provider.
 *
 * `SerpApiTopicEnrichmentService` takes `ProvidesTopicTrends` as a
 * constructor dependency, and `GenerateSearchQueriesJob`/`SynthesizeTopicsJob`
 * both method-inject that service on their ordinary `handle()` signature —
 * with no config check gating the injection itself, resolving either job
 * threw `BindingResolutionException` on a fresh install the moment the host
 * generated its first blog topic batch, regardless of whether it had opted
 * into trend enrichment.
 *
 * `isEnabled()` is this interface's capability probe (SerpApi trends are a
 * paid third-party service no new application has credentials for on day
 * one), so "this application has no trend data" is already expressible: the
 * flag returns false and the empty collections are simply never inspected by
 * a caller that checks the flag first.
 *
 * A host with a SerpApi (or similar) integration binds its own
 * implementation; `bindIf` leaves it alone.
 */
final class NullTopicTrendsProvider implements ProvidesTopicTrends
{
    /**
     * @return Collection<int, never>
     */
    public function getTrendingTopics(?string $country = null): Collection
    {
        return collect();
    }

    /**
     * @return Collection<int, never>
     */
    public function getAutocompleteSuggestions(string $query): Collection
    {
        return collect();
    }

    public function isEnabled(): bool
    {
        return false;
    }
}
