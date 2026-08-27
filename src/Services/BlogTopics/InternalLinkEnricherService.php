<?php

declare(strict_types=1);

namespace Core45\AiPosts\Services\BlogTopics;

use Core45\AiCore\ModelRegistry;
use Core45\AiCore\Traits\LogsAIOperations;
use Core45\AiPosts\Contracts\Seo\ResolvesUrlToModel;
use Core45\AiPosts\Contracts\Seo\ScansInternalLinks;
use Illuminate\Support\Facades\Log;

class InternalLinkEnricherService
{
    use LogsAIOperations;

    private const MAX_AUTO_INJECTED_LINKS = 3;

    public function __construct(
        private readonly ScansInternalLinks $linkScanner,
        private readonly ResolvesUrlToModel $urlModelResolver,
    ) {}

    /**
     * Validate internal links in HTML content. Removes broken internal links.
     * Resolves placeholder fragment-only links (href="#") to real URLs.
     *
     * @return array{html: string, warnings: array<int, string>}
     */
    public function validateLinks(string $html, string $locale): array
    {
        $this->logDebug('validateLinks - Started', [
            'locale' => $locale,
            'html_length' => mb_strlen($html),
        ]);

        $warnings = [];

        // Resolve placeholder fragment-only links (href="#", href="#section", etc.)
        // to real product/post/category URLs by matching anchor text
        $html = $this->resolveFragmentLinks($html, $locale, $warnings);

        $appUrl = (string) config('app.url');
        $scanResult = $this->linkScanner->scanContent($html, $appUrl);

        foreach ($scanResult->internalLinks as $link) {
            $href = $link['url'];
            $resolved = $this->urlModelResolver->resolve($href);

            if ($resolved === null) {
                $warnings[] = "Broken internal link removed: {$href}";
                $html = $this->unwrapLink($html, $href);
            }
        }

        $this->logDebug('validateLinks - Completed', [
            'locale' => $locale,
            'internal_links_found' => $scanResult->internalLinkCount,
            'warnings_count' => count($warnings),
            'warnings' => $warnings,
        ]);

        return ['html' => $html, 'warnings' => $warnings];
    }

    /**
     * Resolve <a href="#..."> links to real internal URLs by matching anchor text
     * against product names, post titles, and category names.
     *
     * If a match is found, the href is replaced with the real URL.
     * If no match is found, the <a> wrapper is removed (text preserved).
     *
     * @param  array<int, string>  $warnings
     */
    private function resolveFragmentLinks(string $html, string $locale, array &$warnings): string
    {
        return (string) preg_replace_callback(
            '/<a\s[^>]*href\s*=\s*["\']#[^"\']*["\'][^>]*>(.*?)<\/a>/is',
            function (array $matches) use ($locale, &$warnings): string {
                $innerHtml = $matches[1];
                $anchorText = trim(strip_tags($innerHtml));

                if ($anchorText === '') {
                    $warnings[] = 'Empty placeholder link removed';

                    return $innerHtml;
                }

                $this->logDebug('resolveFragmentLinks - Searching URL for anchor text', [
                    'anchor_text' => $anchorText,
                    'locale' => $locale,
                ]);

                $url = $this->findUrlForAnchorText($anchorText, $locale);

                if ($url !== null) {
                    Log::info('InternalLinkEnricherService: Resolved placeholder link', [
                        'anchor_text' => $anchorText,
                        'url' => $url,
                    ]);
                    $this->logDebug('resolveFragmentLinks - Resolved placeholder', [
                        'anchor_text' => $anchorText,
                        'resolved_url' => $url,
                    ]);
                    $warnings[] = "Placeholder link resolved: \"{$anchorText}\" → {$url}";

                    return '<a href="'.htmlspecialchars($url, ENT_QUOTES, 'UTF-8').'">'.$innerHtml.'</a>';
                }

                $this->logDebug('resolveFragmentLinks - No match found, removing placeholder', [
                    'anchor_text' => $anchorText,
                ]);
                $warnings[] = "Placeholder link removed (no match): \"{$anchorText}\"";

                return $innerHtml;
            },
            $html,
        );
    }

    /**
     * Search for a product, post, or category whose name/title matches the anchor text.
     * Tries exact match first, then partial (LIKE) match.
     */
    private function findUrlForAnchorText(string $anchorText, string $locale): ?string
    {
        // Escape LIKE wildcard characters in anchor text to prevent false matches
        $escapedAnchorText = str_replace(['%', '_'], ['\%', '\_'], $anchorText);

        $registry = app(ModelRegistry::class);
        $productClass = $registry->getModelClassOrFail('product');
        $postClass = $registry->getModelClassOrFail('post');
        $categoryClass = $registry->getModelClassOrFail('category');

        // 1. Products — match by translated name
        $product = $productClass::query()
            ->where('active', true)
            ->whereTranslation('name', $anchorText, $locale)
            ->first();

        if ($product === null) {
            $product = $productClass::query()
                ->where('active', true)
                ->whereTranslationLike('name', '%'.$escapedAnchorText.'%', $locale)
                ->first();
        }

        if ($product !== null) {
            $url = $product->getUrl($locale); // @phpstan-ignore method.notFound
            if ($url !== null) {
                return $url;
            }
        }

        // 2. Posts — match by translated title
        $post = $postClass::query()
            ->where('active', true)
            ->whereTranslation('title', $anchorText, $locale)
            ->first();

        if ($post === null) {
            $post = $postClass::query()
                ->where('active', true)
                ->whereTranslationLike('title', '%'.$escapedAnchorText.'%', $locale)
                ->first();
        }

        if ($post !== null) {
            $url = $post->getUrl($locale); // @phpstan-ignore method.notFound
            if ($url !== null) {
                return $url;
            }
        }

        // 3. Product categories — match by translated name
        $category = $categoryClass::query()
            ->where('active', true)
            ->whereTranslation('name', $anchorText, $locale)
            ->first();

        if ($category === null) {
            $category = $categoryClass::query()
                ->where('active', true)
                ->whereTranslationLike('name', '%'.$escapedAnchorText.'%', $locale)
                ->first();
        }

        if ($category !== null) {
            $url = $category->getUrl($locale); // @phpstan-ignore method.notFound
            if ($url !== null) {
                return $url;
            }
        }

        return null;
    }

    /**
     * Inject internal links into HTML where keyphrase matches are found in text nodes.
     *
     * Uses DOM parsing to only match within text content, avoiding replacements
     * inside HTML tags, attributes, or existing anchor elements.
     *
     * @param  array<int, array{url: string, anchorText: string, relevanceScore: float}>  $suggestions
     * @return array{html: string, injectedCount: int}
     */
    public function injectLinks(string $html, array $suggestions): array
    {
        $this->logDebug('injectLinks - Started', [
            'html_length' => mb_strlen($html),
            'suggestions_count' => count($suggestions),
            'suggestions' => array_map(fn (array $s): array => [
                'url' => $s['url'],
                'anchorText' => $s['anchorText'],
                'relevanceScore' => $s['relevanceScore'],
            ], $suggestions),
        ]);

        if (trim($html) === '') {
            return ['html' => $html, 'injectedCount' => 0];
        }

        usort($suggestions, fn ($a, $b) => $b['relevanceScore'] <=> $a['relevanceScore']);

        $doc = new \DOMDocument;
        libxml_use_internal_errors(true);
        $doc->loadHTML(
            '<?xml encoding="UTF-8"><div id="__wrapper">'.$html.'</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD,
        );
        libxml_clear_errors();

        $xpath = new \DOMXPath($doc);
        $injected = 0;
        /** @var array<string, true> $injectedUrls */
        $injectedUrls = [];

        foreach ($suggestions as $suggestion) {
            if ($injected >= self::MAX_AUTO_INJECTED_LINKS) {
                break;
            }

            $anchorText = $suggestion['anchorText'];
            $url = $suggestion['url'];

            // Skip if this URL was already injected (avoids duplicate links from multiple anchor candidates)
            if (isset($injectedUrls[$url])) {
                continue;
            }

            // Find text nodes NOT inside <a> tags that contain the anchor text
            $anchorLower = mb_strtolower($anchorText);
            // Escape quotes for safe XPath interpolation using concat()
            if (str_contains($anchorLower, '"')) {
                $xpathAnchor = "concat('".str_replace("'", "',\"'\",'", $anchorLower)."')";
            } else {
                $xpathAnchor = '"'.$anchorLower.'"';
            }
            $textNodes = $xpath->query(
                '//div[@id="__wrapper"]//text()[not(ancestor::a) and contains(translate(., "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), '.$xpathAnchor.')]',
            );

            if ($textNodes === false || $textNodes->length === 0) {
                continue;
            }

            // Replace in the first matching text node only
            $textNode = $textNodes->item(0);
            if (!$textNode instanceof \DOMNode || $textNode->parentNode === null) {
                continue;
            }

            $nodeText = $textNode->nodeValue ?? '';
            $pos = mb_stripos($nodeText, $anchorText);
            if ($pos === false) {
                continue;
            }

            $original = mb_substr($nodeText, $pos, mb_strlen($anchorText));
            $before = mb_substr($nodeText, 0, $pos);
            $after = mb_substr($nodeText, $pos + mb_strlen($anchorText));

            $anchor = $doc->createElement('a', $original);
            $anchor->setAttribute('href', $url);
            $anchor->setAttribute('data-auto-linked', 'true');

            $parent = $textNode->parentNode;
            if ($before !== '') {
                $parent->insertBefore($doc->createTextNode($before), $textNode);
            }
            $parent->insertBefore($anchor, $textNode);
            if ($after !== '') {
                $parent->insertBefore($doc->createTextNode($after), $textNode);
            }
            $parent->removeChild($textNode);

            $injected++;
            $injectedUrls[$url] = true;

            Log::info('InternalLinkEnricherService: Auto-injected link', [
                'url' => $url,
                'anchor_text' => $anchorText,
            ]);
        }

        // Extract inner HTML of wrapper div
        $wrapper = $doc->getElementById('__wrapper');
        if ($wrapper === null) {
            return ['html' => $html, 'injectedCount' => $injected];
        }

        $result = '';
        foreach ($wrapper->childNodes as $child) {
            $result .= $doc->saveHTML($child);
        }

        $this->logDebug('injectLinks - Completed', [
            'injected_count' => $injected,
            'injected_urls' => array_keys($injectedUrls),
        ]);

        return ['html' => $result, 'injectedCount' => $injected];
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
}
