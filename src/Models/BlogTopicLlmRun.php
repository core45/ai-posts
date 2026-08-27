<?php

declare(strict_types=1);

namespace Core45\AiPosts\Models;

use Core45\AiPosts\Enums\BlogTopicLlmStep;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BlogTopicLlmRun extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'batch_id',
        'candidate_id',
        'step',
        'provider',
        'model',
        'prompt_text',
        'response_text',
        'prompt_hash',
        'response_hash',
        'tokens_in',
        'tokens_out',
        'duration_ms',
    ];

    protected function casts(): array
    {
        return [
            'step' => BlogTopicLlmStep::class,
            'tokens_in' => 'integer',
            'tokens_out' => 'integer',
            'duration_ms' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<BlogTopicBatch, $this>
     */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(BlogTopicBatch::class, 'batch_id');
    }

    /**
     * @return BelongsTo<BlogTopicCandidate, $this>
     */
    public function candidate(): BelongsTo
    {
        return $this->belongsTo(BlogTopicCandidate::class, 'candidate_id');
    }
}
