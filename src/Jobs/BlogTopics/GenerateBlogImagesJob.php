<?php

declare(strict_types=1);

namespace Core45\AiPosts\Jobs\BlogTopics;

use Astrotomic\Translatable\Contracts\Translatable;
use Core45\AiPosts\Concerns\ExtractsArticleContent;
use Core45\AiPosts\Jobs\BlogTopics\Concerns\NotifiesUser;
use Core45\AiPosts\Models\BlogTopicCandidate;
use Core45\AiPosts\Services\BlogTopics\BlogImageGeneratorService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class GenerateBlogImagesJob implements ShouldBeUnique, ShouldQueue
{
    use ExtractsArticleContent;
    use NotifiesUser;
    use Queueable;

    public int $tries = 3;

    public int $timeout = 300;

    /** @var array<int, int> */
    public array $backoff = [15, 30, 60];

    public function __construct(
        public Model $post,
        public BlogTopicCandidate $candidate,
        public ?int $userId = null,
    ) {
        $this->onQueue('ai-bulk');
    }

    public function uniqueId(): int
    {
        return (int) $this->post->getKey();
    }

    public function handle(BlogImageGeneratorService $imageService): void
    {
        if (!config('ai.assistant.blog_topics.images.enabled', false)) {
            return;
        }

        if (!$this->candidate->generate_images) {
            return;
        }

        /** @var array{featured_image?: bool, gallery?: array{enabled: bool, count: int}, in_text?: array{enabled: bool, count: int}}|null $imageConfig */
        $imageConfig = $this->candidate->image_config;

        $result = $imageService->generateImages($this->post, $this->candidate, $imageConfig);
        $generatedMedia = $result['media'];
        $inTextImages = $result['in_text_images'];

        // Embed in-text images into main_text HTML via data-id attributes
        if (!empty($inTextImages)) {
            $this->embedInTextImages($inTextImages);
        }

        if ($generatedMedia->isEmpty()) {
            Log::info('GenerateBlogImagesJob: No images generated', [
                'post_id' => $this->post->getKey(),
                'candidate_id' => $this->candidate->id,
            ]);

            $this->notifyUser(
                __('Image generation completed — no images were produced'),
                __('The image API did not return any images for post: :title', [
                    'title' => $this->candidate->title,
                ]),
                'info',
            );

            return;
        }

        Log::info('GenerateBlogImagesJob: Images generated', [
            'post_id' => $this->post->getKey(),
            'candidate_id' => $this->candidate->id,
            'count' => $generatedMedia->count(),
            'in_text_count' => count($inTextImages),
        ]);

        $this->notifyUser(
            __('Images generated for blog post: :title', [
                'title' => $this->candidate->title,
            ]),
            __(':count images generated and attached to the post.', [
                'count' => $generatedMedia->count(),
            ]),
            'success',
        );
    }

    /**
     * Embed in-text images into the post's main_text HTML using data-id UUID references.
     *
     * @param  array<int, array{uuid: string, alt: string, section_index?: int|null}>  $inTextImages
     */
    private function embedInTextImages(array $inTextImages): void
    {
        $locale = (string) config('app.locale', 'en');
        $translation = $this->post instanceof Translatable ? $this->post->translate($locale) : null;

        if ($translation === null || empty($translation->main_text)) {
            return;
        }

        $translation->main_text = $this->embedFiguresIntoHtml($translation->main_text, $inTextImages);
        $translation->save();
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('GenerateBlogImagesJob: Failed', [
            'post_id' => $this->post->getKey(),
            'candidate_id' => $this->candidate->id,
            'error' => $exception->getMessage(),
        ]);
    }
}
