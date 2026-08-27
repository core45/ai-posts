<?php

declare(strict_types=1);

namespace Core45\AiPosts\Services\BlogTopics;

use Core45\AiPosts\DTOs\BlogTopics\CitationCheckResult;

class CitationGuardService
{
    /**
     * Check content for uncited factual claims.
     *
     * @param  array<array{marker: int, url: string, title?: string}>  $citationsJson
     */
    public function check(string $markdownContent, array $citationsJson): CitationCheckResult
    {
        $strict = (bool) config('ai.assistant.blog_topics.citation_guard.strict', false);

        $sentences = $this->extractSentences($markdownContent);
        $factualSentences = $this->identifyFactualClaims($sentences);
        $validMarkers = array_column($citationsJson, 'marker');

        $totalClaims = count($factualSentences);
        $citedClaims = 0;
        $uncitedClaims = [];
        $warnings = [];

        foreach ($factualSentences as $sentence) {
            if ($this->hasCitationMarker($sentence, $validMarkers)) {
                $citedClaims++;
            } else {
                $uncitedClaims[] = $sentence;
                $warnings[] = "Uncited claim: \"{$this->truncate($sentence, 100)}\"";
            }
        }

        // Check for orphaned markers (referencing non-existent citations)
        $usedMarkers = $this->extractUsedMarkers($markdownContent);
        foreach ($usedMarkers as $marker) {
            if (!in_array($marker, $validMarkers)) {
                $warnings[] = "Citation marker [{$marker}] references a non-existent source";
            }
        }

        $passed = $strict ? empty($uncitedClaims) : true;

        return new CitationCheckResult(
            passed: $passed,
            warnings: $warnings,
            uncitedClaims: $uncitedClaims,
            totalClaims: $totalClaims,
            citedClaims: $citedClaims,
        );
    }

    /**
     * @return array<string>
     */
    private function extractSentences(string $content): array
    {
        // Strip HTML tags but keep text content
        $text = strip_tags($content);

        // Split on sentence boundaries
        $sentences = preg_split('/(?<=[.!?])\s+/', $text, -1, PREG_SPLIT_NO_EMPTY);

        return is_array($sentences) ? array_map('trim', $sentences) : [];
    }

    /**
     * Identify sentences that contain factual indicators.
     *
     * @param  array<string>  $sentences
     * @return array<string>
     */
    private function identifyFactualClaims(array $sentences): array
    {
        $factualPatterns = [
            '/\b\d{1,3}(?:[.,]\d{3})*(?:[.,]\d+)?\s*%/', // Percentages
            '/\b\d{4}\b/', // Years
            '/\b(?:billion|million|thousand|trillion)\b/i', // Large numbers
            '/\baccording to\b/i',
            '/\breport(?:s|ed)?\b/i',
            '/\bstudy\b/i',
            '/\bstatistic(?:s|ally)?\b/i',
            '/\bsurve(?:y|yed)\b/i',
            '/\b(?:EUR|USD|\$|€)\s*\d/i', // Currency amounts
            '/\b\d+(?:[.,]\d+)?\s*(?:EUR|USD)\b/i', // Currency amounts reversed
            '/\bIEC\s+\d+/i', // Standard references
            '/\bISO\s+\d+/i', // ISO standards
        ];

        $factualSentences = [];

        foreach ($sentences as $sentence) {
            if (strlen($sentence) < 10) {
                continue;
            }

            foreach ($factualPatterns as $pattern) {
                if (preg_match($pattern, $sentence)) {
                    $factualSentences[] = $sentence;
                    break;
                }
            }
        }

        return $factualSentences;
    }

    /**
     * @param  array<int>  $validMarkers
     */
    private function hasCitationMarker(string $sentence, array $validMarkers): bool
    {
        if (preg_match_all('/\[(\d+)\]/', $sentence, $matches)) {
            foreach ($matches[1] as $marker) {
                if (in_array((int) $marker, $validMarkers)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return array<int>
     */
    private function extractUsedMarkers(string $content): array
    {
        preg_match_all('/\[(\d+)\]/', $content, $matches);

        return array_unique(array_map('intval', $matches[1]));
    }

    private function truncate(string $text, int $length): string
    {
        if (strlen($text) <= $length) {
            return $text;
        }

        return substr($text, 0, $length).'...';
    }
}
