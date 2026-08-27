<?php

declare(strict_types=1);

namespace Core45\AiPosts\Jobs\BlogTopics;

use Astrotomic\Translatable\Contracts\Translatable;
use Core45\AiCore\Contracts\AiClient;
use Core45\AiCore\Contracts\GeneratesSlugs;
use Core45\AiCore\Exceptions\AiRateLimitedException;
use Core45\AiCore\Exceptions\AiUnavailableException;
use Core45\AiCore\ModelRegistry;
use Core45\AiPosts\Enums\BlogTopicCandidateStatus;
use Core45\AiPosts\Jobs\BlogTopics\Concerns\NotifiesUser;
use Core45\AiPosts\Jobs\TranslatePostToLocale;
use Core45\AiPosts\Models\BlogProductSeries;
use Core45\AiPosts\Models\BlogTopicCandidate;
use Core45\AiPosts\Models\BlogTopicProfile;
use Core45\AiPosts\Services\BlogPipeline\AuthorNameGenerator;
use Core45\AiPosts\Services\BlogTopics\BlogPostWriterService;
use Core45\AiPosts\Services\BlogTopics\CitationGuardService;
use Core45\AiPosts\Support\HostJobs;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * @deprecated Use Core45\AiPosts\Jobs\BlogPipeline\StartPipelineJob instead.
 *             This job is retained for rollback safety. It will be removed
 *             once the new pipeline is fully validated in production.
 */
class GenerateBlogPostJob implements ShouldBeUnique, ShouldQueue
{
    use NotifiesUser;
    use Queueable;

    public int $tries = 10;

    public int $maxExceptions = 3;

    /** @var array<int, int> */
    public array $backoff = [10, 30, 60];

    public int $timeout = 600;

    public readonly string $operationId;

    public function __construct(
        public BlogTopicCandidate $candidate,
        public ?int $userId = null,
    ) {
        $this->onQueue('ai-bulk');
        $this->operationId = (string) Str::uuid();
    }

    public function uniqueId(): int
    {
        return $this->candidate->id;
    }

    /**
     * NOTE: `AiClient` is injected as a documented seam placeholder.
     * `BlogPostWriterService::write()` owns its own seam call (routes through
     * `completeText()`); `operationId` is minted once per dispatch and
     * logged, but not forwarded into the service.
     */
    public function handle(BlogPostWriterService $writerService, CitationGuardService $citationGuard, AiClient $client): void
    {
        Log::warning('GenerateBlogPostJob is deprecated. Use the new blog pipeline (StartPipelineJob) instead.', [
            'candidate_id' => $this->candidate->id,
            'operation_id' => $this->operationId,
        ]);

        // Guard: only proceed if candidate is approved or already set to generating (retry)
        $this->candidate->refresh();
        /** @var BlogTopicCandidateStatus $candidateStatus */
        $candidateStatus = $this->candidate->status;
        if (!in_array($candidateStatus, [BlogTopicCandidateStatus::Approved, BlogTopicCandidateStatus::Generating], true)) {
            Log::warning('GenerateBlogPostJob: Candidate not ready, skipping', [
                'candidate_id' => $this->candidate->id,
                'status' => $candidateStatus->value,
            ]);

            return;
        }

        // Atomically update status to prevent parallel execution
        $this->candidate->update([
            'status' => BlogTopicCandidateStatus::Generating,
        ]);

        $profile = $this->candidate->batch->profile ?? $this->candidate->series?->profile;
        if ($profile === null) {
            Log::error('GenerateBlogPostJob: No profile found for candidate', [
                'candidate_id' => $this->candidate->id,
            ]);

            $this->candidate->update([
                'status' => BlogTopicCandidateStatus::Failed,
            ]);

            return;
        }

        try {
            /** @var BlogProductSeries|null $series */
            $series = $this->candidate->series;
            $content = $writerService->write($this->candidate, $profile, $series);

            // Run citation guard
            $citationCheck = $citationGuard->check(
                $content['main_text'],
                $content['citations_json'],
            );

            // Store citation warnings in risk_flags_json
            if (!empty($citationCheck->warnings)) {
                $this->candidate->update([
                    'risk_flags_json' => array_merge(
                        $this->candidate->risk_flags_json ?? [],
                        ['citation_warnings' => $citationCheck->warnings],
                    ),
                ]);
            }

            // If strict mode and check failed, mark as failed
            if (!$citationCheck->passed) {
                Log::warning('GenerateBlogPostJob: Citation guard failed in strict mode', [
                    'candidate_id' => $this->candidate->id,
                    'uncited_claims' => count($citationCheck->uncitedClaims),
                ]);

                $this->candidate->update([
                    'status' => BlogTopicCandidateStatus::Failed,
                ]);

                $this->notifyUser(
                    __('Blog post generation failed'),
                    __('Citation guard failed for topic ":title". :count uncited claims found.', [
                        'title' => $this->candidate->title,
                        'count' => count($citationCheck->uncitedClaims),
                    ]),
                    'danger',
                );

                return;
            }

            // Populate primary language translation
            $primaryLocale = config('app.locale', 'en');

            // Create Post record as draft
            $postClass = app(ModelRegistry::class)->getModelClassOrFail('post');
            $post = $postClass::create([
                'post_type_id' => config('ai-posts.taxonomy.blog_post_type', 2),
                'post_category_id' => config('ai-posts.taxonomy.default_post_category', 1),
                'active' => false,
                'publish_at' => $this->candidate->scheduled_publish_at ?? now(),
                'author' => AuthorNameGenerator::generate($primaryLocale),
            ]);

            if (!$post instanceof Translatable) {
                throw new \LogicException('GenerateBlogPostJob: registered post model must be an Astrotomic-translatable Model.');
            }

            $translation = $post->translateOrNew($primaryLocale);
            // Translation properties are undeclared on the generic Model at the AI-package boundary.
            $translation->title = $content['title']; // @phpstan-ignore property.notFound
            $translation->slug = app(GeneratesSlugs::class)->make($content['title']); // @phpstan-ignore property.notFound
            $translation->excerpt = $content['excerpt']; // @phpstan-ignore property.notFound
            $translation->main_text = $content['main_text']; // @phpstan-ignore property.notFound
            $translation->seo_title = $content['seo_title']; // @phpstan-ignore property.notFound
            $translation->seo_description = $content['seo_description']; // @phpstan-ignore property.notFound
            $translation->seo_keywords = $content['seo_keywords']; // @phpstan-ignore property.notFound
            $post->save();

            // Populate SeoIndexable with keyphrases for internal link matching
            $this->populateSeoKeyphrases($post, $primaryLocale, $content);

            // Update candidate
            $this->candidate->update([
                'status' => BlogTopicCandidateStatus::Generated,
                'post_id' => $post->getKey(),
                'citations_json' => $content['citations_json'],
            ]);

            // Chain: enrich links → (optional) FAQ → (optional) images → translate
            $this->dispatchEnrichmentAndTranslations($post, $primaryLocale, $profile);

            Log::info('GenerateBlogPostJob: Post created', [
                'candidate_id' => $this->candidate->id,
                'post_id' => $post->getKey(),
            ]);

            $this->notifyUser(
                __('Blog post generated'),
                __('Blog post generated from research topic: :title', [
                    'title' => $content['title'],
                ]),
                'success',
            );
        } catch (AiRateLimitedException $e) {
            $retryAfter = $e->retryAfter > 0 ? $e->retryAfter : 60;

            Log::warning('GenerateBlogPostJob: Rate limited', [
                'candidate_id' => $this->candidate->id,
                'retry_after_seconds' => $retryAfter,
            ]);

            $this->release($retryAfter);
        } catch (AiUnavailableException $e) {
            Log::warning('GenerateBlogPostJob: Provider overloaded', [
                'candidate_id' => $this->candidate->id,
            ]);

            $this->release(60);
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('GenerateBlogPostJob: Permanently failed', [
            'candidate_id' => $this->candidate->id,
            'error' => $exception->getMessage(),
        ]);

        $this->candidate->update([
            'status' => BlogTopicCandidateStatus::Failed,
        ]);

        $this->notifyUser(
            __('Blog post generation failed'),
            __('Failed to generate blog post for topic ":title": :error', [
                'title' => $this->candidate->title,
                'error' => $exception->getMessage(),
            ]),
            'danger',
        );
    }

    /**
     * Populate SeoIndexable with keyphrases extracted from generated SEO keywords.
     *
     * @param  array{seo_keywords: string, seo_title: string, seo_description: string, title: string, excerpt: string, main_text: string, citations_json: array<mixed>}  $content
     */
    private function populateSeoKeyphrases(Model $post, string $locale, array $content): void
    {
        $seoIndexable = $post->getOrCreateSeoIndexable($locale); // @phpstan-ignore method.notFound

        // Parse comma-separated keywords
        $keywords = array_map('trim', explode(',', $content['seo_keywords']));
        $keywords = array_filter($keywords, fn (string $k): bool => $k !== '');

        $primaryKeyphrase = array_shift($keywords);
        $secondaryKeyphrases = array_slice($keywords, 0, 3);

        $seoIndexable->update([
            'title' => $content['seo_title'] ?: $content['title'],
            'description' => $content['seo_description'] ?: $content['excerpt'],
            'keyphrases' => [
                'primary' => $primaryKeyphrase,
                'secondary' => $secondaryKeyphrases,
            ],
        ]);
    }

    private function dispatchEnrichmentAndTranslations(Model $post, string $primaryLocale, BlogTopicProfile $profile): void
    {
        /** @var array<string, array<string, mixed>> $supportedLocales */
        $supportedLocales = config('laravellocalization.supportedLocales', []);
        $locales = array_keys($supportedLocales);

        $translationJobs = [];
        foreach ($locales as $locale) {
            if ($locale !== $primaryLocale) {
                $translationJobs[] = new TranslatePostToLocale($post, $primaryLocale, $locale, $this->userId);
            }
        }

        // Build chain: detect+humanize → enrich → (optional) images → translate
        // DetectAndHumanizeJob runs first on clean text (before any HTML enrichment).
        // If AI is detected (score >= 50), it humanizes automatically.
        $chain = [];

        if ($this->candidate->humanize_content) {
            if ($humanizeJob = HostJobs::resolve('humanize')) {
                $chain[] = new $humanizeJob($post, $primaryLocale);
            }
        }

        $chain[] = new EnrichPostLinksJob($post, $primaryLocale, $profile, $this->userId);

        if ($this->candidate->generate_faq) {
            $chain[] = new GenerateBlogFaqJob($post, $this->candidate, $this->userId);
        }

        if ($this->candidate->generate_images && config('ai.assistant.blog_topics.images.enabled', false)) {
            $chain[] = new GenerateBlogImagesJob($post, $this->candidate, $this->userId);
        }

        $chain = [...$chain, ...$translationJobs];

        Bus::chain($chain)->onQueue('ai-bulk')->dispatch();
    }
}
