<?php

declare(strict_types=1);

namespace Core45\AiPosts\Models;

use Core45\AiPosts\Database\Factories\BlogTopicBatchFactory;
use Core45\AiPosts\Enums\BlogTopicBatchStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property BlogTopicBatchStatus $status
 */
class BlogTopicBatch extends Model
{
    /**
     * Laravel maps a model to a factory by swapping its namespace for the
     * host's factory namespace.
     * A package namespace produces nonsense, so the factory is named
     * here; without this ::factory() fatals.
     */
    protected static function newFactory(): BlogTopicBatchFactory
    {
        return BlogTopicBatchFactory::new();
    }

    /** @use HasFactory<BlogTopicBatchFactory> */
    use HasFactory;

    protected $fillable = [
        'profile_id',
        'status',
        'config_json',
        'queries_json',
        'error_message',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => BlogTopicBatchStatus::class,
            'config_json' => 'array',
            'queries_json' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<BlogTopicProfile, $this>
     */
    public function profile(): BelongsTo
    {
        return $this->belongsTo(BlogTopicProfile::class, 'profile_id');
    }

    /**
     * @return HasMany<BlogTopicSource, $this>
     */
    public function sources(): HasMany
    {
        return $this->hasMany(BlogTopicSource::class, 'batch_id');
    }

    /**
     * @return HasMany<BlogTopicCandidate, $this>
     */
    public function candidates(): HasMany
    {
        return $this->hasMany(BlogTopicCandidate::class, 'batch_id');
    }

    /**
     * @return HasMany<BlogTopicLlmRun, $this>
     */
    public function llmRuns(): HasMany
    {
        return $this->hasMany(BlogTopicLlmRun::class, 'batch_id');
    }
}
