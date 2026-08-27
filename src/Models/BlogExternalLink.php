<?php

declare(strict_types=1);

namespace Core45\AiPosts\Models;

use Core45\AiCore\ModelRegistry;
use Core45\AiPosts\Database\Factories\BlogExternalLinkFactory;
use Core45\AiPosts\Enums\BlogExternalLinkStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int|null $profile_id
 * @property string $url
 * @property string $url_hash
 * @property string $domain
 * @property string $label
 * @property BlogExternalLinkStatus $status
 * @property string|null $anchor_text_suggestion
 * @property string|null $blocked_reason
 * @property int|null $blocked_by
 * @property Carbon|null $blocked_at
 * @property int $usage_count
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class BlogExternalLink extends Model
{
    /**
     * Laravel maps a model to a factory by swapping its namespace for the
     * host's factory namespace.
     * A package namespace produces nonsense, so the factory is named
     * here; without this ::factory() fatals.
     */
    protected static function newFactory(): BlogExternalLinkFactory
    {
        return BlogExternalLinkFactory::new();
    }

    /** @use HasFactory<BlogExternalLinkFactory> */
    use HasFactory;

    protected $fillable = [
        'profile_id',
        'url',
        'url_hash',
        'domain',
        'label',
        'status',
        'anchor_text_suggestion',
        'blocked_reason',
        'blocked_by',
        'blocked_at',
        'usage_count',
    ];

    protected function casts(): array
    {
        return [
            'status' => BlogExternalLinkStatus::class,
            'usage_count' => 'integer',
            'blocked_at' => 'datetime',
        ];
    }

    /**
     * Find a link by URL and profile (application-level dedup for nullable profile_id).
     * MySQL treats NULL != NULL in unique constraints, so this handles global links.
     */
    public static function findByUrlAndProfile(string $url, ?int $profileId): ?self
    {
        $query = static::where('url_hash', hash('sha256', $url));

        return $profileId === null
            ? $query->whereNull('profile_id')->first()
            : $query->where('profile_id', $profileId)->first();
    }

    /**
     * @return BelongsTo<BlogTopicProfile, $this>
     */
    public function profile(): BelongsTo
    {
        return $this->belongsTo(BlogTopicProfile::class, 'profile_id');
    }

    /**
     * @return BelongsTo<Model, $this>
     */
    public function blockedByUser(): BelongsTo
    {
        return $this->belongsTo(app(ModelRegistry::class)->getModelClassOrFail('user'), 'blocked_by');
    }

    /**
     * @return HasMany<BlogExternalLinkUsage, $this>
     */
    public function usages(): HasMany
    {
        return $this->hasMany(BlogExternalLinkUsage::class, 'link_id');
    }

    /**
     * @return HasMany<BlogExternalLinkReview, $this>
     */
    public function reviews(): HasMany
    {
        return $this->hasMany(BlogExternalLinkReview::class, 'link_id');
    }
}
