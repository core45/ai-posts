<?php

declare(strict_types=1);

namespace Core45\AiPosts\Jobs\BlogTopics;

use Astrotomic\Translatable\Contracts\Translatable;
use Core45\AiCore\Contracts\GeneratesSlugs;
use Core45\AiCore\Enums\AiSurface;
use Core45\AiCore\Exceptions\AiRateLimitedException;
use Core45\AiCore\Exceptions\AiUnavailableException;
use Core45\AiCore\ModelRegistry;
use Core45\AiCore\Services\AiClientFactory;
use Core45\AiCore\Services\RemoteAiClient;
use Core45\AiPosts\Enums\BlogProductSeriesStatus;
use Core45\AiPosts\Enums\BlogTopicCandidateStatus;
use Core45\AiPosts\Enums\BlogTopicLlmStep;
use Core45\AiPosts\Jobs\BlogTopics\Concerns\NotifiesUser;
use Core45\AiPosts\Models\BlogProductSeries;
use Core45\AiPosts\Models\BlogTopicCandidate;
use Core45\AiPosts\Services\BlogTopics\LlmAuditService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PlanProductSeriesJob implements ShouldQueue
{
    use NotifiesUser;
    use Queueable;

    /**
     * The phasea-owned prompt this job runs (v2.6 design §2.5). The instruction
     * text lives in phasea's `BlogTopicsProductSeriesPlanPrompt`; this host
     * names it and sends structured parameters only.
     */
    private const PRODUCT_SERIES_PLAN_TEMPLATE = 'blog_topics.product_series_plan';

    public int $tries = 10;

    public int $maxExceptions = 3;

    /** @var array<int, int> */
    public array $backoff = [10, 30, 60];

    public int $timeout = 300;

    public readonly string $operationId;

    public function __construct(
        public BlogProductSeries $series,
        public ?int $userId = null,
    ) {
        $this->onQueue('ai-bulk');
        $this->operationId = (string) Str::uuid();
    }

    /**
     * The Phase-8 SEAM-08 contract gap (bespoke free-text system/user prompt
     * with `$response->usage`-based cost accounting) is resolved via
     * `AiClientFactory::for(AiSurface::BlogTopics)` -> RemoteAiClient -> phasea.
     * `operationId` is minted once per dispatch and forwarded into the routing
     * call, so a retry replays the same id and phasea's metering does not
     * re-bill.
     *
     * v2.6 design §2.5: the call runs `AiClient::runPrompt()` against a
     * phasea-OWNED template. No instruction text leaves this host.
     */
    public function handle(LlmAuditService $auditService): void
    {
        Log::info('PlanProductSeriesJob: Starting', [
            'series_id' => $this->series->id,
            'operation_id' => $this->operationId,
        ]);

        $this->series->update(['status' => BlogProductSeriesStatus::Planning]);

        try {
            // Fetch target data. This STAYS here — it queries host product,
            // category and tag models — but the items now ride as structured
            // parameters instead of being rendered into an instruction string.
            $targetContext = $this->buildTargetContext();

            // v2.6: the host cannot know which model ran — phasea routes the task and
            // AiTextResult carries only text + token counts. Record the truthful
            // provider ('phasea') and an explicit unknown rather than a config guess.
            // FOLLOW-UP: have phasea return provider/model on AiTextResult so these
            // audit rows become accurate instead of merely honest.
            $provider = 'phasea';
            $model = 'unknown';

            $parameters = [
                'total_posts' => $this->series->total_posts,
                'target_type' => $targetContext['type'],
                'target_items' => $targetContext['items'],
            ];

            $startTime = microtime(true);

            // Phase-8 SEAM-08 gap resolved: route the completion through the
            // per-surface factory (local = identical Prism path; remote = phasea).
            $client = app(AiClientFactory::class)->for(AiSurface::BlogTopics);
            $result = $client->runPrompt(
                self::PRODUCT_SERIES_PLAN_TEMPLATE,
                $parameters,
                $this->operationId,
                // Transport-only, and NOT optional: series planning has always
                // run on the 300s assistant budget. Dropping it here would
                // silently move this job onto the 30s `AI_SERVICE_TIMEOUT`
                // default.
                [
                    'timeout' => config('ai.assistant.timeout', 300),
                ],
            );

            $durationMs = (int) ((microtime(true) - $startTime) * 1000);

            $auditService->log(
                step: BlogTopicLlmStep::SeriesPlanning,
                provider: $provider,
                model: $model,
                // D-48-6: the prompt lives in phasea now, so there is none to
                // record here. The template key plus the parameters IS the
                // reproducible input — and recording the whole structure keeps
                // `prompt_hash` discriminating between runs, which the bare
                // template key would collapse to a single digest per surface.
                prompt: json_encode([
                    'template' => self::PRODUCT_SERIES_PLAN_TEMPLATE,
                    'parameters' => $parameters,
                ], JSON_UNESCAPED_UNICODE) ?: self::PRODUCT_SERIES_PLAN_TEMPLATE,
                response: $result->text,
                candidateId: null,
                batchId: null,
                tokensIn: $result->promptTokens,
                tokensOut: $result->completionTokens,
                durationMs: $durationMs,
            );

            // Parse topics from response
            $topics = $this->parseTopicsResponse($result->text);

            $filteredTopics = $topics;

            // Create candidates
            foreach ($filteredTopics as $index => $topic) {
                BlogTopicCandidate::create([
                    'series_id' => $this->series->id,
                    'title' => $topic['title'] ?? 'Untitled Topic',
                    'slug' => app(GeneratesSlugs::class)->make($topic['title'] ?? 'untitled-topic'),
                    'intent' => $topic['intent'] ?? '',
                    'rationale' => $topic['rationale'] ?? '',
                    'outline_json' => $topic['outline'] ?? [],
                    'rank' => $index + 1,
                    'status' => BlogTopicCandidateStatus::Proposed,
                ]);
            }

            // Store topics and update status
            $this->series->update([
                'status' => BlogProductSeriesStatus::Planned,
                'topics_json' => $filteredTopics,
            ]);

            Log::info('PlanProductSeriesJob: Completed', [
                'series_id' => $this->series->id,
                'topics_planned' => count($filteredTopics),
                'duplicates_skipped' => count($topics) - count($filteredTopics),
            ]);

            $this->notifyUser(
                __('Series topics planned'),
                __(':count topics planned for series ":name"', [
                    'count' => count($filteredTopics),
                    'name' => $this->series->name,
                ]),
                'success',
            );
        } catch (AiRateLimitedException $e) {
            $retryAfter = $e->retryAfter > 0 ? $e->retryAfter : 60;
            Log::warning('PlanProductSeriesJob: Rate limited', [
                'series_id' => $this->series->id,
                'retry_after_seconds' => $retryAfter,
            ]);
            $this->release($retryAfter);
        } catch (AiUnavailableException $e) {
            Log::warning('PlanProductSeriesJob: Provider overloaded', [
                'series_id' => $this->series->id,
            ]);
            $this->release(60);
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('PlanProductSeriesJob: Failed', [
            'series_id' => $this->series->id,
            'error' => $exception->getMessage(),
        ]);

        $this->notifyUser(
            __('Series planning failed'),
            __('Failed to plan topics for series ":name": :error', [
                'name' => $this->series->name,
                'error' => $exception->getMessage(),
            ]),
            'danger',
        );
    }

    /**
     * Gather the host-side content the series is built around.
     *
     * The ITEM SHAPE VARIES BY TARGET TYPE and that is deliberate: a product
     * contributes `{name, summary, description}`, a category or tag only
     * `{name}`. phasea's template passes items through untouched rather than
     * re-deriving a fixed key set, so padding the shorter shapes here would
     * change what the model reads.
     *
     * @return array{type: string, items: list<array<string, string>>}
     */
    private function buildTargetContext(): array
    {
        $locale = app()->getLocale();
        $context = ['type' => $this->series->target_type, 'items' => []];

        switch ($this->series->target_type) {
            case 'product':
                $productClass = app(ModelRegistry::class)->getModelClassOrFail('product');
                $products = $productClass::whereIn('id', $this->series->target_ids)->get();
                foreach ($products as $product) {
                    $t = $product instanceof Translatable ? $product->translate($locale) : null;
                    $context['items'][] = [
                        'name' => $t?->getAttribute('name') ?? '',
                        'summary' => $t?->getAttribute('summary') ?? '',
                        'description' => $t?->getAttribute('description') ?? '',
                    ];
                }
                break;
            case 'category':
                // Registry key is `category`, not `product_category`.
                $categoryClass = app(ModelRegistry::class)->getModelClassOrFail('category');
                $categories = $categoryClass::whereIn('id', $this->series->target_ids)->get();
                foreach ($categories as $cat) {
                    $t = $cat instanceof Translatable ? $cat->translate($locale) : null;
                    $context['items'][] = ['name' => $t?->getAttribute('name') ?? ''];
                }
                break;
            case 'tag':
                $tagClass = app(ModelRegistry::class)->getModelClassOrFail('tag');
                $tags = $tagClass::whereIn('id', $this->series->target_ids)->get();
                foreach ($tags as $tag) {
                    $t = $tag instanceof Translatable ? $tag->translate($locale) : null;
                    $context['items'][] = ['name' => $t?->getAttribute('name') ?? ''];
                }
                break;
        }

        return $context;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function parseTopicsResponse(string $responseText): array
    {
        $cleaned = preg_replace('/```(?:json)?\s*/', '', $responseText);
        $cleaned = trim((string) $cleaned);

        if (preg_match('/\[[\s\S]*\]/', $cleaned, $matches)) {
            $decoded = json_decode($matches[0], true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        Log::warning('PlanProductSeriesJob: Failed to parse topics response');

        return [];
    }
}
