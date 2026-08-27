<?php

declare(strict_types=1);

namespace Core45\AiPosts\Support\Seo;

use Core45\AiPosts\Contracts\Seo\SuggestsInternalLinks;
use Illuminate\Database\Eloquent\Model;

/**
 * The default when the host has not bound its own internal-link suggestion
 * strategy.
 *
 * `EnrichPostLinksJob::handle()` method-injects `SuggestsInternalLinks` as
 * the string-matching fallback used when the AI-powered suggestion step
 * (`AiLinkSuggestionService`) returns nothing — but the job resolves it
 * unconditionally on every method call, whether or not the fallback path is
 * ever taken, so a fresh install threw `BindingResolutionException` before
 * either suggestion strategy ran.
 *
 * Returning an empty list is exactly what "no suggestions" already means to
 * the caller: `EnrichPostLinksJob` treats an empty `$linkTargets` array as a
 * legitimate outcome (`$actuallyInjected` stays 0) and continues the rest of
 * enrichment (external link suggestions, SEO indexable link counts) without
 * it.
 *
 * A host with a real internal-link index binds its own implementation;
 * `bindIf` leaves it alone.
 */
final class NullInternalLinkSuggester implements SuggestsInternalLinks
{
    public function suggestLinks(Model $indexable, int $limit = 5, string $contentText = ''): array
    {
        return [];
    }
}
