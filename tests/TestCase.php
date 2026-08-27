<?php

declare(strict_types=1);

namespace Core45\AiPosts\Tests;

use Core45\AiCore\AiCoreServiceProvider;
use Core45\AiPosts\AiPostsServiceProvider;
use Orchestra\Testbench\TestCase as OrchTestCase;

/**
 * D12 two-layer testing: per-package Testbench harness. Boots ONLY this
 * package and ai-core — no host app, no host service providers.
 */
abstract class TestCase extends OrchTestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            AiCoreServiceProvider::class,
            AiPostsServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('ai.base_url', 'https://phasea.test');
        $app['config']->set('ai.token', 'test-token');
    }
}
