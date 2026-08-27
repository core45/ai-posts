<?php

declare(strict_types=1);

namespace Core45\AiPosts\Jobs\BlogPipeline;

use Astrotomic\Translatable\Contracts\Translatable;
use Core45\AiCore\Contracts\NotifiesOperator;
use Core45\AiCore\Enums\NotificationSeverity;
use Core45\AiCore\ModelRegistry;
use Core45\AiPosts\Enums\BlogPostPipelineStatus;
use Core45\AiPosts\Jobs\TranslatePostToLocale;
use Core45\AiPosts\Models\BlogPostPipelineRun;
use Core45\AiPosts\Models\BlogProfileLesson;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Final step in the enrichment chain. Marks the pipeline run as completed.
 */
class FinalizePipelineRunJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly int $runId,
    ) {
        $this->onQueue('ai-bulk');
    }

    public function handle(): void
    {
        $run = BlogPostPipelineRun::find($this->runId);

        if ($run === null) {
            Log::warning('FinalizePipelineRunJob: Run not found', [
                'run_id' => $this->runId,
            ]);

            return;
        }

        if ($run->status->isTerminal()) {
            Log::info('FinalizePipelineRunJob: Run already in terminal state', [
                'run_id' => $this->runId,
                'status' => $run->status->value,
            ]);

            return;
        }

        $run->update([
            'status' => BlogPostPipelineStatus::Completed,
            'completed_at' => now(),
            'current_step' => 'completed',
        ]);

        Log::info('FinalizePipelineRunJob: Pipeline run completed', [
            'run_id' => $this->runId,
            'post_id' => $run->post_id,
        ]);

        $this->verifyLocaleBodies($run);
        $this->notifyPipelineComplete($run);
        $this->dispatchLearningJobs($run);
    }

    /**
     * Defence-in-depth: every non-primary locale should have a translated body by now.
     * If one is missing (e.g. a translation that never recovered), re-dispatch it so the
     * post does not quietly ship with an untranslated locale.
     */
    private function verifyLocaleBodies(BlogPostPipelineRun $run): void
    {
        $post = $run->post;

        if ($post === null) {
            return;
        }

        /** @var array<string, array<string, mixed>> $supportedLocales */
        $supportedLocales = config('laravellocalization.supportedLocales', []);

        foreach (array_keys($supportedLocales) as $locale) {
            if ($locale === $run->primary_locale) {
                continue;
            }

            $translation = $post instanceof Translatable ? $post->translate($locale) : null;
            if ($translation !== null && !empty($translation->main_text)) {
                continue;
            }

            Log::warning('FinalizePipelineRunJob: Missing translated body, re-dispatching translation', [
                'run_id' => $run->id,
                'post_id' => $post->getKey(),
                'locale' => $locale,
            ]);

            TranslatePostToLocale::dispatch($post, $run->primary_locale, $locale);
        }
    }

    /**
     * Notify the operator about pipeline completion.
     */
    private function notifyPipelineComplete(BlogPostPipelineRun $run): void
    {
        $userId = $run->candidate->approved_by;

        if ($userId === null) {
            return;
        }

        $userClass = app(ModelRegistry::class)->getModelClassOrFail('user');
        $user = $userClass::find($userId);

        if ($user === null) {
            return;
        }

        $candidateTitle = $run->candidate->title;

        app(NotifiesOperator::class)->notifyOperator(
            $user,
            __('Blog Post Generated'),
            __('Blog post ":title" generated. Ready for review.', ['title' => $candidateTitle]),
            NotificationSeverity::Success,
        );
    }

    /**
     * Dispatch feedback learning and lesson effectiveness jobs after pipeline completion.
     */
    private function dispatchLearningJobs(BlogPostPipelineRun $run): void
    {
        TrackLessonEffectivenessJob::dispatch($run);

        $qualifyingLessons = BlogProfileLesson::query()
            ->where('profile_id', $run->profile_id)
            ->where('active', true)
            ->where('confidence', '>=', 0.8)
            ->where('effectiveness_score', '>=', 0.6)
            ->where('times_applied', '>=', 5)
            ->get();

        $userId = $run->candidate?->approved_by;

        foreach ($qualifyingLessons as $lesson) {
            PromoteToGlobalKnowledgeJob::dispatch($lesson, $userId);
        }

        if ($qualifyingLessons->isNotEmpty()) {
            Log::info('FinalizePipelineRunJob: Dispatched promotion jobs for qualifying lessons', [
                'run_id' => $run->id,
                'qualifying_lesson_count' => $qualifyingLessons->count(),
            ]);
        }
    }
}
