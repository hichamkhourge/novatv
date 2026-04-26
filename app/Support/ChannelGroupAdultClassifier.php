<?php

namespace App\Support;

use Illuminate\Support\Str;

class ChannelGroupAdultClassifier
{
    /**
     * Conservative keyword list for adult category detection.
     *
     * @var list<string>
     */
    private const KEYWORDS = [
        'adult',
        'adults',
        '18+',
        'xxx',
        'porn',
        'porno',
        'erotic',
        'sex',
        'hentai',
        'milf',
        'playboy',
    ];

    /**
     * Known false positives that should stay non-adult.
     *
     * @var list<string>
     */
    private const EXCLUDED_PHRASES = [
        'adult swim',
    ];

    public static function isAdult(?string $name): bool
    {
        $normalized = self::normalize($name);

        if ($normalized === '') {
            return false;
        }

        foreach (self::EXCLUDED_PHRASES as $excludedPhrase) {
            if (str_contains($normalized, $excludedPhrase)) {
                return false;
            }
        }

        foreach (self::KEYWORDS as $keyword) {
            if (preg_match('/(?:^| )' . preg_quote($keyword, '/') . '(?: |$)/', $normalized) === 1) {
                return true;
            }
        }

        return false;
    }

    public static function normalize(?string $name): string
    {
        return Str::of((string) $name)
            ->ascii()
            ->lower()
            ->replaceMatches('/[^a-z0-9\+]+/', ' ')
            ->squish()
            ->value();
    }
}
