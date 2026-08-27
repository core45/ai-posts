<?php

declare(strict_types=1);

namespace Core45\AiPosts\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum BlogLinkPlacedBy: string implements HasColor, HasLabel
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
