<?php

namespace Goldnead\StatamicProducts\Support;

/**
 * Was ein Zugang enthalten kann.
 *
 * Vier Arten bringt das Addon mit, jede mit einem Geschwister, das sie
 * ausliefert: `access` (ein anderer Zugang, verschachtelt), `course`
 * (statamic-courses), `file` (ein Asset) und `event` (statamic-events).
 * Weitere Arten meldet die Website an, adriangoldner.com etwa `community`:
 *
 * ```php
 * ContentKinds::register('community', 'Community-Bereich', 'Space (Kennung)');
 * ```
 *
 * Das ist die kleinste Naht, die das Formular braucht, um eine Art anbieten und
 * pruefen zu koennen. Eine Art der Website wird gespeichert und angezeigt, ihr
 * Verweis gilt als „nicht pruefbar": nur die Website weiss, worauf er zeigt.
 */
final class ContentKinds
{
    public const ACCESS = 'access';

    public const COURSE = 'course';

    public const FILE = 'file';

    public const EVENT = 'event';

    /** @var array<string, array{label: string, ref_label: string|null}> */
    private static array $registered = [];

    /**
     * Eine Art der Website anmelden.
     *
     * Am besten im `boot()` eines ServiceProviders der Website. Die Beschriftung
     * wird so angezeigt, wie sie hier steht; uebersetzen ist Sache des Aufrufers.
     */
    public static function register(string $kind, string $label, ?string $refLabel = null): void
    {
        self::$registered[$kind] = ['label' => $label, 'ref_label' => $refLabel];
    }

    /** @return list<string> */
    public static function builtIn(): array
    {
        return [self::ACCESS, self::COURSE, self::FILE, self::EVENT];
    }

    /** @return list<string> */
    public static function all(): array
    {
        return array_values(array_unique([...self::builtIn(), ...array_keys(self::$registered)]));
    }

    public static function isBuiltIn(string $kind): bool
    {
        return in_array($kind, self::builtIn(), true);
    }

    /**
     * Fuer das Formular: Wert, Beschriftung, und was im Verweisfeld erwartet wird.
     *
     * @return list<array{value: string, label: string, ref_label: string, host: bool}>
     */
    public static function options(): array
    {
        return array_map(fn (string $kind) => [
            'value' => $kind,
            'label' => self::label($kind),
            'ref_label' => self::refLabel($kind),
            'host' => ! self::isBuiltIn($kind),
        ], self::all());
    }

    public static function label(string $kind): string
    {
        if (isset(self::$registered[$kind])) {
            return self::$registered[$kind]['label'];
        }

        return self::translated('content_kind_'.$kind, $kind);
    }

    public static function refLabel(string $kind): string
    {
        if (isset(self::$registered[$kind]) && self::$registered[$kind]['ref_label'] !== null) {
            return (string) self::$registered[$kind]['ref_label'];
        }

        return self::translated('content_ref_'.$kind, __('statamic-products::messages.content_ref_host'));
    }

    /** Zwischen Tests. */
    public static function forget(): void
    {
        self::$registered = [];
    }

    private static function translated(string $key, string $fallback): string
    {
        $full = 'statamic-products::messages.'.$key;
        $translated = __($full);

        return is_string($translated) && $translated !== $full ? $translated : $fallback;
    }
}
