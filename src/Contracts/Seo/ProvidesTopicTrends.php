<?php

declare(strict_types=1);

namespace Core45\AiPosts\Contracts\Seo;

use Core45\AiPosts\DTOs\Seo\SerpApiAutocompleteSuggestion;
use Core45\AiPosts\DTOs\Seo\SerpApiTrend;
use Illuminate\Support\Collection;

interface ProvidesTopicTrends
{
    /**
     * Get currently trending topics.
     *
     * @return Collection<int, SerpApiTrend>
     */
    public function getTrendingTopics(?string $country = null): Collection;

    /**
     * Get autocomplete suggestions for a query.
     *
     * @return Collection<int, SerpApiAutocompleteSuggestion>
     */
    public function getAutocompleteSuggestions(string $query): Collection;

    /**
     * Check whether the underlying topic trends provider is enabled.
     */
    public function isEnabled(): bool;
}
