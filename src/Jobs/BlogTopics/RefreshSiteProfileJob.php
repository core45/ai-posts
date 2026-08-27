<?php

declare(strict_types=1);

namespace Core45\AiPosts\Jobs\BlogTopics;

use Core45\AiCore\Contracts\AiClient;
use Core45\AiCore\Exceptions\AiRateLimitedException;
use Core45\AiCore\Exceptions\AiUnavailableException;
use Core45\AiPosts\Jobs\BlogTopics\Concerns\NotifiesUser;
use Core45\AiPosts\Models\BlogTopicProfile;
use Core45\AiPosts\Services\BlogTopics\SiteProfileService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class RefreshSiteProfileJob implements ShouldQueue
{
    use NotifiesUser;
    use Queueable;

    public int $tries = 10;

    public int $maxExceptions = 3;

    /** @var array<int, int> */
    public array $backoff = [10, 30, 60];

    public int $timeout = 300;

    public readonly string $operationId;

    public function __construct(
        public BlogTopicProfile $profile,
        public ?int $userId = null,
    ) {
        $this->onQueue('ai-bulk');
        $this->operationId = (string) Str::uuid();
    }

    /**
     * NOTE: `AiClient` is injected as a documented seam placeholder.
     * `SiteProfileService::buildProfile()` owns its own seam call (routes
     * through `completeText()`); `operationId` is minted once per dispatch
     * and logged, but not forwarded into the service.
     */
    public function handle(SiteProfileService $service, AiClient $client): void
    {
        Log::info('RefreshSiteProfileJob: Starting', [
            'profile_id' => $this->profile->id,
            'operation_id' => $this->operationId,
        ]);

        try {
            $service->buildProfile($this->profile);

            $this->notifyUser(
                __('Profile refreshed successfully'),
                __('Site profile ":name" has been refreshed.', [
                    'name' => $this->profile->name,
                ]),
                'success',
            );
        } catch (AiRateLimitedException $e) {
            $retryAfter = $e->retryAfter > 0 ? $e->retryAfter : 60;

            Log::warning('RefreshSiteProfileJob: Rate limited', [
                'profile_id' => $this->profile->id,
                'retry_after_seconds' => $retryAfter,
            ]);

            $this->release($retryAfter);
        } catch (AiUnavailableException $e) {
            Log::warning('RefreshSiteProfileJob: Provider overloaded', [
                'profile_id' => $this->profile->id,
            ]);

            $this->release(60);
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('RefreshSiteProfileJob: Permanently failed', [
            'profile_id' => $this->profile->id,
            'error' => $exception->getMessage(),
        ]);

        $this->notifyUser(
            __('Profile refresh failed'),
            __('Failed to refresh site profile ":name": :error', [
                'name' => $this->profile->name,
                'error' => $exception->getMessage(),
            ]),
            'danger',
        );
    }
}
