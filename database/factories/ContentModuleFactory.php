<?php

declare(strict_types=1);

namespace Core45\AiPosts\Database\Factories;

use Core45\AiPosts\Models\ContentModule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ContentModule>
 */
class ContentModuleFactory extends Factory
{
    /**
     * Named explicitly: Factory::modelName() otherwise guesses
     * a class in the host namespace that does not exist.
     */
    protected $model = ContentModule::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'slug' => fake()->unique()->slug(3),
            'name' => fake()->words(3, true),
            'description' => fake()->sentence(),
            // v2.6 (plan 48-15): factories fabricate no prompt text. NULL means
            // "use phasea's registered default for this slug"; a test that needs a
            // tenant override sets the column explicitly (a non-null value). A
            // factory that manufactured prompt bodies would keep the host-owns-the-
            // prompt pattern alive in every new test.
            'system_prompt_template' => null,
            'user_prompt_template' => null,
            'output_format' => 'html',
            'quality_criteria' => null,
            'requires_research' => true,
            'requires_previous_modules' => true,
            'is_built_in' => false,
            'active' => true,
        ];
    }

    public function builtIn(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_built_in' => true,
        ]);
    }
}
