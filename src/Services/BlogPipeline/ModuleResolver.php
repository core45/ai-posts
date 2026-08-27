<?php

declare(strict_types=1);

namespace Core45\AiPosts\Services\BlogPipeline;

use Core45\AiPosts\Exceptions\ModuleNotFoundException;
use Core45\AiPosts\Models\ContentModule;
use Core45\AiPosts\Services\BlogPipeline\Modules\AbstractContentModule;
use Core45\AiPosts\Services\BlogPipeline\Modules\DynamicContentModule;

class ModuleResolver
{
    public function __construct(
        private readonly PromptTemplateResolver $promptResolver,
    ) {}

    public function resolve(string $slug): AbstractContentModule
    {
        /** @var array<string, class-string<AbstractContentModule>> $classMap */
        $classMap = (array) config('blog-pipeline.modules', []);

        if (isset($classMap[$slug])) {
            $instance = app($classMap[$slug]);

            if (!$instance instanceof AbstractContentModule) {
                throw ModuleNotFoundException::forSlug($slug);
            }

            return $instance;
        }

        $record = ContentModule::query()
            ->where('slug', $slug)
            ->where('active', true)
            ->first();

        if ($record === null) {
            throw ModuleNotFoundException::forSlug($slug);
        }

        return new DynamicContentModule($record, $this->promptResolver);
    }

    /**
     * @param  array<int, array<string, mixed>>  $modulesConfig
     * @return array<AbstractContentModule>
     */
    public function resolveAll(array $modulesConfig): array
    {
        $modules = [];

        foreach ($modulesConfig as $config) {
            if (!($config['enabled'] ?? true)) {
                continue;
            }

            $modules[] = $this->resolve((string) $config['module_slug']);
        }

        return $modules;
    }
}
