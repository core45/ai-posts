<?php

declare(strict_types=1);

namespace Core45\AiPosts\Enums;

enum BlogLinkPlacedBy: string
{
    case Ai = 'ai';
    case Admin = 'admin';

    public function getLabel(): string
    {
        return match ($this) {
            self::Ai => __('AI'),
            self::Admin => __('Admin'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Ai => 'info',
            self::Admin => 'success',
        };
    }
}
