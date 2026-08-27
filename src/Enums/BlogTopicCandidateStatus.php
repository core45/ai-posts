<?php

declare(strict_types=1);

namespace Core45\AiPosts\Enums;

enum BlogTopicCandidateStatus: string
{
    case Proposed = 'proposed';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Generating = 'generating';
    case Generated = 'generated';
    case Published = 'published';
    case Failed = 'failed';

    public function getLabel(): string
    {
        return match ($this) {
            self::Proposed => __('Proposed'),
            self::Approved => __('Approved'),
            self::Rejected => __('Rejected'),
            self::Generating => __('Generating'),
            self::Generated => __('Generated'),
            self::Published => __('Published'),
            self::Failed => __('Failed'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Proposed => 'info',
            self::Approved => 'success',
            self::Rejected => 'danger',
            self::Generating => 'warning',
            self::Generated => 'success',
            self::Published => 'success',
            self::Failed => 'danger',
        };
    }
}
