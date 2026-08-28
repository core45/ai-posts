<?php

declare(strict_types=1);

namespace Core45\AiPosts\Tests\Fixtures;

use Astrotomic\Translatable\Contracts\Translatable as TranslatableContract;
use Astrotomic\Translatable\Translatable;
use Core45\AiCore\Contracts\ProvidesSeoIndexable;
use Core45\AiCore\Models\Concerns\HasSeoIndexable;
use Core45\AiPosts\Models\BlogTopicCandidate;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * The minimum host `Post` `PostContentRewriteService` / blog pipeline can run
 * against. Everything on it is here because the service (or the abstract base
 * in ai-core) actually reaches for it.
 */
class Post extends Model implements ProvidesSeoIndexable, TranslatableContract
{
    use HasSeoIndexable;
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

    /**
     * Read by the translation path to decide whether citation-preservation
     * instructions are required.
     *
     * @return HasOne<BlogTopicCandidate, $this>
     */
    public function blogTopicCandidate(): HasOne
    {
        return $this->hasOne(BlogTopicCandidate::class);
    }
}
