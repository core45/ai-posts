<?php

declare(strict_types=1);

namespace Core45\AiPosts\DTOs\BlogTopics;

final readonly class CitationCheckResult
{
    /**
     * @param  array<string>  $warnings
     * @param  array<string>  $uncitedClaims
     */
    public function __construct(
        public bool $passed,
        public array $warnings,
        public array $uncitedClaims,
        public int $totalClaims,
        public int $citedClaims,
    ) {}
}
