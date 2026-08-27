<?php

declare(strict_types=1);

namespace Core45\AiPosts\DTOs\Seo;

final readonly class SerpApiTrend
{
    /**
     * @param  array<string>|null  $trendBreakdown
     * @param  array<array{title: string, url: string}>|null  $newsLinks
     * @param  array<string>|null  $relatedQueries
     */
    public function __construct(
        public string $query,
        public int $searchVolume,
        public ?int $increasePercentage,
        public ?array $trendBreakdown,
        public ?array $newsLinks,
        public ?array $relatedQueries,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromApiRow(array $data): self
    {
        return new self(
            query: (string) ($data['query'] ?? ''),
            searchVolume: (int) ($data['search_volume'] ?? 0),
            increasePercentage: isset($data['increase_percentage']) ? (int) $data['increase_percentage'] : null,
            trendBreakdown: is_array($data['trend_breakdown'] ?? null) ? $data['trend_breakdown'] : null,
            newsLinks: $data['serpapi_news_link'] ?? null,
            relatedQueries: $data['related_queries'] ?? null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'query' => $this->query,
            'search_volume' => $this->searchVolume,
            'increase_percentage' => $this->increasePercentage,
            'trend_breakdown' => $this->trendBreakdown,
            'news_links' => $this->newsLinks,
            'related_queries' => $this->relatedQueries,
        ];
    }
}
