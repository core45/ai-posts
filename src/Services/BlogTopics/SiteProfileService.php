<?php

declare(strict_types=1);

namespace Core45\AiPosts\Services\BlogTopics;

use Astrotomic\Translatable\Contracts\Translatable;
use Core45\AiCore\Enums\AiSurface;
use Core45\AiCore\ModelRegistry;
use Core45\AiCore\Services\AiClientFactory;
use Core45\AiPosts\Enums\BlogTopicLlmStep;
use Core45\AiPosts\Models\BlogTopicProfile;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SiteProfileService
{
    /** phasea-owned prompt (v2.6 §2.5) — this host names it, never authors it. */
    private const SITE_PROFILE_TEMPLATE = 'blog_topics.site_profile';

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

    public function buildProfile(BlogTopicProfile $profile): void
    {
        $siteData = $this->collectSiteData();

        /** @var array<string>|null $keywords */
        $keywords = $profile->seed_keywords;

        $parameters = [
            'products' => $siteData['products'],
            'categories' => $siteData['categories'],
            'recent_posts' => $siteData['recent_posts'],
            'seed_keywords' => is_array($keywords) ? array_values($keywords) : [],
        ];

        $startTime = microtime(true);

        $client = app(AiClientFactory::class)->for(AiSurface::BlogTopics);
        // The timeout budget rides the transport-only `options` argument, not
        // the template parameters — dropping it here would silently move this
        // generation onto the 30s `AI_SERVICE_TIMEOUT` default.
        $result = $client->runPrompt(
            self::SITE_PROFILE_TEMPLATE,
            $parameters,
            (string) Str::uuid(),
            [
                'timeout' => config('ai.assistant.timeout', 300),
            ],
        );

        $durationMs = (int) ((microtime(true) - $startTime) * 1000);

        $responseText = $result->text;
        $profileData = $this->parseJsonResponse($responseText);

        $profile->update([
            'profile_json' => $profileData,
            'last_refreshed_at' => now(),
        ]);

        $this->auditService->log(
            step: BlogTopicLlmStep::Profile,
            provider: $this->provider,
            model: $this->model,
            // The prompt lives in phasea now, so there is none to record here.
            // The template key plus the parameters IS the reproducible input
            // (D-48-6).
            prompt: json_encode([
                'template' => self::SITE_PROFILE_TEMPLATE,
                'parameters' => $parameters,
            ], JSON_UNESCAPED_UNICODE) ?: self::SITE_PROFILE_TEMPLATE,
            response: $responseText,
            batchId: null,
            tokensIn: $result->promptTokens,
            tokensOut: $result->completionTokens,
            durationMs: $durationMs,
        );

        Log::info('Site profile built successfully', ['profile_id' => $profile->id]);
    }

    /**
     * The site catalog the profile is derived from. Returned as three discrete
     * lists so each one crosses the wire as its own validated parameter of the
     * phasea-owned template, rather than as one pre-rendered blob.
     *
     * @return array{products: list<array{name: string}>, categories: list<array{name: string}>, recent_posts: list<array{title: string, excerpt: string}>}
     */
    private function collectSiteData(): array
    {
        $registry = app(ModelRegistry::class);
        $productClass = $registry->getModelClassOrFail('product');
        $categoryClass = $registry->getModelClassOrFail('category');
        $postClass = $registry->getModelClassOrFail('post');

        $products = [];
        foreach ($productClass::query()->where('active', true)->limit(50)->get() as $p) {
            /** @var Model|null $t */
            $t = ($p instanceof Translatable ? ($p->translate('en') ?? $p->translate('es')) : null);
            $products[] = ['name' => $t->name ?? 'Unknown'];
        }

        $categories = [];
        foreach ($categoryClass::query()->limit(30)->get() as $c) {
            /** @var Model|null $t */
            $t = ($c instanceof Translatable ? ($c->translate('en') ?? $c->translate('es')) : null);
            $categories[] = ['name' => $t->name ?? 'Unknown'];
        }

        $recentPosts = [];
        foreach ($postClass::query()->where('active', true)->orderByDesc('created_at')->limit(20)->get() as $p) {
            /** @var Model|null $t */
            $t = ($p instanceof Translatable ? ($p->translate('en') ?? $p->translate('es')) : null);
            $recentPosts[] = [
                'title' => $t->title ?? 'Untitled',
                'excerpt' => $t->excerpt ?? '',
            ];
        }

        return [
            'products' => $products,
            'categories' => $categories,
            'recent_posts' => $recentPosts,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function parseJsonResponse(string $responseText): array
    {
        $cleaned = preg_replace('/```(?:json)?\s*/', '', $responseText);
        $cleaned = trim((string) $cleaned);

        if (preg_match('/\{[\s\S]*\}/', $cleaned, $matches)) {
            $decoded = json_decode($matches[0], true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return ['raw_response' => $responseText];
    }
}
