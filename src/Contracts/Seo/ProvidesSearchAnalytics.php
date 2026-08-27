<?php

declare(strict_types=1);

namespace Core45\AiPosts\Contracts\Seo;

use Core45\AiPosts\DTOs\Seo\SearchAnalyticsRow;
use Illuminate\Support\Collection;

interface ProvidesSearchAnalytics
{
    /**
     * Whether a search analytics connection is active.
     */
    public function isConnected(): bool;

    /**
     * Fetch search analytics data.
     *
     * @param  array<int, string>  $dimensions
     * @return Collection<int, SearchAnalyticsRow>
     */
    public function fetchSearchAnalytics(
        string $siteUrl,
        string $startDate,
        string $endDate,
        array $dimensions = ['query', 'page', 'date', 'country'],
        int $rowLimit = 25000,
    ): Collection;
}
