<?php

declare(strict_types=1);

namespace Core45\AiPosts\Tests\Fixtures;

use Astrotomic\Translatable\Contracts\Translatable as TranslatableContract;
use Astrotomic\Translatable\Translatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * The minimum host `Post` `PostContentRewriteService` can run against.
 *
 * Everything on it is here because the service (or the abstract base in
 * ai-core) actually reaches for it — see the discovery list in
 * `HostAppRewritesAPostEndToEndTest`.
 */
class Post extends Model implements TranslatableContract
{
    use Translatable;

    /** @var array<int, string> */
    public array $translatedAttributes = [
        'title',
        'slug',
        'excerpt',
        'main_text',
        'seo_title',
        'seo_description',
        'seo_keywords',
    ];

    protected $guarded = [];

    /**
     * `buildModelContext()` calls `label()` on both of these, so neither can be
     * a plain integer column.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'post_type_id' => PostType::class,
            'post_category_id' => PostCategory::class,
        ];
    }

    /**
     * Eager-loaded by `buildModelContext()`; its `name` column is plucked into
     * the AI context.
     *
     * @return BelongsToMany<Tag, $this>
     */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'post_tag');
    }

    /**
     * Also eager-loaded by `buildModelContext()`, together with its
     * translations.
     *
     * @return BelongsTo<ProductCategory, $this>
     */
    public function productCategory(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class);
    }
}
