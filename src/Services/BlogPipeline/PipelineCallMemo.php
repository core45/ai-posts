<?php

declare(strict_types=1);

namespace Core45\AiPosts\Services\BlogPipeline;

use Closure;
use Core45\AiCore\Services\RemoteAiClient;
use Core45\AiPosts\Models\BlogPostPipelineRun;
use Illuminate\Support\Facades\Log;
use Ramsey\Uuid\Uuid;

/**
 * A DETERMINISTIC operation id plus a DURABLE result memo for every billed
 * blog-pipeline call (plan 48-24, finding F2).
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * THE DEFECT THIS CLOSES, AND WHY IT IS A MONEY DEFECT.
 *
 * Every billed call on this surface used to mint `(string) Str::uuid()` INLINE at
 * its own call site — a v4 UUID, i.e. a fresh random value on every evaluation of
 * that line. `ExecuteModuleJob` declares `$tries = 5`, so each queue attempt sent
 * a DIFFERENT operation id for the SAME logical call, and phasea's idempotency
 * ledger (keyed on tenant + task + operation_id) could not recognise the second
 * attempt as a replay of the first. The provider call completes BEFORE any of the
 * bookkeeping that follows it, so ANY failure in that window — and 48-23's
 * dual-write doubled the payload serialised into the audit row's `prompt_text`
 * MEDIUMTEXT, which is the most likely failure in it — retried and PAID THE
 * PROVIDER AGAIN, silently, for output that had already been generated.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WHY A DETERMINISTIC ID IS NECESSARY BUT NOT SUFFICIENT, AND THE MEMO IS THE
 * OTHER HALF. READ THIS BEFORE CHANGING EITHER.
 *
 * phasea does NOT replay a completed operation — it REFUSES it. A second call on
 * a non-fresh reservation raises `DuplicateOperationException`
 * (`LaravelAiAgentRunner::runWithReservation()`), which the wire renders as 409
 * `operation_already_completed` / `operation_in_flight`. There is no endpoint that
 * hands back the earlier completion's text.
 *
 * So a deterministic id ALONE would convert "re-bill and succeed" into "do not
 * re-bill and fail the pipeline run permanently" — a different bug, not a fix.
 * The result is therefore persisted HERE, on the run itself, the instant the
 * provider answers and BEFORE any other bookkeeping runs. A retry finds the memo,
 * returns the stored answer, and never reaches the network at all: no second
 * provider call, no 409, and the run completes.
 *
 * The deterministic id remains load-bearing as the SECOND line of defence — for
 * the one case the memo cannot cover, where this host never received the response
 * (a true transport timeout). Then there is nothing to memoise, the retry sends
 * the same id, and phasea refuses it rather than billing twice. That is the
 * deliberate trade recorded in 48-24: a maybe-billed no-response failure becomes a
 * hard, visible module failure instead of a silent double charge.
 *
 * FAILURES PHASEA CAN PROVE WERE UNBILLED STAY RETRYABLE. `BudgetGuard` releases
 * the reservation on a `FailoverableException` (the vendor's 429/402/503 front-door
 * refusals), so a transient provider blip does not poison the deterministic id.
 * Only a failure of UNKNOWN spend outcome leaves the row non-Released, which is
 * exactly the set that must not be re-billed.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WHY `context_json` AND NOT A NEW TABLE. `BlogPostPipelineRun::setContextValue()`
 * is an existing, immediately-saving durable write on the row this work already
 * belongs to, and the memo has a lifetime of exactly one module execution. A new
 * table would outlive its purpose and need its own retention story. The entries
 * ARE pruned — {@see forgetModule()} runs on the LAST line of
 * `ExecuteModuleJob::handle()` — because an unbounded memo carrying full module
 * text would be the same defect one door over.
 */
final class PipelineCallMemo
{
    /**
     * Fixed UUIDv5 namespace for blog-pipeline operation ids.
     *
     * Following the existing host precedent for derived identifiers
     * (`RemoteAiClient::OBJECT_UUID_NAMESPACE`, `AbstractContentRewriteService`'s
     * per-field `Uuid::uuid5($parent, $scopeKey)`). A v5 UUID is a valid RFC-4122
     * UUID, so it satisfies phasea's `['required', 'uuid']` rule on every endpoint
     * a v4 id satisfied — the migration is invisible on the wire.
     *
     * NEVER CHANGE THIS VALUE. It is the identity of every in-flight operation:
     * rotating it re-mints every id and reopens the exact defect above for the
     * duration of one deploy.
     */
    private const string OPERATION_NAMESPACE = '9e6d1f2a-4c37-4b8e-a0d1-7f3c5b21e480';

    /** The `context_json` sub-key the memo lives under. */
    private const string CONTEXT_KEY = 'llm_memo';

    /**
     * The stable operation id for one logical billed call within a run.
     *
     * `$scopeKey` must name the call precisely enough that two DIFFERENT billed
     * calls never collide, and stably enough that the SAME call across queue
     * attempts derives the same value. Quality retries are different calls (each
     * is separately billed and separately wanted), so the attempt number is part
     * of the module scope key; a job retry re-runs from attempt 1 and therefore
     * re-derives attempt 1's id.
     */
    public function operationId(BlogPostPipelineRun $run, string $scopeKey): string
    {
        return Uuid::uuid5(self::OPERATION_NAMESPACE, "blog-pipeline:{$run->id}:{$scopeKey}")->toString();
    }

    /**
     * Run a billed call once per (run, scope), or return the stored answer.
     *
     * THE ORDER INSIDE IS THE WHOLE POINT. The callback returns, the result is
     * written durably, and only then does the caller do anything else. Every line
     * a caller runs after this method is inside the "already paid for" region, and
     * a failure there costs a retry rather than a second completion.
     *
     * @param  Closure(string $operationId): array<string, mixed>  $call
     * @return array{fresh: bool, result: array<string, mixed>}
     */
    public function remember(BlogPostPipelineRun $run, string $moduleSlug, string $scopeKey, Closure $call): array
    {
        $operationId = $this->operationId($run, $scopeKey);
        $stored = $this->recall($run, $operationId);

        if ($stored !== null) {
            Log::info('PipelineCallMemo: replaying a completed billed call instead of re-issuing it', [
                'pipeline_run_id' => $run->id,
                'module_slug' => $moduleSlug,
                'scope' => $scopeKey,
                'operation_id' => $operationId,
            ]);

            return ['fresh' => false, 'result' => $stored];
        }

        $result = $call($operationId);

        $this->store($run, $moduleSlug, $operationId, $result);

        return ['fresh' => true, 'result' => $result];
    }

    /**
     * Drop every memo entry belonging to a module, once NOTHING CAN RETRY IT.
     *
     * Called from the last line of `ExecuteModuleJob::handle()`, deliberately not
     * from the executor's persistence step — see the comment there. Pruning as soon
     * as `module_outputs` is written leaves `trackLinkedUrls()` and
     * `afterModule()` running unprotected, and a failure in either would retry a
     * job whose memo had just been deleted: the same re-bill, one door later.
     */
    public function forgetModule(BlogPostPipelineRun $run, string $moduleSlug): void
    {
        $context = $run->context_json ?? [];
        $memo = $context[self::CONTEXT_KEY] ?? null;

        if (!is_array($memo) || $memo === []) {
            return;
        }

        $kept = array_filter(
            $memo,
            static fn (mixed $entry): bool => !is_array($entry) || ($entry['module_slug'] ?? null) !== $moduleSlug,
        );

        if (count($kept) === count($memo)) {
            return;
        }

        $context[self::CONTEXT_KEY] = $kept;
        $run->context_json = $context;
        $run->save();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function recall(BlogPostPipelineRun $run, string $operationId): ?array
    {
        $entry = ($run->context_json ?? [])[self::CONTEXT_KEY][$operationId] ?? null;

        if (!is_array($entry)) {
            return null;
        }

        $result = $entry['result'] ?? null;

        return is_array($result) ? $result : null;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function store(BlogPostPipelineRun $run, string $moduleSlug, string $operationId, array $result): void
    {
        // A UUID carries no `.`, so the dot-notation write cannot nest by
        // accident — the operation id stays one flat key.
        $run->setContextValue(self::CONTEXT_KEY.'.'.$operationId, [
            'module_slug' => $moduleSlug,
            'stored_at' => now()->toIso8601String(),
            'result' => $result,
        ]);
    }
}
