<?php

declare(strict_types=1);

namespace Core45\AiPosts\Contracts\Seo;

use Illuminate\Database\Eloquent\Model;

interface SuggestsInternalLinks
{
    /**
     * Suggest internal linking opportunities for a given indexable model.
     *
     * @return array<int, array{indexable: Model, reason: string, relevanceScore: float}>
     */
    public function suggestLinks(Model $indexable, int $limit = 5, string $contentText = ''): array;
}
