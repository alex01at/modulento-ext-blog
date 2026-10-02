<?php

declare(strict_types=1);

namespace Modulento\Blog;

/**
 * What posts and categories share: how an address is formed from a title
 * and which language's text a visitor gets.
 */
final class Texts
{
    /** Lower-case letters, digits and single hyphens; umlauts and accents are written out. */
    public static function slugify(string $text, int $maxLength = 200): string
    {
        $text = strtr(mb_strtolower(trim($text)), ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
        $text = (string) preg_replace('/[^a-z0-9]+/', '-', strtolower($ascii !== false ? $ascii : $text));

        return trim(substr($text, 0, $maxLength), '-');
    }

    /**
     * The language whose text is shown to a visitor reading $locale: their
     * own, else the site's default language, else whichever exists - the
     * same order the core uses for offers. Nothing is hidden because of
     * the language it was written in.
     *
     * @param array<string, mixed> $translations by locale
     */
    public static function pick(array $translations, string $locale, string $default): ?string
    {
        if (isset($translations[$locale])) {
            return $locale;
        }
        if (isset($translations[$default])) {
            return $default;
        }

        return array_key_first($translations);
    }
}
