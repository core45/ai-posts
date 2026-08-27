<?php

declare(strict_types=1);

namespace Core45\AiPosts\Jobs\BlogTopics;

use Astrotomic\Translatable\Contracts\Translatable;
use Core45\AiCore\Contracts\UnconfiguredSeam;
use Core45\AiCore\ModelRegistry;
use Core45\AiCore\Traits\LogsAIOperations;
use Core45\AiPosts\Contracts\Seo\ReportsLinkEnrichment;
use Core45\AiPosts\Contracts\Seo\ScansInternalLinks;
use Core45\AiPosts\Contracts\Seo\SuggestsInternalLinks;
use Core45\AiPosts\Enums\BlogLinkPlacedBy;
use Core45\AiPosts\Models\BlogExternalLink;
use Core45\AiPosts\Models\BlogTopicProfile;
use Core45\AiPosts\Services\BlogTopics\AiLinkSuggestionService;
use Core45\AiPosts\Services\BlogTopics\ExternalLinkManagerService;
use Core45\AiPosts\Services\BlogTopics\ExternalLinkSafetyService;
use Core45\AiPosts\Services\BlogTopics\InternalLinkEnricherService;
use Core45\AiPosts\Support\HostJobs;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class EnrichPostLinksJob implements ShouldBeUnique, ShouldQueue
{
    use LogsAIOperations;
    use Queueable;

    public int $tries = 3;

    public int $timeout = 180;

    public int $uniqueFor = 300;

    public function __construct(
        public Model $post,
        public string $locale,
        public ?BlogTopicProfile $profile = null,
        public ?int $userId = null,
    ) {
        $this->onQueue('ai-bulk');
    }

    public function uniqueId(): string
    {
        return $this->post->getKey().'-'.$this->locale;
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

    public function handle(
        InternalLinkEnricherService $enricher,
        ExternalLinkManagerService $externalLinkManager,
        ExternalLinkSafetyService $safetyService,
        AiLinkSuggestionService $aiLinkService,
        SuggestsInternalLinks $linkSuggestionService,
        ScansInternalLinks $linkScanner,
    ): void {
        $this->logDebug('handle - Started', [
            'post_id' => $this->post->getKey(),
            'locale' => $this->locale,
            'has_profile' => $this->profile !== null,
        ]);

        $translation = $this->translation($this->locale);
        if ($translation === null || empty($translation->main_text)) {
            Log::warning('EnrichPostLinksJob: No translation found', [
                'post_id' => $this->post->getKey(),
                'locale' => $this->locale,
            ]);
            $this->logDebug('handle - Aborted: no translation or empty main_text');

            return;
        }

        $html = $translation->main_text;

        $this->logDebug('Step 1: Validate existing internal links', [
            'html_length' => mb_strlen($html),
        ]);

        // Step 1: Validate existing internal links
        $validation = $enricher->validateLinks($html, $this->locale);
        $html = $validation['html'];

        foreach ($validation['warnings'] as $warning) {
            Log::warning('EnrichPostLinksJob: '.$warning, ['post_id' => $this->post->getKey()]);
        }

        // Step 2: Auto-inject internal links using AI-powered suggestion
        $seoIndexable = $this->post->getOrCreateSeoIndexable($this->locale); // @phpstan-ignore method.notFound

        $this->logDebug('Step 2: SeoIndexable state before keyphrase check', [
            'seo_indexable_id' => $seoIndexable->id,
            'primary_keyphrase' => $seoIndexable->primary_keyphrase,
            'title' => $seoIndexable->title,
            'seo_keywords' => $translation->seo_keywords, // @phpstan-ignore property.notFound
        ]);

        // Ensure keyphrases are populated (existing posts may lack them)
        $this->ensureKeyphrasesPopulated($seoIndexable, $translation);

        // Primary: AI-powered semantic matching
        $linkTargets = $this->getAiLinkTargets($aiLinkService, $seoIndexable, $html);
        $linkSource = 'AI (Gemini)';

        // Fallback: string-matching if AI returned nothing
        if ($linkTargets === []) {
            $this->logDebug('Step 2: AI returned no suggestions, falling back to string matching');
            $linkTargets = $this->getStringMatchLinkTargets($linkSuggestionService, $seoIndexable, $html);
            $linkSource = 'String matching (fallback)';
        }

        $this->logDebug('Step 2: Link targets ready', [
            'link_targets_count' => count($linkTargets),
            'link_targets' => array_map(fn (array $t): array => [
                'url' => $t['url'],
                'anchorText' => $t['anchorText'],
                'relevanceScore' => $t['relevanceScore'],
            ], $linkTargets),
        ]);

        $injectionResult = $enricher->injectLinks($html, $linkTargets);
        $html = $injectionResult['html'];
        $actuallyInjected = $injectionResult['injectedCount'];

        $this->logDebug('Step 2: Links injected', [
            'injected_count' => $actuallyInjected,
        ]);

        // Step 3: Suggest external link placements (NOT auto-insert — errata E1)
        if ($this->profile !== null) {
            $availableLinks = $externalLinkManager->getAvailableLinks($this->profile);
            $suggestedCount = 0;

            /** @var BlogExternalLink $link */
            foreach ($availableLinks as $link) {
                if ($suggestedCount >= 2) {
                    break;
                }

                $safetyResult = $safetyService->check($link->url);
                if (!$safetyResult->safe) {
                    $externalLinkManager->reviewLink($link, true, $safetyResult->reason, 'ai');

                    continue;
                }

                $anchor = $link->anchor_text_suggestion ?? $link->label;

                // Find context snippet around where the link could go
                $contextSnippet = $this->findContextSnippet($html, $anchor);

                $externalLinkManager->suggestPlacement(
                    $link,
                    $this->post,
                    $anchor,
                    $contextSnippet,
                    BlogLinkPlacedBy::Ai,
                );
                $suggestedCount++;
            }
        }

        $this->logDebug('Step 3: External link suggestions complete');

        // Save enriched content
        $translation->main_text = $html;
        $translation->save();

        // Update SeoIndexable link counts (errata E2: use DTO property)
        //
        // `links_counted_at` is an attestation, not a cache key: it says a
        // count was actually taken at that moment. An unconfigured scanner
        // reports zero links for any document, so writing its answer would
        // stamp "we counted" over a non-answer — and would silently regress a
        // real, previously-measured count to 0 in any host that lost its
        // binding. Skipping leaves the last genuine measurement standing.
        if (!$linkScanner instanceof UnconfiguredSeam) {
            $appUrl = (string) config('app.url');
            $scanResult = $linkScanner->scanContent($html, $appUrl);
            $seoIndexable->update([
                'internal_links_outbound' => $scanResult->internalLinkCount,
                'links_counted_at' => now(),
            ]);
        } else {
            Log::info('EnrichPostLinksJob: internal link count not updated', [
                'post_id' => $this->post->getKey(),
                'reason' => 'No '.ScansInternalLinks::class.' implementation is bound, so no count was taken.',
            ]);
        }

        // Step 4: Dispatch SEO analysis (errata E11)
        if ($seoJob = HostJobs::resolve('analyze_seo')) {
            $seoJob::dispatch($seoIndexable);
        }

        Log::info('EnrichPostLinksJob: Post enriched', [
            'post_id' => $this->post->getKey(),
            'locale' => $this->locale,
            'internal_links_suggested' => count($linkTargets),
            'internal_links_injected' => $actuallyInjected,
            'warnings' => count($validation['warnings']),
        ]);

        $this->sendReport($translation, $linkTargets, $actuallyInjected, $validation['warnings'], $linkSource);
    }

    /**
     * Send the enrichment report to the triggering user and to admins.
     *
     * Delivery goes through the `ReportsLinkEnrichment` seam rather than a
     * Filament call, so the package works on any panel or none.
     *
     * @param  array<int, array{url: string, anchorText: string, reason?: string, relevanceScore: float}>  $linkTargets
     * @param  array<int, string>  $warnings
     */
    private function sendReport(Model $translation, array $linkTargets, int $injectedCount, array $warnings, string $source): void
    {
        $report = [
            'post_id' => $this->post->getKey(),
            'post_title' => $translation->title ?? "Post #{$this->post->getKey()}",
            'locale' => $this->locale,
            'source' => $source,
            'suggested' => array_map(fn (array $t): array => [
                'url' => $t['url'],
                'anchorText' => $t['anchorText'],
                'reason' => $t['reason'] ?? '',
            ], $linkTargets),
            'injected_count' => $injectedCount,
            'warnings' => $warnings,
        ];

        $reporter = app(ReportsLinkEnrichment::class);

        // Notify the user who triggered the run
        $userClass = app(ModelRegistry::class)->getModelClassOrFail('user');

        if ($this->userId !== null) {
            $user = $userClass::find($this->userId);
            if ($user !== null) {
                $reporter->report($report, $user);
            }
        }

        // Send email to admins who opted into system emails
        $admins = $userClass::role(['super_admin', 'ai_admin']) // @phpstan-ignore staticMethod.notFound (Spatie HasRoles scope, not declared on Model)
            ->where('receive_system_emails', true)
            ->when($this->userId, fn ($q) => $q->where('id', '!=', $this->userId))
            ->get();

        if ($admins->isNotEmpty()) {
            $reporter->reportToMany($report, $admins);
        }
    }

    /**
     * Get link targets using AI-powered semantic matching.
     *
     * @return array<int, array{url: string, anchorText: string, relevanceScore: float}>
     */
    private function getAiLinkTargets(AiLinkSuggestionService $aiLinkService, Model $seoIndexable, string $html): array
    {
        try {
            $suggestions = $aiLinkService->suggestLinks($seoIndexable, $html, $this->locale);

            $this->logDebug('Step 2: AI link suggestions received', [
                'suggestions_count' => count($suggestions),
            ]);

            return $suggestions;
        } catch (\Throwable $e) {
            Log::warning('EnrichPostLinksJob: AI link suggestion failed, will use fallback', [
                'post_id' => $this->post->getKey(),
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Get link targets using string-matching fallback.
     *
     * @return array<int, array{url: string, anchorText: string, relevanceScore: float}>
     */
    private function getStringMatchLinkTargets(SuggestsInternalLinks $linkSuggestionService, Model $seoIndexable, string $html): array
    {
        $contentText = strip_tags($html);
        $suggestions = $linkSuggestionService->suggestLinks($seoIndexable, 5, $contentText);

        $this->logDebug('Step 2: String-match suggestions received', [
            'suggestions_count' => count($suggestions),
        ]);

        $linkTargets = [];

        foreach ($suggestions as $suggestion) {
            $indexable = $suggestion['indexable'];
            // The contract returns `Model`, not the host's SeoIndexable — a
            // package interface cannot name a host class. That widening is the
            // seam working as intended, and the cost is that PHPStan can no
            // longer see this relation or the concrete class behind it. Both
            // members are pinned by tests/Feature/Packages/HostObligationsTest
            // ('seo_indexable.indexable'), so these are recorded obligations
            // rather than silent assumptions.
            // @phpstan-ignore property.notFound (host SeoIndexable relation; pinned by HostObligationsTest)
            $model = $indexable->indexable;
            $url = null;

            // `is_object` is not redundant with `method_exists`: method_exists
            // also accepts a class-string, and PHPStan will not narrow to a
            // callable receiver without it. Widening the contract to `Model`
            // is what exposed this — the concrete host type used to carry the
            // guarantee implicitly.
            if (is_object($model) && method_exists($model, 'getUrl')) {
                $url = $model->getUrl($this->locale);
            }

            if ($url !== null) {
                $anchors = array_filter([
                    $indexable->primary_keyphrase ?? '',
                    $indexable->title ?? '',
                ], fn (string $a): bool => $a !== '');

                foreach ($anchors as $anchor) {
                    $linkTargets[] = [
                        'url' => $url,
                        'anchorText' => $anchor,
                        'relevanceScore' => $suggestion['relevanceScore'],
                    ];
                }
            }
        }

        return $linkTargets;
    }

    /**
     * Populate SeoIndexable keyphrases from translation's seo_keywords if missing.
     *
     * Existing posts created before automated keyphrase population may have
     * seo_keywords but empty SeoIndexable keyphrases, causing suggestLinks()
     * to return zero results (keyphrase matching is 50% of the relevance score).
     */
    private function ensureKeyphrasesPopulated(Model $seoIndexable, Model $translation): void
    {
        if (!empty($seoIndexable->primary_keyphrase)) {
            return;
        }

        $seoKeywords = $translation->seo_keywords ?? '';
        if ($seoKeywords === '') {
            return;
        }

        $keywords = array_map('trim', explode(',', $seoKeywords));
        $keywords = array_filter($keywords, fn (string $k): bool => $k !== '');

        if (empty($keywords)) {
            return;
        }

        $primaryKeyphrase = array_shift($keywords);
        $secondaryKeyphrases = array_slice($keywords, 0, 3);

        $seoIndexable->update([
            // @phpstan-ignore property.notFound, property.notFound, property.notFound (translation is a generic Model at the AI-package boundary)
            'title' => $seoIndexable->title ?: ($translation->seo_title ?: $translation->title),
            // @phpstan-ignore property.notFound, property.notFound, property.notFound
            'description' => $seoIndexable->description ?: ($translation->seo_description ?: $translation->excerpt),
            'keyphrases' => [
                'primary' => $primaryKeyphrase,
                'secondary' => $secondaryKeyphrases,
            ],
        ]);

        Log::info('EnrichPostLinksJob: Populated missing keyphrases from seo_keywords', [
            'post_id' => $this->post->getKey(),
            'locale' => $this->locale,
            'primary_keyphrase' => $primaryKeyphrase,
            'secondary_count' => count($secondaryKeyphrases),
        ]);
    }

    private function findContextSnippet(string $html, string $anchor): string
    {
        $plainText = strip_tags($html);
        $pos = mb_stripos($plainText, $anchor);
        if ($pos === false) {
            return '';
        }

        $start = max(0, $pos - 50);
        $length = mb_strlen($anchor) + 100;

        return mb_substr($plainText, $start, $length);
    }
}
