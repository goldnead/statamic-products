# Statamic Products

The thing that is sold, with a name, a list price, and the access it grants.

`statamic-payments` knows what something costs. `statamic-offers` knows how it is presented.
`statamic-entitlements` knows that somebody has access to it. Between them sat a hole: the thing
itself existed nowhere, so every site invented it again — and on one of them it got invented twice,
as `member_packages` and `access_packages`, and the two drifted.

This addon is that missing middle and nothing more. It does not deliver anything. What a course
*shows* stays the website's business; this says that a course exists, what it costs, and what it
opens.

## Installation

```bash
composer require goldnead/statamic-products
php artisan migrate
```

Products then live under **Utilities → Products**.

## Usage

Create products under **Utilities → Products**. From there they behave like any other entry in the
payment catalogue, so nothing else has to learn about this addon:

```php
use Goldnead\StatamicPayments\Support\Checkout;

// Sell one. The handle is all `statamic-payments` needs; the amount comes from
// the server and never from the request.
$checkout = app(Checkout::class)->start('stimmwerkstatt');

abort_if($checkout === null, 404);          // no such product, or it is inactive

return redirect()->away($checkout->checkoutUrl);
```

```php
// Ask what something costs, without starting anything.
$product = app(\Goldnead\StatamicPayments\Support\Catalogue::class)->find('stimmwerkstatt');

// Or reach the row itself.
use Goldnead\StatamicProducts\Models\Product;

$product = Product::firstWhere('handle', 'stimmwerkstatt');
$product->grantSlugs();          // ['stimmwerkstatt-zugang']
$product->hasBeenSold();         // whether the handle is frozen
$product->isShadowedByConfig();  // whether a config line overrules this row
```

An offer points at a product by its handle, exactly as it pointed at a config line before:

```php
use Goldnead\StatamicOffers\Models\Offer;

Offer::create([
    'handle' => 'fruehling',
    'name' => 'Frühlingsaktion',
    'product' => 'stimmwerkstatt',
    'amount_cent' => 9900,
]);
```

## What a product is

| Field | Meaning |
| --- | --- |
| `handle` | What offers, payments and invoices call it. **Unique across every brand**, and frozen once the product has been sold. |
| `name` | What is bought. Goes into the order confirmation and onto the invoice (§ 312j BGB). |
| `amount_cent` | The list price. `0` is allowed and means free. An offer may undercut it; nobody else may. |
| `currency` | Empty means the shop currency. |
| `digital` | A tax fact, not a medium: it decides the place of supply and with it the mandatory notice (§ 3a UStG). **No default** — whoever creates a product answers it. |
| `grants` | The access a paid copy opens, as a list. Empty is normal. |
| `active` | Retired rather than deleted. |
| `brand_id` | Zero on every single-brand install. An agency with three brands gets three catalogues. |

What is deliberately *not* here: discounts, sales copy, placement, bundling. That is the offer
level and it already exists in `statamic-offers`. A product has a list price and no opinion about
how it is advertised.

## How it reaches the checkout

Two seams on `statamic-payments`' `Catalogue`, and they answer different questions.

- **`extend()` prices one handle.** Reached by anything a browser sends, and by a provider webhook
  hours after the sale — so it is a single indexed lookup, and it does **not** depend on which brand
  is current. A webhook has no brand, and a price that needed one could not be resolved there at all.
- **`contribute()` lists what there is.** That question only ever comes from a screen, so the answer
  is scoped: this brand's products, the active ones.

Requires `goldnead/statamic-payments` 1.15 or newer for `Catalogue::contribute()`. Without it a
product could be bought but would not appear in any picker — which is how the product select in the
offer form used to show three of six products and then refuse the save with a 422.

## Config wins

A handle may exist both here and in `config/statamic-payments.php`. **The config file wins**: a
price in version control was written on purpose, and a deploy must not be silently overruled by a
row in a table.

Silent is right for the answer and wrong for the screen, so the collision is shown — a badge on the
row and a warning in the form. Two truths about one price is the illness that had a checkout charge
330 while the catalogue said 332, and the person who noticed was a customer.

Nothing has to be migrated. A site whose prices live in a file can install this addon and never
notice it.

## What it will not do

- **No course player, no community engine, no members area.** The addon says a product *is* a
  course. What a course shows stays the website's business. Otherwise an addon becomes a platform.
- **No calendar and no booking.** `statamic-events` and cal.com solve that. A product may point at
  them; it does not rebuild them.
- **No product content.** Lessons, videos and files are Statamic content and belong in collections.
  A product is the ticket, not the show.

## Requirements

- PHP 8.2+
- Statamic 6
- `goldnead/statamic-payments` ^1.15

Optional: `goldnead/statamic-brand-context` for multi-brand catalogues.

## Licence

Proprietary.
