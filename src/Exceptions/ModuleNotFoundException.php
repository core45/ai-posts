<?php

declare(strict_types=1);

namespace Core45\AiPosts\Exceptions;

use RuntimeException;

class ModuleNotFoundException extends RuntimeException
{
    public static function forSlug(string $slug): self
    {
        return new self("Content module not found for slug: {$slug}");
    }
}
