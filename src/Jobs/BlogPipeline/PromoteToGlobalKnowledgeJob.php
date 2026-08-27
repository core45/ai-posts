<?php

declare(strict_types=1);

namespace Core45\AiPosts\Jobs\BlogPipeline;

use Core45\AiCore\Enums\AIKnowledgeType;
use Core45\AiCore\Services\KnowledgeService;
use Core45\AiPosts\Jobs\BlogTopics\Concerns\NotifiesUser;
use Core45\AiPosts\Models\BlogProfileLesson;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Promote a high-performing profile lesson to global AI knowledge.
 *
 * A lesson qualifies when:
 * - confidence >= 0.8
 * - effectiveness_score >= 0.6
 * - times_applied >= 5
 */
class PromoteToGlobalKnowledgeJob implements ShouldQueue
{
    use NotifiesUser;
    use Queueable;

    public int $tries = 2;

    public int $timeout = 30;

    public function __construct(
        public BlogProfileLesson $lesson,
        public ?int $userId = null,
    ) {
        $this->onQueue('default');
    }

    public function handle(KnowledgeService $knowledgeService): void
    {
        $this->lesson->refresh();

        if (!$this->qualifiesForPromotion()) {
            Log::info('PromoteToGlobalKnowledgeJob: Lesson does not qualify for promotion', [
                'lesson_id' => $this->lesson->id,
                'confidence' => $this->lesson->confidence,
                'effectiveness_score' => $this->lesson->effectiveness_score,
                'times_applied' => $this->lesson->times_applied,
            ]);

            return;
        }

        $title = Str::limit($this->lesson->lesson_text, 80);

        $knowledge = $knowledgeService->create([
            'type' => AIKnowledgeType::Instruction,
            'tier' => 'contextual',
            'audience' => 'admin',
            'title' => $title,
            'content' => $this->lesson->lesson_text,
            'source_type' => 'suggested',
            'enabled' => false,
        ]);

        Log::info('PromoteToGlobalKnowledgeJob: Lesson promoted to global knowledge', [
            'lesson_id' => $this->lesson->id,
            'knowledge_id' => $knowledge->id,
            'title' => $title,
        ]);

        $this->notifyUser(
            __('Lesson Promoted for Review'),
            __('Profile lesson ":title" has been promoted to global knowledge (pending admin approval).', [
                'title' => $title,
            ]),
            'info',
        );
    }

    private function qualifiesForPromotion(): bool
    {
        return (float) $this->lesson->confidence >= 0.8
            && (float) ($this->lesson->effectiveness_score ?? 0) >= 0.6
            && (int) $this->lesson->times_applied >= 5;
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('PromoteToGlobalKnowledgeJob failed', [
            'lesson_id' => $this->lesson->id,
            'error' => $exception->getMessage(),
        ]);
    }
}
