<?php

declare(strict_types=1);

namespace Core45\AiPosts\Contracts\BlogTopics;

use Core45\AiPosts\DTOs\BlogTopics\SearchResults;

interface SearchResearchProvider
{
    /**
     * Execute a search query and return structured results with sources.
     *
     * @param  array<string, mixed>  $options
     */
    public function search(string $query, array $options = []): SearchResults;

    /**
     * Get the provider name for audit logging.
     */
    public function getProviderName(): string;
}
