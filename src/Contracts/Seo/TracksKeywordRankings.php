<?php

declare(strict_types=1);

namespace Core45\AiPosts\Contracts\Seo;

use Core45\AiPosts\DTOs\Seo\WincherKeywordPosition;
use Illuminate\Support\Collection;

interface TracksKeywordRankings
{
    /**
     * Whether keyword rank tracking is enabled and configured.
     */
    public function isEnabled(): bool;

    /**
     * Fetch current keyword positions.
     *
     * @return Collection<int, WincherKeywordPosition>
     */
    public function fetchKeywordPositions(): Collection;

    /**
     * Get keyword position history over time.
     *
     * @return array<string, mixed>
     */
    public function getKeywordHistory(string $keyword, int $days = 30): array;
}
