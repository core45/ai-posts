<?php

declare(strict_types=1);

namespace Core45\AiPosts\Support\Seo;

use Core45\AiCore\Contracts\UnconfiguredSeam;
use Core45\AiPosts\Contracts\Seo\ResolvesUrlToModel;

/**
 * The default when the host has not bound its own URL-to-model resolver.
 *
 * `InternalLinkEnricherService`'s constructor takes `ResolvesUrlToModel`, and
 * that service is itself method-injected into `EnrichPostLinksJob::handle()`
 * — chained unconditionally after every assembled post — so a fresh install
 * threw `BindingResolutionException` on the first enrichment run, before any
 * link was even checked. `LinkVerificationService` and
 * `VerifyPostLinksJob::handle()` resolve it the same unconditional way.
 *
 * **Why this implements `UnconfiguredSeam`, and why an earlier draft of this
 * docblock was wrong.** It argued that always answering "unresolvable" is the
 * safe direction, since a link nobody can vouch for should not be published.
 * That holds for content being *assembled*: `AssemblePostJob` builds an
 * inactive draft, and dropping an unverifiable link from it costs nothing.
 *
 * It does not hold for `EnrichPostLinksJob`, which runs over **existing,
 * published** posts and ends in
 * `$translation->main_text = $html; $translation->save();`
 * (`Jobs/BlogTopics/EnrichPostLinksJob.php:182-183`). There, a resolver that
 * cannot resolve anything makes every internal link look broken, so the job
 * strips editorial links the pipeline never authored — logging each as "Broken
 * internal link removed" when nothing was broken. The operator is told their
 * content was bad.
 *
 * The trap needs no exotic setup. `bindIf` defaults each contract
 * independently, so a host that wires `ScansInternalLinks` (pure HTML parsing,
 * easy) before `ResolvesUrlToModel` (needs host routing, harder) lands in
 * exactly that state — and that host used to get a loud
 * `BindingResolutionException` instead. A *fully* unwired install happens to be
 * safe only because the null scanner finds no links to check, which is luck,
 * not design.
 *
 * So this is marked `UnconfiguredSeam` and both removal paths skip removal when
 * they see it. `null` from a real resolver still means "broken, remove". `null`
 * from this one means "nobody asked me to know".
 *
 * A host with real posts/products/categories to resolve against binds its
 * own implementation; `bindIf` leaves it alone.
 */
final class NullUrlToModelResolver implements ResolvesUrlToModel, UnconfiguredSeam
{
    public function resolve(string $url): ?array
    {
        return null;
    }
}
