<?php

declare(strict_types=1);

namespace Core45\AiPosts\Support\Seo;

use Core45\AiPosts\Contracts\Seo\ProvidesKeywordMetrics;

/**
 * The default when the host has not bound its own keyword-metrics provider.
 *
 * `ProvidesKeywordMetrics` is method-injected, unconditionally, into
 * `KeywordResearchJob::handle()`, and `PipelineOrchestratorService::
 * dispatchResearchBatch()` always includes that job in the research batch —
 * no config flag guards the dispatch. So a fresh install threw
 * `BindingResolutionException` on the first blog pipeline run, before
 * `isEnabled()` was ever checked.
 *
 * Semrush (and similar keyword-difficulty services) are paid third-party
 * integrations no new application has credentials for on day one.
 * `isEnabled()` is this interface's capability probe, so "this application
 * has no keyword metrics" is already expressible: the flag returns false,
 * which is what every caller checks before trusting the difficulty array.
 *
 * A host with a Semrush (or similar) integration binds its own
 * implementation; `bindIf` leaves it alone.
 */
final class NullKeywordMetricsProvider implements ProvidesKeywordMetrics
{
    public function isEnabled(): bool
    {
        return false;
    }

    public function getKeywordDifficulty(string $keyword, string $database = 'us'): array
    {
        return [];
    }
}
