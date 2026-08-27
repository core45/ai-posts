<?php

declare(strict_types=1);

namespace Core45\AiPosts\Services\BlogTopics;

use Core45\AiPosts\Enums\BlogTopicLlmStep;
use Core45\AiPosts\Models\BlogTopicLlmRun;

class LlmAuditService
{
    public function log(
        BlogTopicLlmStep $step,
        string $provider,
        string $model,
        string $prompt,
        string $response,
        ?int $batchId = null,
        ?int $candidateId = null,
        ?int $tokensIn = null,
        ?int $tokensOut = null,
        ?int $durationMs = null,
    ): BlogTopicLlmRun {
        return BlogTopicLlmRun::create([
            'batch_id' => $batchId,
            'candidate_id' => $candidateId,
            'step' => $step,
            'provider' => $provider,
            'model' => $model,
            'prompt_text' => $prompt,
            'response_text' => $response,
            'prompt_hash' => hash('sha256', $prompt),
            'response_hash' => hash('sha256', $response),
            'tokens_in' => $tokensIn,
            'tokens_out' => $tokensOut,
            'duration_ms' => $durationMs,
        ]);
    }
}
