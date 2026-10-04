<?php

namespace Goldnead\StatamicProducts\Support;

use Closure;
use Illuminate\Support\Facades\Log;
use Throwable;

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
 * Es sei denn, sie gibt eine Quelle fuer Namen (`options:`) und einen
 * Resolver (`resolver:`) mit, siehe {@see self::register()}.
 */
final class ContentKinds
{
    public const ACCESS = 'access';

    public const COURSE = 'course';

    public const FILE = 'file';

    public const EVENT = 'event';

    /** @var array<string, array{label: string, ref_label: string|null, options: Closure|null, resolver: Closure|null}> */
    private static array $registered = [];

    /**
     * Eine Art der Website anmelden.
     *
     * Am besten im `boot()` eines ServiceProviders der Website. Die Beschriftung
     * wird so angezeigt, wie sie hier steht; uebersetzen ist Sache des Aufrufers.
     *
     * `$options` macht aus dem Textfeld eine Auswahl mit Namen: ein Callable ohne
     * Argumente, das `[Wert => Beschriftung]` liefert. Es laeuft, wenn das Formular
     * aufgeht, nicht beim Anmelden. Wirft es oder liefert es etwas anderes, wird
     * das protokolliert und das Formular bleibt beim Textfeld.
     *
     * `$resolver` beantwortet die Pruefung des Verweises, ein Callable mit dem
     * Verweis als Argument: eine Zeichenkette (gefunden, das ist der Name),
     * `null` (gibt es nicht mehr) oder `RefTarget::unknowable()` (kann ich nicht
     * pruefen). Wirft er, gilt der Verweis als nicht pruefbar. Ohne Resolver ist
     * jeder Verweis der Art „nicht pruefbar", auch mit Auswahl.
     *
     * @param  (callable(): array<array-key, string>)|null  $options
     * @param  (callable(string): (string|RefTarget|null))|null  $resolver
     */
    public static function register(
        string $kind,
        string $label,
        ?string $refLabel = null,
        ?callable $options = null,
        ?callable $resolver = null,
    ): void {
        self::$registered[$kind] = [
            'label' => $label,
            'ref_label' => $refLabel,
            'options' => $options === null ? null : Closure::fromCallable($options),
            'resolver' => $resolver === null ? null : Closure::fromCallable($resolver),
        ];
    }

    /**
     * Die Auswahl einer Art der Website, jetzt gelesen.
     *
     * Null heisst: Textfeld. Das gilt ohne Quelle, bei einer leeren Liste und bei
     * einer Quelle, die versagt (dann steht eine Warnung im Log). Eingebaute
     * Arten haben hier nie etwas; ihre Listen baut der Controller.
     *
     * @return list<array{value: string, label: string}>|null
     */
    public static function choices(string $kind): ?array
    {
        $source = self::$registered[$kind]['options'] ?? null;

        if ($source === null) {
            return null;
        }

        try {
            $options = $source();

            if (! is_array($options)) {
                throw new \UnexpectedValueException('options must return [value => label], got '.get_debug_type($options));
            }

            $list = [];

            foreach ($options as $value => $label) {
                $list[] = ['value' => (string) $value, 'label' => (string) $label];
            }
        } catch (Throwable $e) {
            Log::warning('statamic-products: options for content kind "'.$kind.'" failed, falling back to free text: '.$e->getMessage());

            return null;
        }

        return $list === [] ? null : $list;
    }

    /**
     * Der Resolver einer Art der Website, oder null.
     */
    public static function resolver(string $kind): ?Closure
    {
        return self::$registered[$kind]['resolver'] ?? null;
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
