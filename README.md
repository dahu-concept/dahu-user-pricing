# Dahu User Pricing

Per-account pricing for WooCommerce: named customer tiers, a price per tier on
every product and variation, the public price and the resale margin shown
beside it, and manually built orders priced for the right customer.

MIT licensed. Requires WordPress 6.0, PHP 7.4 and WooCommerce 7.0.

## What it is for

A shop that sells the same catalogue to the public and to resellers has two
prices for every product, and the second one is not a discount off the first.
It is a negotiated figure that depends on who is logged in, it has to be
visible next to the public price so the reseller can see what they will make,
and it has to survive the places where WooCommerce prices things without
asking who the customer is.

That last part is most of the work. The catalogue, the cart and checkout all go
through price filters, so they are straightforward. An order a shop manager
builds by hand over the phone does not: the line is priced from the catalogue
by a request whose current user is the manager, and the amount is written onto
the line, where no filter can reach it. A trade customer's phone order
therefore comes out at public prices unless something reprices it explicitly.

## Tiers

A tier is a name you choose, plus the two strings shown in front of a price:

| Name | Label for the tier price | Label for the public price |
|---|---|---|
| Trade | `Your price:` | `Suggested retail:` |
| Wholesale | `Wholesale:` | `RRP:` |

There is no built-in list and no limit. Map a WordPress role to a tier and
accounts holding it are charged that tier's price.

Labels are trimmed when saved, and the space between a label and an amount
comes from the stylesheet. A gap that depended on a shop owner remembering a
trailing space would be invisible in the field and lost on the first save.

A tier's **key** is derived from its name once, at creation, and never again.
Prices live in product meta named after the key, so a key that changed would
leave every price under the old name unreachable with nothing on screen to say
so. Rename the label freely; the key just stops matching it.

**Nothing happens until a tier exists and a role is mapped to it.** Activating
the plugin changes no price.

## Prices

Every product gains one field per tier, among WooCommerce's own price fields.
Variations get their own, and a variation left empty inherits its parent's.

- **Empty** means this tier pays the public price.
- **Zero** means this tier pays nothing.

The two are different instructions and both ends of the plugin keep them apart
— `empty()` would quietly turn free into full price, which is the kind of bug
that only shows up in an invoice.

Only `price` is overridden; `regular_price` is left alone. That is what keeps
the public price readable while a tier price is in force, and it is what the
margin is computed from.

A shop sale price does **not** stack on top of a tier price: compounding them
would make a reseller's price depend on a promotion they never agreed to. A
shop wanting the lower of the two can say so on the filter below.

## What a price looks like

For an account with a tier, on a product that has a tier price:

```
Your price: 308.21 €   Suggested retail: 474.17 €   Margin: 35.0%
```

The public figure appears only when it differs from the tier price — two
identical amounts, one struck through, read as a discount that is not there.
The margin is off by default: it is commercial information, not decoration.

All of it is assembled server-side. The version this was ported from did it in
JavaScript, with five scripts walking the rendered page to find amounts by
class name; that works on exactly one theme, flashes the wrong price before it
runs, and is invisible to anything reading the page without running scripts.

## The view switch

`[dahu_user_pricing_view_switch]` renders a link that flips an account between
its own prices and the public ones. Nothing places it automatically: a plugin
injecting a control into a theme's header would be guessing at a layout it
cannot see.

**It changes what is displayed only.** The cart always charges the account's
own price, and keeps showing that price even while the catalogue shows public
ones, because the cart's job is to say what this account will be billed. A
switch that changed the charged price would be a discount control disguised as
a display preference.

## Previewing a tier

Roles listed as managers get a toolbar menu for looking at the shop as any
tier — the only reliable way to check a tier is configured correctly, and
quicker than keeping a second account in a second browser.

The preview is faithful, which makes it dangerous: prices while previewing
really are that tier's prices, in the cart as well as the catalogue. So an
order cannot be placed while one is running. A banner says so at the top of
the page, in the cart and at checkout, and checkout refuses with a message
naming the way out. A warning on its own was what the earlier version had, and
a warning does not stop a tired person at nine in the evening.

A preview overrides one thing: the tier. It touches no role and no capability.

## Orders built in wp-admin

Adding a product to an order reprices every line from the order's customer,
net of tax, and leaves tax itself to `WC_Order::calculate_totals()`, which
knows the customer's address and tax class. This plugin does not implement tax.

On the "Add order" screen the chosen customer is not written to the draft until
Create is clicked, so the browser sends the field's current value with the
add-item request and the server persists it a few seconds early.

Changing the customer on an order that already has lines shows a **Reprice
lines for this customer** button. It is not automatic: a line may have been
adjusted by hand, and a silent reprice would throw that away with no undo.

Lines whose price is carried by a parent line — bundle and composite
components — are left alone. This plugin does not depend on those plugins; it
recognises the metas they set so as not to damage them.

## For developers

```php
// Supply a tier price from anywhere. Return null for "no tier price".
add_filter( 'dahu_user_pricing_price', function ( $price, $product, $tier ) {
	return $price;
}, 10, 3 );

// Metas marking an order line whose price belongs to a parent line.
add_filter( 'dahu_user_pricing_child_line_metas', function ( $metas ) {
	$metas[] = '_my_bundle_parent';
	return $metas;
} );
```

```php
dahu_user_pricing_get_tier( $user_id = null );          // tier key, or null
dahu_user_pricing_get_price_labels( $tier = null );      // array{public, tier}
dahu_user_pricing_resolve_line_pricing( $product, $user, $quantity = 1 );
```

The last one is what code building an order needs: a quote, a renewal, an
import all write amounts onto the line themselves, where no price filter
applies, so they have to ask for the price rather than hope.

## Uninstalling

The plugin's option goes. The tier prices on your products **stay**, because
there can be thousands of them, they were typed in by hand, and deleting them
cannot be undone. A setting on the pricing screen deletes them instead, off by
default.

Deleting a single tier works the same way: the tier goes from the settings and
its roles are unmapped, every price filed under it stays, and recreating the
tier under the same name brings them back.
