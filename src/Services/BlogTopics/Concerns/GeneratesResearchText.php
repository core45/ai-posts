<?php

declare(strict_types=1);

namespace Core45\AiPosts\Services\BlogTopics\Concerns;

use Core45\AiCore\DTOs\AiTextResult;
use Core45\AiCore\Enums\AiSurface;
use Core45\AiCore\Services\AiClientFactory;
use Illuminate\Support\Str;

/**
 * Runs the blog-research LLM text calls through the converged `AiClient` seam.
 *
 * Phase 16-05: the client-side primary/fallback-provider retry walk (fail
 * over from the configured primary research provider to a secondary one on
 * a transient outage) is REMOVED. phasea selects its own model behind a
 * single opaque endpoint (D17) -- a client-side "try provider B" concept has
 * no analog there. Job-level retry/backoff via `AiRateLimitedException`/
 * `AiUnavailableException` (already the correct vocabulary for this call
 * path's consumers since Plan 16-04) is now the sole retry mechanism.
 */
trait GeneratesResearchText
{
    /**
     * Run a PHASEA-OWNED prompt template with structured parameters (v2.6 §2.5).
     *
     * The replacement for the deleted `generateResearchText()`/`runResearchPrompt()`
     * pair: callers name a template phasea owns instead of assembling instruction
     * text here. `provider`/`model` are advisory labels carried through for
     * audit-log continuity — phasea selects its own model server-side, so they
     * are recorded, not honored remotely.
     *
     * PLAN 48-20 DELETED THE `completeText()` PAIR THIS REPLACED. Once every
     * research caller had migrated, `generateResearchText()` was a PRIVATE trait
     * method with zero callers and `runResearchPrompt()` was called only by it —
     * dead code whose only remaining effect was to make `completeText()` look
     * like it still had a non-translate production caller. Removing it is what
     * makes `NoNonTranslateCompleteTextCallerTest`'s assertion structurally true
     * rather than a documented exception.
     *
     * `$options` is transport-only (a per-call `timeout` budget) and is never
     * sent to phasea: a surface that used to run on a 300s completion budget
     * must pass it here, or migrating drops it to the 30s `AI_SERVICE_TIMEOUT`
     * default and converts a working generation into a timeout.
     *
     * @param  array<string, mixed>  $parameters
     * @param  array<string, mixed>  $options
     * @return array{response: AiTextResult, provider: string, model: string}
     */
    protected function runPromptTemplate(string $template, array $parameters, array $options = []): array
    {
        $client = app(AiClientFactory::class)->for(AiSurface::Research);

        return [
            'response' => $client->runPrompt($template, $parameters, (string) Str::uuid(), $options),
            'provider' => 'phasea',
            'model' => 'unknown',
        ];
    }
}
