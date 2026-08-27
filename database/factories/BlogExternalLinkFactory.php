<?php

declare(strict_types=1);

namespace Core45\AiPosts\Database\Factories;

use Core45\AiPosts\Enums\BlogExternalLinkStatus;
use Core45\AiPosts\Models\BlogExternalLink;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<BlogExternalLink> */
class BlogExternalLinkFactory extends Factory
{
    protected $model = BlogExternalLink::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $url = fake()->url();

        return [
            'profile_id' => null,
            'url' => $url,
            'url_hash' => hash('sha256', $url),
            'domain' => parse_url($url, PHP_URL_HOST) ?: 'example.com',
            'label' => fake()->sentence(3),
            'status' => BlogExternalLinkStatus::Active,
            'usage_count' => 0,
        ];
    }

    public function global(): static
    {
        return $this->state(fn (array $attributes) => [
            'profile_id' => null,
        ]);
    }

    public function blocked(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => BlogExternalLinkStatus::Blocked,
            'blocked_reason' => fake()->sentence(),
            'blocked_at' => now(),
        ]);
    }

    public function proposedByAi(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => BlogExternalLinkStatus::ProposedByAi,
        ]);
    }
}
