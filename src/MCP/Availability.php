<?php

namespace WPMCP\MCP;

use WPMCP\Auth\Client_Access;
use WPMCP\Governance\Governance;
use WPMCP\Governance\Opt_In_Gates;
use WPMCP\Identity\Identity_Context;
use WPMCP\Identity\Identity_Store;
use WPMCP\Identity\Ip_Allowlist;
use WPMCP\Memory\Memory_Guard;
use WPMCP\Tools\Content\Content_Guard;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The one walk that says why an ability cannot run (issue #627).
 *
 * explain() checks the requirements in a fixed order and returns the first
 * Unavailable, or null when nothing stands in the way. Site-level reasons
 * come before user-level ones, so a reply never reveals per-user detail when
 * the site itself lacks something:
 *
 *  environment, license, plugin, multisite, admin or code disable, opt-in and
 *  constants, OAuth scope, identity (address, then scope), capability, input.
 *
 * The rate limit is not probed here: Rate_Limiter::check() spends budget, so
 * the throttled refusal attaches its own Unavailable at call time.
 *
 * It decides nothing: the Registrar's gates still allow or deny every call.
 * It explains a denial they made, so the refusal can carry a reason code.
 */
final class Availability
{
    /** The order of the walk, for tests and docs. */
    public const ORDER = [ 'environment', 'license', 'plugin', 'multisite', 'disabled', 'opt_in', 'oauth_scope', 'identity', 'capability', 'input' ];

    /** @var array<string, string> Test seam: plugin slug => active|inactive|missing. */
    private static array $plugin_state_for_tests = [];

    public static function set_plugin_state_for_tests(string $slug, ?string $state): void
    {
        if (! defined('WPMCP_TESTING') || ! WPMCP_TESTING) {
            return;
        }
        if (null === $state) {
            unset(self::$plugin_state_for_tests[ $slug ]);
            return;
        }
        self::$plugin_state_for_tests[ $slug ] = $state;
    }

    /**
     * @param array{input?: array<string, mixed>} $context
     */
    public static function explain(Ability $a, array $context = []): ?Unavailable
    {
        $input = is_array($context['input'] ?? null) ? $context['input'] : [];

        return self::environment($a)
            ?? self::license($a)
            ?? self::plugin($a)
            ?? self::multisite($a)
            ?? self::disabled($a)
            ?? self::opt_in($a)
            ?? self::oauth_scope($a)
            ?? self::explain_identity($a)
            ?? self::capability($a, $input)
            ?? self::input($a, $input);
    }

    /** requires['php'] (a minimum version) and requires['extension'] (names). */
    private static function environment(Ability $a): ?Unavailable
    {
        $php = $a->requires['php'] ?? null;
        if (is_string($php) && '' !== $php && version_compare(PHP_VERSION, $php, '<')) {
            return new Unavailable(
                'environment',
                sprintf('%s needs PHP %s or newer, and this site runs PHP %s.', $a->name, $php, PHP_VERSION),
                'Ask the host to upgrade PHP on this site.'
            );
        }
        foreach ((array) ($a->requires['extension'] ?? []) as $extension) {
            if (is_string($extension) && ! extension_loaded($extension)) {
                return new Unavailable(
                    'environment',
                    sprintf('%s needs the PHP extension "%s", which this server does not load.', $a->name, $extension),
                    'Ask the host to enable that PHP extension.'
                );
            }
        }
        return null;
    }

    private static function license(Ability $a): ?Unavailable
    {
        $tier = (string) ($a->requires['tier'] ?? $a->tier);
        if (Registrar::tier_permitted($tier)) {
            return null;
        }
        return new Unavailable(
            'pro_required',
            sprintf('"%s" is not included in the edition of WP MCP this site runs.', $a->name),
            'The account owner switches this site to an edition of WP MCP that includes it.',
            [ 'admin_url' => admin_url('admin.php?page=wpmcp') ]
        );
    }

    /**
     * requires['plugin'] (slug => name, every one needed), then the plugin
     * the ability's domain depends on (Registrar::PLUGIN_DOMAINS).
     */
    private static function plugin(Ability $a): ?Unavailable
    {
        $plugins_url = admin_url('plugins.php');
        foreach ((array) ($a->requires['plugin'] ?? []) as $slug => $name) {
            $slug  = (string) $slug;
            $name  = (string) $name;
            $state = self::plugin_state($slug);
            if ('active' === $state) {
                continue;
            }
            $reason = 'inactive' === $state ? 'plugin_inactive' : 'plugin_missing';
            return new Unavailable(
                $reason,
                sprintf('%1$s is not active on this site, so %2$s cannot run.', $name, $a->name),
                'inactive' === $state
                    ? sprintf('A site administrator activates %s, then the MCP client reconnects.', $name)
                    : sprintf('A site administrator installs and activates %s, then the MCP client reconnects.', $name),
                [ 'admin_url' => $plugins_url ]
            );
        }

        $missing = Registrar::domain_plugin_missing($a);
        if (null !== $missing) {
            return new Unavailable(
                'plugin_missing',
                $missing->get_error_message(),
                '',
                [ 'admin_url' => $plugins_url ]
            );
        }
        return null;
    }

    /**
     * Whether a plugin is active, installed but inactive, or missing, by the
     * directory slug (or single-file slug) it installs under.
     */
    public static function plugin_state(string $slug): string
    {
        if (isset(self::$plugin_state_for_tests[ $slug ])) {
            return self::$plugin_state_for_tests[ $slug ];
        }
        $active = (array) get_option('active_plugins', []);
        if (is_multisite()) {
            $active = array_merge($active, array_keys((array) get_site_option('active_sitewide_plugins', [])));
        }
        foreach ($active as $file) {
            $file = (string) $file;
            if (0 === strpos($file, $slug . '/') || $slug . '.php' === $file) {
                return 'active';
            }
        }
        $dir = defined('WP_PLUGIN_DIR') ? WP_PLUGIN_DIR : '';
        if ('' !== $dir && ( is_dir($dir . '/' . $slug) || is_file($dir . '/' . $slug . '.php') )) {
            return 'inactive';
        }
        return 'missing';
    }

    /** requires['multisite']: truthy needs a network install. */
    private static function multisite(Ability $a): ?Unavailable
    {
        if (empty($a->requires['multisite']) || is_multisite()) {
            return null;
        }
        return new Unavailable(
            'multisite_required',
            sprintf('%s works on a WordPress multisite network, and this site is a single site.', $a->name),
            'Use it on a multisite network install.'
        );
    }

    private static function disabled(Ability $a): ?Unavailable
    {
        $layer = Governance::explain($a)['layer'];
        if (null === $layer) {
            return null;
        }
        [ $dimension, $kind ] = explode('_', $layer, 2);
        $key                  = 'ability' === $dimension ? $a->name : (string) $a->{$dimension};
        if ('toggle' === $kind) {
            return new Unavailable(
                'disabled_by_admin',
                sprintf('"%s" is disabled by governance: the %s toggle for "%s" is off.', $a->name, $dimension, $key),
                'A site administrator switches it back on under WP MCP > Abilities in wp-admin.',
                [ 'admin_url' => admin_url('admin.php?page=wpmcp-abilities') ]
            );
        }
        return new Unavailable(
            'disabled_by_code',
            sprintf('"%s" is disabled by governance: the wpmcp_%s_enabled filter on this site switches off "%s".', $a->name, $dimension, $key),
            'A site developer changes that filter in the site code.'
        );
    }

    /** The default-off opt-in gate, requires['opt_in'], then requires['constant']. */
    private static function opt_in(Ability $a): ?Unavailable
    {
        $filter = Opt_In_Gates::filter_for($a->name);
        $closed = null !== $filter && ! Opt_In_Gates::is_open($a->name);
        if (null === $filter && is_string($a->requires['opt_in'] ?? null) && '' !== $a->requires['opt_in']) {
            $filter = $a->requires['opt_in'];
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- the ability's own declared wpmcp_* opt-in filter.
            $closed = ! (bool) apply_filters($filter, false);
        }
        if ($closed) {
            return new Unavailable(
                'opt_in_required',
                sprintf('%s is off by default, and this site has not opted in (%s).', $a->name, $filter),
                sprintf('A site administrator switches it on under WP MCP > Opt-in operations, or a developer adds add_filter(\'%s\', \'__return_true\');.', $filter),
                [ 'admin_url' => admin_url('admin.php?page=wpmcp-opt-in') ]
            );
        }

        $constant = $a->requires['constant'] ?? null;
        if (is_string($constant) && '' !== $constant && defined($constant) && constant($constant)) {
            return new Unavailable(
                'wp_constant',
                sprintf('%s cannot run while %s is set on this site.', $a->name, $constant),
                sprintf('A site developer removes %s from wp-config.php, if that is safe for this site.', $constant)
            );
        }
        return null;
    }

    private static function oauth_scope(Ability $a): ?Unavailable
    {
        $denial = Client_Access::denial($a);
        if (null === $denial) {
            return null;
        }
        return new Unavailable(
            'scope_read_only',
            $denial->get_error_message(),
            sprintf('Reconnect the MCP client and grant the "%s" scope.', Client_Access::SCOPE_FULL)
        );
    }

    /**
     * The identity this connection acts as: unknown, pinned to other
     * addresses, or not covering $a. Null when no identity is active or it
     * allows $a.
     */
    public static function explain_identity(Ability $a): ?Unavailable
    {
        $current = Identity_Context::current();
        if (null === $current) {
            return null;
        }
        $connection = admin_url('admin.php?page=wpmcp-connection');
        $identity   = Identity_Store::get($current);
        if (null === $identity) {
            return new Unavailable(
                'identity_scope',
                'This connection acts as a WP MCP identity that does not exist on this site (it was deleted or renamed), so no tool can run.',
                'A site administrator connects the client again with an existing identity under WP MCP > Connection.',
                [ 'admin_url' => $connection ]
            );
        }
        if (Ip_Allowlist::refuses($identity)) {
            return self::identity_refused();
        }
        if (! Governance::is_within_identity_scope($a)) {
            return new Unavailable(
                'identity_scope',
                sprintf('"%s" is outside the scope of the WP MCP identity this connection acts as.', $a->name),
                'A site administrator widens that identity or connects with another one under WP MCP > Connection.',
                [ 'admin_url' => $connection ]
            );
        }
        return null;
    }

    /**
     * A pinned identity used from a client address it does not allow
     * (issue #416). The client is not told that the address is the reason:
     * #416 keeps the rule unreadable to whoever holds the credential, so
     * this reads as an identity refusal and points the human at the audit
     * log, where the refused address is recorded. Telling the client the
     * address rule applies is an owner call (noted on issue #627).
     */
    public static function identity_refused(): Unavailable
    {
        return new Unavailable(
            'identity_scope',
            'The WP MCP identity this connection acts as refused this request.',
            'A site administrator can see which rule refused it in the WP MCP governance audit log, and change the identity under WP MCP > Connection.',
            [ 'admin_url' => admin_url('admin.php?page=wpmcp-audit-log'), 'hidden' => true ]
        );
    }

    /**
     * @param array<string, mixed> $input
     */
    private static function capability(Ability $a, array $input): ?Unavailable
    {
        $capability = $a->capability;
        if (null !== $a->op_capability && is_string($input['operation'] ?? null)) {
            $op_cap = ($a->op_capability)($input);
            if (null !== $op_cap && ! current_user_can($op_cap)) {
                $capability = $op_cap;
            }
        }
        if (! current_user_can($capability)) {
            return new Unavailable(
                'capability_missing',
                sprintf('%s requires the "%s" capability.', $a->name, $capability),
                'Ask a site administrator for a role that has it.',
                [ 'admin_url' => admin_url('users.php') ]
            );
        }
        if ('network' === ($a->requires['multisite'] ?? null) && ! is_super_admin()) {
            return new Unavailable(
                'network_admin_required',
                sprintf('%s changes the whole network, so it needs a network administrator.', $a->name),
                'Ask a network administrator to run it.'
            );
        }
        return null;
    }

    /**
     * @param array<string, mixed> $input
     */
    private static function input(Ability $a, array $input): ?Unavailable
    {
        $denied = Content_Guard::input_denial($a, $input);
        if (null === $denied && null !== $a->objects) {
            $denied = Object_Guard::denial((array) ($a->objects)($input));
        }
        if (null !== $denied) {
            return new Unavailable(
                'object_denied',
                sprintf('The caller may not reach an object this %s call names (%s).', $a->name, $denied),
                'Try another object, or ask a site administrator for access to this one.'
            );
        }

        $rule = Memory_Guard::blocking_rule($a, $input);
        if (null !== $rule) {
            return self::memory_blocked($a, (int) $rule['id']);
        }
        return null;
    }

    /** A published severity=block memory entry stops this call. */
    public static function memory_blocked(Ability $a, int $entry_id): Unavailable
    {
        return new Unavailable(
            'memory_blocked',
            sprintf('A published project memory rule (entry %1$d) blocks %2$s for this input. Read the rule with memory-recall.', $entry_id, $a->name),
            'Change the input so the rule no longer matches, or ask a site administrator to review that memory entry in wp-admin.',
            [ 'admin_url' => admin_url('edit.php?post_type=wpmcp_memory') ]
        );
    }

    /** The throttled call's contract, for the refusal the rate limiter returns. */
    public static function rate_limited(Ability $a, int $retry_after): Unavailable
    {
        return new Unavailable(
            'rate_limited',
            sprintf('Rate limit exceeded for "%s".', $a->name),
            sprintf('Wait %d second(s), then retry.', $retry_after),
            [ 'retry_after' => $retry_after ]
        );
    }
}
