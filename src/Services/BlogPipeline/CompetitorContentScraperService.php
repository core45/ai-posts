<?php

declare(strict_types=1);

namespace Core45\AiPosts\Services\BlogPipeline;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CompetitorContentScraperService
{
    private const int TIMEOUT_SECONDS = 15;

    private const int MAX_CONTENT_BYTES = 512_000;

    /**
     * Scrape a single URL and extract structural information.
     *
     * @return array{url: string, title: string, headings: array<string>, word_count: int, links_count: int, error: string|null}
     */
    public function scrape(string $url): array
    {
        $empty = [
            'url' => $url,
            'title' => '',
            'headings' => [],
            'word_count' => 0,
            'links_count' => 0,
            'error' => null,
        ];

        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (compatible; BlogResearchBot/1.0)',
                    'Accept' => 'text/html',
                ])
                ->get($url);

            if ($response->failed()) {
                $empty['error'] = "HTTP {$response->status()}";

                return $empty;
            }

            $body = $response->body();

            if (strlen($body) > self::MAX_CONTENT_BYTES) {
                $body = substr($body, 0, self::MAX_CONTENT_BYTES);
            }

            return $this->parseHtml($url, $body);
        } catch (\Throwable $e) {
            Log::warning('CompetitorContentScraperService: scrape failed', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            $empty['error'] = $e->getMessage();

            return $empty;
        }
    }

    /**
     * Analyze multiple scraped pages and aggregate statistics.
     *
     * @param  array<int, array{url: string, title: string, headings: array<string>, word_count: int, links_count: int, error: string|null}>  $scrapedPages
     * @return array{avg_word_count: int, common_topics: array<string>, content_gaps: array<string>, pages_analyzed: int, pages_failed: int}
     */
    public function analyze(array $scrapedPages): array
    {
        $successful = array_filter($scrapedPages, fn (array $page): bool => $page['error'] === null && $page['word_count'] > 0);
        $failed = count($scrapedPages) - count($successful);

        if ($successful === []) {
            return [
                'avg_word_count' => 0,
                'common_topics' => [],
                'content_gaps' => [],
                'pages_analyzed' => 0,
                'pages_failed' => $failed,
            ];
        }

        $totalWords = array_sum(array_column($successful, 'word_count'));
        $avgWords = (int) round($totalWords / count($successful));

        $allHeadings = [];
        foreach ($successful as $page) {
            foreach ($page['headings'] as $heading) {
                $normalized = mb_strtolower(trim($heading));
                if ($normalized !== '') {
                    $allHeadings[$normalized] = ($allHeadings[$normalized] ?? 0) + 1;
                }
            }
        }

        arsort($allHeadings);
        $commonTopics = array_slice(array_keys($allHeadings), 0, 15);

        return [
            'avg_word_count' => $avgWords,
            'common_topics' => $commonTopics,
            'content_gaps' => [],
            'pages_analyzed' => count($successful),
            'pages_failed' => $failed,
        ];
    }

    /**
     * Parse HTML content and extract structural data.
     *
     * @return array{url: string, title: string, headings: array<string>, word_count: int, links_count: int, error: string|null}
     */
    private function parseHtml(string $url, string $html): array
    {
        libxml_use_internal_errors(true);

        $dom = new \DOMDocument;
        $dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOWARNING | LIBXML_NOERROR);

        libxml_clear_errors();

        $title = '';
        $titleElements = $dom->getElementsByTagName('title');
        if ($titleElements->length > 0) {
            $titleNode = $titleElements->item(0);
            $title = $titleNode !== null ? trim($titleNode->textContent) : '';
        }

        $headings = [];
        foreach (['h1', 'h2', 'h3'] as $tag) {
            $elements = $dom->getElementsByTagName($tag);
            for ($i = 0; $i < $elements->length; $i++) {
                $node = $elements->item($i);
                $text = $node !== null ? trim($node->textContent) : '';
                if ($text !== '') {
                    $headings[] = $text;
                }
            }
        }

        $bodyElements = $dom->getElementsByTagName('body');
        $bodyText = '';
        if ($bodyElements->length > 0) {
            $bodyNode = $bodyElements->item(0);
            $bodyText = $bodyNode !== null ? $bodyNode->textContent : '';
        }
        $wordCount = str_word_count(strip_tags($bodyText));

        $linksCount = $dom->getElementsByTagName('a')->length;

        return [
            'url' => $url,
            'title' => $title,
            'headings' => $headings,
            'word_count' => $wordCount,
            'links_count' => $linksCount,
            'error' => null,
        ];
    }
}
