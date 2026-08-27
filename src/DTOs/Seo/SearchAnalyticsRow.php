<?php

declare(strict_types=1);

namespace Core45\AiPosts\DTOs\Seo;

final readonly class SearchAnalyticsRow
{
    public function __construct(
        public string $query,
        public string $page,
        public int $clicks,
        public int $impressions,
        public float $ctr,
        public float $position,
        public string $date,
        public string $country = '',
    ) {}

    /**
     * @param  array{keys?: array<int, string>, clicks?: int, impressions?: int, ctr?: float, position?: float}  $row
     * @param  array<int, string>  $dimensions
     */
    public static function fromApiRow(array $row, array $dimensions): self
    {
        $keys = $row['keys'] ?? [];
        $queryIndex = array_search('query', $dimensions);
        $pageIndex = array_search('page', $dimensions);
        $dateIndex = array_search('date', $dimensions);
        $countryIndex = array_search('country', $dimensions);

        return new self(
            query: $queryIndex !== false ? ($keys[$queryIndex] ?? '') : '',
            page: $pageIndex !== false ? ($keys[$pageIndex] ?? '') : '',
            clicks: (int) ($row['clicks'] ?? 0),
            impressions: (int) ($row['impressions'] ?? 0),
            ctr: (float) ($row['ctr'] ?? 0.0),
            position: (float) ($row['position'] ?? 0.0),
            date: $dateIndex !== false ? ($keys[$dateIndex] ?? '') : '',
            country: $countryIndex !== false ? ($keys[$countryIndex] ?? '') : '',
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'query' => $this->query,
            'page' => $this->page,
            'clicks' => $this->clicks,
            'impressions' => $this->impressions,
            'ctr' => $this->ctr,
            'position' => $this->position,
            'date' => $this->date,
            'country' => $this->country,
        ];
    }
}
