<?php

declare(strict_types=1);

namespace Core45\AiPosts\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

/**
 * The host's own `post_translations` row. The package owns the `blog_*` and
 * `ai_*` schema; `posts`/`post_translations` are the host's, so the test app
 * has to declare them exactly as a consuming application would.
 */
class PostTranslation extends Model
{
    public $timestamps = false;

    protected $guarded = [];
}
