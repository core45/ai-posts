<?php

declare(strict_types=1);

namespace Core45\AiPosts\Contracts\Seo;

use Core45\AiPosts\DTOs\Seo\BingQueryStatsRow;
use Illuminate\Support\Collection;

interface ProvidesBingQueryStats
{
    /**
     * Whether Bing Webmaster query stats access is enabled and configured.
     */
    public function isEnabled(): bool;

    /**
     * Get the configured site URL, if any.
     */
    public function getSiteUrl(): ?string;

    /**
     * Fetch query stats for a site.
     *
     * @return Collection<int, BingQueryStatsRow>
     */
    public function fetchQueryStats(string $siteUrl): Collection;
}
