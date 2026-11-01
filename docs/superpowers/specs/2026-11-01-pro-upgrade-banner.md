# In-admin Pro banner

Status: approved by the owner on 2026-11-01 ("replicate, in our high quality TDD way").
Branch: feat/pro-upgrade-banner. Marketing companion: coupon pass-through on
wpmcp-marketing/src/pages/pricing.astro.

## Why

The reference competitor reports that 80 to 90% of its Pro sales start from a
dismissible banner inside wp-admin that names Pro and links to the pricing
page. WP MCP has no in-plugin path from a free install to the pricing page
except the Freemius submenu. This adds one, built on the plugin's existing
notice conventions (Announcements, Snapshot_Retention_Notice).

## What the competitor does (studied from its source, internal only)

- One PHP class hooked on admin_notices, rendered on every wp-admin screen
  except its own (its own screens carry a header CTA instead).
- Shown to manage_options users only, hidden when the licence allows premium
  code, hidden once the user dismissed it (user meta, permanent).
- Dismiss is an AJAX call with a nonce; the markup carries inline CSS and JS.
- The CTA is the public pricing page on its own site, never the Freemius
  in-admin pricing iframe; a secondary link goes to the same page.
- Copy is a headline plus six feature bullets with check marks.
- Its admin frame also rotates Pro and lifetime-deal promo slides, and every
  Pro-only tab renders a locked card with an upgrade link.

## What we build

`WPMCP\Admin\Pro_Banner` (src/Admin/Pro_Banner.php), registered next to
Announcements in Plugin.php.

Shown when all of these hold:

- the user has manage_options;
- `Pro\Gate::is_pro()` is false;
- the current screen is the WordPress Dashboard (`dashboard`), the Plugins
  list (`plugins`), or any WP MCP screen (`toplevel_page_wpmcp`,
  `wpmcp_page_*`); the exact list is filterable through
  `wpmcp_pro_banner_screens`;
- the user has not dismissed it in the last 30 days, and has dismissed it
  fewer than three times in total.

Dismissal is a nonce-protected admin-post link, like Announcements (works
without JavaScript, redirects back). State is one user-meta array:
`wpmcp_pro_banner` => `['dismissed_at' => int, 'dismissals' => int]`.
The third dismissal is permanent.

The offer is one array, `Pro_Banner::offer()`, filterable through
`wpmcp_pro_banner_offer`: coupon code, percent off, and the pricing URL with
`coupon=` plus UTM parameters (`utm_source=plugin`, `utm_medium=admin-banner`,
`utm_campaign=pro-banner`). Defaults: code `WPMCP20`, 20 percent, the owner
confirms the code before release. Filtered values are sanitized before output.

Copy stays clear of the pay-to-unlock phrases the compliance tool flags
("upgrade to pro", "go pro", "unlock", "buy now"): it says what Pro keeps and
adds, and the button reads "See Pro pricing". No em dashes or en dashes.

No JavaScript. Flat colours, small inline stylesheet scoped to the notice.

## Flavors

The wp.org and WooCommerce builds carry no paid tier, so the file is added to
`removed_paths` in scripts/flavors/wporg/policy.php, and the `use` line and
the registration block in Plugin.php are removed through count-validated
edits in strip.php. WporgStripTest pins both.

## Tests (tests/free/Admin/ProBannerTest.php)

- Renders the offer for an administrator on the dashboard: pricing URL,
  coupon, UTM parameters, dismiss link with nonce.
- Nothing for a Pro licence; nothing below manage_options.
- Only on the allowed screens; the list is filterable.
- Dismiss refuses a missing nonce and a user below manage_options.
- Dismiss hides for 30 days, then the banner returns; the third dismissal is
  permanent (a `set_now_for_tests` seam advances time).
- The offer filter changes coupon, percent and URL; HTML and `javascript:`
  values are neutralised.
- Output contains none of the compliance tool's upsell phrases and no dashes.

## Out of scope

Locked cards on Pro-only screens, promo rotation, an "Upgrade" submenu, any
change to Freemius menus. Follow-ups once the banner has numbers.
