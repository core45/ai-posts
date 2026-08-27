<?php

declare(strict_types=1);

namespace Core45\AiPosts\Services\BlogPipeline;

use Core45\AiPosts\Exceptions\UnbillableModulePayloadException;
use Core45\AiPosts\Services\BlogPipeline\Modules\AbstractContentModule;

/**
 * THE LAST THING THAT LOOKS AT A MODULE PAYLOAD BEFORE IT IS BILLED
 * (plan 48-24, findings F4 and F5).
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * F4 — "SAFE BY CONSTRUCTION" WAS NOT ENFORCED AT THE PAYLOAD BOUNDARY.
 *
 * 48-23's dual write is what makes rollback, blue/green and mixed fleets safe,
 * and its guarantee is stated as structural: both complete shapes on every module
 * render. But the only thing implementing it is `DynamicContentModule::buildPayload()`.
 * `ModuleResolver` admits ANY `AbstractContentModule` subclass named in
 * `config('blog-pipeline.modules')`, the abstract contract returns an
 * unconstrained `array<string, mixed>`, and `ModuleExecutor` forwarded whatever
 * came back straight to `runPrompt()` without looking.
 *
 * That map is EMPTY today, so this is latent rather than live — but it is the
 * documented extension point, and a second module class that sent typed data only
 * would deliver a silent, billed, content-free completion to any old phasea, which
 * is the precise failure the dual write exists to prevent. A structural claim that
 * nothing checks is a comment, not a guarantee.
 *
 * F5 — THE DUAL WRITE NARROWED THE EFFECTIVE SIZE CONTRACT.
 *
 * Typed fields carry no encoded-byte ceiling, their retired twins are capped at
 * 2,000,000 characters each by phasea, and the edge in front of phasea refuses a
 * body over 8 MB. Sending both doubled the body, so two individually legal shapes
 * can now be refused together — and the host had no preflight, so the first sign
 * was a 413 from a proxy or a 422 from a rule, after the payload had been built
 * and sent. The intersection is enforced here instead, with a message naming the
 * value that broke it.
 *
 * WHY REFUSING IS RIGHT AND TRUNCATING IS NOT. Both failures mean the instruction
 * the model would receive is not the one the host meant to send. A truncated
 * prompt is a paid completion built from mutilated input that nobody notices; a
 * refusal costs a run and says which value to fix.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class ModulePayloadGuard
{
    /**
     * The typed keys `PromptTemplateResolver::buildPlaceholders()` produces, i.e.
     * phasea's `ModulePlaceholderData` contract as this host knows it.
     *
     * PINNED AGAINST THE REAL BUILDERS by `ModulePayloadGuardTest`, so a key added
     * to the resolver and not here (or the reverse) fails loudly rather than
     * quietly relaxing the completeness bar this class exists to hold.
     *
     * @var list<string>
     */
    public const array TYPED_KEYS = [
        'topic_title',
        'topic_intent',
        'topic_outline',
        'site_profile',
        'brand_narrative',
        'research_keywords',
        'research_serp_analysis',
        'research_questions',
        'research_synthesis',
        'previous_module_summaries',
        'recent_module_contents',
        'previous_module_last',
        'rag_context',
        'product_context',
        'internal_link_targets',
        'language',
        'style_rules',
        'forbidden_patterns',
        'learned_knowledge',
        'profile_lessons',
        'research_sources',
        'already_linked_urls',
    ];

    /**
     * The `{{placeholder}}` names the RETIRED map must cover — phasea's
     * `SUPPORTED_PLACEHOLDERS` minus `citation_instructions`, which phasea
     * supplies itself from `citations_enabled`.
     *
     * Deleted with the transition window, together with the shape it describes.
     *
     * @var list<string>
     */
    public const array LEGACY_KEYS = [
        'topic_title',
        'topic_intent',
        'topic_outline',
        'site_profile',
        'brand_narrative',
        'research_keywords',
        'research_serp_analysis',
        'research_questions',
        'research_synthesis',
        'previous_modules',
        'previous_modules_detailed',
        'previous_module_last',
        'rag_context',
        'product_context',
        'internal_link_targets',
        'language',
        'style_rules',
        'forbidden_patterns',
        'learned_knowledge',
        'profile_lessons',
        'research_sources',
        'already_linked_urls',
    ];

    /**
     * Refuse a module payload that cannot be safely billed.
     *
     * @param  array<string, mixed>  $payload
     *
     * @throws UnbillableModulePayloadException
     */
    public function assertBillable(array $payload, AbstractContentModule $module): void
    {
        $class = $module::class;

        $this->assertBothShapesComplete($payload, $class);
        $this->assertWithinSizeIntersection($payload, $class);
    }

    /**
     * @param  array<string, mixed>  $payload
     *
     * @throws UnbillableModulePayloadException
     */
    private function assertBothShapesComplete(array $payload, string $class): void
    {
        foreach (
            [
                'placeholder_data' => self::TYPED_KEYS,
                'placeholders' => self::LEGACY_KEYS,
            ] as $key => $expected
        ) {
            $shape = $payload[$key] ?? null;

            if (!is_array($shape) || $shape === []) {
                throw new UnbillableModulePayloadException(
                    "{$class} produced a module payload with no `{$key}` map. The transition window requires BOTH complete placeholder shapes on every render, because whichever phasea build answers must already hold what it needs (plan 48-23)."
                );
            }

            $missing = array_values(array_diff($expected, array_keys($shape)));

            if ($missing !== []) {
                throw new UnbillableModulePayloadException(
                    "{$class} produced an incomplete `{$key}` map: ".implode(', ', $missing)
                    .'. An incomplete map renders those values as the empty string in a PAID completion, so the call is refused before it is made.'
                );
            }
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     *
     * @throws UnbillableModulePayloadException
     */
    private function assertWithinSizeIntersection(array $payload, string $class): void
    {
        $maxValueChars = (int) config('blog-pipeline.max_placeholder_value_chars', 2_000_000);

        /** @var array<string, mixed> $legacy */
        $legacy = $payload['placeholders'] ?? [];

        foreach ($legacy as $placeholder => $value) {
            if (!is_string($value)) {
                continue;
            }

            // CHARACTERS, matching phasea's `max:` rule on `parameters.placeholders.*`.
            $length = mb_strlen($value);

            if ($length > $maxValueChars) {
                throw new UnbillableModulePayloadException(
                    "{$class} produced a `placeholders.{$placeholder}` value of {$length} characters, over the {$maxValueChars} the AI service accepts. Refused here rather than sent to be rejected."
                );
            }
        }

        $maxBodyBytes = (int) config('blog-pipeline.max_prompt_body_bytes', 8 * 1024 * 1024);

        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE);

        if (!is_string($encoded)) {
            throw new UnbillableModulePayloadException("{$class} produced a module payload that cannot be JSON-encoded.");
        }

        // BYTES, matching what an HTTP edge counts. `strlen()` on a JSON string is
        // the real wire length, which is the whole point of measuring it here
        // rather than counting characters somewhere convenient.
        $bytes = strlen($encoded);

        if ($bytes > $maxBodyBytes) {
            throw new UnbillableModulePayloadException(
                "{$class} produced a module payload of {$bytes} bytes, over the {$maxBodyBytes}-byte ceiling the AI service edge enforces. The transition window sends BOTH placeholder shapes, so this body is roughly twice what one shape would be; refused here rather than sent to be rejected as an opaque 413."
            );
        }
    }
}
