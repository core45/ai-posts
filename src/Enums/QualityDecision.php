<?php

declare(strict_types=1);

namespace Core45\AiPosts\Enums;

enum QualityDecision: string
{
    case Pass = 'pass';
    case RetrySame = 'retry_same';
    case Escalate = 'escalate';
    case Pause = 'pause';
    case Fail = 'fail';
}
