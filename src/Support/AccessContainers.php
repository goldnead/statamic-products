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
 * Liegt in einer Ablage neben dem Verkaufsmaterial auch Privates (ein
 * Eingangsordner, importierte Dateien), schraenkt ein Schluessel sie auf einen
 * Ordner ein: `allow(['private_downloads' => 'downloads'])`. Der Dateiwaehler
 * oeffnet und bleibt in diesem Ordner, und der Server lehnt jede Datei
 * daneben ab (auch `../`).
 *
 * **Ohne Anmeldung** gilt jeder Container ausser denen von
 * `statamic-clientrooms`: dessen Basis-Kennung (`statamic-clientrooms.container`)
 * und die Markenvarianten `<basis>-<markenId>`. Erkannt wird das an der
 * Konfiguration, die nur existiert, wenn das Addon installiert ist. Andere
 * private Container erkennt dieses Addon nicht; wer welche hat, meldet an.
 */
final class AccessContainers
{
    /** @var array<string, string|null>|null Kennung => Ordner (null: ganze Ablage) */
    private static ?array $allowed = null;

    /**
     * @param  string|array<int|string, string>  $handles  `['kennung']` oder `['kennung' => 'ordner']`
     */
    public static function allow(string|array $handles): void
    {
        self::$allowed ??= [];

        foreach ((array) $handles as $key => $value) {
            [$handle, $folder] = is_int($key) ? [(string) $value, null] : [$key, self::cleanFolder((string) $value)];

            // Ein Ordner, der einmal gesetzt ist, wird von einer spaeteren
            // Freigabe der ganzen Ablage nicht wieder aufgemacht.
            self::$allowed[$handle] = $folder ?? (self::$allowed[$handle] ?? null);
        }
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
            return array_values(array_intersect($existing, array_keys(self::$allowed)));
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

        $handle = strstr($id, '::', true);

        if (! self::allows($handle)) {
            return false;
        }

        $folder = self::folder($handle);

        if ($folder === null) {
            return true;
        }

        $path = substr($id, strlen($handle) + 2);

        return ! in_array('..', explode('/', $path), true)
            && str_starts_with(ltrim($path, '/'), $folder.'/');
    }

    /** Auf welchen Ordner eine erlaubte Ablage eingeschraenkt ist, sonst null. */
    public static function folder(string $handle): ?string
    {
        return self::$allowed[$handle] ?? null;
    }

    private static function cleanFolder(string $folder): ?string
    {
        $folder = trim($folder, '/');

        return $folder === '' ? null : $folder;
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
