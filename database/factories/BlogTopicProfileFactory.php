<?php

declare(strict_types=1);

namespace Core45\AiPosts\Database\Factories;

use Core45\AiPosts\Models\BlogTopicProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BlogTopicProfile>
 */
class BlogTopicProfileFactory extends Factory
{
    /**
     * Named explicitly: Factory::modelName() otherwise guesses
     * a class in the host namespace that does not exist.
     */
    protected $model = BlogTopicProfile::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->company().' Blog Profile',
            'seed_keywords' => fake()->words(rand(3, 6)),
            'excluded_topics' => [],
            'constraints' => null,
            'brand_narrative' => fake()->paragraph(3),
        ];
    }

    public function withProfile(): static
    {
        return $this->state(fn (array $attributes) => [
            'profile_json' => [
                'themes' => ['industrial equipment', 'electrical components'],
                'target_audience' => 'B2B industrial buyers',
                'verticals' => ['manufacturing', 'construction'],
                'content_strengths' => ['product guides', 'technical specifications'],
                'content_gaps' => ['industry trends', 'case studies'],
            ],
            'last_refreshed_at' => now(),
        ]);
    }

    public function withoutBrandNarrative(): static
    {
        return $this->state(fn (array $attributes) => [
            'brand_narrative' => null,
        ]);
    }
}
