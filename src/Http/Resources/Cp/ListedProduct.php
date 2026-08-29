<?php

namespace Goldnead\StatamicProducts\Http\Resources\Cp;

use Goldnead\StatamicProducts\Models\Product;
use Goldnead\StatamicProducts\Support\CpNumber;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row.
 *
 * @mixin Product
 */
class ListedProduct extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'handle' => $this->handle,
            'name' => $this->name,
            // Formatted here rather than by the model, so this listing writes a
            // price the way the offers listing next door does. The model's
            // `amount()` is a machine-readable decimal and stays that way.
            'amount' => CpNumber::decimal($this->amount_cent / 100, 2),
            'currency' => $this->currency(),
            'digital' => $this->digital,
            // A count, not a list: the column has one line, and a product that
            // opens four things would push the price out of view. Null rather
            // than 0, because a column full of zeroes reads as a broken feature
            // while an empty cell reads as "opens nothing", which is normal.
            'grants_count' => count($this->grantSlugs()) ?: null,
            'active' => $this->active,
            // **The collision, made visible.** The catalogue lets config win
            // silently and on purpose; silent is right for the answer and wrong
            // for the screen. Two truths about one price is what had a checkout
            // charge 330 while the catalogue said 332, and the person who
            // noticed was a customer.
            'shadowed' => $this->isShadowedByConfig(),
            'edit_values' => [
                'name' => $this->name,
                'handle' => $this->handle,
                'amount_cent' => $this->amount_cent,
                'currency' => $this->currency,
                // As a string, because the Control Panel's `Select` does not
                // accept a boolean `modelValue` — see the note beside
                // `supplyOptions` in the screen. Laravel's `boolean` rule reads
                // '1' and '0' back the same way.
                'digital' => $this->digital ? '1' : '0',
                'grants' => $this->grantSlugs(),
                'active' => $this->active,
                // Whether the handle field may still be edited. Sent per row so
                // the form can lock the input instead of accepting a change and
                // failing validation after the fact.
                'sold' => $this->hasBeenSold(),
            ],
        ];
    }
}
