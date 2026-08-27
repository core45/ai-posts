<?php

declare(strict_types=1);

namespace Core45\AiPosts\DTOs\BlogPipeline;

final readonly class ModuleOutput
{
    /**
     * @param  array<int, array{marker: int, title: string, url: string}>  $citations
     */
    public function __construct(
        public string $content,
        public string $summary,
        public array $citations = [],
        public string $provider = '',
        public string $model = '',
        public int $tokensUsed = 0,
        public float $durationSeconds = 0,
        public float $costEstimate = 0,
    ) {}
}
