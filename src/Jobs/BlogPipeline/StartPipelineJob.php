<?php

declare(strict_types=1);

namespace Core45\AiPosts\Jobs\BlogPipeline;

use Core45\AiPosts\Jobs\BlogTopics\Concerns\NotifiesUser;
use Core45\AiPosts\Models\BlogTopicCandidate;
use Core45\AiPosts\Models\BlogTopicProfile;
use Core45\AiPosts\Services\BlogPipeline\PipelineOrchestratorService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class StartPipelineJob implements ShouldBeUnique, ShouldQueue
{
    use NotifiesUser;
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(
        public BlogTopicCandidate $candidate,
        public BlogTopicProfile $profile,
        public ?int $userId = null,
    ) {
        $this->onQueue('ai-bulk');
    }

    public function uniqueId(): int
    {
        return $this->candidate->id;
    }

    public function handle(PipelineOrchestratorService $orchestrator): void
    {
        $this->notifyUser(
            __('Pipeline Started'),
            __('Blog pipeline started for ":title"', ['title' => $this->candidate->title]),
            'info',
        );

        $orchestrator->start($this->candidate, $this->profile);
    }

    public function failed(\Throwable $exception): void
    {
        $this->notifyUser(
            __('Pipeline Failed to Start'),
            __('Failed to start pipeline for ":title": :error', [
                'title' => $this->candidate->title,
                'error' => $exception->getMessage(),
            ]),
            'danger',
        );
    }
}
