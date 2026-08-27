<?php

declare(strict_types=1);

namespace Core45\AiPosts\Jobs\BlogPipeline;

use Astrotomic\Translatable\Contracts\Translatable;
use Core45\AiCore\ModelRegistry;
use Core45\AiPosts\Contracts\Seo\ResolvesUrlToModel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Periodic job to verify that internal links in published blog posts still resolve.
 *
 * If postId is null, checks all active blog posts.
 * Logs warnings for any broken internal links found.
 */
class VerifyPostLinksJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(
        public readonly ?int $postId = null,
    ) {
        $this->onQueue('default');
    }

    public function handle(ResolvesUrlToModel $urlModelResolver): void
    {
        $posts = $this->getPostsToCheck();

        $totalBroken = 0;
        $totalChecked = 0;

        foreach ($posts as $post) {
            $result = $this->checkPostLinks($post, $urlModelResolver);
            $totalBroken += $result['broken_count'];
            $totalChecked += $result['checked_count'];
        }

        Log::info('VerifyPostLinksJob: Completed', [
            'post_id' => $this->postId,
            'posts_checked' => $posts->count(),
            'links_checked' => $totalChecked,
            'broken_links' => $totalBroken,
        ]);
    }

    /**
     * @return Collection<int, Model>
     */
    private function getPostsToCheck(): Collection
    {
        $postClass = app(ModelRegistry::class)->getModelClassOrFail('post');
        $query = $postClass::query()
            ->where('active', true)
            ->where('post_type_id', config('ai-posts.taxonomy.blog_post_type', 2));

        if ($this->postId !== null) {
            $query->where('id', $this->postId);
        }

        return $query->get();
    }

    /**
     * Check all internal links in a post's translated main_text.
     *
     * @return array{checked_count: int, broken_count: int, broken_urls: array<int, string>}
     */
    private function checkPostLinks(Model $post, ResolvesUrlToModel $urlModelResolver): array
    {
        $appUrl = (string) config('app.url');

        /** @var array<string, array<string, mixed>> $supportedLocales */
        $supportedLocales = config('laravellocalization.supportedLocales', []);
        $brokenUrls = [];
        $checkedCount = 0;

        foreach (array_keys($supportedLocales) as $locale) {
            $translation = $post instanceof Translatable ? $post->translate((string) $locale) : null;

            if ($translation === null || empty($translation->main_text)) {
                continue;
            }

            $links = $this->extractInternalLinks((string) $translation->main_text, $appUrl);

            foreach ($links as $url) {
                $checkedCount++;
                $resolved = $urlModelResolver->resolve($url);

                if ($resolved === null) {
                    $brokenUrls[] = $url;
                }
            }
        }

        if ($brokenUrls !== []) {
            Log::warning('VerifyPostLinksJob: Broken links found', [
                'post_id' => $post->getKey(),
                'broken_count' => count($brokenUrls),
                'broken_urls' => $brokenUrls,
            ]);
        }

        return [
            'checked_count' => $checkedCount,
            'broken_count' => count($brokenUrls),
            'broken_urls' => $brokenUrls,
        ];
    }

    /**
     * Extract internal link URLs from HTML content.
     *
     * @return array<int, string>
     */
    private function extractInternalLinks(string $html, string $appUrl): array
    {
        $parsedHost = parse_url($appUrl, PHP_URL_HOST);
        $baseHost = mb_strtolower(is_string($parsedHost) ? $parsedHost : '');
        $urls = [];

        $previousUseErrors = libxml_use_internal_errors(true);
        $dom = new \DOMDocument;
        $dom->loadHTML(
            '<?xml encoding="UTF-8">'.$html,
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD,
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previousUseErrors);

        $anchors = $dom->getElementsByTagName('a');

        /** @var \DOMElement $anchor */
        foreach ($anchors as $anchor) {
            $href = $anchor->getAttribute('href');

            if ($href === '' || str_starts_with($href, '#') || str_starts_with($href, 'mailto:') || str_starts_with($href, 'tel:')) {
                continue;
            }

            if (str_starts_with($href, '/')) {
                $urls[] = rtrim($appUrl, '/').$href;

                continue;
            }

            $parsedLinkHost = parse_url($href, PHP_URL_HOST);
            $linkHost = mb_strtolower(is_string($parsedLinkHost) ? $parsedLinkHost : '');
            if ($linkHost === $baseHost) {
                $urls[] = $href;
            }
        }

        return array_unique($urls);
    }
}
