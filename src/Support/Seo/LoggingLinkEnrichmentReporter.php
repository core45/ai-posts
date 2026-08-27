<?php

declare(strict_types=1);

namespace Core45\AiPosts\Support\Seo;

use Core45\AiPosts\Contracts\Seo\ReportsLinkEnrichment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

/**
 * The default when the host has not bound its own link-enrichment reporter.
 *
 * `EnrichPostLinksJob::sendReport()` calls `app(ReportsLinkEnrichment::class)`
 * unconditionally at the end of every `handle()` run — chained after every
 * assembled post regardless of any Filament panel or notification setup —
 * so a fresh install threw `BindingResolutionException` after enrichment had
 * already succeeded, discarding the work that had just been done.
 *
 * Modelled on `ai-core`'s `LoggingOperatorNotifier`: it logs rather than
 * doing nothing silently, because a report that vanishes into the void when
 * nobody is listening is worse than one that at least leaves a trace an
 * operator can grep for.
 *
 * A host with a notification channel (Filament, mail, Slack, …) binds its
 * own implementation; `bindIf` leaves it alone.
 */
final class LoggingLinkEnrichmentReporter implements ReportsLinkEnrichment
{
    public function report(array $report, Model $recipient): void
    {
        Log::info('AI link enrichment report (no ReportsLinkEnrichment bound — logged only)', [
            'recipient_type' => $recipient::class,
            'recipient_id' => $recipient->getKey(),
            'report' => $report,
            'delivered' => false,
        ]);
    }

    public function reportToMany(array $report, iterable $recipients): void
    {
        foreach ($recipients as $recipient) {
            $this->report($report, $recipient);
        }
    }
}
