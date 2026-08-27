<?php

declare(strict_types=1);

namespace Core45\AiPosts\Contracts\Seo;

interface ResolvesUrlToModel
{
    /**
     * Resolve a URL to a model reference.
     *
     * @return array{type: string, id: int, locale: string}|null
     */
    public function resolve(string $url): ?array;
}
