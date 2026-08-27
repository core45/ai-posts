<?php

declare(strict_types=1);

namespace Core45\AiPosts;

use Core45\AiCore\Support\PromptKeyRegistry;
use Core45\AiPosts\Contracts\BlogTopics\SearchResearchProvider;
use Core45\AiPosts\Contracts\Seo\ProvidesKeywordMetrics;
use Core45\AiPosts\Contracts\Seo\ProvidesTopicTrends;
use Core45\AiPosts\Contracts\Seo\ReportsLinkEnrichment;
use Core45\AiPosts\Contracts\Seo\ResolvesUrlToModel;
use Core45\AiPosts\Contracts\Seo\ScansInternalLinks;
use Core45\AiPosts\Contracts\Seo\SearchesSerp;
use Core45\AiPosts\Contracts\Seo\SuggestsInternalLinks;
use Core45\AiPosts\Services\BlogTopics\GroundedSearchProvider;
use Core45\AiPosts\Support\Seo\LoggingLinkEnrichmentReporter;
use Core45\AiPosts\Support\Seo\NullInternalLinkScanner;
use Core45\AiPosts\Support\Seo\NullInternalLinkSuggester;
use Core45\AiPosts\Support\Seo\NullKeywordMetricsProvider;
use Core45\AiPosts\Support\Seo\NullSerpSearcher;
use Core45\AiPosts\Support\Seo\NullTopicTrendsProvider;
use Core45\AiPosts\Support\Seo\NullUrlToModelResolver;
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

        $this->bindFreshInstallDefaults();
    }

    /**
     * Interfaces the blog pipeline resolves on its ordinary path — job
     * `handle()` method injection, or an unguarded `app(X::class)` — with no
     * config flag or `bound()`/`try`/`catch` standing in front of the
     * resolution itself. Found the same way `ai-core`'s equivalent bindings
     * were: by asking what `composer require core45/ai-posts` does in an
     * application that has bound nothing of its own.
     *
     * Laravel resolves every `handle()` parameter before the method body
     * runs, so a job that checks `config('...enabled')` on its first line
     * still throws `BindingResolutionException` first if one of its
     * parameters has no binding — the internal feature check never gets the
     * chance to skip the work. That is what made these findable only by
     * reading call sites, not by grepping for `app(...)`.
     *
     * Contracts left out of this list stay unbound deliberately: their only
     * resolution sites are jobs `PipelineOrchestratorService::
     * dispatchResearchBatch()` dispatches conditionally on a
     * `research_config` flag (`ProvidesSearchAnalytics`,
     * `ProvidesBingQueryStats` behind `enable_gsc`/`enable_bing`;
     * `TracksKeywordRankings` behind `enable_wincher`). A host that never
     * turns those flags on never resolves the interface, so binding a
     * default for them would tidy a list, not fix a fatal.
     */
    private function bindFreshInstallDefaults(): void
    {
        // SerpApi-class trends/SERP data are paid third-party services no new
        // application has credentials for on day one. `ProvidesTopicTrends` and
        // `SearchesSerp` are resolved unconditionally by the BlogTopics research
        // jobs (`GenerateSearchQueriesJob`/`SynthesizeTopicsJob` via
        // `SerpApiTopicEnrichmentService`, and `KeywordResearchJob`/
        // `UserIntentResearchJob` directly) regardless of whether the host has
        // opted into SERP analysis — see `NullTopicTrendsProvider`/
        // `NullSerpSearcher` docblocks for the exact call chain.
        $this->app->bindIf(ProvidesTopicTrends::class, NullTopicTrendsProvider::class);
        $this->app->bindIf(SearchesSerp::class, NullSerpSearcher::class);

        // Same shape as `SearchesSerp` above: `ProvidesKeywordMetrics` (Semrush)
        // is method-injected into `KeywordResearchJob::handle()`, which every
        // pipeline run dispatches unconditionally.
        $this->app->bindIf(ProvidesKeywordMetrics::class, NullKeywordMetricsProvider::class);

        // `ScansInternalLinks`, `ResolvesUrlToModel`, `SuggestsInternalLinks` and
        // `ReportsLinkEnrichment` are all resolved on the link-enrichment path
        // that runs after every assembled post
        // (`PipelineOrchestratorService::afterAssembly()` chains
        // `EnrichPostLinksJob` unconditionally) and after every pipeline module
        // (`ModuleExecutor::trackLinkedUrls()`). None of the four has a config
        // flag in front of it — see each Null* class's own docblock for its
        // exact call sites and why the empty/null answer is the safe one.
        $this->app->bindIf(ScansInternalLinks::class, NullInternalLinkScanner::class);
        $this->app->bindIf(ResolvesUrlToModel::class, NullUrlToModelResolver::class);
        $this->app->bindIf(SuggestsInternalLinks::class, NullInternalLinkSuggester::class);
        $this->app->bindIf(ReportsLinkEnrichment::class, LoggingLinkEnrichmentReporter::class);

        // `SearchResearchProvider` is different from the rest of this method:
        // it is not a null object but a genuinely working default.
        // `GroundedSearchProvider` routes through `AiClient::groundedSearch()`
        // — phasea's own grounded-search endpoint — so it needs no SERP/search
        // API key of its own; it only needs `AiClient`, which `ai-core` already
        // binds unconditionally. `ResearchService` (constructor-injected with
        // this interface) is method-injected into `ExecuteResearchJob::handle()`
        // and `GenerateSearchQueriesJob::handle()`, both on the ordinary
        // BlogTopics research path, so an unbound interface here failed before
        // any research ever ran.
        $this->app->bindIf(SearchResearchProvider::class, GroundedSearchProvider::class);
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
        $registry = $this->app->make(PromptKeyRegistry::class);

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
    }

    public function boot(): void
    {
        $this->registerPromptKeys();

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
