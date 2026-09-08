# Changelog

## 1.5.0 — 2026-09-07

### New: a product can carry a payment plan

Four columns on `products`: `interval`, `times`, `trial_days`, `trial_amount_cent`. All
nullable, and `interval` is the switch — without it a product behaves exactly as before.

**This is not a new capability but a restored one.** `statamic-payments` has been able to do
subscriptions, instalments and trials since 1.5.0 — one mechanism, three faces: `times = null`
is a subscription, `times = N` an instalment plan, `trial_days` a trial
(`Subscriptions::planFor()`). The plan is read from the catalogue.

As long as the catalogue was a config, the plan sat there and worked. Since this addon moved
the products into a table, `Product::toCatalogueEntry()` passed on exactly `handle`, `name`,
`amount_cent`, `currency`, `digital` and `grants` — and so **a row from the database could no
longer carry a plan**. `planFor()` returned `null` for every table product, without an error and
without a log entry. The capability was not broken, it was unreachable.

Noticed on 2026-09-06 in a concrete place: the ChoirAccelerator page on adriangoldner.com
promises "2 × 780 EUR or 3 × 520 EUR", and while moving the purchase onto our own funnel it
turned out that our own checkout cannot offer that.

### Control Panel

Four fields in the product form, together as one decision. Count, trial days and trial amount
are disabled without an interval and are cleared along with it on save — otherwise a `times = 3`
stays hanging on a product sold once, which nobody sees and which takes effect as soon as
somebody later sets an interval.

Zero charges are rejected: that is a typo, not an instruction.

### Why no enumeration for `interval`

Free text in the provider's own wording (`1 month`, `12 weeks`).
`Subscriptions::afterOneInterval()` passes the value on to Carbon and falls back to one month on
anything unreadable instead of throwing. A stricter rule here would cut down what the payment
addon can do.

### Tests

`PaymentPlanTest` (5) and three in `ProductScreenTest`. The most important is the first: a
product **without** a plan still gets none. A migration with default values would have turned
every existing product into a subscription.

`down()` checks for the table before it drops columns — `CatalogueTest` deletes it deliberately,
to show that a missing table does not take the checkout down with it.

## 1.4.0 — 2026-09-05

### New: products in the sales section of the sidebar

The product screen is registered as a Statamic utility and therefore sat under "Utilities",
between Cache and PHP Info (Adrian, 2026-09-03, F36). It now hangs in the sales section that
`statamic-payments` names with `Cp\SuiteNav::section()`: the same section as Payments, Offers
and Funnels, so that two almost identically named sections do not stand side by side, because
Statamic does not translate section names.

Route and permission stay. The entry under "Utilities" is unhooked, otherwise the screen would
stand there twice; that is how it was in the first attempt on 09-04.

`Cp\SuiteNav` only exists from `goldnead/statamic-payments` 1.18.0 on, and the constraint still
allows `^1.15`. The call therefore sits behind `class_exists()`, as in `statamic-booking`: with
an older payments the screen gets a section "Products" of its own instead of a `Class not found`
while the whole CP navigation is being built. The shared sales section exists from payments
1.18.0 on.

Internal: `tests/Fakes/insights-table-metric.php` brought up to insights 1.2.1 (`bucketed()`
sorts the buckets explicitly).

## 1.3.0 — 2026-09-02

### New: the product shows its offers and its buyers

Until now the family only knew the forward direction: an offer points at a product, a payment row
carries its handle. From the product there was no way back, and "who bought this" meant opening
two other screens and searching.

Now every product has a page of its own (`GET utilities/products/{product}`, reached from the
listing through "Offers and buyers" in the row actions and from the edit stack). It shows the
product's facts and below them two sections:

- **Offers** — every offer that sells this product, as the main product (`product`) or in a
  bundle (`products`), with slot, price (the list price when the offer has none of its own),
  active, and a jump into the offer listing, already filtered on the handle.
- **Buyers** — the last 50 paid purchases through `payment_items` ⋈ `payments`, so including
  those bought as an order bump or after the purchase: email, date, the row's amount, refunded
  badge, jump into the payment listing.

The page is brand-scoped like the listing: in multi-brand operation a product of another brand is
a 404, and the buyer list is additionally restricted to the product's `payments.brand_id`. With
no current brand there is no page (fail-closed, as everywhere in the family).

Both sections exist only when the addon in question is installed and migrated
(`Support\Siblings`, a class check plus a table check). If it is missing, the section is
missing — `null` is "I cannot know", an empty list is "nobody", and the screen shows only the
latter as an empty state.

The jumps into the neighbouring listings are searches (`?search=`), not detail pages: neither
offers nor payments have one. If the neighbouring utility is not registered, there is no button.

## 1.2.0 — 2026-08-30

### Fixed: an abandoned purchase froze the handle forever

`hasBeenSold()` asked whether **any** payment row carried the handle. But `Checkout::start()`
writes the `payments` row and its `payment_items` **before** it calls the provider, with status
`initiated` — and `prune_unpaid_after_days` is `0` out of the box, so nobody clears them away.

Consequence: a visitor opens the checkout and closes the tab. After that the product's handle is
locked and deletion is refused, permanently, for a product nobody ever bought. The reason was
visible nowhere — the message says "has already been sold".

Now only `status = paid` counts. A refund does not lift the lock: refunds are columns on a paid
row, the status stays `paid`, and the invoice still exists.

### Fixed: a PATCH without `active` or `grants` deleted both, silently, with 200

`$request->boolean()` reads a missing key as `false`, and `(array) null` is `[]`. Anyone who
changed only the price by PATCH switched the product off and threw its grants away — and got
`200` back. Our own form always sends every field, which is why it never showed up there.

Now only what was actually sent is written. `grants: []` remains a statement and still empties.

**Both bugs had got past a green test suite**, because every fixture paid with `paid` and no test
sent a partial PATCH. Five tests for it, counter-checked: against the old state they turn red.

Found while writing the documentation — by an agent that checked the prose against the code.

## 1.1.0 — 2026-08-30

### Fixed: the types were named in German, while this family stores English

`statamic-payments` stores `paid`, `open`, `expired`. `statamic-offers` stores `bump`,
`post_purchase`, `standalone`. `statamic-booking` stores `booked`, `cancelled`. In 1.0.0 this
addon had `zugang`, `termin`, `sitzungen` and `kohorte` — German values in an English code base,
in a column a buyer reads in his own database.

New: `access`, `event`, `sessions`, `cohort`. `download` and `feed` were English already.

A migration rewrites existing rows; it is reversible, because a rollback that leaves behind
values the old code does not know is not a rollback. It was noticed during the Statamic Addon
Studio audit, a few hours after 1.0.0 and before anybody had installed it — a stored value is
fixed from the first installation on.

**Anyone who has already installed 1.0.0 only needs `php artisan migrate`.**

## 1.0.0 — 2026-08-30

First version. Requires `goldnead/statamic-payments` **1.15** — that is where
`Catalogue::contribute()` sits, without which a product would be purchasable but would appear in
no selection.

### New: a product is finally a thing

Until now a product lay spread over three places, and none of them knew what it is:
`statamic-offers` knew how to present one, `statamic-payments` knew what one costs — a line in a
config file, without a screen — and `statamic-entitlements` knew that somebody has access to it,
as a free string. The thing itself existed nowhere, so every website invented it anew. On
adriangoldner.com it was invented twice, as `member_packages` and as `access_packages`, and the
two drifted apart.

One table, one screen under **Utilities → Products**, and two connections to the payment addon's
catalogue. No more than that: **the addon delivers nothing.** What a course *shows* stays the
website's business. It says that a course exists, what it costs and what it opens.

### New: a type and a pointer

`type` and `ref`. Six types: download, access, event, sessions, cohort, feed.

**The type is a statement, not an automation.** Calling a product "event" says that it is a live
date; it reserves no seat. With Kajabi and Podia the product type is the delivery itself — the
course type *is* the player — and that road ends in building the course player, community
engine, calendar management and podcast hosting yourself. Delivery happens on the website and in
the neighbouring addons. Some of those do not exist yet.

That is why a pointer has **three** states, not two: found, gone, and *nobody here can say*.
Confusing the last two means either accusing a faultless product, or waving a broken pointer
through because the package that would have noticed it is missing. An event product can be
created before `statamic-events` is installed.

**What points at nothing is counted, not only marked.** The badge on the row says which product
is affected; the number above the table says that any are affected at all. Every column in the
Control Panel can be switched off, and a catalogue that looks clean because somebody hid a column
is exactly the silent error the field is built against.

`statamic-events` and `statamic-booking` are optional and ship as `require-dev` — so that
resolution is tested against their real migration and their real model, and not against a table
this addon made up for itself.

### The decisions inside it

**The handle is unique across all brands, even when the row is not.** It stands on payment rows
and invoices that must still be readable in years, and none of those places knows a brand — a
provider webhook least of all. An agency with three brands therefore names its products apart.
That costs something, and it costs less than a webhook that cannot price what it was sent.

**The handle of a sold product is fixed.** Renaming breaks nothing loudly — it makes an old
invoice show a row whose product nobody can find any more. Everything else about it stays
changeable; a price that could never be corrected after the first sale would be the worse rule.

**A sold product is not deleted but retired.** The delete button says no instead of silently
doing something else.

**`digital` has no preselection.** It is not a description of the medium but the statement that
decides the place of supply and thereby the mandatory note on the invoice (§ 3a UStG). Any
default is wrong for half of a catalogue, and a wrong default shows up as a tax line nobody
checked.

**Config beats table.** A price in a file is in version control and was written down
deliberately. The collision is shown anyway — badge in the listing, warning in the form —
because two truths about one price are exactly the illness that had a checkout charging 330 while
the catalogue said 332.

**Resolving prices needs no brand, listing products does.** `extend()` is reached by everything a
browser sends, and by a webhook hours after the purchase; `contribute()` only by a screen. The
one answers unfiltered, the other falls closed.

**A missing table does not take the checkout with it.** Between `composer require` and
`php artisan migrate` there are minutes on a real host and months on a forgotten staging box. In
that window the catalogue answers as if the addon were not installed — and writes it to the log,
because an empty catalogue and a broken one look the same from outside.
