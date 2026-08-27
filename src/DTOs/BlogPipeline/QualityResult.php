<?php

declare(strict_types=1);

namespace Core45\AiPosts\DTOs\BlogPipeline;

use Core45\AiPosts\Enums\QualityDecision;

final readonly class QualityResult
{
    /**
     * @param  array<string, int>  $scores
     * @param  array<string>  $issues
     */
    public function __construct(
        public bool $passed,
        public float $overallScore,
        public array $scores = [],
        public array $issues = [],
        public QualityDecision $decision = QualityDecision::Pass,
    ) {}
}
