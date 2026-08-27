# core45/ai-posts

The blog AI stack: BlogTopics research and generation, the BlogPipeline
(modules, orchestrator, quality gates, scrapers), `PostContentRewriteService`,
`TranslatePostToLocale`, `GeneratePostPhotosJob`, FAQ writing, internal and
external link enrichment, and the image prompt director.

Part of the phaseb AI extraction. As-built reference: `docs/AI_PACKAGES.md`.

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
and the 25 migrations that create and evolve their tables all ship here. The
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

All of these are pinned by `tests/Feature/Packages/HostObligationsTest.php`, so
a host that stops satisfying one fails a test rather than a request. Add to both
this list and that test in the same commit as the code that needs the member.

**`post`**

| Kind | Member |
| --- | --- |
| relations | `productCategory()`, `tags()`, `blogTopicCandidate()` |
| methods | `translate()`, `translateOrNew()`, `getUrl()`, `getOrCreateSeoIndexable()` |
| columns | `id`, `post_type_id`, `post_category_id`, `author` |

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
