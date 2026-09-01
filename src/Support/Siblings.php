<?php

namespace Goldnead\StatamicProducts\Support;

use Illuminate\Support\Facades\Schema;

/**
 * Which optional siblings the product screen may read from.
 *
 * `statamic-offers` is optional: a site can sell products through config
 * alone. `statamic-payments` is required by this addon, but its tables exist
 * only after its migrations ran, and the window between `composer require`
 * and `php artisan migrate` is real. Both are answered the same way — a class
 * the sibling ships has to exist and its table has to be there — and by class
 * *name*, so an install without the sibling never loads anything of it.
 *
 * Same shape as `RefTarget`'s probes for events and booking, and with the
 * same test seam: {@see pretend()} lets a suite declare a sibling present after
 * building its table by hand, or absent although it is installed.
 */
final class Siblings
{
    public const OFFERS = 'offers';

    public const PAYMENTS = 'payments';

    /** @var array<string, array{class: string, table: string}> */
    private const KNOWN = [
        self::OFFERS => ['class' => '\Goldnead\StatamicOffers\Models\Offer', 'table' => 'offers'],
        self::PAYMENTS => ['class' => '\Goldnead\StatamicPayments\Models\Payment', 'table' => 'payments'],
    ];

    /** @var array<string, bool> */
    private static array $pretended = [];

    public static function installed(string $sibling): bool
    {
        if (array_key_exists($sibling, self::$pretended)) {
            return self::$pretended[$sibling];
        }

        $known = self::KNOWN[$sibling] ?? null;

        return $known !== null
            && class_exists($known['class'])
            && Schema::hasTable($known['table']);
    }

    public static function pretend(string $sibling, bool $installed = true): void
    {
        self::$pretended[$sibling] = $installed;
    }

    public static function forget(): void
    {
        self::$pretended = [];
    }
}
