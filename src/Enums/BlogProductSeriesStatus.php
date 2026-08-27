<?php

declare(strict_types=1);

namespace Core45\AiPosts\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum BlogProductSeriesStatus: string implements HasColor, HasLabel
{
    case Planning = 'planning';
    case Planned = 'planned';
    case InProgress = 'in_progress';
    case Paused = 'paused';
    case Completed = 'completed';

    public function getLabel(): string
    {
        return match ($this) {
            self::Planning => __('Planning'),
            self::Planned => __('Planned'),
            self::InProgress => __('In Progress'),
            self::Paused => __('Paused'),
            self::Completed => __('Completed'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Planning => 'gray',
            self::Planned => 'info',
            self::InProgress => 'warning',
            self::Paused => 'gray',
            self::Completed => 'success',
        };
    }
}
