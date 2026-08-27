<?php

declare(strict_types=1);

namespace Core45\AiPosts\Contracts\Seo;

use Illuminate\Database\Eloquent\Model;

/**
 * Delivers a link-enrichment report to a recipient, hiding both the host's
 * notification class and the notify call behind one seam.
 */
interface ReportsLinkEnrichment
{
    /**
     * @param  array{
     *     post_id: int,
     *     post_title: string,
     *     locale: string,
     *     source: string,
     *     suggested: array<int, array{url: string, anchorText: string, reason?: string}>,
     *     injected_count: int,
     *     warnings: array<int, string>,
     * }  $report
     */
    public function report(array $report, Model $recipient): void;

    /**
     * Deliver the same report to a batch of recipients.
     *
     * Kept as its own method rather than left for the caller to loop: the host
     * sends these through Laravel's batch notification path, and collapsing
     * that into N single sends would change how the notifications are queued
     * and grouped. The seam preserves the delivery shape, not just the payload.
     *
     * @param  array{
     *     post_id: int,
     *     post_title: string,
     *     locale: string,
     *     source: string,
     *     suggested: array<int, array{url: string, anchorText: string, reason?: string}>,
     *     injected_count: int,
     *     warnings: array<int, string>,
     * }  $report
     * @param  iterable<int, Model>  $recipients
     */
    public function reportToMany(array $report, iterable $recipients): void;
}
