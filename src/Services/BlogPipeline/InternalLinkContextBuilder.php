<?php

declare(strict_types=1);

namespace Core45\AiPosts\Services\BlogPipeline;

use Astrotomic\Translatable\Contracts\Translatable;
use Core45\AiCore\ModelRegistry;
use Core45\AiPosts\Models\BlogPostPipelineRun;
use Illuminate\Support\Facades\Log;

class InternalLinkContextBuilder
{
    private const MAX_TARGETS = 20;

    /**
     * Build internal link targets for injection into a module prompt.
     *
     * @return array<int, array{type: string, title: string, url: string, description: string}>
     */
    public function buildTargets(BlogPostPipelineRun $run, string $moduleSlug): array
    {
        $locale = $run->primary_locale;

        // Semantic post/product targets retired with the local vector store —
        // category targets are the remaining (non-semantic) source.
        $targets = $this->buildCategoryTargets($locale);

        $targets = $this->prioritizeTargets($targets);

        Log::debug('InternalLinkContextBuilder: Built targets', [
            'pipeline_run_id' => $run->id,
            'module_slug' => $moduleSlug,
            'target_count' => count($targets),
        ]);

        return array_slice($targets, 0, self::MAX_TARGETS);
    }

    /**
     * Find active product categories for the given locale.
     *
     * @return array<int, array{type: string, title: string, url: string, description: string, relevance_score: float, link_count: int}>
     */
    private function buildCategoryTargets(string $locale): array
    {
        $targets = [];

        $categoryClass = app(ModelRegistry::class)->getModelClassOrFail('category');

        $categories = $categoryClass::query()
            ->where('active', true)
            ->whereHas('translations', fn ($q) => $q->where('locale', $locale)->whereNotNull('name'))
            ->limit(4)
            ->get();

        foreach ($categories as $category) {
            // The package never imports the host's ProductCategory class (§3
            // seam) — guard the Astrotomic-dependent and category-only calls
            // below at this boundary, following the precedent at
            // DetectionPollService.php:260.
            if (!method_exists($category, 'getUrl')) {
                continue;
            }

            $url = $category->getUrl($locale);
            if ($url === null) {
                continue;
            }

            $translation = $category instanceof Translatable ? $category->translate($locale) : null;
            $name = $translation->name ?? 'Untitled';
            $description = '';
            if ($translation !== null) {
                $desc = $translation->description ?? '';
                $description = is_string($desc) ? mb_substr(strip_tags($desc), 0, 120) : '';
            }

            $targets[] = [
                'type' => 'category',
                'title' => $name,
                'url' => $url,
                'description' => $description,
                'relevance_score' => 0.5,
                'link_count' => $this->getIncomingLinkCount($url),
            ];
        }

        return $targets;
    }

    /**
     * Prioritize targets by relevance score, then by fewer incoming links (underlinked pages first).
     *
     * @param  array<int, array{type: string, title: string, url: string, description: string, relevance_score: float, link_count: int}>  $targets
     * @return array<int, array{type: string, title: string, url: string, description: string}>
     */
    private function prioritizeTargets(array $targets): array
    {
        usort($targets, function (array $a, array $b): int {
            $scoreComparison = $b['relevance_score'] <=> $a['relevance_score'];
            if ($scoreComparison !== 0) {
                return $scoreComparison;
            }

            return $a['link_count'] <=> $b['link_count'];
        });

        return array_map(fn (array $target): array => [
            'type' => $target['type'],
            'title' => $target['title'],
            'url' => $target['url'],
            'description' => $target['description'],
        ], $targets);
    }

    /**
     * Count how many times a URL has been linked to across all posts.
     */
    private function getIncomingLinkCount(string $url): int
    {
        $internalLinkClickClass = app(ModelRegistry::class)->getModelClassOrFail('internal_link_click');

        return (int) $internalLinkClickClass::query()
            ->where('target_url', $url)
            ->sum('click_count');
    }
}
