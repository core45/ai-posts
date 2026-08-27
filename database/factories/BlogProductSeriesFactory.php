<?php

declare(strict_types=1);

namespace Core45\AiPosts\Database\Factories;

use Core45\AiPosts\Enums\BlogProductSeriesStatus;
use Core45\AiPosts\Models\BlogProductSeries;
use Core45\AiPosts\Models\BlogTopicProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<BlogProductSeries> */
class BlogProductSeriesFactory extends Factory
{
    protected $model = BlogProductSeries::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'profile_id' => BlogTopicProfile::factory(),
            'name' => fake()->sentence(3),
            'target_type' => fake()->randomElement(['product', 'category', 'tag']),
            'target_ids' => [fake()->numberBetween(1, 100)],
            'total_posts' => fake()->numberBetween(5, 20),
            'interval_days' => fake()->randomElement([3, 7, 14]),
            'auto_publish' => fake()->boolean(),
            'status' => BlogProductSeriesStatus::Planning,
            'posts_generated' => 0,
        ];
    }

    public function planned(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => BlogProductSeriesStatus::Planned,
            'topics_json' => [
                ['title' => 'Topic 1', 'outline' => ['Section A']],
                ['title' => 'Topic 2', 'outline' => ['Section B']],
            ],
        ]);
    }

    public function inProgress(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => BlogProductSeriesStatus::InProgress,
            'next_publish_at' => now()->addDays(7),
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => BlogProductSeriesStatus::Completed,
        ]);
    }
}
