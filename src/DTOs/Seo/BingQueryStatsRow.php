<?php

declare(strict_types=1);

namespace Core45\AiPosts\DTOs\Seo;

use Carbon\Carbon;

final readonly class BingQueryStatsRow
{
    public function __construct(
        public string $query,
        public int $impressions,
        public int $clicks,
        public float $ctr,
        public float $position,
        public string $date,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromApiRow(array $data): self
    {
        $impressions = (int) ($data['Impressions'] ?? 0);
        $clicks = (int) ($data['Clicks'] ?? 0);
        $ctr = $impressions > 0 ? $clicks / $impressions : 0.0;

        return new self(
            query: (string) ($data['Query'] ?? ''),
            impressions: $impressions,
            clicks: $clicks,
            ctr: $ctr,
            position: (float) ($data['AvgClickPosition'] ?? 0.0),
            date: self::parseMicrosoftDate((string) ($data['Date'] ?? '')),
        );
    }

    /**
     * Parse Microsoft JSON date format: /Date(1316156400000-0700)/
     */
    private static function parseMicrosoftDate(string $dateStr): string
    {
        if (preg_match('/\/Date\((\d+)([+-]\d+)?\)\//', $dateStr, $matches)) {
            return Carbon::createFromTimestampMs((int) $matches[1])->format('Y-m-d');
        }

        return now()->format('Y-m-d');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'query' => $this->query,
            'impressions' => $this->impressions,
            'clicks' => $this->clicks,
            'ctr' => $this->ctr,
            'position' => $this->position,
            'date' => $this->date,
        ];
    }
}
