<?php

declare(strict_types=1);

namespace Core45\AiPosts\Services\BlogPipeline;

use Core45\AiCore\Contracts\NotifiesOperator;
use Core45\AiCore\Enums\NotificationSeverity;
use Core45\AiCore\ModelRegistry;
use Core45\AiPosts\Enums\BlogPostPipelineStatus;
use Core45\AiPosts\Enums\WorkflowMode;
use Core45\AiPosts\Jobs\BlogPipeline\AssemblePostJob;
use Core45\AiPosts\Jobs\BlogPipeline\CompetitorContentJob;
use Core45\AiPosts\Jobs\BlogPipeline\ExecuteModuleJob;
use Core45\AiPosts\Jobs\BlogPipeline\FinalizePipelineRunJob;
use Core45\AiPosts\Jobs\BlogPipeline\KeywordResearchJob;
use Core45\AiPosts\Jobs\BlogPipeline\RankTrackingInsightsJob;
use Core45\AiPosts\Jobs\BlogPipeline\ResearchSynthesisJob;
use Core45\AiPosts\Jobs\BlogPipeline\SearchConsoleInsightsJob;
use Core45\AiPosts\Jobs\BlogPipeline\SerpAnalysisJob;
use Core45\AiPosts\Jobs\BlogPipeline\UserIntentResearchJob;
use Core45\AiPosts\Jobs\BlogTopics\EnrichPostLinksJob;
use Core45\AiPosts\Jobs\BlogTopics\GenerateBlogFaqJob;
use Core45\AiPosts\Jobs\BlogTopics\GenerateBlogImagesJob;
use Core45\AiPosts\Jobs\TranslatePostToLocale;
use Core45\AiPosts\Models\BlogPostPipelineRun;
use Core45\AiPosts\Models\BlogTopicCandidate;
use Core45\AiPosts\Models\BlogTopicProfile;
use Core45\AiPosts\Models\ContentModule;
use Core45\AiPosts\Support\HostJobs;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;

class PipelineOrchestratorService
{
    /**
     * Start a new pipeline run for the given candidate and profile.
     *
     * Builds a frozen config snapshot, creates the run, links it to the candidate,
     * and dispatches the research batch. After research completes, the synthesis
     * job runs and then the writing modules begin.
     */
    public function start(BlogTopicCandidate $candidate, BlogTopicProfile $profile): BlogPostPipelineRun
    {
        $configSnapshot = $this->buildConfigSnapshot($profile);

        $run = BlogPostPipelineRun::create([
            'candidate_id' => $candidate->id,
            'profile_id' => $profile->id,
            'status' => BlogPostPipelineStatus::Researching,
            'primary_locale' => $profile->primary_locale,
            'config_snapshot' => $configSnapshot,
            'module_outputs' => [],
            'research_data' => [],
            'started_at' => now(),
            'current_step' => 'research',
        ]);

        $candidate->update(['pipeline_run_id' => $run->id]);

        $this->dispatchResearchBatch($run);

        return $run;
    }

    /**
     * Called after research synthesis completes. Checks for checkpoint_after_research
     * and workflow mode, then transitions to writing phase and dispatches the first module.
     *
     * Pausing rules:
     * - StepByStep: always pause after research
     * - SemiAutomatic: pause only if checkpoint_after_research is true
     * - Automatic: never pause
     */
    public function afterResearch(BlogPostPipelineRun $run): void
    {
        $run->refresh();

        $checkpointAfterResearch = (bool) ($run->config_snapshot['modules_config']['checkpoint_after_research'] ?? false);

        $workflowMode = WorkflowMode::from(
            $run->config_snapshot['modules_config']['workflow_mode'] ?? WorkflowMode::Automatic->value,
        );

        $shouldPause = match ($workflowMode) {
            WorkflowMode::Automatic => false,
            WorkflowMode::StepByStep => true,
            WorkflowMode::SemiAutomatic => $checkpointAfterResearch,
        };

        if ($shouldPause) {
            $run->update([
                'status' => BlogPostPipelineStatus::Paused,
                'paused_at' => now(),
                'current_step' => 'checkpoint:after-research',
            ]);

            $this->notifyApprovedBy(
                $run,
                'Research Complete',
                'Research complete for ":topic". Review and approve to continue.',
                ['topic' => $run->candidate->title],
            );

            return;
        }

        $this->dispatchFirstModule($run);
    }

    /**
     * Called after a module completes. Determines the next step:
     * dispatch next module, pause at checkpoint, or trigger assembly.
     *
     * If the module's quality decision is 'pause', the pipeline pauses for human review.
     */
    public function afterModule(BlogPostPipelineRun $run, string $completedSlug): void
    {
        $run->refresh();

        $moduleOutput = ($run->module_outputs ?? [])[$completedSlug] ?? [];
        $qualityDecision = $moduleOutput['quality_decision'] ?? 'pass';

        if ($qualityDecision === 'pause') {
            $run->update([
                'status' => BlogPostPipelineStatus::Paused,
                'paused_at' => now(),
                'current_step' => "quality-pause:{$completedSlug}",
            ]);

            $this->notifyApprovedBy(
                $run,
                'Quality Review Required',
                'Module ":module" needs quality review for ":topic".',
                ['module' => $completedSlug, 'topic' => $run->candidate->title],
            );

            return;
        }

        $modules = $run->config_snapshot['modules_config']['modules'];
        $enabledModules = $this->getEnabledModules($modules);

        $completedIndex = $this->findModuleIndex($enabledModules, $completedSlug);

        if ($completedIndex === -1) {
            throw new \LogicException("Completed module slug '{$completedSlug}' not found in enabled modules list");
        }

        $nextModule = $enabledModules[$completedIndex + 1] ?? null;

        if ($nextModule === null) {
            $this->afterAllModules($run);

            return;
        }

        if ($this->shouldPauseAfter($run, $completedSlug, $modules)) {
            $run->update([
                'status' => BlogPostPipelineStatus::Paused,
                'paused_at' => now(),
                'current_step' => "checkpoint:after-{$completedSlug}",
            ]);

            $this->notifyApprovedBy(
                $run,
                'Module Checkpoint',
                'Module ":module" complete for ":topic". Review and approve to continue.',
                ['module' => $completedSlug, 'topic' => $run->candidate->title],
            );

            return;
        }

        $run->update(['current_step' => $nextModule['module_slug']]);
        ExecuteModuleJob::dispatch($run, $nextModule['module_slug'], $nextModule);
    }

    /**
     * Resume a paused pipeline run by dispatching the next unexecuted module.
     *
     * @throws \LogicException When the run is not in Paused status.
     */
    public function resume(BlogPostPipelineRun $run): void
    {
        if ($run->status !== BlogPostPipelineStatus::Paused) {
            throw new \LogicException('Cannot resume a pipeline that is not paused');
        }

        $modules = $run->config_snapshot['modules_config']['modules'];
        $nextModule = $this->findNextUnexecutedModule($run, $modules);

        $nextStep = $nextModule !== null ? $nextModule['module_slug'] : 'assembly';

        $run->update([
            'status' => BlogPostPipelineStatus::Writing,
            'paused_at' => null,
            'current_step' => $nextStep,
        ]);

        if ($nextModule !== null) {
            ExecuteModuleJob::dispatch($run, $nextModule['module_slug'], $nextModule);
        } else {
            AssemblePostJob::dispatch($run);
        }
    }

    /**
     * Cancel a pipeline run, marking it as failed.
     */
    public function cancel(BlogPostPipelineRun $run): void
    {
        $run->update([
            'status' => BlogPostPipelineStatus::Failed,
            'completed_at' => now(),
        ]);
    }

    /**
     * Force-complete a pipeline run by dispatching assembly with whatever is done.
     */
    public function forceComplete(BlogPostPipelineRun $run): void
    {
        $run->update(['current_step' => 'assembly:forced']);
        AssemblePostJob::dispatch($run);
    }

    /**
     * Re-run a specific module with optional admin feedback injected into the prompt.
     *
     * Stores the feedback in context_json, clears previous output for this module,
     * resets the status to Writing, and dispatches the module job.
     */
    public function regenerateModule(BlogPostPipelineRun $run, string $moduleSlug, ?string $feedback): void
    {
        $contextJson = $run->context_json ?? [];

        if ($feedback !== null && $feedback !== '') {
            $contextJson["regeneration_feedback.{$moduleSlug}"] = $feedback;
        }

        $moduleOutputs = $run->module_outputs ?? [];
        unset($moduleOutputs[$moduleSlug]);

        $run->update([
            'status' => BlogPostPipelineStatus::Writing,
            'paused_at' => null,
            'current_step' => $moduleSlug,
            'context_json' => $contextJson,
            'module_outputs' => $moduleOutputs,
        ]);

        $modules = $run->config_snapshot['modules_config']['modules'] ?? [];
        $moduleConfig = null;

        foreach ($modules as $module) {
            if (($module['module_slug'] ?? '') === $moduleSlug) {
                $moduleConfig = $module;

                break;
            }
        }

        ExecuteModuleJob::dispatch($run, $moduleSlug, $moduleConfig);
    }

    /**
     * Called when all modules are done. Dispatches the assembly job.
     */
    public function afterAllModules(BlogPostPipelineRun $run): void
    {
        $run->update(['current_step' => 'assembly']);
        AssemblePostJob::dispatch($run);
    }

    /**
     * Called after AssemblePostJob completes. Dispatches the enrichment + translation chain
     * and updates the run status to Enriching.
     */
    public function afterAssembly(BlogPostPipelineRun $run): void
    {
        $run->refresh();

        $post = $run->post;

        if ($post === null) {
            return;
        }

        $run->update([
            'status' => BlogPostPipelineStatus::Enriching,
        ]);

        $primaryLocale = $run->primary_locale;
        $candidate = $run->candidate;
        $profile = $run->profile;

        $chain = [];

        if ($candidate->humanize_content) {
            if ($humanizeJob = HostJobs::resolve('humanize')) {
                $chain[] = new $humanizeJob($post, $primaryLocale);
            }
        }

        $chain[] = new EnrichPostLinksJob($post, $primaryLocale, $profile);

        if ($candidate->generate_faq) {
            $chain[] = new GenerateBlogFaqJob($post, $candidate);
        }

        if ($candidate->generate_images && config('ai.assistant.blog_topics.images.enabled', false)) {
            $chain[] = new GenerateBlogImagesJob($post, $candidate);
        }

        /** @var array<string, array<string, mixed>> $supportedLocales */
        $supportedLocales = config('laravellocalization.supportedLocales', []);

        foreach (array_keys($supportedLocales) as $locale) {
            if ($locale !== $primaryLocale) {
                $chain[] = new TranslatePostToLocale($post, $primaryLocale, $locale);
            }
        }

        $chain[] = new FinalizePipelineRunJob($run->id);

        Bus::chain($chain)->onQueue('ai-bulk')->dispatch();
    }

    /**
     * Dispatch the research batch: staggered research jobs based on profile's
     * research_config, with a finally() callback that dispatches ResearchSynthesisJob.
     *
     * Jobs are staggered by 5 seconds each to avoid SerpAPI rate limits.
     */
    private function dispatchResearchBatch(BlogPostPipelineRun $run): void
    {
        /** @var array<string, mixed> $rc */
        $rc = $run->config_snapshot['research_config'] ?? [];

        $jobs = [
            new KeywordResearchJob($run),
            new UserIntentResearchJob($run),
        ];

        if (!empty($rc['enable_serp_analysis'])) {
            $jobs[] = new SerpAnalysisJob($run);
        }

        if (!empty($rc['enable_competitor_scraping'])) {
            $jobs[] = new CompetitorContentJob($run);
        }

        if (!empty($rc['enable_gsc']) || !empty($rc['enable_bing'])) {
            $jobs[] = new SearchConsoleInsightsJob($run);
        }

        if (!empty($rc['enable_wincher'])) {
            $jobs[] = new RankTrackingInsightsJob($run);
        }

        foreach ($jobs as $index => $job) {
            $job->delay(now()->addSeconds($index * 5));
        }

        Bus::batch($jobs)
            ->allowFailures()
            ->finally(function () use ($run): void {
                $freshRun = BlogPostPipelineRun::find($run->id);
                if ($freshRun !== null) {
                    ResearchSynthesisJob::dispatch($freshRun);
                }
            })
            ->name("research-pipeline-{$run->id}")
            ->onQueue('ai-bulk')
            ->dispatch();
    }

    /**
     * Transition to writing phase and dispatch the first enabled module.
     */
    private function dispatchFirstModule(BlogPostPipelineRun $run): void
    {
        $modules = $run->config_snapshot['modules_config']['modules'] ?? [];
        $firstModule = $this->getEnabledModules($modules)[0] ?? null;

        if ($firstModule === null) {
            $run->update([
                'status' => BlogPostPipelineStatus::Failed,
                'completed_at' => now(),
                'current_step' => 'failed:no-modules',
            ]);

            $this->notifyApprovedBy(
                $run,
                'Pipeline Failed',
                'No content modules are enabled for ":topic". Add modules to the profile and try again.',
                ['topic' => $run->candidate->title],
            );

            return;
        }

        $run->update([
            'status' => BlogPostPipelineStatus::Writing,
            'current_step' => $firstModule['module_slug'],
        ]);
        ExecuteModuleJob::dispatch($run, $firstModule['module_slug'], $firstModule);
    }

    /**
     * Build a frozen config snapshot from the profile for immutable storage on the run.
     *
     * @return array<string, mixed>
     */
    private function buildConfigSnapshot(BlogTopicProfile $profile): array
    {
        /** @var array<string, mixed> $modulesConfig */
        $modulesConfig = $profile->modules_config ?? [];

        /** @var array<int, array<string, mixed>> $modules */
        $modules = $modulesConfig['modules'] ?? [];

        if ($modules === []) {
            $modules = $this->defaultModuleItems();
            $modulesConfig['modules'] = $modules;

            Log::warning('Profile has no modules configured, using defaults', [
                'profile_id' => $profile->id,
            ]);
        }
        $modulePrompts = $this->snapshotModulePrompts($modules);

        return [
            'modules_config' => $modulesConfig,
            'module_prompts' => $modulePrompts,
            'quality_config' => $profile->quality_config ?? [],
            'research_config' => $profile->research_config ?? [],
            'brand_narrative' => $profile->brand_narrative ?? '',
        ];
    }

    /**
     * Snapshot a reproducible REFERENCE for each module slug (D-48-6).
     *
     * v2.6 (design §2.5, plan 48-15): the run no longer freezes a COPY of the
     * prompt text — the instruction lives in phasea. It records `module_slug`
     * (which names phasea's default for that slug) and `override_used` (whether a
     * tenant customisation applied). A non-null template column is the tenant's
     * own authored text; the seeded default is null after Task 3. That pair is
     * enough to reconstruct which instruction ran without the host storing any.
     *
     * @param  array<int, array<string, mixed>>  $modules
     * @return array<string, array{module_slug: string, override_used: bool}>
     */
    private function snapshotModulePrompts(array $modules): array
    {
        $slugs = array_column($modules, 'module_slug');

        if ($slugs === []) {
            return [];
        }

        $records = ContentModule::query()
            ->whereIn('slug', $slugs)
            ->get()
            ->keyBy('slug');

        $references = [];

        foreach ($slugs as $slug) {
            $record = $records->get($slug);
            $references[$slug] = [
                'module_slug' => $slug,
                'override_used' => $record !== null
                    && ($record->system_prompt_template !== null || $record->user_prompt_template !== null),
            ];
        }

        return $references;
    }

    /**
     * Filter the modules list to only enabled modules.
     *
     * @param  array<int, array<string, mixed>>  $modules
     * @return array<int, array<string, mixed>>
     */
    private function getEnabledModules(array $modules): array
    {
        return array_values(array_filter(
            $modules,
            fn (array $module): bool => $module['enabled'] ?? true,
        ));
    }

    /**
     * @return array<int, array{module_slug: string, order: int, enabled: bool, checkpoint: bool}>
     */
    private function defaultModuleItems(): array
    {
        $slugs = [
            'expert-content',
            'case-study',
            'faq',
            'common-mistakes',
            'myths',
            'comparisons',
            'insider-insights',
            'checklist',
            'trends',
            'conclusion',
        ];

        return array_map(fn (string $slug, int $index): array => [
            'module_slug' => $slug,
            'order' => $index,
            'enabled' => true,
            'checkpoint' => false,
        ], $slugs, array_keys($slugs));
    }

    /**
     * Find the index of a module by slug within the enabled modules list.
     *
     * @param  array<int, array<string, mixed>>  $enabledModules
     */
    private function findModuleIndex(array $enabledModules, string $slug): int
    {
        foreach ($enabledModules as $index => $module) {
            if (($module['module_slug'] ?? '') === $slug) {
                return $index;
            }
        }

        return -1;
    }

    /**
     * Determine if the pipeline should pause after the completed writing module.
     *
     * Research checkpoint is handled separately by afterResearch().
     *
     * @param  array<int, array<string, mixed>>  $allModules
     */
    private function shouldPauseAfter(BlogPostPipelineRun $run, string $completedSlug, array $allModules): bool
    {
        $workflowMode = WorkflowMode::from(
            $run->config_snapshot['modules_config']['workflow_mode'] ?? WorkflowMode::Automatic->value,
        );

        return match ($workflowMode) {
            WorkflowMode::Automatic => false,
            WorkflowMode::StepByStep => true,
            WorkflowMode::SemiAutomatic => $this->moduleHasCheckpoint($allModules, $completedSlug),
        };
    }

    /**
     * Check whether the given module slug has a checkpoint flag set.
     *
     * @param  array<int, array<string, mixed>>  $modules
     */
    private function moduleHasCheckpoint(array $modules, string $slug): bool
    {
        foreach ($modules as $module) {
            if (($module['module_slug'] ?? '') === $slug) {
                return (bool) ($module['checkpoint'] ?? false);
            }
        }

        return false;
    }

    /**
     * Find the next module that hasn't been executed yet (no entry in module_outputs).
     *
     * @param  array<int, array<string, mixed>>  $allModules
     * @return array<string, mixed>|null
     */
    private function findNextUnexecutedModule(BlogPostPipelineRun $run, array $allModules): ?array
    {
        $completedSlugs = array_keys($run->module_outputs ?? []);

        foreach ($allModules as $module) {
            if (!($module['enabled'] ?? true)) {
                continue;
            }

            $slug = $module['module_slug'] ?? '';

            if (!in_array($slug, $completedSlugs, true)) {
                return $module;
            }
        }

        return null;
    }

    /**
     * Notify the user who approved this pipeline run.
     */
    /**
     * @param  array<string, string>  $bodyParams
     */
    private function notifyApprovedBy(BlogPostPipelineRun $run, string $titleKey, string $bodyKey, array $bodyParams = []): void
    {
        $userId = $run->candidate->approved_by ?? null;

        if ($userId === null) {
            return;
        }

        $userClass = app(ModelRegistry::class)->getModelClassOrFail('user');

        /** @var Model|null $user */
        $user = $userClass::find($userId);

        if ($user === null) {
            return;
        }

        $translatedBody = __($bodyKey, $bodyParams);

        // The translation keys travel WITH the notification rather than being
        // written back into it afterwards.
        //
        // This used to send the notification, then re-read
        // `$user->notifications()->latest()->first()` and patch these three keys
        // into whatever row came back. That was already racy — two runs
        // finishing close together would patch each other's row — and it breaks
        // outright once delivery is behind `NotifiesOperator`: an implementation
        // that writes no database row (the logging default) would leave `latest()`
        // pointing at some older, unrelated notification and corrupt it.
        //
        // The host reads these back in `DatabaseNotification` to re-translate the
        // message into the reader's locale instead of the queue worker's, so they
        // have to be persisted — just not like that.
        app(NotifiesOperator::class)->notifyOperator(
            $user,
            __($titleKey),
            $translatedBody,
            NotificationSeverity::Info,
            [
                'title_key' => $titleKey,
                'body_key' => $bodyKey,
                'body_params' => $bodyParams,
            ],
        );
    }
}
