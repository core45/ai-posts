<?php

declare(strict_types=1);

namespace Core45\AiPosts\Services;

use Astrotomic\Translatable\Contracts\Translatable;
use Core45\AiCore\Contracts\GeneratesContentMedia;
use Core45\AiCore\Exceptions\AiBudgetExceededException;
use Core45\AiCore\Exceptions\AiOperationAlreadyCompletedException;
use Core45\AiCore\ModelRegistry;
use Core45\AiCore\Services\AbstractContentRewriteService;
use Illuminate\Database\Eloquent\Model;

/**
 * AI content rewrite service for Posts.
 *
 * Extends AbstractContentRewriteService to provide Post-specific implementations
 * for content rewriting, translation, and SEO generation.
 */
class PostContentRewriteService extends AbstractContentRewriteService
{
    /** @var array<string, mixed> Media generation options */
    private array $mediaOptions = [];

    // ═══════════════════════════════════════════════════════════════════════
    // PUBLIC API
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Set media generation options for the next processing run.
     *
     * @param  array<string, mixed>  $options
     */
    public function withMediaGeneration(array $options): self
    {
        $this->mediaOptions = $options;

        return $this;
    }

    /**
     * Process a post - rewrite/translate all content and SEO fields.
     *
     * @return array{model_id: int, processed_fields: array<string>, errors: array<string>}
     */
    public function processPost(Model $post): array
    {
        // The package never imports the host's Post class (§3 seam) — guard
        // the Astrotomic-dependent parent algorithm at this boundary, following
        // the precedent at DetectionPollService.php:260.
        if (!$post instanceof Translatable) {
            throw new \InvalidArgumentException(sprintf(
                'Model [%s] must implement %s to be processed by %s.',
                $post::class,
                Translatable::class,
                self::class,
            ));
        }

        return $this->processModel($post);
    }

    /**
     * Translate all translatable fields of a post from one locale to another.
     *
     * Translates title, slug, excerpt, main_text, and SEO fields using AI.
     * Skips fields that already have content in the target locale unless $overwrite is true.
     *
     * @return array{model_id: int, processed_fields: array<string>, errors: array<string>}
     */
    public function translateToLocale(Model $post, string $sourceLocale, string $targetLocale, bool $overwrite = false): array
    {
        /** @phpstan-ignore property.notFound */
        $modelId = (int) $post->id;

        $results = [
            'model_id' => $modelId,
            'processed_fields' => [],
            'errors' => [],
        ];

        // The package never imports the host's Post class (§3 seam) — guard
        // the Astrotomic calls below at this boundary instead, following the
        // precedent at DetectionPollService.php:260.
        if (!$post instanceof Translatable) {
            $results['errors'][] = sprintf('Model [%s] does not support translations.', $post::class);

            return $results;
        }

        $this->logRequest($this->getServiceName().' - translateToLocale', [
            'model_id' => $modelId,
            'source' => $sourceLocale,
            'target' => $targetLocale,
            'overwrite' => $overwrite,
        ]);

        $sourceTranslation = $post->translate($sourceLocale);
        if ($sourceTranslation === null || empty($sourceTranslation->title)) {
            $results['errors'][] = "No source content found for locale {$sourceLocale}";

            return $results;
        }

        $allFields = array_merge(
            ['title' => 'plain', 'excerpt' => 'plain'],
            ['main_text' => 'rich'],
            $this->getSeoFields(),
        );

        /** @phpstan-ignore method.notFound */
        $isResearchPost = $post->blogTopicCandidate()->exists();

        foreach ($allFields as $field => $type) {
            if ($this->isCancelled()) {
                break;
            }

            $sourceValue = (string) ($sourceTranslation->{$field} ?? '');
            if ($sourceValue === '') {
                continue;
            }

            $targetTranslation = $post->translate($targetLocale);
            if (!$overwrite && $targetTranslation !== null && !empty($targetTranslation->{$field})) {
                continue;
            }

            try {
                $textToTranslate = (string) $sourceValue;

                // Add citation preservation instructions for research-generated posts
                if ($isResearchPost && preg_match('/\[\d+\]/', $textToTranslate)) {
                    $citationInstructions = 'IMPORTANT: Preserve ALL citation markers [1], [2], etc. exactly as-is. '
                        .'Do NOT translate URLs in the Sources section. '
                        ."Translate the 'Sources' heading but keep [N] Title - URL format unchanged.\n\n";
                    $textToTranslate = $citationInstructions.$textToTranslate;
                }

                // Deterministic per-field operation id derived from the job's
                // operationId (audit 5.4) — retries replay the same id so
                // phasea's usage metering does not re-bill already-translated
                // fields. Falls back to per-call UUIDs when no id was threaded.
                /** @var string $translated */
                $translated = $this->withScopedOperationId(
                    "field:{$field}",
                    fn (): string => $this->translateText($textToTranslate, $sourceLocale, $targetLocale, $type),
                );
                $this->saveTranslation($post, $field, $targetLocale, $translated);
                $results['processed_fields'][] = "{$field}:{$targetLocale}";
            } catch (AiBudgetExceededException $e) {
                // Budget exhaustion is tenant-global — translating further
                // fields cannot succeed. Fail fast so the job fails
                // immediately instead of burning retries (audit §6).
                throw $e;
            } catch (AiOperationAlreadyCompletedException $e) {
                // A retry re-sent a field a prior attempt already translated under the
                // same deterministic id. Not a failure — reconcile against the persisted
                // translation so an already-done field is not reported as a failed one.
                $this->reconcileAlreadyCompletedField($post, $field, $targetLocale, $results, $e);
            } catch (\Exception $e) {
                $results['errors'][] = "{$field}:{$targetLocale} - ".$this->sanitizeErrorMessage($e);
                $this->logError($this->getServiceName().' - translateToLocale', $e);
            }
        }

        // Generate slug from translated title
        if (in_array("title:{$targetLocale}", $results['processed_fields'], true)) {
            try {
                $post->refresh();
                $translatedTitle = $post->translate($targetLocale)->title ?? '';
                if ($translatedTitle !== '') {
                    $slug = $this->generateUniqueSlug($translatedTitle, $modelId, $targetLocale);
                    $this->saveTranslation($post, 'slug', $targetLocale, $slug);
                    $results['processed_fields'][] = "slug:{$targetLocale}";
                }
            } catch (\Exception $e) {
                $results['errors'][] = "slug:{$targetLocale} - ".$this->sanitizeErrorMessage($e);
            }
        }

        $post->refresh();

        $this->logResponse($this->getServiceName().' - translateToLocale', $results);

        return $results;
    }

    // ═══════════════════════════════════════════════════════════════════════
    // ABSTRACT METHOD IMPLEMENTATIONS
    // ═══════════════════════════════════════════════════════════════════════

    protected function getContentFields(): array
    {
        return [
            'excerpt' => 'plain',
            'main_text' => 'rich',
        ];
    }

    protected function getSeoFields(): array
    {
        return [
            'seo_title' => 'plain',
            'seo_description' => 'plain',
            'seo_keywords' => 'plain',
        ];
    }

    protected function getModelClass(): string
    {
        return app(ModelRegistry::class)->getModelClassOrFail('post');
    }

    protected function getTranslationClass(): string
    {
        return app(ModelRegistry::class)->getModelClassOrFail('post_translation');
    }

    /**
     * Override the property default: the touchable class must come from
     * `ModelRegistry`, and a property initializer cannot call `app()`.
     *
     * @return array<int, class-string<Model>>
     */
    protected function getTouchableModelClasses(): array
    {
        return [app(ModelRegistry::class)->getModelClassOrFail('post')];
    }

    protected function getServiceName(): string
    {
        return 'PostContentRewrite';
    }

    protected function getContentTypeLabel(): string
    {
        return 'blog posts';
    }

    /**
     * The `content_type` discriminator phasea's `rewrite.field_rewrite` and
     * `rewrite.field_generate` templates select this subclass's field
     * descriptions with (v2.6 design §2.5). It replaces the already-resolved
     * `field_description` string plan 48-05 sent as a temporary shape.
     */
    protected function getContentTypeKey(): string
    {
        return 'post';
    }

    protected function getPromptOverrideKey(): string
    {
        return 'post_rewrite_system';
    }

    protected function getSeoPromptOverrideKey(): string
    {
        return 'post_seo_system';
    }

    /**
     * The `content_type` discriminator phasea's `rewrite.seo_field` template
     * selects this subclass's instruction with (v2.6 design §2.5). It replaces
     * `buildSeoFieldPrompt()`, whose three branches were transcribed verbatim
     * into that template's variant table.
     */
    protected function getSeoContentTypeKey(): string
    {
        return 'post';
    }

    protected function buildModelContext(Model $model): array
    {
        $model->load(['tags', 'productCategory.translations']);

        // The package never imports the host's Post class (§3 seam), so these
        // Post-only attributes are read off the widened Model one at a time
        // and ignored individually rather than typed away.
        /** @phpstan-ignore property.notFound */
        $postId = $model->id;
        /** @phpstan-ignore property.notFound */
        $postTypeId = $model->post_type_id;
        /** @phpstan-ignore property.notFound */
        $postCategoryId = $model->post_category_id;
        /** @phpstan-ignore property.notFound */
        $author = $model->author;
        /** @phpstan-ignore property.notFound */
        $tags = $model->tags;
        /** @phpstan-ignore property.notFound */
        $productCategory = $model->productCategory;

        $context = [
            'post_id' => $postId,
            'post_type' => $postTypeId?->label() ?? 'Post',
            'post_category' => $postCategoryId?->label(),
            'product_category' => [],
            'tags' => $tags->pluck('name')->toArray(),
            'author' => $author,
            'translations' => [],
        ];

        // Collect product category names in all locales
        if ($productCategory) {
            foreach ($this->getLocales() as $locale) {
                $catTranslation = $productCategory->translate($locale);
                if ($catTranslation?->name) {
                    $context['product_category'][$locale] = $catTranslation->name;
                }
            }
        }

        // Collect existing translations for context
        if ($model instanceof Translatable) {
            foreach ($this->getLocales() as $locale) {
                $translation = $model->translate($locale);
                if ($translation) {
                    /** @phpstan-ignore property.notFound */
                    $title = $translation->title;
                    /** @phpstan-ignore property.notFound */
                    $excerpt = $translation->excerpt;
                    /** @phpstan-ignore property.notFound */
                    $mainText = $translation->main_text;

                    $context['translations'][$locale] = [
                        'title' => $title,
                        'excerpt' => $excerpt,
                        'main_text' => $this->truncateForContext($mainText),
                    ];
                }
            }
        }

        return $context;
    }

    protected function getFieldDescription(string $field): string
    {
        return match ($field) {
            'excerpt' => 'a concise post excerpt (2-3 sentences summarizing the key points)',
            'main_text' => 'a detailed blog/article content (well-structured with headings, paragraphs, and engaging content)',
            'seo_title' => 'an SEO title (max 60 characters, include main keyword)',
            'seo_description' => 'an SEO meta description (max 160 characters, compelling call-to-action)',
            'seo_keywords' => 'SEO keywords (5-10 relevant keywords, comma-separated)',
            default => "content for the {$field} field",
        };
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    protected function buildSeoContext(Model $model, string $locale, array $context): array
    {
        $translation = $model instanceof Translatable ? $model->translate($locale) : null;

        $seoContextLimit = (int) config('ai.assistant.content.seo_context_limit', 5000);

        return [
            'title' => $translation->title ?? '',
            'excerpt' => $translation->excerpt ?? '',
            'main_text' => $this->truncateForContext($translation->main_text ?? null, $seoContextLimit),
            'post_type' => $context['post_type'] ?? '',
            'post_category' => $context['post_category'] ?? '',
            'product_category' => $context['product_category'][$locale]
                ?? $context['product_category'][array_key_first($context['product_category'] ?? [])]
                ?? '',
            'tags' => $context['tags'] ?? [],
        ];
    }

    /**
     * Handle title/slug generation for a post.
     *
     * @param  array<string, mixed>  $context
     */
    protected function handleNameSlug(Model $model, string $locale, array $context): void
    {
        if (!$model instanceof Translatable) {
            return;
        }

        $translation = $model->translate($locale);
        $hasTitle = $translation && !empty($translation->title);
        $hasSlug = $translation && !empty($translation->slug);

        if ($hasTitle && $hasSlug) {
            return;
        }

        $title = null;

        if (!$hasTitle) {
            $title = $this->generateOrTranslateTitle($model, $locale, $context);
        } else {
            $title = $translation->title;
        }

        /** @phpstan-ignore property.notFound */
        $modelId = (int) $model->id;
        $slug = $hasSlug ? $translation->slug : $this->generateUniqueSlug($title, $modelId, $locale);

        $this->saveTranslation($model, 'title', $locale, $title);
        $this->saveTranslation($model, 'slug', $locale, $slug);
    }

    protected function findSourceLocale(Model $model, string $field): ?string
    {
        if (!$model instanceof Translatable) {
            return null;
        }

        foreach ($this->getLocales() as $locale) {
            $translation = $model->translate($locale);
            if ($translation && !empty($translation->{$field})) {
                return $locale;
            }
        }

        return null;
    }

    protected function slugExistsForLocale(string $slug, int $excludeId, string $locale): bool
    {
        $translationClass = app(ModelRegistry::class)->getModelClassOrFail('post_translation');

        return $translationClass::where('slug', $slug)
            ->where('locale', $locale)
            ->where('post_id', '!=', $excludeId)
            ->exists();
    }

    /**
     * Resolve the content archetype for a post.
     *
     * Uses the HasContentArchetype trait's resolution:
     * 1. Direct assignment on the post
     * 2. System default for Post type
     *
     * The host's HasContentArchetype trait is not exposed via a package-visible
     * contract, so it is called dynamically here — this package never imports
     * the host's Post class.
     */
    protected function resolveArchetype(Model $model): ?Model
    {
        if (!method_exists($model, 'resolveContentArchetype')) {
            return null;
        }

        $archetype = $model->resolveContentArchetype();

        return $archetype instanceof Model ? $archetype : null;
    }

    // ═══════════════════════════════════════════════════════════════════════
    // MEDIA GENERATION (Phase 5 Hook Override)
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Check if media generation is enabled for the current run.
     */
    private function isMediaGenerationEnabled(): bool
    {
        return !empty($this->mediaOptions) && ($this->mediaOptions['enabled'] ?? false);
    }

    /**
     * Process AI image generation for a post (Phase 5).
     *
     * @param  array<string, mixed>  $results
     */
    protected function processMediaGeneration(Model $model, array &$results): void
    {
        if (!$this->isMediaGenerationEnabled()) {
            return;
        }

        $model->load(['tags', 'productCategory.translations']);

        try {
            $service = app(GeneratesContentMedia::class);
            $service->processMediaGeneration($model, array_merge($this->mediaOptions, [
                'rich_text_field' => 'main_text',
                'gallery_collection' => 'gallery',
            ]));
            $results['processed_fields'][] = 'media_generation';
        } catch (\Exception $e) {
            $results['errors'][] = 'media_generation - '.$this->sanitizeErrorMessage($e);
            $this->logError($this->getServiceName().' - processMediaGeneration', $e);
        }
    }

    /**
     * The image-marker GATING decision, shared by the v2.6 parameter path and by
     * the legacy `getImageMarkerInstructions()` below, so the condition has one
     * home per subclass and cannot drift between them.
     */
    protected function imageMarkersEnabled(): bool
    {
        if (!$this->isMediaGenerationEnabled()) {
            return false;
        }

        return ($this->mediaOptions['target'] ?? 'gallery') !== 'gallery';
    }

    /**
     * Which phasea marker variant a post renders.
     */
    protected function imageMarkerVariant(): ?string
    {
        return 'post';
    }

    /**
     * Get [IMAGE:prompt] marker instructions for rich content main_text.
     *
     * D-48-4 — TRANSLATE ONLY, AND ONLY UNTIL PHASES 41–43 MIGRATE IT. The
     * rewrite, generate and unique-sections paths no longer read this: they send
     * `include_image_markers`/`image_marker_variant` and phasea's
     * `RewriteFormatInstructions` renders the block. The one remaining caller is
     * `getFormatInstructions()` <- `buildTranslatePrompt()`, which is the
     * translation carve-out this phase must not touch. When translation
     * migrates, this method and its sibling in `ProductContentRewriteService`
     * go with it.
     */
    protected function getImageMarkerInstructions(): string
    {
        if (!$this->imageMarkersEnabled()) {
            return '';
        }

        return <<<'INSTRUCTIONS'
- IMAGE MARKERS: Insert exactly 2 image markers in the main text at natural visual break points.
  Use this exact format: [IMAGE:descriptive prompt for the image]
  The prompt should describe a blog post illustration that relates to the surrounding text.
  Example: [IMAGE:professional illustration of modern warehouse management system with digital dashboard]
  Place markers between paragraphs, NOT inside HTML tags.
INSTRUCTIONS;
    }

    // ═══════════════════════════════════════════════════════════════════════
    // PRIVATE HELPERS
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Generate or translate title from source locale.
     *
     * @param  array<string, mixed>  $context
     */
    private function generateOrTranslateTitle(Model $post, string $locale, array $context): string
    {
        $sourceLocale = $this->findSourceLocale($post, 'title');

        if ($sourceLocale && $sourceLocale !== $locale && $post instanceof Translatable) {
            /** @phpstan-ignore property.notFound */
            $sourceTitle = (string) $post->translate($sourceLocale)?->title;

            return $this->translateText($sourceTitle, $sourceLocale, $locale);
        }

        return $this->generateTitleFromContext($locale, $context);
    }

    /**
     * Generate title from context when no source exists.
     *
     * @param  array<string, mixed>  $context
     */
    private function generateTitleFromContext(string $locale, array $context): string
    {
        $parts = [];

        // Add post type
        if ($context['post_type']) {
            $parts[] = $context['post_type'];
        }

        // Add product category if available
        $category = $context['product_category'][$locale]
            ?? $context['product_category'][array_key_first($context['product_category'] ?? [])]
            ?? null;
        if ($category) {
            $parts[] = $category;
        }

        // Add post_id for uniqueness
        $parts[] = 'Post '.$context['post_id'];

        return implode(' - ', $parts);
    }
}
