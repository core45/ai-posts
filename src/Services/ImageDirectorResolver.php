<?php

declare(strict_types=1);

namespace Core45\AiPosts\Services;

use Core45\AiCore\ModelRegistry;
use Illuminate\Database\Eloquent\Model;

final class ImageDirectorResolver
{
    /**
     * Resolve the effective profile and style for image generation.
     *
     * Profile precedence:
     *   1. Explicit profile id
     *   2. Record's resolveImageDirectorProfile() (FK or its own default)
     *   3. ImageDirectorProfile::defaultForModel(record class)
     *   4. null
     *
     * Style precedence:
     *   1. Explicit slug
     *   2. Profile's defaultImageStyle
     *   3. First active style (by sort_order)
     *   4. null
     *
     * @return array{profile: ?Model, style: ?Model}
     */
    public function resolve(?Model $record, ?int $explicitProfileId, ?string $explicitStyleSlug): array
    {
        $profileClass = app(ModelRegistry::class)->getModelClassOrFail('image_director_profile');
        $styleClass = app(ModelRegistry::class)->getModelClassOrFail('image_style');

        // The package never imports the host's ImageDirectorProfile/ImageStyle
        // classes (§3 seam) — defaultForModel()/resolveBySlug()/active() are
        // host-only helpers, not part of any package-visible contract, so each
        // static call below is guarded with an ignore annotation per the
        // notFound precedent already used across this package.
        $profile = $explicitProfileId !== null ? $profileClass::find($explicitProfileId) : null;

        if ($profile === null && $record !== null && method_exists($record, 'resolveImageDirectorProfile')) {
            $profile = $record->resolveImageDirectorProfile();
        }

        if ($profile === null) {
            /** @phpstan-ignore staticMethod.notFound */
            $profile = $profileClass::defaultForModel($record !== null ? $record::class : null);
        }

        /** @phpstan-ignore staticMethod.notFound */
        $style = $styleClass::resolveBySlug($explicitStyleSlug);

        if ($style === null) {
            $style = $profile?->defaultImageStyle;
        }

        if ($style === null) {
            /** @phpstan-ignore staticMethod.notFound */
            $style = $styleClass::active()->orderBy('sort_order')->first();
        }

        return ['profile' => $profile, 'style' => $style];
    }
}
