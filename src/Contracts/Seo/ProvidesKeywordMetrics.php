<?php

declare(strict_types=1);

namespace Core45\AiPosts\Contracts\Seo;

interface ProvidesKeywordMetrics
{
    /**
     * Whether keyword metrics lookup is enabled and configured.
     */
    public function isEnabled(): bool;

    /**
     * Get keyword difficulty score.
     *
     * @return array<string, float|string>
     */
    public function getKeywordDifficulty(string $keyword, string $database = 'us'): array;
}
