<?php

declare(strict_types=1);

namespace Core45\AiPosts\Contracts\Seo;

interface SearchesSerp
{
    /**
     * Execute a SERP search and return the full parsed response.
     *
     * @param  array<string, mixed>  $options  Keys: location, country, language, num, start
     * @return array<string, mixed>|null
     */
    public function search(string $query, string $engine = 'google', array $options = []): ?array;
}
