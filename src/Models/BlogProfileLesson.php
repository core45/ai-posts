<?php

declare(strict_types=1);

namespace Core45\AiPosts\Models;

use Core45\AiPosts\Database\Factories\BlogProfileLessonFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $lesson_type
 * @property string $lesson_text
 * @property array<int>|null $source_feedback_ids
 * @property float $confidence
 * @property int $times_applied
 * @property float|null $effectiveness_score
 */
class BlogProfileLesson extends Model
{
    /**
     * Laravel maps a model to a factory by swapping its namespace for the
     * host's factory namespace.
     * A package namespace produces nonsense, so the factory is named
     * here; without this ::factory() fatals.
     */
    protected static function newFactory(): BlogProfileLessonFactory
    {
        return BlogProfileLessonFactory::new();
    }

    /** @use HasFactory<BlogProfileLessonFactory> */
    use HasFactory;

    protected $fillable = [
        'profile_id',
        'lesson_type',
        'lesson_text',
        'source_feedback_ids',
        'confidence',
        'times_applied',
        'effectiveness_score',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'source_feedback_ids' => 'array',
            'confidence' => 'decimal:2',
            'effectiveness_score' => 'decimal:2',
            'active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<BlogTopicProfile, $this>
     */
    public function profile(): BelongsTo
    {
        return $this->belongsTo(BlogTopicProfile::class, 'profile_id');
    }
}
