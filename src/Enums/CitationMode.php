<?php

declare(strict_types=1);

namespace Core45\AiPosts\Enums;

enum CitationMode: string
{
    case Render = 'render';
    case Strip = 'strip';
    case Strict = 'strict';

    public function getLabel(): string
    {
        return match ($this) {
            self::Render => __('Render as footnotes'),
            self::Strip => __('Strip markers (keep audit trail)'),
            self::Strict => __('Strict (fail on uncited claims)'),
        };
    }
}
