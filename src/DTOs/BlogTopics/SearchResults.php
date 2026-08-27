<?php

declare(strict_types=1);

namespace Core45\AiPosts\DTOs\BlogTopics;

use Core45\AiCore\DTOs\SearchResult;

final readonly class SearchResults
{
    /**
     * @param  array<SearchResult>  $results
     */
    public function __construct(
        public string $query,
        public array $results,
        public string $provider,
        public ?string $rawResponse = null,
    ) {}
}
