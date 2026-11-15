<?php

namespace WPMCP\Integrations;

use WPMCP\Tools\Meta\Secret_Options;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Post SMTP status, log, test send and from identity, as ops on the theme
 * dispatcher pair (through Ops_Status_Packs), so they add no top-level tools
 * (issue #696):
 *  - get-post-smtp-config: the active transport, the from identity, host,
 *    port, security, run mode, logging and fallback settings, and whether the
 *    OAuth token is connected. The postman_options option is masked through
 *    the secret-option registry (issue #865), account identifiers (user
 *    names, OAuth client ids) are hidden, and every stored or constant
 *    credential value (also in its base64 form) is scrubbed from the whole
 *    answer. Credentials are never returned, only which are set.
 *  - get-post-smtp-log: the last N rows of the post_smtp_logs table (status,
 *    masked recipients, subject, transport, time, a trimmed and scrubbed
 *    error). Never the body, headers or the session transcript, which can
 *    hold the SMTP conversation.
 *  - send-post-smtp-test: Mail_Check, destructive (confirm:true), only to the
 *    caller's own or the admin address, the shared hourly limit.
 *    recoverable:false: a mail was sent.
 *  - set-post-smtp-from: sender_email and/or sender_name, merged into the
 *    postman_options option as stored (never credentials, never the
 *    transport) and snapshotted on that option, so a rollback restores it
 *    exactly.
 *
 * The test send and the write are off until the site opts in through the
 * wpmcp_enable_smtp_write filter (opt_in_required otherwise). Presence is
 * filterable through wpmcp_post_smtp_active.
 */
final class Post_SMTP_Status
{
    public const OPTION       = 'postman_options';
    public const TOKEN_OPTION = 'postman_auth_token';

    /** Option keys that identify an account: hidden and scrubbed, not returned. */
    private const IDENT_KEYS = [ 'basic_auth_username', 'fallback_smtp_username', 'oauth_client_id', 'cloudflare_account_id', 'mailgun_domain_name' ];

    /** Keys the answer leaves out: free-form headers can carry credentials. */
    private const OMIT_KEYS = [ 'headers' ];

    /** Renames so redact()'s secret tokens ("auth") keep a setting that is not one. */
    private const RENAMES = [ 'auth_type' => 'authentication_type', 'fallback_smtp_use_auth' => 'fallback_smtp_use_authentication' ];

    private const SKIP_CONSTANTS = [ 'POST_SMTP_VER', 'POST_SMTP_DB_VERSION' ];

    /** The log's own success marker for a send that went out. */
    private const SENT = [ '1', 'Sent ( ** Fallback ** )' ];

    /** @return array<string,array<string,mixed>> */
    public static function operations(): array
    {
        $requires = static fn () => Ops_Status_Packs::presence(self::is_active(), 'post_smtp_inactive', 'Post SMTP');
        $enabled  = SMTP_Pack_Support::writes_enabled();

        return [
            'get-post-smtp-config' => Ops_Status_Packs::read_op(
                'Post SMTP: transport, from identity, host, port, security, run mode, logging, fallback and whether OAuth is connected; credentials are never returned, only which are set',
                $requires,
                static fn (): array => self::scrub(self::config())
            ),
            'get-post-smtp-log'    => Ops_Status_Packs::read_op(
                'Post SMTP email log: the last N sends (status, masked recipients, subject, transport, time, error); never the body or session transcript. Optional status filter',
                $requires,
                static fn (array $args): array => self::scrub(self::log(self::secrets(), (int) ($args['limit'] ?? 20), (string) ($args['status'] ?? ''))),
                [
                    'limit'  => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 100 ],
                    'status' => [ 'type' => 'string', 'enum' => [ 'sent', 'failed', 'queued' ] ],
                ]
            ),
            'send-post-smtp-test'  => [
                'mode'               => 'destructive',
                'capability'         => 'manage_options',
                'description'        => 'Send a test email through Post SMTP to your own or the admin address (confirm:true). Not reversible: a mail is sent (recoverable:false). Same recipient rule and hourly limit as get-site-health mail_test.' . SMTP_Pack_Support::gate_note(),
                'enabled_by_default' => $enabled,
                'opt_in_filter'      => SMTP_Pack_Support::ENABLE_FILTER,
                'input_schema'       => SMTP_Pack_Support::test_schema(),
                'requires'           => $requires,
                'handler'            => static fn (array $args): array => self::scrub(SMTP_Pack_Support::send_test((string) $args['recipient'])),
            ],
            'set-post-smtp-from'   => [
                'mode'               => 'write',
                'capability'         => 'manage_options',
                'description'        => 'Set the from address and/or name Post SMTP sends with. Only those two settings change, never credentials or the transport. Snapshotted on the postman_options option; restorable with rollback-operation.' . SMTP_Pack_Support::gate_note(),
                'enabled_by_default' => $enabled,
                'opt_in_filter'      => SMTP_Pack_Support::ENABLE_FILTER,
                'input_schema'       => [
                    'type'          => 'object',
                    'properties'    => [
                        'from_email' => [ 'type' => 'string' ],
                        'from_name'  => [ 'type' => 'string', 'maxLength' => 200 ],
                    ],
                    'minProperties' => 1,
                ],
                'requires'           => $requires,
                'validate'           => static fn (array $args): ?array => self::from_refusal($args),
                'snapshot'           => static fn (): array => [ 'object_type' => 'option', 'object_id' => self::OPTION ],
                'handler'            => static fn (array $args): array => self::set_from($args),
            ],
        ];
    }

    /** Whether Post SMTP is loaded, filterable through wpmcp_post_smtp_active. */
    public static function is_active(): bool
    {
        return (bool) apply_filters('wpmcp_post_smtp_active', defined('POST_SMTP_VER')) && class_exists('PostmanOptions');
    }

    /** @return array<string,mixed> the stored option, raw. */
    private static function stored(): array
    {
        $stored = get_option(self::OPTION, []);
        return is_array($stored) ? $stored : [];
    }

    /** @return string[] the stored option's keys that hold credentials. */
    private static function secret_keys(array $stored): array
    {
        $keys = [ 'access_token', 'refresh_token' ];
        foreach (array_keys($stored) as $key) {
            if (1 === preg_match('/(api_key|api_token|secret|passw|token)/i', (string) $key)) {
                $keys[] = strtolower((string) $key);
            }
        }
        return array_values(array_unique($keys));
    }

    /** @return array<string,mixed> */
    private static function config(): array
    {
        $stored  = self::stored();
        $masked  = (array) Secret_Options::mask_registered(self::OPTION, $stored);
        $secrets = self::secret_keys($stored);

        $set = [];
        foreach ($secrets as $field) {
            if (isset($stored[ $field ]) && '' !== $stored[ $field ] && [] !== $stored[ $field ]) {
                $set[] = $field;
            }
        }

        $settings = [];
        foreach ($masked as $key => $value) {
            $key = (string) $key;
            if (in_array($key, self::OMIT_KEYS, true)) {
                continue;
            }
            if (in_array($key, self::IDENT_KEYS, true)) {
                $value = '' === (string) (is_scalar($value) ? $value : '1') ? '' : Ops_Status_Packs::REDACTED;
            }
            $settings[ self::RENAMES[ $key ] ?? $key ] = $value;
        }

        $token = get_option(self::TOKEN_OPTION, []);
        $token = is_array($token) ? $token : [];
        $found = [];

        return [
            'version'        => defined('POST_SMTP_VER') ? (string) POST_SMTP_VER : null,
            'configured'     => isset($stored['transport_type']),
            'transport'      => (string) ($stored['transport_type'] ?? ''),
            'from_email'     => (string) ($stored['sender_email'] ?? ''),
            'from_name'      => (string) ($stored['sender_name'] ?? ''),
            'secrets_set'    => $set,
            'oauth'          => [
                'connected'  => ! empty($token['access_token']) || ! empty($token['refresh_token']),
                'expires_at' => Ops_Status_Packs::iso_time($token['auth_token_expires'] ?? null),
            ],
            'settings'       => $settings,
            'constants'      => SMTP_Pack_Support::constants('POST_SMTP_', self::SKIP_CONSTANTS, $found),
        ];
    }

    /** @return array<string,mixed> */
    private static function log(array $scrub, int $limit, string $status): array
    {
        global $wpdb;

        $limit   = max(1, min(100, $limit));
        $table   = $wpdb->prefix . 'post_smtp_logs';
        $enabled = 'false' !== (string) (self::stored()['mail_log_enabled'] ?? 'true');
        if (defined('POST_SMTP_CORE_MAIL_LOG')) {
            $enabled = (bool) POST_SMTP_CORE_MAIL_LOG;
        }
        $out = [ 'logging_enabled' => $enabled, 'timezone' => wp_timezone_string(), 'emails' => [] ];

        if (! Ops_Status_Packs::table_exists($table)) {
            return $out + [ 'hint' => 'Post SMTP has no email log table on this site.' ];
        }

        // Fixed fragments, never caller text: the status filter picks one.
        $clauses = [
            'sent'   => "WHERE `success` IN ('1', 'Sent ( ** Fallback ** )')",
            'queued' => "WHERE `success` = 'In Queue'",
            'failed' => "WHERE (`success` IS NULL OR `success` NOT IN ('1', 'Sent ( ** Fallback ** )', 'In Queue'))",
        ];
        $where = $clauses[ $status ] ?? '';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Post SMTP's own log table; the name is built from $wpdb->prefix and a fixed suffix, $where is one of the fixed fragments above, the body, headers and transcript are not selected, the limit is bound.
        $rows = $wpdb->get_results($wpdb->prepare("SELECT `id`, `success`, `from_header`, `to_header`, `original_subject`, `transport_uri`, `time` FROM `{$table}` {$where} ORDER BY `id` DESC LIMIT %d", $limit), ARRAY_A);

        foreach (is_array($rows) ? $rows : [] as $row) {
            $success = (string) $row['success'];
            $label   = in_array($success, self::SENT, true) ? 'sent' : ('In Queue' === $success ? 'queued' : 'failed');
            $time    = is_numeric($row['time']) ? (int) $row['time'] : 0;

            $out['emails'][] = [
                'id'        => (int) $row['id'],
                'status'    => $label,
                'to'        => SMTP_Pack_Support::masked_addresses($row['to_header']),
                'from'      => SMTP_Pack_Support::masked_addresses($row['from_header']),
                'subject'   => SMTP_Pack_Support::text($row['original_subject'], $scrub),
                'transport' => SMTP_Pack_Support::text($row['transport_uri'], $scrub),
                'error'     => 'failed' === $label ? SMTP_Pack_Support::text($success, $scrub) : '',
                // current_time('timestamp'): the site's wall clock, as stored.
                'time'      => $time > 0 ? gmdate('Y-m-d H:i:s', $time) : null,
            ];
        }
        return $out;
    }

    /** @return array{code:string,message:string}|null */
    private static function from_refusal(array $args): ?array
    {
        if (array_key_exists('from_email', $args) && ! is_email((string) $args['from_email'])) {
            return [ 'code' => 'invalid_args', 'message' => 'from_email must be a valid email address.' ];
        }
        if (array_key_exists('from_name', $args) && '' === trim(sanitize_text_field((string) $args['from_name']))) {
            return [ 'code' => 'invalid_args', 'message' => 'from_name must not be empty.' ];
        }
        if (! isset(self::stored()['transport_type'])) {
            return [ 'code' => 'post_smtp_not_configured', 'message' => 'Post SMTP has no saved settings yet. Run its setup wizard first; this tool only changes the from identity of a configured transport.' ];
        }
        if (is_multisite() && ! is_main_site()) {
            $network = get_site_option('postman_network_options', []);
            if (is_array($network) && isset($network['post_smtp_global_settings'])) {
                return [ 'code' => 'post_smtp_network_settings', 'message' => 'Post SMTP uses the network-wide settings of the main site. Change the from identity there.' ];
            }
        }
        return null;
    }

    /** @return array{from_email:string,from_name:string} */
    private static function set_from(array $args): array
    {
        $options = self::stored();
        if (array_key_exists('from_email', $args)) {
            $options['sender_email'] = sanitize_email((string) $args['from_email']);
        }
        if (array_key_exists('from_name', $args)) {
            $options['sender_name'] = sanitize_text_field((string) $args['from_name']);
        }
        update_option(self::OPTION, $options);

        if (class_exists('PostmanOptions') && method_exists('PostmanOptions', 'getInstance')) {
            $instance = \PostmanOptions::getInstance();
            if (method_exists($instance, 'reload')) {
                $instance->reload();
            }
        }

        return [
            'from_email' => (string) ($options['sender_email'] ?? ''),
            'from_name'  => (string) ($options['sender_name'] ?? ''),
        ];
    }

    /**
     * Scrub by value: every credential Post SMTP holds is replaced wherever it
     * appears in $data.
     *
     * @param array<mixed> $data
     * @return array<mixed>
     */
    private static function scrub(array $data): array
    {
        return SMTP_Pack_Support::scrub($data, self::secrets());
    }

    /**
     * Every credential value Post SMTP holds (stored, base64 decoded, in the
     * OAuth token option or a constant). Account identifiers such as the SMTP
     * user name are hidden by key, not scrubbed by value: that would also blank
     * a sender address that equals the user name.
     *
     * @return string[]
     */
    private static function secrets(): array
    {
        $stored  = self::stored();
        $keys    = self::secret_keys($stored);
        $secrets = [];
        SMTP_Pack_Support::collect($stored, $keys, $secrets);
        $token = get_option(self::TOKEN_OPTION, []);
        SMTP_Pack_Support::collect(is_array($token) ? $token : [], $keys, $secrets);
        SMTP_Pack_Support::constants('POST_SMTP_', self::SKIP_CONSTANTS, $secrets);

        return $secrets;
    }
}
