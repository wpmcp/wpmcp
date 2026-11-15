<?php

namespace WPMCP\Integrations;

use WPMCP\Tools\Meta\Secret_Options;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * FluentSMTP status, log, test send and sender name, as ops on the theme
 * dispatcher pair (through Ops_Status_Packs), so they add no top-level tools
 * (issue #696):
 *  - get-fluent-smtp-config: every connection (provider, sender, host, port,
 *    where its credentials live, the last health check), the routing of
 *    additional sender addresses, the misc settings and the FLUENTMAIL_*
 *    constants that are defined. The settings are read through the plugin's
 *    own fluentMailGetSettings() (which decrypts), then masked through the
 *    secret-option registry (issue #865) and the plugin's own SecretMasker
 *    lists, and every stored or constant credential value is scrubbed from
 *    the whole answer. Credentials are never returned, only which are set.
 *  - get-fluent-smtp-log: the last N rows of the fsmpt_email_logs table
 *    (status, masked recipients, subject, time, a trimmed and scrubbed
 *    error). Never the body, headers or attachments.
 *  - send-fluent-smtp-test: Mail_Check, destructive (confirm:true), only to
 *    the caller's own or the admin address, the shared hourly limit.
 *    recoverable:false: a mail was sent.
 *  - set-fluent-smtp-from: the display name of one existing connection.
 *    FluentSMTP routes by the From address, so the address names the
 *    connection and is never changed here; an address no connection owns is
 *    refused. Saved through fluentMailSetSettings() (never the raw option,
 *    which would corrupt the encrypted fields) and snapshotted on the
 *    fluentmail-settings option as stored, so a rollback restores it exactly.
 *
 * The test send and the write are off until the site opts in through the
 * wpmcp_enable_smtp_write filter (opt_in_required otherwise). Presence is
 * filterable through wpmcp_fluent_smtp_active.
 */
final class Fluent_SMTP_Status
{
    public const OPTION        = 'fluentmail-settings';
    public const HEALTH_OPTION = '_fluentsmtp_connection_health';

    /** Provider setting keys that hold credentials, besides the plugin's own lists. */
    private const SECRET_KEYS = [ 'password', 'api_key', 'secret_key', 'access_key', 'client_secret', 'access_token', 'refresh_token', 'auth_token', 'auth_key', 'private_key', 'token' ];

    /** Provider setting keys that identify an account: masked and scrubbed, not returned. */
    private const IDENT_KEYS = [ 'username', 'client_id', 'tenant', 'tenant_id', 'account_id', 'access_key_id' ];

    private const SKIP_CONSTANTS = [ 'FLUENTMAIL_PLUGIN_FILE', 'FLUENTMAIL_PLUGIN_PATH', 'FLUENTMAIL_PLUGIN_URL', 'FLUENTMAIL_PLUGIN_VERSION', 'FLUENTMAIL_UPLOAD_DIR' ];

    /** @return array<string,array<string,mixed>> */
    public static function operations(): array
    {
        $requires = static fn () => Ops_Status_Packs::presence(self::is_active(), 'fluent_smtp_inactive', 'FluentSMTP');
        $enabled  = SMTP_Pack_Support::writes_enabled();

        return [
            'get-fluent-smtp-config' => Ops_Status_Packs::read_op(
                'FluentSMTP: connections (provider, sender, host, port, where credentials live, last health check), additional sender routing, misc settings and defined constants; credentials are never returned, only which are set',
                $requires,
                static fn (): array => self::scrub(self::config())
            ),
            'get-fluent-smtp-log'    => Ops_Status_Packs::read_op(
                'FluentSMTP email log: the last N sends (status, masked recipients, subject, time, error); never the body. Optional status filter',
                $requires,
                static fn (array $args): array => self::scrub(self::log((int) ($args['limit'] ?? 20), (string) ($args['status'] ?? ''))),
                [
                    'limit'  => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 100 ],
                    'status' => [ 'type' => 'string', 'enum' => [ 'pending', 'failed', 'sent' ] ],
                ]
            ),
            'send-fluent-smtp-test'  => [
                'mode'               => 'destructive',
                'capability'         => 'manage_options',
                'description'        => 'Send a test email through FluentSMTP to your own or the admin address (confirm:true). Not reversible: a mail is sent (recoverable:false). Same recipient rule and hourly limit as get-site-health mail_test.' . SMTP_Pack_Support::gate_note(),
                'enabled_by_default' => $enabled,
                'opt_in_filter'      => SMTP_Pack_Support::ENABLE_FILTER,
                'input_schema'       => SMTP_Pack_Support::test_schema(),
                'requires'           => $requires,
                'handler'            => static fn (array $args): array => self::scrub(SMTP_Pack_Support::send_test((string) $args['recipient'])),
            ],
            'set-fluent-smtp-from'   => [
                'mode'               => 'write',
                'capability'         => 'manage_options',
                'description'        => 'Set the sender name of the FluentSMTP connection that owns from_email (FluentSMTP routes by From address, so the address itself is not changed; an address no connection owns is refused). Saved through FluentSMTP\'s own settings writer; snapshotted on the fluentmail-settings option, restorable with rollback-operation.' . SMTP_Pack_Support::gate_note(),
                'enabled_by_default' => $enabled,
                'opt_in_filter'      => SMTP_Pack_Support::ENABLE_FILTER,
                'input_schema'       => [
                    'type'       => 'object',
                    'properties' => [
                        'from_email'  => [ 'type' => 'string' ],
                        'sender_name' => [ 'type' => 'string', 'minLength' => 1, 'maxLength' => 200 ],
                    ],
                    'required'   => [ 'from_email', 'sender_name' ],
                ],
                'requires'           => $requires,
                'validate'           => static fn (array $args): ?array => self::from_refusal($args),
                'snapshot'           => static fn (): array => [ 'object_type' => 'option', 'object_id' => self::OPTION ],
                'handler'            => static fn (array $args): array => self::set_from($args),
            ],
        ];
    }

    /** Whether FluentSMTP is loaded, filterable through wpmcp_fluent_smtp_active. */
    public static function is_active(): bool
    {
        return (bool) apply_filters('wpmcp_fluent_smtp_active', defined('FLUENTMAIL'))
            && function_exists('fluentMailGetSettings')
            && function_exists('fluentMailSetSettings');
    }

    /** @return array<string,mixed> the settings as FluentSMTP reads them (decrypted), uncached. */
    private static function settings(): array
    {
        $settings = fluentMailGetSettings([], false);
        return is_array($settings) ? $settings : [];
    }

    /** @return string[] */
    private static function secret_keys(): array
    {
        $keys  = self::SECRET_KEYS;
        $class = 'FluentMail\\App\\Services\\SecretMasker';
        if (class_exists($class)) {
            foreach ([ 'SECRET_FIELDS', 'WITHHELD_FIELDS' ] as $name) {
                if (defined($class . '::' . $name)) {
                    $keys = array_merge($keys, (array) constant($class . '::' . $name));
                }
            }
            if (defined($class . '::ENCRYPTED_FIELDS')) {
                foreach ((array) constant($class . '::ENCRYPTED_FIELDS') as $fields) {
                    $keys = array_merge($keys, array_keys((array) $fields));
                }
            }
        }
        return array_values(array_unique(array_map('strtolower', $keys)));
    }

    /** @return array<string,mixed> */
    private static function config(): array
    {
        $settings = self::settings();
        $masked   = (array) Secret_Options::mask_registered(self::OPTION, $settings);
        $secrets  = self::secret_keys();
        $health   = get_option(self::HEALTH_OPTION, []);
        $health   = is_array($health) ? $health : [];
        $mappings = is_array($settings['mappings'] ?? null) ? $settings['mappings'] : [];

        $connections = [];
        foreach ((array) ($settings['connections'] ?? []) as $key => $connection) {
            $raw      = (array) ($connection['provider_settings'] ?? []);
            $shown    = (array) ($masked['connections'][ $key ]['provider_settings'] ?? []);
            $sender   = (string) ($raw['sender_email'] ?? '');
            $set      = [];
            foreach ($secrets as $field) {
                if (isset($raw[ $field ]) && '' !== $raw[ $field ] && [] !== $raw[ $field ]) {
                    $set[] = $field;
                }
            }
            $extra = [];
            foreach ($mappings as $email => $target) {
                if ((string) $target === (string) $key && 0 !== strcasecmp((string) $email, $sender)) {
                    $extra[] = (string) $email;
                }
            }
            $check = is_array($health[ $key ] ?? null) ? $health[ $key ] : null;

            $connections[] = [
                'id'                => (string) $key,
                'title'             => (string) ($connection['title'] ?? ''),
                'provider'          => (string) ($raw['provider'] ?? ''),
                'sender_email'      => $sender,
                'sender_name'       => (string) ($raw['sender_name'] ?? ''),
                'additional_senders' => $extra,
                'secrets_set'       => $set,
                'health'            => null === $check ? null : [
                    'status'     => (string) ($check['status'] ?? ''),
                    'message'    => SMTP_Pack_Support::text($check['message'] ?? ''),
                    'checked_at' => Ops_Status_Packs::iso_datetime((string) ($check['checked_at'] ?? '')),
                ],
                'provider_settings' => self::shown($shown),
            ];
        }

        $found  = [];
        $consts = SMTP_Pack_Support::constants('FLUENTMAIL_', self::SKIP_CONSTANTS, $found);

        return [
            'version'            => defined('FLUENTMAIL_PLUGIN_VERSION') ? (string) FLUENTMAIL_PLUGIN_VERSION : null,
            'default_connection' => (string) ($settings['misc']['default_connection'] ?? ''),
            'encrypted_at_rest'  => ! empty($settings['use_encrypt']),
            'connections'        => $connections,
            'misc'               => is_array($settings['misc'] ?? null) ? $settings['misc'] : [],
            'constants'          => $consts,
        ];
    }

    /**
     * A connection's settings for display: account identifiers hidden, the SMTP
     * auth switch reported as 'authentication' and the key store as
     * 'stored_in', so redact()'s secret tokens keep them.
     *
     * @param array<string,mixed> $values
     * @return array<string,mixed>
     */
    private static function shown(array $values): array
    {
        $out = [];
        foreach ($values as $key => $value) {
            $key = (string) $key;
            if ('auth' === $key) {
                $out['authentication'] = 'yes' === $value || true === $value;
            } elseif ('key_store' === $key) {
                $out['stored_in'] = 'wp_config' === $value ? 'wp-config' : 'database';
            } elseif (in_array(strtolower($key), self::IDENT_KEYS, true)) {
                $out[ $key ] = '' === (string) (is_scalar($value) ? $value : '1') ? '' : Ops_Status_Packs::REDACTED;
            } else {
                $out[ $key ] = $value;
            }
        }
        return $out;
    }

    /** @return array<string,mixed> */
    private static function log(int $limit, string $status): array
    {
        global $wpdb;

        $limit  = max(1, min(100, $limit));
        $prefix = defined('FLUENT_MAIL_DB_PREFIX') ? (string) FLUENT_MAIL_DB_PREFIX : 'fsmpt_';
        $table  = $wpdb->prefix . $prefix . 'email_logs';
        $misc   = (array) (self::settings()['misc'] ?? []);
        $on     = 'no' !== ($misc['log_emails'] ?? 'yes');
        $out    = [ 'logging_enabled' => $on, 'timezone' => wp_timezone_string(), 'emails' => [] ];

        if (! Ops_Status_Packs::table_exists($table)) {
            return $out + [ 'hint' => 'FluentSMTP has no email log table on this site.' ];
        }

        $where = '';
        $args  = [];
        if (in_array($status, [ 'pending', 'failed', 'sent' ], true)) {
            $where  = 'WHERE `status` = %s';
            $args[] = $status;
        }
        $args[] = $limit;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- FluentSMTP's own log table; the name is built from $wpdb->prefix and a fixed suffix, the body, headers and attachments are not selected, the status and the limit are bound.
        $rows = $wpdb->get_results($wpdb->prepare("SELECT `id`, `to`, `from`, `subject`, `status`, `response`, `created_at` FROM `{$table}` {$where} ORDER BY `id` DESC LIMIT %d", $args), ARRAY_A);

        foreach (is_array($rows) ? $rows : [] as $row) {
            $failed = 'sent' !== (string) $row['status'];
            $out['emails'][] = [
                'id'      => (int) $row['id'],
                'status'  => (string) $row['status'],
                'to'      => SMTP_Pack_Support::masked_addresses($row['to']),
                'from'    => SMTP_Pack_Support::masked_addresses($row['from']),
                'subject' => SMTP_Pack_Support::text($row['subject']),
                'error'   => $failed ? SMTP_Pack_Support::text($row['response']) : '',
                'time'    => (string) $row['created_at'],
            ];
        }
        return $out;
    }

    /** @return array{code:string,message:string}|null */
    private static function from_refusal(array $args): ?array
    {
        $email = (string) $args['from_email'];
        if (! is_email($email)) {
            return [ 'code' => 'invalid_args', 'message' => 'from_email must be a valid email address.' ];
        }
        if (null === self::connection_key(self::settings(), $email)) {
            return [
                'code'    => 'fluent_smtp_no_connection',
                'message' => sprintf('No FluentSMTP connection sends from %s. FluentSMTP routes mail by From address, so this tool only renames a connection that already owns the address (see get-fluent-smtp-config).', $email),
            ];
        }
        if ('' === trim(sanitize_text_field((string) $args['sender_name']))) {
            return [ 'code' => 'invalid_args', 'message' => 'sender_name must not be empty.' ];
        }
        return null;
    }

    /** The key of the connection an address routes to (exact, then case-insensitive), or null. */
    private static function connection_key(array $settings, string $email): ?string
    {
        $mappings    = is_array($settings['mappings'] ?? null) ? $settings['mappings'] : [];
        $connections = is_array($settings['connections'] ?? null) ? $settings['connections'] : [];
        $key         = null;
        if (isset($mappings[ $email ])) {
            $key = (string) $mappings[ $email ];
        } else {
            foreach ($mappings as $mapped => $target) {
                if (0 === strcasecmp((string) $mapped, $email)) {
                    $key = (string) $target;
                    break;
                }
            }
        }
        return null !== $key && isset($connections[ $key ]) ? $key : null;
    }

    /** @return array<string,string> */
    private static function set_from(array $args): array
    {
        $settings = self::settings();
        $key      = self::connection_key($settings, (string) $args['from_email']);
        if (null === $key) {
            throw new Operation_Refused('fluent_smtp_no_connection', 'No FluentSMTP connection sends from that address.');
        }

        $name = sanitize_text_field((string) $args['sender_name']);
        $settings['connections'][ $key ]['provider_settings']['sender_name'] = $name;
        fluentMailSetSettings($settings);

        $after = self::settings();
        return [
            'from_email'  => (string) ($after['connections'][ $key ]['provider_settings']['sender_email'] ?? ''),
            'sender_name' => (string) ($after['connections'][ $key ]['provider_settings']['sender_name'] ?? ''),
        ];
    }

    /**
     * Scrub by value: every credential and account identifier FluentSMTP holds,
     * as stored (ciphertext), as it reads them (decrypted) and from constants,
     * is replaced wherever it appears in $data.
     *
     * @param array<mixed> $data
     * @return array<mixed>
     */
    private static function scrub(array $data): array
    {
        $keys    = array_merge(self::secret_keys(), self::IDENT_KEYS);
        $secrets = [];
        $stored  = get_option(self::OPTION, []);
        SMTP_Pack_Support::collect(is_array($stored) ? $stored : [], $keys, $secrets);
        SMTP_Pack_Support::collect(self::settings(), $keys, $secrets);
        SMTP_Pack_Support::constants('FLUENTMAIL_', self::SKIP_CONSTANTS, $secrets);

        return SMTP_Pack_Support::scrub($data, $secrets);
    }
}
