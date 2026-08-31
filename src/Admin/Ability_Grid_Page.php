<?php

namespace WPMCP\Admin;

use WPMCP\Connect\Exposure;
use WPMCP\Governance\Governance;
use WPMCP\Governance\Governance_Audit_Log;
use WPMCP\Governance\Opt_In_Gates;
use WPMCP\MCP\Ability;
use WPMCP\MCP\Registrar;
use WPMCP\Plugin;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The per-ability toggle grid (issue #78): the MCP surface this install
 * registers, grouped by domain, with per-ability and bulk per-domain
 * enable/disable.
 *
 * Sources rows from the Registrar — never a hardcoded list — so the grid can
 * never drift from what this install actually runs. Each row shows tier, risk
 * hints, and the effective state WITH the governance layer that decides it
 * (Governance::explain()).
 *
 * Trust rules:
 *  - Every write goes through Governance::set_ability_toggle() /
 *    set_domain_toggle() — the existing narrowing mechanism, no bypass —
 *    and lands in the governance audit log with the acting user.
 *  - The grid shows only what this install would actually register. An
 *    ability this install cannot run has no row at all (issue #161): no lock
 *    state, no upsell copy, and no write path. A row exists to be acted on,
 *    and the only rows an admin can act on here are the ones the Registrar
 *    would accept; anything else could only be shown as unavailable, which is
 *    not a state this screen has.
 *  - Default-off dangerous abilities (exec, db writes, fs writes) CANNOT be
 *    enabled here while their execution opt-in filter is absent: the filter
 *    (see Opt_In_Gates) stays the master gate, and the grid refuses rather
 *    than pretending.
 *
 * Every state-changing action requires manage_options AND a valid nonce,
 * matching Connection_Page.
 */
class Ability_Grid_Page
{
    public const SLUG         = 'wpmcp-abilities';
    public const NONCE_ACTION = 'wpmcp_ability_grid';

    /**
     * Dispatch a posted screen action. Returns null when no action was
     * posted, otherwise a result array for render(); failures come back as
     * ['error' => message] so the screen can show them inline.
     *
     * @param array $post The (unslashed) POST payload.
     */
    public function handle_request(array $post): ?array
    {
        $action = self::str($post['wpmcp_grid_action'] ?? '');
        if ('' === $action) {
            return null;
        }

        if (! current_user_can('manage_options')) {
            return ['error' => __('You are not allowed to manage MCP abilities on this site.', 'wpmcp')];
        }

        if (! wp_verify_nonce(self::str($post['_wpnonce'] ?? ''), self::NONCE_ACTION)) {
            return ['error' => __('Security check failed. Reload the page and try again.', 'wpmcp')];
        }

        switch ($action) {
            case 'toggle_ability':
                return $this->toggle_ability($post);
            case 'toggle_domain':
                return $this->toggle_domain($post);
        }

        return ['error' => __('Unknown action.', 'wpmcp')];
    }

    private function toggle_ability(array $post): array
    {
        $name    = sanitize_text_field(self::str($post['ability'] ?? ''));
        $enabled = '1' === self::str($post['enabled'] ?? '');

        if (! isset($this->declared_by_name()[ $name ])) {
            /* translators: %s: ability name. */
            return ['error' => sprintf(__('Unknown ability "%s".', 'wpmcp'), $name)];
        }

        if ($enabled && ! Opt_In_Gates::is_open($name)) {
            return [
                'error' => sprintf(
                    /* translators: 1: ability name, 2: opt-in filter name. */
                    __('"%1$s" is a default-off dangerous ability. It cannot be enabled from this screen: its execution is gated by the %2$s opt-in filter, which only site code can add. The filter is the master gate.', 'wpmcp'),
                    $name,
                    Opt_In_Gates::filter_for($name)
                ),
            ];
        }

        Governance::set_ability_toggle($name, $enabled);
        $this->audit($name, $enabled);

        return ['action' => 'toggle_ability', 'ability' => $name, 'enabled' => $enabled];
    }

    /**
     * Bulk per-domain toggle. Disable writes the single domain-level toggle
     * (the narrowing layer already covers every ability in the domain).
     * Enable clears the domain-level toggle AND writes an explicit enable
     * per ability — except gate-closed dangerous ones, which are refused
     * exactly like a per-ability enable would be.
     *
     * The per-ability writes cover only the listed members: an ability this
     * install cannot register is never named, enabled, or refused (issue
     * #161). The domain-level write is domain-wide by construction, which is
     * what a domain control means: it clears the domain layer for the whole
     * domain, including members this install does not list. That is
     * deliberate and safe here, because the domain layer only ever narrows —
     * clearing it grants nothing on its own, and every unlisted member is
     * still refused by Registrar::tier_permitted() at registration and again
     * at execution.
     */
    private function toggle_domain(array $post): array
    {
        $domain  = sanitize_text_field(self::str($post['domain'] ?? ''));
        $enabled = '1' === self::str($post['enabled'] ?? '');

        $members = array_filter(
            $this->declared_by_name(),
            static fn (Ability $a): bool => $a->domain === $domain
        );
        if ([] === $members) {
            /* translators: %s: domain name. */
            return ['error' => sprintf(__('Unknown domain "%s".', 'wpmcp'), $domain)];
        }

        Governance::set_domain_toggle($domain, $enabled);
        $this->audit('domain:' . $domain, $enabled);

        $updated = [];
        $refused = [];
        if ($enabled) {
            foreach ($members as $ability) {
                if (! Opt_In_Gates::is_open($ability->name)) {
                    $refused[] = $ability->name;
                    continue;
                }
                Governance::set_ability_toggle($ability->name, true);
                $this->audit($ability->name, true);
                $updated[] = $ability->name;
            }
        }

        return [
            'action'  => 'toggle_domain',
            'domain'  => $domain,
            'enabled' => $enabled,
            'updated' => $updated,
            'refused' => $refused,
        ];
    }

    /**
     * The grid model: domain => rows. Each row carries name, tier,
     * operation, risk hints, the opt-in gate state, and the effective
     * enabled state with the layer that decided it.
     *
     * Sourced from declared_by_name(), so the grid lists only what this
     * install would actually register (issue #161): abilities it cannot run
     * are absent rather than listed in a state nobody can act on.
     *
     * @return array<string, array<int, array>>
     */
    public function rows(): array
    {
        $rows = [];
        foreach ($this->declared_by_name() as $ability) {
            $rows[ $ability->domain ][] = $this->row_for($ability);
        }
        ksort($rows);
        return $rows;
    }

    private function row_for(Ability $a): array
    {
        $explain = Governance::explain($a);
        $gated   = Opt_In_Gates::is_gated($a->name);

        if ($explain['enabled']) {
            $reason = __('enabled', 'wpmcp');
        } else {
            $reason = $this->reason_for_layer($explain['layer']);
        }

        return [
            'name'        => $a->name,
            'domain'      => $a->domain,
            'operation'   => $a->operation,
            'tier'        => $a->tier,
            'destructive' => $a->destructive_hint,
            'dangerous'   => $gated,
            'gate_filter' => Opt_In_Gates::filter_for($a->name),
            'gate_open'   => $gated ? Opt_In_Gates::is_open($a->name) : true,
            'enabled'     => $explain['enabled'],
            'reason'      => $reason,
        ];
    }

    private function reason_for_layer(?string $layer): string
    {
        if ('ability_filter' === $layer && ! Exposure::is_enabled()) {
            return __('disabled: master switch (Connection screen)', 'wpmcp');
        }

        $reasons = [
            'ability_toggle'   => __('disabled: governance ability toggle', 'wpmcp'),
            'ability_filter'   => __('disabled: wpmcp_ability_enabled filter', 'wpmcp'),
            'domain_toggle'    => __('disabled: governance domain toggle', 'wpmcp'),
            'domain_filter'    => __('disabled: wpmcp_domain_enabled filter', 'wpmcp'),
            'operation_toggle' => __('disabled: governance operation toggle', 'wpmcp'),
            'operation_filter' => __('disabled: wpmcp_operation_enabled filter', 'wpmcp'),
        ];

        return $reasons[ $layer ] ?? __('disabled', 'wpmcp');
    }

    /**
     * Record a grid change to the governance audit log with the acting
     * WordPress user, reusing the log's existing entry shape: the toggled
     * subject in the ability column ('domain:x' for bulk domain changes),
     * 'user:{login}' as the identity, and the NEW enabled state as allowed.
     */
    private function audit(string $subject, bool $enabled): void
    {
        $user  = wp_get_current_user();
        $actor = $user instanceof \WP_User && $user->exists() ? $user->user_login : 'unknown';
        Governance_Audit_Log::record($subject, 'user:' . $actor, $enabled);
    }

    /**
     * Whether this install would register the ability at all.
     *
     * This mirrors ONLY Registrar::register()'s tier gate, by calling the
     * same predicate rather than re-stating it. register() also drops
     * governance-disabled abilities, and that gate is deliberately NOT
     * mirrored here: showing a governance-disabled ability together with the
     * layer that disabled it, so an admin can re-enable it, is the entire
     * point of this screen. Restoring "parity" by adding the governance test
     * would empty the grid of everything worth acting on.
     *
     * Applied in declared_by_name(), which both rows() and the write paths
     * read, so the read model and the write model cannot drift: an ability
     * with no row must also have no write path.
     */
    private function is_available(Ability $a): bool
    {
        return Registrar::tier_permitted($a->tier);
    }

    /**
     * The abilities this screen governs, keyed by name: the declared surface
     * narrowed to what this install can actually register. Everything else is
     * absent rather than shown locked (issue #161), for reads and writes
     * alike, so a hand-crafted POST cannot toggle a row nobody can see.
     *
     * @return array<string, Ability>
     */
    private function declared_by_name(): array
    {
        $map = [];
        foreach (Plugin::instance()->declared_abilities() as $ability) {
            if (! $this->is_available($ability)) {
                continue;
            }
            $map[ $ability->name ] = $ability;
        }
        return $map;
    }

    /**
     * Request values may arrive as arrays (?_wpnonce[]=x); treat anything
     * that is not a plain string as absent instead of letting a (string)
     * cast raise an array-to-string warning.
     *
     * @param mixed $value
     */
    private static function str($value): string
    {
        return is_string($value) ? $value : '';
    }

    public function render(): void
    {
        // phpcs:ignore -- nonce + capability are verified inside handle_request().
        $result = $this->handle_request(wp_unslash($_POST));
        $nonce  = wp_create_nonce(self::NONCE_ACTION);
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('wpmcp: Abilities', 'wpmcp'); ?></h1>
            <p>
                <?php echo esc_html__('Every ability the plugin declares, grouped by domain. Toggles write governance state (a narrowing layer: they can disable, or clear a disable, never bypass another layer), and every change is recorded in the governance audit log.', 'wpmcp'); ?>
            </p>

            <?php if (isset($result['error'])) : ?>
                <div class="notice notice-error"><p><?php echo esc_html($result['error']); ?></p></div>
            <?php elseif (isset($result['action']) && 'toggle_ability' === $result['action']) : ?>
                <div class="notice notice-success"><p>
                    <?php
                    printf(
                        /* translators: 1: ability name, 2: enabled/disabled. */
                        esc_html__('%1$s is now %2$s.', 'wpmcp'),
                        '<code>' . esc_html($result['ability']) . '</code>',
                        $result['enabled'] ? esc_html__('enabled', 'wpmcp') : esc_html__('disabled', 'wpmcp')
                    );
                    ?>
                </p></div>
            <?php elseif (isset($result['action']) && 'toggle_domain' === $result['action']) : ?>
                <div class="notice notice-success"><p>
                    <?php
                    printf(
                        /* translators: 1: domain name, 2: enabled/disabled. */
                        esc_html__('Domain %1$s is now %2$s.', 'wpmcp'),
                        '<code>' . esc_html($result['domain']) . '</code>',
                        $result['enabled'] ? esc_html__('enabled', 'wpmcp') : esc_html__('disabled', 'wpmcp')
                    );
                    ?>
                </p></div>
                <?php if (! empty($result['refused'])) : ?>
                    <div class="notice notice-warning"><p>
                        <?php echo esc_html__('Not enabled (execution opt-in filter absent):', 'wpmcp'); ?>
                        <code><?php echo esc_html(implode(', ', $result['refused'])); ?></code>
                    </p></div>
                <?php endif; ?>
            <?php endif; ?>

            <?php foreach ($this->rows() as $domain => $rows) : ?>
                <h2 style="margin-top: 1.5em;">
                    <code><?php echo esc_html($domain); ?></code>
                    <span style="font-weight: normal; font-size: 13px;">
                        (<?php echo (int) count($rows); ?>)
                    </span>
                </h2>
                <form method="post" style="margin: 0.25em 0 0.5em;">
                    <input type="hidden" name="wpmcp_grid_action" value="toggle_domain">
                    <input type="hidden" name="_wpnonce" value="<?php echo esc_attr($nonce); ?>">
                    <input type="hidden" name="domain" value="<?php echo esc_attr($domain); ?>">
                    <button type="submit" name="enabled" value="1" class="button button-small">
                        <?php echo esc_html__('Enable all', 'wpmcp'); ?>
                    </button>
                    <button type="submit" name="enabled" value="0" class="button button-small">
                        <?php echo esc_html__('Disable all', 'wpmcp'); ?>
                    </button>
                </form>
                <table class="widefat striped">
                    <thead><tr>
                        <th><?php echo esc_html__('Ability', 'wpmcp'); ?></th>
                        <th><?php echo esc_html__('Tier', 'wpmcp'); ?></th>
                        <th><?php echo esc_html__('Operation', 'wpmcp'); ?></th>
                        <th><?php echo esc_html__('Risk', 'wpmcp'); ?></th>
                        <th><?php echo esc_html__('State', 'wpmcp'); ?></th>
                        <th></th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($rows as $row) : ?>
                        <tr>
                            <td><code><?php echo esc_html($row['name']); ?></code></td>
                            <td>
                                <?php if ('pro' === $row['tier']) : ?>
                                    <strong><?php echo esc_html__('PRO', 'wpmcp'); ?></strong>
                                <?php else : ?>
                                    <?php echo esc_html__('free', 'wpmcp'); ?>
                                <?php endif; ?>
                            </td>
                            <td><?php echo esc_html($row['operation']); ?></td>
                            <td>
                                <?php if ($row['dangerous']) : ?>
                                    <span style="color: #b32d2e; font-weight: 600;">
                                        <span class="dashicons dashicons-warning" aria-hidden="true"></span>
                                        <?php echo esc_html__('default-off', 'wpmcp'); ?>
                                    </span>
                                    <br>
                                    <span class="description">
                                        <?php
                                        printf(
                                            /* translators: %s: opt-in filter name. */
                                            esc_html__('gated by %s', 'wpmcp'),
                                            '<code>' . esc_html($row['gate_filter']) . '</code>'
                                        );
                                        ?>
                                    </span>
                                <?php elseif ($row['destructive']) : ?>
                                    <?php echo esc_html__('destructive', 'wpmcp'); ?>
                                <?php else : ?>
                                    &mdash;
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($row['enabled']) : ?>
                                    <strong style="color: #00753d;"><?php echo esc_html($row['reason']); ?></strong>
                                <?php else : ?>
                                    <strong style="color: #b32d2e;"><?php echo esc_html($row['reason']); ?></strong>
                                <?php endif; ?>
                                <?php if ($row['dangerous'] && ! $row['gate_open'] && $row['enabled']) : ?>
                                    <br>
                                    <span class="description"><?php echo esc_html__('execution still blocked until the opt-in filter is added', 'wpmcp'); ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <form method="post">
                                    <input type="hidden" name="wpmcp_grid_action" value="toggle_ability">
                                    <input type="hidden" name="_wpnonce" value="<?php echo esc_attr($nonce); ?>">
                                    <input type="hidden" name="ability" value="<?php echo esc_attr($row['name']); ?>">
                                    <?php if ($row['enabled']) : ?>
                                        <button type="submit" name="enabled" value="0" class="button button-small">
                                            <?php echo esc_html__('Disable', 'wpmcp'); ?>
                                        </button>
                                    <?php elseif ($row['dangerous'] && ! $row['gate_open']) : ?>
                                        <button type="button" class="button button-small" disabled
                                            title="<?php echo esc_attr(sprintf(
                                                /* translators: %s: opt-in filter name. */
                                                __('Cannot be enabled here: requires the %s opt-in filter in code.', 'wpmcp'),
                                                $row['gate_filter']
                                            )); ?>">
                                            <?php echo esc_html__('Enable', 'wpmcp'); ?>
                                        </button>
                                    <?php else : ?>
                                        <button type="submit" name="enabled" value="1" class="button button-small button-primary">
                                            <?php echo esc_html__('Enable', 'wpmcp'); ?>
                                        </button>
                                    <?php endif; ?>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endforeach; ?>
        </div>
        <?php
    }
}
