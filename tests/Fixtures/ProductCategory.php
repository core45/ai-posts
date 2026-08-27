<?php

declare(strict_types=1);

namespace Core45\AiPosts\Tests\Fixtures;

use Astrotomic\Translatable\Contracts\Translatable as TranslatableContract;
use Astrotomic\Translatable\Translatable;
use Illuminate\Database\Eloquent\Model;

/**
 * Required only because `buildModelContext()` eager-loads
 * `productCategory.translations` and then calls `translate($locale)?->name` on
 * it — so the host's product category has to be Astrotomic-translatable even
 * when the post has none.
 */
class ProductCategory extends Model implements TranslatableContract
{
    use Translatable;

    public $timestamps = false;

    /** @var array<int, string> */
    public array $translatedAttributes = ['name'];

    protected $guarded = [];
}
