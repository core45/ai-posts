<?php

declare(strict_types=1);

namespace Core45\AiPosts\Tests\Fixtures;

/**
 * Same obligation as `PostType`, for `$model->post_category_id?->label()`.
 */
enum PostCategory: int
{
    case News = 1;

    public function label(): string
    {
        return 'News';
    }
}
