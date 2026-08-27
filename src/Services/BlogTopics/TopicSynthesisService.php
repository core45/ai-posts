<?php

declare(strict_types=1);

namespace Core45\AiPosts\Services\BlogTopics;

use Core45\AiCore\Contracts\GeneratesSlugs;
use Core45\AiPosts\Enums\BlogTopicCandidateStatus;
use Core45\AiPosts\Enums\BlogTopicLlmStep;
use Core45\AiPosts\Models\BlogTopicBatch;
use Core45\AiPosts\Models\BlogTopicCandidate;
use Core45\AiPosts\Services\BlogTopics\Concerns\GeneratesResearchText;
use Illuminate\Support\Facades\Log;

class TopicSynthesisService
{
    use GeneratesResearchText;

    /** phasea-owned prompt (v2.6 §2.5) — this host names it, never authors it. */
    private const TOPIC_SYNTHESIS_TEMPLATE = 'blog_topics.topic_synthesis';

    public function __construct(
        private readonly LlmAuditService $auditService,
    ) {}

    public function synthesize(BlogTopicBatch $batch): void
    {
        $sources = $batch->sources()->get();
        $profile = $batch->profile;
        $maxTopics = config('ai.assistant.blog_topics.research.max_topics_per_batch', 10);

        $sourcesData = $sources->map(fn ($s) => [
            'id' => $s->id,
            'url' => $s->url,
            'title' => $s->title,
            'snippet' => $s->snippet,
            'domain' => $s->domain,
        ])->toArray();

        /** @var array<string, mixed> $batchConfig */
        $batchConfig = $batch->getAttribute('config_json') ?? [];

        /** @var array<array{query: string, volume: int}> $trendingTopics */
        $trendingTopics = $batchConfig['trending_topics'] ?? [];

        /** @var array<string> $relatedSearches */
        $relatedSearches = $batchConfig['related_searches'] ?? [];

        // Everything the instruction used to interpolate now travels as data:
        // the encoding of these lists happens inside the phasea template,
        // because encoding is presentation and presentation belongs with the
        // prompt.
        $parameters = [
            'max_topics' => (int) $maxTopics,
            'site_profile' => $profile->profile_json ?? [],
            'seed_keywords' => $profile->seed_keywords ?? [],
            'sources' => $sourcesData,
            'trending_topics' => $trendingTopics,
            'related_searches' => $relatedSearches,
        ];

        $startTime = microtime(true);

        // The transport-only `options` argument carries the 300s budget this
        // surface ran on before migration; without it the call would inherit
        // the 30s `AI_SERVICE_TIMEOUT` default.
        $generated = $this->runPromptTemplate(
            self::TOPIC_SYNTHESIS_TEMPLATE,
            $parameters,
            ['timeout' => config('ai.assistant.timeout', 300)],
        );
        $response = $generated['response'];

        $durationMs = (int) ((microtime(true) - $startTime) * 1000);

        $this->auditService->log(
            step: BlogTopicLlmStep::Synthesis,
            provider: $generated['provider'],
            model: $generated['model'],
            // The prompt lives in phasea now, so there is none to record here.
            // The template key plus the parameters IS the reproducible input
            // (D-48-6).
            prompt: json_encode([
                'template' => self::TOPIC_SYNTHESIS_TEMPLATE,
                'parameters' => $parameters,
            ], JSON_UNESCAPED_UNICODE) ?: self::TOPIC_SYNTHESIS_TEMPLATE,
            response: $response->text,
            batchId: $batch->id,
            tokensIn: $response->promptTokens,
            tokensOut: $response->completionTokens,
            durationMs: $durationMs,
        );

        $topics = $this->parseJsonArrayResponse($response->text);

        foreach ($topics as $topic) {
            if (!is_array($topic) || empty($topic['title'])) {
                continue;
            }

            $evidence = $topic['evidence'] ?? [];
            if (empty($evidence)) {
                Log::warning('Topic candidate has no evidence, skipping', ['title' => $topic['title']]);

                continue;
            }

            BlogTopicCandidate::create([
                'batch_id' => $batch->id,
                'title' => $topic['title'],
                'slug' => app(GeneratesSlugs::class)->make($topic['title']),
                'intent' => $topic['intent'] ?? null,
                'rationale' => $topic['rationale'] ?? null,
                'outline_json' => $topic['outline'] ?? null,
                'evidence_json' => $evidence,
                'rank' => $topic['rank'] ?? 0,
                'risk_flags_json' => $topic['risk_flags'] ?? null,
                'status' => BlogTopicCandidateStatus::Proposed,
            ]);
        }

        Log::info('Topic synthesis completed', [
            'batch_id' => $batch->id,
            'candidates_created' => $batch->candidates()->count(),
        ]);
    }

    /**
     * @return array<mixed>
     */
    private function parseJsonArrayResponse(string $responseText): array
    {
        $cleaned = preg_replace('/```(?:json)?\s*/', '', $responseText);
        $cleaned = trim((string) $cleaned);

        if (preg_match('/\[[\s\S]*\]/', $cleaned, $matches)) {
            $decoded = json_decode($matches[0], true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }
}
