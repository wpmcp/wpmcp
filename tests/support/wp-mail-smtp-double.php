<?php
/**
 * A stand-in for WP Mail SMTP for the WP Mail SMTP status ops on the theme
 * pair (WP_Mail_SMTP_Status), for tests that run without the plugin loaded.
 *
 * Storage is kept the way WP Mail SMTP 4.10 keeps it (src/Options.php): one
 * `wp_mail_smtp` option holding groups (mail, smtp, one group per mailer,
 * general, license, logs ...). Like the plugin:
 *  - Options::init() is a singleton that reads the option once when it is
 *    built and keeps it, re-reading only after its own set(), so a test sees
 *    the same staleness a rollback leaves behind in one request;
 *  - the SMTP password is stored encrypted and get() decrypts it (here a
 *    reversible encoding, so the stored value never equals the password);
 *  - constants (WPMS_MAIL_FROM, WPMS_SMTP_PASS ...) win over stored values
 *    when constants are enabled, which a test turns on through the plugin's
 *    own wp_mail_smtp_options_is_const_enabled filter and Options::$constants;
 *  - set($options, $once, $overwrite_existing) REPLACES the whole option when
 *    $overwrite_existing is true and merges into it when false, re-encrypting
 *    the SMTP password, exactly as the plugin does.
 * wp_mail_smtp() is the plugin container: is_pro(), get_pro()->get_logs()
 * (the Pro email log switch) and get_providers()->get_options($mailer)
 * ->get_title(). The Pro email log collection and its Email rows are the Pro
 * classes' names and methods.
 *
 * Every definition is guarded (with autoloading on) so the real plugin wins
 * wherever it is loaded. WPMS_PLUGIN_VER is never defined here, so the ops
 * count WP Mail SMTP as active only where a test turns
 * wpmcp_wp_mail_smtp_active on.
 */

// phpcs:disable

namespace WPMailSMTP {
    if (! class_exists(Options::class)) {
        class Options
        {
            public const META_KEY = 'wp_mail_smtp';

            /** @var string[] Every mailer but PHP's default 'mail'. */
            public static $mailers = [ 'sendlayer', 'smtpcom', 'sendinblue', 'amazonses', 'gmail', 'mailgun', 'mailjet', 'mailersend', 'mandrill', 'outlook', 'postmark', 'resend', 'sendgrid', 'sparkpost', 'zoho', 'elasticemail', 'smtp2go', 'smtp', 'pepipost', 'pepipostapi' ];

            /** @var array<string, array<string, mixed>> group => key => value, the constants a test defines. */
            public static array $constants = [];

            /** @var array<string, string[]> The plugin's map of constants-aware keys. */
            private const MAP = [
                'mail'     => [ 'from_name', 'from_email', 'mailer', 'return_path', 'from_name_force', 'from_email_force' ],
                'smtp'     => [ 'host', 'port', 'encryption', 'autotls', 'auth', 'user', 'pass' ],
                'sendgrid' => [ 'api_key', 'domain' ],
                'mailgun'  => [ 'api_key', 'domain', 'region' ],
                'outlook'  => [ 'one_click_setup_enabled', 'client_id', 'client_secret' ],
                'postmark' => [ 'server_api_token', 'message_stream' ],
                'license'  => [ 'key' ],
            ];

            private static ?self $instance = null;

            /** @var array<string, array<string, mixed>> */
            protected $options = [];

            public function __construct()
            {
                $this->populate_options();
            }

            public static function init(): self
            {
                if (null === self::$instance) {
                    self::$instance = new self();
                }
                return self::$instance;
            }

            /** Test helper: forget the singleton, as a new request would. */
            public static function reset(): void
            {
                self::$instance  = null;
                self::$constants = [];
            }

            public static function encrypt(string $value): string
            {
                return 'enc:' . base64_encode(strrev($value));
            }

            protected function populate_options(): void
            {
                $options       = get_option(self::META_KEY, []);
                $this->options = is_array($options) ? $options : [];
            }

            public function is_const_enabled(): bool
            {
                return (bool) apply_filters('wp_mail_smtp_options_is_const_enabled', defined('WPMS_ON') && WPMS_ON === true);
            }

            public function is_const_defined($group, $key): bool
            {
                return $this->is_const_enabled() && ! empty(self::$constants[ $group ][ $key ]);
            }

            public function get($group, $key, $strip_slashes = true)
            {
                if ($this->is_const_defined($group, $key)) {
                    return self::$constants[ $group ][ $key ];
                }
                if (isset($this->options[ $group ][ $key ])) {
                    $value = $this->options[ $group ][ $key ];
                    if ('smtp' === $group && 'pass' === $key && is_string($value) && 0 === strpos($value, 'enc:')) {
                        return strrev((string) base64_decode(substr($value, 4)));
                    }
                    return $value;
                }
                if (isset(self::MAP[ $group ]) && in_array($key, self::MAP[ $group ], true)) {
                    return in_array($key, [ 'from_email_force', 'from_name_force', 'return_path', 'auth', 'autotls' ], true) ? false : '';
                }
                return null;
            }

            public function get_group($group): array
            {
                $options = $this->options[ $group ] ?? [];
                foreach (self::MAP[ $group ] ?? [] as $key) {
                    $options[ $key ] = $this->get($group, $key);
                }
                return $options;
            }

            public function get_all_raw(): array
            {
                $options = $this->options;
                foreach ($options as $group => $values) {
                    foreach ((array) $values as $key => $value) {
                        $options[ $group ][ $key ] = $this->get($group, $key, false);
                    }
                }
                return $options;
            }

            public function set($options, $once = false, $overwrite_existing = true): void
            {
                if (! $overwrite_existing) {
                    $options = array_replace_recursive($this->get_all_raw(), $options);
                }
                if (isset($options['mail']['from_name'])) {
                    $options['mail']['from_name'] = sanitize_text_field($options['mail']['from_name']);
                }
                if (isset($options['mail']['from_email'])) {
                    $options['mail']['from_email'] = sanitize_email($options['mail']['from_email']);
                }
                if (isset($options['smtp']['pass']) && ! $this->is_const_defined('smtp', 'pass')) {
                    $options['smtp']['pass'] = self::encrypt(trim((string) $options['smtp']['pass']));
                }
                update_option(self::META_KEY, $options, 'no');
                $this->populate_options();
            }
        }
    }
}

namespace WPMailSMTP\Pro\Emails\Logs {
    if (! class_exists(Email::class)) {
        class Email
        {
            /** @param array<string, mixed> $row */
            public function __construct(private array $row)
            {
            }

            public function get_id(): int
            {
                return (int) $this->row['id'];
            }

            public function get_subject(): string
            {
                return (string) $this->row['subject'];
            }

            public function get_people($type = 'to'): array
            {
                return (array) ($this->row['people'][ $type ] ?? []);
            }

            public function get_status(): int
            {
                return (int) $this->row['status'];
            }

            public function get_error_text(): string
            {
                return (string) ($this->row['error_text'] ?? '');
            }

            public function get_mailer(): string
            {
                return (string) ($this->row['mailer'] ?? '');
            }

            public function get_date_sent(): \DateTime
            {
                return new \DateTime((string) $this->row['date_sent'], new \DateTimeZone('UTC'));
            }
        }
    }

    if (! class_exists(EmailsCollection::class)) {
        class EmailsCollection
        {
            /** @var array<int, array<string, mixed>> The log table's rows. */
            public static array $rows = [];

            /** @var array<int, array<string, mixed>> The params of every collection built. */
            public static array $built = [];

            /** @param array<string, mixed> $params */
            public function __construct(private array $params = [])
            {
                self::$built[] = $params;
            }

            /** @return Email[] newest id first, per_page at a time (default 10). */
            public function get(): array
            {
                $rows = self::$rows;
                usort($rows, static fn (array $a, array $b): int => $b['id'] <=> $a['id']);
                $rows = array_slice($rows, (int) ($this->params['offset'] ?? 0), (int) ($this->params['per_page'] ?? 10));
                return array_map(static fn (array $row): Email => new Email($row), $rows);
            }
        }
    }
}

namespace {
    if (! class_exists('WPMCP_Test_WP_Mail_SMTP')) {
        /** The container wp_mail_smtp() returns, with the Pro and providers surfaces the ops use. */
        class WPMCP_Test_WP_Mail_SMTP
        {
            public static ?self $instance = null;

            public static bool $pro = false;

            public static bool $logs_enabled = false;

            /** @var object|null Set when Pro is loaded, like Core::$pro. */
            public $pro_object = null;

            public function is_pro(): bool
            {
                return self::$pro;
            }

            public function get_pro()
            {
                if (! self::$pro) {
                    return null;
                }
                return new class {
                    public function get_logs(): object
                    {
                        return new class {
                            public function is_enabled(): bool
                            {
                                return WPMCP_Test_WP_Mail_SMTP::$logs_enabled;
                            }
                        };
                    }
                };
            }

            public function get_providers(): object
            {
                return new class {
                    public function get_options($provider, $connection = null): object
                    {
                        $titles = [ 'smtp' => 'Other SMTP', 'sendgrid' => 'SendGrid', 'mail' => 'Default (none)' ];
                        return new class ($titles[ $provider ] ?? '') {
                            public function __construct(private string $title)
                            {
                            }

                            public function get_title(): string
                            {
                                return $this->title;
                            }
                        };
                    }
                };
            }
        }
    }

    if (! function_exists('wp_mail_smtp')) {
        function wp_mail_smtp()
        {
            if (null === WPMCP_Test_WP_Mail_SMTP::$instance) {
                WPMCP_Test_WP_Mail_SMTP::$instance = new WPMCP_Test_WP_Mail_SMTP();
            }
            return WPMCP_Test_WP_Mail_SMTP::$instance;
        }
    }
}
