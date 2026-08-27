<?php

declare(strict_types=1);

namespace Core45\AiPosts\Jobs\BlogPipeline;

use Core45\AiCore\Contracts\AiClient;
use Core45\AiCore\Contracts\NotifiesOperator;
use Core45\AiCore\Enums\AiSurface;
use Core45\AiCore\Enums\NotificationSeverity;
use Core45\AiCore\ModelRegistry;
use Core45\AiCore\Services\AiClientFactory;
use Core45\AiCore\Traits\LogsAIOperations;
use Core45\AiPosts\Enums\BlogPostPipelineStatus;
use Core45\AiPosts\Enums\BlogTopicLlmStep;
use Core45\AiPosts\Models\BlogPostPipelineRun;
use Core45\AiPosts\Services\BlogPipeline\PipelineOrchestratorService;
use Core45\AiPosts\Services\BlogTopics\LlmAuditService;
use Core45\AiPosts\Support\HostJobs;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ResearchSynthesisJob implements ShouldQueue
{
    use LogsAIOperations;
    use Queueable;

    /**
     * v2.6: phasea routes every blog-pipeline call and selects its own model
     * — the host never chooses, so these are honest audit labels, not a
     * resolved setting, and are never forwarded to `completeText()`.
     */
    private const string PROVIDER_LABEL = 'phasea';

    private const string MODEL_LABEL = 'unknown';

    /**
     * The phasea-owned research-synthesis prompt (v2.6 design §2.5, plan 48-13).
     * The persona and the brief structure live in phasea's
     * `BlogPipelineResearchSynthesisPrompt`; the host names it and sends the
     * topic plus the collected research sections.
     */
    private const string SYNTHESIS_TEMPLATE = 'blog_pipeline.research_synthesis';

    public int $tries = 5;

    public int $maxExceptions = 2;

    public int $timeout = 1800;

    public readonly string $operationId;

    public function __construct(
        public BlogPostPipelineRun $run,
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

    public function handle(AiClient $client, LlmAuditService $auditService, PipelineOrchestratorService $orchestrator): void
    {
        $this->run->refresh();

        $researchData = $this->run->research_data ?? [];

        if ($researchData === []) {
            Log::warning('ResearchSynthesisJob: no research data found, storing empty synthesis', [
                'pipeline_run_id' => $this->run->id,
            ]);

            $this->run->storeResearchData('synthesis', [
                'writing_brief' => '',
                'key_points' => [],
                'recommended_word_count' => 0,
                'content_angle' => '',
            ]);

            $orchestrator->afterResearch($this->run);

            return;
        }

        $provider = self::PROVIDER_LABEL;
        $model = self::MODEL_LABEL;

        // v2.6 (design §2.5, plan 48-13): phasea owns the synthesis prompt
        // (`blog_pipeline.research_synthesis`). The host sends the topic and the
        // collected research sections as structured parameters; the template
        // holds the persona and the brief structure, and composes the flatten.
        $parameters = $this->buildPromptParameters($researchData);

        $startTime = microtime(true);

        $client = app(AiClientFactory::class)->for(AiSurface::BlogPipeline);
        $result = $client->runPrompt(
            self::SYNTHESIS_TEMPLATE,
            $parameters,
            $this->operationId,
            // The synthesis budget rides `runPrompt`'s transport-only options,
            // never the template parameters.
            [
                'timeout' => config('ai.assistant.timeouts.module', config('ai.assistant.timeout', 300)),
            ],
        );

        $durationSeconds = microtime(true) - $startTime;
        $promptTokens = $result->promptTokens;
        $completionTokens = $result->completionTokens;
        $cost = $this->calculateEstimatedCost($provider, $model, $promptTokens, $completionTokens);

        $this->logDebug('ResearchSynthesisJob - Operation', [
            'pipeline_run_id' => $this->run->id,
            'operation_id' => $this->operationId,
        ]);

        $auditService->log(
            step: BlogTopicLlmStep::Synthesis,
            provider: $provider,
            model: $model,
            // The prompt lives in phasea now; the template key plus the
            // parameters IS the reproducible input recorded here (D-48-6).
            prompt: json_encode([
                'template' => self::SYNTHESIS_TEMPLATE,
                'parameters' => $parameters,
            ], JSON_UNESCAPED_UNICODE) ?: self::SYNTHESIS_TEMPLATE,
            response: $result->text,
            batchId: $this->run->candidate->batch_id,
            candidateId: $this->run->candidate_id,
            tokensIn: $promptTokens,
            tokensOut: $completionTokens,
            durationMs: (int) ($durationSeconds * 1000),
        );

        $this->run->storeResearchData('synthesis', [
            'writing_brief' => $result->text,
            'llm_used' => "{$provider}/{$model}",
            'tokens_used' => $promptTokens + $completionTokens,
            'cost_estimate' => round($cost, 6),
        ]);

        $this->run->addCost($cost);

        Log::info('ResearchSynthesisJob: completed', [
            'pipeline_run_id' => $this->run->id,
            'provider' => $provider,
            'model' => $model,
            'tokens' => $promptTokens + $completionTokens,
            'cost' => $cost,
        ]);

        $orchestrator->afterResearch($this->run);
    }

    /**
     * Handle job failure: mark the pipeline as failed so it doesn't stay stuck in Researching.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('ResearchSynthesisJob failed', [
            'pipeline_run_id' => $this->run->id,
            'error' => $exception->getMessage(),
        ]);

        $this->run->update([
            'status' => BlogPostPipelineStatus::Failed,
            'current_step' => 'failed:research-synthesis',
            'completed_at' => now(),
        ]);

        $this->notifyFailure($exception);
    }

    /**
     * Notify the approving user about the research synthesis failure.
     */
    private function notifyFailure(\Throwable $exception): void
    {
        $userId = $this->run->candidate->approved_by ?? null;

        if ($userId === null) {
            return;
        }

        $userClass = app(ModelRegistry::class)->getModelClassOrFail('user');
        $user = $userClass::find($userId);

        if ($user === null) {
            return;
        }

        app(NotifiesOperator::class)->notifyOperator(
            $user,
            __('Pipeline Failed'),
            __('Research synthesis failed for ":topic": :error', [
                'topic' => $this->run->candidate->title,
                'error' => $exception->getMessage(),
            ]),
            NotificationSeverity::Danger,
        );
    }

    /**
     * The structured parameters for phasea's `blog_pipeline.research_synthesis`
     * template (plan 48-13). The instruction — persona, brief structure, and the
     * per-section flatten — lives on phasea; the host sends only the topic and
     * whichever research sections it collected, preserving the pre-migration
     * per-section `isset` presence so the template omits an absent section
     * exactly as the old host flatten did.
     *
     * @param  array<string, mixed>  $researchData
     * @return array<string, mixed>
     */
    private function buildPromptParameters(array $researchData): array
    {
        $parameters = ['topic' => (string) $this->run->candidate->title];

        foreach (['keywords', 'serp', 'competitors', 'search_console', 'user_intent'] as $section) {
            if (isset($researchData[$section])) {
                $parameters[$section] = $researchData[$section];
            }
        }

        return $parameters;
    }
}
