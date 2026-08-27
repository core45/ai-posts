<?php

declare(strict_types=1);

namespace Core45\AiPosts\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum BlogExternalLinkUsageStatus: string implements HasColor, HasLabel
{
    case Suggested = 'suggested';
    case Accepted = 'accepted';
    case Rejected = 'rejected';

    public function getLabel(): string
    {
        return match ($this) {
            self::Suggested => __('Suggested'),
            self::Accepted => __('Accepted'),
            self::Rejected => __('Rejected'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Suggested => 'warning',
            self::Accepted => 'success',
            self::Rejected => 'danger',
        };
    }
}
