<?php

declare(strict_types=1);

namespace Core45\AiPosts\Database\Factories;

use Core45\AiPosts\Enums\BlogPostPipelineStatus;
use Core45\AiPosts\Models\BlogPostPipelineRun;
use Core45\AiPosts\Models\BlogTopicBatch;
use Core45\AiPosts\Models\BlogTopicCandidate;
use Core45\AiPosts\Models\BlogTopicProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BlogPostPipelineRun>
 */
class BlogPostPipelineRunFactory extends Factory
{
    /**
     * Named explicitly: Factory::modelName() otherwise guesses
     * a class in the host namespace that does not exist.
     */
    protected $model = BlogPostPipelineRun::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $profile = BlogTopicProfile::factory()->create();
        $batch = BlogTopicBatch::factory()->create(['profile_id' => $profile->id]);
        $candidate = BlogTopicCandidate::factory()->create(['batch_id' => $batch->id]);

        return [
            'candidate_id' => $candidate->id,
            'profile_id' => $profile->id,
            'post_id' => null,
            'status' => BlogPostPipelineStatus::Researching,
            'current_step' => null,
            'primary_locale' => 'pl',
            'context_json' => [],
            'module_outputs' => [],
            'research_data' => [],
            'quality_log' => [],
            'config_snapshot' => [],
            'total_cost' => 0,
        ];
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => BlogPostPipelineStatus::Completed,
            'started_at' => now()->subHour(),
            'completed_at' => now(),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => BlogPostPipelineStatus::Failed,
            'started_at' => now()->subHour(),
            'completed_at' => now(),
        ]);
    }

    public function paused(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => BlogPostPipelineStatus::Paused,
            'started_at' => now()->subHour(),
            'paused_at' => now(),
        ]);
    }
}
