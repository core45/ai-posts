<?php

declare(strict_types=1);

namespace Core45\AiPosts\Models;

use Core45\AiPosts\Database\Factories\ContentModuleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $slug
 * @property string|null $system_prompt_template
 * @property string|null $user_prompt_template
 * @property array<string, mixed>|null $quality_criteria
 * @property bool $is_built_in
 */
class ContentModule extends Model
{
    /**
     * Laravel maps a model to a factory by swapping its namespace for the
     * host's factory namespace.
     * A package namespace produces nonsense, so the factory is named
     * here; without this ::factory() fatals.
     */
    protected static function newFactory(): ContentModuleFactory
    {
        return ContentModuleFactory::new();
    }

    /** @use HasFactory<ContentModuleFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::deleting(function (ContentModule $module): void {
            if ($module->is_built_in) {
                throw new \LogicException('Cannot delete built-in content modules.');
            }
        });
    }

    protected $fillable = [
        'slug',
        'name',
        'description',
        'system_prompt_template',
        'user_prompt_template',
        'output_format',
        'quality_criteria',
        'requires_research',
        'requires_previous_modules',
        'is_built_in',
        'active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quality_criteria' => 'array',
            'requires_research' => 'boolean',
            'requires_previous_modules' => 'boolean',
            'is_built_in' => 'boolean',
            'active' => 'boolean',
        ];
    }
}
