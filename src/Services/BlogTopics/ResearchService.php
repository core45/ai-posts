<?php

declare(strict_types=1);

namespace Core45\AiPosts\Services\BlogTopics;

use Core45\AiPosts\Contracts\BlogTopics\SearchResearchProvider;
use Core45\AiPosts\Enums\BlogTopicLlmStep;
use Core45\AiPosts\Models\BlogTopicBatch;
use Core45\AiPosts\Models\BlogTopicSource;
use Core45\AiPosts\Services\BlogTopics\Concerns\GeneratesResearchText;
use Illuminate\Support\Facades\Log;

class ResearchService
{
    use GeneratesResearchText;

    /** phasea-owned prompt (v2.6 §2.5) — this host names it, never authors it. */
    private const RESEARCH_QUERIES_TEMPLATE = 'blog_topics.research_queries';

    public function __construct(
        private readonly SearchResearchProvider $searchProvider,
        private readonly LlmAuditService $auditService,
    ) {}

    /**
     * Generate search queries from profile + seed keywords.
     *
     * @return array<string>
     */
    public function generateQueries(BlogTopicBatch $batch): array
    {
        $profile = $batch->profile;

        /** @var array<string> $seedKeywords */
        $seedKeywords = $profile->seed_keywords ?? [];
        /** @var array<string> $excludedTopics */
        $excludedTopics = $profile->excluded_topics ?? [];

        /** @var array<string, mixed> $batchConfig */
        $batchConfig = $batch->getAttribute('config_json') ?? [];
        /** @var array<string> $expandedKeywords */
        $expandedKeywords = $batchConfig['expanded_keywords'] ?? [];

        // Expanded suggestions are just more seed keywords — merged here as DATA
        // rather than appended to an instruction string, which is the whole point
        // of the v2.6 split: this host sends parameters and holds no prompt.
        if ($expandedKeywords !== []) {
            $seedKeywords = array_values(array_unique([...$seedKeywords, ...$expandedKeywords]));
        }

        $maxQueries = (int) config('ai.assistant.blog_topics.research.max_queries_per_batch', 8);

        $parameters = [
            'site_profile' => $profile->profile_json ?? [],
            'seed_keywords' => $seedKeywords,
            'excluded_topics' => $excludedTopics,
            'constraints' => (string) $profile->constraints,
            'max_queries' => $maxQueries,
        ];

        $startTime = microtime(true);

        $generated = $this->runPromptTemplate(self::RESEARCH_QUERIES_TEMPLATE, $parameters);
        $response = $generated['response'];

        $durationMs = (int) ((microtime(true) - $startTime) * 1000);

        $this->auditService->log(
            step: BlogTopicLlmStep::Research,
            provider: $generated['provider'],
            model: $generated['model'],
            // The prompt lives in phasea now, so there is none to record here.
            // The template key plus the parameters IS the reproducible input.
            prompt: json_encode([
                'template' => self::RESEARCH_QUERIES_TEMPLATE,
                'parameters' => $parameters,
            ], JSON_UNESCAPED_UNICODE) ?: self::RESEARCH_QUERIES_TEMPLATE,
            response: $response->text,
            batchId: $batch->id,
            tokensIn: $response->promptTokens,
            tokensOut: $response->completionTokens,
            durationMs: $durationMs,
        );

        $queries = $this->parseJsonArrayResponse($response->text);

        return array_slice($queries, 0, $maxQueries);
    }

    /**
     * Execute a single search query and store sources.
     */
    public function executeSearch(BlogTopicBatch $batch, string $query): void
    {
        $searchResults = $this->searchProvider->search($query);

        foreach ($searchResults->results as $result) {
            $urlHash = hash('sha256', $result->url);

            // Skip duplicates within this batch (unique constraint on batch_id + url_hash)
            if (BlogTopicSource::where('batch_id', $batch->id)->where('url_hash', $urlHash)->exists()) {
                continue;
            }

            $metaJson = null;
            if ($result->ogImage !== null || $result->images !== []) {
                $metaJson = array_filter([
                    'og_image' => $result->ogImage,
                    'images' => $result->images !== [] ? $result->images : null,
                ]);
            }

            BlogTopicSource::create([
                'batch_id' => $batch->id,
                'url' => $result->url,
                'url_hash' => $urlHash,
                'domain' => $result->domain,
                'title' => $result->title,
                'snippet' => $result->snippet,
                'language' => $result->language,
                'published_at' => $result->publishedAt,
                'reliability_score' => $result->reliabilityScore,
                'meta_json' => $metaJson,
            ]);
        }

        $resultsCount = count($searchResults->results);

        // A zero-source search is not routine: the batch silently degrades into
        // "0 topics ready for review" with nothing in the log explaining why.
        // The usual cause is a query the upstream search declined to run (see
        // the query-generation prompt above), so record the query at WARNING.
        if ($resultsCount === 0) {
            Log::warning('Research search returned no sources', [
                'batch_id' => $batch->id,
                'query' => $query,
                'provider' => $searchResults->provider,
            ]);

            return;
        }

        Log::info('Research search executed', [
            'batch_id' => $batch->id,
            'query' => $query,
            'results_count' => $resultsCount,
        ]);
    }

    /**
     * @return array<string>
     */
    private function parseJsonArrayResponse(string $responseText): array
    {
        $cleaned = preg_replace('/```(?:json)?\s*/', '', $responseText);
        $cleaned = trim((string) $cleaned);

        if (preg_match('/\[[\s\S]*\]/', $cleaned, $matches)) {
            $decoded = json_decode($matches[0], true);
            if (is_array($decoded)) {
                return array_filter($decoded, 'is_string');
            }
        }

        return [];
    }
}
