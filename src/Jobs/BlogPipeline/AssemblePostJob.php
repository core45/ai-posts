<?php

declare(strict_types=1);

namespace Core45\AiPosts\Jobs\BlogPipeline;

use Astrotomic\Translatable\Contracts\Translatable;
use Core45\AiCore\Contracts\GeneratesSlugs;
use Core45\AiCore\ModelRegistry;
use Core45\AiPosts\Enums\BlogPostPipelineStatus;
use Core45\AiPosts\Enums\BlogTopicCandidateStatus;
use Core45\AiPosts\Enums\CitationMode;
use Core45\AiPosts\Models\BlogPostPipelineRun;
use Core45\AiPosts\Services\BlogPipeline\AuthorNameGenerator;
use Core45\AiPosts\Services\BlogPipeline\LinkVerificationService;
use Core45\AiPosts\Services\BlogPipeline\PipelineOrchestratorService;
use Filament\Notifications\Notification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Assembles the final blog post from all completed module outputs.
 *
 * Collects module outputs in config order, merges content, processes citations,
 * creates the Post record, and dispatches the enrichment + translation chain.
 */
class AssemblePostJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(
        public readonly BlogPostPipelineRun $run,
    ) {
        $this->onQueue('ai-bulk');
    }

    public function handle(PipelineOrchestratorService $orchestrator): void
    {
        $this->run->refresh();

        if ($this->run->status->isTerminal()) {
            Log::warning('AssemblePostJob: Run is in terminal state, skipping', [
                'pipeline_run_id' => $this->run->id,
                'status' => $this->run->status->value,
            ]);

            return;
        }

        $mergedHtml = $this->mergeModuleContent();
        $processedHtml = $this->processCitations($mergedHtml, $this->collectCitations());
        $processedHtml = $this->verifyInternalLinks($processedHtml);

        $post = $this->createPost($mergedHtml, $processedHtml);

        // Link the artifact to both the run and the candidate atomically. If a
        // worker dies between these writes, a half-linked state (run.post_id set
        // but candidate.post_id null) would let the "restart stuck run" gate
        // offer a restart and orphan this Post.
        DB::transaction(function () use ($post): void {
            $this->run->update([
                'post_id' => $post->getKey(),
            ]);

            $this->run->candidate->update([
                'post_id' => $post->getKey(),
                'status' => BlogTopicCandidateStatus::Generated,
            ]);
        });

        Log::info('AssemblePostJob: Post assembled, delegating to orchestrator', [
            'pipeline_run_id' => $this->run->id,
            'post_id' => $post->getKey(),
        ]);

        $orchestrator->afterAssembly($this->run);
    }

    /**
     * Create the Post record with its primary locale translation and SEO data.
     */
    private function createPost(string $mergedHtml, string $processedHtml): Model
    {
        $title = $this->extractTitle($mergedHtml);
        $excerpt = $this->extractExcerpt($mergedHtml);
        $primaryLocale = $this->run->primary_locale;

        $postClass = app(ModelRegistry::class)->getModelClassOrFail('post');
        $post = $postClass::create([
            'post_type_id' => config('ai-posts.taxonomy.blog_post_type', 2),
            'post_category_id' => config('ai-posts.taxonomy.default_post_category', 1),
            'active' => false,
            'publish_at' => $this->run->candidate->scheduled_publish_at ?? now(),
            'author' => AuthorNameGenerator::generate($primaryLocale),
        ]);

        if (!$post instanceof Translatable) {
            throw new \LogicException('AssemblePostJob: registered post model must be an Astrotomic-translatable Model.');
        }

        $translation = $post->translateOrNew($primaryLocale);
        // Translation properties are undeclared on the generic Model at the AI-package boundary.
        $translation->title = $title; // @phpstan-ignore property.notFound
        $translation->slug = app(GeneratesSlugs::class)->make($title); // @phpstan-ignore property.notFound
        $translation->excerpt = $excerpt; // @phpstan-ignore property.notFound
        $translation->main_text = $processedHtml; // @phpstan-ignore property.notFound
        $translation->seo_title = Str::limit($title, 70, ''); // @phpstan-ignore property.notFound
        $translation->seo_description = Str::limit($excerpt, 156, ''); // @phpstan-ignore property.notFound
        $translation->seo_keywords = $this->generateKeywords($title); // @phpstan-ignore property.notFound
        $post->save();

        $this->populateSeoKeyphrases($post, $primaryLocale, $translation->seo_keywords ?? '');

        return $post;
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('AssemblePostJob: Permanently failed', [
            'pipeline_run_id' => $this->run->id,
            'error' => $exception->getMessage(),
        ]);

        $this->run->update([
            'status' => BlogPostPipelineStatus::Failed,
            'completed_at' => now(),
            'current_step' => 'failed:assembly',
        ]);

        $this->notifyFailure($exception);
    }

    /**
     * Notify the approving user about the assembly failure.
     */
    private function notifyFailure(\Throwable $exception): void
    {
        $userId = $this->run->candidate->approved_by ?? null;

        if ($userId === null) {
            return;
        }

        $userClass = app(ModelRegistry::class)->getModelClassOrFail('user');
        $user = $userClass::find($userId);

        if ($user === null) {
            return;
        }

        Notification::make()
            ->title(__('Pipeline Failed'))
            ->body(__('Assembly failed for ":topic": :error', [
                'topic' => $this->run->candidate->title,
                'error' => $exception->getMessage(),
            ]))
            ->danger()
            ->sendToDatabase($user);
    }

    /**
     * Verify internal links in the processed HTML and return cleaned content.
     */
    private function verifyInternalLinks(string $html): string
    {
        /** @var LinkVerificationService $linkVerifier */
        $linkVerifier = app(LinkVerificationService::class);
        $result = $linkVerifier->verify($html, $this->run->primary_locale);

        Log::info('AssemblePostJob: Link verification completed', [
            'pipeline_run_id' => $this->run->id,
            'verified_count' => $result['verified_count'],
            'removed_count' => $result['removed_count'],
            'warnings' => $result['warnings'],
        ]);

        return $result['html'];
    }

    /**
     * Get enabled module outputs in config-defined order.
     *
     * @return array<string, array<string, mixed>>
     */
    private function getOrderedModuleOutputs(): array
    {
        /** @var array<int, array<string, mixed>> $modules */
        $modules = $this->run->config_snapshot['modules_config']['modules'] ?? [];
        /** @var array<string, array<string, mixed>> $outputs */
        $outputs = $this->run->module_outputs ?? [];

        $ordered = [];

        foreach ($modules as $module) {
            $slug = $module['module_slug'] ?? '';

            if (!($module['enabled'] ?? true)) {
                continue;
            }

            if (isset($outputs[$slug])) {
                $ordered[$slug] = $outputs[$slug];
            }
        }

        return $ordered;
    }

    /**
     * Merge module content in the order defined by config_snapshot modules list.
     */
    private function mergeModuleContent(): string
    {
        $parts = array_filter(
            array_column($this->getOrderedModuleOutputs(), 'content'),
            fn (mixed $content): bool => $content !== null,
        );

        return implode("\n", $parts);
    }

    /**
     * Collect all citations from module outputs in config order.
     *
     * @return array<int, array{marker: int, title: string, url: string}>
     */
    private function collectCitations(): array
    {
        $citations = [];

        foreach ($this->getOrderedModuleOutputs() as $output) {
            /** @var array<int, array{marker: int, title: string, url: string}> $moduleCitations */
            $moduleCitations = $output['citations_json'] ?? [];

            foreach ($moduleCitations as $citation) {
                $citations[] = $citation;
            }
        }

        return $citations;
    }

    /**
     * Process citations based on the profile's citation mode.
     *
     * @param  array<int, array{marker: int, title: string, url: string}>  $citations
     */
    private function processCitations(string $html, array $citations): string
    {
        /** @var CitationMode $citationMode */
        $citationMode = $this->run->profile->citation_mode ?? CitationMode::Strip;

        return match ($citationMode) {
            CitationMode::Render => $this->renderCitations($html, $citations),
            CitationMode::Strict => $html,
            default => $this->stripCitations($html),
        };
    }

    /**
     * Strip all citation markers and any trailing references section.
     */
    private function stripCitations(string $html): string
    {
        $html = preg_replace('/\s*\[\d+\]/', '', $html) ?? $html;

        $html = preg_replace(
            '/<h[1-6][^>]*>\s*(References|Bibliography|Sources)\s*<\/h[1-6]>.*$/si',
            '',
            $html,
        ) ?? $html;

        return trim($html);
    }

    /**
     * Convert citation markers to footnote links and append a references section.
     *
     * @param  array<int, array{marker: int, title: string, url: string}>  $citations
     */
    private function renderCitations(string $html, array $citations): string
    {
        if ($citations === []) {
            return $html;
        }

        $citationMap = [];
        foreach ($citations as $citation) {
            $citationMap[(int) $citation['marker']] = $citation;
        }

        $html = preg_replace_callback(
            '/\[(\d+)\]/',
            fn (array $matches): string => '<sup><a href="#cite-'.$matches[1].'">['.
                $matches[1].']</a></sup>',
            $html,
        ) ?? $html;

        $referencesHtml = '<section class="references"><h3>References</h3><ol>';

        ksort($citationMap);

        foreach ($citationMap as $marker => $citation) {
            $title = htmlspecialchars($citation['title'], ENT_QUOTES, 'UTF-8');
            $url = htmlspecialchars($citation['url'], ENT_QUOTES, 'UTF-8');
            $referencesHtml .= '<li id="cite-'.$marker.'"><a href="'.$url.'" target="_blank" rel="noopener noreferrer">'.$title.'</a></li>';
        }

        $referencesHtml .= '</ol></section>';

        return $html."\n".$referencesHtml;
    }

    /**
     * Extract the title from the first H1 or H2 tag, falling back to candidate title.
     */
    private function extractTitle(string $html): string
    {
        if (preg_match('/<h[12][^>]*>(.*?)<\/h[12]>/i', $html, $matches)) {
            $title = strip_tags($matches[1]);
            if (trim($title) !== '') {
                return trim($title);
            }
        }

        return $this->run->candidate->title;
    }

    /**
     * Extract an excerpt from the first 200 characters of stripped text from the first module.
     */
    private function extractExcerpt(string $html): string
    {
        $text = strip_tags($html);
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;
        $text = trim($text);

        return Str::limit($text, 200, '...');
    }

    /**
     * Generate comma-separated keywords from the title.
     */
    private function generateKeywords(string $title): string
    {
        $researchData = $this->run->research_data ?? [];

        if (!empty($researchData['keywords'])) {
            /** @var array<int, string> $keywords */
            $keywords = $researchData['keywords'];

            return implode(', ', array_slice($keywords, 0, 8));
        }

        $words = preg_split('/\s+/', strtolower($title));
        $stopWords = ['the', 'a', 'an', 'and', 'or', 'but', 'in', 'on', 'at', 'to', 'for', 'of', 'is', 'it', 'by', 'with', 'as', 'from'];
        $keywords = array_filter($words ?: [], fn (string $word): bool => strlen($word) > 2 && !in_array($word, $stopWords, true));

        return implode(', ', array_slice(array_values($keywords), 0, 8));
    }

    /**
     * Populate SeoIndexable with keyphrases.
     */
    private function populateSeoKeyphrases(Model $post, string $locale, string $seoKeywords): void
    {
        $seoIndexable = $post->getOrCreateSeoIndexable($locale); // @phpstan-ignore method.notFound

        $keywords = array_map('trim', explode(',', $seoKeywords));
        $keywords = array_filter($keywords, fn (string $k): bool => $k !== '');

        $primaryKeyphrase = array_shift($keywords);
        $secondaryKeyphrases = array_slice($keywords, 0, 3);

        $translation = null;
        if ($post instanceof Translatable) {
            $translation = $post->translate($locale);
        }

        $seoTitle = $translation === null ? null : ($translation->seo_title ?? $translation->title ?? null);
        $seoDescription = $translation === null ? null : ($translation->seo_description ?? $translation->excerpt ?? null);

        $seoIndexable->update([
            'title' => $seoTitle,
            'description' => $seoDescription,
            'keyphrases' => [
                'primary' => $primaryKeyphrase,
                'secondary' => $secondaryKeyphrases,
            ],
        ]);
    }
}
