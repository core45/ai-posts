<?php

declare(strict_types=1);

namespace Core45\AiPosts\Support;

use Illuminate\Support\Facades\Log;

/**
 * Resolve the optional host jobs the pipeline hands off to.
 *
 * Each is configured by class name (`config('ai-posts.jobs.*')`) rather than
 * imported, for the same reason ai-core configures `ai.finalize_job`: these
 * jobs edit host records and use host infrastructure, so naming them is the
 * application's job.
 *
 * All three are optional, and null is handled here rather than at five call
 * sites. Two rules the §9 scope-honesty note makes non-negotiable:
 *
 *  - never substitute a guessed class name for a missing one. A silent
 *    fallback runs the wrong job, which is worse than running none;
 *  - never skip quietly. A configured-away hand-off is a real reduction in
 *    what the pipeline does, so every skip is logged at warning with the
 *    config key that would restore it. A step that silently does nothing looks
 *    identical to a step that worked.
 *
 * A configured class that does not exist is a misconfiguration, not an absence,
 * and is logged at error — the package will not pretend it was never set.
 */
final class HostJobs
{
    /**
     * @return class-string|null
     */
    public static function resolve(string $key): ?string
    {
        $configKey = "ai-posts.jobs.{$key}";
        $class = config($configKey);

        if ($class === null || $class === '') {
            Log::warning("ai-posts: no job configured for {$configKey}; skipping that step.", [
                'config_key' => $configKey,
            ]);

            return null;
        }

        if (!is_string($class) || !class_exists($class)) {
            Log::error("ai-posts: {$configKey} names a class that does not exist; skipping that step.", [
                'config_key' => $configKey,
                'configured' => is_string($class) ? $class : gettype($class),
            ]);

            return null;
        }

        return $class;
    }

    /**
     * Queue middleware for a pipeline job.
     *
     * Returns an empty array when unconfigured, which is a real and safe
     * degradation: the job still runs, it just retries on the queue's terms
     * rather than the AI provider's.
     *
     * @return array<int, object>
     */
    public static function rateLimitMiddleware(): array
    {
        $class = self::resolve('rate_limit_middleware');

        return $class === null ? [] : [new $class];
    }
}
