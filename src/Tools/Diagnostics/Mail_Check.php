<?php

namespace WPMCP\Tools\Diagnostics;

use WPMCP\Governance\Governance_Audit_Log;
use WPMCP\MCP\Confirmation_Required;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The mail delivery check behind get-site-health's mail_test (issue #415).
 *
 * The report says how wp_mail() would send right now, found generically
 * rather than from a list of known mail plugins:
 *  - a plugin that replaces the pluggable wp_mail() or hooks pre_wp_mail
 *    sends by its own route (usually an HTTP API), reported as "plugin";
 *  - otherwise the transport is what PHPMailer is left with after
 *    phpmailer_init runs on a scratch instance, exactly as wp_mail() runs it:
 *    mail (PHP mail()), sendmail, qmail or smtp. The scratch instance is
 *    never sent.
 * The plugins (or theme, or must-use plugin) owning those callbacks are
 * named from the callback's source file. The From address and name are the
 * ones wp_mail() would use: core's default, the wp_mail_from filters, then
 * whatever phpmailer_init set. SMTP host, port, encryption and whether it
 * authenticates are reported; the username and password never are, and are
 * scrubbed from a failure message should a transport echo them.
 *
 * A non-empty mail_test sends one plain test message with wp_mail(), and only
 * to the caller's own address or the site admin email, so the tool cannot mail
 * arbitrary recipients. Sending needs manage_options (the report only needs
 * the ability's view_site_health_checks), confirm:true, and at most
 * LIMIT sends per user per WINDOW seconds. Every send, failure and refusal is
 * recorded in the governance audit log, with the recipient's role (self or
 * admin), never the address.
 */
class Mail_Check
{
    public const ABILITY = 'wpmcp/get-site-health';

    public const LIMIT  = 5;
    public const WINDOW = HOUR_IN_SECONDS;

    private const LIMIT_KEY = 'wpmcp_mail_test_';

    private const TRANSPORTS = [ 'mail', 'sendmail', 'qmail', 'smtp' ];

    /** @var callable(): float */
    private $clock;

    /** @var string[] Credential values seen on the scratch mailer, scrubbed from errors. */
    private array $secrets = [];

    public function __construct(callable $clock)
    {
        $this->clock = $clock;
    }

    public static function reset_limit(int $user_id): void
    {
        delete_transient(self::LIMIT_KEY . $user_id);
    }

    public function run(string $recipient, bool $confirm): array
    {
        $report         = $this->report();
        $recipient      = strtolower(trim($recipient));
        $report['test'] = '' === $recipient ? null : $this->send($recipient, $confirm);

        $report['next_steps'] = self::next_steps($report);

        return $report;
    }

    private function report(): array
    {
        $from_name  = 'WordPress';
        $site       = self::site_domain();
        $from_email = 'wordpress@' . $site;

        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- applying core's wp_mail() filter to report the effective From, not defining one.
        $from_email = (string) apply_filters('wp_mail_from', $from_email);
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- applying core's wp_mail() filter to report the effective From, not defining one.
        $from_name = (string) apply_filters('wp_mail_from_name', $from_name);

        $mailer = self::scratch_mailer();
        $type   = 'mail';
        $smtp   = null;
        if (null !== $mailer) {
            try {
                $mailer->setFrom($from_email, $from_name, false);
            } catch (\Throwable $e) {
                // An invalid From is reported as filtered; the test send surfaces the error.
                unset($e);
            }

            try {
                // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- running core's hook on a scratch mailer, as wp_mail() does, to see what mail plugins configure.
                do_action_ref_array('phpmailer_init', [ &$mailer ]);
            } catch (\Throwable $e) {
                unset($e);
            }

            $type = strtolower((string) $mailer->Mailer);
            if ('' !== (string) $mailer->From) {
                $from_email = (string) $mailer->From;
                $from_name  = (string) $mailer->FromName;
            }
            if ('smtp' === $type) {
                $smtp = [
                    'host'       => (string) $mailer->Host,
                    'port'       => (int) $mailer->Port,
                    'encryption' => '' === (string) $mailer->SMTPSecure ? 'none' : (string) $mailer->SMTPSecure,
                    'auth'       => (bool) $mailer->SMTPAuth,
                ];
            }
            $this->secrets = array_values(array_filter(
                [ (string) $mailer->Username, (string) $mailer->Password ],
                static fn (string $value): bool => strlen($value) >= 3
            ));
        }

        $replaced  = self::wp_mail_replaced();
        $transport = $replaced || has_filter('pre_wp_mail') ? 'plugin' : (in_array($type, self::TRANSPORTS, true) ? $type : 'mail');

        return [
            'transport'   => $transport,
            'smtp'        => 'smtp' === $transport ? $smtp : null,
            'plugins'     => self::plugins($replaced),
            'from'        => [ 'email' => $from_email, 'name' => $from_name ],
            'site_domain' => $site,
        ];
    }

    private function send(string $recipient, bool $confirm): array
    {
        if (! current_user_can('manage_options')) {
            throw new \RuntimeException('Sending a test email needs manage_options.');
        }

        $user  = wp_get_current_user();
        $self  = strtolower((string) $user->user_email);
        $admin = strtolower((string) get_option('admin_email'));
        if ('' !== $self && $recipient === $self) {
            $role = 'self';
        } elseif ('' !== $admin && $recipient === $admin) {
            $role = 'admin';
        } else {
            Governance_Audit_Log::record_quietly(self::ABILITY, false, 'mail-test:refused-recipient');
            throw new \InvalidArgumentException('mail_test only sends to your own email address or the site admin email.');
        }

        $key    = self::LIMIT_KEY . (int) $user->ID;
        $now    = ($this->clock)();
        $stored = get_transient($key);
        $stamps = array_values(array_filter(
            is_array($stored) ? $stored : [],
            static fn ($stamp): bool => is_numeric($stamp) && (float) $stamp > $now - self::WINDOW
        ));
        if (count($stamps) >= self::LIMIT) {
            Governance_Audit_Log::record_quietly(self::ABILITY, false, 'mail-test:rate-limited');
            throw new \RuntimeException(esc_html(sprintf('Test email limit reached (%d per hour per user). Try again later.', self::LIMIT)));
        }

        if (! $confirm) {
            throw new Confirmation_Required('Sending a test email requires confirm:true.');
        }

        $stamps[] = $now;
        set_transient($key, $stamps, self::WINDOW);

        $error    = null;
        $listener = static function ($failure) use (&$error): void {
            if ($failure instanceof \WP_Error) {
                $error = $failure->get_error_message();
            }
        };
        add_action('wp_mail_failed', $listener);
        try {
            $sent = (bool) wp_mail(
                $recipient,
                sprintf('Test email from %s', wp_specialchars_decode((string) get_option('blogname'), ENT_QUOTES)),
                sprintf(
                    "This is a test message from the site health mail check on %s, sent at %s UTC.\n\nIt confirms the site can send email. No action is needed.",
                    home_url('/'),
                    gmdate('Y-m-d H:i:s')
                )
            );
        } catch (\Throwable $e) {
            $sent  = false;
            $error = $e->getMessage();
        } finally {
            remove_action('wp_mail_failed', $listener);
        }

        Governance_Audit_Log::record_quietly(self::ABILITY, $sent, sprintf('mail-test:%s:%s', $sent ? 'sent' : 'failed', $role));

        if ($sent) {
            return [
                'sent'      => true,
                'recipient' => $recipient,
                'error'     => null,
                'note'      => 'The transport accepted the message; delivery is confirmed only when it arrives.',
            ];
        }

        return [
            'sent'      => false,
            'recipient' => $recipient,
            'error'     => $this->scrub(null === $error || '' === $error ? 'wp_mail() returned false without an error: a plugin blocked or short-circuited the send.' : $error),
        ];
    }

    /** @return string[] */
    private static function next_steps(array $report): array
    {
        $steps = [];

        if (in_array($report['transport'], [ 'mail', 'sendmail', 'qmail' ], true)) {
            $steps[] = 'Mail goes out through the server\'s own mailer (PHP mail() or sendmail), which many hosts block or which lands in spam. Install and configure an SMTP or transactional mail plugin.';
        }

        $from_domain = strtolower((string) substr((string) strrchr((string) $report['from']['email'], '@'), 1));
        $site        = strtolower((string) $report['site_domain']);
        if ('' !== $from_domain && '' !== $site && ! self::same_domain($from_domain, $site)) {
            $steps[] = sprintf(
                'The From address domain (%s) does not match the site domain (%s). Receiving servers may reject or spam-filter it unless SPF, DKIM and DMARC for %s allow this sender.',
                $from_domain,
                $site,
                $from_domain
            );
        }

        $test = $report['test'];
        if (null === $test) {
            $steps[] = 'To verify sending, pass mail_test set to your own email address or the site admin email, with confirm:true.';
        } elseif ($test['sent']) {
            $steps[] = 'If the test email does not arrive, check the spam folder, then the mail plugin\'s log, then the SPF, DKIM and DMARC records of the From domain.';
        } else {
            $steps[] = 'Fix the error above and send again. With an SMTP or mail service plugin, check its host, port, encryption and credentials in its settings.';
        }

        return $steps;
    }

    private static function same_domain(string $a, string $b): bool
    {
        $a = (string) preg_replace('#^www\.#', '', $a);
        $b = (string) preg_replace('#^www\.#', '', $b);

        return $a === $b || str_ends_with($a, '.' . $b) || str_ends_with($b, '.' . $a);
    }

    private function scrub(string $message): string
    {
        foreach ($this->secrets as $secret) {
            $message = str_replace($secret, '[redacted]', $message);
        }
        return $message;
    }

    private static function site_domain(): string
    {
        $host = (string) wp_parse_url(network_home_url(), PHP_URL_HOST);
        return (string) preg_replace('#^www\.#', '', $host);
    }

    /** A PHPMailer configured the way wp_mail() creates one, or null when unavailable. */
    private static function scratch_mailer(): ?object
    {
        if (! class_exists('WP_PHPMailer') && is_readable(ABSPATH . WPINC . '/class-wp-phpmailer.php')) {
            require_once ABSPATH . WPINC . '/PHPMailer/PHPMailer.php';
            require_once ABSPATH . WPINC . '/PHPMailer/SMTP.php';
            require_once ABSPATH . WPINC . '/PHPMailer/Exception.php';
            require_once ABSPATH . WPINC . '/class-wp-phpmailer.php';
        }
        if (class_exists('WP_PHPMailer')) {
            return new \WP_PHPMailer(true);
        }

        if (! class_exists('PHPMailer\\PHPMailer\\PHPMailer') && is_readable(ABSPATH . WPINC . '/PHPMailer/PHPMailer.php')) {
            require_once ABSPATH . WPINC . '/PHPMailer/PHPMailer.php';
            require_once ABSPATH . WPINC . '/PHPMailer/SMTP.php';
            require_once ABSPATH . WPINC . '/PHPMailer/Exception.php';
        }
        if (class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
            return new \PHPMailer\PHPMailer\PHPMailer(true);
        }

        return null;
    }

    private static function wp_mail_replaced(): bool
    {
        $file = self::function_file('wp_mail');
        return null !== $file && wp_normalize_path(ABSPATH . WPINC . '/pluggable.php') !== $file;
    }

    /**
     * The plugins, must-use plugins or themes whose callbacks decide how mail
     * is sent, with the hooks each one uses.
     *
     * @return array<int, array{plugin: string, name: string, hooks: string[]}>
     */
    private static function plugins(bool $replaced): array
    {
        $files = [];
        if ($replaced) {
            $files[] = [ (string) self::function_file('wp_mail'), 'wp_mail (replaced)' ];
        }

        global $wp_filter;
        foreach ([ 'pre_wp_mail', 'phpmailer_init' ] as $hook) {
            if (! isset($wp_filter[ $hook ]) || ! $wp_filter[ $hook ] instanceof \WP_Hook) {
                continue;
            }
            foreach ($wp_filter[ $hook ]->callbacks as $callbacks) {
                foreach ($callbacks as $callback) {
                    $file = self::callback_file($callback['function'] ?? null);
                    if (null !== $file) {
                        $files[] = [ $file, $hook ];
                    }
                }
            }
        }

        $owners = [];
        foreach ($files as [ $file, $hook ]) {
            $owner = self::owner($file);
            if (null === $owner) {
                continue;
            }
            $id = $owner['plugin'];
            if (! isset($owners[ $id ])) {
                $owners[ $id ] = $owner + [ 'hooks' => [] ];
            }
            if (! in_array($hook, $owners[ $id ]['hooks'], true)) {
                $owners[ $id ]['hooks'][] = $hook;
            }
        }

        return array_values($owners);
    }

    /** @param mixed $callback */
    private static function callback_file($callback): ?string
    {
        try {
            if (is_string($callback) && false !== strpos($callback, '::')) {
                $callback = explode('::', $callback, 2);
            }
            if (is_array($callback) && 2 === count($callback)) {
                $reflection = new \ReflectionMethod($callback[0], (string) $callback[1]);
            } elseif ($callback instanceof \Closure || is_string($callback)) {
                $reflection = new \ReflectionFunction($callback);
            } elseif (is_object($callback) && method_exists($callback, '__invoke')) {
                $reflection = new \ReflectionMethod($callback, '__invoke');
            } else {
                return null;
            }
        } catch (\ReflectionException $e) {
            return null;
        }

        $file = $reflection->getFileName();
        return false === $file ? null : wp_normalize_path($file);
    }

    private static function function_file(string $function): ?string
    {
        if (! function_exists($function)) {
            return null;
        }
        return self::callback_file($function);
    }

    /** @return array{plugin: string, name: string}|null Null for core and unattributable files. */
    private static function owner(string $file): ?array
    {
        $plugins_dir = trailingslashit(wp_normalize_path(WP_PLUGIN_DIR));
        $mu_dir      = trailingslashit(wp_normalize_path(WPMU_PLUGIN_DIR));
        $themes_dir  = trailingslashit(wp_normalize_path(get_theme_root()));

        if (0 === strpos($file, $mu_dir)) {
            $relative = substr($file, strlen($mu_dir));
            $first    = (string) strtok($relative, '/');
            if (! function_exists('get_mu_plugins')) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
            }
            $mu = get_mu_plugins();
            return [
                'plugin' => 'mu-plugins/' . $first,
                'name'   => isset($mu[ $first ]['Name']) && '' !== $mu[ $first ]['Name'] ? (string) $mu[ $first ]['Name'] : $first,
            ];
        }

        if (0 === strpos($file, $plugins_dir)) {
            $relative = substr($file, strlen($plugins_dir));
            $slug     = false === strpos($relative, '/') ? $relative : (string) strtok($relative, '/');
            if (! function_exists('get_plugins')) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
            }
            $name = $slug;
            foreach (get_plugins() as $basename => $data) {
                if ($basename === $slug || 0 === strpos($basename, $slug . '/')) {
                    $name = '' !== (string) ($data['Name'] ?? '') ? (string) $data['Name'] : $slug;
                    break;
                }
            }
            return [ 'plugin' => $slug, 'name' => $name ];
        }

        if (0 === strpos($file, $themes_dir)) {
            $stylesheet = (string) strtok(substr($file, strlen($themes_dir)), '/');
            $theme      = wp_get_theme($stylesheet);
            return [
                'plugin' => 'theme/' . $stylesheet,
                'name'   => $theme->exists() ? (string) $theme->get('Name') : $stylesheet,
            ];
        }

        return null;
    }
}
