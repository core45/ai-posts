<?php

declare(strict_types=1);

namespace Core45\AiPosts\Services\BlogTopics;

use Core45\AiCore\Enums\AiSurface;
use Core45\AiCore\ModelRegistry;
use Core45\AiCore\Services\AiClientFactory;
use Core45\AiCore\Traits\LogsAIOperations;
use Core45\AiPosts\Enums\BlogTopicLlmStep;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AiLinkSuggestionService
{
    use LogsAIOperations;

    /**
     * The phasea-owned prompt this surface runs (v2.6 design §2.5). The
     * instruction text lives in phasea's `BlogTopicsInternalLinksPrompt`; this
     * host names it and sends structured parameters only.
     */
    private const INTERNAL_LINKS_TEMPLATE = 'blog_topics.internal_links';

    private string $provider;

    private string $model;

    public function __construct(
        private readonly LlmAuditService $auditService,
    ) {
        // v2.6: phasea routes this task; the host cannot know the model.
        // AiTextResult carries only text + tokens. Record the truthful provider
        // and an explicit unknown rather than a config guess.
        $this->provider = 'phasea';
        $this->model = 'unknown';
    }

    /**
     * Use AI to find semantically relevant internal link targets for an article.
     *
     * Sends the article content + a compact list of all available pages
     * to an LLM, which returns placement suggestions with anchor text,
     * URL, and reasoning.
     *
     * @return array<int, array{url: string, anchorText: string, reason: string, relevanceScore: float}>
     */
    public function suggestLinks(Model $sourceIndexable, string $articleHtml, string $locale, int $limit = 5): array
    {
        $contentText = strip_tags($articleHtml);

        if (mb_strlen($contentText) < 100) {
            $this->logDebug('suggestLinks - Article too short for AI matching', [
                'content_length' => mb_strlen($contentText),
            ]);

            return [];
        }

        $targets = $this->buildTargetList($sourceIndexable, $locale);

        if ($targets === []) {
            $this->logDebug('suggestLinks - No available targets');

            return [];
        }

        $this->logDebug('suggestLinks - Sending to AI', [
            'source_id' => $sourceIndexable->id, // @phpstan-ignore property.notFound
            'content_length' => mb_strlen($contentText),
            'target_count' => count($targets),
            'provider' => $this->provider,
            'model' => $this->model,
        ]);

        $parameters = [
            'content_text' => $this->truncateContent($contentText),
            'targets' => $targets,
            'limit' => $limit,
        ];

        $startTime = microtime(true);

        $client = app(AiClientFactory::class)->for(AiSurface::BlogTopics);
        $result = $client->runPrompt(
            self::INTERNAL_LINKS_TEMPLATE,
            $parameters,
            (string) Str::uuid(),
            // Transport-only, and NOT optional: link enrichment has always run
            // on its own 120s budget. Dropping it here would silently move the
            // surface onto the 30s `AI_SERVICE_TIMEOUT` default.
            [
                'timeout' => config('ai.assistant.timeouts.linking', 120),
            ],
        );

        $durationMs = (int) ((microtime(true) - $startTime) * 1000);

        $this->auditService->log(
            step: BlogTopicLlmStep::LinkEnrichment,
            provider: $this->provider,
            model: $this->model,
            // D-48-6: the prompt lives in phasea now, so there is none to
            // record here. The template key plus the parameters IS the
            // reproducible input.
            prompt: json_encode([
                'template' => self::INTERNAL_LINKS_TEMPLATE,
                'parameters' => $parameters,
            ], JSON_UNESCAPED_UNICODE) ?: self::INTERNAL_LINKS_TEMPLATE,
            response: $result->text,
            tokensIn: $result->promptTokens,
            tokensOut: $result->completionTokens,
            durationMs: $durationMs,
        );

        $this->logDebug('suggestLinks - AI response received', [
            'response_length' => mb_strlen($result->text),
            'duration_ms' => $durationMs,
            'tokens_in' => $result->promptTokens,
            'tokens_out' => $result->completionTokens,
        ]);

        $suggestions = $this->parseResponse($result->text, $targets);

        $this->logDebug('suggestLinks - Parsed suggestions', [
            'suggestion_count' => count($suggestions),
            'suggestions' => $suggestions,
        ]);

        return array_slice($suggestions, 0, $limit);
    }

    /**
     * Cap the article body before it leaves this host.
     *
     * A COST control, not the untrusted-input guard: phasea's template declares
     * its own `max:` on `content_text` (D-48-2). The two are complementary —
     * this one keeps a very long article from being paid for, that one refuses
     * anything oversized regardless of which host sent it.
     */
    private function truncateContent(string $contentText): string
    {
        return mb_strlen($contentText) > 4000
            ? mb_substr($contentText, 0, 4000).'... [truncated]'
            : $contentText;
    }

    /**
     * Build a compact list of available link targets for the LLM.
     *
     * @return array<int, array{id: int, type: string, title: string, keyphrase: string|null, url: string}>
     */
    private function buildTargetList(Model $sourceIndexable, string $locale): array
    {
        $seoIndexableClass = app(ModelRegistry::class)->getModelClassOrFail('seo_indexable');
        $candidates = $seoIndexableClass::query()
            ->forLocale($locale) // @phpstan-ignore method.notFound
            ->indexable()
            ->where('id', '!=', $sourceIndexable->id) // @phpstan-ignore property.notFound
            ->whereNotNull('canonical')
            ->where('canonical', '!=', '')
            ->select(['id', 'indexable_type', 'indexable_id', 'title', 'keyphrases', 'canonical'])
            ->with('indexable')
            ->get();

        $targets = [];

        foreach ($candidates as $candidate) {
            $title = $candidate->title ?? '';
            $keyphrase = $candidate->primary_keyphrase;

            // Skip entries without a meaningful title
            if ($title === '' && ($keyphrase === null || $keyphrase === '')) {
                continue;
            }

            // Resolve URL from the model (authoritative) with canonical as fallback
            $url = $candidate->canonical;
            /** @var Model|null $model */
            $model = $candidate->indexable;
            if ($model !== null && method_exists($model, 'getUrl')) {
                $url = $model->getUrl($locale) ?? $url;
            }

            if ($url === null || $url === '') {
                continue;
            }

            $type = match (true) {
                str_contains($candidate->indexable_type, 'Product') && !str_contains($candidate->indexable_type, 'Category') => 'product',
                str_contains($candidate->indexable_type, 'Post') => 'blog_post',
                str_contains($candidate->indexable_type, 'Category') => 'category',
                default => 'page',
            };

            $targets[] = [
                'id' => $candidate->id,
                'type' => $type,
                'title' => $title,
                'keyphrase' => $keyphrase,
                'url' => $url,
            ];
        }

        return $targets;
    }

    /**
     * Parse AI response into link suggestion format.
     *
     * @param  array<int, array{id: int, type: string, title: string, keyphrase: string|null, url: string}>  $targets
     * @return array<int, array{url: string, anchorText: string, reason: string, relevanceScore: float}>
     */
    private function parseResponse(string $responseText, array $targets): array
    {
        $cleaned = preg_replace('/```(?:json)?\s*/', '', $responseText);
        $cleaned = trim((string) $cleaned);

        if (preg_match('/\[[\s\S]*\]/', $cleaned, $matches)) {
            $decoded = json_decode($matches[0], true);
        }

        if (!isset($decoded) || !is_array($decoded)) {
            Log::warning('AiLinkSuggestionService: Failed to parse AI response', [
                'response_preview' => mb_substr($responseText, 0, 500),
            ]);

            return [];
        }

        // Index targets by id for quick lookup
        $targetIndex = [];
        foreach ($targets as $target) {
            $targetIndex[$target['id']] = $target;
        }

        $suggestions = [];

        foreach ($decoded as $item) {
            if (!is_array($item)) {
                continue;
            }

            $targetId = $item['target_id'] ?? null;
            $anchorText = isset($item['anchor_text']) && is_string($item['anchor_text'])
                ? trim($item['anchor_text'])
                : '';
            $reason = isset($item['reason']) && is_string($item['reason'])
                ? trim($item['reason'])
                : 'AI-suggested link';

            if ($targetId === null || $anchorText === '' || !isset($targetIndex[$targetId])) {
                continue;
            }

            $target = $targetIndex[$targetId];

            $suggestions[] = [
                'url' => $target['url'],
                'anchorText' => $anchorText,
                'reason' => $reason,
                'relevanceScore' => 0.9, // AI suggestions get high base score
            ];
        }

        return $suggestions;
    }
}
