<?php

declare(strict_types=1);

namespace Core45\AiPosts\Models;

use Core45\AiCore\ModelRegistry;
use Core45\AiPosts\Database\Factories\BlogPostFeedbackFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string|null $module_slug
 * @property int $rating
 * @property string $feedback_type
 * @property string $comment
 * @property string $action_requested
 * @property Carbon|null $created_at
 * @property array<mixed>|null $ai_analysis
 */
class BlogPostFeedback extends Model
{
    /**
     * Laravel maps a model to a factory by swapping its namespace for the
     * host's factory namespace.
     * A package namespace produces nonsense, so the factory is named
     * here; without this ::factory() fatals.
     */
    protected static function newFactory(): BlogPostFeedbackFactory
    {
        return BlogPostFeedbackFactory::new();
    }

    /** @use HasFactory<BlogPostFeedbackFactory> */
    use HasFactory;

    protected $table = 'blog_post_feedbacks';

    public $timestamps = false;

    protected $fillable = [
        'pipeline_run_id',
        'post_id',
        'user_id',
        'module_slug',
        'rating',
        'feedback_type',
        'comment',
        'action_requested',
        'ai_analysis',
        'applied_to_profile',
        'applied_to_knowledge',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'ai_analysis' => 'array',
            'applied_to_profile' => 'boolean',
            'applied_to_knowledge' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (BlogPostFeedback $feedback): void {
            if ($feedback->created_at === null) {
                $feedback->created_at = now();
            }
        });
    }

    /**
     * @return BelongsTo<BlogPostPipelineRun, $this>
     */
    public function pipelineRun(): BelongsTo
    {
        return $this->belongsTo(BlogPostPipelineRun::class, 'pipeline_run_id');
    }

    /**
     * @return BelongsTo<Model, $this>
     */
    public function post(): BelongsTo
    {
        return $this->belongsTo(app(ModelRegistry::class)->getModelClassOrFail('post'), 'post_id');
    }

    /**
     * @return BelongsTo<Model, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(app(ModelRegistry::class)->getModelClassOrFail('user'), 'user_id');
    }
}
