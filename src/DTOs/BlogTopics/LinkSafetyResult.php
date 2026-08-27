<?php

declare(strict_types=1);

namespace Core45\AiPosts\DTOs\BlogTopics;

final readonly class LinkSafetyResult
{
    public function __construct(
        public bool $safe,
        public string $reason,
        public float $confidence,
    ) {}
}
