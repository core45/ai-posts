<?php

declare(strict_types=1);

namespace Core45\AiPosts;

use Core45\AiCore\Support\PromptKeyRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * ai-posts package provider (D11): the blog AI stack — BlogTopics research and
 * generation, the BlogPipeline modules/orchestrator/quality gates, post
 * rewrite, translation and photo generation.
 *
 * The package now owns the blog domain itself: the Blog* models, their enums,
 * DTOs, factories and the migrations that create their tables. The host keeps
 * the Filament UI and schedules the jobs (see README, "Host requirements").
 */
class AiPostsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Package-owned defaults. §6.2: mergeConfigFrom is a SHALLOW, top-level
        // merge — a host that publishes its own config/ai-posts.php and omits a
        // top-level key loses everything beneath it. There is exactly one
        // top-level key here ('taxonomy') to keep that failure mode small.
        $this->mergeConfigFrom(__DIR__.'/../config/ai-posts.php', 'ai-posts');

        // The pipeline reads `config('blog-pipeline.*')` in fourteen places —
        // quality thresholds, retry limits, forbidden patterns, payload ceilings
        // — but the package shipped no such file and never merged one. Every one
        // of those reads fell back to its inline default in any app that had not
        // written `config/blog-pipeline.php` by hand.
        //
        // Those defaults are not all the same. `summarization_max_words` reads
        // 300 inline against 800 in this file, so an install without the file
        // silently summarised to well under half the intended length — no error,
        // just shorter content. That is the failure this merge prevents, and it
        // is why the shipped file is the source of truth rather than a
        // convenience.
        $this->mergeConfigFrom(__DIR__.'/../config/blog-pipeline.php', 'blog-pipeline');

        $this->registerPromptKeys();
    }

    /**
     * The two prompt keys this package owns, from `PostContentRewriteService`'s
     * `getPromptOverrideKey()` / `getSeoPromptOverrideKey()`.
     *
     * They used to be enumerated in `ai-core`'s `availablePromptKeys()` along
     * with every other domain's, which is the leak D14 names. `ai-core` now
     * holds the registry and knows none of its contents.
     */
    private function registerPromptKeys(): void
    {
        $this->app->resolving(PromptKeyRegistry::class, static function (PromptKeyRegistry $registry): void {
            $registry->registerMany([
                'post_rewrite_system' => [
                    'label' => 'Post Content Rewrite System Prompt',
                    'template' => 'rewrite.field_rewrite',
                ],
                'post_seo_system' => [
                    'label' => 'Post SEO Generation System Prompt',
                    'template' => 'rewrite.seo_field',
                ],
            ]);
        });
    }

    public function boot(): void
    {
        // D5/§6.1: these migrations keep their ORIGINAL basenames from the
        // host tree. Laravel's Migrator keys the `migrations` ledger on
        // basename, not path, so an existing install already records every one
        // of them and none re-runs. Re-timestamping any of these files would
        // make it run again against a table that already exists.
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        // These migrations are deliberately NOT publishable.
        //
        // Publishing would copy all 25 byte-identical basenames into
        // database/migrations, and §6.1's duplicate rule then bites silently:
        // Migrator::getMigrationFiles() keys the set by basename
        // (Migrator.php:584), and BaseCommand::getMigrationPaths() appends
        // database/migrations LAST (BaseCommand.php:28-29), so on a collision
        // the published host copy wins and the package's own copy is discarded.
        // Every later package upgrade to one of these files would then be
        // loaded, keyed, and thrown away — no error, no warning.
        //
        // One `vendor:publish --all` is enough to pin an app to stale schema
        // forever, and a docblock saying "don't publish these" does not stop
        // --all. Not registering the tag does.
    }
}
