<?php

declare(strict_types=1);

namespace Core45\AiPosts\Services\BlogPipeline;

use Core45\AiCore\Contracts\ChecksContentSimilarity;
use Core45\AiCore\Enums\AiSurface;
use Core45\AiCore\Services\AiClientFactory;
use Core45\AiCore\Traits\LogsAIOperations;
use Core45\AiPosts\DTOs\BlogPipeline\ModuleOutput;
use Core45\AiPosts\DTOs\BlogPipeline\QualityResult;
use Core45\AiPosts\Enums\BlogTopicLlmStep;
use Core45\AiPosts\Enums\QualityDecision;
use Core45\AiPosts\Models\BlogPostPipelineRun;
use Core45\AiPosts\Models\ContentModule;
use Core45\AiPosts\Services\BlogTopics\LlmAuditService;
use Illuminate\Support\Facades\Log;

/**
 * `runLlmJudge()` builds a system+user prompt and evaluates it through
 * `AiClientFactory::for(AiSurface::BlogPipeline)->completeText()` — the same
 * seam every other blog_pipeline call routes through (phasea
 * `POST api/v1/ai/text/complete` with `surface=blog_pipeline`; the local
 * Prism path was retired in CUT-10).
 */
class QualityGateService
{
    use LogsAIOperations;

    /**
     * v2.6: phasea routes every blog-pipeline call and selects its own model
     * — the host never chooses, so `provider`/`model` are honest audit
     * labels, not a resolved setting, and are never forwarded to
     * `completeText()`.
     */
    private const string PROVIDER_LABEL = 'phasea';

    private const string MODEL_LABEL = 'unknown';

    /**
     * The phasea-owned quality-gate evaluator prompt (v2.6 design §2.5, plan
     * 48-13). The instruction — persona, five dimensions, 1-10 scale, JSON
     * contract — lives in phasea's `BlogPipelineQualityGatePrompt`; the host
     * names it and sends only the module content.
     */
    private const string QUALITY_GATE_TEMPLATE = 'blog_pipeline.quality_gate';

    private const DUPLICATION_THRESHOLD = 0.30;

    /** @var array<string> */
    private const POLISH_DIACRITICS = ['ą', 'ę', 'ó', 'ś', 'ł', 'ż', 'ź', 'ć', 'ń'];

    /** @var array<string> */
    private const JUDGE_DIMENSIONS = [
        'expertise_tone',
        'natural_language',
        'value_density',
        'topic_relevance',
        'readability',
    ];

    public function __construct(
        private readonly ChecksContentSimilarity $similarityService,
        private readonly LlmAuditService $auditService,
        private readonly PipelineCallMemo $callMemo,
    ) {}

    /**
     * Evaluate module output across three layers: structural, content, and LLM judge.
     */
    public function evaluate(ModuleOutput $output, ContentModule $module, BlogPostPipelineRun $run): QualityResult
    {
        $issues = [];
        $scores = [];
        $structuralFail = false;
        $contentFail = false;

        $this->runStructuralChecks($output, $module, $run, $issues, $structuralFail, $contentFail);
        $this->runContentChecks($output, $run, $issues, $contentFail);

        $judgeScores = $this->runLlmJudge($output, $run, $module->slug);
        $scores = array_merge($scores, $judgeScores);

        $overallScore = $this->calculateOverallScore($scores);

        $passed = !$structuralFail && !$contentFail && $overallScore >= $this->resolveQualityThreshold($run);

        return new QualityResult(
            passed: $passed,
            overallScore: $overallScore,
            scores: $scores,
            issues: $issues,
            decision: $this->decide(
                new QualityResult(
                    passed: $passed,
                    overallScore: $overallScore,
                    scores: $scores,
                    issues: $issues,
                ),
                $run,
                $structuralFail || $contentFail,
            ),
        );
    }

    /**
     * Determine the quality decision based on score thresholds.
     */
    public function decide(QualityResult $result, BlogPostPipelineRun $run, bool $structuralFail = false): QualityDecision
    {
        if ($structuralFail) {
            return QualityDecision::RetrySame;
        }

        $threshold = $this->resolveQualityThreshold($run);

        if ($result->overallScore >= $threshold) {
            return QualityDecision::Pass;
        }

        if ($result->overallScore >= ($threshold - 2.0)) {
            return QualityDecision::RetrySame;
        }

        return QualityDecision::Escalate;
    }

    /**
     * Layer 1: Structural checks (fast, no LLM).
     *
     * @param  array<string>  $issues
     */
    private function runStructuralChecks(
        ModuleOutput $output,
        ContentModule $module,
        BlogPostPipelineRun $run,
        array &$issues,
        bool &$structuralFail,
        bool &$contentFail,
    ): void {
        $content = $output->content;
        $stripped = strip_tags($content);

        if (trim($stripped) === '') {
            $issues[] = 'Content is empty';
            $structuralFail = true;

            return;
        }

        $wordCount = str_word_count($stripped);
        $criteria = $module->quality_criteria ?? [];

        $minWords = $criteria['min_words'] ?? null;
        if ($minWords !== null && $wordCount < (int) $minWords) {
            $issues[] = "Word count ({$wordCount}) is below minimum ({$minWords})";
            $structuralFail = true;
        }

        $maxWords = $criteria['max_words'] ?? null;
        if ($maxWords !== null && $wordCount > (int) $maxWords) {
            $issues[] = "Word count ({$wordCount}) exceeds maximum ({$maxWords})";
            $structuralFail = true;
        }

        $this->checkRequiredElements($content, $module, $issues, $structuralFail);
        $this->checkForbiddenPatterns($content, $module, $issues, $contentFail);
        $this->checkOutputFormat($content, $module, $issues, $structuralFail);
        $this->checkHtmlValidity($content, $issues);
        $this->checkLanguageHeuristic($stripped, $run, $issues);
    }

    /**
     * Check that required HTML elements are present in the content.
     *
     * @param  array<string>  $issues
     */
    private function checkRequiredElements(string $content, ContentModule $module, array &$issues, bool &$structuralFail): void
    {
        $criteria = $module->quality_criteria ?? [];
        /** @var array<string> $requiredElements */
        $requiredElements = $criteria['required_elements'] ?? [];

        if ($requiredElements === []) {
            return;
        }

        $missingElements = [];

        foreach ($requiredElements as $element) {
            $element = mb_strtolower(trim($element));
            if ($element === '' || preg_match('/<'.preg_quote($element, '/').'[\s>]/i', $content)) {
                continue;
            }
            $missingElements[] = $element;
        }

        if ($missingElements !== []) {
            $issues[] = 'Missing required HTML elements: '.implode(', ', $missingElements);
            $structuralFail = true;
        }
    }

    /**
     * Check that the output matches the expected format (html, markdown, json).
     *
     * @param  array<string>  $issues
     */
    private function checkOutputFormat(string $content, ContentModule $module, array &$issues, bool &$structuralFail): void
    {
        $expectedFormat = $module->output_format ?? 'html';
        $trimmed = trim($content);

        $issuesBefore = count($issues);

        match ($expectedFormat) {
            'html' => $this->checkIsHtml($trimmed, $issues),
            'json' => $this->checkIsJson($trimmed, $issues),
            default => null,
        };

        if (count($issues) > $issuesBefore) {
            $structuralFail = true;
        }
    }

    /**
     * @param  array<string>  $issues
     */
    private function checkIsHtml(string $content, array &$issues): void
    {
        if ($content !== '' && $content === strip_tags($content) && mb_strlen($content) > 200) {
            $issues[] = 'Expected HTML output but content contains no HTML tags';
        }
    }

    /**
     * @param  array<string>  $issues
     */
    private function checkIsJson(string $content, array &$issues): void
    {
        if ($content === '') {
            return;
        }

        $cleaned = preg_replace('/^```(?:json)?\s*/i', '', $content) ?? $content;
        $cleaned = preg_replace('/\s*```$/', '', $cleaned) ?? $cleaned;

        if (!is_array(json_decode(trim($cleaned), true))) {
            $issues[] = 'Expected JSON output but content is not valid JSON';
        }
    }

    /**
     * Check for forbidden patterns in content (case-insensitive).
     *
     * @param  array<string>  $issues
     */
    private function checkForbiddenPatterns(string $content, ContentModule $module, array &$issues, bool &$contentFail): void
    {
        /** @var array<string> $globalPatterns */
        $globalPatterns = config('blog-pipeline.forbidden_patterns', []);

        $criteria = $module->quality_criteria ?? [];
        /** @var array<string> $modulePatterns */
        $modulePatterns = $criteria['forbidden_patterns'] ?? [];

        $allPatterns = array_unique(array_merge($globalPatterns, $modulePatterns));
        $foundPatterns = [];

        foreach ($allPatterns as $pattern) {
            if (stripos($content, $pattern) !== false) {
                $foundPatterns[] = $pattern;
            }
        }

        if ($foundPatterns !== []) {
            $issues[] = 'Forbidden patterns found: '.implode(', ', $foundPatterns);
            $contentFail = true;
        }
    }

    /**
     * Basic HTML validity check: detect unclosed tags.
     *
     * @param  array<string>  $issues
     */
    private function checkHtmlValidity(string $content, array &$issues): void
    {
        if (trim($content) === '' || strip_tags($content) === $content) {
            return;
        }

        $openTags = [];
        $selfClosing = ['br', 'hr', 'img', 'input', 'meta', 'link', 'area', 'base', 'col', 'embed', 'source', 'track', 'wbr'];

        if (preg_match_all('/<\/?([a-zA-Z][a-zA-Z0-9]*)[^>]*\/?>/', $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $fullTag = $match[0];
                $tagName = mb_strtolower($match[1]);

                if (in_array($tagName, $selfClosing, true)) {
                    continue;
                }

                if (str_contains($fullTag, '</')) {
                    if ($openTags !== [] && end($openTags) === $tagName) {
                        array_pop($openTags);
                    }
                } else {
                    $openTags[] = $tagName;
                }
            }
        }

        if ($openTags !== []) {
            $issues[] = 'Unclosed HTML tags detected: '.implode(', ', array_unique($openTags));
        }
    }

    /**
     * Heuristic language detection for Polish content.
     *
     * @param  array<string>  $issues
     */
    private function checkLanguageHeuristic(string $strippedContent, BlogPostPipelineRun $run, array &$issues): void
    {
        $locale = $run->primary_locale ?? 'en';

        if ($locale !== 'pl') {
            return;
        }

        $hasDiacritics = false;
        foreach (self::POLISH_DIACRITICS as $char) {
            if (mb_strpos($strippedContent, $char) !== false) {
                $hasDiacritics = true;
                break;
            }
        }

        if (!$hasDiacritics && mb_strlen($strippedContent) > 100) {
            $issues[] = 'Content appears to not contain Polish diacritics (expected for locale pl)';
        }
    }

    /**
     * Layer 2: Content checks using existing services.
     *
     * @param  array<string>  $issues
     */
    private function runContentChecks(ModuleOutput $output, BlogPostPipelineRun $run, array &$issues, bool &$contentFail): void
    {
        $moduleOutputs = $run->module_outputs ?? [];

        foreach ($moduleOutputs as $slug => $previousOutput) {
            $previousContent = $previousOutput['content'] ?? '';
            if ($previousContent === '') {
                continue;
            }

            $similarity = $this->similarityService->calculateSimilarity(
                $output->content,
                $previousContent,
            );

            if ($similarity > self::DUPLICATION_THRESHOLD) {
                $issues[] = "High duplication detected with module '{$slug}' (Jaccard similarity: ".round($similarity, 3).')';
                $contentFail = true;
            }
        }
    }

    /**
     * Layer 3: LLM-as-Judge evaluation.
     *
     * Returns scores for each dimension. Gracefully degrades on failure.
     *
     * @return array<string, int>
     */
    private function runLlmJudge(ModuleOutput $output, BlogPostPipelineRun $run, string $moduleSlug): array
    {
        $defaultScores = $this->defaultPassingScores();

        try {
            $provider = self::PROVIDER_LABEL;
            $model = self::MODEL_LABEL;

            // v2.6 (design §2.5, plan 48-13): phasea owns the quality-gate
            // evaluator prompt (`blog_pipeline.quality_gate`). The host sends the
            // raw module content as a structured parameter; the template holds
            // the five scoring dimensions, the 1-10 scale and the JSON contract,
            // and strips the HTML itself.
            $parameters = ['content' => $output->content];

            $startTime = microtime(true);

            // THE THIRD BILLED CALL IN THE POST-PROVIDER WINDOW, AND IT HAD THE
            // SAME DEFECT AS THE OTHER TWO (plan 48-24, F2). `(string) Str::uuid()`
            // was minted here per evaluation, so every `ExecuteModuleJob` retry
            // re-judged — and re-paid for — content that had already been judged.
            //
            // KEYED ON THE CONTENT BEING JUDGED. That is exactly what the call is a
            // function of: a replayed module output derives the same id, and a
            // quality retry, which produces genuinely different content to judge,
            // derives a different one without anyone passing an attempt counter in.
            //
            // A JUDGE FAILURE STILL DEGRADES GRACEFULLY. The catch below is
            // unchanged, so a 409 from a duplicate that outlived its memo lands on
            // the same default-scores path any other judge failure takes — it never
            // becomes a second charge and never fails the run.
            $call = $this->callMemo->remember(
                $run,
                $moduleSlug,
                'module-judge:'.hash('sha256', $output->content),
                function (string $operationId) use ($parameters, $run): array {
                    // Resolve the per-surface client once; the SEAM-08 placeholder is now a
                    // real routing decision: AI_SURFACE_BLOG_PIPELINE_DRIVER is honored
                    // (local = identical Prism path; remote = phasea). phasea owns model
                    // selection and resilience end to end — no provider/model is chosen
                    // or forwarded here.
                    $client = app(AiClientFactory::class)->for(AiSurface::BlogPipeline);

                    Log::debug('QualityGateService: runLlmJudge operation_id', [
                        'pipeline_run_id' => $run->id,
                        'operation_id' => $operationId,
                    ]);

                    $result = $client->runPrompt(
                        self::QUALITY_GATE_TEMPLATE,
                        $parameters,
                        $operationId,
                        // The judge budget rides `runPrompt`'s transport-only options,
                        // never the template parameters, so the judge keeps its own
                        // timeout instead of inheriting the 30s default.
                        [
                            'timeout' => config('ai.assistant.timeouts.judge', 45),
                        ],
                    );

                    return [
                        'text' => $result->text,
                        'prompt_tokens' => $result->promptTokens,
                        'completion_tokens' => $result->completionTokens,
                    ];
                },
            );

            $text = (string) ($call['result']['text'] ?? '');
            $promptTokens = (int) ($call['result']['prompt_tokens'] ?? 0);
            $completionTokens = (int) ($call['result']['completion_tokens'] ?? 0);

            $durationSeconds = microtime(true) - $startTime;
            $cost = $this->calculateEstimatedCost($provider, $model, $promptTokens, $completionTokens);

            // Once per provider call, not once per attempt to make one — a replay
            // must not file a second audit row or double-count one judge call in
            // `total_cost`.
            if ($call['fresh']) {
                $this->auditService->log(
                    step: BlogTopicLlmStep::QualityJudge,
                    provider: $provider,
                    model: $model,
                    // The prompt lives in phasea now; the template key plus the
                    // parameters IS the reproducible input recorded here (D-48-6).
                    prompt: json_encode([
                        'template' => self::QUALITY_GATE_TEMPLATE,
                        'parameters' => $parameters,
                    ], JSON_UNESCAPED_UNICODE) ?: self::QUALITY_GATE_TEMPLATE,
                    response: $text,
                    batchId: $run->candidate->batch_id ?? null,
                    candidateId: $run->candidate_id,
                    tokensIn: $promptTokens,
                    tokensOut: $completionTokens,
                    durationMs: (int) ($durationSeconds * 1000),
                );

                $run->addCost($cost);
            }

            return $this->parseJudgeResponse($text, $defaultScores);
        } catch (\Throwable $e) {
            $run->appendQualityLog([
                'module_slug' => 'judge',
                'timestamp' => now()->toIso8601String(),
                'quality_gate_bypassed' => true,
                'bypass_reason' => $e->getMessage(),
            ]);

            if ((bool) config('blog-pipeline.pause_on_judge_failure', false)) {
                Log::warning('QualityGateService: LLM judge failed, pausing pipeline', [
                    'pipeline_run_id' => $run->id,
                    'error' => $e->getMessage(),
                ]);

                $zeroScores = [];
                foreach (self::JUDGE_DIMENSIONS as $dimension) {
                    $zeroScores[$dimension] = 0;
                }

                return $zeroScores;
            }

            Log::warning('QualityGateService: LLM judge failed, using default passing scores', [
                'pipeline_run_id' => $run->id,
                'error' => $e->getMessage(),
            ]);

            return $defaultScores;
        }
    }

    /**
     * Parse the LLM judge response JSON into scores.
     *
     * @param  array<string, int>  $fallback
     * @return array<string, int>
     */
    private function parseJudgeResponse(string $responseText, array $fallback): array
    {
        $cleaned = trim($responseText);
        $cleaned = preg_replace('/^```(?:json)?\s*/i', '', $cleaned) ?? $cleaned;
        $cleaned = preg_replace('/\s*```$/', '', $cleaned) ?? $cleaned;
        $cleaned = trim($cleaned);

        $decoded = json_decode($cleaned, true);

        if (!is_array($decoded)) {
            Log::warning('QualityGateService: failed to parse judge response as JSON', [
                'response' => mb_substr($responseText, 0, 500),
            ]);

            return $fallback;
        }

        $scores = [];

        foreach (self::JUDGE_DIMENSIONS as $dimension) {
            $value = $decoded[$dimension] ?? null;

            if (!is_numeric($value)) {
                Log::warning("QualityGateService: missing or non-numeric score for dimension '{$dimension}'");

                return $fallback;
            }

            $score = (int) $value;
            $scores[$dimension] = max(1, min(10, $score));
        }

        return $scores;
    }

    /**
     * Calculate overall score as average of all dimension scores.
     *
     * @param  array<string, int>  $scores
     */
    private function calculateOverallScore(array $scores): float
    {
        if ($scores === []) {
            return 0.0;
        }

        return round(array_sum($scores) / count($scores), 2);
    }

    /**
     * Resolve the quality threshold: per-profile override (quality_config), then global config.
     */
    private function resolveQualityThreshold(BlogPostPipelineRun $run): float
    {
        $snapshot = $run->config_snapshot ?? [];

        return (float) ($snapshot['quality_config']['quality_threshold']
            ?? config('blog-pipeline.quality_threshold', 6.0));
    }

    /**
     * Default scores used when LLM judge fails (graceful degradation).
     *
     * @return array<string, int>
     */
    private function defaultPassingScores(): array
    {
        $threshold = (float) config('blog-pipeline.quality_threshold', 6.0);
        $defaultScore = (int) ceil($threshold);

        $scores = [];
        foreach (self::JUDGE_DIMENSIONS as $dimension) {
            $scores[$dimension] = $defaultScore;
        }

        return $scores;
    }
}
