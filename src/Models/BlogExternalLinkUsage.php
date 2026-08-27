<?php

declare(strict_types=1);

namespace Core45\AiPosts\Models;

use Core45\AiCore\ModelRegistry;
use Core45\AiPosts\Enums\BlogExternalLinkUsageStatus;
use Core45\AiPosts\Enums\BlogLinkPlacedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $link_id
 * @property int $post_id
 * @property string $anchor_text
 * @property string|null $context_snippet
 * @property BlogExternalLinkUsageStatus $status
 * @property BlogLinkPlacedBy $placed_by
 * @property Carbon $suggested_at
 * @property Carbon|null $accepted_at
 */
class BlogExternalLinkUsage extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'link_id',
        'post_id',
        'anchor_text',
        'context_snippet',
        'status',
        'placed_by',
        'suggested_at',
        'accepted_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => BlogExternalLinkUsageStatus::class,
            'placed_by' => BlogLinkPlacedBy::class,
            'suggested_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<BlogExternalLink, $this>
     */
    public function link(): BelongsTo
    {
        return $this->belongsTo(BlogExternalLink::class, 'link_id');
    }

    /**
     * @return BelongsTo<Model, $this>
     */
    public function post(): BelongsTo
    {
        return $this->belongsTo(app(ModelRegistry::class)->getModelClassOrFail('post'), 'post_id');
    }
}
