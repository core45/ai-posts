<?php

declare(strict_types=1);

namespace Core45\AiPosts\Tests;

use Core45\AiPosts\Contracts\BlogTopics\SearchResearchProvider;
use Core45\AiPosts\Contracts\Seo\ProvidesKeywordMetrics;
use Core45\AiPosts\Contracts\Seo\ProvidesTopicTrends;
use Core45\AiPosts\Contracts\Seo\ReportsLinkEnrichment;
use Core45\AiPosts\Contracts\Seo\ResolvesUrlToModel;
use Core45\AiPosts\Contracts\Seo\ScansInternalLinks;
use Core45\AiPosts\Contracts\Seo\SearchesSerp;
use Core45\AiPosts\Contracts\Seo\SuggestsInternalLinks;
use Core45\AiPosts\DTOs\Seo\InternalLinkScanResult;
use Core45\AiPosts\Services\BlogTopics\GroundedSearchProvider;
use Core45\AiPosts\Support\Seo\LoggingLinkEnrichmentReporter;
use Core45\AiPosts\Support\Seo\NullInternalLinkScanner;
use Core45\AiPosts\Support\Seo\NullInternalLinkSuggester;
use Core45\AiPosts\Support\Seo\NullKeywordMetricsProvider;
use Core45\AiPosts\Support\Seo\NullSerpSearcher;
use Core45\AiPosts\Support\Seo\NullTopicTrendsProvider;
use Core45\AiPosts\Support\Seo\NullUrlToModelResolver;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Attributes\Test;

/**
 * What `composer require core45/ai-posts` gives you, before the host wires
 * anything.
 *
 * Modelled on `ai-core`'s `FreshInstallBindingsTest`: the Testbench app here
 * (`TestCase::getPackageProviders()`) binds nothing of its own beyond
 * `ai-core` and `ai-posts`, so resolving through the container is exactly
 * what a fresh install does — the question a new adopter asks first
 * ("I installed it and changed nothing — does it run?"), not the question
 * every other suite in this package asks (does a class behave correctly once
 * its collaborators are supplied).
 *
 * Scope: this proves the container is satisfiable on the ordinary blog
 * pipeline path, not that the package is useful with no configuration —
 * `ModelRegistry` still needs `config('ai.models')`, and every default bound
 * here reports "disabled"/"nothing found" rather than doing real SEO work.
 */
class FreshInstallBindingsTest extends TestCase
{
    /**
     * Every interface the blog pipeline resolves unconditionally — job
     * `handle()` method injection or an unguarded `app(X::class)`, with no
     * config flag or `bound()` check in front — must have a default.
     *
     * `ProvidesSearchAnalytics`, `ProvidesBingQueryStats` and
     * `TracksKeywordRankings` are deliberately absent from this list: their
     * only resolution sites are jobs dispatched conditionally on a
     * `research_config` flag the host must opt into
     * (`enable_gsc`/`enable_bing`/`enable_wincher`), so a fresh install never
     * resolves them at all.
     */
    #[Test]
    public function the_pipeline_contracts_resolve_with_nothing_bound_by_the_host(): void
    {
        $this->assertInstanceOf(NullTopicTrendsProvider::class, app(ProvidesTopicTrends::class));
        $this->assertInstanceOf(NullSerpSearcher::class, app(SearchesSerp::class));
        $this->assertInstanceOf(NullKeywordMetricsProvider::class, app(ProvidesKeywordMetrics::class));
        $this->assertInstanceOf(NullInternalLinkScanner::class, app(ScansInternalLinks::class));
        $this->assertInstanceOf(NullUrlToModelResolver::class, app(ResolvesUrlToModel::class));
        $this->assertInstanceOf(NullInternalLinkSuggester::class, app(SuggestsInternalLinks::class));
        $this->assertInstanceOf(LoggingLinkEnrichmentReporter::class, app(ReportsLinkEnrichment::class));
        $this->assertInstanceOf(GroundedSearchProvider::class, app(SearchResearchProvider::class));
    }

    /**
     * The capability-probe defaults must answer "no"/"disabled".
     *
     * A default that answered `true`/enabled would pass the test above and
     * still break the install: the pipeline would go on to call the real
     * data method expecting real data. Reporting disabled is what makes
     * these null objects correct rather than merely present.
     */
    #[Test]
    public function the_capability_probe_defaults_report_disabled(): void
    {
        $this->assertFalse(app(ProvidesTopicTrends::class)->isEnabled());
        $this->assertFalse(app(ProvidesKeywordMetrics::class)->isEnabled());
    }

    /**
     * The non-probe defaults answer "nothing found"/"unresolvable" rather
     * than fabricating data — the same "does nothing" direction as
     * `ai-core`'s `NullFocusKeyphraseStore`.
     */
    #[Test]
    public function the_remaining_defaults_report_nothing_found(): void
    {
        $this->assertNull(app(SearchesSerp::class)->search('anything'));
        $this->assertNull(app(ResolvesUrlToModel::class)->resolve('https://example.test/anything'));
        $this->assertSame([], app(SuggestsInternalLinks::class)->suggestLinks(new class extends Model {}));

        $scanResult = app(ScansInternalLinks::class)->scanContent('<a href="/x">x</a>');
        $this->assertSame(0, $scanResult->internalLinkCount);
        $this->assertSame([], $scanResult->internalLinks);
    }

    /**
     * A host binding must win over the default.
     *
     * `bindIf`, not `bind`. If these were `bind`, adding a default would take
     * the feature away from every application that already had one.
     */
    #[Test]
    public function a_host_binding_overrides_the_default(): void
    {
        $hostScanner = new class implements ScansInternalLinks
        {
            public function scanContent(string $htmlContent, ?string $baseUrl = null): InternalLinkScanResult
            {
                return new InternalLinkScanResult(internalLinkCount: 99);
            }
        };

        $this->app->bind(ScansInternalLinks::class, fn (): ScansInternalLinks => $hostScanner);

        $this->assertSame($hostScanner, app(ScansInternalLinks::class));
        $this->assertNotInstanceOf(NullInternalLinkScanner::class, app(ScansInternalLinks::class));
    }
}
