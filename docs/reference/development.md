# Developing the WordPress MCP plugin: test gate and local setup

Moved from the repository README; the short overview is in [README.md](../../README.md) and the index of these pages in [docs/reference/README.md](README.md).

## Development

```bash
composer install       # install dev dependencies
composer test:local    # the test gate: lint, drift check, PHPUnit on the default and minimum WordPress and on multisite, coverage floor
composer lint          # PSR-12 with WordPress-idiomatic naming
```

The PHPUnit suite does not run on GitHub Actions. It runs locally through `bin/test-local.sh`, and the pre-push hook in `.githooks/` runs it before every push, on the default WordPress, on the `Requires at least` minimum, and on the default WordPress installed as a multisite network (`WP_MULTISITE=1`). Every leg runs even when one fails, and the run ends with a pass/fail summary. The first run of the script turns the hook on for the clone. GitHub keeps only the static gates: lint, the drift check, compliance and Plugin Check. Run `composer check:flavors` for the release-flavor class guard in seconds; the rule behind it is in [CONTRIBUTING.md](../../CONTRIBUTING.md).

The script needs MariaDB and Subversion, and nothing else to configure:

```bash
brew install mariadb@10.11 subversion   # keg-only, does not conflict with a mysql install
bin/test-local.sh                       # default WordPress with the coverage floor, the `Requires at least` WordPress, multisite, live groups
bin/test-local.sh --all                 # the same on every WordPress in WP_VERSIONS, plus the released MCP Adapter leg
bin/test-local.sh --plan                # list the legs a run would execute, run nothing
bin/test-local.sh --multisite           # only the suite on the default WordPress as a multisite network (takes -- --filter too)
bin/test-local.sh -- --filter SnapshotTest   # a targeted PHPUnit run
ELEMENTOR_VERSION=latest bin/test-local.sh   # check for drift against the newest Elementor
WPMCP_TEST_ADAPTER_PLUGIN=/path/to/mcp-adapter/mcp-adapter.php bin/test-local.sh   # against a canonical MCP Adapter build, e.g. its trunk after composer install --no-dev
bin/test-local.sh --live-forms          # only the Contact Form 7 + Flamingo live group
bin/test-local.sh --live-blocks         # only the block suites live group (Kadence Blocks, GenerateBlocks, Spectra, Otter Blocks, Stackable, Ultimate Blocks)
bin/test-local.sh --live-buddypress     # only the BuddyPress live group
bin/test-local.sh --live-bbpress        # only the bbPress live group
bin/test-local.sh --live-lms            # only the Tutor LMS and LifterLMS live groups
bin/test-local.sh --live-crm            # only the FluentCRM and MailPoet live group
bin/test-local.sh --live-helpdesk       # only the BetterDocs, Fluent Support and Awesome Support live group
bin/test-local.sh --live-activity-log   # only the WP Activity Log and Simple History live group (both downloaded from WordPress.org)
bin/test-local.sh --live-action-scheduler   # only the Action Scheduler live group (the standalone plugin, downloaded from WordPress.org; WPMCP_LIVE_ACTION_SCHEDULER=1 is what it sets)
bin/test-local.sh --live-site-gate      # only the site gate live group (SeedProd, WP Maintenance Mode, Under Construction, Maintenance and CMP, downloaded from WordPress.org)
bin/test-local.sh --live-email-log      # only the WP Mail Logging, Check and Log Email and Email Log live group (all downloaded from WordPress.org)
bin/test-local.sh --live-amelia         # only the Amelia Lite live group
bin/test-local.sh --live-wp-mail-smtp   # only the WP Mail SMTP live group
bin/test-local.sh --live-fluent-smtp    # only the FluentSMTP live group (downloads the plugin)
bin/test-local.sh --live-post-smtp      # only the Post SMTP live group (downloads the plugin)
WPMCP_MEMBERPRESS_SRC=/path/to/memberpress bin/test-local.sh --live-memberpress   # only the MemberPress live group, against a local MemberPress copy
WPMCP_ELEMENTOR_PRO_SRC=/path/to/elementor-pro bin/test-local.sh --live-elementor-pro   # only the Elementor Pro live group, against a local Elementor Pro copy
WPMCP_WP_ROCKET_SRC=/path/to/wp-rocket WPMCP_PERFMATTERS_SRC=/path/to/perfmatters WPMCP_FLYING_PRESS_SRC=/path/to/flying-press bin/test-local.sh --live-cache-settings   # only the cache settings live groups, against local copies (any one is enough)
WPMCP_JET_ELEMENTS_SRC=/path/to/jet-elements bin/test-local.sh --live-jet-elements   # only the JetElements live group, against a local JetElements copy
WPMCP_WS_FORM_SRC=/path/to/ws-form-pro bin/test-local.sh --live-ws-form   # only the WS Form live group, against a local WS Form PRO copy
bin/test-local.sh --live-links          # only the live Pretty Links and ThirstyAffiliates group, against the real free plugins
bin/test-local.sh --live-site-reviews   # only the live Site Reviews group, against the real free plugin
bin/test-local.sh --live-adapter        # only both suites on the latest MCP Adapter plugin released on WordPress.org (WPMCP_ADAPTER_VERSION pins one)
bin/test-local.sh --prune               # prune the test cache to its budget now (--prune --all: every install no run is using)
```

It starts a private MariaDB server under `~/.cache/wpmcp-tests`, installs each WordPress version there once per version of the install scripts and of the test plugins (each unpinned plugin's latest release is looked up on WordPress.org on every run, falling back to the newest cached install offline, and the summary names the plugin versions each leg ran against), and gives every worktree its own database, so worktrees can run side by side. The default run ends with eight live groups, each on its own install: the live forms group (the Contact Form 7 adapter against the real Contact Form 7 and Flamingo), the live blocks group (blocks inserted through the block-suites tools, rendered with the real Kadence Blocks, GenerateBlocks, Spectra, Otter Blocks, Stackable and Ultimate Blocks), the live BuddyPress group (the BuddyPress ops run through the real BuddyPress, its hooks firing, and rolled back), the live bbPress group (topics and replies posted and topics closed, stuck and spammed through the real bbPress, each rolled back to the exact rows, counts and sticky lists it started from) and the live Tutor LMS and LifterLMS groups (a course tree built through the LMS ops, read back through each plugin's own curriculum API, and rolled back) and the live CRM group (FluentCRM contacts and MailPoet subscribers written, imported and rolled back through each plugin's own API without resubscribing anyone, and campaigns drafted, tested and sent through each plugin's own mailer with every email captured, never delivered) and the live Amelia group (services, employees with their hours, customers, appointments and events written and deleted through Amelia Lite's own controllers, with its slot checks and notifications running and customer mail captured, and rolled back). MySQL does not work: the harness makes every table TEMPORARY and MySQL cannot reopen one inside a query, which WooCommerce does. Skip the gate for one push with `WPMCP_SKIP_LOCAL_TESTS=1 git push`.

The cache keeps itself small (it once grew to 73 GB in one night with many worktrees running at once). One run goes at a time across all worktrees (`WPMCP_TEST_MAX_PARALLEL`, default 1), because concurrent gates collide on the shared caches; another says it is waiting and waits up to an hour (`WPMCP_TEST_SLOT_TIMEOUT`, in seconds). A targeted run (`bin/test-local.sh -- --filter Foo`, or any PHPUnit arguments after `--`) takes no slot and never waits behind a gate: its core copy, database and config are its own, and the installs it shares are keyed on their contents and never changed once built. It still passes the free-space check and prunes to the budget below. Installs are pruned least recently used first to at most 6 (`WPMCP_TEST_KEEP_INSTALLS`) and 15 GB (`WPMCP_TEST_MAX_GB`), and any install unused for 6 hours (`WPMCP_TEST_INSTALL_TTL_HOURS`) goes, after every new install and when a run exits, Ctrl-C included; an install a live run is using is never removed. With less than 20 GB free on the cache's volume (`WPMCP_TEST_MIN_FREE_GB`) a run first prunes the cache to the two most recently used installs, and with less than 10 GB (`WPMCP_TEST_HARD_MIN_FREE_GB`) it stops. Nothing is downloaded twice: WordPress core, the WordPress test library and every plugin zip are kept in `~/.cache/wpmcp-tests/downloads` by exact version (see `bin/download-cache.sh`), so rebuilding a pruned install makes no network call. A plugin's latest release is asked of WordPress.org at most once a day (`WPMCP_TEST_LATEST_TTL_HOURS`), and the downloads keep their own budget: unused for 30 days (`WPMCP_TEST_DOWNLOAD_TTL_DAYS`) or beyond 3 GB (`WPMCP_TEST_DOWNLOAD_MAX_GB`), least recently used first.
