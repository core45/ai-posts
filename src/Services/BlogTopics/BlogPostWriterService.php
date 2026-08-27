<?php

declare(strict_types=1);

namespace Core45\AiPosts\Services\BlogTopics;

use Astrotomic\Translatable\Contracts\Translatable;
use Core45\AiCore\Contracts\ProvidesMandatoryWords;
use Core45\AiCore\Enums\AiSurface;
use Core45\AiCore\ModelRegistry;
use Core45\AiCore\Services\AiClientFactory;
use Core45\AiCore\Traits\LogsAIOperations;
use Core45\AiPosts\Enums\BlogTopicLlmStep;
use Core45\AiPosts\Models\BlogProductSeries;
use Core45\AiPosts\Models\BlogTopicCandidate;
use Core45\AiPosts\Models\BlogTopicProfile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * @deprecated Use the new blog pipeline system (Core45\AiPosts\Services\BlogPipeline\*) instead.
 *             This service is retained for rollback safety. It will be removed
 *             once the new pipeline is fully validated in production.
 */
class BlogPostWriterService
{
    use LogsAIOperations;

    /** phasea-owned prompt (v2.6 §2.5) — this host names it, never authors it. */
    private const BLOG_POST_TEMPLATE = 'blog_topics.blog_post';

    private string $provider;

    private string $model;

    public function __construct(
        private readonly LlmAuditService $auditService,
        private readonly ExternalLinkManagerService $externalLinkManager,
    ) {
        // v2.6: the host cannot know which model ran — phasea routes the task and
        // AiTextResult carries only text + token counts. Record the truthful
        // provider ('phasea') and an explicit unknown rather than a config guess.
        // FOLLOW-UP: have phasea return provider/model on AiTextResult so these
        // audit rows become accurate instead of merely honest.
        $this->provider = 'phasea';
        $this->model = 'unknown';
    }

    /**
     * Write a blog post from an approved candidate.
     *
     * @return array{title: string, excerpt: string, main_text: string, seo_title: string, seo_description: string, seo_keywords: string, citations_json: array<mixed>}
     */
    public function write(BlogTopicCandidate $candidate, BlogTopicProfile $profile, ?BlogProductSeries $series = null): array
    {
        $sources = $candidate->batch?->sources()->get() ?? collect();
        /** @var array<array{source_id?: int, evidence_summary?: string}> $evidence */
        $evidence = $candidate->evidence_json ?? [];

        // Build source reference for the prompt
        $sourceReferences = [];
        foreach ($evidence as $entry) {
            $sourceId = $entry['source_id'] ?? null;
            if ($sourceId !== null) {
                $source = $sources->firstWhere('id', $sourceId);
                if ($source) {
                    $sourceReferences[] = [
                        'id' => $source->id,
                        'url' => $source->url,
                        'title' => $source->title,
                        'snippet' => $source->snippet,
                    ];
                }
            }
        }

        // Build enhanced context. Both helpers stay here — they read host
        // models — but they are consumed as DATA now: the series and the link
        // targets cross the wire as structured lists and the phasea template
        // does the formatting, because formatting is presentation.
        $productContext = $series !== null ? $this->buildProductContext($series) : [];
        $externalLinkTargets = $this->buildExternalLinkTargets($profile);

        // `profile` here is phasea's anti-slop precedence parameter, NOT
        // `site_profile` below — those are different things and conflating them is
        // the specific wrong turn phasea's own docblock flags. `site_profile` is
        // opaque context rendered as JSON; `profile.mandatory_words` is a bounded,
        // structured list phasea subtracts from its filler ban (phase 50, D-50-1).
        $parameters = [
            'brand_voice' => config('ai.assistant.content.brand_voice', 'professional'),
            'title' => $candidate->title,
            'intent' => $candidate->intent,
            'rationale' => $candidate->rationale,
            'outline' => $candidate->outline_json ?? [],
            'site_profile' => $profile->profile_json ?? [],
            'sources' => $sourceReferences,
            'product_context' => $productContext,
            'external_link_targets' => $externalLinkTargets,
            ...app(ProvidesMandatoryWords::class)->asProfileParameter(),
        ];

        $this->logDebug('write - Sending to LLM', [
            'candidate_id' => $candidate->id,
            'provider' => $this->provider,
            'model' => $this->model,
            'template' => self::BLOG_POST_TEMPLATE,
            'source_references_count' => count($sourceReferences),
            'has_product_context' => !empty($productContext),
            'external_link_targets_count' => count($externalLinkTargets),
        ]);

        $startTime = microtime(true);

        $client = app(AiClientFactory::class)->for(AiSurface::BlogTopics);
        // The timeout budget rides the transport-only `options` argument, not
        // the template parameters. This is the LONGEST generation in the
        // BlogTopics pipeline: dropping the budget here would silently move a
        // full article write onto the 30s `AI_SERVICE_TIMEOUT` default and turn
        // a working call into a paid timeout (T-48-07).
        $result = $client->runPrompt(
            self::BLOG_POST_TEMPLATE,
            $parameters,
            (string) Str::uuid(),
            [
                'timeout' => config('ai.assistant.timeout', 300),
            ],
        );

        $durationMs = (int) ((microtime(true) - $startTime) * 1000);

        $this->auditService->log(
            step: BlogTopicLlmStep::Writing,
            provider: $this->provider,
            model: $this->model,
            // The prompt lives in phasea now, so there is none to record here.
            // The template key plus the parameters IS the reproducible input
            // (D-48-6).
            prompt: json_encode([
                'template' => self::BLOG_POST_TEMPLATE,
                'parameters' => $parameters,
            ], JSON_UNESCAPED_UNICODE) ?: self::BLOG_POST_TEMPLATE,
            response: $result->text,
            candidateId: $candidate->id,
            batchId: $candidate->batch_id,
            tokensIn: $result->promptTokens,
            tokensOut: $result->completionTokens,
            durationMs: $durationMs,
        );

        $this->logDebug('write - LLM response received', [
            'candidate_id' => $candidate->id,
            'response_length' => mb_strlen($result->text),
            'duration_ms' => $durationMs,
            'tokens_in' => $result->promptTokens,
            'tokens_out' => $result->completionTokens,
        ]);

        $parsed = $this->parseWritingResponse($result->text, $sourceReferences);

        $this->logDebug('write - Parsed result', [
            'candidate_id' => $candidate->id,
            'title' => $parsed['title'],
            'seo_title_length' => mb_strlen($parsed['seo_title']),
            'seo_description_length' => mb_strlen($parsed['seo_description']),
            'main_text_length' => mb_strlen($parsed['main_text']),
            'citations_count' => count($parsed['citations_json']),
        ]);

        return $parsed;
    }

    /**
     * Build product context for series-linked posts.
     *
     * @return array<string, mixed>
     */
    public function buildProductContext(BlogProductSeries $series): array
    {
        $context = [
            'target_type' => $series->target_type,
            'products' => [],
        ];

        $locale = app()->getLocale();
        $registry = app(ModelRegistry::class);

        switch ($series->target_type) {
            case 'product':
                $productClass = $registry->getModelClassOrFail('product');
                $products = $productClass::whereIn('id', $series->target_ids)->get();
                foreach ($products as $product) {
                    $translation = $product instanceof Translatable ? $product->translate($locale) : null;
                    $name = $translation?->getAttribute('name') ?? '';
                    $summary = $translation?->getAttribute('summary') ?? '';
                    $context['products'][] = [
                        'name' => $name,
                        'url' => $product->getUrl($locale), // @phpstan-ignore method.notFound
                        'summary' => $summary,
                    ];
                }
                break;
            case 'category':
                $categoryClass = $registry->getModelClassOrFail('category');
                $categories = $categoryClass::whereIn('id', $series->target_ids)->get();
                foreach ($categories as $category) {
                    $translation = $category instanceof Translatable ? $category->translate($locale) : null;
                    $context['products'][] = [
                        'name' => $translation?->getAttribute('name') ?? '',
                        'type' => 'category',
                    ];
                }
                break;
            case 'tag':
                $tagClass = $registry->getModelClassOrFail('tag');
                $tags = $tagClass::whereIn('id', $series->target_ids)->get();
                foreach ($tags as $tag) {
                    $translation = $tag instanceof Translatable ? $tag->translate($locale) : null;
                    $context['products'][] = [
                        'name' => $translation?->getAttribute('name') ?? '',
                        'type' => 'tag',
                    ];
                }
                break;
        }

        return $context;
    }

    /**
     * Build external link targets from approved links.
     *
     * @return array<int, array{url: string, anchor_text: string, link_id: int}>
     */
    private function buildExternalLinkTargets(BlogTopicProfile $profile): array
    {
        $links = $this->externalLinkManager->getAvailableLinks($profile);
        $targets = [];

        foreach ($links->take(5) as $link) {
            $targets[] = [
                'url' => $link->url,
                'anchor_text' => $link->anchor_text_suggestion ?? $link->label,
                'link_id' => $link->id,
            ];
        }

        return $targets;
    }

    /**
     * @param  array<array{id: int, url: string, title: string|null, snippet: string}>  $sourceReferences
     * @return array{title: string, excerpt: string, main_text: string, seo_title: string, seo_description: string, seo_keywords: string, citations_json: array<mixed>}
     */
    private function parseWritingResponse(string $responseText, array $sourceReferences): array
    {
        $cleaned = preg_replace('/```(?:json)?\s*/', '', $responseText);
        $cleaned = trim((string) $cleaned);

        $data = [];
        if (preg_match('/\{[\s\S]*\}/', $cleaned, $matches)) {
            $decoded = json_decode($matches[0], true);
            if (is_array($decoded)) {
                $data = $decoded;
            }
        }

        if (empty($data)) {
            Log::warning('Failed to parse blog post writing response, using raw text');
            $data = [
                'title' => 'Generated Blog Post',
                'main_text' => $responseText,
            ];
        }

        return [
            'title' => $data['title'] ?? 'Untitled',
            'excerpt' => $data['excerpt'] ?? '',
            'main_text' => $data['main_text'] ?? '',
            'seo_title' => $this->truncateAtWordBoundary($data['seo_title'] ?? ($data['title'] ?? 'Untitled'), 70),
            'seo_description' => $this->truncateAtWordBoundary($data['seo_description'] ?? '', 156),
            'seo_keywords' => $data['seo_keywords'] ?? '',
            'citations_json' => $data['citations'] ?? [],
        ];
    }

    /**
     * Truncate text to a max length at a word boundary.
     */
    private function truncateAtWordBoundary(string $text, int $maxLength): string
    {
        $text = trim($text);

        if (mb_strlen($text) <= $maxLength) {
            return $text;
        }

        $truncated = mb_substr($text, 0, $maxLength - 1);

        // Try to break at the last space to avoid cutting mid-word
        $lastSpace = mb_strrpos($truncated, ' ');
        if ($lastSpace !== false && $lastSpace > $maxLength * 0.6) {
            $truncated = mb_substr($truncated, 0, $lastSpace);
        }

        return rtrim($truncated, ' .,;:').'…';
    }
}
