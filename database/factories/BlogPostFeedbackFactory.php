<?php

declare(strict_types=1);

namespace Core45\AiPosts\Database\Factories;

use Core45\AiCore\Support\HostFactory;
use Core45\AiPosts\Models\BlogPostFeedback;
use Core45\AiPosts\Models\BlogPostPipelineRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BlogPostFeedback>
 */
class BlogPostFeedbackFactory extends Factory
{
    /**
     * Named explicitly: Factory::modelName() otherwise guesses
     * a class in the host namespace that does not exist.
     */
    protected $model = BlogPostFeedback::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'pipeline_run_id' => BlogPostPipelineRun::factory(),
            'post_id' => HostFactory::for('post'),
            'user_id' => HostFactory::for('user'),
            'module_slug' => fake()->randomElement(['research', 'outline', 'draft', 'polish', null]),
            'rating' => fake()->numberBetween(1, 5),
            'feedback_type' => fake()->randomElement(['quality', 'accuracy', 'tone', 'structure']),
            'comment' => fake()->paragraph(),
            'action_requested' => fake()->randomElement(['none', 'revise', 'regenerate', 'manual_edit']),
            'ai_analysis' => null,
            'applied_to_profile' => false,
            'applied_to_knowledge' => false,
        ];
    }

    public function withAiAnalysis(): static
    {
        return $this->state(fn (array $attributes): array => [
            'ai_analysis' => [
                'sentiment' => fake()->randomElement(['positive', 'negative', 'neutral']),
                'actionable_items' => [fake()->sentence()],
                'priority' => fake()->randomElement(['low', 'medium', 'high']),
            ],
        ]);
    }

    public function applied(): static
    {
        return $this->state(fn (array $attributes): array => [
            'applied_to_profile' => true,
            'applied_to_knowledge' => true,
        ]);
    }
}
