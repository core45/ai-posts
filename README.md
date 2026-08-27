# core45/ai-posts

The blog AI stack: BlogTopics research and generation, the BlogPipeline
(modules, orchestrator, quality gates, scrapers), `PostContentRewriteService`,
`TranslatePostToLocale`, `GeneratePostPhotosJob`, FAQ writing, internal and
external link enrichment, and the image prompt director.

Developed in the `core45/ai-packages` monorepo; this repository is a read-only
split of it. The monorepo README describes how the packages relate to each
other.

## ⚠️ Not reusable outside phaseb yet

This package imports **72 distinct host classes** — `App\Models\Post`,
`PostTranslation`, `BlogPostPipelineRun`, `BlogTopicCandidate`,
`BlogTopicProfile`, `ContentModule`, `Product`, `ProductCategory`, `Tag`, plus
host enums, DTOs and services. It installs and autoloads in another Laravel app
and then fatals the moment it touches one of them.

It is organised as a package — own namespace, own manifest, own test suite,
enforced dependency direction — but it is **not portable**. Closing that gap
means either inverting every model access behind interfaces or moving the blog
models into a package too. Until then the coupling is pinned by a ceiling in
`tests/PackageWiringTest.php` so it cannot grow unnoticed; lower it as coupling
is removed, never raise it without a documented reason.

`core45/ai-core` and `core45/ai-chat` do not share this limitation.

## Requirements

PHP 8.4+, Laravel 13, `core45/ai-core: ^0.1`. Declared dependencies:
`filament/filament` (admin notifications), `spatie/laravel-medialibrary`
(generated blog images), `ramsey/uuid`.

## What this package provides

- `Services\BlogTopics\*` — research, topic synthesis, blog post/FAQ writing,
  search providers (SerpApi, grounded, composite, mock), link management,
  citation guard, site profiling, product series.
- `Services\BlogPipeline\*` — module executor and resolver, orchestrator,
  quality gate, prompt template resolver, payload guard, competitor scraper.
- `Jobs\BlogTopics\*` and `Jobs\BlogPipeline\*` — the queued flows.
- `Jobs\TranslatePostToLocale`, `Jobs\GeneratePostPhotosJob`.
- `Services\PostContentRewriteService` — the ai-core rewrite base, specialised
  for posts.

Ships services and jobs only (D7): the host keeps all Filament and Livewire UI,
resolves these classes from the container, and schedules the jobs.

**This package owns the blog domain schema.** Its 13 models, their factories
and the 26 migrations that create and evolve their tables all ship here. The
`ai_*` tables ship with `core45/ai-core`. The host keeps the Filament UI and
schedules the jobs.

The migrations are deliberately **not publishable**. Publishing would copy
byte-identical basenames into the host's `database/migrations`, and Laravel
keys migrations by basename with the host path taking precedence — so the
published copy would win and every later package upgrade to those files would
be silently discarded.

## How a host application integrates this

The consuming application must supply the host services this package calls
(`SlugGenerator`, `InternalLinkScannerService`, the SerpApi and Search Console
services, and so on). The blog models, enums and DTOs are no longer among them
— they ship here.

### Contracts the host must bind

`AiPostsServiceProvider` binds nothing — no `bind`/`bindIf`/`singleton`/
`scoped` call exists in it at all, only the two `mergeConfigFrom()` calls
below. Every contract this package defines, and every `ai-core` contract its
own services resolve from the container, is left for the host to bind.
Resolving any of these unbound throws a `BindingResolutionException` naming
the interface at the moment that code path runs.

**Defined here (`Core45\AiPosts\Contracts\`):**

| Contract | For |
|---|---|
| `Seo\SearchesSerp` | SERP search execution |
| `Seo\ProvidesTopicTrends` | trending topics + autocomplete suggestions |
| `Seo\ProvidesKeywordMetrics` | keyword difficulty scoring |
| `Seo\TracksKeywordRankings` | keyword rank tracking (e.g. Wincher) |
| `Seo\ProvidesSearchAnalytics` | search analytics (e.g. Google Search Console) |
| `Seo\ProvidesBingQueryStats` | Bing Webmaster query stats |
| `Seo\SuggestsInternalLinks` | internal-link suggestions for an indexable model |
| `Seo\ScansInternalLinks` | extracting internal/external links from HTML |
| `Seo\ResolvesUrlToModel` | resolving a URL back to a host model reference |
| `Seo\ReportsLinkEnrichment` | delivering a link-enrichment report to a recipient |
| `BlogTopics\SearchResearchProvider` | the topic-research web-search backend; four implementations (SerpApi, grounded, composite, mock) ship in `Services\BlogTopics`, but the host still binds the interface to the one it wants |

**Defined in `core45/ai-core` but only ever resolved from this package's own
services**, so installing `ai-posts` makes binding them mandatory even though
`ai-core` itself never requires them:

| Contract | Resolved by |
|---|---|
| `Core45\AiCore\Contracts\ProvidesMandatoryWords` | `Services\BlogTopics\BlogPostWriterService`, `Services\BlogTopics\BlogFaqWriterService`, `Services\BlogPipeline\Modules\DynamicContentModule` |
| `Core45\AiCore\Contracts\ObservesAiContracts` | `Services\BlogPipeline\Modules\DynamicContentModule` |
| `Core45\AiCore\Contracts\GeneratesSlugs` | `Jobs\BlogPipeline\AssemblePostJob`, `Jobs\BlogTopics\GenerateBlogPostJob`, `Jobs\BlogTopics\PlanProductSeriesJob`, `Services\BlogTopics\TopicSynthesisService`, `Services\BlogTopics\ManualTopicService` |
| `Core45\AiCore\Contracts\GeneratesContentMedia` | `Services\PostContentRewriteService` — **only** when media options were set; `PostContentRewriteService.php:482` is the sole resolution point and the plain rewrite path never reaches it. A host that does not generate media does not need this binding (proved by `tests/HostAppRewritesAPostEndToEndTest.php`, which never binds it). |
| `Core45\AiCore\Contracts\ChecksContentSimilarity` | `Services\BlogPipeline\QualityGateService` (constructor-injected, so `ModuleExecutor` — and anything that resolves it from the container — requires the binding too) |

### Binding snippet

A drop-in `AppServiceProvider::register()` for a fresh host. Every left-hand
side above is unbound by both `AiPostsServiceProvider` and
`AiCoreServiceProvider`; this is the complete list, nothing more.

Two of them — `ChecksContentSimilarity` and `SearchResearchProvider` — already
have a working implementation shipped in these packages, so the snippet binds
straight to it. The rest (SERP search, keyword/rank-tracking/analytics
providers, internal-link scanning/suggestion, URL resolution, link-enrichment
delivery, mandatory-word lists, contract observability) have no shipped
implementation — they wrap a specific host integration (SerpApi, Semrush,
Wincher, Google Search Console, Bing Webmaster, …) or a host policy, so the
class names below (`App\Services\Seo\...`, `App\AI\...`) are illustrative:
create them in the host app, implementing the interface's real methods as
listed in `src/Contracts/`.

```php
<?php

declare(strict_types=1);

namespace App\Providers;

use App\AI\MandatoryWordList;                    // implements ProvidesMandatoryWords
use App\Filament\Support\ContractObservationLog; // implements ObservesAiContracts
use App\Services\Seo\BingWebmasterService;       // implements ProvidesBingQueryStats
use App\Services\Seo\InternalLinkScannerService; // implements ScansInternalLinks
use App\Services\Seo\InternalLinkSuggestionService; // implements SuggestsInternalLinks
use App\Services\Seo\SearchConsoleService;       // implements ProvidesSearchAnalytics
use App\Services\Seo\SemrushService;             // implements ProvidesKeywordMetrics
use App\Services\Seo\SerpApiService;             // implements SearchesSerp
use App\Services\Seo\SiteUrlResolverService;     // implements ResolvesUrlToModel
use App\Services\Seo\WincherService;             // implements TracksKeywordRankings
use App\Support\FilamentLinkReportNotifier;      // implements ReportsLinkEnrichment
use Core45\AiCore\Contracts\ChecksContentSimilarity;
use Core45\AiCore\Contracts\GeneratesContentMedia;
use Core45\AiCore\Contracts\GeneratesSlugs;
use Core45\AiCore\Contracts\ObservesAiContracts;
use Core45\AiCore\Contracts\ProvidesMandatoryWords;
use Core45\AiCore\Services\ContentSimilarityService;
use Core45\AiPosts\Contracts\BlogTopics\SearchResearchProvider;
use Core45\AiPosts\Contracts\Seo\ProvidesBingQueryStats;
use Core45\AiPosts\Contracts\Seo\ProvidesKeywordMetrics;
use Core45\AiPosts\Contracts\Seo\ProvidesSearchAnalytics;
use Core45\AiPosts\Contracts\Seo\ProvidesTopicTrends;
use Core45\AiPosts\Contracts\Seo\ReportsLinkEnrichment;
use Core45\AiPosts\Contracts\Seo\ResolvesUrlToModel;
use Core45\AiPosts\Contracts\Seo\ScansInternalLinks;
use Core45\AiPosts\Contracts\Seo\SearchesSerp;
use Core45\AiPosts\Contracts\Seo\SuggestsInternalLinks;
use Core45\AiPosts\Contracts\Seo\TracksKeywordRankings;
use Core45\AiPosts\Services\BlogTopics\GroundedSearchProvider;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // ai-core contract shipped with a working implementation — bind straight to it.
        $this->app->bind(ChecksContentSimilarity::class, ContentSimilarityService::class);

        // Host-authored: the app's own SlugGenerator (or Str::slug() wrapper).
        $this->app->bind(GeneratesSlugs::class, fn () => new class implements GeneratesSlugs {
            public function make(string $title): string
            {
                return \Illuminate\Support\Str::slug($title);
            }
        });

        // Host-authored: attaches generated media (e.g. via spatie/medialibrary) to a rewritten record.
        $this->app->bind(GeneratesContentMedia::class, \App\AI\MediaGenerationService::class);

        // Host-authored: store-wide mandatory-word list fed into the AI prompt profile.
        $this->app->bind(ProvidesMandatoryWords::class, MandatoryWordList::class);

        // Host-authored: exit-window observability only — must never throw into a render path.
        $this->app->bind(ObservesAiContracts::class, ContractObservationLog::class);

        // ai-posts's own contract, shipped with four implementations
        // (GroundedSearchProvider, SerpApiSearchProvider, CompositeSearchProvider,
        // MockSearchProvider) in Services\BlogTopics — pick the one you want, or
        // compose your own via CompositeSearchProvider.
        $this->app->bind(SearchResearchProvider::class, GroundedSearchProvider::class);

        // Host-authored SEO integrations — one class per third-party API.
        $this->app->bind(SearchesSerp::class, SerpApiService::class);
        $this->app->bind(ProvidesTopicTrends::class, SerpApiService::class); // same provider, different method set
        $this->app->bind(ProvidesKeywordMetrics::class, SemrushService::class);
        $this->app->bind(TracksKeywordRankings::class, WincherService::class);
        $this->app->bind(ProvidesSearchAnalytics::class, SearchConsoleService::class);
        $this->app->bind(ProvidesBingQueryStats::class, BingWebmasterService::class);
        $this->app->bind(SuggestsInternalLinks::class, InternalLinkSuggestionService::class);
        $this->app->bind(ScansInternalLinks::class, InternalLinkScannerService::class);
        $this->app->bind(ResolvesUrlToModel::class, SiteUrlResolverService::class);
        $this->app->bind(ReportsLinkEnrichment::class, FilamentLinkReportNotifier::class);
    }
}
```

### Configuration

`config('ai.*')` keys this package's own code reads (all consumer-owned — none
of them ship a value in `ai-core`'s `config/ai.php` beyond `assistant.enabled`,
so a fresh host's own `config/ai.php` needs at least this much):

```php
<?php

declare(strict_types=1);

return [

    // Registers the host models `Core45\AiCore\ModelRegistry` resolves for
    // this package — see "Registered models" above for the full required key list.
    'models' => [
        'post' => \App\Models\Post::class,
        'post_translation' => \App\Models\PostTranslation::class,
        'user' => \App\Models\User::class,
        'product' => \App\Models\Product::class,
        'category' => \App\Models\ProductCategory::class,
        'product_translation' => \App\Models\ProductTranslation::class,
        'product_category_translation' => \App\Models\ProductCategoryTranslation::class,
        'tag' => \App\Models\Tag::class,
        'faq' => \App\Models\Faq::class,
        'seo_indexable' => \App\Models\SeoIndexable::class,
        'image_style' => \App\Models\ImageStyle::class,
        'image_director_profile' => \App\Models\ImageDirectorProfile::class,
        'content_archetype' => \App\Models\ContentArchetype::class,
        'translation_lock' => \Core45\AiCore\Models\TranslationLock::class,
    ],

    'assistant' => [
        // Master switch: with this off, ai-core-filament's four resources still
        // route but 403 on canAccess().
        'enabled' => true,

        // Default per-call timeout (seconds) for AI calls this package makes
        // when no more specific timeout below applies.
        'timeout' => 300,

        'timeouts' => [
            // BlogPipeline module generation (ModuleExecutor, FeedbackAnalysisJob, ImproveModuleJob).
            'module' => 480,
            // QualityGateService's LLM judge pass.
            'judge' => 45,
            // AiLinkSuggestionService's internal-link suggestion call.
            'linking' => 120,
        ],

        'content' => [
            // Read by BlogPostWriterService when composing the writer prompt.
            'brand_voice' => 'professional, direct, no filler',
            // PostContentRewriteService: how much surrounding SEO context to send.
            'seo_context_limit' => 5000,
        ],

        'blog_topics' => [
            'images' => [
                // Gates GenerateBlogImagesJob / BlogImageGeneratorService entirely.
                'enabled' => true,
            ],
            'citation_guard' => [
                // CitationGuardService: reject ungrounded claims instead of just flagging them.
                'strict' => false,
            ],
            // ExternalLinkSafetyService: never link out to these domains.
            'competitor_domains' => ['competitor-one.com', 'competitor-two.com'],
            'research' => [
                // ResearchService::generateQueries()
                'max_queries_per_batch' => 8,
                // TopicSynthesisService
                'max_topics_per_batch' => 10,
            ],
        ],
    ],

];
```

`config('ai-posts.*')` — this package's own config, already merged with the
defaults below by `AiPostsServiceProvider`; publish and override only the keys
that differ from your host's taxonomy:

```php
<?php

declare(strict_types=1);

return [
    'taxonomy' => [
        'blog_post_type' => 2,
        'default_post_category' => 1,
        'faq_display_location' => 'none',
    ],

    'jobs' => [
        // Optional host job class names — null disables the hand-off, each
        // call site logs when it does. Left unset here on purpose: only wire
        // the ones this host actually has.
        'rate_limit_middleware' => null,
        'humanize' => null,   // constructed with (Model $post, string $locale)
        'analyze_seo' => null, // dispatched with (Model $seoIndexable)
    ],

    'filament' => [
        'post_resource' => \App\Filament\Resources\Posts\PostResource::class,
        'navigation_group' => 'Blog',
    ],
];
```

### Invoking the package

Resolving and calling the two entry-point services, and dispatching the two
standalone jobs — the same four classes `tests/PackageWiringTest.php` pins as
the package's shipped entry points (plus `ResearchService`, covered by
`PipelineOrchestratorService::start()` under the hood).

```php
<?php

declare(strict_types=1);

use Core45\AiPosts\Jobs\GeneratePostPhotosJob;
use Core45\AiPosts\Jobs\TranslatePostToLocale;
use Core45\AiPosts\Models\BlogTopicCandidate;
use Core45\AiPosts\Models\BlogTopicProfile;
use Core45\AiPosts\Services\BlogPipeline\PipelineOrchestratorService;
use Core45\AiPosts\Services\PostContentRewriteService;

// 1. Rewrite/generate a post's AI content and SEO fields in place.
//    $post must implement Astrotomic\Translatable\Contracts\Translatable.
$result = app(PostContentRewriteService::class)->processPost($post);
// $result: array{model_id: int, processed_fields: array<string>, errors: array<string>}

// Same service, with AI-generated media attached afterwards (GeneratesContentMedia).
// 'enabled' is the switch processMediaGeneration() checks; 'rich_text_field' and
// 'gallery_collection' are set internally and cannot be overridden from here.
app(PostContentRewriteService::class)
    ->withMediaGeneration(['enabled' => true])
    ->processPost($post);

// Translate one post's fields from one locale to another.
app(PostContentRewriteService::class)->translateToLocale($post, 'en', 'es');

// 2. Start a BlogPipeline run for an approved topic candidate: builds the
//    config snapshot, creates the BlogPostPipelineRun, and dispatches the
//    research batch (SearchConsoleInsightsJob, KeywordResearchJob, ...).
$candidate = BlogTopicCandidate::find($candidateId);
$profile = BlogTopicProfile::find($profileId);
$run = app(PipelineOrchestratorService::class)->start($candidate, $profile);

// 3. Dispatch translation and photo-generation as standalone queued jobs.
TranslatePostToLocale::dispatch($post, 'en', 'de', auth()->id());

GeneratePostPhotosJob::dispatch(
    post: $post,
    generateFeatured: true,
    generateGallery: true,
    generateInText: false,
    replaceExisting: false,
);
```

### Registered models

Every host model the package touches is resolved through
`Core45\AiCore\ModelRegistry`, populated from `config('ai.models')`. The
package names no host class. Keys it reads: `post`, `post_translation`, `user`,
`product`, `category`, `product_translation`,
`product_category_translation`, `tag`, `faq`, `seo_indexable`, `image_style`,
`image_director_profile`, `content_archetype`, `translation_lock`.

A key the package reads but the host has not configured throws at the seam,
naming the missing key — it does not fall back to a guessed class.

### Obligations the interfaces do not capture

Written down rather than left implicit, because each one is a runtime fatal for
the first host that does not meet it:

These are duck-typed on purpose: the package takes `Model`, never your concrete
class, so nothing in the type system checks them. That means each one is a
runtime fatal — a PHP `Error`, which the surrounding `catch (\Exception …)`
blocks do not catch — for the first host that does not meet it.

**A test that pins all of them ships with `ai-core`.** Copy it into your suite:

```bash
cp vendor/core45/ai-core/stubs/HostObligationsTest.php.stub \
   tests/Feature/HostObligationsTest.php
```

It is a data-driven Pest file with five cases — relations, instance methods,
static calls and scopes, columns, and derived attributes — and every entry is
keyed by a `config('ai.models')` REGISTRY KEY rather than a class name, so it
resolves whatever classes *you* registered. That is why it can ship at all, and
why it has to be copied rather than run from inside the package: only your
application knows what is in the registry.

One thing it gets right that a naive version does not: `method_exists()` alone
is not a sufficient check. It returns false for an Eloquent scope (`active()`
is `scopeActive`) and for a plain column read as a property, so a
`method_exists`-only gate reports healthy members as missing. Each kind is
checked the way it actually resolves.

Add to both this list and your copy of that test in the same commit as the code
that needs the member.

**`post`**

| Kind | Member |
| --- | --- |
| relations | `productCategory()`, `tags()`, `blogTopicCandidate()` |
| methods | `translate()`, `translateOrNew()`, `getUrl()`, `getOrCreateSeoIndexable()` |
| columns | `id`, `author`, and `post_type_id`/`post_category_id` **cast to backed enums exposing `label()`** — see below |

`translate()`/`translateOrNew()` come from Astrotomic's `Translatable`;
`getOrCreateSeoIndexable()` from `Core45\AiCore\Contracts\ProvidesSeoIndexable`.
The rest are guaranteed by nothing but the test.

**`category`** — `translate()`, `getUrl()`.

**`image_director_profile`** — a static `defaultForModel()`.
**`image_style`** — a static `resolveBySlug()` and an `active()` query scope.

**Test-time only:** any registered model used as a foreign key by a package
factory must use `HasFactory`. `HasFactory` is a trait, so no interface can
require it; `Core45\AiCore\Support\HostFactory` throws naming the key and the
class when it is missing.

Why a test and not just this list: every one of these calls sits behind a
`@phpstan-ignore` at its call site, because the package holds the model as
`Model` and cannot know more. A suppression silences the only warning there
was, so without the test these would be invisible assumptions — which is
precisely how a missing `productCategory` relation once killed the admin
"AI Rewrite Content" action while every other gate stayed green.

### Obligations the tables above do not state

Four requirements are invisible in the contract and model tables, and each one
below is an error `tests/HostAppRewritesAPostEndToEndTest.php` actually
produced while a bare Testbench app was wired up from nothing.

**`post_type_id` and `post_category_id` must be enum objects, not ints.**
`buildModelContext()` calls `$postTypeId?->label()`
(`PostContentRewriteService.php:296`), so a plain integer gives
`Error: Call to a member function label() on int`. Null is fine — the code
falls back to `'Post'`. Worse, `buildModelContext()` runs OUTSIDE
`processModel()`'s per-field `try` blocks, so this kills the whole rewrite
rather than degrading one field into `$results['errors']`.

**`config('translatable.locales')` must be set.** `getLocales()` reads
`config('translatable.locales', ['en'])`, but that default is unreachable:
Astrotomic's own `Locales` throws `LocalesNotDefinedException` first.

**The `posts` table must have `timestamps()`.** `saveTranslation()` calls
`$model->touch()` for every class in `getTouchableModelClasses()`, so without
`updated_at` every field fails with `no such column: updated_at`.

**Faking the AI in your own tests means binding the CONCRETE
`Core45\AiCore\Services\RemoteAiClient`, not the `AiClient` contract.**
`AiClientFactory::for()` does `app()->make(RemoteAiClient::class)`
(`AiClientFactory.php:22`). Binding the documented interface is inert on this
path and lets real HTTP requests leave the process — which is how this was
found, as five live calls returning
`AI service rejected the request (status: 401)`.

### Foreign-key assumptions

The migrations create foreign keys against the literal table names `posts` and
`users`. Two consequences for a consumer whose schema differs:

- registering `ai.models.post` against a model on a differently-named table
  leaves those keys pointing at a table the app does not use;
- the package migrations are timestamped from `2026_03_04`, and Laravel orders
  by basename across all paths, so an app whose `posts` or `users` table is
  created later than that will fail on a foreign key to a table that does not
  exist yet.

Neither is a problem for an app whose `posts`/`users` predate those timestamps,
which is the common case.

The inversion anomaly is resolved: `SearchResearchProvider` and its DTOs now
live here as `Core45\AiPosts\Contracts\BlogTopics\SearchResearchProvider`,
alongside the four implementations. The host binds the interface to a concrete
provider in its own service provider, which is the intended direction.

## Testing

```bash
vendor/bin/phpunit -c packages/ai-posts/phpunit.xml.dist
```

Orchestra Testbench, ai-core + ai-posts only. `tests/PackageWiringTest.php`
asserts the provider boots, the entry-point classes exist, and the host-import
count stays at or under its ceiling.
