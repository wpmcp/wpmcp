<?php

namespace WPMCP\Admin;

use WPMCP\Connect\Exposure;
use WPMCP\Governance\Governance;
use WPMCP\Governance\Governance_Audit_Log;
use WPMCP\Governance\New_Tools_Policy;
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
 * Sources rows from the Registrar - never a hardcoded list - so the grid can
 * never drift from what this install actually runs. Each row shows tier, risk
 * hints, and the effective state WITH the governance layer that decides it
 * (Governance::explain()).
 *
 * Trust rules:
 *  - Every write goes through Governance::set_ability_toggle() /
 *    set_domain_toggle() - the existing narrowing mechanism, no bypass -
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
 * Every state-changing action requires the management capability AND a valid nonce,
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

        if (! current_user_can(Management_Access::CAPABILITY)) {
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
            case 'toggle_auto_enable_new':
                return $this->toggle_auto_enable_new($post);
        }

        return ['error' => __('Unknown action.', 'wpmcp')];
    }

    /**
     * "Switch on new tools after updates" (issue #853): one undo point and
     * one audit row with the acting user, through New_Tools_Policy::set().
     */
    private function toggle_auto_enable_new(array $post): array
    {
        $enabled = '1' === self::str($post['enabled'] ?? '');
        $user    = wp_get_current_user();
        $actor   = $user instanceof \WP_User && $user->exists() ? $user->user_login : 'unknown';

        $operation_id = New_Tools_Policy::set($enabled, 'ability-grid', 'user:' . $actor);

        return ['action' => 'toggle_auto_enable_new', 'enabled' => $enabled, 'operation_id' => $operation_id];
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
     * per ability - except gate-closed dangerous ones, which are refused
     * exactly like a per-ability enable would be.
     *
     * The per-ability writes cover only the listed members: an ability this
     * install cannot register is never named, enabled, or refused (issue
     * #161). The domain-level write is domain-wide by construction, which is
     * what a domain control means: it clears the domain layer for the whole
     * domain, including members this install does not list. That is
     * deliberate and safe here, because the domain layer only ever narrows -
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
            <h1><?php echo esc_html(Plugin::page_title(_x('Abilities', 'admin menu', 'wpmcp'))); ?></h1>
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
            <?php elseif (isset($result['action']) && 'toggle_auto_enable_new' === $result['action']) : ?>
                <div class="notice notice-success"><p>
                    <?php
                    echo $result['enabled']
                        ? esc_html__('Updates will now switch on the new tools they add, except tools that are never switched on automatically.', 'wpmcp')
                        : esc_html__('New tools that an update adds will now stay off until you switch them on.', 'wpmcp');
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

            <?php $auto_new = New_Tools_Policy::is_on(); ?>
            <form method="post" style="margin: 1em 0;">
                <input type="hidden" name="wpmcp_grid_action" value="toggle_auto_enable_new">
                <input type="hidden" name="_wpnonce" value="<?php echo esc_attr($nonce); ?>">
                <input type="hidden" name="enabled" value="<?php echo $auto_new ? '0' : '1'; ?>">
                <strong><?php echo esc_html__('Switch on new tools after updates:', 'wpmcp'); ?></strong>
                <?php echo $auto_new ? esc_html__('on', 'wpmcp') : esc_html__('off', 'wpmcp'); ?>
                <button type="submit" class="button button-small">
                    <?php echo $auto_new ? esc_html__('Turn off', 'wpmcp') : esc_html__('Turn on', 'wpmcp'); ?>
                </button>
                <br><span class="description">
                    <?php echo esc_html__('When on, tools that a WP MCP update adds arrive switched on. Tools that run code, write files or database rows, or manage users, plugins or themes always arrive off, and a tool you switched off stays off.', 'wpmcp'); ?>
                </span>
            </form>

            <?php
            $grid        = $this->rows();
            $open_domain = $this->acted_domain($result);
            $this->render_filters();
            ?>

            <?php foreach ($grid as $domain => $rows) : ?>
                <?php
                $disabled_count = count(array_filter($rows, static fn (array $r): bool => ! $r['enabled']));
                ?>
                <details class="wpmcp-domain" data-domain="<?php echo esc_attr($domain); ?>"<?php echo $domain === $open_domain ? ' open' : ''; ?> style="margin-top: 1em;">
                    <summary style="cursor: pointer; padding: 0.4em 0; font-size: 1.15em;">
                        <code><?php echo esc_html($domain); ?></code>
                        <span class="wpmcp-domain-counts" style="font-weight: normal; font-size: 13px;">
                            <?php
                            echo esc_html(sprintf(
                                /* translators: %d: number of abilities in the domain. */
                                _n('%d ability', '%d abilities', count($rows), 'wpmcp'),
                                count($rows)
                            ) . ', ' . sprintf(
                                /* translators: %d: number of abilities in the domain that are disabled. */
                                __('%d disabled', 'wpmcp'),
                                $disabled_count
                            ));
                            ?>
                        </span>
                    </summary>
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
                        <tr class="wpmcp-ability-row" data-name="<?php echo esc_attr($row['name']); ?>" data-state="<?php echo $row['enabled'] ? 'enabled' : 'disabled'; ?>" data-risk="<?php echo esc_attr(self::risk_tokens($row)); ?>">
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
                                    -
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
                </details>
            <?php endforeach; ?>
            <?php $this->render_filter_script(); ?>
        </div>
        <?php
    }

    /**
     * The domain the posted action touched, so that section renders open
     * after the page reloads and the admin sees the result in place
     * (issue #880). Null when nothing was posted or the action failed.
     */
    private function acted_domain(?array $result): ?string
    {
        if (null === $result || isset($result['error'])) {
            return null;
        }
        if ('toggle_domain' === ($result['action'] ?? '')) {
            return (string) $result['domain'];
        }
        if ('toggle_ability' === ($result['action'] ?? '')) {
            $ability = $this->declared_by_name()[ $result['ability'] ] ?? null;
            return $ability instanceof Ability ? $ability->domain : null;
        }
        return null;
    }

    /**
     * Space-separated risk tokens the client-side risk filter matches on.
     */
    private static function risk_tokens(array $row): string
    {
        $tokens = [];
        if ($row['destructive']) {
            $tokens[] = 'destructive';
        }
        if ($row['dangerous']) {
            $tokens[] = 'default-off';
        }
        return implode(' ', $tokens);
    }

    /**
     * Search box, state and risk filters, expand/collapse controls and a
     * live region (issue #880). Rendered hidden: the filter script reveals
     * them, so without JavaScript the screen is the plain list of
     * collapsible domains, which still works with the keyboard.
     */
    private function render_filters(): void
    {
        ?>
        <div id="wpmcp-ability-filters" class="wpmcp-ability-filters" hidden style="flex-wrap: wrap; gap: 0.75em 1.25em; align-items: flex-end; margin: 1em 0;">
            <p style="margin: 0;">
                <label for="wpmcp-ability-search" style="display: block; font-weight: 600;"><?php echo esc_html__('Search abilities', 'wpmcp'); ?></label>
                <input type="search" id="wpmcp-ability-search" class="regular-text" autocomplete="off" aria-describedby="wpmcp-ability-status" placeholder="<?php echo esc_attr__('Part of an ability name', 'wpmcp'); ?>">
            </p>
            <p style="margin: 0;">
                <label for="wpmcp-ability-state" style="display: block; font-weight: 600;"><?php echo esc_html__('State', 'wpmcp'); ?></label>
                <select id="wpmcp-ability-state">
                    <option value=""><?php echo esc_html__('All states', 'wpmcp'); ?></option>
                    <option value="enabled"><?php echo esc_html__('Enabled', 'wpmcp'); ?></option>
                    <option value="disabled"><?php echo esc_html__('Disabled', 'wpmcp'); ?></option>
                </select>
            </p>
            <p style="margin: 0;">
                <label for="wpmcp-ability-risk" style="display: block; font-weight: 600;"><?php echo esc_html__('Risk', 'wpmcp'); ?></label>
                <select id="wpmcp-ability-risk">
                    <option value=""><?php echo esc_html__('Any risk', 'wpmcp'); ?></option>
                    <option value="destructive"><?php echo esc_html__('Destructive', 'wpmcp'); ?></option>
                    <option value="default-off"><?php echo esc_html__('Default-off', 'wpmcp'); ?></option>
                </select>
            </p>
            <p style="margin: 0;">
                <button type="button" class="button" id="wpmcp-ability-expand"><?php echo esc_html__('Expand all', 'wpmcp'); ?></button>
                <button type="button" class="button" id="wpmcp-ability-collapse"><?php echo esc_html__('Collapse all', 'wpmcp'); ?></button>
            </p>
            <p id="wpmcp-ability-status" role="status" aria-live="polite" style="margin: 0; flex-basis: 100%;"></p>
        </div>
        <?php
    }

    /**
     * Vanilla, dependency-free filter (issue #880). It only shows and hides
     * rows and sections: no request, no state written, so the toggles and
     * the governance audit log behave exactly as before.
     */
    private function render_filter_script(): void
    {
        $i18n = [
            /* translators: %d: number of matching abilities. */
            'matches' => __('%d abilities match.', 'wpmcp'),
            'none'    => __('No abilities match.', 'wpmcp'),
        ];
        ?>
        <script>
        (function () {
            var box = document.getElementById('wpmcp-ability-filters');
            if (! box) {
                return;
            }
            var i18n = <?php echo wp_json_encode($i18n); ?>;
            var search = document.getElementById('wpmcp-ability-search');
            var state = document.getElementById('wpmcp-ability-state');
            var risk = document.getElementById('wpmcp-ability-risk');
            var status = document.getElementById('wpmcp-ability-status');
            var sections = Array.prototype.slice.call(document.querySelectorAll('details.wpmcp-domain'));
            var timer = null;
            box.hidden = false;
            box.style.display = 'flex';

            function apply() {
                var q = search.value.trim().toLowerCase();
                var s = state.value;
                var r = risk.value;
                var active = '' !== q || '' !== s || '' !== r;
                var total = 0;
                sections.forEach(function (section) {
                    var shown = 0;
                    section.querySelectorAll('tr.wpmcp-ability-row').forEach(function (row) {
                        var ok = (! q || -1 !== row.dataset.name.toLowerCase().indexOf(q))
                            && (! s || row.dataset.state === s)
                            && (! r || -1 !== (' ' + row.dataset.risk + ' ').indexOf(' ' + r + ' '));
                        row.hidden = ! ok;
                        if (ok) {
                            shown++;
                        }
                    });
                    total += shown;
                    section.hidden = active && 0 === shown;
                    if (active && shown > 0 && ! section.open) {
                        section.open = true;
                        section.dataset.filterOpened = '1';
                    } else if (! active && '1' === section.dataset.filterOpened) {
                        section.open = false;
                        delete section.dataset.filterOpened;
                    }
                });
                status.textContent = ! active ? '' : (0 === total ? i18n.none : i18n.matches.replace('%d', total));
            }

            search.addEventListener('input', function () {
                clearTimeout(timer);
                timer = setTimeout(apply, 150);
            });
            state.addEventListener('change', apply);
            risk.addEventListener('change', apply);
            document.getElementById('wpmcp-ability-expand').addEventListener('click', function () {
                sections.forEach(function (section) {
                    if (! section.hidden) {
                        section.open = true;
                    }
                });
            });
            document.getElementById('wpmcp-ability-collapse').addEventListener('click', function () {
                sections.forEach(function (section) {
                    section.open = false;
                    delete section.dataset.filterOpened;
                });
            });
        }());
        </script>
        <?php
    }
}
