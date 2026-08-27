<?php

namespace Core45\AiPosts\Database\Factories;

use Core45\AiPosts\Models\BlogTopicBatch;
use Core45\AiPosts\Models\BlogTopicSource;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BlogTopicSource>
 */
class BlogTopicSourceFactory extends Factory
{
    /**
     * Named explicitly: Factory::modelName() otherwise guesses
     * a class in the host namespace that does not exist.
     */
    protected $model = BlogTopicSource::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $url = fake()->url();

        return [
            'batch_id' => BlogTopicBatch::factory(),
            'url' => $url,
            'url_hash' => hash('sha256', $url),
            'domain' => fake()->domainName(),
            'title' => fake()->sentence(rand(5, 10)),
            'snippet' => fake()->paragraph(2),
            'language' => fake()->randomElement(['en', 'de', 'es', 'fr']),
            'published_at' => fake()->dateTimeBetween('-1 year', 'now'),
            'reliability_score' => fake()->randomFloat(2, 0.3, 1.0),
        ];
    }
}
