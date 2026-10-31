=== Dahu User Pricing ===
Contributors: hugovialjaime
Tags: woocommerce, wholesale, b2b, pricing, trade prices
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.1.0
License: MIT
License URI: https://opensource.org/licenses/MIT

Give each customer role its own price on every product, with the public price and the resale margin shown beside it.

== Description ==

Dahu User Pricing adds price tiers to a WooCommerce shop. A tier is a named
price level you create — Trade, Wholesale, Gold, whatever your shop calls them
— and every product and variation gains one price field per tier. Map a
WordPress role to a tier and accounts holding that role are charged that price,
in the catalogue, in the cart, at checkout, and on an order a shop manager
builds by hand.

It is built for a shop that sells the same catalogue to the public and to
resellers, and it is opinionated about what that needs:

* **Both prices, side by side.** A reseller sees their own price and the public
  one, each with a label you write ("Your price:", "Suggested retail:"). The
  public figure is the number they will put on their own shelf, so it is shown
  rather than hidden.
* **The margin, if you want it.** The gap between the two, as a percentage of
  the public price or as a multiplier. Off by default: a margin is commercial
  information, not decoration.
* **A view switch.** An account can flip between its own prices and the public
  ones with a shortcode-placed link. It changes what is displayed only — the
  cart always charges the account's own price.
* **A tier preview from the toolbar.** Look at the shop exactly as a tier's
  customers see it, without a second account. Prices while previewing really
  are that tier's prices, so an order cannot be placed until you stop: the
  banner says so and checkout refuses.
* **Manually built orders priced correctly.** A line added from the order
  screen's "Add product" modal is normally priced from the catalogue, by a
  request whose user is the shop manager — so a trade customer's phone order
  comes out at public prices. This plugin reprices it from the order's
  customer, net of tax, and leaves tax itself to WooCommerce.

Nothing changes on your shop until you create a tier and map a role to it.
Activating the plugin on its own is a no-op.

= Works with your existing prices =

Only a product's price is overridden; its regular price is left alone. That is
what keeps the public price readable while a tier price is in force, and it is
what the display and the margin are computed from.

A shop sale price does not stack on top of a tier price. A tier price is a
negotiated figure, not a discount off the public one, and compounding them
would make a reseller's price depend on a promotion they never agreed to. If
you want the lower of the two instead, the `dahu_user_pricing_price` filter is
three lines away.

= Variable products =

Each variation gets its own tier price fields, and a variation left empty
inherits its parent's. The cached price range of a variable product is keyed by
tier, so one account's prices are never served to another from cache.

= For developers =

* `dahu_user_pricing_price` — supply a tier price from anywhere: a commercial
  pricing plugin, a quantity-break table, an ERP. Return null for "no tier
  price".
* `dahu_user_pricing_child_line_metas` — the metas marking an order line whose
  price is carried by a parent line, so bundle components are left alone.
* `dahu_user_pricing_get_tier()`, `dahu_user_pricing_get_price_labels()` and
  `dahu_user_pricing_resolve_line_pricing()` — ask for a tier, its labels, or
  the net price a given customer pays, which is what code building an order
  needs.

== External services ==

This plugin contacts no external service. It sends nothing anywhere, collects
no statistics, and makes no network request of any kind.

== Installation ==

1. Install and activate the plugin. WooCommerce must be active.
2. Go to WooCommerce → User pricing and create a tier: a name, and the two
   labels shown in front of a price.
3. On the same screen, map the WordPress roles that should get that tier.
4. Edit a product. The General tab now has one price field per tier; a variable
   product has them on each variation.

An empty tier price field means that tier pays the public price. A zero means
that tier pays nothing — the two are different instructions and the plugin
keeps them apart.

== Frequently Asked Questions ==

= Does the view switch change what a customer is charged? =

No. It changes the prices shown in the catalogue. The cart and checkout always
charge the account's own tier price, and the cart keeps showing that price even
while the catalogue is showing public ones, because the cart's job is to say
what this account will be billed.

= What happens to my tier prices if I uninstall? =

They are kept. They are typed in by hand, there can be thousands of them, and
deleting them cannot be undone — so uninstalling leaves them in place and a
reinstall finds them again. There is a setting on the pricing screen to delete
them instead, off by default.

= I deleted a tier. Did I lose its prices? =

No. Deleting a tier removes it from the settings and unmaps its roles; every
price filed under it stays where it is, and recreating the tier under the same
name brings them all back.

= Can an account hold two tiers? =

It can hold two mapped roles, and it will get one of the two tiers — which one
is not defined. Give an account one mapped role.

== Changelog ==

= 1.1.0 =
* The activation screen now opens on the plugin's own mark rather than its initials, and the banner carries the same drawing as a watermark.

= 1.0.0 =
* First public release.
