<?php

declare(strict_types=1);

namespace Core45\AiPosts\Jobs;

use Astrotomic\Translatable\Contracts\Translatable;
use Core45\AiCore\Contracts\AiClient;
use Core45\AiCore\Contracts\NotifiesOperator;
use Core45\AiCore\DTOs\ImagePromptPlan;
use Core45\AiCore\DTOs\ImagePromptRequest;
use Core45\AiCore\Enums\AiSurface;
use Core45\AiCore\Enums\NotificationSeverity;
use Core45\AiCore\ModelRegistry;
use Core45\AiCore\Services\AiClientFactory;
use Core45\AiPosts\Concerns\ExtractsArticleContent;
use Core45\AiPosts\Services\ImageDirectorResolver;
use Core45\AiPosts\Services\ImagePromptDirector;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\HasMedia;

/**
 * The Images surface's first real `AiClient` consumer (Phase 8, plan
 * 08-16): image generation routes through `AiClientFactory::for(AiSurface::
 * Images)` instead of directly resolving `ImageGeneratorContract`.
 */
class GeneratePostPhotosJob implements ShouldBeUnique, ShouldQueue
{
    use ExtractsArticleContent;
    use Queueable;

    /**
     * Minted once in the constructor and reused across Horizon retries
     * (Pitfall 5) — never re-minted per attempt.
     */
    public readonly string $operationId;

    public int $tries = 3;

    public int $timeout = 600;

    public int $uniqueFor = 660;

    /** @var array<int, int> */
    public array $backoff = [15, 30, 60];

    private const int GALLERY_COUNT = 3;

    private const int IN_TEXT_COUNT = 2;

    private const string FEATURED_ASPECT_RATIO = '16:9';

    private const string GALLERY_ASPECT_RATIO = '4:3';

    private const string IN_TEXT_ASPECT_RATIO = '16:9';

    private ?ImagePromptPlan $plan = null;

    private ?ImagePromptRequest $request = null;

    private ?ImagePromptDirector $director = null;

    private ?Model $resolvedProfile = null;

    private ?Model $resolvedStyle = null;

    private ?string $firstFailureReason = null;

    public function __construct(
        public Model $post,
        public bool $generateFeatured,
        public bool $generateGallery,
        public bool $generateInText,
        public bool $replaceExisting,
        public ?int $profileId = null,
        public ?string $styleSlug = null,
        public ?int $userId = null,
    ) {
        $this->onQueue('ai-bulk');
        $this->operationId = (string) Str::uuid();
    }

    public function uniqueId(): int
    {
        return (int) $this->post->getKey();
    }

    /**
     * Narrow the post to the Spatie media contract before touching AI-generated
     * photos — the model is typed against `Model` for the AI-package boundary,
     * but every host post model implements {@see HasMedia}.
     */
    private function media(): Model&HasMedia
    {
        if (!$this->post instanceof HasMedia) {
            throw new \LogicException('GeneratePostPhotosJob: post model does not implement HasMedia.');
        }

        return $this->post;
    }

    /**
     * Guard the Astrotomic `translate()` call behind the seam — the model is
     * typed against `Model`, so callers cannot assume Translatable.
     */
    private function translation(string $locale): ?Model
    {
        if (!$this->post instanceof Translatable) {
            return null;
        }

        return $this->post->translate($locale);
    }

    public function handle(AiClientFactory $factory, ?ImagePromptDirector $director = null, ?ImageDirectorResolver $resolver = null): void
    {
        $client = $factory->for(AiSurface::Images);

        $director ??= app(ImagePromptDirector::class);
        $resolver ??= app(ImageDirectorResolver::class);

        ['profile' => $this->resolvedProfile, 'style' => $this->resolvedStyle] = $resolver->resolve(
            $this->post,
            $this->profileId,
            $this->styleSlug,
        );

        $this->plan = $this->buildPlan(
            featured: $this->generateFeatured ? 1 : 0,
            gallery: $this->generateGallery ? self::GALLERY_COUNT : 0,
            inText: $this->generateInText ? self::IN_TEXT_COUNT : 0,
            director: $director,
        );

        $generated = 0;

        if ($this->generateFeatured) {
            $generated += $this->processFeaturedImage($client);
        }

        if ($this->generateGallery) {
            $generated += $this->processGalleryImages($client);
        }

        if ($this->generateInText) {
            $generated += $this->processInTextImages($client);
        }

        $this->notifyUser($generated);
    }

    private function resolveCanonicalLocale(): ?string
    {
        /** @var array<string> $locales */
        $locales = config('translatable.locales', ['en']);

        foreach ($locales as $locale) {
            $translation = $this->translation($locale);
            if ($translation !== null && !empty($translation->main_text)) {
                return $locale;
            }
        }

        foreach ($locales as $locale) {
            $translation = $this->translation($locale);
            if ($translation !== null && !empty($translation->title)) {
                return $locale;
            }
        }

        return null;
    }

    private function buildPlan(int $featured, int $gallery, int $inText, ImagePromptDirector $director): ImagePromptPlan
    {
        $this->director = $director;
        $locale = $this->resolveCanonicalLocale() ?? config('app.locale', 'en');
        $translation = $this->translation((string) $locale);

        $mainText = $translation !== null ? (string) $translation->main_text : ''; // @phpstan-ignore property.notFound

        $request = new ImagePromptRequest(
            title: $this->getPostTitle(),
            excerpt: $this->getPostExcerpt(),
            bodyPlain: $this->stripAndCap($mainText),
            headings: $this->extractHeadings($mainText),
            locale: (string) $locale,
            featured: $featured > 0,
            gallery: $gallery,
            inText: $inText,
            profile: $this->resolvedProfile,
            style: $this->resolvedStyle,
        );

        $this->request = $request;

        return $director->plan($request);
    }

    /**
     * The image director and the request that produced the plan. Both are set in
     * {@see buildPlan()} before any image is processed; the coalesce is a
     * PHPStan-satisfying guard for a state {@see handle()} never leaves them in.
     * They back the defensive fallback arm — when the director's own plan
     * unexpectedly omits a requested slot — which delegates to the director's
     * public `fallback*()` methods instead of re-authoring a prompt here.
     */
    private function fallbackDirector(): ImagePromptDirector
    {
        return $this->director ?? app(ImagePromptDirector::class);
    }

    private function fallbackRequest(): ImagePromptRequest
    {
        return $this->request ?? throw new \LogicException(
            'GeneratePostPhotosJob: image prompt request accessed before the plan was built.',
        );
    }

    /**
     * Remember the first image-generation failure so the zero-generated
     * notification can tell the admin *why* nothing was produced.
     */
    private function recordFailure(\Throwable $e): void
    {
        $this->firstFailureReason ??= $e->getMessage();
    }

    private function processFeaturedImage(AiClient $client): int
    {
        if (!$this->replaceExisting && $this->media()->getMedia('featured_image')->first() !== null) {
            return 0;
        }

        $featuredItem = $this->plan?->featured;
        if ($featuredItem === null) {
            $featuredItem = $this->fallbackDirector()->fallbackFeatured($this->fallbackRequest());
        }

        $promptRecord = ImagePromptDirector::promptRecord($featuredItem->template, $featuredItem->parameters);

        try {
            $result = $client->generateImageFromTemplate($featuredItem->template, $featuredItem->parameters, [
                'aspect_ratio' => self::FEATURED_ASPECT_RATIO,
            ], $this->operationId);

            $extension = $this->getExtension($result['mime_type']);

            $this->media()
                // @phpstan-ignore-next-line method.notFound (addMediaFromString is an InteractsWithMedia trait method, not part of the HasMedia contract)
                ->addMediaFromString($result['bytes'])
                ->usingFileName(Str::ulid().'.'.$extension)
                ->usingName('AI Generated Featured Image')
                ->withCustomProperties([
                    'ai_generated' => true,
                    'source' => $result['provider'],
                    'prompt' => $promptRecord,
                ])
                ->toMediaCollection('featured_image');

            return 1;
        } catch (\Exception $e) {
            $this->recordFailure($e);
            Log::warning('GeneratePostPhotosJob: Featured image failed', [
                'post_id' => $this->post->getKey(),
                'error' => $e->getMessage(),
            ]);

            return 0;
        }
    }

    private function processGalleryImages(AiClient $client): int
    {
        if ($this->replaceExisting) {
            $this->media()->clearMediaCollection('gallery');
        }

        $generated = 0;

        for ($i = 1; $i <= self::GALLERY_COUNT; $i++) {
            $gItem = $this->plan?->gallery[$i - 1] ?? null;
            if ($gItem === null) {
                $gItem = $this->fallbackDirector()->fallbackGallery($this->fallbackRequest(), $i);
            }

            $promptRecord = ImagePromptDirector::promptRecord($gItem->template, $gItem->parameters);

            try {
                $result = $client->generateImageFromTemplate($gItem->template, $gItem->parameters, [
                    'aspect_ratio' => self::GALLERY_ASPECT_RATIO,
                ], $this->operationId);

                $extension = $this->getExtension($result['mime_type']);

                $this->media()
                    // @phpstan-ignore-next-line method.notFound (addMediaFromString is an InteractsWithMedia trait method, not part of the HasMedia contract)
                    ->addMediaFromString($result['bytes'])
                    ->usingFileName(Str::ulid().'.'.$extension)
                    ->usingName("AI Generated Gallery {$i}/".self::GALLERY_COUNT)
                    ->withCustomProperties([
                        'ai_generated' => true,
                        'source' => $result['provider'],
                        'prompt' => $promptRecord,
                    ])
                    ->toMediaCollection('gallery');

                $generated++;
            } catch (\Exception $e) {
                $this->recordFailure($e);
                Log::warning("GeneratePostPhotosJob: Gallery image {$i}/".self::GALLERY_COUNT.' failed', [
                    'post_id' => $this->post->getKey(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $generated;
    }

    private function processInTextImages(AiClient $client): int
    {
        /** @var array<string> $locales */
        $locales = config('translatable.locales', ['en']);
        $localesWithContent = [];

        foreach ($locales as $locale) {
            $translation = $this->translation($locale);
            if ($translation !== null && !empty($translation->main_text)) {
                $localesWithContent[] = $locale;
            }
        }

        if ($localesWithContent === []) {
            return 0;
        }

        if ($this->replaceExisting) {
            foreach ($localesWithContent as $locale) {
                $collection = "{$locale}.main_text";
                $translation = $this->translation($locale);
                if ($translation === null) {
                    continue;
                }
                $translation->main_text = $this->stripExistingInTextImages($translation->main_text, $collection); // @phpstan-ignore property.notFound, property.notFound
                $translation->save();
            }
        }

        $inTextImages = [];

        for ($i = 1; $i <= self::IN_TEXT_COUNT; $i++) {
            $item = $this->plan?->inText[$i - 1] ?? null;
            // The ALT stays the host's title-derived metadata on the fallback arm
            // (`buildAltText()` — not a prompt, kept by design), so the item is
            // read for the prompt only.
            $altText = $item !== null ? $item->alt : $this->buildAltText($i);
            $sectionIndex = $item?->sectionIndex;

            if ($item === null) {
                $item = $this->fallbackDirector()->fallbackInText($this->fallbackRequest(), $i);
            }

            $promptRecord = ImagePromptDirector::promptRecord($item->template, $item->parameters);

            try {
                $result = $client->generateImageFromTemplate($item->template, $item->parameters, [
                    'aspect_ratio' => self::IN_TEXT_ASPECT_RATIO,
                ], $this->operationId);

                $extension = $this->getExtension($result['mime_type']);

                $generatedMedia = [];
                foreach ($localesWithContent as $locale) {
                    $collection = "{$locale}.main_text";

                    $media = $this->media()
                        // @phpstan-ignore-next-line method.notFound (addMediaFromString is an InteractsWithMedia trait method, not part of the HasMedia contract)
                        ->addMediaFromString($result['bytes'])
                        ->usingFileName(Str::ulid().'.'.$extension)
                        ->usingName("AI Generated In-Text {$i}")
                        ->withCustomProperties([
                            'ai_generated' => true,
                            'source' => $result['provider'],
                            'prompt' => $promptRecord,
                            'alt' => $altText,
                        ])
                        ->toMediaCollection($collection);

                    $generatedMedia[$locale] = $media->uuid;
                }

                $inTextImages[] = [
                    'uuids' => $generatedMedia,
                    'alt' => $altText,
                    'section_index' => $sectionIndex,
                ];
            } catch (\Exception $e) {
                $this->recordFailure($e);
                Log::warning("GeneratePostPhotosJob: In-text image {$i} failed", [
                    'post_id' => $this->post->getKey(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($inTextImages !== []) {
            foreach ($localesWithContent as $locale) {
                $localeImages = [];
                foreach ($inTextImages as $image) {
                    if (isset($image['uuids'][$locale])) {
                        $localeImages[] = [
                            'uuid' => $image['uuids'][$locale],
                            'alt' => $image['alt'],
                            'section_index' => $image['section_index'],
                        ];
                    }
                }

                if ($localeImages !== []) {
                    $this->embedInTextImages($localeImages, $locale);
                }
            }
        }

        return count($inTextImages);
    }

    private function stripExistingInTextImages(string $html, string $collection): string
    {
        $existingMedia = $this->media()->getMedia($collection);

        foreach ($existingMedia as $media) {
            $uuid = preg_quote($media->uuid, '/');

            $html = (string) preg_replace(
                '/\s*<figure>\s*<img\s[^>]*data-id="'.$uuid.'"[^>]*>\s*<\/figure>\s*/s',
                '',
                $html,
            );

            $html = (string) preg_replace(
                '/\s*<img\s[^>]*data-id="'.$uuid.'"[^>]*>\s*/s',
                '',
                $html,
            );
        }

        $this->media()->clearMediaCollection($collection);

        return $html;
    }

    /**
     * @param  array<int, array{uuid: string, alt: string, section_index?: int|null}>  $inTextImages
     */
    private function embedInTextImages(array $inTextImages, string $locale): void
    {
        $this->post->refresh();
        $translation = $this->translation($locale);

        if ($translation === null || empty($translation->main_text)) {
            return;
        }

        $translation->main_text = $this->embedFiguresIntoHtml($translation->main_text, $inTextImages);
        $translation->save();
    }

    private function getPostTitle(): string
    {
        /** @var array<string> $locales */
        $locales = config('translatable.locales', ['en']);

        foreach ($locales as $locale) {
            $translation = $this->translation($locale);
            if ($translation !== null && !empty($translation->title)) {
                return $translation->title;
            }
        }

        return "Post #{$this->post->getKey()}";
    }

    private function getPostExcerpt(): string
    {
        /** @var array<string> $locales */
        $locales = config('translatable.locales', ['en']);

        foreach ($locales as $locale) {
            $translation = $this->translation($locale);
            if ($translation !== null && !empty($translation->excerpt)) {
                return Str::limit($translation->excerpt, 200);
            }
        }

        return '';
    }

    private function buildAltText(int $imageNumber): string
    {
        $title = $this->getPostTitle();

        return Str::limit("{$title} - illustration {$imageNumber}", 125);
    }

    private function getExtension(string $mimeType): string
    {
        return match ($mimeType) {
            'image/jpeg', 'image/jpg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => 'png',
        };
    }

    private function notifyUser(int $generated): void
    {
        if ($this->userId === null) {
            return;
        }

        $userClass = app(ModelRegistry::class)->getModelClassOrFail('user');
        $user = $userClass::find($this->userId);
        if ($user === null) {
            return;
        }

        $title = $this->getPostTitle();

        if ($generated === 0) {
            $body = __('No photos were generated for post: :title', ['title' => Str::limit($title, 80)]);

            if ($this->firstFailureReason !== null) {
                $body .= ' '.__('Reason: :reason', ['reason' => Str::limit($this->firstFailureReason, 200)]);
            }

            app(NotifiesOperator::class)->notifyOperator(
                $user,
                __('Photo generation completed'),
                $body,
                NotificationSeverity::Warning,
            );

            return;
        }

        app(NotifiesOperator::class)->notifyOperator(
            $user,
            __('Photos generated for post: :title', ['title' => Str::limit($title, 60)]),
            __(':count photos generated and attached to the post.', ['count' => $generated]),
            NotificationSeverity::Success,
        );
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('GeneratePostPhotosJob: Failed', [
            'post_id' => $this->post->getKey(),
            'error' => $exception->getMessage(),
        ]);
    }
}
