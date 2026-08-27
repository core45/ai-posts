<?php

declare(strict_types=1);

namespace Core45\AiPosts\Jobs\BlogPipeline;

use Core45\AiCore\Contracts\AiClient;
use Core45\AiCore\Enums\AiSurface;
use Core45\AiCore\Services\AiClientFactory;
use Core45\AiCore\Traits\LogsAIOperations;
use Core45\AiPosts\Enums\BlogTopicLlmStep;
use Core45\AiPosts\Jobs\BlogTopics\Concerns\NotifiesUser;
use Core45\AiPosts\Models\BlogPostPipelineRun;
use Core45\AiPosts\Models\BlogProfileLesson;
use Core45\AiPosts\Services\BlogTopics\LlmAuditService;
use Core45\AiPosts\Support\HostJobs;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Regenerate a specific module's content based on admin feedback.
 */
class ImproveModuleJob implements ShouldQueue
{
    use LogsAIOperations;
    use NotifiesUser;
    use Queueable;

    public int $tries = 5;

    public int $maxExceptions = 2;

    public int $timeout = 1800;

    /**
     * v2.6: phasea routes every blog-pipeline call and selects its own model
     * — the host never chooses, so these are honest audit labels, not a
     * resolved setting, and are never forwarded to `completeText()`.
     */
    private const string PROVIDER_LABEL = 'phasea';

    private const string MODEL_LABEL = 'unknown';

    /**
     * The phasea-owned module-improvement prompt (v2.6 design §2.5, plan 48-13).
     * The content-editor persona and the ORIGINAL CONTENT / ADMIN FEEDBACK /
     * PROFILE WRITING LESSONS layout live in phasea's
     * `BlogPipelineImproveModulePrompt`; the host names it and sends the module
     * content, the admin feedback and the active profile lessons.
     */
    private const string IMPROVE_MODULE_TEMPLATE = 'blog_pipeline.improve_module';

    public readonly string $operationId;

    public function __construct(
        public BlogPostPipelineRun $run,
        public string $moduleSlug,
        public ?string $feedback = null,
        public ?int $userId = null,
    ) {
        $this->onQueue('ai-bulk');
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

        $moduleOutputs = $this->run->module_outputs ?? [];
        $existingOutput = $moduleOutputs[$this->moduleSlug] ?? null;

        if ($existingOutput === null) {
            Log::warning('ImproveModuleJob: No existing output found for module', [
                'pipeline_run_id' => $this->run->id,
                'module_slug' => $this->moduleSlug,
            ]);

            return;
        }

        $originalContent = (string) ($existingOutput['content'] ?? '');

        if ($originalContent === '') {
            Log::warning('ImproveModuleJob: Module output content is empty', [
                'pipeline_run_id' => $this->run->id,
                'module_slug' => $this->moduleSlug,
            ]);

            return;
        }

        $profileLessons = $this->loadProfileLessons();

        // v2.6 (design §2.5, plan 48-13): phasea owns the module-improvement
        // prompt (`blog_pipeline.improve_module`). The host sends the module's
        // current content, the admin feedback and the active profile lessons as
        // structured parameters; the template holds the content-editor persona
        // and renders the ORIGINAL CONTENT / ADMIN FEEDBACK / PROFILE WRITING
        // LESSONS layout, delimiting the human-authored feedback (T-48-47).
        $parameters = [
            'content' => $originalContent,
            'feedback' => $this->feedback,
            'profile_lessons' => $profileLessons,
        ];

        $provider = self::PROVIDER_LABEL;
        $model = self::MODEL_LABEL;

        $startTime = microtime(true);

        $client = app(AiClientFactory::class)->for(AiSurface::BlogPipeline);
        $result = $client->runPrompt(
            self::IMPROVE_MODULE_TEMPLATE,
            $parameters,
            $this->operationId,
            // The module budget rides `runPrompt`'s transport-only options.
            [
                'timeout' => config('ai.assistant.timeouts.module', 480),
            ],
        );

        $durationSeconds = microtime(true) - $startTime;
        $promptTokens = $result->promptTokens;
        $completionTokens = $result->completionTokens;

        $this->logDebug('ImproveModuleJob - Operation', [
            'pipeline_run_id' => $this->run->id,
            'module_slug' => $this->moduleSlug,
            'operation_id' => $this->operationId,
        ]);

        $auditService->log(
            step: BlogTopicLlmStep::ModuleImprovement,
            provider: $provider,
            model: $model,
            // The prompt lives in phasea now; the template key plus the
            // parameters IS the reproducible input recorded here (D-48-6).
            prompt: json_encode([
                'template' => self::IMPROVE_MODULE_TEMPLATE,
                'parameters' => $parameters,
            ], JSON_UNESCAPED_UNICODE) ?: self::IMPROVE_MODULE_TEMPLATE,
            response: $result->text,
            batchId: $this->run->candidate->batch_id ?? null,
            candidateId: $this->run->candidate_id,
            tokensIn: $promptTokens,
            tokensOut: $completionTokens,
            durationMs: (int) ($durationSeconds * 1000),
        );

        $cost = $this->calculateEstimatedCost($provider, $model, $promptTokens, $completionTokens);
        $this->run->addCost($cost);

        $existingOutput['content'] = $result->text;
        $existingOutput['improved'] = true;
        $existingOutput['improvement_feedback'] = $this->feedback;
        $this->run->storeModuleOutput($this->moduleSlug, $existingOutput);

        Log::info('ImproveModuleJob: Module content improved', [
            'pipeline_run_id' => $this->run->id,
            'module_slug' => $this->moduleSlug,
            'original_length' => strlen($originalContent),
            'improved_length' => strlen($result->text),
        ]);

        $this->notifyUser(
            __('Module Improved'),
            __('Module ":module" for pipeline #:id has been improved based on feedback.', [
                'module' => $this->moduleSlug,
                'id' => $this->run->id,
            ]),
            'success',
        );
    }

    /**
     * Load active profile lessons for the run's profile.
     *
     * @return array<string>
     */
    private function loadProfileLessons(): array
    {
        $profile = $this->run->profile;

        if ($profile === null) {
            return [];
        }

        return BlogProfileLesson::query()
            ->where('profile_id', $profile->id)
            ->where('active', true)
            ->where('confidence', '>=', 0.5)
            ->pluck('lesson_text')
            ->toArray();
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('ImproveModuleJob failed', [
            'pipeline_run_id' => $this->run->id,
            'module_slug' => $this->moduleSlug,
            'error' => $exception->getMessage(),
        ]);

        $this->notifyUser(
            __('Module Improvement Failed'),
            __('Failed to improve module ":module" for pipeline #:id: :error', [
                'module' => $this->moduleSlug,
                'id' => $this->run->id,
                'error' => $exception->getMessage(),
            ]),
            'danger',
        );
    }
}
