<?php

declare(strict_types=1);

namespace Core45\AiPosts\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum BlogPostPipelineStatus: string implements HasColor, HasLabel
{
    case Researching = 'researching';
    case Writing = 'writing';
    case Paused = 'paused';
    case Reviewing = 'reviewing';
    case Enriching = 'enriching';
    case Translating = 'translating';
    case Completed = 'completed';
    case Failed = 'failed';

    public function getLabel(): string
    {
        return match ($this) {
            self::Researching => __('Researching'),
            self::Writing => __('Writing'),
            self::Paused => __('Paused'),
            self::Reviewing => __('Reviewing'),
            self::Enriching => __('Enriching'),
            self::Translating => __('Translating'),
            self::Completed => __('Completed'),
            self::Failed => __('Failed'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Researching, self::Writing, self::Enriching, self::Translating => 'info',
            self::Paused, self::Reviewing => 'warning',
            self::Completed => 'success',
            self::Failed => 'danger',
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Completed, self::Failed]);
    }

    public function isActive(): bool
    {
        return in_array($this, [self::Researching, self::Writing, self::Enriching, self::Translating, self::Reviewing]);
    }
}
