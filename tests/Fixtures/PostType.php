<?php

declare(strict_types=1);

namespace Core45\AiPosts\Tests\Fixtures;

/**
 * `buildModelContext()` calls `$model->post_type_id?->label()`, so a host whose
 * `post_type_id` column holds a plain integer gets
 * `Error: Call to a member function label() on int` — an UNCAUGHT fatal, since
 * `buildModelContext()` runs outside `processModel()`'s per-field try blocks.
 * The column must be cast to a backed enum exposing `label()`.
 */
enum PostType: int
{
    case Article = 1;

    public function label(): string
    {
        return 'Article';
    }
}
