<?php

declare(strict_types=1);

namespace Core45\AiPosts\Jobs\BlogPipeline;

use Core45\AiPosts\Enums\BlogPostPipelineStatus;
use Core45\AiPosts\Models\BlogPostPipelineRun;
use Core45\AiPosts\Models\BlogProfileLesson;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Track how active profile lessons affect pipeline quality over time.
 *
 * Dispatched after each pipeline run completes. Compares the current run's
 * quality scores against previous runs for the same profile and adjusts
 * lesson effectiveness accordingly.
 */
class TrackLessonEffectivenessJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 60;

    public function __construct(
        public BlogPostPipelineRun $run,
    ) {
        $this->onQueue('default');
    }

    public function handle(): void
    {
        $this->run->refresh();
        $profile = $this->run->profile;

        if ($profile === null) {
            Log::warning('TrackLessonEffectivenessJob: No profile found for run', [
                'pipeline_run_id' => $this->run->id,
            ]);

            return;
        }

        $activeLessons = BlogProfileLesson::query()
            ->where('profile_id', $profile->id)
            ->where('active', true)
            ->get();

        if ($activeLessons->isEmpty()) {
            Log::info('TrackLessonEffectivenessJob: No active lessons to track', [
                'pipeline_run_id' => $this->run->id,
            ]);

            return;
        }

        $currentScore = $this->calculateRunQualityScore($this->run);
        $previousAverageScore = $this->calculatePreviousAverageScore($profile->id, $this->run->id);

        foreach ($activeLessons as $lesson) {
            $currentEffectiveness = (float) ($lesson->effectiveness_score ?? 0.5);
            $currentTimesApplied = (int) $lesson->times_applied;

            if ($previousAverageScore !== null && $currentScore !== null) {
                if ($currentScore > $previousAverageScore) {
                    $currentEffectiveness = min(1.0, $currentEffectiveness + 0.1);
                } elseif ($currentScore < $previousAverageScore) {
                    $currentEffectiveness = max(0.0, $currentEffectiveness - 0.1);
                }
            }

            $newTimesApplied = $currentTimesApplied + 1;
            $shouldDeactivate = $currentEffectiveness < 0.3 && $newTimesApplied >= 5;

            $lesson->update([
                'effectiveness_score' => round($currentEffectiveness, 2),
                'times_applied' => $newTimesApplied,
                'active' => !$shouldDeactivate,
            ]);

            if ($shouldDeactivate) {
                Log::info('TrackLessonEffectivenessJob: Deactivated low-effectiveness lesson', [
                    'lesson_id' => $lesson->id,
                    'effectiveness_score' => $currentEffectiveness,
                    'times_applied' => $newTimesApplied,
                ]);
            }
        }

        Log::info('TrackLessonEffectivenessJob: Effectiveness tracking completed', [
            'pipeline_run_id' => $this->run->id,
            'profile_id' => $profile->id,
            'lessons_tracked' => $activeLessons->count(),
            'current_score' => $currentScore,
            'previous_average' => $previousAverageScore,
        ]);
    }

    /**
     * Calculate the quality score for a pipeline run from its quality_log or module_outputs.
     */
    private function calculateRunQualityScore(BlogPostPipelineRun $run): ?float
    {
        $qualityLog = $run->quality_log ?? [];

        $scores = [];

        foreach ($qualityLog as $entry) {
            if (isset($entry['overallScore']) && is_numeric($entry['overallScore'])) {
                $scores[] = (float) $entry['overallScore'];
            }
        }

        if ($scores !== []) {
            return round(array_sum($scores) / count($scores), 2);
        }

        $moduleOutputs = $run->module_outputs ?? [];

        foreach ($moduleOutputs as $output) {
            if (isset($output['quality_score']) && is_numeric($output['quality_score'])) {
                $scores[] = (float) $output['quality_score'];
            }
        }

        if ($scores !== []) {
            return round(array_sum($scores) / count($scores), 2);
        }

        return null;
    }

    /**
     * Calculate the average quality score from previous completed runs for this profile.
     */
    private function calculatePreviousAverageScore(int $profileId, int $excludeRunId): ?float
    {
        $previousRuns = BlogPostPipelineRun::query()
            ->where('profile_id', $profileId)
            ->where('id', '!=', $excludeRunId)
            ->where('status', BlogPostPipelineStatus::Completed)
            ->latest('completed_at')
            ->limit(10)
            ->get();

        if ($previousRuns->isEmpty()) {
            return null;
        }

        $scores = [];

        foreach ($previousRuns as $previousRun) {
            $score = $this->calculateRunQualityScore($previousRun);

            if ($score !== null) {
                $scores[] = $score;
            }
        }

        if ($scores === []) {
            return null;
        }

        return round(array_sum($scores) / count($scores), 2);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('TrackLessonEffectivenessJob failed', [
            'pipeline_run_id' => $this->run->id,
            'error' => $exception->getMessage(),
        ]);
    }
}
