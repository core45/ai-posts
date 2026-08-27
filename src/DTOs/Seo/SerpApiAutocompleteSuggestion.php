<?php

declare(strict_types=1);

namespace Core45\AiPosts\DTOs\Seo;

final readonly class SerpApiAutocompleteSuggestion
{
    public function __construct(
        public string $value,
        public string $type,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromApiRow(array $data): self
    {
        return new self(
            value: (string) ($data['value'] ?? $data['suggestion'] ?? ''),
            type: (string) ($data['type'] ?? 'suggestion'),
        );
    }
}
