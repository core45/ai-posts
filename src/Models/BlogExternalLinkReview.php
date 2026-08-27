<?php

declare(strict_types=1);

namespace Core45\AiPosts\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $link_id
 * @property bool $is_harmful
 * @property string $reason
 * @property string|null $checked_url_content
 * @property string $reviewed_by
 * @property Carbon $reviewed_at
 */
class BlogExternalLinkReview extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'link_id',
        'is_harmful',
        'reason',
        'checked_url_content',
        'reviewed_by',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'is_harmful' => 'boolean',
            'reviewed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<BlogExternalLink, $this>
     */
    public function link(): BelongsTo
    {
        return $this->belongsTo(BlogExternalLink::class, 'link_id');
    }
}
