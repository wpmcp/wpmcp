<?php

namespace WPMCP\Admin;

use WPMCP\Auth\Client_Access;
use WPMCP\Auth\Client_Metadata_Document;
use WPMCP\Auth\Client_Store;
use WPMCP\Auth\OAuth_Config;
use WPMCP\Connect\Bundle_Builder;
use WPMCP\Connect\Client_Config_Generator;
use WPMCP\Connect\Connection_Tester;
use WPMCP\Connect\Exposure;
use WPMCP\Identity\Identity_Store;
use WPMCP\Plugin;

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
 *    and a timestamp - enough to list and revoke, never enough to connect.
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
            case 'oauth_client_approval':
                Client_Metadata_Document::set_requires_approval('1' === self::str($post['require'] ?? ''));
                return ['action' => 'oauth_client_approval'];
            case 'oauth_client_approve':
            case 'oauth_client_deny':
            case 'oauth_client_forget':
                return $this->decide_oauth_client($action, self::str($post['client_id'] ?? ''));
            case 'oauth_access':
                return $this->set_oauth_access($post);
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

    /**
     * Approve, deny or forget an OAuth client that connected with a Client
     * ID Metadata Document (issue #388). Denying also revokes its tokens.
     */
    private function decide_oauth_client(string $action, string $client_id): array
    {
        // Matched exactly against the registry keys, which are the
        // validated client_ids themselves, so no sanitizing rewrite here.
        $done = match ($action) {
            'oauth_client_approve' => Client_Metadata_Document::approve($client_id),
            'oauth_client_deny'    => Client_Metadata_Document::deny($client_id),
            default                => Client_Metadata_Document::forget($client_id),
        };

        if (! $done) {
            return ['error' => __('That OAuth client is not known to this site.', 'wpmcp')];
        }

        return ['action' => $action, 'client_id' => $client_id];
    }

    /**
     * Change the access level of one OAuth connection (issue #454). Applies
     * on the connection's next request; a level above what the client was
     * granted has no effect, because the token's own scope still applies.
     */
    private function set_oauth_access(array $post): array
    {
        $client_id = self::str($post['client_id'] ?? '');
        $user_id   = (int) self::str($post['user_id'] ?? '');
        $parsed    = Client_Access::parse(self::str($post['access'] ?? ''));

        if (null === $parsed || ! Client_Access::set($client_id, $user_id, $parsed[0], $parsed[1])) {
            return ['error' => __('That access level could not be applied.', 'wpmcp')];
        }

        return ['action' => 'oauth_access', 'client_id' => $client_id];
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
     * (see Bundle_Builder), so serving it discloses only the endpoint URL -
     * the same fact this screen already shows.
     *
     * @param callable|null $sender Test seam: receives the built bundle path
     *                              instead of streaming it and exiting.
     * @return \WP_Error|null WP_Error on refusal when a $sender is injected;
     *                        otherwise streams and exits.
     */
    public function download_bundle(?callable $sender = null)
    {
        $nonce      = is_string($_GET['_wpnonce'] ?? null) ? sanitize_text_field(wp_unslash($_GET['_wpnonce'])) : '';
        $authorized = current_user_can('manage_options')
            && '' !== $nonce
            && wp_verify_nonce($nonce, self::NONCE_ACTION);

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

        // readfile() is an error under WordPress.WP.AlternativeFunctions;
        // file_get_contents() is one of the three names the Plugin Check
        // review ruleset excludes from it. The bundle is a few kilobytes of
        // generated JSON, so reading it into memory costs nothing.
        $bundle = (string) file_get_contents($path);
        wp_delete_file($path);

        nocache_headers();
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="wpmcp.mcpb"');
        header('Content-Length: ' . (string) strlen($bundle));
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- binary .mcpb body sent as application/octet-stream; any escaper would corrupt it.
        echo $bundle;
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
            <h1><?php echo esc_html(Plugin::page_title(_x('Connection', 'admin menu', 'wpmcp'))); ?></h1>

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
                    is <strong><?php echo esc_html__('exposed', 'wpmcp'); ?></strong>
                <?php else : ?>
                    is <strong><?php echo esc_html__('disabled by the master switch', 'wpmcp'); ?></strong>
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
                    <?php if (! empty($result['self_test']['checks'])) : ?>
                        <ul style="margin-left: 1.5em; list-style: disc;">
                            <?php foreach ($result['self_test']['checks'] as $check) : ?>
                                <li>
                                    <strong><?php echo esc_html(($check['ok'] ? "\u{2713} " : "\u{2717} ") . $check['label']); ?></strong>
                                    <br><span class="description"><?php echo esc_html($check['detail']); ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <h2><?php echo esc_html__('2. Create a connection', 'wpmcp'); ?></h2>
            <p>
                <?php echo esc_html__('This creates a standard WordPress Application Password for the chosen user. Least privilege: prefer a dedicated user with only the role the agent needs, the agent can never do more than that user can, and wpmcp governance and tool capability gates narrow further from there. You can revoke the password below at any time.', 'wpmcp'); ?>
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
                        <strong><?php echo esc_html__('Application password created, shown only once.', 'wpmcp'); ?></strong>
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
                        <?php echo esc_html__('Double-click to install in Claude Desktop, then enter the username and password above when prompted. The bundle contains no credentials, only this site\'s endpoint and a self-contained proxy.', 'wpmcp'); ?>
                    </span>
                </p>

                <h2><?php echo esc_html__('4. Next steps', 'wpmcp'); ?></h2>
                <ol>
                    <li><?php echo esc_html__('Ask your client to list tools, you should see the wpmcp toolset.', 'wpmcp'); ?></li>
                    <li><?php echo esc_html__('Every write is snapshotted first; review and roll back anything from the wpmcp History screen.', 'wpmcp'); ?></li>
                    <li><?php echo esc_html__('Narrow what agents may do per ability, domain, or operation via governance, and audit every decision on the Audit Log screen.', 'wpmcp'); ?></li>
                </ol>
            <?php endif; ?>

            <?php $this->render_oauth_clients($nonce); ?>

            <?php $this->render_identities(); ?>

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
    /**
     * Scoped identities and the client addresses each is pinned to (issue
     * #416). Read-only: identities are edited with create-identity. Hidden
     * while no identity exists.
     */
    private function render_identities(): void
    {
        $identities = Identity_Store::list();
        if (! $identities) {
            return;
        }
        ?>
        <h2><?php echo esc_html__('Scoped identities', 'wpmcp'); ?></h2>
        <p><?php echo esc_html__('An identity with allowed addresses refuses MCP requests from anywhere else. The client address is REMOTE_ADDR; behind a reverse proxy, list the proxy with the wpmcp_trusted_proxies filter so X-Forwarded-For is used. The admin screens are never restricted.', 'wpmcp'); ?></p>
        <table class="widefat striped">
            <thead><tr>
                <th><?php echo esc_html__('Identity', 'wpmcp'); ?></th>
                <th><?php echo esc_html__('Allowed addresses', 'wpmcp'); ?></th>
            </tr></thead>
            <tbody>
            <?php foreach ($identities as $identity) : ?>
                <?php $ips = array_map('strval', (array) ($identity['allowed_ips'] ?? [])); ?>
                <tr>
                    <td><?php echo esc_html((string) ($identity['name'] ?? '')); ?></td>
                    <td><?php echo $ips ? '<code>' . esc_html(implode(', ', $ips)) . '</code>' : esc_html__('Any address', 'wpmcp'); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    /**
     * OAuth clients that connected with a Client ID Metadata Document
     * (issue #388), and the switch that holds new ones for approval. Only
     * shown while OAuth is on. Each client's redirect hosts are listed, and
     * a client that only redirects to this machine's loopback address is
     * flagged: its document cannot prove who is really running it.
     */
    private function render_oauth_clients(string $nonce): void
    {
        if (! OAuth_Config::is_enabled()) {
            return;
        }

        $required = Client_Metadata_Document::requires_approval();
        $clients  = Client_Metadata_Document::clients();
        $labels   = [
            'approved' => __('Approved', 'wpmcp'),
            'denied'   => __('Blocked', 'wpmcp'),
            'pending'  => __('Waiting for approval', 'wpmcp'),
            'seen'     => __('Allowed (approval off)', 'wpmcp'),
        ];
        ?>
        <h2><?php echo esc_html__('OAuth clients', 'wpmcp'); ?></h2>
        <p><?php echo esc_html__('MCP clients that sign in through OAuth with a client metadata document (an https URL as their client ID) are listed here. This site fetches that document to learn the client\'s name and where it may redirect.', 'wpmcp'); ?></p>
        <form method="post">
            <input type="hidden" name="wpmcp_connection_action" value="oauth_client_approval">
            <input type="hidden" name="_wpnonce" value="<?php echo esc_attr($nonce); ?>">
            <input type="hidden" name="require" value="<?php echo $required ? '0' : '1'; ?>">
            <?php submit_button(
                $required ? __('Stop requiring approval', 'wpmcp') : __('Require approval for new clients', 'wpmcp'),
                'secondary',
                'submit',
                false
            ); ?>
            <span class="description">
                <?php echo $required
                    ? esc_html__('New clients wait here until you approve them.', 'wpmcp')
                    : esc_html__('New clients can connect as soon as a signed-in user authorizes them. Turning approval on keeps the clients already listed.', 'wpmcp'); ?>
            </span>
        </form>
        <?php $this->render_oauth_connections($nonce); ?>
        <?php if (! $clients) : ?>
            <p><?php echo esc_html__('No OAuth client has used a client metadata document yet.', 'wpmcp'); ?></p>
            <?php return; ?>
        <?php endif; ?>
        <table class="widefat striped" style="margin-top: 1em;">
            <thead><tr>
                <th><?php echo esc_html__('Client', 'wpmcp'); ?></th>
                <th><?php echo esc_html__('Redirects to', 'wpmcp'); ?></th>
                <th><?php echo esc_html__('Status', 'wpmcp'); ?></th>
                <th></th>
            </tr></thead>
            <tbody>
            <?php foreach ($clients as $client) : ?>
                <?php
                $hosts    = array_map('strval', (array) ($client['redirect_hosts'] ?? []));
                $loopback = $hosts && ! array_diff($hosts, ['127.0.0.1', '::1', 'localhost']);
                $status   = (string) ($client['status'] ?? '');
                ?>
                <tr>
                    <td>
                        <strong><?php echo esc_html((string) ($client['client_name'] ?? '')); ?></strong><br>
                        <code><?php echo esc_html((string) $client['client_id']); ?></code>
                    </td>
                    <td>
                        <?php echo esc_html(implode(', ', $hosts)); ?>
                        <?php if ($loopback) : ?>
                            <br><span class="description"><?php echo esc_html__('Loopback only: any program on the user\'s computer could present this client ID. Approve it only if you expect this client.', 'wpmcp'); ?></span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo esc_html($labels[ $status ] ?? $status); ?></td>
                    <td>
                        <?php foreach (['oauth_client_approve' => __('Approve', 'wpmcp'), 'oauth_client_deny' => __('Block', 'wpmcp'), 'oauth_client_forget' => __('Forget', 'wpmcp')] as $action => $label) : ?>
                            <?php if (('oauth_client_approve' === $action && 'approved' === $status) || ('oauth_client_deny' === $action && 'denied' === $status)) {
                                continue;
                            } ?>
                            <form method="post" style="display: inline;">
                                <input type="hidden" name="wpmcp_connection_action" value="<?php echo esc_attr($action); ?>">
                                <input type="hidden" name="_wpnonce" value="<?php echo esc_attr($nonce); ?>">
                                <input type="hidden" name="client_id" value="<?php echo esc_attr((string) $client['client_id']); ?>">
                                <?php submit_button($label, 'oauth_client_deny' === $action ? 'delete small' : 'small', 'submit', false); ?>
                            </form>
                        <?php endforeach; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }
    /**
     * Every live OAuth connection with its access level (issue #454), and
     * a control to change it. Lowering applies on the connection's next
     * request, without the client reconnecting. Connections made before
     * access levels existed show as full access.
     */
    private function render_oauth_connections(string $nonce): void
    {
        $connections = Client_Access::connections();
        $identities  = array_values(array_filter(array_map(static fn (array $i): string => (string) ($i['name'] ?? ''), Identity_Store::list()), 'strlen'));
        $cimd        = Client_Metadata_Document::clients();
        ?>
        <h3><?php echo esc_html__('Connected applications', 'wpmcp'); ?></h3>
        <p><?php echo esc_html__('Each application a user approved through OAuth, and what it may do. Read only refuses anything that would change the site. A scoped identity limits it exactly as that identity is limited. Changes apply on its next request.', 'wpmcp'); ?></p>
        <?php if (! $connections) : ?>
            <p><?php echo esc_html__('No application is connected through OAuth.', 'wpmcp'); ?></p>
            <?php return; ?>
        <?php endif; ?>
        <table class="widefat striped" style="margin-bottom: 1em;">
            <thead><tr>
                <th><?php echo esc_html__('Application', 'wpmcp'); ?></th>
                <th><?php echo esc_html__('Approved by', 'wpmcp'); ?></th>
                <th><?php echo esc_html__('Access', 'wpmcp'); ?></th>
            </tr></thead>
            <tbody>
            <?php foreach ($connections as $connection) : ?>
                <?php
                $client_id = $connection['client_id'];
                $client    = Client_Store::get($client_id);
                $name      = (string) ($client['client_name'] ?? ($cimd[ $client_id ]['client_name'] ?? ''));
                $user      = get_userdata($connection['user_id']);
                $current   = Client_Access::IDENTITY === $connection['level'] ? 'identity:' . $connection['identity'] : $connection['level'];
                $capped    = Client_Access::SCOPE_READ === $connection['scope'];
                $choices   = [
                    Client_Access::FULL => $capped ? __('Full access (granted read only)', 'wpmcp') : __('Full access', 'wpmcp'),
                    Client_Access::READ => __('Read only', 'wpmcp'),
                ];
                foreach ($identities as $identity) {
                    /* translators: %s: scoped identity name. */
                    $choices[ 'identity:' . $identity ] = sprintf(__('As the "%s" identity', 'wpmcp'), $identity);
                }
                ?>
                <tr>
                    <td>
                        <strong><?php echo esc_html('' !== $name ? $name : __('Unnamed application', 'wpmcp')); ?></strong><br>
                        <code><?php echo esc_html($client_id); ?></code>
                    </td>
                    <td><?php echo esc_html($user ? $user->user_login : __('Deleted user', 'wpmcp')); ?></td>
                    <td>
                        <form method="post">
                            <input type="hidden" name="wpmcp_connection_action" value="oauth_access">
                            <input type="hidden" name="_wpnonce" value="<?php echo esc_attr($nonce); ?>">
                            <input type="hidden" name="client_id" value="<?php echo esc_attr($client_id); ?>">
                            <input type="hidden" name="user_id" value="<?php echo esc_attr((string) $connection['user_id']); ?>">
                            <select name="access">
                                <?php foreach ($choices as $value => $label) : ?>
                                    <option value="<?php echo esc_attr($value); ?>" <?php selected($current, $value); ?>><?php echo esc_html($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?php submit_button(__('Save', 'wpmcp'), 'small', 'submit', false); ?>
                            <?php if (! $connection['stored']) : ?>
                                <br><span class="description"><?php echo esc_html__('Connected before access levels existed, so it has full access.', 'wpmcp'); ?></span>
                            <?php endif; ?>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }
}
