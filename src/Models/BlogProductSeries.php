<?php

declare(strict_types=1);

namespace Core45\AiPosts\Models;

use Core45\AiPosts\Database\Factories\BlogProductSeriesFactory;
use Core45\AiPosts\Enums\BlogProductSeriesStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $profile_id
 * @property string $name
 * @property string $target_type
 * @property array<int> $target_ids
 * @property int $total_posts
 * @property int $interval_days
 * @property bool $auto_publish
 * @property BlogProductSeriesStatus $status
 * @property array<mixed>|null $topics_json
 * @property Carbon|null $next_publish_at
 * @property int $posts_generated
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class BlogProductSeries extends Model
{
    /**
     * Laravel maps a model to a factory by swapping its namespace for the
     * host's factory namespace.
     * A package namespace produces nonsense, so the factory is named
     * here; without this ::factory() fatals.
     */
    protected static function newFactory(): BlogProductSeriesFactory
    {
        return BlogProductSeriesFactory::new();
    }

    /** @use HasFactory<BlogProductSeriesFactory> */
    use HasFactory;

    protected $table = 'blog_product_series';

    protected $fillable = [
        'profile_id',
        'name',
        'target_type',
        'target_ids',
        'total_posts',
        'interval_days',
        'auto_publish',
        'status',
        'topics_json',
        'next_publish_at',
        'posts_generated',
    ];

    protected function casts(): array
    {
        return [
            'target_ids' => 'array',
            'topics_json' => 'array',
            'auto_publish' => 'boolean',
            'total_posts' => 'integer',
            'interval_days' => 'integer',
            'posts_generated' => 'integer',
            'status' => BlogProductSeriesStatus::class,
            'next_publish_at' => 'datetime',
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
     * @return HasMany<BlogTopicCandidate, $this>
     */
    public function candidates(): HasMany
    {
        return $this->hasMany(BlogTopicCandidate::class, 'series_id');
    }
}
