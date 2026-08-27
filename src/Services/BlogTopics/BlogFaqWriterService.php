<?php

declare(strict_types=1);

namespace Core45\AiPosts\Services\BlogTopics;

use Astrotomic\Translatable\Contracts\Translatable;
use Core45\AiCore\Contracts\ProvidesMandatoryWords;
use Core45\AiCore\Enums\AiSurface;
use Core45\AiCore\ModelRegistry;
use Core45\AiCore\Services\AiClientFactory;
use Core45\AiPosts\Enums\BlogTopicLlmStep;
use Core45\AiPosts\Models\BlogTopicCandidate;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class BlogFaqWriterService
{
    /** phasea-owned prompt (v2.6 §2.5) — this host names it, never authors it. */
    private const BLOG_FAQ_TEMPLATE = 'blog_topics.blog_faq';

    private string $provider;

    private string $model;

    public function __construct(
        private readonly LlmAuditService $auditService,
    ) {
        // v2.6: the host cannot know which model ran — phasea routes the task and
        // AiTextResult carries only text + token counts. Record the truthful
        // provider ('phasea') and an explicit unknown rather than a config guess.
        // FOLLOW-UP: have phasea return provider/model on AiTextResult so these
        // audit rows become accurate instead of merely honest.
        $this->provider = 'phasea';
        $this->model = 'unknown';
    }

    /**
     * Generate FAQ items for a blog post.
     *
     * `$operationId` is the calling job's dispatch-stable id (audit 5.4):
     * forwarding it makes retries replay the same id so phasea's usage
     * metering does not re-bill. When null (direct/manual calls) a fresh
     * UUID is minted per LLM call, matching the previous behavior.
     *
     * @return array<int, Model>
     */
    public function generate(Model $post, BlogTopicCandidate $candidate, ?string $operationId = null): array
    {
        if (!$candidate->generate_faq) {
            return [];
        }

        /** @var array{enabled: bool, count: int}|null $faqConfig */
        $faqConfig = $candidate->faq_config;
        if ($faqConfig === null || !($faqConfig['enabled'] ?? false)) {
            return [];
        }

        $count = min(max((int) ($faqConfig['count'] ?? 5), 3), 10);

        // Pipeline posts are written in the profile's primary locale (e.g. pl),
        // which may differ from the running app locale (e.g. en). Resolve the
        // locale that actually has content instead of assuming app.locale.
        $contentLocale = $this->resolveContentLocale($post);

        if ($contentLocale === null) {
            Log::warning('BlogFaqWriterService: No translation with content found for post', [
                'post_id' => $post->id, // @phpstan-ignore property.notFound
                'app_locale' => (string) config('app.locale', 'en'),
            ]);

            return [];
        }

        $translation = $post instanceof Translatable ? $post->translate($contentLocale) : null;

        if ($translation === null) {
            return [];
        }

        $title = $translation->title ?? '';
        $mainText = $translation->main_text ?? '';
        $excerpt = $translation->excerpt ?? '';

        $parameters = $this->buildParameters($title, $mainText, $excerpt, $count, $contentLocale);
        $responseText = $this->callLlm($parameters, $candidate, $operationId);
        $faqData = $this->parseResponse($responseText, $count);

        if (empty($faqData)) {
            Log::warning('BlogFaqWriterService: No FAQs parsed from LLM response', [
                'post_id' => $post->id, // @phpstan-ignore property.notFound
                'candidate_id' => $candidate->id,
            ]);

            return [];
        }

        return $this->createFaqRecords($post, $faqData, $contentLocale);
    }

    /**
     * Resolve the locale whose translation actually carries the post body:
     * the app locale when it has content, otherwise the first configured locale
     * whose translation has a non-empty main_text. Null when none has content.
     */
    private function resolveContentLocale(Model $post): ?string
    {
        $appLocale = (string) config('app.locale', 'en');

        if ($this->translationHasContent($post, $appLocale)) {
            return $appLocale;
        }

        /** @var array<string, mixed> $supported */
        $supported = config('laravellocalization.supportedLocales', []);

        foreach (array_keys($supported) as $locale) {
            if ($this->translationHasContent($post, (string) $locale)) {
                return (string) $locale;
            }
        }

        return null;
    }

    private function translationHasContent(Model $post, string $locale): bool
    {
        $translation = $post instanceof Translatable ? $post->translate($locale) : null;

        return $translation !== null && trim((string) ($translation->main_text ?? '')) !== '';
    }

    /**
     * The template input for the phasea-owned FAQ prompt.
     *
     * Tag stripping and the 4 000-character truncation stay HERE: they are a
     * cost control over tenant content, complementary to the template's own
     * bound on the same field rather than replaced by it (D-48-2).
     *
     * @return array<string, mixed>
     */
    private function buildParameters(string $title, string $mainText, string $excerpt, int $count, string $contentLocale): array
    {
        // Strip HTML tags for cleaner context
        $plainText = strip_tags($mainText);
        // Truncate to avoid token limits
        if (strlen($plainText) > 4000) {
            $plainText = substr($plainText, 0, 4000).'...';
        }

        return [
            'title' => $title,
            'excerpt' => $excerpt,
            'main_text' => $plainText,
            'count' => $count,
            // The resolved locale travels as DATA. The host does not phrase a
            // language sentence — the template does.
            'content_locale' => $contentLocale,
            // Store-wide mandatory words, so phasea's anti-slop ban list yields to
            // a word the brand requires (phase 50, D-50-1). Absent when the store
            // mandates nothing.
            ...app(ProvidesMandatoryWords::class)->asProfileParameter(),
        ];
    }

    /**
     * Run the phasea-owned FAQ template and return raw response text.
     *
     * @param  array<string, mixed>  $parameters
     */
    protected function callLlm(array $parameters, BlogTopicCandidate $candidate, ?string $operationId = null): string
    {
        $startTime = microtime(true);

        $client = app(AiClientFactory::class)->for(AiSurface::BlogTopics);
        // The timeout budget rides the transport-only `options` argument, not
        // the template parameters — dropping it here would silently move this
        // generation onto the 30s `AI_SERVICE_TIMEOUT` default.
        $result = $client->runPrompt(
            self::BLOG_FAQ_TEMPLATE,
            $parameters,
            // Dispatch-stable when the calling job supplies one, so a retry
            // replays the same id and phasea's metering does not re-bill.
            $operationId ?? (string) Str::uuid(),
            [
                'timeout' => config('ai.assistant.timeout', 300),
            ],
        );

        $durationMs = (int) ((microtime(true) - $startTime) * 1000);

        $this->auditService->log(
            step: BlogTopicLlmStep::Faq,
            provider: $this->provider,
            model: $this->model,
            // The prompt lives in phasea now, so there is none to record here.
            // The template key plus the parameters IS the reproducible input
            // (D-48-6).
            prompt: json_encode([
                'template' => self::BLOG_FAQ_TEMPLATE,
                'parameters' => $parameters,
            ], JSON_UNESCAPED_UNICODE) ?: self::BLOG_FAQ_TEMPLATE,
            response: $result->text,
            candidateId: $candidate->id,
            batchId: $candidate->batch_id,
            tokensIn: $result->promptTokens,
            tokensOut: $result->completionTokens,
            durationMs: $durationMs,
        );

        return $result->text;
    }

    /**
     * Parse LLM response into FAQ data array.
     *
     * @return array<int, array{question: string, answer: string}>
     */
    private function parseResponse(string $responseText, int $maxCount): array
    {
        $cleaned = preg_replace('/```(?:json)?\s*/', '', $responseText);
        $cleaned = trim((string) $cleaned);

        $data = [];
        if (preg_match('/\[[\s\S]*\]/', $cleaned, $matches)) {
            $decoded = json_decode($matches[0], true);
            if (is_array($decoded)) {
                $data = $decoded;
            }
        }

        // Filter valid entries and cap at max count
        $valid = [];
        foreach ($data as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $question = trim((string) ($entry['question'] ?? ''));
            $answer = trim((string) ($entry['answer'] ?? ''));
            if ($question !== '' && $answer !== '') {
                $valid[] = ['question' => $question, 'answer' => $answer];
            }
            if (count($valid) >= $maxCount) {
                break;
            }
        }

        return $valid;
    }

    /**
     * Create Faq records and attach to post.
     *
     * @param  array<int, array{question: string, answer: string}>  $faqData
     * @return array<int, Model>
     */
    private function createFaqRecords(Model $post, array $faqData, string $locale): array
    {
        $faqClass = app(ModelRegistry::class)->getModelClassOrFail('faq');
        $faqs = [];

        foreach ($faqData as $index => $item) {
            $faq = $faqClass::create([
                'faq_category_id' => null,
                'display_location' => config('ai-posts.taxonomy.faq_display_location', 'none'),
                'sort_order' => $index,
                'active' => true,
            ]);

            if ($faq instanceof Translatable) {
                $faqTranslation = $faq->translateOrNew($locale);
                $faqTranslation->question = $item['question']; // @phpstan-ignore property.notFound
                $faqTranslation->answer = $item['answer']; // @phpstan-ignore property.notFound
            }
            $faq->save();

            $post->faqs()->attach($faq->id, ['sort_order' => $index]); // @phpstan-ignore method.notFound, property.notFound

            $faqs[] = $faq;
        }

        return $faqs;
    }
}
