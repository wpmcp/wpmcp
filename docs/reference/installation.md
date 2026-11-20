# Install the WordPress MCP plugin: requirements, zip, WP-CLI and source

Moved from the repository README; the short overview is in [README.md](../../README.md) and the index of these pages in [docs/reference/README.md](README.md).

## Requirements

| Dependency | Version |
| --- | --- |
| WordPress | >= 6.9 (bundles the Abilities API) |
| PHP | >= 8.1 |
| Composer | for installing from source |

## Installation

Download [wpmcp.zip](https://github.com/wpmcp/wordpress-mcp/releases/latest/download/wpmcp.zip) (always the latest release), upload it under **Plugins > Add New > Upload Plugin**, and activate it. On activation WordPress shows an optional opt-in for update and security emails; **Skip** works and the plugin runs the same either way. The free plugin is complete on its own, and Pro unlocks in the same plugin when you add a licence: there is nothing else to install.

With WP-CLI:

```bash
wp plugin install https://github.com/wpmcp/wordpress-mcp/releases/latest/download/wpmcp.zip --activate
```

If your host refuses the upload for size (the zip is about 4.5 MB and some hosts cap uploads at 2 MB), use the WP-CLI line above, or unzip it into `wp-content/plugins/` so you have `wp-content/plugins/wpmcp/wpmcp.php`.

Every [release](https://github.com/wpmcp/wordpress-mcp/releases) also carries `safe-mcp-for-woocommerce.zip` (Safe MCP for WooCommerce, store tools and the safety core only) and `wpmcp-directory-<version>.zip` (the wordpress.org directory edition, without the licensing SDK or Pro). wpmcp is not yet on the wp.org plugin directory (planned).

### From source (developers)

```bash
git clone https://github.com/wpmcp/wordpress-mcp.git wp-content/plugins/wpmcp
cd wp-content/plugins/wpmcp
composer install --no-dev
```

Then activate **wpmcp** from the Plugins screen in wp-admin. The folder must be named `wpmcp`.
