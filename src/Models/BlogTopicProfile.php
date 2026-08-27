<?php

declare(strict_types=1);

namespace Core45\AiPosts\Models;

use Core45\AiPosts\Database\Factories\BlogTopicProfileFactory;
use Core45\AiPosts\Enums\CitationMode;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BlogTopicProfile extends Model
{
    /**
     * Laravel maps a model to a factory by swapping its namespace for the
     * host's factory namespace.
     * A package namespace produces nonsense, so the factory is named
     * here; without this ::factory() fatals.
     */
    protected static function newFactory(): BlogTopicProfileFactory
    {
        return BlogTopicProfileFactory::new();
    }

    /** @use HasFactory<BlogTopicProfileFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'profile_json',
        'seed_keywords',
        'excluded_topics',
        'constraints',
        'brand_narrative',
        'config_json',
        'modules_config',
        'primary_locale',
        'citation_mode',
        'research_config',
        'quality_config',
        'last_refreshed_at',
    ];

    protected function casts(): array
    {
        return [
            'profile_json' => 'array',
            'seed_keywords' => 'array',
            'excluded_topics' => 'array',
            'config_json' => 'array',
            'modules_config' => 'array',
            'citation_mode' => CitationMode::class,
            'research_config' => 'array',
            'quality_config' => 'array',
            'last_refreshed_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<BlogTopicBatch, $this>
     */
    public function batches(): HasMany
    {
        return $this->hasMany(BlogTopicBatch::class, 'profile_id');
    }

    /**
     * @return HasMany<BlogProductSeries, $this>
     */
    public function series(): HasMany
    {
        return $this->hasMany(BlogProductSeries::class, 'profile_id');
    }

    /**
     * @return HasMany<BlogExternalLink, $this>
     */
    public function externalLinks(): HasMany
    {
        return $this->hasMany(BlogExternalLink::class, 'profile_id');
    }

    /**
     * @return HasMany<BlogPostPipelineRun, $this>
     */
    public function pipelineRuns(): HasMany
    {
        return $this->hasMany(BlogPostPipelineRun::class, 'profile_id');
    }

    /**
     * @return HasMany<BlogProfileLesson, $this>
     */
    public function lessons(): HasMany
    {
        return $this->hasMany(BlogProfileLesson::class, 'profile_id');
    }
}
