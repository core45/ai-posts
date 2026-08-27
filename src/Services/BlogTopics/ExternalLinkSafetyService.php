<?php

declare(strict_types=1);

namespace Core45\AiPosts\Services\BlogTopics;

use Core45\AiPosts\DTOs\BlogTopics\LinkSafetyResult;
use Core45\AiPosts\Enums\BlogExternalLinkStatus;
use Core45\AiPosts\Models\BlogExternalLink;

class ExternalLinkSafetyService
{
    /**
     * Check if an external URL is safe to link to.
     *
     * Checks in order:
     * 1. Admin blocklist (instant reject if URL/domain is blocked)
     * 2. Admin safelist (instant pass if existing active link)
     * 3. Domain reputation (reject known spam TLDs, suspicious patterns)
     * 4. Default: REJECT unknown URLs (default-deny)
     */
    public function check(string $url): LinkSafetyResult
    {
        $urlHash = hash('sha256', $url);
        $domain = (string) parse_url($url, PHP_URL_HOST);

        // Check 1: Is this exact URL or its domain blocked?
        $blockedLink = BlogExternalLink::query()
            ->where('status', BlogExternalLinkStatus::Blocked)
            ->where(function ($query) use ($urlHash, $domain): void {
                $query->where('url_hash', $urlHash)
                    ->orWhere('domain', $domain);
            })
            ->first();

        if ($blockedLink !== null) {
            return new LinkSafetyResult(
                safe: false,
                reason: 'URL or domain is blocked: '.($blockedLink->blocked_reason ?? 'No reason provided'),
                confidence: 1.0,
            );
        }

        // Check 2: Is this an active (admin-approved) link?
        $activeLink = BlogExternalLink::query()
            ->where('status', BlogExternalLinkStatus::Active)
            ->where('url_hash', $urlHash)
            ->first();

        if ($activeLink !== null) {
            return new LinkSafetyResult(
                safe: true,
                reason: 'URL is on active safelist',
                confidence: 1.0,
            );
        }

        // Check 2.5: Domain reputation — reject known spam TLDs, suspicious patterns
        $suspiciousTlds = ['xyz', 'top', 'buzz', 'click', 'link', 'gq', 'cf', 'tk', 'ml', 'ga'];
        $tld = strtolower(pathinfo($domain, PATHINFO_EXTENSION));
        if (in_array($tld, $suspiciousTlds, true)) {
            return new LinkSafetyResult(
                safe: false,
                reason: "Suspicious TLD '.{$tld}' — commonly associated with spam",
                confidence: 0.9,
            );
        }

        // Check competitor domains from config
        $competitorDomains = config('ai.assistant.blog_topics.competitor_domains', []);
        if (is_array($competitorDomains) && in_array($domain, $competitorDomains, true)) {
            return new LinkSafetyResult(
                safe: false,
                reason: 'Domain is a known competitor',
                confidence: 1.0,
            );
        }

        // Check 3: Default-deny for unknown URLs
        return new LinkSafetyResult(
            safe: false,
            reason: 'Unknown URL — requires admin review or manual addition to approved links',
            confidence: 0.0,
        );
    }
}
