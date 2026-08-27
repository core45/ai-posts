<?php

declare(strict_types=1);

namespace Core45\AiPosts\Services\BlogPipeline\Modules;

use Core45\AiPosts\DTOs\BlogPipeline\ModuleContext;
use Core45\AiPosts\DTOs\BlogPipeline\ModuleOutput;
use Core45\AiPosts\DTOs\BlogPipeline\QualityResult;
use Core45\AiPosts\Models\BlogPostPipelineRun;

abstract class AbstractContentModule
{
    abstract public function getSlug(): string;

    abstract public function prepare(BlogPostPipelineRun $run): ModuleContext;

    /**
     * Build the phasea `blog_pipeline.module` PARAMETER payload for this module.
     *
     * v2.6 (design §2.5, plan 48-15): the host no longer assembles instruction
     * strings. It names the module slug, supplies the placeholder values, and
     * passes any tenant template override as DATA — phasea composes the
     * instruction from its own library.
     *
     * THE PLACEHOLDER KEY DEPENDS ON THE SERVICE (plan 48-22): `placeholder_data`
     * (typed) when phasea advertises `typed_placeholders`, the retired
     * `placeholders` (formatted strings) when it does not — because an
     * un-advertising phasea is an OLD one, and typed data would render empty
     * there rather than fail. The return shape is therefore stated loosely on
     * purpose; `DynamicContentModule::buildPayload()` owns the choice.
     *
     * @return array<string, mixed>
     */
    abstract public function buildPayload(BlogPostPipelineRun $run, ModuleContext $context): array;

    abstract public function execute(BlogPostPipelineRun $run, ModuleContext $context): ModuleOutput;

    abstract public function verify(ModuleOutput $output, BlogPostPipelineRun $run): QualityResult;

    /**
     * @return array<string>
     */
    public function getRequiredContext(): array
    {
        return ['research_data', 'previous_modules'];
    }
}
