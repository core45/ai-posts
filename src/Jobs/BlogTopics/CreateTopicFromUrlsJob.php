<?php

declare(strict_types=1);

namespace Core45\AiPosts\Jobs\BlogTopics;

use Core45\AiCore\Contracts\AiClient;
use Core45\AiCore\Exceptions\AiRateLimitedException;
use Core45\AiCore\Exceptions\AiUnavailableException;
use Core45\AiPosts\Jobs\BlogTopics\Concerns\NotifiesUser;
use Core45\AiPosts\Models\BlogTopicProfile;
use Core45\AiPosts\Services\BlogTopics\UrlTopicService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class CreateTopicFromUrlsJob implements ShouldQueue
{
    use NotifiesUser;
    use Queueable;

    public int $tries = 10;

    public int $maxExceptions = 3;

    /** @var array<int, int> */
    public array $backoff = [10, 30, 60];

    public int $timeout = 300;

    public readonly string $operationId;

    /**
     * @param  array<string>  $urls
     */
    public function __construct(
        public BlogTopicProfile $profile,
        public array $urls,
        public ?string $pastedText = null,
        public ?int $userId = null,
    ) {
        $this->onQueue('ai-bulk');
        $this->operationId = (string) Str::uuid();
    }

    /**
     * NOTE: `AiClient` is injected as a documented seam placeholder. The
     * actual AI work is delegated to
     * `UrlTopicService::createTopicFromContent()`, which owns its own seam
     * call (routes through `completeText()`). `operationId` is minted once
     * per dispatch and logged, but not forwarded into the service (its
     * signature is unowned here).
     */
    public function handle(UrlTopicService $service, AiClient $client): void
    {
        Log::info('CreateTopicFromUrlsJob: Starting', [
            'profile_id' => $this->profile->id,
            'url_count' => count($this->urls),
            'has_pasted_text' => $this->pastedText !== null,
            'operation_id' => $this->operationId,
        ]);

        try {
            $candidate = $service->createTopicFromContent(
                profile: $this->profile,
                urls: $this->urls,
                pastedText: $this->pastedText,
            );

            Log::info('CreateTopicFromUrlsJob: Completed', [
                'profile_id' => $this->profile->id,
                'candidate_id' => $candidate->id,
            ]);

            $this->notifyUser(
                __('Topic created from URLs'),
                __('A new topic ":title" has been synthesized from the provided content.', [
                    'title' => $candidate->title,
                ]),
                'success',
            );
        } catch (AiRateLimitedException $e) {
            $retryAfter = $e->retryAfter > 0 ? $e->retryAfter : 60;

            Log::warning('CreateTopicFromUrlsJob: Rate limited', [
                'profile_id' => $this->profile->id,
                'retry_after_seconds' => $retryAfter,
            ]);

            $this->release($retryAfter);
        } catch (AiUnavailableException $e) {
            Log::warning('CreateTopicFromUrlsJob: Provider overloaded', [
                'profile_id' => $this->profile->id,
            ]);

            $this->release(60);
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('CreateTopicFromUrlsJob: Permanently failed', [
            'profile_id' => $this->profile->id,
            'error' => $exception->getMessage(),
        ]);

        $this->notifyUser(
            __('Topic creation from URLs failed'),
            __('Failed to create topic from URLs for profile #:id: :error', [
                'id' => $this->profile->id,
                'error' => $exception->getMessage(),
            ]),
            'danger',
        );
    }
}
