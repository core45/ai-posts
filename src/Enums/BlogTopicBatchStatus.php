<?php

declare(strict_types=1);

namespace Core45\AiPosts\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum BlogTopicBatchStatus: string implements HasColor, HasLabel
{
    case Draft = 'draft';
    case Researching = 'researching';
    case Synthesizing = 'synthesizing';
    case Completed = 'completed';
    case Failed = 'failed';
    case Manual = 'manual';

    public function getLabel(): string
    {
        return match ($this) {
            self::Draft => __('Draft'),
            self::Researching => __('Researching'),
            self::Synthesizing => __('Synthesizing'),
            self::Completed => __('Completed'),
            self::Failed => __('Failed'),
            self::Manual => __('Manual'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Researching, self::Synthesizing => 'warning',
            self::Completed => 'success',
            self::Failed => 'danger',
            self::Manual => 'info',
        };
    }
}
