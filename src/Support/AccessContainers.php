<?php

namespace Goldnead\StatamicProducts\Support;

use Statamic\Facades\AssetContainer;

/**
 * Aus welchen Ablagen (Asset-Containern) ein Zugang Dateien nehmen darf.
 *
 * Ein Zugang wird verkauft. Auf einer Installation mit Klientenraeumen liegen
 * daneben Container voller privater Dateien (Probenaufnahmen, Unterlagen
 * einzelner Kunden), und ein Dropdown, das sie alle anbietet, ist ein Klick
 * von einem Datenleck entfernt. Die Website sagt deshalb, welche erlaubt sind:
 *
 * ```php
 * AccessContainers::allow(['assets', 'downloads']);
 * ```
 *
 * **Ohne Anmeldung** gilt jeder Container ausser denen von
 * `statamic-clientrooms`: dessen Basis-Kennung (`statamic-clientrooms.container`)
 * und die Markenvarianten `<basis>-<markenId>`. Erkannt wird das an der
 * Konfiguration, die nur existiert, wenn das Addon installiert ist. Andere
 * private Container erkennt dieses Addon nicht; wer welche hat, meldet an.
 */
final class AccessContainers
{
    /** @var list<string>|null */
    private static ?array $allowed = null;

    /**
     * @param  string|list<string>  $handles
     */
    public static function allow(string|array $handles): void
    {
        self::$allowed = array_values(array_unique([...(self::$allowed ?? []), ...(array) $handles]));
    }

    /**
     * Die erlaubten Kennungen, sortiert, nur solche, die es gibt.
     *
     * @return list<string>
     */
    public static function allowed(): array
    {
        $existing = AssetContainer::all()->map(fn ($container) => (string) $container->handle())->sort()->values()->all();

        if (self::$allowed !== null) {
            return array_values(array_intersect($existing, self::$allowed));
        }

        return array_values(array_filter($existing, fn (string $handle) => ! self::isClientRoom($handle)));
    }

    public static function allows(string $handle): bool
    {
        return in_array($handle, self::allowed(), true);
    }

    /** Ob eine Asset-ID (`container::pfad`) aus einer erlaubten Ablage stammt. */
    public static function allowsAsset(string $id): bool
    {
        if (! str_contains($id, '::')) {
            return false;
        }

        return self::allows(strstr($id, '::', true));
    }

    private static function isClientRoom(string $handle): bool
    {
        $base = config('statamic-clientrooms.container');

        if (! is_string($base) || $base === '') {
            return false;
        }

        return $handle === $base || preg_match('/^'.preg_quote($base, '/').'-\d+$/', $handle) === 1;
    }

    /** Zwischen Tests. */
    public static function forget(): void
    {
        self::$allowed = null;
    }
}
