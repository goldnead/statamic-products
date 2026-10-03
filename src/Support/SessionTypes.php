<?php

namespace Goldnead\StatamicProducts\Support;

/**
 * Die Sessiontypen, auf die eine Guthabenzeile Guthaben schreibt.
 *
 * Welche es gibt, weiss nur die Website: auf adriangoldner.com sind es zwei
 * UUIDs in VocalFlow. Sie meldet sie mit Klarnamen an:
 *
 * ```php
 * SessionTypes::register('8f0c…', 'Einzelsession');
 * SessionTypes::register('2b7d…', 'Gruppensession');
 * ```
 *
 * Dann waehlt das Formular aus diesen Namen, und ein Tippfehler kann kein
 * Guthaben mehr auf einen Typ schreiben, den es nicht gibt. Meldet die Website
 * nichts an, bleibt das Feld Freitext und jeder Wert „nicht pruefbar".
 */
final class SessionTypes
{
    /** @var array<string, string> */
    private static array $registered = [];

    public static function register(string $id, string $label): void
    {
        self::$registered[$id] = $label;
    }

    /** Ob die Website ueberhaupt etwas angemeldet hat. */
    public static function known(): bool
    {
        return self::$registered !== [];
    }

    public static function has(string $id): bool
    {
        return array_key_exists($id, self::$registered);
    }

    public static function label(string $id): ?string
    {
        return self::$registered[$id] ?? null;
    }

    /** @return list<string> */
    public static function ids(): array
    {
        return array_keys(self::$registered);
    }

    /**
     * Fuer das Formular.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::$registered as $id => $label) {
            $options[] = ['value' => (string) $id, 'label' => $label];
        }

        return $options;
    }

    /**
     * Wie bei Inhalten: gefunden, fehlt, nicht pruefbar.
     *
     * @return array{state: string, label: string|null}
     */
    public static function target(string $id): array
    {
        if (! self::known()) {
            return ['state' => RefTarget::UNKNOWABLE, 'label' => null];
        }

        return self::has($id)
            ? ['state' => RefTarget::RESOLVED, 'label' => self::label($id)]
            : ['state' => RefTarget::MISSING, 'label' => null];
    }

    /** Zwischen Tests. */
    public static function forget(): void
    {
        self::$registered = [];
    }
}
