<?php

declare(strict_types=1);

namespace Core45\AiPosts\Services\BlogTopics;

use Astrotomic\Translatable\Contracts\Translatable;
use Core45\AiCore\DTOs\ImagePromptRequest;
use Core45\AiCore\Enums\AiSurface;
use Core45\AiCore\Services\AiClientFactory;
use Core45\AiCore\Services\RemoteAiClient;
use Core45\AiPosts\Concerns\ExtractsArticleContent;
use Core45\AiPosts\Enums\BlogTopicLlmStep;
use Core45\AiPosts\Models\BlogTopicCandidate;
use Core45\AiPosts\Models\BlogTopicSource;
use Core45\AiPosts\Services\ImageDirectorResolver;
use Core45\AiPosts\Services\ImagePromptDirector;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class BlogImageGeneratorService
{
    use ExtractsArticleContent;

    /** @var array<string, string> */
    private const ASPECT_RATIOS = [
        'featured_image' => '16:9',
        'gallery' => '4:3',
        'in_text' => '16:9',
    ];

    /**
     * The phasea-owned prompts this surface runs (v2.6 design §2.5).
     *
     * The three IMAGE templates replace the local `build*ImagePrompt()`
     * builders: an image prompt is authored instruction text just like a
     * completion prompt, it just cannot ride `runPrompt()` because that returns
     * text. `blog_topics.image_alt_text` is an ordinary text template.
     */
    private const FEATURED_IMAGE_TEMPLATE = 'blog_topics.featured_image';

    private const GALLERY_IMAGE_TEMPLATE = 'blog_topics.gallery_image';

    private const IN_TEXT_IMAGE_TEMPLATE = 'blog_topics.in_text_image';

    private const ALT_TEXT_TEMPLATE = 'blog_topics.image_alt_text';

    /**
     * Host-side caps applied before parameters leave this repo.
     *
     * COST controls that also keep the payload inside the bounds phasea's
     * templates declare — those bounds are the untrusted-input guard (D-48-2),
     * these are the thing that stops a pathological source snippet from
     * turning image generation into a 422.
     */
    private const MAX_TITLE_CHARS = 500;

    private const MAX_CONTEXT_CHARS = 2000;

    public function __construct(
        private readonly LlmAuditService $auditService,
        private readonly ImagePromptDirector $director,
        private readonly ImageDirectorResolver $resolver,
    ) {}

    /**
     * Generate images for a blog post based on image config.
     *
     * @param  array{featured_image?: bool, gallery?: array{enabled: bool, count: int}, in_text?: array{enabled: bool, count: int}, style?: string|null, profile_id?: int|null}|null  $imageConfig
     * @return array{media: Collection<int, Media>, in_text_images: array<int, array{uuid: string, alt: string, section_index?: int|null}>}
     */
    public function generateImages(Model $post, BlogTopicCandidate $candidate, ?array $imageConfig = null): array
    {
        if (!config('ai.assistant.blog_topics.images.enabled', false)) {
            return ['media' => collect(), 'in_text_images' => []];
        }

        // Backward compatibility: if no image_config, fall back to old behavior (1 featured image)
        if ($imageConfig === null) {
            $imageConfig = [
                'featured_image' => true,
                'gallery' => ['enabled' => false, 'count' => 0],
                'in_text' => ['enabled' => false, 'count' => 0],
            ];
        }

        // Resolve counts up front (used for both plan request and loop bounds).
        $featuredEnabled = (bool) ($imageConfig['featured_image'] ?? false);
        $galleryConfig = $imageConfig['gallery'] ?? ['enabled' => false, 'count' => 0];
        $galleryCount = $galleryConfig['enabled'] ? min((int) $galleryConfig['count'], 8) : 0;
        $inTextConfig = $imageConfig['in_text'] ?? ['enabled' => false, 'count' => 0];
        $inTextCount = $inTextConfig['enabled'] ? min((int) $inTextConfig['count'], 5) : 0;
        $explicitProfileId = isset($imageConfig['profile_id']) ? (int) $imageConfig['profile_id'] : null;
        ['profile' => $profile, 'style' => $style] = $this->resolver->resolve($post, $explicitProfileId, $imageConfig['style'] ?? null);

        // Build ONE plan from the generated post body when any images are requested.
        $plan = null;
        if ($featuredEnabled || $galleryCount > 0 || $inTextCount > 0) {
            $primaryLocale = (string) (config('translatable.locales', ['en'])[0] ?? 'en');
            /** @var Model|null $translation */
            $translation = $post instanceof Translatable ? $post->translate($primaryLocale) : null;
            $mainText = $translation !== null ? (string) $translation->main_text : ''; // @phpstan-ignore property.notFound
            $postTitle = $translation !== null ? (string) $translation->title : ($candidate->title ?? ''); // @phpstan-ignore property.notFound
            $postExcerpt = $translation !== null ? (string) $translation->excerpt : ($candidate->rationale ?? ''); // @phpstan-ignore property.notFound

            $plan = $this->director->plan(new ImagePromptRequest(
                title: $postTitle,
                excerpt: $postExcerpt,
                bodyPlain: $this->stripAndCap($mainText),
                headings: $this->extractHeadings($mainText),
                locale: $primaryLocale,
                featured: $featuredEnabled,
                gallery: $galleryCount,
                inText: $inTextCount,
                entityContext: $candidate->title ?? null,
                profile: $profile,
                style: $style,
            ));
        }

        $generatedMedia = collect();
        $inTextImages = [];

        // Featured image (goes to `featured_image` collection — single file)
        if ($featuredEnabled) {
            $featuredItem = $plan?->featured;
            $altText = $featuredItem !== null ? $featuredItem->alt : $this->generateAltText(
                $candidate->title ?? '',
                $candidate->rationale ?? $candidate->title ?? '',
            );
            $media = $this->generateSingleImage(
                $post,
                $candidate,
                'featured_image',
                'featured_image',
                $featuredItem !== null ? $featuredItem->template : self::FEATURED_IMAGE_TEMPLATE,
                $featuredItem !== null ? $featuredItem->parameters : ['title' => $this->cap($candidate->title, self::MAX_TITLE_CHARS)],
                $altText,
            );

            if ($media !== null) {
                $generatedMedia->push($media);
            }
        }

        // Gallery images (go to `gallery` collection)
        if ($galleryCount > 0) {
            $sourceContexts = $this->extractSourceContexts($candidate);

            for ($i = 0; $i < $galleryCount; $i++) {
                $context = $sourceContexts[$i] ?? ($candidate->rationale ?? $candidate->title ?? '');
                $galleryItem = $plan !== null ? ($plan->gallery[$i] ?? null) : null;
                $altText = $galleryItem !== null ? $galleryItem->alt : $this->generateAltText($candidate->title ?? '', $context);
                $media = $this->generateSingleImage(
                    $post,
                    $candidate,
                    'gallery',
                    'gallery',
                    $galleryItem !== null ? $galleryItem->template : self::GALLERY_IMAGE_TEMPLATE,
                    $galleryItem !== null ? $galleryItem->parameters : [
                        'title' => $this->cap($candidate->title, self::MAX_TITLE_CHARS),
                        'context' => $this->cap($context, self::MAX_CONTEXT_CHARS),
                        'number' => $i + 1,
                    ],
                    $altText,
                );

                if ($media !== null) {
                    $generatedMedia->push($media);
                }
            }
        }

        // In-text images (go to `{inTextLocale}.main_text` collection, embedded via data-id)
        if ($inTextCount > 0) {
            $inTextLocale = (string) config('app.locale', 'en');
            $collection = "{$inTextLocale}.main_text";
            $sourceContexts = $this->extractSourceContexts($candidate);

            for ($i = 0; $i < $inTextCount; $i++) {
                $context = $sourceContexts[$i] ?? ($candidate->rationale ?? $candidate->title ?? '');
                $inTextItem = $plan !== null ? ($plan->inText[$i] ?? null) : null;
                $altText = $inTextItem !== null ? $inTextItem->alt : $this->generateAltText($candidate->title ?? '', $context);
                $media = $this->generateSingleImage(
                    $post,
                    $candidate,
                    'in_text',
                    $collection,
                    $inTextItem !== null ? $inTextItem->template : self::IN_TEXT_IMAGE_TEMPLATE,
                    $inTextItem !== null ? $inTextItem->parameters : [
                        'title' => $this->cap($candidate->title, self::MAX_TITLE_CHARS),
                        'context' => $this->cap($context, self::MAX_CONTEXT_CHARS),
                    ],
                    $altText,
                );

                if ($media !== null) {
                    $generatedMedia->push($media);
                    $inTextImages[] = [
                        'uuid' => $media->uuid,
                        'alt' => $altText,
                        'section_index' => $inTextItem?->sectionIndex,
                    ];
                }
            }
        }

        return ['media' => $generatedMedia, 'in_text_images' => $inTextImages];
    }

    /**
     * Generate a single image and store it in the given media collection.
     *
     * ALWAYS `$promptTemplate` + `$templateParameters` — a phasea-owned image
     * prompt (v2.6 §2.5). The literal-`prompt` alternative this method used to
     * accept is gone: it carried the instruction `ImagePromptDirector` composed
     * host-side, and since plan 48-19 the director returns a template key and
     * parameters too, so no path here has a prompt string to send.
     *
     * @param  array<string, mixed>  $templateParameters
     */
    private function generateSingleImage(
        Model $post,
        BlogTopicCandidate $candidate,
        string $imageType,
        string $mediaCollection,
        string $promptTemplate,
        array $templateParameters,
        string $altText,
    ): ?Media {
        try {
            $imageData = $this->callImageGenerationApi(
                $promptTemplate,
                $templateParameters,
                self::ASPECT_RATIOS[$imageType] ?? '16:9',
            );
            if ($imageData === null) {
                return null;
            }

            $tempPath = 'temp/blog-images/'.uniqid('img_', true).'.png';
            Storage::disk('local')->put($tempPath, $imageData);
            $fullPath = Storage::disk('local')->path($tempPath);

            $media = $post->addMedia($fullPath) // @phpstan-ignore method.notFound
                ->usingFileName(uniqid('blog_', true).'.png')
                ->withCustomProperties(['alt' => $altText])
                ->toMediaCollection($mediaCollection);

            Storage::disk('local')->delete($tempPath);

            return $media;
        } catch (\Throwable $e) {
            Log::warning('BlogImageGeneratorService: Failed to generate image', [
                'post_id' => $post->id, // @phpstan-ignore property.notFound
                'candidate_id' => $candidate->id,
                'type' => $imageType,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Extract contextual descriptions from research sources.
     *
     * @return array<int, string>
     */
    private function extractSourceContexts(BlogTopicCandidate $candidate): array
    {
        $sources = BlogTopicSource::where('batch_id', $candidate->batch_id)->get();
        $contexts = [];

        foreach ($sources as $source) {
            $context = $source->title ?? $source->snippet ?? '';
            if ($context !== '') {
                $contexts[] = $context;
            }
        }

        return $contexts;
    }

    /**
     * Cap a value before it leaves this host as a template parameter.
     *
     * A COST control that also keeps the payload inside the bound phasea's
     * template declares — that bound is the untrusted-input guard (D-48-2), and
     * without this cap a pathological source snippet would turn a generated
     * image into a validation error.
     */
    private function cap(?string $value, int $maxCharacters): string
    {
        $value = $value ?? '';

        return mb_strlen($value) > $maxCharacters ? mb_substr($value, 0, $maxCharacters) : $value;
    }

    /**
     * Generate alt text for an image through the phasea-owned prompt.
     *
     * v2.6 §2.5: the accessibility instruction lives in phasea's
     * `BlogTopicsImageAltTextPrompt`; this host names it and sends the post
     * title plus the image's context. The surface stays `AiSurface::BlogTopics`
     * (NOT `Images`) — alt text is text generation, and the template's own
     * `task()` matches, so routing and billing are unchanged.
     */
    protected function generateAltText(string $postTitle, string $context): string
    {
        // v2.6: phasea routes this task; the host cannot know the model.
        // AiTextResult carries only text + tokens. Record the truthful provider
        // and an explicit unknown rather than a config guess.
        $provider = 'phasea';
        $model = 'unknown';

        $parameters = [
            'post_title' => $this->cap($postTitle, self::MAX_TITLE_CHARS),
            'context' => $this->cap($context, self::MAX_CONTEXT_CHARS),
        ];

        $startTime = microtime(true);

        $client = app(AiClientFactory::class)->for(AiSurface::BlogTopics);
        $result = $client->runPrompt(
            self::ALT_TEXT_TEMPLATE,
            $parameters,
            (string) Str::uuid(),
            // Transport-only, and NOT optional: this call has always run on the
            // assistant budget rather than the 30s service-wide default.
            [
                'timeout' => config('ai.assistant.timeout', 300),
            ],
        );

        $durationMs = (int) ((microtime(true) - $startTime) * 1000);

        $this->auditService->log(
            step: BlogTopicLlmStep::ImageAltText,
            provider: $provider,
            model: $model,
            // D-48-6: the prompt lives in phasea now, so there is none to
            // record here. The template key plus the parameters IS the
            // reproducible input.
            prompt: json_encode([
                'template' => self::ALT_TEXT_TEMPLATE,
                'parameters' => $parameters,
            ], JSON_UNESCAPED_UNICODE) ?: self::ALT_TEXT_TEMPLATE,
            response: $result->text,
            tokensIn: $result->promptTokens,
            tokensOut: $result->completionTokens,
            durationMs: $durationMs,
        );

        return trim($result->text);
    }

    /**
     * Generate image bytes through the AiClient seam (Phase 32, audit C3).
     *
     * `RemoteAiClient::generateImageFromTemplate()` posts phasea's `api/ai/image/generate`
     * (forwarding the aspect ratio) and performs the secondary byte-fetch of
     * the returned URL — the same flow `GeneratePostPhotosJob`/
     * `AIMediaGenerationService` use. The direct in-process Gemini
     * `generateContent` call this method previously made is retired; phasea
     * holds the vendor key and gateway selection.
     *
     * v2.6: the host no longer sends `model`. It was the last surface where
     * phaseb chose a model and phasea obeyed (ImageController validated and
     * forwarded it). phasea now resolves the image model itself from
     * config('ai.image.model'), whose default is the same
     * `gemini-2.5-flash-image` this used to send — so behaviour is unchanged
     * while the decision moves to the service that owns it.
     *
     * v2.6 §2.5 (phase 48): the request carries `prompt_template` + `parameters`
     * and NO instruction text at all. The literal-`prompt` alternative was
     * removed by plan 48-19 — it existed only for the prompts the
     * `ImagePromptDirector` composed per post, and the director's decoration and
     * fallback wording both moved to phasea, so nothing here holds a prompt
     * string to send.
     *
     * Returns raw image bytes, or null on any failure (the caller treats a
     * null as "skip this image" — observable behavior unchanged).
     *
     * @param  array<string, mixed>  $templateParameters
     */
    protected function callImageGenerationApi(
        string $promptTemplate,
        array $templateParameters = [],
        string $aspectRatio = '16:9',
    ): ?string {
        $params = [
            'aspect_ratio' => $aspectRatio,
            'timeout' => (int) config('ai.assistant.timeout', 300),
        ];

        try {
            $client = app(AiClientFactory::class)->for(AiSurface::Images);

            $result = $client->generateImageFromTemplate($promptTemplate, $templateParameters, $params, (string) Str::uuid());
        } catch (\Throwable $e) {
            Log::warning('BlogImageGeneratorService: remote image generation failed', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        $bytes = $result['bytes'] ?? null;

        return is_string($bytes) && $bytes !== '' ? $bytes : null;
    }
}
