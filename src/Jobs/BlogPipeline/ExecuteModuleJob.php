<?php

declare(strict_types=1);

namespace Core45\AiPosts\Jobs\BlogPipeline;

use Core45\AiCore\Exceptions\AiOperationAlreadyCompletedException;
use Core45\AiCore\Exceptions\AiRequestRejectedException;
use Core45\AiCore\Services\RemoteAiClient;
use Core45\AiPosts\Enums\BlogPostPipelineStatus;
use Core45\AiPosts\Jobs\BlogTopics\Concerns\NotifiesUser;
use Core45\AiPosts\Models\BlogPostPipelineRun;
use Core45\AiPosts\Services\BlogPipeline\ModuleExecutor;
use Core45\AiPosts\Services\BlogPipeline\PipelineCallMemo;
use Core45\AiPosts\Services\BlogPipeline\PipelineOrchestratorService;
use Core45\AiPosts\Support\HostJobs;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ExecuteModuleJob implements ShouldBeUnique, ShouldQueue
{
    use NotifiesUser;
    use Queueable;

    public int $tries = 5;

    public int $maxExceptions = 2;

    public int $timeout = 600;

    /**
     * @param  array<string, mixed>|null  $moduleConfig
     */
    public function __construct(
        public BlogPostPipelineRun $run,
        public string $moduleSlug,
        public ?array $moduleConfig = null,
        public ?int $userId = null,
    ) {
        $this->onQueue('ai-bulk');
        $this->userId = $run->candidate->approved_by ?? $userId;
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return HostJobs::rateLimitMiddleware();
    }

    public function uniqueId(): string
    {
        return "{$this->run->id}_{$this->moduleSlug}";
    }

    public function handle(ModuleExecutor $executor, PipelineOrchestratorService $orchestrator, PipelineCallMemo $callMemo): void
    {
        $this->run->refresh();

        if ($this->run->status !== BlogPostPipelineStatus::Writing) {
            Log::info("ExecuteModuleJob: skipping {$this->moduleSlug}, pipeline status is {$this->run->status->value}");

            return;
        }

        $this->run->update(['current_step' => "module:{$this->moduleSlug}"]);

        $executor->executeWithEscalation($this->run, $this->moduleSlug, $this->moduleConfig);

        $orchestrator->afterModule($this->run, $this->moduleSlug);

        // THE MEMO'S LIFETIME IS THIS JOB'S, AND THE LAST LINE IS WHERE IT ENDS
        // (plan 48-24, F2). Pruning it inside the executor — as soon as
        // `module_outputs` was written — looked tidier and left a hole exactly the
        // shape of the bug: `trackLinkedUrls()` and `afterModule()` still run after
        // that point, so a failure in either would retry a job whose protection had
        // just been deleted and buy the module's completion a second time. The
        // memo therefore outlives every line that can fail this attempt, and is
        // dropped only once nothing is left to retry.
        //
        // Bounded, and that matters because the entries carry full module text: one
        // module's worth, for the duration of one job.
        $callMemo->forgetModule($this->run, $this->moduleSlug);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error("ExecuteModuleJob failed: {$this->moduleSlug}", [
            'pipeline_run_id' => $this->run->id,
            'error' => $exception->getMessage(),
        ]);

        $this->run->update([
            'status' => BlogPostPipelineStatus::Failed,
            'current_step' => "failed:module:{$this->moduleSlug}",
            'completed_at' => now(),
        ]);

        $this->notifyUser(
            __('Pipeline #:id — module ":module" failed', [
                'id' => $this->run->id,
                'module' => $this->moduleSlug,
            ]),
            $this->friendlyErrorMessage($exception),
            'danger',
        );
    }

    private function friendlyErrorMessage(\Throwable $e): string
    {
        // MONEY-SAFETY REFUSAL, NOT A MALFUNCTION (plan 48-24, F2). The module's
        // operation id is derived from run + module + attempt, so a retry after a
        // completion this host never received asks phasea to re-run an id it has
        // already billed — and phasea refuses rather than charging twice. The
        // operator needs to know the choice was deliberate and that retrying the
        // module as-is cannot clear it, because the alternative to this message is
        // a raw "operation already completed" nobody can act on.
        if ($e instanceof AiOperationAlreadyCompletedException) {
            return __('The AI service already produced and billed this module call, but the result never reached this site (typically a transport timeout). It was NOT re-run, to avoid paying for the same completion twice. Restart the pipeline run to generate this module again.');
        }

        // Permanent request rejection (16-REVIEW MJ-01): 4xx from the AI
        // service (422 validation / 401/403 auth / 404) never clears by
        // retrying — tell the operator to fix configuration, not to wait.
        if ($e instanceof AiRequestRejectedException) {
            return __('The AI service rejected the request (status :status). Retrying will not help — check the prompt size, surface configuration, and AI service credentials.', [
                'status' => $e->status,
            ]);
        }

        // Otherwise message-based classification: RemoteAiClient's
        // Core45\AiCore\Exceptions\* hierarchy hardwires getCode() to 0
        // (AiClientException::__construct always passes 0), so an
        // exception-code check can never match reality here. Other clients use
        // HTTP codes too and must not hijack the LLM-specific messages below.
        $message = $e->getMessage();

        if (str_contains($message, 'not_found')) {
            return __('The AI service reported the requested model is no longer available. This is managed by the AI service, not the blog pipeline config.');
        }

        if (str_contains(strtolower($message), 'rate limit') || str_contains($message, 'rate_limit')) {
            return __('AI provider rate limit persisted after several automatic retries. Please try again in a few minutes.');
        }

        if (str_contains(strtolower($message), 'overloaded') || str_contains($message, 'AI service unavailable')) {
            return __('AI provider is temporarily overloaded. Try again later.');
        }

        if (str_contains(strtolower($message), 'timeout')) {
            return __('AI provider request timed out. The prompt may be too long, or the provider is slow.');
        }

        return Str::limit($message, 300);
    }
}
