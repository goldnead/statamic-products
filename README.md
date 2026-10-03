# Statamic Products

The thing that is sold, with a name, a list price, and the access it grants.

`statamic-payments` knows what something costs. `statamic-offers` knows how it is presented.
`statamic-entitlements` knows that somebody has access to it. Between them sat a hole: the thing
itself existed nowhere, so every site invented it again — and on one of them it got invented twice,
as `member_packages` and `access_packages`, and the two drifted.

This addon is that missing middle and nothing more. **It keeps, it does not deliver.** It keeps what
a purchase opens (which courses, files, dates, session credits and whether the members area opens)
as an access record; what a course *shows*, which file is streamed and which credit is written stays
with the sibling addons and the website, which read it from here.

## Installation

```bash
composer require goldnead/statamic-products
php artisan migrate
```

Products then live under **Utilities → Products**.

Each product also has a screen of its own — **Offers and buyers** in a row's actions — that
shows the way back from the product: every offer that sells it, as lead product or in a
bundle, and the last fifty people who paid for it, including as an order bump or a
follow-up. The offers section is there only with `statamic-offers` installed; the buyers
section only once the payments tables exist. A missing sibling means no section, not an
empty one.

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
| `type` | What kind of thing it is. **An answer, not an instruction** — see below. |
| `ref` | The pointer at the thing of that kind. Empty for a download. |
| `amount_cent` | The list price. `0` is allowed and means free. An offer may undercut it; nobody else may. |
| `currency` | Empty means the shop currency. |
| `digital` | A tax fact, not a medium: it decides the place of supply and with it the mandatory notice (§ 3a UStG). **No default** — whoever creates a product answers it. |
| `grants` | The access a paid copy opens, as a list. Empty is normal. |
| `active` | Retired rather than deleted. |
| `brand_id` | Zero on every single-brand install. An agency with three brands gets three catalogues. Travels in the catalogue entry: `statamic-payments` 1.24.1 and newer stamps a follow-up charge with it instead of inheriting the brand of the payment it follows, and reads zero as "names no brand". |

What is deliberately *not* here: discounts, sales copy, placement, bundling. That is the offer
level and it already exists in `statamic-offers`. A product has a list price and no opinion about
how it is advertised.

## The kind is an answer, not an instruction

| Kind | What it is | `ref` points at |
| --- | --- | --- |
| `download` | PDF, workbook, recording | nothing — the thing *is* the product |
| `access` | A course, a members area, a community | a Statamic entry id |
| `event` | Live event, workshop, concert, webinar | an event uuid in `statamic-events` |
| `sessions` | A package of appointments | a booking funnel handle in `statamic-booking` |
| `cohort` | A programme with a start, an end and a group | a Statamic entry id |
| `feed` | A paid podcast or newsletter | a Statamic collection handle |

**Nothing here delivers anything.** Naming a product an `event` says it is a live date; it does not
reserve a seat. Kajabi and Podia go the other way — there the product type *is* the delivery, the
course type *is* the player — and that road ends in building a course player, a community engine, a
scheduler and podcast hosting. Delivery stays on the website and in the sibling addons that do that
job. Some of them do not exist yet.

Which is why an unresolvable pointer is **shown as unresolved rather than refused**. A pointer has
three states, not two:

- **resolved** — found it, and the screen shows its name.
- **gone** — the sibling that owns that kind is installed and says there is no such thing. A real
  defect: sold, paid, nothing behind it. Flagged on the row **and counted above the table**, because
  every column in a Control Panel listing can be switched off and a catalogue that reads as tidy
  because somebody hid a column is exactly the silent failure this is for.
- **cannot be checked** — the sibling is not installed, or has not migrated. Nothing is wrong with
  the product; nobody can confirm it either. Never flagged, because a badge that cries wolf is a
  badge everyone learns to ignore.

`statamic-events` and `statamic-booking` are optional. Install them and their pointers start being
checked; leave them out and products for those kinds can still be filed.

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

## Accesses

A product grants slugs (`grants`). An **access** is the record behind one slug: same handle, and it
keeps what that slug contains. **Utilities → Accesses** (own permission,
`access product-accesses utility`), with a list and a detail page built like the product's.

| Field | Meaning |
| --- | --- |
| `handle` | The grant slug. Unique across every brand, **frozen once a grant in `statamic-entitlements` carries it** (checked only when the `entitlements` table exists). A granted access cannot be deleted either. |
| `name`, `description`, `cover` | What the buyer's account shows. `cover` is an asset (`container::path`) or a URL. |
| `active`, `brand_id` | As on a product. |
| `opens_members_area` | Whoever holds it gets into the site's members area. |
| `contents` | Ordered list of `{kind, ref, label}`. For files the position is part of the download id. |
| `credits` | Credit lines `{line, session_type, kind: one_time\|subscription, count, per_month, valid_months, ended_at}`. |

**Content kinds:** `access` (another access, nested; a cycle is refused), `course` (an entry id of
the `statamic-courses` course collection), `file` (an asset, `container::path`), `event` (a uuid in
`statamic-events`). A site adds its own kinds:

```php
use Goldnead\StatamicProducts\Support\ContentKinds;

ContentKinds::register('community', 'Community-Bereich', 'Space (Kennung)');
```

Every pointer shows the same three states as a product's `ref`: found, gone, cannot be checked. A
site's own kinds are always "cannot be checked"; only the site knows what they point at.

**Credit lines:** `line` is assigned by the model, starts at 0 and is **never handed out twice**,
not even after a line was deleted (`credit_lines_issued` counts). It is the `<index>` in the
site's idempotency key, so reusing one would make a later credit look like it already happened. An
import may bring its own `line`; the counter then moves past the highest. Once the access has been
granted a line can be **ended** (`ended_at`), not deleted, and an ended line stays ended. These rules
live in the model, so an import that bypasses the form is held to them too (a breach throws a
`ValidationException`). The screen counts lines from 1; `line` itself stays as stored.

**Session types** come from the site, with names:

```php
use Goldnead\StatamicProducts\Support\SessionTypes;

SessionTypes::register('8f0c…', 'Einzelsession');
SessionTypes::register('2b7d…', 'Gruppensession');
```

Once any are registered the form picks from them and refuses anything else (a type already stored
stays savable and shows as gone). Without a registration the field is free text and every value
shows as "cannot be checked".

**Two tabs, one access.** The detail page sends a fingerprint of the state it loaded. If somebody
saved in the meantime, the save is refused with HTTP 409 and a message instead of silently
overwriting the newer state.

Files and the cover are picked with core's own assets field and asset browser; the stored value is
the asset id, `container::path`.

**Which containers.** An access is sold, so a client's private files must not end up in one. The
site names the containers an access may take files from:

```php
use Goldnead\StatamicProducts\Support\AccessContainers;

AccessContainers::allow(['assets', 'downloads']);
```

Without that call every container is offered except the ones of `statamic-clientrooms` (its
`statamic-clientrooms.container` handle and the per-brand `<handle>-<brandId>` variants). Other
private containers are not recognised; a site that has some registers its list. The server refuses
files and covers from any other container; a value already stored stays savable and is shown as
gone with a note. With only one allowed container the container dropdown disappears.

A granted access cannot be deleted, through the screen or through the model.

**On the product**, "Opens" is a picker over the accesses of the current brand and still stores
slugs, so payments and entitlements notice nothing. A slug without an access record stays valid and
is listed below the picker as unresolved, not as an error. `ref` becomes optional when one of the
granted slugs is an access, because the access then keeps the contents.

### Setting it up on a site

Everything a site tells this addon goes into the `boot()` of one of its service providers:

```php
use Goldnead\StatamicProducts\Support\AccessContainers;
use Goldnead\StatamicProducts\Support\ContentKinds;
use Goldnead\StatamicProducts\Support\SessionTypes;

ContentKinds::register('community', 'Community-Bereich', 'Space (Kennung)');
SessionTypes::register('8f0c…', 'Einzelsession');
AccessContainers::allow(['assets', 'downloads']);
```

With `goldnead/statamic-entitlements` (^1.4) installed there is nothing else to do: the addon puts
its own `PackageResolver` in place of entitlements' empty default, and a grant on an access then
covers everything the access contains. **A resolver the site binds itself always wins**, whatever
order the providers run in; the addon only replaces `NullPackageResolver`. To keep bundles off
entirely, bind a resolver that returns `[]`. Without entitlements the addon binds nothing and works
as before.

### What a grant covers

The same rules for the resolver and for `Accesses` below:

- A grant on an access covers the `ref` of each of its contents, and through contents of kind
  `access` everything the nested access covers, at any depth. So
  `Entitlements::allows($user, 'cvt-101')` is true for a grant on an access that holds `cvt-101` two
  levels down.
- For a `course` the key is its entry id **and** the slug `statamic-courses` asks about (the entry's
  `product` field, else its slug).
- **`active` does not change what existing grants cover.** It only decides whether an access is
  offered and granted anew (picker, new sales). A retired access resolves exactly like an active
  one, held directly or nested, so buyers of a retired offer keep what they bought. To take access
  away, revoke the grant in `statamic-entitlements` (`revoke()`); deactivating does not.
- A pointer to an access without a record covers that slug and ends there.
- Cycles end; every access is entered once.
- Across all brands: slugs are unique over every brand and a grant carries none.
- All accesses are read once per request (also per Octane request and queued job), and a save or
  delete of an access is visible to the next read in the same request. If the table cannot be read
  (before `php artisan migrate`) the resolver answers "no bundles" and logs an error instead of
  failing the page.

### Reading accesses

```php
use Goldnead\StatamicProducts\Support\Accesses;

$access = Accesses::find('choiraccelerator');   // null when there is no record

$access->expand();                // every slug a grant covers, transitive, without its own
$access->creditLines();           // this access's credit lines for new grants
$access->creditLines(includeEnded: true);       // ... including ended ones, to replay an old grant
$access->contentsOf('course');    // contents of one kind, nested accesses included, in order
$access->model();                 // the Access model, to write or for its own contents only
```

`find()` also returns inactive accesses, and `expand()` and `contentsOf()` answer for them as for
active ones, because existing grants stay valid. `expand()` is the exact inverse of
the resolver: for each slug it lists, the resolver names this access.

**Credits apply to the access granted directly, never through nesting.** `creditLines()` reads the
access itself and nothing it contains, so nesting a coaching access into a bundle does not credit
sessions twice.

## What it will not do

- **No course player, no community engine, no members area.** The addon says a product *is* a
  course. What a course shows stays the website's business. Otherwise an addon becomes a platform.
- **No calendar and no booking.** `statamic-events` and cal.com solve that. A product may point at
  them; it does not rebuild them.
- **No product content.** Lessons, videos and files are Statamic content and belong in collections
  and asset containers. An access *lists* them; it does not hold or serve them. A product is the
  ticket, not the show.

## Requirements

- PHP 8.2+
- Statamic 6
- `goldnead/statamic-payments` ^1.15

Optional: `goldnead/statamic-brand-context` for multi-brand catalogues,
`goldnead/statamic-entitlements` ^1.4 for grants that cover what an access contains.

## Licence

Proprietary.
