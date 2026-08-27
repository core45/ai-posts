<?php

declare(strict_types=1);

namespace Core45\AiPosts\Database\Factories;

use Core45\AiCore\Support\HostFactory;
use Core45\AiPosts\Enums\BlogTopicCandidateStatus;
use Core45\AiPosts\Models\BlogTopicBatch;
use Core45\AiPosts\Models\BlogTopicCandidate;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<BlogTopicCandidate>
 */
class BlogTopicCandidateFactory extends Factory
{
    /**
     * Named explicitly: Factory::modelName() otherwise guesses
     * a class in the host namespace that does not exist.
     */
    protected $model = BlogTopicCandidate::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $title = fake()->sentence(rand(5, 10));

        return [
            'batch_id' => BlogTopicBatch::factory(),
            'title' => $title,
            'slug' => Str::slug($title),
            'intent' => fake()->randomElement(['informational', 'commercial', 'navigational']),
            'rationale' => fake()->paragraph(2),
            'outline_json' => [
                ['heading' => 'Introduction', 'points' => [fake()->sentence()]],
                ['heading' => fake()->sentence(3), 'points' => [fake()->sentence(), fake()->sentence()]],
                ['heading' => 'Conclusion', 'points' => [fake()->sentence()]],
            ],
            'evidence_json' => [
                ['source_id' => 1, 'evidence_summary' => fake()->sentence(), 'relevance' => 'high'],
            ],
            'rank' => fake()->numberBetween(1, 10),
            'enable_citations' => false,
            'status' => BlogTopicCandidateStatus::Proposed,
        ];
    }

    public function approved(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => BlogTopicCandidateStatus::Approved,
            'approved_by' => HostFactory::for('user'),
            'approved_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $config
     */
    public function withImageConfig(?array $config = null): static
    {
        $config ??= [
            'featured_image' => true,
            'gallery' => ['enabled' => true, 'count' => 3],
            'in_text' => ['enabled' => true, 'count' => 2],
        ];

        return $this->state(fn (array $attributes) => [
            'generate_images' => true,
            'image_config' => $config,
        ]);
    }

    /**
     * @param  array{enabled: bool, count: int}|null  $config
     */
    public function withFaqConfig(?array $config = null): static
    {
        $config ??= ['enabled' => true, 'count' => 5];

        return $this->state(fn (array $attributes) => [
            'generate_faq' => true,
            'faq_config' => $config,
        ]);
    }

    public function withCitationsEnabled(): static
    {
        return $this->state(fn (array $attributes) => [
            'enable_citations' => true,
        ]);
    }

    public function rejected(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => BlogTopicCandidateStatus::Rejected,
        ]);
    }

    public function generated(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => BlogTopicCandidateStatus::Generated,
            'approved_by' => HostFactory::for('user'),
            'approved_at' => now()->subHour(),
        ]);
    }
}
