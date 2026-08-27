<?php

declare(strict_types=1);

namespace Core45\AiPosts\Database\Factories;

use Core45\AiPosts\Models\BlogProfileLesson;
use Core45\AiPosts\Models\BlogTopicProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BlogProfileLesson>
 */
class BlogProfileLessonFactory extends Factory
{
    /**
     * Named explicitly: Factory::modelName() otherwise guesses
     * a class in the host namespace that does not exist.
     */
    protected $model = BlogProfileLesson::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'profile_id' => BlogTopicProfile::factory(),
            'lesson_type' => fake()->randomElement(['tone', 'structure', 'vocabulary', 'formatting', 'audience']),
            'lesson_text' => fake()->paragraph(),
            'source_feedback_ids' => [fake()->randomNumber(3), fake()->randomNumber(3)],
            'confidence' => fake()->randomFloat(2, 0.50, 1.00),
            'times_applied' => fake()->numberBetween(0, 20),
            'effectiveness_score' => fake()->optional(0.7)->randomFloat(2, 0.00, 1.00),
            'active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => [
            'active' => false,
        ]);
    }

    public function highConfidence(): static
    {
        return $this->state(fn (array $attributes): array => [
            'confidence' => fake()->randomFloat(2, 0.80, 1.00),
            'effectiveness_score' => fake()->randomFloat(2, 0.80, 1.00),
        ]);
    }
}
