<?php

declare(strict_types=1);

namespace Core45\AiPosts\DTOs\BlogPipeline;

final readonly class ModuleContext
{
    /**
     * @param  array<string, mixed>  $ragResults
     * @param  array<string, mixed>  $researchData
     * @param  array<string, string>  $previousModuleSummaries
     * @param  array<string, string>  $recentModuleContents  Full content of 3 most recent modules
     * @param  array<int|string, mixed>  $internalLinkTargets
     * @param  array<string, mixed>  $productContext
     * @param  array<string, mixed>  $styleRules
     * @param  array<string>  $forbiddenPatterns
     * @param  array<string>  $profileLessons
     * @param  array<string>  $learnedKnowledge
     * @param  array<int, array{id: int, title: string, url: string}>  $researchSources
     * @param  array<string>  $alreadyLinkedUrls  URLs already linked in earlier modules
     */
    public function __construct(
        public array $ragResults = [],
        public array $researchData = [],
        public array $previousModuleSummaries = [],
        public ?string $lastModuleContent = null,
        public array $recentModuleContents = [],
        public array $internalLinkTargets = [],
        public array $productContext = [],
        public string $language = 'English',
        public array $styleRules = [],
        public array $forbiddenPatterns = [],
        public array $profileLessons = [],
        public array $learnedKnowledge = [],
        public bool $citationsEnabled = false,
        public array $researchSources = [],
        public array $alreadyLinkedUrls = [],
    ) {}
}
