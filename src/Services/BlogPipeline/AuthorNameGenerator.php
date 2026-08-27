<?php

declare(strict_types=1);

namespace Core45\AiPosts\Services\BlogPipeline;

class AuthorNameGenerator
{
    /** @var array<string, list<string>> */
    private const NAMES_BY_LOCALE = [
        'pl' => [
            'Anna Kowalska',
            'Piotr Nowak',
            'Katarzyna Wiśniewska',
            'Tomasz Wójcik',
            'Magdalena Kamińska',
            'Marcin Lewandowski',
            'Agnieszka Zielińska',
            'Krzysztof Szymański',
        ],
        'en' => [
            'James Smith',
            'Sarah Johnson',
            'Michael Williams',
            'Emily Brown',
            'David Wilson',
            'Jessica Taylor',
            'Robert Anderson',
            'Laura Thomas',
        ],
    ];

    public static function generate(string $locale): string
    {
        $names = self::NAMES_BY_LOCALE[$locale] ?? self::NAMES_BY_LOCALE['en'];

        return $names[array_rand($names)];
    }
}
