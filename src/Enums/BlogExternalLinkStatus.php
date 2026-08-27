<?php

declare(strict_types=1);

namespace Core45\AiPosts\Enums;

enum BlogExternalLinkStatus: string
{
    case Active = 'active';
    case Blocked = 'blocked';
    case ProposedByAi = 'proposed_by_ai';

    public function getLabel(): string
    {
        return match ($this) {
            self::Active => __('Active'),
            self::Blocked => __('Blocked'),
            self::ProposedByAi => __('Proposed by AI'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Active => 'success',
            self::Blocked => 'danger',
            self::ProposedByAi => 'info',
        };
    }
}
