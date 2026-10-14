<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Metadata;

/**
 * Conventional name derivation: snake_case, pluralization, table and
 * foreign-key defaults shared by metadata resolution and relation keys.
 */
final class Naming
{
    /**
     * Convert a PascalCase / camelCase short name to snake_case.
     *
     * Consecutive capitals are treated as one acronym run: `APIKey`
     * becomes `api_key`, `HTTPSConnection` becomes `https_connection`.
     *
     * @param  string  $shortName
     * @return string
     */
    public static function snake(string $shortName): string
    {
        // An acronym run ends where a capital is followed by a lowercase
        // letter — the split lands before that trailing capital, so
        // `HTTPSConnection` yields `https_connection` rather than
        // `httpsconnection`.
        $withAcronymSplits = preg_replace(
            '/([A-Z]+)([A-Z][a-z])/',
            '$1_$2',
            $shortName,
        ) ?? $shortName;

        // Lowercase→uppercase and digit→uppercase boundaries split per word.
        $snake = strtolower((string) preg_replace('/(?<=[a-z0-9])([A-Z])/', '_$1', $withAcronymSplits));

        return $snake;
    }

    /**
     * Append the naive plural suffix to a snake_case name.
     *
     * Ordered suffix rules, first match wins: already-plural tails pass
     * through, sibilant clusters take `-es`, then `-ies` and the plain-`s`
     * fallback. Words ending in plain `s` (`settings`, `news`) pass through
     * unchanged; a vowel before the `s` still takes `-es` (`bus`, `status`,
     * `atlas`).
     * The heuristic cannot know every irregular — `#[Table(name: ...)]` is
     * the escape hatch for `person` → `people`.
     *
     * @param  string  $snake
     * @return string
     */
    public static function pluralize(string $snake): string
    {
        // Uninflected tails never change (`series`; also catches `-ies`
        // tails that are already plural-shaped).
        if (preg_match('/(?:ies|series)$/', $snake) === 1) {
            return $snake;
        }

        // A vowel before the final `s` still takes `-es` (`bus`, `status`,
        // `atlas`, `alias`).
        if (preg_match('/(?:[aeiouy])s$/', $snake) === 1) {
            return $snake . 'es';
        }

        // Sibilant and s-cluster endings take `-es` (`address` →
        // `addresses`, `church` → `churches`, `box` → `boxes`).
        if (preg_match('/(?:ss|x|z|ch|sh)$/', $snake) === 1) {
            return $snake . 'es';
        }

        // Any other trailing `s` reads as already plural (`settings`,
        // `news`).
        if (preg_match('/s$/', $snake) === 1) {
            return $snake;
        }

        // Consonant+`y` swaps to `-ies` (`category` → `categories`).
        if (preg_match('/[^aeiou]y$/', $snake) === 1) {
            return substr($snake, 0, -1) . 'ies';
        }

        return $snake . 's';
    }

    /**
     * The default table name: snake-cased plural of the short name.
     *
     * @param  string  $shortName
     * @return string
     */
    public static function table(string $shortName): string
    {
        return self::pluralize(self::snake($shortName));
    }

    /**
     * The default foreign key: snake-cased short name plus `_id`.
     *
     * @param  string  $shortName
     * @return string
     */
    public static function foreignKey(string $shortName): string
    {
        return self::snake($shortName) . '_id';
    }
}
