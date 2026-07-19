<?php

namespace WPMCP\Admin;

use WPMCP\Connect\Bundle_Builder;
use WPMCP\Connect\Client_Config_Generator;
use WPMCP\Connect\Connection_Tester;
use WPMCP\Connect\Exposure;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The Connection screen (issue #76): the first-ten-minutes experience.
 * Shows this site's MCP endpoint, provisions a core Application Password
 * for a chosen user (with least-privilege guidance), renders filled client
 * configs, runs the server-side self-test, flips the master exposure
 * switch, serves the secret-free desktop bundle, and revokes passwords it
 * issued.
 *
 * Credential handling contract:
 *  - The plaintext Application Password exists only in the provision
 *    request's response array, rendered once. It is never stored, logged,
 *    audited, or echoed on any later render.
 *  - The persisted ledger (self::OPTION) holds only user_id, uuid, name,
 *    and a timestamp — enough to list and revoke, never enough to connect.
 *  - Revocation calls the core WP_Application_Passwords API, so the hashed
 *    credential is deleted and the connected client is cut off immediately.
 *
 * Every state-changing action requires manage_options AND a valid nonce;
 * provisioning additionally requires edit_user on the target user and
 * defers to core's wp_is_application_passwords_available_for_user() check
 * rather than bypassing it.
 */
class Connection_Page
{
    public const SLUG         = 'wpmcp-connection';
    public const NONCE_ACTION = 'wpmcp_connection';
    public const OPTION       = 'wpmcp_connection_passwords';

    /**
     * Dispatch a posted screen action. Returns null when no action was
     * posted, otherwise a result array for render(); failures come back as
     * ['error' => message] so the screen can show them inline.
     *
     * @param array $post The (unslashed) POST payload.
     */
    public function handle_request(array $post): ?array
    {
        $action = self::str($post['wpmcp_connection_action'] ?? '');
        if ('' === $action) {
            return null;
        }

        if (! current_user_can('manage_options')) {
            return ['error' => __('You are not allowed to manage MCP connections on this site.', 'wpmcp')];
        }

        if (! wp_verify_nonce(self::str($post['_wpnonce'] ?? ''), self::NONCE_ACTION)) {
            return ['error' => __('Security check failed. Reload the page and try again.', 'wpmcp')];
        }

        switch ($action) {
            case 'provision':
                return $this->provision($post);
            case 'revoke':
                return $this->revoke($post);
            case 'toggle':
                Exposure::set_enabled('1' === self::str($post['enabled'] ?? ''));
                return ['action' => 'toggle', 'enabled' => Exposure::is_enabled()];
            case 'self_test':
                return ['action' => 'self_test', 'self_test' => (new Connection_Tester())->test()];
        }

        return ['error' => __('Unknown action.', 'wpmcp')];
    }

    private function provision(array $post): array
    {
        $user_id = (int) ($post['user_id'] ?? 0);
        $user    = get_userdata($user_id);
        if (! $user) {
            return ['error' => __('That user does not exist.', 'wpmcp')];
        }

        if (! current_user_can('edit_user', $user_id)) {
            return ['error' => __('You are not allowed to create credentials for that user.', 'wpmcp')];
        }

        if (! wp_is_application_passwords_available_for_user($user)) {
            return ['error' => __('Application Passwords are not available for that user on this site. They require HTTPS (or a local environment) and must not be disabled by another plugin.', 'wpmcp')];
        }

        $name = sanitize_text_field(self::str($post['name'] ?? ''));
        if ('' === $name) {
            $name = sprintf('wpmcp (%s)', gmdate('Y-m-d H:i'));
        }

        $created = \WP_Application_Passwords::create_new_application_password($user_id, ['name' => $name]);
        if (is_wp_error($created)) {
            return ['error' => $created->get_error_message()];
        }

        [$password, $item] = $created;

        $records   = $this->records();
        $records[] = [
            'user_id' => $user_id,
            'uuid'    => (string) $item['uuid'],
            'name'    => (string) $item['name'],
            'created' => time(),
        ];
        update_option(self::OPTION, $records, false);

        return [
            'action'      => 'provision',
            'user_login'  => $user->user_login,
            'name'        => (string) $item['name'],
            'uuid'        => (string) $item['uuid'],
            'password'    => $password,
            'auth_header' => Client_Config_Generator::auth_header($user->user_login, $password),
            'configs'     => (new Client_Config_Generator())->configs($user->user_login, $password),
        ];
    }

    private function revoke(array $post): array
    {
        $user_id = (int) ($post['user_id'] ?? 0);
        $uuid    = sanitize_text_field(self::str($post['uuid'] ?? ''));

        $deleted = \WP_Application_Passwords::delete_application_password($user_id, $uuid);
        if (is_wp_error($deleted)) {
            return ['error' => $deleted->get_error_message()];
        }

        $records = array_values(array_filter(
            $this->records(),
            static fn (array $record): bool => $record['uuid'] !== $uuid || (int) $record['user_id'] !== $user_id
        ));
        update_option(self::OPTION, $records, false);

        return ['action' => 'revoke', 'uuid' => $uuid];
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

    /** @return array<int, array{user_id: int, uuid: string, name: string, created: int}> */
    private function records(): array
    {
        $records = get_option(self::OPTION, []);
        return is_array($records) ? $records : [];
    }

    /**
     * admin_post handler for the desktop bundle download. GET + nonce (the
     * download link is nonce'd) + manage_options. The bundle is secret-free
     * (see Bundle_Builder), so serving it discloses only the endpoint URL —
     * the same fact this screen already shows.
     *
     * @param callable|null $sender Test seam: receives the built bundle path
     *                              instead of streaming it and exiting.
     * @return \WP_Error|null WP_Error on refusal when a $sender is injected;
     *                        otherwise streams and exits.
     */
    public function download_bundle(?callable $sender = null)
    {
        // phpcs:ignore -- this IS the nonce verification for the download.
        $authorized = current_user_can('manage_options')
            && wp_verify_nonce(self::str(wp_unslash($_GET['_wpnonce'] ?? '')), self::NONCE_ACTION);

        if (! $authorized) {
            if (null !== $sender) {
                return new \WP_Error('wpmcp_forbidden', __('You are not allowed to download the connection bundle.', 'wpmcp'));
            }
            wp_die(esc_html__('You are not allowed to download the connection bundle.', 'wpmcp'), 403);
        }

        $path = (new Bundle_Builder())->build(Client_Config_Generator::endpoint());

        if (null !== $sender) {
            $sender($path);
            return null;
        }

        nocache_headers();
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="wpmcp.mcpb"');
        header('Content-Length: ' . (string) filesize($path));
        readfile($path);
        unlink($path);
        exit;
    }

    public function render(): void
    {
        // phpcs:ignore -- nonce + capability are verified inside handle_request().
        $result   = $this->handle_request(wp_unslash($_POST));
        $endpoint = Client_Config_Generator::endpoint();
        $nonce    = wp_create_nonce(self::NONCE_ACTION);
        $exposed  = Exposure::is_enabled();
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('wpmcp: Connection', 'wpmcp'); ?></h1>

            <?php if (isset($result['error'])) : ?>
                <div class="notice notice-error"><p><?php echo esc_html($result['error']); ?></p></div>
            <?php elseif (isset($result['action']) && 'revoke' === $result['action']) : ?>
                <div class="notice notice-success"><p><?php echo esc_html__('Application password revoked. The client it was issued to is disconnected.', 'wpmcp'); ?></p></div>
            <?php endif; ?>

            <h2><?php echo esc_html__('1. MCP surface', 'wpmcp'); ?></h2>
            <p>
                <?php echo esc_html__('Endpoint:', 'wpmcp'); ?>
                <code><?php echo esc_html($endpoint); ?></code>
                <?php if ($exposed) : ?>
                    — <strong><?php echo esc_html__('exposed', 'wpmcp'); ?></strong>
                <?php else : ?>
                    — <strong><?php echo esc_html__('disabled by the master switch', 'wpmcp'); ?></strong>
                <?php endif; ?>
            </p>
            <form method="post">
                <input type="hidden" name="wpmcp_connection_action" value="toggle">
                <input type="hidden" name="_wpnonce" value="<?php echo esc_attr($nonce); ?>">
                <input type="hidden" name="enabled" value="<?php echo $exposed ? '0' : '1'; ?>">
                <?php submit_button(
                    $exposed ? __('Turn MCP off', 'wpmcp') : __('Turn MCP on', 'wpmcp'),
                    $exposed ? 'secondary' : 'primary',
                    'submit',
                    false
                ); ?>
                <span class="description">
                    <?php echo esc_html__('The master switch narrows through governance: off means every ability denies instantly, for every client and credential. Its state is shown in the admin bar.', 'wpmcp'); ?>
                </span>
            </form>

            <form method="post" style="margin-top: 1em;">
                <input type="hidden" name="wpmcp_connection_action" value="self_test">
                <input type="hidden" name="_wpnonce" value="<?php echo esc_attr($nonce); ?>">
                <?php submit_button(__('Run connection self-test', 'wpmcp'), 'secondary', 'submit', false); ?>
            </form>
            <?php if (isset($result['self_test'])) : ?>
                <div class="notice notice-<?php echo $result['self_test']['ok'] ? 'success' : 'error'; ?>">
                    <p><?php echo esc_html($result['self_test']['message']); ?></p>
                </div>
            <?php endif; ?>

            <h2><?php echo esc_html__('2. Create a connection', 'wpmcp'); ?></h2>
            <p>
                <?php echo esc_html__('This creates a standard WordPress Application Password for the chosen user. Least privilege: prefer a dedicated user with only the role the agent needs — the agent can never do more than that user can, and wpmcp governance and tool capability gates narrow further from there. You can revoke the password below at any time.', 'wpmcp'); ?>
            </p>
            <form method="post">
                <input type="hidden" name="wpmcp_connection_action" value="provision">
                <input type="hidden" name="_wpnonce" value="<?php echo esc_attr($nonce); ?>">
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="wpmcp-connection-user"><?php echo esc_html__('Connect as user', 'wpmcp'); ?></label></th>
                        <td>
                            <?php
                            wp_dropdown_users([
                                'name'     => 'user_id',
                                'id'       => 'wpmcp-connection-user',
                                'selected' => get_current_user_id(),
                            ]);
                            ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="wpmcp-connection-name"><?php echo esc_html__('Credential name', 'wpmcp'); ?></label></th>
                        <td><input type="text" class="regular-text" id="wpmcp-connection-name" name="name" placeholder="<?php echo esc_attr__('e.g. Claude Code on my laptop', 'wpmcp'); ?>"></td>
                    </tr>
                </table>
                <?php submit_button(__('Create application password', 'wpmcp')); ?>
            </form>

            <?php if (isset($result['action']) && 'provision' === $result['action']) : ?>
                <div class="notice notice-success">
                    <p>
                        <strong><?php echo esc_html__('Application password created — shown only once.', 'wpmcp'); ?></strong>
                        <?php echo esc_html__('Copy it (or a filled config below) now; wpmcp does not store it and cannot show it again.', 'wpmcp'); ?>
                    </p>
                    <p>
                        <?php echo esc_html__('User:', 'wpmcp'); ?> <code><?php echo esc_html($result['user_login']); ?></code>
                        &nbsp; <?php echo esc_html__('Password:', 'wpmcp'); ?> <code><?php echo esc_html($result['password']); ?></code>
                    </p>
                </div>

                <h2><?php echo esc_html__('3. Paste into your client', 'wpmcp'); ?></h2>
                <?php foreach ($result['configs'] as $client) : ?>
                    <h3><?php echo esc_html($client['label']); ?> <code><?php echo esc_html($client['config_file']); ?></code></h3>
                    <p class="description"><?php echo esc_html($client['note']); ?></p>
                    <?php if (isset($client['command'])) : ?>
                        <p><code><?php echo esc_html($client['command']); ?></code></p>
                    <?php endif; ?>
                    <textarea class="large-text code" rows="9" readonly><?php echo esc_textarea($client['snippet']); ?></textarea>
                <?php endforeach; ?>

                <h3><?php echo esc_html__('Claude Desktop bundle', 'wpmcp'); ?></h3>
                <p>
                    <a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=wpmcp_download_bundle'), self::NONCE_ACTION)); ?>">
                        <?php echo esc_html__('Download wpmcp.mcpb', 'wpmcp'); ?>
                    </a>
                    <span class="description">
                        <?php echo esc_html__('Double-click to install in Claude Desktop, then enter the username and password above when prompted. The bundle contains no credentials — only this site\'s endpoint and a self-contained proxy.', 'wpmcp'); ?>
                    </span>
                </p>

                <h2><?php echo esc_html__('4. Next steps', 'wpmcp'); ?></h2>
                <ol>
                    <li><?php echo esc_html__('Ask your client to list tools — you should see the wpmcp toolset.', 'wpmcp'); ?></li>
                    <li><?php echo esc_html__('Every write is snapshotted first; review and roll back anything from the wpmcp History screen.', 'wpmcp'); ?></li>
                    <li><?php echo esc_html__('Narrow what agents may do per ability, domain, or operation via governance, and audit every decision on the Audit Log screen.', 'wpmcp'); ?></li>
                </ol>
            <?php endif; ?>

            <h2><?php echo esc_html__('Issued application passwords', 'wpmcp'); ?></h2>
            <?php $records = $this->records(); ?>
            <?php if (! $records) : ?>
                <p><?php echo esc_html__('None issued from this screen yet.', 'wpmcp'); ?></p>
            <?php else : ?>
                <table class="widefat striped">
                    <thead><tr>
                        <th><?php echo esc_html__('Name', 'wpmcp'); ?></th>
                        <th><?php echo esc_html__('User', 'wpmcp'); ?></th>
                        <th><?php echo esc_html__('Created', 'wpmcp'); ?></th>
                        <th></th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($records as $record) : ?>
                        <?php $record_user = get_userdata((int) $record['user_id']); ?>
                        <tr>
                            <td><?php echo esc_html($record['name']); ?></td>
                            <td><?php echo esc_html($record_user ? $record_user->user_login : '#' . (int) $record['user_id']); ?></td>
                            <td><?php echo esc_html(gmdate('Y-m-d H:i', (int) $record['created'])); ?></td>
                            <td>
                                <form method="post">
                                    <input type="hidden" name="wpmcp_connection_action" value="revoke">
                                    <input type="hidden" name="_wpnonce" value="<?php echo esc_attr($nonce); ?>">
                                    <input type="hidden" name="user_id" value="<?php echo (int) $record['user_id']; ?>">
                                    <input type="hidden" name="uuid" value="<?php echo esc_attr($record['uuid']); ?>">
                                    <?php submit_button(__('Revoke', 'wpmcp'), 'delete small', 'submit', false); ?>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }
}
