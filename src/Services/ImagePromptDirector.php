<?php

declare(strict_types=1);

namespace Core45\AiPosts\Services;

use Core45\AiCore\DTOs\ImagePromptItem;
use Core45\AiCore\DTOs\ImagePromptPlan;
use Core45\AiCore\DTOs\ImagePromptRequest;
use Core45\AiCore\DTOs\InTextPromptItem;
use Core45\AiCore\Enums\AiSurface;
use Core45\AiCore\Services\AiClientFactory;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ImagePromptDirector
{
    /**
     * The phasea-owned image template that wraps a base prompt — one the
     * image-director model returned, or one an author typed into an `[IMAGE:…]`
     * marker — in the decoration frame.
     */
    public const string DECORATED_TEMPLATE = 'images.decorated';

    /**
     * The phasea-owned image template that owns BOTH the per-slot fallback base
     * wording and the decoration frame, so a fallback is a single call.
     */
    public const string FALLBACK_TEMPLATE = 'images.fallback';

    /**
     * Host-side caps applied before a value leaves this repo as a template
     * parameter, mirroring `BlogImageGeneratorService`'s (which already capped
     * the parameters IT sent).
     *
     * NOT cosmetic. phasea's templates bound every parameter as the
     * untrusted-input guard (D-48-2), and the fallback path is REACHABLE, not
     * defensive: any phasea failure sends `plan()` through `fallbackPlan()`,
     * which fills every slot from the post's own title and excerpt. Without a
     * cap, an article with a title longer than phasea's 500-char bound would
     * turn an LLM outage into a 422 and produce NO image, where before it
     * produced a long prompt that still generated. Truncating is the behaviour
     * closer to the pre-migration one.
     *
     * Each ceiling sits at the matching template rule, so a value that fits here
     * cannot be rejected there.
     */
    private const int MAX_TITLE_CHARS = 500;

    private const int MAX_EXCERPT_CHARS = 2000;

    private const int MAX_CONTEXT_CHARS = 2000;

    private const int MAX_BASE_PROMPT_CHARS = 5000;

    public function plan(ImagePromptRequest $request): ImagePromptPlan
    {
        $structured = $this->requestPlanFromLlm($request);

        if ($structured === null || $structured === []) {
            return $this->fallbackPlan($request);
        }

        return $this->buildPlanFromStructured($structured, $request);
    }

    /**
     * The decoration VALUES a profile and style contribute, as phasea's image
     * templates take them.
     *
     * v2.6 design §2.5 (plan 48-19): this replaces `decoratePrompt()`, which
     * composed the frame here. The labels (`Style:`, `Always include:`,
     * `Never show:`, `Avoid:`) and the fixed `No text, letters, words, logos or
     * watermarks.` guard were authored prose and now live in phasea's
     * `DecoratesImagePrompts`; only these tenant-owned values travel.
     *
     * Values are sent RAW — untrimmed, unfiltered, empty strings preserved —
     * because phasea reproduces the host's exact gating (a whitespace-only
     * `style_fragment` still renders, a whitespace-only `negative_prompt` still
     * disappears). Normalising here would silently change composed prompts.
     *
     * Public so the author-marker path in `AIMediaGenerationService` can build
     * the same parameter map without a second copy of this knowledge.
     *
     * @return array<string, mixed>
     */
    /**
     * @param  object|null  $profile  consumer-owned ImageDirectorProfile
     * @param  object|null  $style  consumer-owned ImageStyle
     */
    public function decorationParameters(?object $profile, ?object $style): array
    {
        return [
            'style_fragment' => $style?->prompt_fragment,
            'style_guidance' => $profile?->style_guidance,
            // `array_values()`: a Filament repeater deletion leaves an
            // integer-keyed hole that would otherwise JSON-encode as an object.
            'always_include' => $profile !== null ? array_values($profile->always_include ?? []) : [],
            'forbidden' => $profile !== null ? array_values($profile->forbidden ?? []) : [],
            'negative_prompt' => $profile?->negative_prompt,
        ];
    }

    /**
     * The reproducible input for an audit/metadata field that used to store the
     * composed prompt string (D-48-6).
     *
     * The host cannot record the instruction any more — it never sees it. The
     * template key plus the parameters IS the reproducible input, and it is what
     * `BlogImageGeneratorService::generateAltText()` already records for the text
     * surfaces.
     *
     * @param  array<string, mixed>  $parameters
     */
    public static function promptRecord(string $template, array $parameters): string
    {
        return json_encode([
            'template' => $template,
            'parameters' => $parameters,
        ], JSON_UNESCAPED_UNICODE) ?: $template;
    }

    /**
     * @return array<mixed>|null Raw structured array, or null on any failure.
     */
    protected function requestPlanFromLlm(ImagePromptRequest $request): ?array
    {
        try {
            return $this->callStructured($request);
        } catch (\Throwable $e) {
            Log::warning('ImagePromptDirector: LLM plan failed, using fallback', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * v2.6 design §2.5 (phase 48): posts the phasea-owned `images.prompt_director`
     * template with the plan request as structured parameters. This host holds no
     * image-director instruction text — the developer base and the user body both
     * moved to phasea, and the tenant's `ImageDirectorProfile.system_prompt` and
     * `ImageStyle.prompt_fragment` travel as parameters where phasea reproduces
     * the host's `?:` precedence (empty profile prompt falls through to the base).
     *
     * It runs on `AiSurface::Images`, but it is a TEXT completion that returns
     * JSON describing image prompts — NOT the `image/generate` path. The JSON
     * decode and the `is_array($decoded) ? $decoded : null` contract are
     * unchanged, so a malformed body still falls back to `fallbackPlan()`.
     *
     * @return array<mixed>|null Raw structured array, or null on any failure.
     */
    protected function callStructured(ImagePromptRequest $request): ?array
    {
        $operationId = (string) Str::uuid();

        $client = app(AiClientFactory::class)->for(AiSurface::Images);

        $result = $client->runPrompt(
            'images.prompt_director',
            [
                'title' => $request->title,
                'excerpt' => $request->excerpt,
                'locale' => $request->locale,
                'entity_context' => $request->entityContext,
                'body_plain' => $request->bodyPlain,
                'headings' => array_values($request->headings),
                'profile_system_prompt' => $request->profile?->system_prompt,
                'style_fragment' => $request->style?->prompt_fragment,
                'featured' => $request->featured,
                'gallery' => $request->gallery,
                'in_text' => $request->inText,
            ],
            $operationId,
        );

        $text = trim($result->text);

        /** @var mixed $decoded */
        $decoded = json_decode($text, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param  array<mixed>  $structured
     */
    private function buildPlanFromStructured(array $structured, ImagePromptRequest $request): ImagePromptPlan
    {
        $featured = null;
        if ($request->featured && isset($structured['featured']['prompt'])) {
            $featured = new ImagePromptItem(
                self::DECORATED_TEMPLATE,
                $this->decoratedParameters((string) $structured['featured']['prompt'], $request),
                $this->altOr((string) ($structured['featured']['alt'] ?? ''), $request->title),
            );
        } elseif ($request->featured) {
            $featured = $this->fallbackFeatured($request);
        }

        $gallery = [];
        $galleryItems = is_array($structured['gallery'] ?? null) ? $structured['gallery'] : [];
        for ($i = 0; $i < $request->gallery; $i++) {
            $raw = $galleryItems[$i] ?? null;
            $gallery[] = is_array($raw) && isset($raw['prompt'])
                ? new ImagePromptItem(self::DECORATED_TEMPLATE, $this->decoratedParameters((string) $raw['prompt'], $request), $this->altOr((string) ($raw['alt'] ?? ''), $request->title))
                : $this->fallbackGallery($request, $i + 1);
        }

        $inText = [];
        $inTextItems = is_array($structured['in_text'] ?? null) ? $structured['in_text'] : [];
        for ($i = 0; $i < $request->inText; $i++) {
            $raw = $inTextItems[$i] ?? null;
            if (is_array($raw) && isset($raw['prompt'])) {
                $inText[] = new InTextPromptItem(
                    self::DECORATED_TEMPLATE,
                    $this->decoratedParameters((string) $raw['prompt'], $request),
                    $this->altOr((string) ($raw['alt'] ?? ''), $request->title),
                    isset($raw['section_index']) ? (int) $raw['section_index'] : null,
                    isset($raw['section_heading']) ? (string) $raw['section_heading'] : null,
                );
            } else {
                $inText[] = $this->fallbackInText($request, $i + 1);
            }
        }

        return new ImagePromptPlan($featured, $gallery, $inText);
    }

    /**
     * A model-returned base prompt plus this request's decoration values.
     *
     * @return array<string, mixed>
     */
    private function decoratedParameters(string $basePrompt, ImagePromptRequest $request): array
    {
        return ['base_prompt' => $this->cap($basePrompt, self::MAX_BASE_PROMPT_CHARS)]
            + $this->decorationParameters($request->profile, $request->style);
    }

    /**
     * Truncate a value to the ceiling phasea's matching template rule declares.
     *
     * Public so `AIMediaGenerationService` caps the author's `[IMAGE:…]` marker
     * text and its entity context the same way, rather than growing a second,
     * divergent set of limits.
     */
    public function cap(string $value, int $maxCharacters): string
    {
        return mb_strlen($value) > $maxCharacters ? mb_substr($value, 0, $maxCharacters) : $value;
    }

    /**
     * The base-prompt ceiling, for callers that build an `images.decorated`
     * parameter map without going through a plan.
     */
    public function capBasePrompt(string $value): string
    {
        return $this->cap($value, self::MAX_BASE_PROMPT_CHARS);
    }

    /**
     * The entity-context ceiling, for the `entity_gallery` slot.
     */
    public function capContext(string $value): string
    {
        return $this->cap($value, self::MAX_CONTEXT_CHARS);
    }

    private function fallbackPlan(ImagePromptRequest $request): ImagePromptPlan
    {
        $featured = $request->featured ? $this->fallbackFeatured($request) : null;
        $gallery = [];
        for ($i = 1; $i <= $request->gallery; $i++) {
            $gallery[] = $this->fallbackGallery($request, $i);
        }
        $inText = [];
        for ($i = 1; $i <= $request->inText; $i++) {
            $inText[] = $this->fallbackInText($request, $i);
        }

        return new ImagePromptPlan($featured, $gallery, $inText);
    }

    /**
     * The per-slot fallbacks. Public because they are the SINGLE place the host
     * names phasea's fallback slots — `GeneratePostPhotosJob` delegates to them
     * on its defensive arm (when a plan unexpectedly omits a requested slot)
     * rather than assembling a divergent parameter map.
     *
     * v2.6 design §2.5 (plan 48-19): the base WORDING these once interpolated
     * ("Editorial hero image for an article titled …", "Supporting image …",
     * "Illustration for the section …") moved to phasea's `images.fallback`.
     * What is left is the slot name and the tenant/post DATA it interpolates —
     * which of `title`, `excerpt`, `context`, `number` a slot needs, plus the
     * decoration values.
     */
    public function fallbackFeatured(ImagePromptRequest $r): ImagePromptItem
    {
        return new ImagePromptItem(
            self::FALLBACK_TEMPLATE,
            ['slot' => 'featured', 'title' => $this->cap($r->title, self::MAX_TITLE_CHARS), 'excerpt' => $this->cap($r->excerpt, self::MAX_EXCERPT_CHARS)]
                + $this->decorationParameters($r->profile, $r->style),
            $this->altOr('', $r->title),
        );
    }

    public function fallbackGallery(ImagePromptRequest $r, int $n): ImagePromptItem
    {
        return new ImagePromptItem(
            self::FALLBACK_TEMPLATE,
            ['slot' => 'gallery', 'title' => $this->cap($r->title, self::MAX_TITLE_CHARS), 'excerpt' => $this->cap($r->excerpt, self::MAX_EXCERPT_CHARS), 'number' => $n]
                + $this->decorationParameters($r->profile, $r->style),
            $this->altOr('', $r->title),
        );
    }

    public function fallbackInText(ImagePromptRequest $r, int $n): InTextPromptItem
    {
        $heading = $r->headings[$n - 1] ?? null;
        // Which of the two the section is described by is a DATA choice, so it
        // stays here; phasea receives the winner as `context`.
        $context = $heading ?? $r->excerpt;

        return new InTextPromptItem(
            self::FALLBACK_TEMPLATE,
            ['slot' => 'in_text', 'title' => $this->cap($r->title, self::MAX_TITLE_CHARS), 'context' => $this->cap($context, self::MAX_CONTEXT_CHARS)]
                + $this->decorationParameters($r->profile, $r->style),
            $this->altOr('', $r->title),
            $heading !== null ? $n : null,
            $heading,
        );
    }

    private function altOr(string $alt, string $title): string
    {
        $alt = trim($alt);

        return mb_substr($alt !== '' ? $alt : $title, 0, 125);
    }
}
