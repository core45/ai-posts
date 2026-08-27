<?php

declare(strict_types=1);

namespace Core45\AiPosts\Services\BlogPipeline;

use Core45\AiPosts\DTOs\BlogPipeline\ModuleContext;
use Core45\AiPosts\Models\BlogPostPipelineRun;
use Core45\AiPosts\Services\BlogPipeline\Modules\DynamicContentModule;

/**
 * Gathers the TYPED host DATA a blog-pipeline module run supplies to phasea.
 *
 * v2.6 (design §2.5, plans 48-15 and 48-21): the host neither resolves nor
 * FORMATS any part of the instruction. It reads values off host models — the
 * candidate, the profile, `research_data`, the {@see ModuleContext} — and hands
 * them over with their structure intact.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WHAT CHANGED IN 48-21 (finding F5), AND WHY IT WAS NOT ALREADY DONE.
 *
 * 48-15 flipped this class from resolving templates to building a map of
 * already-FORMATTED strings, and recorded that as "parameter prep". It was not:
 * the strings it produced were model-visible presentation the host had authored —
 *
 *   `--- Module: {slug} ---`          block headers
 *   `=== Recent Module: {slug} ===`   block headers
 *   `Available sources for citation:` the heading above the numbered list
 *   `None yet — this is the first module.`  the empty-case sentence
 *   `implode(', ')` vs `implode("\n")`     list separators the model reads
 *
 * — and 48-14 had already transcribed every one of those into phasea's
 * `ModulePlaceholderResolver`, where they sat unit-tested and UNCALLED. 48-14
 * said 48-15 would send raw values; 48-15's summary then described the same
 * values as both "RAW" and "a preformatted string map". This class is the half
 * of that handoff that never landed.
 *
 * Now it returns STRUCTURE — a `slug => summary` map, source records, arrays for
 * the JSON blocks, plain lists — and phasea decides how every one of them reads.
 * phasea's contract is typed, so a regression to formatted strings is a 422
 * rather than a silent return of prompt ownership to the host.
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * WHY `citation_instructions` IS ABSENT. The citation-rules block is authored
 * instruction text phasea owns. The host signals only the per-run decision
 * (`citationsEnabled`, carried separately by {@see DynamicContentModule});
 * phasea fills the value from its own library when enabled.
 */
class PromptTemplateResolver
{
    /**
     * Build the typed placeholder DATA map.
     *
     * The keys are phasea's `ModulePlaceholderData` typed contract — deliberately
     * NOT the template's `{{placeholder}}` names, because the mapping from data to
     * placeholder is phasea's to make (`previous_module_summaries` becomes
     * `{{previous_modules}}` only after phasea formats it into blocks).
     *
     * @return array<string, mixed>
     */
    public function buildPlaceholders(BlogPostPipelineRun $run, ModuleContext $context): array
    {
        $run->loadMissing(['candidate', 'profile']);

        $researchData = $run->research_data ?? [];

        return [
            // From candidate
            'topic_title' => $run->candidate->title ?? '',
            'topic_intent' => $run->candidate->intent ?? '',
            'topic_outline' => $this->arrayOrEmpty($run->candidate->outline_json),

            // From profile
            'site_profile' => $this->arrayOrEmpty($run->profile->profile_json),
            'brand_narrative' => (string) ($run->config_snapshot['brand_narrative'] ?? $run->profile->brand_narrative ?? ''),

            // From research_data
            'research_keywords' => $this->arrayOrEmpty($researchData['keywords'] ?? null),
            'research_serp_analysis' => $this->arrayOrEmpty($researchData['serp'] ?? null),
            'research_questions' => $this->arrayOrEmpty($researchData['user_intent'] ?? null),
            'research_synthesis' => (string) ($researchData['synthesis'] ?? ''),

            // From ModuleContext — structure preserved, formatting left to phasea.
            'previous_module_summaries' => $context->previousModuleSummaries,
            'recent_module_contents' => $context->recentModuleContents,
            'previous_module_last' => $context->lastModuleContent ?? '',
            'rag_context' => $this->arrayOrEmpty($context->ragResults),
            'product_context' => $this->arrayOrEmpty($context->productContext),
            'internal_link_targets' => $this->arrayOrEmpty($context->internalLinkTargets),
            'language' => $context->language,
            'style_rules' => $this->arrayOrEmpty($context->styleRules),
            'forbidden_patterns' => array_values($context->forbiddenPatterns),
            'learned_knowledge' => array_values($context->learnedKnowledge),
            'profile_lessons' => array_values($context->profileLessons),
            'research_sources' => array_values($context->researchSources),
            'already_linked_urls' => array_values($context->alreadyLinkedUrls),
        ];
    }

    /**
     * Normalise a nullable structured value to an array.
     *
     * This is the ONLY transformation left, and it is not presentation: phasea's
     * contract types these keys as arrays, and its own `toJson()` applies the
     * empty-to-`''` degradation the model used to see the host apply.
     *
     * @return array<mixed>
     */
    private function arrayOrEmpty(mixed $data): array
    {
        return is_array($data) ? $data : [];
    }

    // ═════════════════════════════════════════════════════════════════════════
    // THE RETIRED SHAPE — TRANSITION WINDOW ONLY (plan 48-22). DELETE ME.
    //
    // Everything below this line is the PRE-48-21 implementation of this class,
    // restored verbatim from
    // `git show 302fd4b^:app/Services/BlogPipeline/PromptTemplateResolver.php`.
    // It exists for exactly one reason: a phaseb talking to a phasea that knows
    // only `placeholders`, which sending typed data does not fail against — it
    // renders every placeholder EMPTY and returns a paid completion built from an
    // instruction with no content in it.
    //
    // SENT ON EVERY RENDER SINCE 48-23, not on a probed subset of them. 48-22
    // called this only when a capability probe said phasea was old, and that was
    // unsafe: the probe and the paid render are DIFFERENT HTTP REQUESTS, so a
    // rollback, a blue/green cutover or a mixed fleet answers one from a new build
    // and serves the other from an old one. Dual-writing costs one extra formatted
    // map per render, built from data already in memory, and removes the question.
    //
    // YES, THIS IS HOST-HELD MODEL-VISIBLE FORMATTING AGAIN, and that is not an
    // oversight: it is the price of the window, bounded and dated. Nothing calls
    // it once phasea advertises `typed_placeholders`
    // (`AiServiceCapabilities::TYPED_MODULE_PLACEHOLDERS`), and the exit condition
    // in `.planning/…/deferred-items.md` (48-23/TRANSITION-WINDOW) is the removal
    // of this block together with phasea's tolerance for the shape it produces.
    //
    // NOT REFACTORED, NOT "IMPROVED", NOT SHARED WITH `buildPlaceholders()`.
    // Its only job is to emit the bytes an old phasea has always received, and
    // every edit is an opportunity to change them. `PreTypedPlaceholderBytesTest`
    // compares it against a copy of the original file and fails on any drift.
    // ═════════════════════════════════════════════════════════════════════════

    /**
     * The RETIRED placeholder map: `{{placeholder}} => already-formatted string`.
     *
     * @return array<string, string>
     */
    public function buildLegacyPlaceholders(BlogPostPipelineRun $run, ModuleContext $context): array
    {
        $run->loadMissing(['candidate', 'profile']);

        $researchData = $run->research_data ?? [];

        return [
            // From candidate
            'topic_title' => $run->candidate->title ?? '',
            'topic_intent' => $run->candidate->intent ?? '',
            'topic_outline' => $this->legacyToJson($run->candidate->outline_json),

            // From profile
            'site_profile' => $this->legacyToJson($run->profile->profile_json),
            'brand_narrative' => (string) ($run->config_snapshot['brand_narrative'] ?? $run->profile->brand_narrative ?? ''),

            // From research_data
            'research_keywords' => $this->legacyToJson($researchData['keywords'] ?? null),
            'research_serp_analysis' => $this->legacyToJson($researchData['serp'] ?? null),
            'research_questions' => $this->legacyToJson($researchData['user_intent'] ?? null),
            'research_synthesis' => (string) ($researchData['synthesis'] ?? ''),

            // From ModuleContext
            'previous_modules' => $this->legacyFormatPreviousModules($context->previousModuleSummaries),
            'previous_modules_detailed' => $this->legacyFormatRecentModules($context->recentModuleContents),
            'previous_module_last' => $context->lastModuleContent ?? '',
            'rag_context' => $this->legacyToJson($context->ragResults),
            'product_context' => $this->legacyToJson($context->productContext),
            'internal_link_targets' => $this->legacyToJson($context->internalLinkTargets),
            'language' => $context->language,
            'style_rules' => $this->legacyToJson($context->styleRules),
            'forbidden_patterns' => implode(', ', $context->forbiddenPatterns),
            'learned_knowledge' => implode("\n", $context->learnedKnowledge),
            'profile_lessons' => implode("\n", $context->profileLessons),
            'research_sources' => $this->legacyFormatResearchSources($context),
            'already_linked_urls' => $this->legacyFormatAlreadyLinkedUrls($context->alreadyLinkedUrls),
        ];
    }

    /**
     * Encode a value as pretty-printed JSON, returning empty string for null/empty.
     */
    private function legacyToJson(mixed $data): string
    {
        if ($data === null || $data === [] || $data === '') {
            return '';
        }

        return (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    /**
     * Format previous module summaries as labeled blocks.
     *
     * @param  array<string, string>  $summaries
     */
    private function legacyFormatPreviousModules(array $summaries): string
    {
        if ($summaries === []) {
            return '';
        }

        $blocks = [];
        foreach ($summaries as $slug => $summary) {
            $blocks[] = "--- Module: {$slug} ---\n{$summary}";
        }

        return implode("\n\n", $blocks);
    }

    /**
     * Format recent module full contents as labeled blocks for richer deduplication context.
     *
     * @param  array<string, string>  $contents
     */
    private function legacyFormatRecentModules(array $contents): string
    {
        if ($contents === []) {
            return '';
        }

        $blocks = [];
        foreach ($contents as $slug => $content) {
            $stripped = strip_tags($content);
            $blocks[] = "=== Recent Module: {$slug} ===\n{$stripped}";
        }

        return implode("\n\n", $blocks);
    }

    /**
     * Format research sources as a numbered list for citation injection.
     */
    private function legacyFormatResearchSources(ModuleContext $context): string
    {
        if ($context->researchSources === []) {
            return '';
        }

        $lines = ['Available sources for citation:'];
        foreach ($context->researchSources as $source) {
            $lines[] = "[{$source['id']}] {$source['title']} — {$source['url']}";
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<string>  $urls
     */
    private function legacyFormatAlreadyLinkedUrls(array $urls): string
    {
        if ($urls === []) {
            return 'None yet — this is the first module.';
        }

        return implode("\n", array_map(fn (string $url): string => "- {$url}", $urls));
    }
}
