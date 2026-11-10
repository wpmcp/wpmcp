<?php

namespace WPMCP\Integrations;

use WPMCP\MCP\Confirmation_Required;
use WPMCP\Tools\Diagnostics\Mail_Check;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * WP Mail SMTP status, test send and from identity, as free ops on the theme
 * dispatcher pair (through Ops_Status_Packs), so they add no top-level tools:
 *  - get-wp-mail-smtp-config: the active mailer, from identity, the settings
 *    WP Mail SMTP's constants override, and the mailer's settings read
 *    through its own Options API (so constants and the decrypted SMTP
 *    password are what it sees). Credentials are masked by key, and every
 *    stored or constant credential value is scrubbed from the whole answer,
 *    so a secret cannot leak through a renamed or nested key; redact() is the
 *    second line.
 *  - get-wp-mail-smtp-log: the last N sends from the Pro email log, or
 *    logging_enabled:false when the Pro log is off or absent.
 *  - send-wp-mail-smtp-test: Mail_Check (issue #415), the check behind
 *    get-site-health's mail_test, with its rules: confirm:true, only to the
 *    caller's own or the admin address, the shared hourly limit.
 *  - set-wp-mail-smtp-from: the from name and address, merged into the
 *    settings through Options::set() as WP Mail SMTP's own settings screen
 *    saves them, snapshot-wrapped on the wp_mail_smtp option. A field a
 *    constant defines is refused, since the constant would win.
 *
 * Every op runs at manage_options and declares a 'requires' check, so with
 * WP Mail SMTP inactive the ops stay documented (dependency_met:false) and
 * answer wp_mail_smtp_inactive. Presence is filterable through
 * wpmcp_wp_mail_smtp_active.
 */
final class WP_Mail_SMTP_Status
{
    public const OPTION = 'wp_mail_smtp';

    private const OPTIONS_CLASS = 'WPMailSMTP\\Options';
    private const EMAILS_CLASS  = 'WPMailSMTP\\Pro\\Emails\\Logs\\EmailsCollection';

    /** Mailer setting keys never returned (credentials, OAuth app ids, the SMTP user, as Mail_Check). */
    private const MASKED_KEYS = [ 'pass', 'user', 'api_key', 'secret_key', 'client_id', 'client_secret', 'server_api_token', 'access_token', 'refresh_token', 'auth_code', 'user_details' ];

    /** Keys whose values are credentials, scrubbed wherever they appear. */
    private const SECRET_KEYS = [ 'pass', 'api_key', 'secret_key', 'client_secret', 'server_api_token', 'access_token', 'refresh_token', 'auth_code', 'key' ];

    /** Shortest credential value scrubbed from free text; shorter ones would mask unrelated words. */
    private const SCRUB_MIN = 6;

    /** @return array<string,array<string,mixed>> */
    public static function operations(): array
    {
        $requires = static fn () => Ops_Status_Packs::presence(self::is_active(), 'wp_mail_smtp_inactive', 'WP Mail SMTP');

        return [
            'get-wp-mail-smtp-config' => Ops_Status_Packs::read_op(
                'WP Mail SMTP: active mailer, from identity, constant overrides and the mailer settings with credentials masked; all_mailers:true adds the other configured mailers',
                $requires,
                static fn (array $args): array => self::scrub(self::config(true === ($args['all_mailers'] ?? false))),
                [ 'all_mailers' => [ 'type' => 'boolean' ] ]
            ),
            'get-wp-mail-smtp-log'    => Ops_Status_Packs::read_op(
                'WP Mail SMTP Pro email log: the last N sends (subject, recipients, status, error); logging_enabled:false without Pro or with the log off',
                $requires,
                static fn (array $args): array => self::scrub(self::log((int) ($args['limit'] ?? 10))),
                [ 'limit' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 100 ] ]
            ),
            'send-wp-mail-smtp-test'  => [
                'mode'         => 'read',
                'capability'   => 'manage_options',
                'description'  => 'Report how mail is sent now and, with confirm:true, send a test email to your own or the admin address. Same rules as get-site-health mail_test',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'recipient' => [ 'type' => 'string' ],
                        'confirm'   => [ 'type' => 'boolean' ],
                    ],
                    'required'   => [ 'recipient' ],
                ],
                'requires'     => $requires,
                'handler'      => static fn (array $args): array => self::scrub(self::send_test((string) $args['recipient'], true === ($args['confirm'] ?? false))),
            ],
            'set-wp-mail-smtp-from'   => [
                'mode'          => 'write',
                'capability'    => 'manage_options',
                'description'   => 'Set the from name and/or address WP Mail SMTP sends with. Snapshotted on the wp_mail_smtp option; restorable with rollback-operation',
                'input_schema'  => [
                    'type'          => 'object',
                    'properties'    => [
                        'from_email' => [ 'type' => 'string' ],
                        'from_name'  => [ 'type' => 'string', 'maxLength' => 200 ],
                    ],
                    'minProperties' => 1,
                ],
                'requires'      => $requires,
                'validate'      => static fn (array $args): ?array => self::from_refusal($args),
                'snapshot'      => static fn (): array => [ 'object_type' => 'option', 'object_id' => self::OPTION ],
                'handler'       => static fn (array $args): array => self::set_from($args),
            ],
        ];
    }

    /** Whether WP Mail SMTP is loaded, filterable through wpmcp_wp_mail_smtp_active. */
    public static function is_active(): bool
    {
        return (bool) apply_filters('wpmcp_wp_mail_smtp_active', defined('WPMS_PLUGIN_VER'))
            && class_exists(self::OPTIONS_CLASS)
            && function_exists('wp_mail_smtp');
    }

    /** @return array<string,mixed> */
    private static function config(bool $all_mailers): array
    {
        $options = self::options();
        $mailer  = (string) $options->get('mail', 'mailer');

        $constants = [];
        foreach ([ 'mail' => [ 'mailer', 'from_email', 'from_name', 'from_email_force', 'from_name_force', 'return_path' ] ] + [ $mailer => array_keys((array) $options->get_group($mailer)) ] as $group => $keys) {
            foreach ($keys as $key) {
                if ($options->is_const_defined((string) $group, (string) $key)) {
                    $constants[] = $group . '.' . $key;
                }
            }
        }

        $out = [
            'mailer'            => $mailer,
            'mailer_title'      => self::mailer_title($mailer),
            'from_email'        => (string) $options->get('mail', 'from_email'),
            'from_name'         => (string) $options->get('mail', 'from_name'),
            'from_email_forced' => (bool) $options->get('mail', 'from_email_force'),
            'from_name_forced'  => (bool) $options->get('mail', 'from_name_force'),
            'return_path'       => (bool) $options->get('mail', 'return_path'),
            'do_not_send'       => (bool) $options->get('general', 'do_not_send'),
            'pro'               => null !== self::pro_edition(),
            'constants'         => $constants,
            'mailer_settings'   => in_array($mailer, self::mailers(), true) ? self::mask((array) $options->get_group($mailer)) : [],
        ];

        if ($all_mailers) {
            $others = [];
            foreach (array_keys(self::stored()) as $group) {
                if ($group !== $mailer && in_array($group, self::mailers(), true)) {
                    $others[ $group ] = self::mask((array) $options->get_group($group));
                }
            }
            $out['other_mailers'] = $others;
        }

        return $out;
    }

    /** @return array<string,mixed> */
    private static function log(int $limit): array
    {
        $limit = max(1, min(100, $limit));
        $pro   = self::pro_edition();
        $logs  = null !== $pro && method_exists($pro, 'get_logs') ? $pro->get_logs() : null;
        $enabled = is_object($logs) && method_exists($logs, 'is_enabled') && (bool) $logs->is_enabled();

        if (! $enabled || ! class_exists(self::EMAILS_CLASS)) {
            return [
                'logging_enabled' => false,
                'pro'             => null !== $pro,
                'emails'          => [],
                'hint'            => 'The email log needs WP Mail SMTP Pro with logging turned on in its Email Log settings.',
            ];
        }

        $class  = self::EMAILS_CLASS;
        $emails = [];
        $rows   = (new $class([ 'per_page' => $limit, 'offset' => 0, 'order' => 'DESC', 'orderby' => 'id' ]))->get();
        foreach (is_iterable($rows) ? $rows : [] as $email) {
            if (count($emails) >= $limit || ! is_object($email)) {
                break;
            }
            $date     = method_exists($email, 'get_date_sent') ? $email->get_date_sent() : null;
            $emails[] = [
                'id'        => method_exists($email, 'get_id') ? (int) $email->get_id() : null,
                'subject'   => method_exists($email, 'get_subject') ? (string) $email->get_subject() : '',
                'to'        => method_exists($email, 'get_people') ? array_values(array_map('strval', (array) $email->get_people('to'))) : [],
                'status'    => self::status_label(method_exists($email, 'get_status') ? (int) $email->get_status() : -1),
                'error'     => method_exists($email, 'get_error_text') ? (string) $email->get_error_text() : '',
                'mailer'    => method_exists($email, 'get_mailer') ? (string) $email->get_mailer() : '',
                'date_sent' => $date instanceof \DateTimeInterface ? Ops_Status_Packs::iso_datetime(\DateTime::createFromInterface($date)->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s')) : null,
            ];
        }

        return [ 'logging_enabled' => true, 'pro' => true, 'emails' => $emails ];
    }

    /** @return array<string,mixed> */
    private static function send_test(string $recipient, bool $confirm): array
    {
        $check = new Mail_Check(static fn (): int => time());
        try {
            return $check->run($recipient, $confirm);
        } catch (Confirmation_Required $e) {
            // Mail_Check's own rule: recipient and limit pass, nothing sent
            // until confirm:true. Report the transport and what would happen.
            $report         = $check->run('', false);
            $report['test'] = [ 'status' => 'confirmation_required', 'recipient' => strtolower(trim($recipient)), 'message' => $e->getMessage() ];
            return $report;
        } catch (\InvalidArgumentException $e) {
            throw new Operation_Refused('mail_test_recipient_refused', esc_html($e->getMessage()));
        } catch (\RuntimeException $e) {
            throw new Operation_Refused('mail_test_refused', esc_html($e->getMessage()));
        }
    }

    /** @return array{code:string,message:string}|null */
    private static function from_refusal(array $args): ?array
    {
        if (array_key_exists('from_email', $args) && ! is_email((string) $args['from_email'])) {
            return [ 'code' => 'invalid_args', 'message' => 'from_email must be a valid email address.' ];
        }
        $options = self::options();
        foreach ([ 'from_email' => 'WPMS_MAIL_FROM', 'from_name' => 'WPMS_MAIL_FROM_NAME' ] as $key => $constant) {
            if (array_key_exists($key, $args) && $options->is_const_defined('mail', $key)) {
                return [
                    'code'    => 'wp_mail_smtp_constant_override',
                    'message' => sprintf('%s is set by the %s constant in wp-config.php, which WP Mail SMTP uses instead of its settings. Change it there.', $key, $constant),
                ];
            }
        }
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- reading WP Mail SMTP's own filter to find where it keeps its settings.
        if (is_multisite() && ! is_main_site() && (bool) apply_filters('wp_mail_smtp_options_use_global_plugin_options', false)) {
            return [
                'code'    => 'wp_mail_smtp_network_settings',
                'message' => 'WP Mail SMTP keeps its settings on the main site of this network. Change the from identity there.',
            ];
        }
        return null;
    }

    /** @return array{from_email:string,from_name:string} */
    private static function set_from(array $args): array
    {
        $mail = [];
        if (array_key_exists('from_email', $args)) {
            $mail['from_email'] = sanitize_email((string) $args['from_email']);
        }
        if (array_key_exists('from_name', $args)) {
            $mail['from_name'] = sanitize_text_field((string) $args['from_name']);
        }

        // Merged into the stored settings (overwrite_existing false), the way
        // WP Mail SMTP's settings screen saves: true would replace the whole
        // option with this one group and drop the mailer and its credentials.
        $options = self::options();
        $options->set([ 'mail' => $mail ], false, false);

        return [
            'from_email' => (string) $options->get('mail', 'from_email'),
            'from_name'  => (string) $options->get('mail', 'from_name'),
        ];
    }

    private static function options(): object
    {
        $class = self::OPTIONS_CLASS;
        return $class::init();
    }

    private static function container(): object
    {
        $container = wp_mail_smtp();
        return is_object($container) ? $container : new \stdClass();
    }

    /** WP Mail SMTP's own Pro code (its email log lives there), or null on the free edition. */
    private static function pro_edition(): ?object
    {
        $container = self::container();
        $pro       = method_exists($container, 'get_pro') ? $container->get_pro() : null;
        return is_object($pro) ? $pro : null;
    }

    /** @return string[] */
    private static function mailers(): array
    {
        $class = self::OPTIONS_CLASS;
        return isset($class::$mailers) && is_array($class::$mailers) ? $class::$mailers : [];
    }

    /** @return array<string,mixed> the raw stored option, the one source that has every group. */
    private static function stored(): array
    {
        $stored = get_option(self::OPTION, []);
        return is_array($stored) ? $stored : [];
    }

    private static function mailer_title(string $mailer): string
    {
        try {
            $container = self::container();
            if ('' === $mailer || ! method_exists($container, 'get_providers')) {
                return '';
            }
            $provider = $container->get_providers()->get_options($mailer);
            return is_object($provider) && method_exists($provider, 'get_title') ? (string) $provider->get_title() : '';
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * Mask by key. A boolean is never a credential, and the SMTP 'auth' switch
     * is reported as 'authentication' so redact()'s secret tokens keep it.
     */
    private static function mask(array $values): array
    {
        $out = [];
        foreach ($values as $key => $value) {
            $key = (string) $key;
            if ('auth' === $key) {
                $out['authentication'] = (bool) $value;
            } elseif (in_array($key, self::MASKED_KEYS, true)) {
                $out[ $key ] = Ops_Status_Packs::REDACTED;
            } else {
                $out[ $key ] = is_array($value) ? self::mask($value) : $value;
            }
        }
        return $out;
    }

    /**
     * Scrub by value: every credential WP Mail SMTP holds, stored (the SMTP
     * password as its ciphertext) or as it reads it (decrypted, or from a
     * constant), is replaced wherever it appears in $data.
     */
    private static function scrub(array $data): array
    {
        $secrets = [];
        self::collect(self::stored(), false, $secrets);
        $options = self::options();
        foreach (array_merge(self::mailers(), [ 'license' ]) as $group) {
            self::collect((array) $options->get_group($group), false, $secrets);
        }
        $secrets = array_values(array_unique($secrets));
        if ([] === $secrets) {
            return $data;
        }
        usort($secrets, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        array_walk_recursive($data, static function (&$item) use ($secrets): void {
            if (is_string($item)) {
                $item = str_replace($secrets, Ops_Status_Packs::REDACTED, $item);
            }
        });
        return $data;
    }

    /** @param string[] $secrets */
    private static function collect(array $values, bool $under_secret, array &$secrets): void
    {
        foreach ($values as $key => $value) {
            $secret = $under_secret || in_array((string) $key, self::SECRET_KEYS, true);
            if (is_array($value)) {
                self::collect($value, $secret, $secrets);
            } elseif ($secret && is_string($value) && strlen($value) >= self::SCRUB_MIN) {
                $secrets[] = $value;
            }
        }
    }

    private static function status_label(int $status): string
    {
        return [ 0 => 'failed', 1 => 'sent', 2 => 'waiting', 3 => 'delivered' ][ $status ] ?? 'unknown';
    }
}
