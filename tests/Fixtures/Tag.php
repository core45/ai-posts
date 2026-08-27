<?php

declare(strict_types=1);

namespace Core45\AiPosts\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

/**
 * Required only because `buildModelContext()` eager-loads `tags` and plucks
 * `name` off them.
 */
class Tag extends Model
{
    public $timestamps = false;

    protected $guarded = [];
}
