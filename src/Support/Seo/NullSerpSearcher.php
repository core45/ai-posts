<?php

declare(strict_types=1);

namespace Core45\AiPosts\Support\Seo;

use Core45\AiPosts\Contracts\Seo\SearchesSerp;

/**
 * The default when the host has not bound its own SERP search backend.
 *
 * `SearchesSerp` is method-injected, unconditionally, into
 * `KeywordResearchJob::handle()` and `UserIntentResearchJob::handle()` —
 * both of which `PipelineOrchestratorService::dispatchResearchBatch()` always
 * includes in the research batch, with no `research_config` flag guarding
 * the dispatch. So a fresh install threw `BindingResolutionException` on the
 * very first blog pipeline run, even though both jobs already gate the
 * actual `search()` call behind `$rc['enable_serp_analysis']` — the failure
 * happened before that check was ever reached. `CompetitorContentJob` and
 * `SerpAnalysisJob` inject the same interface but are themselves only
 * dispatched when their own config flags are set, so this default also
 * covers those opt-in paths once the host does enable them without a SERP
 * key configured.
 *
 * SERP APIs (SerpApi, and similar) are a paid third-party service no new
 * application has credentials for on day one. There is no `isEnabled()`
 * probe on this interface — `search()` returning `null` already means
 * "no result" to every caller (`if ($response !== null)` / `$serpEnabled ?
 * $serpApi->search($keyword) : null`), so a default that always answers
 * `null` is indistinguishable, from the caller's side, from "SERP disabled".
 *
 * A host with a SerpApi (or similar) key binds its own implementation;
 * `bindIf` leaves it alone.
 */
final class NullSerpSearcher implements SearchesSerp
{
    public function search(string $query, string $engine = 'google', array $options = []): ?array
    {
        return null;
    }
}
