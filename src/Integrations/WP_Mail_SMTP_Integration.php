<?php

namespace WPMCP\Integrations;

use WPMCP\MCP\Confirmation_Required;
use WPMCP\Tools\Diagnostics\Mail_Check;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * WP Mail SMTP integration (wpmcp/wpmailsmtp-read and -write pair).
 *
 * Built on the mail delivery check behind get-site-health's mail_test (issue
 * #415): Mail_Check already says how wp_mail() would send right now and runs
 * the rate-limited, recipient-restricted test send. This pair adds what only
 * the plugin itself knows:
 *  - get-config: the active mailer, from identity, and the mailer's settings
 *    with every credential replaced by MASK. Secrets are masked by key
 *    (pass, api_key, client_secret, tokens...) AND their stored values are
 *    scrubbed from every string in the answer, so a credential cannot leak
 *    through a renamed or nested key. API keys and passwords never leave the
 *    site.
 *  - get-log: the last N sends from the Pro email log (status, recipients,
 *    error text) when logging is on; a clean logging_enabled:false otherwise.
 *  - send-test: Mail_Check::run(), so transport, from identity and the test
 *    result are reported exactly as get-site-health reports them, with the
 *    same confirm:true requirement before anything is sent.
 *  - set-from (write): change the from name and from address. Snapshot-wrapped
 *    on the wp_mail_smtp option, so rollback-operation restores the prior
 *    identity (and, since the whole option is the snapshot target, the exact
 *    prior credentials, which the snapshot keeps server-side only).
 *
 * Every plugin surface sits behind function_exists / class_exists guards:
 * wp_mail_smtp() (the container), WPMailSMTP\Options, and the Pro
 * WPMailSMTP\Pro\Emails\Logs classes. Without the plugin every op answers
 * integration_unavailable.
 */
class WP_Mail_SMTP_Integration extends Integration_Dispatcher
{
    public const OPTION = 'wp_mail_smtp';

    /** Replacement for every credential value in get-config output. */
    public const MASK = '[redacted]';

    /** Option keys, in any mailer group, whose value is a credential. */
    private const SECRET_KEY_PATTERN = '/(^|_)(pass|password|passwd|key|secret|token|credentials|private|signature)(_|$)|api_key|client_id|access_token|refresh_token|auth_code/i';

    /** Shortest credential value scrubbed from free text; shorter ones would mask unrelated words. */
    private const SCRUB_MIN = 8;

    private const OPTIONS_CLASS   = 'WPMailSMTP\\Options';
    private const LOGS_CLASS      = 'WPMailSMTP\\Pro\\Emails\\Logs\\Logs';
    private const EMAILS_CLASS    = 'WPMailSMTP\\Pro\\Emails\\Logs\\EmailsCollection';

    public function integration(): string
    {
        return 'wpmailsmtp';
    }

    public function is_available(): bool
    {
        return function_exists('wp_mail_smtp') && class_exists(self::OPTIONS_CLASS);
    }

    protected function summary(): string
    {
        return 'WP Mail SMTP (active mailer and masked configuration, Pro email log, test send, from identity)';
    }

    protected function operations(): array
    {
        return [
            'get-config' => [
                'mode'         => 'read',
                'capability'   => 'manage_options',
                'description'  => 'Read the active mailer, from name and address, and the mailer settings with every credential masked (passwords, API keys and tokens are never returned). all_mailers:true adds the other mailers\' masked settings',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [ 'all_mailers' => [ 'type' => 'boolean' ] ],
                ],
                'handler'      => fn (array $args): array => [ 'config' => $this->config(true === ($args['all_mailers'] ?? false)) ],
            ],
            'get-log' => [
                'mode'         => 'read',
                'capability'   => 'manage_options',
                'description'  => 'Read the last N sends from the WP Mail SMTP Pro email log (subject, recipients, status, error text, date); logging_enabled:false when the Pro log is off or absent',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [ 'limit' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 100 ] ],
                ],
                'handler'      => fn (array $args): array => $this->log((int) ($args['limit'] ?? 10)),
            ],
            'send-test' => [
                'mode'         => 'read',
                'capability'   => 'manage_options',
                'description'  => 'Report how mail is sent right now (transport, from identity, plugins involved) and, with confirm:true, send a test email to your own or the admin address through the active mailer and report the transport result. Same limits as get-site-health mail_test',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'recipient' => [ 'type' => 'string' ],
                        'confirm'   => [ 'type' => 'boolean' ],
                    ],
                    'required'   => [ 'recipient' ],
                ],
                'handler'      => function (array $args): array {
                    $check     = new Mail_Check(static fn (): int => time());
                    $recipient = (string) $args['recipient'];
                    try {
                        $report = $check->run($recipient, true === ($args['confirm'] ?? false));
                    } catch (Confirmation_Required $e) {
                        // Unconfirmed: report the transport, send nothing, and
                        // say what confirm:true would do (Mail_Check's rule).
                        $report         = $check->run('', false);
                        $report['test'] = [ 'status' => 'confirmation_required', 'recipient' => $recipient, 'message' => $e->getMessage() ];
                    }
                    return $this->scrub_secrets($report);
                },
            ],
            'set-from' => [
                'mode'         => 'write',
                'capability'   => 'manage_options',
                'description'  => 'Change the from name and/or from email address WP Mail SMTP applies to outgoing mail. Snapshot-wrapped on the wp_mail_smtp option, so rollback-operation restores the prior identity',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'from_email' => [ 'type' => 'string' ],
                        'from_name'  => [ 'type' => 'string', 'maxLength' => 200 ],
                    ],
                    'minProperties' => 1,
                ],
                'validate'     => static function (array $args): ?array {
                    if (array_key_exists('from_email', $args) && ! is_email((string) $args['from_email'])) {
                        return [ 'code' => 'invalid_args', 'message' => 'from_email must be a valid email address.' ];
                    }
                    if (! array_key_exists('from_email', $args) && ! array_key_exists('from_name', $args)) {
                        return [ 'code' => 'invalid_args', 'message' => 'Pass from_email, from_name, or both.' ];
                    }
                    return null;
                },
                'snapshot'     => static fn (array $args): array => [ 'object_type' => 'option', 'object_id' => self::OPTION ],
                'handler'      => fn (array $args): array => $this->set_from($args),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function config(bool $all_mailers): array
    {
        $options = $this->options();
        $mailer  = (string) $options->get('mail', 'mailer');
        $stored  = $this->stored();

        $out = [
            'mailer'          => $mailer,
            'mailer_title'    => $this->mailer_title($mailer),
            'from_email'      => (string) $options->get('mail', 'from_email'),
            'from_name'       => (string) $options->get('mail', 'from_name'),
            'from_email_forced' => (bool) $options->get('mail', 'from_email_force'),
            'from_name_forced'  => (bool) $options->get('mail', 'from_name_force'),
            'return_path'     => (bool) $options->get('mail', 'return_path'),
            'mailer_settings' => $this->mask_group((array) ($stored[ $mailer ] ?? [])),
        ];

        if ($all_mailers) {
            $others = [];
            foreach ($stored as $group => $values) {
                if (in_array($group, [ 'mail', 'general', 'license', 'logs', 'alert_email', 'alert_slack_webhook', 'alert_teams_webhook', 'alert_discord_webhook', 'alert_custom_webhook', 'alert_push_notifications', 'alert_events' ], true) || ! is_array($values)) {
                    continue;
                }
                $others[ $group ] = $this->mask_group($values);
            }
            $out['all_mailers'] = $others;
        }

        return $this->scrub_secrets($out);
    }

    /** @return array<string, mixed> */
    private function log(int $limit): array
    {
        $enabled = class_exists(self::LOGS_CLASS)
            && method_exists(self::LOGS_CLASS, 'is_enabled')
            && (bool) call_user_func([ self::LOGS_CLASS, 'is_enabled' ]);

        if (! $enabled || ! class_exists(self::EMAILS_CLASS)) {
            return [ 'logging_enabled' => false, 'emails' => [], 'hint' => 'Email logging needs WP Mail SMTP Pro with the log enabled under its Email Log settings.' ];
        }

        $class  = self::EMAILS_CLASS;
        $emails = [];
        foreach ((new $class([ 'number' => max(1, min(100, $limit)), 'offset' => 0, 'order' => 'DESC' ]))->get() as $email) {
            $status = method_exists($email, 'get_status') ? (int) $email->get_status() : -1;
            $date   = method_exists($email, 'get_date_sent') ? $email->get_date_sent() : null;
            $emails[] = [
                'id'         => method_exists($email, 'get_id') ? (int) $email->get_id() : null,
                'subject'    => method_exists($email, 'get_subject') ? (string) $email->get_subject() : '',
                'to'         => method_exists($email, 'get_people') ? array_values((array) $email->get_people('to')) : [],
                'status'     => self::status_label($status),
                'error'      => method_exists($email, 'get_error_text') ? (string) $email->get_error_text() : '',
                'mailer'     => method_exists($email, 'get_mailer') ? (string) $email->get_mailer() : '',
                'date_sent'  => $date instanceof \DateTimeInterface ? $date->format('Y-m-d H:i:s') : (string) $date,
            ];
        }

        return $this->scrub_secrets([ 'logging_enabled' => true, 'emails' => $emails ]);
    }

    /** @return array<string, mixed> */
    private function set_from(array $args): array
    {
        $options = $this->options();
        $mail    = [];
        if (array_key_exists('from_email', $args)) {
            $mail['from_email'] = sanitize_email((string) $args['from_email']);
        }
        if (array_key_exists('from_name', $args)) {
            $mail['from_name'] = sanitize_text_field((string) $args['from_name']);
        }

        if (method_exists($options, 'set')) {
            $options->set([ 'mail' => $mail ], false, true);
        } else {
            $stored         = $this->stored();
            $stored['mail'] = $mail + (array) ($stored['mail'] ?? []);
            update_option(self::OPTION, $stored);
        }

        $fresh = $this->options();
        return [
            'from_email' => (string) $fresh->get('mail', 'from_email'),
            'from_name'  => (string) $fresh->get('mail', 'from_name'),
        ];
    }

    private function options(): object
    {
        return call_user_func([ self::OPTIONS_CLASS, 'init' ]);
    }

    /** The raw stored option, the one source that has every group. */
    private function stored(): array
    {
        $stored = get_option(self::OPTION, []);
        return is_array($stored) ? $stored : [];
    }

    private function mailer_title(string $mailer): string
    {
        if ('' === $mailer || ! function_exists('wp_mail_smtp')) {
            return '';
        }
        try {
            $container = wp_mail_smtp();
            if (! is_object($container) || ! method_exists($container, 'get_providers')) {
                return '';
            }
            $options = $container->get_providers()->get_options($mailer);
            return is_object($options) && method_exists($options, 'get_title') ? (string) $options->get_title() : '';
        } catch (\Throwable $e) {
            return '';
        }
    }

    /** Mask by key: every credential-looking key, at any depth, becomes MASK. */
    private function mask_group(array $values): array
    {
        $out = [];
        foreach ($values as $key => $value) {
            if (preg_match(self::SECRET_KEY_PATTERN, (string) $key)) {
                $out[ $key ] = self::MASK;
            } elseif (is_array($value)) {
                $out[ $key ] = $this->mask_group($value);
            } else {
                $out[ $key ] = $value;
            }
        }
        return $out;
    }

    /** Scrub by value: the stored credentials themselves, wherever they appear in the answer. */
    private function scrub_secrets(array $data): array
    {
        $secrets = [];
        self::collect_secret_values($this->stored(), false, $secrets);
        if ([] === $secrets) {
            return $data;
        }
        usort($secrets, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        $walk = static function (&$item) use ($secrets): void {
            if (is_string($item)) {
                $item = str_replace($secrets, self::MASK, $item);
            }
        };
        array_walk_recursive($data, $walk);
        return $data;
    }

    /** @param array<int, string> $secrets */
    private static function collect_secret_values(array $values, bool $under_secret_key, array &$secrets): void
    {
        foreach ($values as $key => $value) {
            $secret = $under_secret_key || preg_match(self::SECRET_KEY_PATTERN, (string) $key);
            if (is_array($value)) {
                self::collect_secret_values($value, (bool) $secret, $secrets);
            } elseif ($secret && is_string($value) && strlen($value) >= self::SCRUB_MIN) {
                $secrets[] = $value;
            }
        }
    }

    private static function status_label(int $status): string
    {
        return match ($status) {
            0 => 'failed',
            1 => 'sent',
            2 => 'waiting',
            3 => 'delivered',
            default => 'unknown',
        };
    }
}
