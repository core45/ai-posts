<?php

declare(strict_types=1);

namespace Core45\AiPosts\Models;

use Core45\AiPosts\Database\Factories\BlogTopicSourceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Scout\Searchable;

/**
 * @property string $url
 * @property string $domain
 * @property string|null $title
 * @property string $snippet
 */
class BlogTopicSource extends Model
{
    /**
     * Laravel maps a model to a factory by swapping its namespace for the
     * host's factory namespace.
     * A package namespace produces nonsense, so the factory is named
     * here; without this ::factory() fatals.
     */
    protected static function newFactory(): BlogTopicSourceFactory
    {
        return BlogTopicSourceFactory::new();
    }

    /** @use HasFactory<BlogTopicSourceFactory> */
    use HasFactory;

    use Searchable;

    public $timestamps = false;

    protected $fillable = [
        'batch_id',
        'url',
        'url_hash',
        'domain',
        'title',
        'snippet',
        'language',
        'published_at',
        'reliability_score',
        'meta_json',
    ];

    protected function casts(): array
    {
        return [
            'published_at' => 'date',
            'reliability_score' => 'float',
            'meta_json' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function searchableAs(): string
    {
        return config('scout.prefix').'blog_topic_sources';
    }

    /**
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array
    {
        $array = [
            'id' => $this->id,
            'title' => $this->title ?? '',
            'snippet' => $this->snippet ?? '',
            'domain' => $this->domain ?? '',
            'url' => $this->url,
            'language' => $this->language ?? '',
        ];

        return $array;
    }

    public function shouldBeSearchable(): bool
    {
        return true;
    }

    /**
     * @return BelongsTo<BlogTopicBatch, $this>
     */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(BlogTopicBatch::class, 'batch_id');
    }
}
