<?php

declare(strict_types=1);

namespace Core45\AiPosts\Tests;

use Core45\AiCore\AiCoreServiceProvider;
use Core45\AiCore\Models\AIPromptOverride;
use Core45\AiCore\Support\PromptKeyRegistry;
use Core45\AiPosts\AiPostsServiceProvider;
use Illuminate\Support\ServiceProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * A provider that resolves the registry during `register()`, before the domain
 * packages have had their turn. Stands in for anything in a real application
 * that touches the registry early — a middleware binding, an eager Filament
 * panel, another package's provider.
 */
class EarlyRegistryResolver extends ServiceProvider
{
    public function register(): void
    {
        // Force the singleton to be built here, at the worst possible moment.
        $this->app->make(PromptKeyRegistry::class);
    }
}

/**
 * The registry must survive being resolved before every package has registered
 * its keys.
 *
 * This is the failure mode no other test in this suite can see. `ai-core` binds
 * `PromptKeyRegistry` as a singleton, so it is built once and cached. If
 * registration depended on container build-time hooks, anything that resolved
 * the registry early would freeze it — and every existing test would stay green,
 * because in the normal harness all providers register before anything resolves.
 * The admin dropdown would just quietly lose the blog keys in production.
 *
 * `EarlyRegistryResolver` is deliberately ordered between `ai-core` and
 * `ai-posts` so the singleton is already built and cached by the time
 * `AiPostsServiceProvider` runs.
 */
class PromptKeyEarlyResolutionTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            AiCoreServiceProvider::class,
            EarlyRegistryResolver::class,
            AiPostsServiceProvider::class,
        ];
    }

    #[Test]
    public function keys_registered_after_the_registry_was_resolved_still_land(): void
    {
        $offered = AIPromptOverride::availablePromptKeys();

        $this->assertArrayHasKey('post_rewrite_system', $offered);
        $this->assertArrayHasKey('post_seo_system', $offered);
    }

    #[Test]
    public function the_core_keys_survive_early_resolution_too(): void
    {
        $this->assertArrayHasKey('admin_system', AIPromptOverride::availablePromptKeys());
    }

    /**
     * The registry resolved early and the registry resolved late must be the
     * same object — if a second instance were ever built, half the keys would
     * live on the one nobody reads.
     */
    #[Test]
    public function there_is_exactly_one_registry(): void
    {
        $this->assertSame(app(PromptKeyRegistry::class), app(PromptKeyRegistry::class));
    }
}
