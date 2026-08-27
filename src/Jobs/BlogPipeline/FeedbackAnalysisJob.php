<?php

declare(strict_types=1);

namespace Core45\AiPosts\Jobs\BlogPipeline;

use Core45\AiCore\Contracts\AiClient;
use Core45\AiCore\Enums\AiSurface;
use Core45\AiCore\Services\AiClientFactory;
use Core45\AiCore\Traits\LogsAIOperations;
use Core45\AiPosts\Enums\BlogTopicLlmStep;
use Core45\AiPosts\Jobs\BlogTopics\Concerns\NotifiesUser;
use Core45\AiPosts\Models\BlogPostFeedback;
use Core45\AiPosts\Models\BlogPostPipelineRun;
use Core45\AiPosts\Models\BlogProfileLesson;
use Core45\AiPosts\Services\BlogTopics\LlmAuditService;
use Core45\AiPosts\Support\HostJobs;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class FeedbackAnalysisJob implements ShouldQueue
{
    use LogsAIOperations;
    use NotifiesUser;
    use Queueable;

    public int $tries = 5;

    public int $maxExceptions = 2;

    public int $timeout = 1800;

    private const SIMILARITY_THRESHOLD = 0.7;

    /**
     * v2.6: phasea routes every blog-pipeline call and selects its own model
     * — the host never chooses, so these are honest audit labels, not a
     * resolved setting, and are never forwarded to `completeText()`.
     */
    private const string PROVIDER_LABEL = 'phasea';

    private const string MODEL_LABEL = 'unknown';

    /**
     * The phasea-owned feedback-analysis prompt (v2.6 design §2.5, plan 48-13).
     * The writing-quality-analyst persona and the feedback/lessons layout live in
     * phasea's `BlogPipelineFeedbackAnalysisPrompt`; the host names it and sends
     * the feedback records plus the existing profile lessons.
     */
    private const string FEEDBACK_ANALYSIS_TEMPLATE = 'blog_pipeline.feedback_analysis';

    public readonly string $operationId;

    public function __construct(
        public BlogPostPipelineRun $run,
        public ?int $userId = null,
    ) {
        $this->onQueue('default');
        $this->operationId = (string) Str::uuid();
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return HostJobs::rateLimitMiddleware();
    }

    public function handle(AiClient $client, LlmAuditService $auditService): void
    {
        $this->run->refresh();

        $feedbackRecords = BlogPostFeedback::query()
            ->where('pipeline_run_id', $this->run->id)
            ->where('applied_to_profile', false)
            ->get();

        if ($feedbackRecords->isEmpty()) {
            Log::info('FeedbackAnalysisJob: No unapplied feedback found', [
                'pipeline_run_id' => $this->run->id,
            ]);

            return;
        }

        $profile = $this->run->profile;
        if ($profile === null) {
            Log::warning('FeedbackAnalysisJob: No profile found for run', [
                'pipeline_run_id' => $this->run->id,
            ]);

            return;
        }

        $existingLessons = BlogProfileLesson::query()
            ->where('profile_id', $profile->id)
            ->where('active', true)
            ->get();

        $lessons = $this->extractLessons($feedbackRecords, $existingLessons, $auditService);

        if ($lessons === []) {
            Log::info('FeedbackAnalysisJob: No lessons extracted from feedback', [
                'pipeline_run_id' => $this->run->id,
            ]);

            $this->markFeedbackApplied($feedbackRecords);

            return;
        }

        $feedbackIds = $feedbackRecords->pluck('id')->toArray();

        foreach ($lessons as $lesson) {
            $this->persistLesson($lesson, $profile->id, $feedbackIds, $existingLessons);
        }

        $this->markFeedbackApplied($feedbackRecords);

        Log::info('FeedbackAnalysisJob: Lessons extracted and persisted', [
            'pipeline_run_id' => $this->run->id,
            'lessons_count' => count($lessons),
        ]);

        $this->notifyUser(
            __('Feedback Analyzed'),
            __(':count lesson(s) extracted from pipeline #:id feedback.', [
                'count' => count($lessons),
                'id' => $this->run->id,
            ]),
            'success',
        );
    }

    /**
     * Extract lessons from feedback using LLM analysis.
     *
     * @param  Collection<int, BlogPostFeedback>  $feedbackRecords
     * @param  Collection<int, BlogProfileLesson>  $existingLessons
     * @return array<int, array{type: string, lesson_text: string, confidence: float}>
     */
    private function extractLessons(
        Collection $feedbackRecords,
        Collection $existingLessons,
        LlmAuditService $auditService,
    ): array {
        $feedbackSummary = $feedbackRecords->map(fn (BlogPostFeedback $fb): array => [
            'module' => $fb->module_slug,
            'rating' => $fb->rating,
            'type' => $fb->feedback_type,
            'comment' => $fb->comment,
            'action' => $fb->action_requested,
        ])->values()->toArray();

        $existingLessonTexts = $existingLessons
            ->pluck('lesson_text')
            ->toArray();

        // v2.6 (design §2.5, plan 48-13): phasea owns the feedback-analysis
        // prompt (`blog_pipeline.feedback_analysis`). The host sends the feedback
        // records and the existing profile lessons as structured parameters; the
        // template holds the writing-quality-analyst persona and renders the
        // pretty-printed feedback block.
        $parameters = ['feedback_items' => $feedbackSummary];

        if ($existingLessonTexts !== []) {
            $parameters['existing_lessons'] = $existingLessonTexts;
        }

        $provider = self::PROVIDER_LABEL;
        $model = self::MODEL_LABEL;

        $startTime = microtime(true);

        $client = app(AiClientFactory::class)->for(AiSurface::BlogPipeline);
        $result = $client->runPrompt(
            self::FEEDBACK_ANALYSIS_TEMPLATE,
            $parameters,
            $this->operationId,
            // The feedback-analysis budget rides `runPrompt`'s transport-only
            // options.
            [
                'timeout' => config('ai.assistant.timeouts.module', 480),
            ],
        );

        $durationSeconds = microtime(true) - $startTime;
        $promptTokens = $result->promptTokens;
        $completionTokens = $result->completionTokens;

        $this->logDebug('FeedbackAnalysisJob - Operation', [
            'pipeline_run_id' => $this->run->id,
            'operation_id' => $this->operationId,
        ]);

        $auditService->log(
            step: BlogTopicLlmStep::FeedbackAnalysis,
            provider: $provider,
            model: $model,
            // The prompt lives in phasea now; the template key plus the
            // parameters IS the reproducible input recorded here (D-48-6).
            prompt: json_encode([
                'template' => self::FEEDBACK_ANALYSIS_TEMPLATE,
                'parameters' => $parameters,
            ], JSON_UNESCAPED_UNICODE) ?: self::FEEDBACK_ANALYSIS_TEMPLATE,
            response: $result->text,
            batchId: $this->run->candidate->batch_id ?? null,
            candidateId: $this->run->candidate_id,
            tokensIn: $promptTokens,
            tokensOut: $completionTokens,
            durationMs: (int) ($durationSeconds * 1000),
        );

        $cost = $this->calculateEstimatedCost($provider, $model, $promptTokens, $completionTokens);
        $this->run->addCost($cost);

        return $this->parseJsonResponse($result->text);
    }

    /**
     * Parse LLM JSON response, handling markdown fences and invalid JSON gracefully.
     *
     * @return array<int, array{type: string, lesson_text: string, confidence: float}>
     */
    private function parseJsonResponse(string $responseText): array
    {
        $cleaned = trim($responseText);
        $cleaned = preg_replace('/^```(?:json)?\s*/i', '', $cleaned) ?? $cleaned;
        $cleaned = preg_replace('/\s*```$/', '', $cleaned) ?? $cleaned;
        $cleaned = trim($cleaned);

        try {
            $decoded = json_decode($cleaned, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            Log::warning('FeedbackAnalysisJob: Failed to parse LLM response as JSON', [
                'pipeline_run_id' => $this->run->id,
                'error' => $e->getMessage(),
                'response_preview' => mb_substr($responseText, 0, 500),
            ]);

            return [];
        }

        if (!is_array($decoded)) {
            return [];
        }

        $validLessons = [];
        $validTypes = ['style', 'content', 'structure', 'seo', 'avoid', 'prefer'];

        foreach ($decoded as $item) {
            if (!is_array($item)) {
                continue;
            }

            $type = $item['type'] ?? null;
            $lessonText = $item['lesson_text'] ?? null;
            $confidence = $item['confidence'] ?? 0.5;

            if (!is_string($type) || !in_array($type, $validTypes, true)) {
                continue;
            }

            if (!is_string($lessonText) || trim($lessonText) === '') {
                continue;
            }

            $validLessons[] = [
                'type' => $type,
                'lesson_text' => trim($lessonText),
                'confidence' => min(1.0, max(0.0, (float) $confidence)),
            ];
        }

        return $validLessons;
    }

    /**
     * Persist a lesson: update existing similar lesson or create new one.
     *
     * @param  array{type: string, lesson_text: string, confidence: float}  $lesson
     * @param  Collection<int, BlogProfileLesson>  $existingLessons
     * @param  array<int>  $feedbackIds
     */
    private function persistLesson(array $lesson, int $profileId, array $feedbackIds, Collection $existingLessons): void
    {
        $similarLesson = $this->findSimilarLesson($lesson['lesson_text'], $existingLessons);

        if ($similarLesson !== null) {
            $currentConfidence = (float) $similarLesson->confidence;
            $newConfidence = min(1.0, $currentConfidence + 0.1);

            $existingFeedbackIds = $similarLesson->source_feedback_ids ?? [];
            $mergedFeedbackIds = array_values(array_unique(array_merge($existingFeedbackIds, $feedbackIds)));

            $similarLesson->update([
                'confidence' => $newConfidence,
                'source_feedback_ids' => $mergedFeedbackIds,
            ]);

            Log::info('FeedbackAnalysisJob: Updated existing lesson confidence', [
                'lesson_id' => $similarLesson->id,
                'old_confidence' => $currentConfidence,
                'new_confidence' => $newConfidence,
            ]);

            return;
        }

        BlogProfileLesson::create([
            'profile_id' => $profileId,
            'lesson_type' => $lesson['type'],
            'lesson_text' => $lesson['lesson_text'],
            'source_feedback_ids' => $feedbackIds,
            'confidence' => 0.5,
            'times_applied' => 0,
            'effectiveness_score' => null,
            'active' => true,
        ]);
    }

    /**
     * Find an existing lesson with similar text (similarity > threshold).
     *
     * @param  Collection<int, BlogProfileLesson>  $existingLessons
     */
    private function findSimilarLesson(string $lessonText, Collection $existingLessons): ?BlogProfileLesson
    {
        $bestMatch = null;
        $bestPercent = 0.0;

        foreach ($existingLessons as $existing) {
            $percent = 0.0;
            similar_text(
                mb_strtolower($lessonText),
                mb_strtolower($existing->lesson_text),
                $percent,
            );

            $normalizedPercent = $percent / 100.0;

            if ($normalizedPercent > self::SIMILARITY_THRESHOLD && $normalizedPercent > $bestPercent) {
                $bestMatch = $existing;
                $bestPercent = $normalizedPercent;
            }
        }

        return $bestMatch;
    }

    /**
     * Mark all feedback records as applied to profile.
     *
     * @param  Collection<int, BlogPostFeedback>  $feedbackRecords
     */
    private function markFeedbackApplied(Collection $feedbackRecords): void
    {
        BlogPostFeedback::query()
            ->whereIn('id', $feedbackRecords->pluck('id'))
            ->update(['applied_to_profile' => true]);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('FeedbackAnalysisJob failed', [
            'pipeline_run_id' => $this->run->id,
            'error' => $exception->getMessage(),
        ]);
    }
}
