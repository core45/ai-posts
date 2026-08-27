<?php

namespace Core45\AiPosts\Database\Factories;

use Core45\AiPosts\Enums\BlogTopicBatchStatus;
use Core45\AiPosts\Models\BlogTopicBatch;
use Core45\AiPosts\Models\BlogTopicProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BlogTopicBatch>
 */
class BlogTopicBatchFactory extends Factory
{
    /**
     * Named explicitly: Factory::modelName() otherwise guesses
     * a class in the host namespace that does not exist.
     */
    protected $model = BlogTopicBatch::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'profile_id' => BlogTopicProfile::factory(),
            'status' => BlogTopicBatchStatus::Draft,
            'config_json' => null,
            'queries_json' => null,
        ];
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => BlogTopicBatchStatus::Completed,
            'started_at' => now()->subHour(),
            'finished_at' => now(),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => BlogTopicBatchStatus::Failed,
            'error_message' => 'Research pipeline failed: API rate limit exceeded',
            'started_at' => now()->subHour(),
            'finished_at' => now(),
        ]);
    }
}
