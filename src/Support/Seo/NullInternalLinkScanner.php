<?php

declare(strict_types=1);

namespace Core45\AiPosts\Support\Seo;

use Core45\AiCore\Contracts\UnconfiguredSeam;
use Core45\AiPosts\Contracts\Seo\ScansInternalLinks;
use Core45\AiPosts\DTOs\Seo\InternalLinkScanResult;

/**
 * The default when the host has not bound its own internal-link scanner.
 *
 * `ModuleExecutor::trackLinkedUrls()` calls `app(ScansInternalLinks::class)`
 * unconditionally after every module completes, and `EnrichPostLinksJob` —
 * chained unconditionally after every assembled post in
 * `PipelineOrchestratorService::afterAssembly()` — method-injects it too.
 * Neither site is behind a feature flag, so a fresh install threw
 * `BindingResolutionException` on the first module of the first pipeline
 * run, well before any SEO integration would have been consulted.
 *
 * Unlike the SEO integrations behind `isEnabled()`/`isConnected()`, link
 * scanning is not a third-party service — the package itself ships an HTML
 * parser for this (`InternalLinkScannerService`, if the host has bound one)
 * — but there is no host-agnostic way to know which one to use, so the
 * default reports "no links found" rather than guessing. Every caller
 * already treats an empty result as a legitimate (if unhelpful) answer:
 * `ModuleExecutor` just records an empty `linked_urls` list, and
 * `LinkVerificationService`/`InternalLinkEnricherService` skip their
 * link-removal loops when there is nothing to iterate.
 *
 * A host that wants real link counts/removal binds its own implementation;
 * `bindIf` leaves it alone.
 *
 * It is an `UnconfiguredSeam` because one caller does NOT merely skip work on
 * an empty result: `EnrichPostLinksJob` writes the count into
 * `seo_indexables.internal_links_outbound` and stamps `links_counted_at` with
 * `now()`. That stamp is an attestation — "as of this moment, we counted" —
 * and stamping it over a non-answer is worse than not writing at all, because
 * a host that later loses its bound scanner would see a real count silently
 * regress to 0 with a fresh timestamp vouching for it.
 */
final class NullInternalLinkScanner implements ScansInternalLinks, UnconfiguredSeam
{
    public function scanContent(string $htmlContent, ?string $baseUrl = null): InternalLinkScanResult
    {
        return new InternalLinkScanResult;
    }
}
