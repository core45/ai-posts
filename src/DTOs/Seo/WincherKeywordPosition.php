<?php

declare(strict_types=1);

namespace Core45\AiPosts\DTOs\Seo;

final readonly class WincherKeywordPosition
{
    public function __construct(
        public string $keyword,
        public int $currentPosition,
        public int $previousPosition,
        public int $change,
        public int $searchVolume,
        public ?string $url,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromApiRow(array $data): self
    {
        $current = (int) ($data['position'] ?? $data['current_position'] ?? 0);
        $previous = (int) ($data['previous_position'] ?? $current);

        return new self(
            keyword: (string) ($data['keyword'] ?? ''),
            currentPosition: $current,
            previousPosition: $previous,
            change: $previous - $current,
            searchVolume: (int) ($data['search_volume'] ?? 0),
            url: isset($data['url']) ? (string) $data['url'] : null,
        );
    }

    public function getChangeDirection(): string
    {
        if ($this->change > 0) {
            return 'up';
        }

        if ($this->change < 0) {
            return 'down';
        }

        return 'neutral';
    }

    public function getPositionBadgeColor(): string
    {
        return match (true) {
            $this->currentPosition >= 1 && $this->currentPosition <= 10 => 'success',
            $this->currentPosition >= 11 && $this->currentPosition <= 20 => 'warning',
            $this->currentPosition >= 21 && $this->currentPosition <= 50 => 'orange',
            default => 'danger',
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'keyword' => $this->keyword,
            'current_position' => $this->currentPosition,
            'previous_position' => $this->previousPosition,
            'change' => $this->change,
            'search_volume' => $this->searchVolume,
            'url' => $this->url,
        ];
    }
}
