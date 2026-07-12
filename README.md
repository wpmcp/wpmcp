# wpmcp

AI builds and edits your WordPress site — and physically can't wreck it.

## Requirements

- PHP 8.1+
- WordPress 6.9+
- [Composer](https://getcomposer.org/)

## Installation

1. Clone or copy this plugin into `wp-content/plugins/wpmcp`.
2. Install PHP dependencies:

   ```bash
   composer install
   ```

3. Activate **wpmcp** from the WordPress Plugins screen.

## Development

Run the test suite:

```bash
composer test
```

Run the linter (PSR-12):

```bash
composer lint
```

## Known limitations

Free-tier history retention (last 20 operations) can bound how far `rollback-session` reaches back, and snapshot capture doesn't cover every post field (e.g. taxonomy terms). See [`docs/superpowers/specs/2026-07-12-wpmcp-mvp-design.md`](docs/superpowers/specs/2026-07-12-wpmcp-mvp-design.md#known-limitations-mvp) for details.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
