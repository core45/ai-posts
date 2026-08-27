<?php

declare(strict_types=1);

namespace Core45\AiPosts\Enums;

use Filament\Support\Contracts\HasLabel;

enum WorkflowMode: string implements HasLabel
{
    case Automatic = 'automatic';
    case SemiAutomatic = 'semi-automatic';
    case StepByStep = 'step-by-step';

    public function getLabel(): string
    {
        return match ($this) {
            self::Automatic => __('Fully automatic'),
            self::SemiAutomatic => __('Semi-automatic (explicit checkpoints only)'),
            self::StepByStep => __('Step-by-step (pause after every module)'),
        };
    }
}
