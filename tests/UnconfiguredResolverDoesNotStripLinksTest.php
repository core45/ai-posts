<?php

declare(strict_types=1);

namespace Core45\AiPosts\Tests;

use Core45\AiCore\Contracts\UnconfiguredSeam;
use Core45\AiPosts\Contracts\Seo\ResolvesUrlToModel;
use Core45\AiPosts\Contracts\Seo\ScansInternalLinks;
use Core45\AiPosts\DTOs\Seo\InternalLinkScanResult;
use Core45\AiPosts\Services\BlogPipeline\LinkVerificationService;
use Core45\AiPosts\Services\BlogTopics\InternalLinkEnricherService;
use Core45\AiPosts\Support\Seo\NullInternalLinkScanner;
use Core45\AiPosts\Support\Seo\NullUrlToModelResolver;
use PHPUnit\Framework\Attributes\Test;

/**
 * The mixed configuration, which is where the danger actually lives.
 *
 * A fully unwired install never reaches this: the null link *scanner* finds no
 * links, so nothing is judged. That safety is luck, not design. The dangerous
 * state is one seam wired and the other not — and it is the likely one, because
 * `bindIf` defaults each contract independently and `ScansInternalLinks` is
 * pure HTML parsing while `ResolvesUrlToModel` needs host routing. The easy one
 * gets written first.
 *
 * In that state a resolver that can resolve nothing returns null for every URL,
 * both removal paths read null as "broken", and `EnrichPostLinksJob` writes the
 * stripped body back over a published post
 * (`Jobs/BlogTopics/EnrichPostLinksJob.php:182-183`). Before the defaults were
 * added, that host got a loud `BindingResolutionException` instead — so a fix
 * for one problem would have created a worse one, silently.
 *
 * These tests pin the guard. They must fail if anyone removes the
 * `UnconfiguredSeam` check.
 */
class UnconfiguredResolverDoesNotStripLinksTest extends TestCase
{
    private const HTML = '<p>See <a href="https://example.test/blog/one">one</a> '
        .'and <a href="https://example.test/blog/two">two</a>.</p>';

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.url', 'https://example.test');
    }

    #[Test]
    public function the_enricher_leaves_every_link_alone_when_the_resolver_is_unconfigured(): void
    {
        $service = new InternalLinkEnricherService(
            $this->scannerFindingBothLinks(),
            new NullUrlToModelResolver,
        );

        $result = $service->validateLinks(self::HTML, 'en');

        $this->assertStringContainsString('href="https://example.test/blog/one"', $result['html']);
        $this->assertStringContainsString('href="https://example.test/blog/two"', $result['html']);

        // And it says so, rather than passing in silence — an operator who
        // expected verification should find out it did not happen.
        $this->assertNotEmpty(array_filter(
            $result['warnings'],
            static fn (string $w): bool => str_contains($w, 'left unchecked'),
        ), 'The skip must be reported in the warnings.');

        $this->assertEmpty(array_filter(
            $result['warnings'],
            static fn (string $w): bool => str_contains($w, 'Broken internal link removed'),
        ), 'Nothing may be reported as broken when nothing could judge it.');
    }

    #[Test]
    public function a_real_resolver_still_removes_the_links_it_cannot_resolve(): void
    {
        // The guard must not disable verification wholesale — a host that HAS
        // bound a resolver keeps the behaviour it configured, including removal.
        $strictResolver = new class implements ResolvesUrlToModel
        {
            public function resolve(string $url): ?array
            {
                return str_ends_with($url, '/one')
                    ? ['type' => 'post', 'id' => 1, 'locale' => 'en']
                    : null;
            }
        };

        $service = new InternalLinkEnricherService($this->scannerFindingBothLinks(), $strictResolver);

        $result = $service->validateLinks(self::HTML, 'en');

        $this->assertStringContainsString('href="https://example.test/blog/one"', $result['html']);
        $this->assertStringNotContainsString('href="https://example.test/blog/two"', $result['html']);
        $this->assertNotEmpty(array_filter(
            $result['warnings'],
            static fn (string $w): bool => str_contains($w, 'Broken internal link removed'),
        ));
    }

    #[Test]
    public function the_pipeline_verifier_applies_the_same_guard(): void
    {
        $service = new LinkVerificationService(
            $this->scannerFindingBothLinks(),
            new NullUrlToModelResolver,
        );

        // Through the public entry point, not the private removal method — the
        // guard has to hold for the path the pipeline actually calls.
        $result = $service->verify(self::HTML, 'en');

        $this->assertStringContainsString('href="https://example.test/blog/one"', $result['html']);
        $this->assertStringContainsString('href="https://example.test/blog/two"', $result['html']);
    }

    /**
     * The scanner's own default carries the marker too, for a different reason
     * from the resolver's.
     *
     * `NullUrlToModelResolver` is marked because a caller DESTROYS on its
     * answer. `NullInternalLinkScanner` is marked because a caller ATTESTS to
     * its answer: `EnrichPostLinksJob` writes `internal_links_outbound` and
     * stamps `links_counted_at` with `now()`, which claims a count was taken.
     * Stamping that over "nobody asked me to count" would silently regress a
     * real, previously-measured count to 0 with a fresh timestamp vouching for
     * it.
     *
     * Both are the same rule: an unconfigured seam's emptiness is an absence of
     * information, and only code that merely SKIPS work may treat it as an
     * answer.
     */
    #[Test]
    public function the_defaults_whose_emptiness_is_acted_on_carry_the_marker(): void
    {
        $this->assertInstanceOf(UnconfiguredSeam::class, new NullUrlToModelResolver);
        $this->assertInstanceOf(UnconfiguredSeam::class, new NullInternalLinkScanner);
    }

    /**
     * A skip must be reported, not silent. An operator who installed the
     * packages expecting link verification has to be able to find out it did
     * not happen — otherwise "no broken links found" and "nothing was checked"
     * look identical in the output.
     */
    #[Test]
    public function the_skip_is_reported_rather_than_passing_quietly(): void
    {
        $result = (new LinkVerificationService(
            $this->scannerFindingBothLinks(),
            new NullUrlToModelResolver,
        ))->verify(self::HTML, 'en');

        $warnings = implode("\n", $result['warnings']);

        $this->assertStringContainsString('left unchecked', $warnings);
        $this->assertStringContainsString(ResolvesUrlToModel::class, $warnings);
        $this->assertStringNotContainsString('Broken internal link removed', $warnings);
    }

    /**
     * A scanner that behaves like a real one — this is the wired half of the
     * mixed configuration.
     */
    private function scannerFindingBothLinks(): ScansInternalLinks
    {
        return new class implements ScansInternalLinks
        {
            public function scanContent(string $htmlContent, ?string $baseUrl = null): InternalLinkScanResult
            {
                return new InternalLinkScanResult(
                    internalLinks: [
                        ['url' => 'https://example.test/blog/one', 'anchor' => 'one'],
                        ['url' => 'https://example.test/blog/two', 'anchor' => 'two'],
                    ],
                    externalLinks: [],
                    internalLinkCount: 2,
                );
            }
        };
    }
}
