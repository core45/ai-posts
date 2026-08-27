<?php

declare(strict_types=1);

namespace Core45\AiPosts\Jobs\BlogTopics;

use Core45\AiCore\Contracts\AiClient;
use Core45\AiCore\Exceptions\AiRateLimitedException;
use Core45\AiCore\Exceptions\AiUnavailableException;
use Core45\AiPosts\Jobs\BlogTopics\Concerns\NotifiesUser;
use Core45\AiPosts\Models\BlogTopicCandidate;
use Core45\AiPosts\Services\BlogTopics\BlogFaqWriterService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class GenerateBlogFaqJob implements ShouldBeUnique, ShouldQueue
{
    use NotifiesUser;
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    /** @var array<int, int> */
    public array $backoff = [15, 30, 60];

    public readonly string $operationId;

    public function __construct(
        public Model $post,
        public BlogTopicCandidate $candidate,
        public ?int $userId = null,
    ) {
        $this->onQueue('ai-bulk');
        $this->operationId = (string) Str::uuid();
    }

    public function uniqueId(): int
    {
        return (int) $this->post->getKey();
    }

    /**
     * NOTE: `AiClient` is injected as a documented seam placeholder.
     * `BlogFaqWriterService::generate()` owns its own seam call (routes
     * through `completeText()`); `operationId` is minted once per dispatch
     * and forwarded into the service (audit 5.4) so retries replay the same
     * id and phasea's usage metering does not re-bill the LLM call.
     */
    public function handle(BlogFaqWriterService $faqService, AiClient $client): void
    {
        if (!$this->candidate->generate_faq) {
            return;
        }

        try {
            $faqs = $faqService->generate($this->post, $this->candidate, $this->operationId);

            if (empty($faqs)) {
                Log::info('GenerateBlogFaqJob: No FAQs generated', [
                    'post_id' => $this->post->getKey(),
                    'candidate_id' => $this->candidate->id,
                ]);

                return;
            }

            Log::info('GenerateBlogFaqJob: FAQs generated', [
                'post_id' => $this->post->getKey(),
                'candidate_id' => $this->candidate->id,
                'count' => count($faqs),
                'operation_id' => $this->operationId,
            ]);

            $this->notifyUser(
                __('FAQs generated for blog post'),
                __(':count FAQ items generated for post: :title', [
                    'count' => count($faqs),
                    'title' => $this->candidate->title,
                ]),
                'success',
            );
        } catch (AiRateLimitedException $e) {
            $retryAfter = $e->retryAfter > 0 ? $e->retryAfter : 60;

            Log::warning('GenerateBlogFaqJob: Rate limited', [
                'post_id' => $this->post->getKey(),
                'candidate_id' => $this->candidate->id,
                'retry_after_seconds' => $retryAfter,
            ]);

            $this->release($retryAfter);
        } catch (AiUnavailableException $e) {
            Log::warning('GenerateBlogFaqJob: Provider overloaded', [
                'post_id' => $this->post->getKey(),
                'candidate_id' => $this->candidate->id,
            ]);

            $this->release(60);
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('GenerateBlogFaqJob: Failed', [
            'post_id' => $this->post->getKey(),
            'candidate_id' => $this->candidate->id,
            'error' => $exception->getMessage(),
        ]);
    }
}
