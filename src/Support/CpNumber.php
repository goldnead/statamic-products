<?php

namespace Goldnead\StatamicProducts\Support;

use NumberFormatter;

/**
 * Numbers, written the way the Control Panel's language writes them.
 *
 * Copied from `statamic-offers` rather than shared, and on purpose: this addon
 * must not depend on that one — offers point at products, not the other way
 * round. Kept byte-identical in behaviour so a product listed here and the same
 * product priced in an offer next door do not read as two different things.
 * That already happened once inside offers, where one screen said "5.00 EUR"
 * and the screen beside it said "5,00 EUR" in the same German Control Panel.
 *
 * Formatting lives on the server because that is where the locale is already
 * settled — core's `Localize` middleware has run by the time a resource is
 * built, while a number assembled in Javascript would follow the browser.
 */
class CpNumber
{
    /**
     * `ext-intl` is not required by this package, so its absence has to mean a
     * plainer number rather than a 500.
     */
    public static function decimal(float|int $value, int $decimals): string
    {
        if (! class_exists(NumberFormatter::class)) {
            return number_format($value, $decimals, '.', '');
        }

        $formatter = new NumberFormatter(app()->getLocale(), NumberFormatter::DECIMAL);
        $formatter->setAttribute(NumberFormatter::MIN_FRACTION_DIGITS, $decimals);
        $formatter->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, $decimals);

        return (string) $formatter->format($value);
    }
}
