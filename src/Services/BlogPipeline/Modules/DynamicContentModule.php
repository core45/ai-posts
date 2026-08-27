<?php

declare(strict_types=1);

namespace Core45\AiPosts\Services\BlogPipeline\Modules;

use Core45\AiCore\Contracts\ObservesAiContracts;
use Core45\AiCore\Contracts\ProvidesMandatoryWords;
use Core45\AiCore\Services\KnowledgeService;
use Core45\AiPosts\DTOs\BlogPipeline\ModuleContext;
use Core45\AiPosts\DTOs\BlogPipeline\ModuleOutput;
use Core45\AiPosts\DTOs\BlogPipeline\QualityResult;
use Core45\AiPosts\Enums\QualityDecision;
use Core45\AiPosts\Models\BlogPostPipelineRun;
use Core45\AiPosts\Models\BlogProfileLesson;
use Core45\AiPosts\Models\BlogTopicSource;
use Core45\AiPosts\Models\ContentModule;
use Core45\AiPosts\Services\BlogPipeline\InternalLinkContextBuilder;
use Core45\AiPosts\Services\BlogPipeline\PromptTemplateResolver;

class DynamicContentModule extends AbstractContentModule
{
    public function __construct(
        private readonly ContentModule $moduleRecord,
        private readonly PromptTemplateResolver $promptResolver,
    ) {}

    public function getSlug(): string
    {
        return $this->moduleRecord->slug;
    }

    public function prepare(BlogPostPipelineRun $run): ModuleContext
    {
        /** @var array<string, array<string, mixed>> $localeConfig */
        $localeConfig = (array) config('laravellocalization.supportedLocales', []);
        $localeName = (string) ($localeConfig[$run->primary_locale]['name'] ?? $run->primary_locale);

        /** @var array<string> $forbiddenPatterns */
        $forbiddenPatterns = (array) config('blog-pipeline.forbidden_patterns', []);

        /** @var InternalLinkContextBuilder $linkBuilder */
        $linkBuilder = app(InternalLinkContextBuilder::class);
        $linkTargets = $linkBuilder->buildTargets($run, $this->getSlug());

        $profileLessons = $this->loadProfileLessons($run);
        $learnedKnowledge = $this->loadLearnedKnowledge($run);

        $citationsEnabled = (bool) ($run->candidate->enable_citations ?? false);
        $researchSources = $citationsEnabled ? $this->loadResearchSources($run) : [];

        $alreadyLinkedUrls = $this->loadAlreadyLinkedUrls($run);

        return new ModuleContext(
            previousModuleSummaries: $run->getModuleSummaries(),
            lastModuleContent: $run->getLastModuleContent(),
            recentModuleContents: $run->getRecentModuleContents(3),
            internalLinkTargets: $linkTargets,
            language: $localeName,
            forbiddenPatterns: $forbiddenPatterns,
            profileLessons: $profileLessons,
            learnedKnowledge: $learnedKnowledge,
            citationsEnabled: $citationsEnabled,
            researchSources: $researchSources,
            alreadyLinkedUrls: $alreadyLinkedUrls,
        );
    }

    /**
     * @return array<string, mixed> the `blog_pipeline.module` parameter payload,
     *                              carrying `placeholder_data` (current contract)
     *                              or `placeholders` (retired shape — see below)
     */
    public function buildPayload(BlogPostPipelineRun $run, ModuleContext $context): array
    {
        // Distinguish a TENANT OVERRIDE from the seeded default by nullability
        // (plan 48-15 Task 3 nulls the seeded columns; null means "use phasea's
        // default for this slug"). A non-null column is the tenant's own text and
        // travels as an override parameter; a null column sends no override, so
        // phasea uses its library default.
        //
        // ─────────────────────────────────────────────────────────────────────
        // CORRECTED 2026-07-25 (phasea phase 50). This comment used to say that
        // a still-populated seeded column was "harmless, because they are
        // byte-identical to phasea's default for that slug, so output is
        // unchanged". THAT IS NO LONGER TRUE, and reading it as still true is
        // the specific way to misjudge this code path.
        //
        // phasea phase 50 replaced the ANTI-AI WRITING RULES block in all ten
        // module system bodies with a shared {{anti_slop_guide}} marker. A
        // legacy seeded column therefore now differs from phasea's default, and
        // because phasea's `systemTemplate()` is a TOTAL REPLACEMENT rather than
        // a wrap, sending it would have suppressed the entire phase on this
        // surface.
        //
        // 48-15's nullability migration was deliberately RELAX-ONLY and never
        // nulled existing rows, so on every install seeded before 48-15 these
        // columns are STILL POPULATED and still travel as overrides on every
        // render. phasea reconciles that itself: it hashes the incoming override
        // against `BlogPipelineModulePrompt::LEGACY_SEEDED_SYSTEM_TEMPLATE_HASHES`
        // and, on a match, discards it as stale seed data in favour of its own
        // current default (phase 50 plan 50-04, branch 2).
        //
        // So this host needs no change for correctness. What it still owes is
        // CLEANUP: a data migration nulling the rows whose content matches the
        // pre-phase-50 seeded bodies, after which phasea's hash const and its
        // branch 2 can both be deleted. That is deliberately NOT done here —
        // it needs verification against a real pre-48-15 database, which no test
        // environment has. Tracked in phasea
        // `.planning/phases/50-anti-slop-guide-consolidation/deferred-items.md`
        // item 2, marked HIGH.
        // ─────────────────────────────────────────────────────────────────────
        $systemOverride = $this->moduleRecord->system_prompt_template;
        $userOverride = $this->moduleRecord->user_prompt_template;

        $payload = [
            'module_slug' => $this->getSlug(),
            'system_template_override' => $systemOverride,
            'user_template_override' => $userOverride,
            'citations_enabled' => $context->citationsEnabled,
            // Store-wide mandatory words, so phasea's anti-slop ban list yields to
            // a word the brand requires (phase 50, D-50-1). Top-level, NOT inside
            // `placeholder_data` — that structure is the typed placeholder map,
            // and this is a parameter phasea reads for itself. Absent when the
            // store mandates nothing.
            ...app(ProvidesMandatoryWords::class)->asProfileParameter(),
        ];

        // ─────────────────────────────────────────────────────────────────────
        // BOTH SHAPES, EVERY RENDER — DUAL-WRITE (plan 48-23). TRANSITION WINDOW
        // ONLY; the legacy half is deleted when the window closes.
        //
        // 48-22 chose ONE shape here from a cached capability probe, and that was
        // unsafe for a reason no probe design can fix: THE PROBE AND THE PAID
        // RENDER ARE DIFFERENT HTTP REQUESTS. A positive answer describes the
        // instance that served the probe, not the instance that will serve the
        // next call —
        //
        //   ROLLBACK      phasea reverts while `supported` is still cached (600s,
        //                 keyed only by base URL) and typed-only payloads keep
        //                 leaving this host;
        //   BLUE/GREEN    the probe hits the new instance, the render hits an old
        //                 one behind the same load balancer — no cache age needed;
        //   MIXED FLEET   one positive probe in a shared, persistent cache steers
        //                 every worker, and outlives a deploy of this host.
        //
        // In each of those an OLD phasea receives typed-only data. It does not
        // reject it — it has no rule for the key at all — so every placeholder
        // resolves to '' and a PAID completion comes back built from an
        // instruction with no topic, no profile and no research in it. Silent.
        //
        // So this host stopped needing to know which phasea answers. It sends
        // BOTH COMPLETE SHAPES, and whichever build is on the other end already
        // holds what it needs: an old phasea has no rule for `placeholder_data`
        // and Laravel ignores keys it has no rule for, so it honours
        // `placeholders`; a current phasea prefers the typed map (and since 48-23
        // only when that map is COMPLETE, so the two cannot half-mix).
        //
        // COST: one extra formatted map per render, built from data already in
        // memory. That is the entire price of making deploy order irrelevant.
        // ─────────────────────────────────────────────────────────────────────
        //
        // TYPED DATA, not formatted strings (plan 48-21, F5). phasea owns every
        // model-visible formatting decision these values feed.
        $payload['placeholder_data'] = $this->promptResolver->buildPlaceholders($run, $context);

        // The RETIRED formatted map, for a phasea that predates F5.
        $payload['placeholders'] = $this->promptResolver->buildLegacyPlaceholders($run, $context);

        // THE PROBE IS NOW OBSERVABILITY, NOT A DECISION. It no longer selects the
        // wire shape — nothing here branches on it — but "which contract is the
        // service on?" is still the question the window's exit is measured
        // against, so the answer is still asked for and recorded. It cannot throw
        // into this render (see {@see ObservesAiContracts}), and if it were
        // deleted outright the payload above would not change by a byte.
        app(ObservesAiContracts::class)->observeContract(
            ObservesAiContracts::TYPED_MODULE_PLACEHOLDERS,
            'blog_pipeline.module',
        );

        return $payload;
    }

    public function execute(BlogPostPipelineRun $run, ModuleContext $context): ModuleOutput
    {
        throw new \RuntimeException('Use ModuleExecutor to execute modules.');
    }

    public function verify(ModuleOutput $output, BlogPostPipelineRun $run): QualityResult
    {
        $criteria = $this->moduleRecord->quality_criteria ?? [];
        $issues = [];
        $wordCount = str_word_count(strip_tags($output->content));

        if (isset($criteria['min_words']) && $wordCount < (int) $criteria['min_words']) {
            $issues[] = "Content too short: {$wordCount} words (minimum: {$criteria['min_words']})";
        }

        if (isset($criteria['max_words']) && $wordCount > (int) $criteria['max_words']) {
            $issues[] = "Content too long: {$wordCount} words (maximum: {$criteria['max_words']})";
        }

        $passed = $issues === [];

        return new QualityResult(
            passed: $passed,
            overallScore: $passed ? 7.0 : 3.0,
            issues: $issues,
            decision: $passed ? QualityDecision::Pass : QualityDecision::RetrySame,
        );
    }

    public function getModuleRecord(): ContentModule
    {
        return $this->moduleRecord;
    }

    /**
     * Load internal link URLs already used by other modules in this pipeline run.
     *
     * @return array<string>
     */
    private function loadAlreadyLinkedUrls(BlogPostPipelineRun $run): array
    {
        /** @var array<string, array<string>> $allLinkedUrls */
        $allLinkedUrls = $run->context_json['linked_urls'] ?? [];

        return collect($allLinkedUrls)
            ->except($this->getSlug())
            ->flatten()
            ->unique()
            ->values()
            ->toArray();
    }

    /**
     * Load active profile lessons for the run's profile.
     *
     * @return array<string>
     */
    private function loadProfileLessons(BlogPostPipelineRun $run): array
    {
        $profile = $run->profile;

        if ($profile === null) {
            return [];
        }

        return BlogProfileLesson::query()
            ->where('profile_id', $profile->id)
            ->where('active', true)
            ->where('confidence', '>=', 0.5)
            ->pluck('lesson_text')
            ->toArray();
    }

    /**
     * Load critical and contextual AI knowledge for the run.
     *
     * @return array<string>
     */
    private function loadLearnedKnowledge(BlogPostPipelineRun $run): array
    {
        /** @var KnowledgeService $knowledgeService */
        $knowledgeService = app(KnowledgeService::class);

        $criticalKnowledge = $knowledgeService->getCriticalKnowledge();

        $candidateTitle = $run->candidate->title ?? '';
        $contextualKnowledge = $candidateTitle !== ''
            ? $knowledgeService->searchContextualKnowledge($candidateTitle)
            : collect();

        $allKnowledge = $criticalKnowledge->merge($contextualKnowledge)->unique('id');

        return $allKnowledge->pluck('content')->toArray();
    }

    /**
     * Load numbered research sources from the candidate's batch for citation injection.
     *
     * @return array<int, array{id: int, title: string, url: string}>
     */
    private function loadResearchSources(BlogPostPipelineRun $run): array
    {
        $batchId = $run->candidate->batch_id ?? null;

        if ($batchId === null) {
            return [];
        }

        return BlogTopicSource::query()
            ->where('batch_id', $batchId)
            ->whereNotNull('url')
            ->orderBy('id')
            ->limit(20)
            ->get()
            ->values()
            ->map(fn (BlogTopicSource $source, int $index): array => [
                'id' => $index + 1,
                'title' => $source->title ?? $source->domain ?? '',
                'url' => $source->url,
            ])
            ->toArray();
    }
}
