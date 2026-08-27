<?php

declare(strict_types=1);

namespace Core45\AiPosts\Services\BlogPipeline;

use Core45\AiPosts\Contracts\Seo\ResolvesUrlToModel;
use Core45\AiPosts\Contracts\Seo\ScansInternalLinks;
use Illuminate\Support\Facades\Log;

class LinkVerificationService
{
    private const MIN_LINK_COUNT = 3;

    public function __construct(
        private readonly ScansInternalLinks $linkScanner,
        private readonly ResolvesUrlToModel $urlModelResolver,
    ) {}

    /**
     * Verify all internal links in assembled HTML.
     *
     * Only checks real internal links (not fragment/anchor links like #cite-1).
     * Removes broken internal links, deduplicates, and checks minimum count.
     *
     * @return array{html: string, verified_count: int, removed_count: int, warnings: string[]}
     */
    public function verify(string $html, string $locale): array
    {
        $appUrl = (string) config('app.url');
        $preScan = $this->linkScanner->scanContent($html, $appUrl);
        $preCount = $preScan->internalLinkCount;

        /** @var array<int, string> $warnings */
        $warnings = [];

        $html = $this->removeBrokenInternalLinks($html, $appUrl, $warnings);
        $html = $this->removeDuplicateLinks($html, $appUrl, $warnings);

        $postScan = $this->linkScanner->scanContent($html, $appUrl);
        $verifiedCount = $postScan->internalLinkCount;
        $removedCount = $preCount - $verifiedCount;

        if ($verifiedCount < self::MIN_LINK_COUNT) {
            $warnings[] = "Low internal link count: {$verifiedCount} (recommended minimum: ".self::MIN_LINK_COUNT.')';
        }

        Log::info('LinkVerificationService: Verification complete', [
            'locale' => $locale,
            'pre_link_count' => $preCount,
            'verified_count' => $verifiedCount,
            'removed_count' => $removedCount,
            'warnings_count' => count($warnings),
        ]);

        return [
            'html' => $html,
            'verified_count' => $verifiedCount,
            'removed_count' => $removedCount,
            'warnings' => $warnings,
        ];
    }

    /**
     * Check each internal link against the ResolvesUrlToModel and remove broken ones.
     *
     * @param  array<int, string>  $warnings
     */
    private function removeBrokenInternalLinks(string $html, string $appUrl, array &$warnings): string
    {
        $scanResult = $this->linkScanner->scanContent($html, $appUrl);

        foreach ($scanResult->internalLinks as $link) {
            $url = $link['url'];
            $resolved = $this->urlModelResolver->resolve($url);

            if ($resolved === null) {
                $warnings[] = "Broken internal link removed: {$url}";
                $html = $this->unwrapLink($html, $url);
            }
        }

        return $html;
    }

    /**
     * Remove duplicate internal links (same URL appearing multiple times).
     * Keeps the first occurrence, unwraps subsequent ones.
     *
     * @param  array<int, string>  $warnings
     */
    private function removeDuplicateLinks(string $html, string $appUrl, array &$warnings): string
    {
        $scanResult = $this->linkScanner->scanContent($html, $appUrl);
        /** @var array<string, int> $seenUrls */
        $seenUrls = [];

        foreach ($scanResult->internalLinks as $link) {
            $url = $link['url'];
            if (!isset($seenUrls[$url])) {
                $seenUrls[$url] = 1;
            } else {
                $seenUrls[$url]++;
            }
        }

        foreach ($seenUrls as $url => $count) {
            if ($count <= 1) {
                continue;
            }

            $duplicateCount = $count - 1;
            $warnings[] = "Duplicate internal link removed ({$duplicateCount}x): {$url}";

            for ($i = 0; $i < $duplicateCount; $i++) {
                $html = $this->unwrapLastOccurrence($html, $url);
            }
        }

        return $html;
    }

    /**
     * Unwrap an <a> tag with specific href, keeping the inner text.
     */
    private function unwrapLink(string $html, string $href): string
    {
        $escapedHref = preg_quote($href, '/');
        $pattern = '/<a\s[^>]*href\s*=\s*["\']'.$escapedHref.'["\'][^>]*>(.*?)<\/a>/is';

        return (string) preg_replace($pattern, '$1', $html);
    }

    /**
     * Unwrap the last occurrence of an <a> tag with the given href, keeping inner text.
     */
    private function unwrapLastOccurrence(string $html, string $href): string
    {
        $escapedHref = preg_quote($href, '/');
        $pattern = '/<a\s[^>]*href\s*=\s*["\']'.$escapedHref.'["\'][^>]*>(.*?)<\/a>/is';

        if (preg_match_all($pattern, $html, $matches, PREG_OFFSET_CAPTURE) && count($matches[0]) > 1) {
            /** @var array{string, int} $lastMatch */
            $lastMatch = $matches[0][count($matches[0]) - 1];
            /** @var array{string, int} $lastInner */
            $lastInner = $matches[1][count($matches[1]) - 1];

            $html = substr_replace($html, $lastInner[0], $lastMatch[1], strlen($lastMatch[0]));
        }

        return $html;
    }
}
