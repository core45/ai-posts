<?php

declare(strict_types=1);

namespace Core45\AiPosts\Services\BlogTopics;

use Core45\AiCore\Enums\AiSurface;
use Core45\AiCore\Services\AiClientFactory;
use Core45\AiPosts\Enums\BlogTopicLlmStep;
use Core45\AiPosts\Models\BlogTopicCandidate;
use Core45\AiPosts\Models\BlogTopicProfile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class UrlTopicService
{
    /** phasea-owned prompt (v2.6 §2.5) — this host names it, never authors it. */
    private const URL_TOPIC_TEMPLATE = 'blog_topics.url_topic_synthesis';

    private string $provider;

    private string $model;

    public function __construct(
        private readonly LlmAuditService $auditService,
        private readonly ManualTopicService $manualTopicService,
    ) {
        // v2.6: phasea routes this task; the host cannot know the model.
        // AiTextResult carries only text + tokens. Record the truthful provider
        // and an explicit unknown rather than a config guess.
        $this->provider = 'phasea';
        $this->model = 'unknown';
    }

    /**
     * Create a topic candidate from fetched URL content and optional pasted text.
     *
     * @param  array<string>  $urls
     */
    public function createTopicFromContent(
        BlogTopicProfile $profile,
        array $urls,
        ?string $pastedText = null,
    ): BlogTopicCandidate {
        $fetchedSources = [];

        foreach ($urls as $url) {
            $result = $this->fetchUrlContent($url);
            if ($result !== null) {
                $fetchedSources[] = $result;
            }
        }

        if ($pastedText !== null && trim($pastedText) !== '') {
            $fetchedSources[] = [
                'url' => 'user://pasted-text',
                'title' => 'User-provided text',
                'content' => trim($pastedText),
            ];
        }

        if ($fetchedSources === []) {
            throw new \RuntimeException('No content could be fetched from the provided URLs and no pasted text was given.');
        }

        $synthesized = $this->synthesizeTopic($profile, $fetchedSources);

        $sources = array_map(fn (array $source): array => [
            'url' => $source['url'],
            'title' => $source['title'] ?? null,
            'snippet' => mb_substr($source['content'], 0, 500),
        ], $fetchedSources);

        return $this->manualTopicService->createManualTopic(
            profile: $profile,
            title: $synthesized['title'],
            intent: $synthesized['intent'],
            rationale: $synthesized['rationale'],
            sources: $sources,
            outline: $synthesized['outline'],
            batchType: 'url_synthesis',
        );
    }

    /**
     * Fetch content from a URL and extract readable text.
     *
     * @return array{url: string, title: string|null, content: string}|null
     */
    public function fetchUrlContent(string $url): ?array
    {
        if (!$this->isSafeUrl($url)) {
            Log::warning('UrlTopicService: Blocked unsafe URL', ['url' => $url]);

            return null;
        }

        try {
            $response = Http::timeout(30)
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (compatible; BlogTopicBot/1.0)',
                ])
                ->get($url);

            if (!$response->successful()) {
                Log::warning('UrlTopicService: Failed to fetch URL', [
                    'url' => $url,
                    'status' => $response->status(),
                ]);

                return null;
            }

            $html = $response->body();
            $content = $this->extractReadableText($html);

            if (trim($content) === '') {
                Log::warning('UrlTopicService: No readable content extracted', ['url' => $url]);

                return null;
            }

            $title = $this->extractTitle($html);

            return [
                'url' => $url,
                'title' => $title,
                'content' => mb_substr($content, 0, 10000),
            ];
        } catch (\Throwable $e) {
            Log::warning('UrlTopicService: Exception fetching URL', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Extract readable text from HTML, preferring article/main content.
     */
    public function extractReadableText(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }

        libxml_use_internal_errors(true);
        $dom = new \DOMDocument;
        $dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();

        $this->removeElements($dom, ['script', 'style', 'nav', 'footer', 'header', 'aside', 'noscript']);

        $xpath = new \DOMXPath($dom);

        /** @var \DOMNodeList<\DOMElement> $articles */
        $articles = $xpath->query('//article');
        if ($articles->length > 0) {
            return $this->getNodeText($articles->item(0));
        }

        /** @var \DOMNodeList<\DOMElement> $mains */
        $mains = $xpath->query('//main');
        if ($mains->length > 0) {
            return $this->getNodeText($mains->item(0));
        }

        $body = $dom->getElementsByTagName('body');
        if ($body->length > 0) {
            return $this->getNodeText($body->item(0));
        }

        return strip_tags($html);
    }

    /**
     * Synthesize a single topic from fetched sources using the LLM.
     *
     * @param  array<array{url: string, title: string|null, content: string}>  $fetchedSources
     * @return array{title: string, intent: string, rationale: string, outline: array<mixed>}
     */
    public function synthesizeTopic(BlogTopicProfile $profile, array $fetchedSources): array
    {
        // The fetched pages cross the wire as DATA. Numbering, key naming and
        // encoding are presentation and now belong to the phasea template; the
        // 5 000-character cap stays here as a cost control, complementing the
        // template's own bound on the same field (D-48-2).
        $sourcesForPrompt = array_map(fn (array $s): array => [
            'url' => $s['url'],
            'title' => $s['title'],
            'text' => mb_substr($s['content'], 0, 5000),
        ], $fetchedSources);

        /** @var array<string>|null $keywords */
        $keywords = $profile->seed_keywords;

        $parameters = [
            'site_profile' => $profile->profile_json ?? [],
            'seed_keywords' => is_array($keywords) ? array_values($keywords) : [],
            'fetched_sources' => $sourcesForPrompt,
        ];

        $startTime = microtime(true);

        $client = app(AiClientFactory::class)->for(AiSurface::BlogTopics);
        // The timeout budget rides the transport-only `options` argument, not
        // the template parameters — dropping it here would silently move this
        // generation onto the 30s `AI_SERVICE_TIMEOUT` default.
        $result = $client->runPrompt(
            self::URL_TOPIC_TEMPLATE,
            $parameters,
            (string) Str::uuid(),
            [
                'timeout' => config('ai.assistant.timeout', 300),
            ],
        );

        $durationMs = (int) ((microtime(true) - $startTime) * 1000);

        $this->auditService->log(
            step: BlogTopicLlmStep::UrlSynthesis,
            provider: $this->provider,
            model: $this->model,
            // The prompt lives in phasea now, so there is none to record here.
            // The template key plus the parameters IS the reproducible input
            // (D-48-6).
            prompt: json_encode([
                'template' => self::URL_TOPIC_TEMPLATE,
                'parameters' => $parameters,
            ], JSON_UNESCAPED_UNICODE) ?: self::URL_TOPIC_TEMPLATE,
            response: $result->text,
            tokensIn: $result->promptTokens,
            tokensOut: $result->completionTokens,
            durationMs: $durationMs,
        );

        return $this->parseJsonObjectResponse($result->text);
    }

    /**
     * @return array{title: string, intent: string, rationale: string, outline: array<mixed>}
     */
    private function parseJsonObjectResponse(string $responseText): array
    {
        $cleaned = preg_replace('/```(?:json)?\s*/', '', $responseText);
        $cleaned = trim((string) $cleaned);

        if (preg_match('/\{[\s\S]*\}/', $cleaned, $matches)) {
            $decoded = json_decode($matches[0], true);
            if (is_array($decoded) && isset($decoded['title'])) {
                return [
                    'title' => (string) $decoded['title'],
                    'intent' => (string) ($decoded['intent'] ?? 'informational'),
                    'rationale' => (string) ($decoded['rationale'] ?? ''),
                    'outline' => is_array($decoded['outline'] ?? null) ? $decoded['outline'] : [],
                ];
            }
        }

        throw new \RuntimeException('Failed to parse LLM response as a valid topic JSON object.');
    }

    private function extractTitle(string $html): ?string
    {
        if (preg_match('/<title[^>]*>(.*?)<\/title>/si', $html, $matches)) {
            return html_entity_decode(trim($matches[1]), ENT_QUOTES, 'UTF-8');
        }

        return null;
    }

    /**
     * @param  array<string>  $tagNames
     */
    private function removeElements(\DOMDocument $dom, array $tagNames): void
    {
        foreach ($tagNames as $tag) {
            $elements = $dom->getElementsByTagName($tag);
            $toRemove = [];
            foreach ($elements as $element) {
                $toRemove[] = $element;
            }
            foreach ($toRemove as $element) {
                $element->parentNode?->removeChild($element);
            }
        }
    }

    private function getNodeText(?\DOMNode $node): string
    {
        if ($node === null) {
            return '';
        }

        $text = $node->textContent ?? '';
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;

        return trim($text);
    }

    /**
     * Validate that a URL is safe to fetch (prevent SSRF attacks).
     */
    private function isSafeUrl(string $url): bool
    {
        $parsed = parse_url($url);

        if (!isset($parsed['scheme']) || !in_array($parsed['scheme'], ['http', 'https'], true)) {
            return false;
        }

        $host = $parsed['host'] ?? '';
        if ($host === '') {
            return false;
        }

        $ip = gethostbyname($host);

        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }
}
