<?php

namespace WPMCP\Tools\Meta;

use WPMCP\Tools\WooCommerce\Gateway_Secrets;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The central registry of options that hold credentials (issue #865), so a
 * generic option read masks them the way the dedicated settings tools do.
 *
 * Gateway_Secrets (issues #292, #507) already masks a payment gateway's
 * settings option. This class adds the same treatment for every other
 * plugin: the generic readers (get-option, update-option's response, the
 * database query tool's wp_options rows) pass a value through
 * mask_option(), so a credential a plugin's own settings read hides is not
 * available in plain text from another tool. A new integration adds its
 * option names here (or through the wpmcp_secret_options filter) instead of
 * growing a second masking rule.
 *
 * Three classes of secret, in order:
 *  1. A registered option that is a credential whole (an API key stored as
 *     its own option): the whole value reads as the mask.
 *  2. A registered option that holds credentials as keys inside an array
 *     (WP Rocket's settings, WP Mail SMTP's options): those keys read as the
 *     mask, at any depth, the rest stays readable. A key is a bare name
 *     (matched at any depth) or a dotted path from the top, where "*"
 *     matches one segment ("license.key").
 *  3. The key-name heuristic, for every option nobody registered: a key
 *     inside an option array named like *api_key*, *secret*, *password*,
 *     *token* or *license_key* is masked, unless the wpmcp_secret_key_allowlist
 *     filter lists the key name. The heuristic masks string values only
 *     (a "require_password" flag or "max_tokens" number is not a credential). The allowlist
 *     never unmasks a registered credential.
 *
 * The mask is Gateway_Secrets::MASK, and an empty credential stays empty so
 * "not set" is still visible. A masked value written back unchanged means
 * "keep what is stored" (unmask_option()), so a read, edited, then written
 * whole never overwrites a live credential with the mask.
 *
 * Only returned copies change: snapshots and the stored option stay raw, so
 * a rollback restores the exact credential.
 *
 * Verification marks in the registry: every name below was checked against
 * the plugin's source (a licensed copy, or the wordpress.org release read
 * from a scratch folder) unless its line says "UNVERIFIED".
 */
final class Secret_Options
{
    /** What a non-empty credential reads as: the same mask every other read path uses. */
    public const MASK = Gateway_Secrets::MASK;

    /** Key names the heuristic treats as credentials. */
    public const KEY_PATTERN = '/(api[_-]?key|secret|passw(or)?d|token|license[_-]?key)/i';

    /**
     * An option's value with credentials masked: a payment gateway's
     * settings through Gateway_Secrets, then the registry and the heuristic.
     *
     * @param mixed $value
     * @return mixed
     */
    public static function mask_option(string $name, $value)
    {
        return self::mask_registered($name, Gateway_Secrets::mask_option($name, $value));
    }

    /**
     * The registry and heuristic layer alone (no gateway rule), for a caller
     * that applies the gateway rule itself.
     *
     * @param mixed $value
     * @return mixed
     */
    public static function mask_registered(string $name, $value)
    {
        if (self::is_unset($value)) {
            return $value;
        }
        $spec = self::spec_for($name);
        if ($spec['whole']) {
            return self::MASK;
        }
        $decoded = Gateway_Secrets::decode($value);
        if (null === $decoded) {
            // A registered option whose value holds no array has nothing that
            // tells which part is a credential: mask it whole. An unregistered
            // one is none of our business.
            return $spec['registered'] ? self::MASK : $value;
        }
        [ $data, $layers ] = $decoded;
        $masked = self::mask_tree($data, $spec, self::allowlist(), []);
        return $masked === $data ? $value : Gateway_Secrets::encode($masked, $layers);
    }

    /**
     * An option_value cell of a wp_options row with credentials masked, as a
     * string (the form a database row carries).
     */
    public static function mask_row_value(string $name, string $value): string
    {
        $masked = self::mask_registered($name, $value);
        return is_string($masked) ? $masked : self::MASK;
    }

    /**
     * A new value for an option with every credential that still reads as the
     * mask put back to what is stored, so writing back a masked read keeps
     * the live credential. Gateway settings first, then the registry and the
     * heuristic.
     *
     * @param mixed $value
     * @return mixed
     */
    public static function unmask_option(string $name, $value)
    {
        $value   = Gateway_Secrets::unmask_option($name, $value);
        $spec    = self::spec_for($name);
        $missing = new \stdClass();
        $stored  = get_option($name, $missing);

        if (Gateway_Secrets::is_masked($value)) {
            return ($spec['registered'] && $missing !== $stored) ? $stored : $value;
        }
        $decoded = Gateway_Secrets::decode($value);
        if (null === $decoded || $spec['whole']) {
            return $value;
        }
        [ $data, $layers ] = $decoded;
        $current  = ($missing === $stored ? null : Gateway_Secrets::decode_settings($stored)) ?? [];
        $restored = self::restore_tree($data, $current, $spec, self::allowlist(), []);
        return $restored === $data ? $value : Gateway_Secrets::encode($restored, $layers);
    }

    /**
     * True when an option name is in the registry (a credential whole, or an
     * array holding credential keys). The heuristic does not count.
     */
    public static function is_registered(string $name): bool
    {
        return self::spec_for($name)['registered'];
    }

    /**
     * The registry: option name (or a "/regex/" for a family of names) to
     * true (the whole value is a credential) or a list of credential key
     * names and dotted paths inside the option array. Extend it with the
     * wpmcp_secret_options filter.
     *
     * @return array<string, true|array<int, string>>
     */
    public static function registry(): array
    {
        $registry = self::defaults();
        $filtered = apply_filters('wpmcp_secret_options', $registry);
        return is_array($filtered) ? $filtered : $registry;
    }

    /**
     * @return array<string, true|array<int, string>>
     */
    private static function defaults(): array
    {
        return [
            // WP Rocket and FlyingPress: the same lists their settings reads mask
            // (secret_keys() of the cache settings adapters, which a test keeps
            // in step with these). Literal names: the adapters are not part of
            // every build, so this class must not name them.
            'wp_rocket_settings' => [ 'consumer_key', 'consumer_email', 'secret_key', 'license', 'secret_cache_key', 'minify_css_key', 'minify_js_key', 'cloudflare_api_key', 'cloudflare_email', 'cloudflare_zone_id', 'sucury_waf_api_key' ],
            'FLYING_PRESS_CONFIG' => [ 'license_key', 'flying_cdn_api_key', 'cf_api_key', 'cf_email', 'cf_zone_id', 'cf_host_signature' ],

            // WP Mail SMTP. Option "wp_mail_smtp" (Options::META_KEY) is a map of mailer
            // groups; its credential keys are the ones in Options::$map. "pass" and the
            // license "key" do not look secret to the heuristic. OAuth tokens sit under
            // the gmail/outlook/zoho groups as access_token and refresh_token. The SMTP
            // login ("user") is the other half of the credential and is masked too, as the
            // plugin's status read masks it (issue #939).
            'wp_mail_smtp'          => [ 'user', 'pass', 'client_secret', 'api_key', 'secret_key', 'server_api_token', 'access_token', 'refresh_token', 'auth_code', 'license.key' ],
            // The key that encrypts the stored credentials (Helpers\Crypto).
            'wp_mail_smtp_mail_key' => true,

            // FluentSMTP 2.4 (read from the wordpress.org release). One option holds every
            // connection's provider settings (connections.<key>.provider_settings) and is
            // read through fluentMailGetSettings(), which decrypts. The names are the
            // plugin's own SecretMasker::SECRET_FIELDS (password, api_key, secret_key,
            // access_key, client_secret), its WITHHELD_FIELDS (access_token, refresh_token)
            // and the auth_token one-time code, plus names the heuristic would miss.
            'fluentmail-settings'   => [ 'password', 'api_key', 'access_key', 'secret_key', 'client_secret', 'access_token', 'refresh_token', 'auth_token', 'auth_key', 'private_key', 'token' ],
            // The alert channels (Settings::notificationSettings): telegram.token, slack.token,
            // slack.webhook_url and discord.webhook_url. A webhook URL is a bearer credential.
            '_fluent_smtp_notify_settings' => [ 'token', 'site_token', 'webhook_url', 'api_token', 'user_key' ],
            // Retired: current releases delete it on the next admin or cron request (it held the
            // Outlook client id, secret and tenant in plain text). Older installs may still hold it.
            '_fluentsmtp_intended_outlook_info' => true,

            // Post SMTP 4.0 (read from the wordpress.org release). "postman_options" holds
            // every transport's credential (the PostmanOptions consts); the API keys and the
            // basic auth password are stored base64 encoded, not encrypted. The email log is
            // the post_smtp_logs table, not an option. "postman_state" holds only counters.
            'postman_options'       => [ 'basic_auth_password', 'fallback_smtp_password', 'oauth_client_secret', 'mandrill_api_key', 'sendgrid_api_key', 'mailersend_api_key', 'sendinblue_api_key', 'mailtrap_api_key', 'resend_api_key', 'cloudflare_api_token', 'smtpcom_api_key', 'mailjet_api_key', 'mailjet_secret_key', 'sendpulse_api_key', 'sendpulse_secret_key', 'postmark_api_key', 'sparkpost_api_key', 'mailgun_api_key', 'elasticemail_api_key', 'emailit_api_key', 'maileroo_api_key', 'sweego_api_key', 'smtp2go_api_key' ],
            // OAuth token pairs (PostmanOAuthToken::OPTIONS_NAME, the Office 365 wizard).
            'postman_auth_token'    => true,
            'postman_office365_oauth' => true,
            // Keyed by each paired device's auth (FCM) token, so the keys are the secret.
            'post_smtp_mobile_app_connection' => true,

            // UpdraftPlus: one option per remote storage service, instance settings nested inside.
            // "dreamhost", "sftp_settings" and "webdav_settings" are the pre-2017 names its
            // migration code still reads (class-updraftplus.php, methods/webdav.php).
            // The login ("user" in FTP, SFTP, Cloud Files and OpenStack; "username" or
            // "login" where an add-on names it so) is the other half of the credential
            // (issue #939).
            '/^updraft_(s3|s3generic|dreamobjects|dreamhost|dropbox|googledrive|onedrive|ftp|sftp|sftp_settings|webdav|webdav_settings|cloudfiles|openstack|azure|googlecloud|backblaze|pcloud|updraftvault)$/i' => [ 'user', 'username', 'login', 'accesskey', 'secretkey', 'sessiontoken', 'apikey', 'pass', 'password', 'key', 'secret', 'token', 'access_token', 'refresh_token', 'auth_token', 'appkey' ],
            // A WebDAV URL carries the user and password (webdavs://user:pass@host/path).
            '/^updraft_webdav(_settings)?$/i'   => [ 'url' ],
            // Pre-2017 flat credential options, still read and migrated by UpdraftPlus
            // (class-updraftplus.php, methods/ftp.php), so an old site can still hold them.
            '/^updraft_((s3|s3generic|dreamobjects)_(login|pass)|ftp_(login|pass)|googledrive_secret|googledrive_token|dropboxtk_access_token|cloudfiles_apikey)$/i' => true,
            'updraft_encryptionphrase'          => true,
            'updraft_dropboxtk_request_token'   => true,
            'updraft_migrator_localkeys'        => true,
            'updraft_central_localkeys'         => true,
            'updraft_remotesites'               => [ 'key' ],

            // BackWPup: every job's settings live in one option; the global settings are one option each.
            'backwpup_jobs'                     => [ 'dbpassword', 'ftppass', 'ftpsshprivkey', 's3secretkey', 'rscapikey', 'glaciersecretkey', 'mailpass', 'dropboxtoken', 'dropboxsecret', 'msazurekey', 'sugarpass', 'sugarrefreshtoken', 'hidrivetoken', 'dropetoken', 'dropesecret', 'dropepass' ],
            'backwpup_cfg_dropboxappsecret'     => true,
            'backwpup_cfg_dropboxsandboxappsecret' => true,
            'backwpup_cfg_easycronapikey'       => true,
            'backwpup_cfg_encryptionkey'        => true,
            'backwpup_cfg_googleclientsecret'   => true,
            'backwpup_cfg_httpauthpassword'     => true,
            'backwpup_cfg_jobrunauthkey'        => true,
            'backwpup_cfg_onedriveclientsecret' => true,
            'backwpup_cfg_sugarsynckey'         => true,
            'backwpup_cfg_sugarsyncsecret'      => true,

            // Image optimizers.
            'imagify_settings'                  => [ 'api_key' ],
            // ShortPixel stores its key as wp-short-pixel-apiKey (option names compare case-insensitively).
            'wp-short-pixel-apikey'             => true,
            'wp-short-pixel-apikeytried'        => true,
            'ewww_image_optimizer_cloud_key'    => true,

            // Mailchimp for WordPress keeps its API key in the "mc4wp" settings array.
            'mc4wp'                             => [ 'api_key' ],
            // Brevo (the Sendinblue plugin): main settings, token store and v3 key options.
            'sib_main_option'                   => [ 'access_key', 'ma_key' ],
            'sib_token_store'                   => true,
            'sib_api_key_v3'                    => true,
            // UNVERIFIED content: the WooCommerce add-on's settings; the heuristic covers api_key style names.
            'wc_sendinblue_settings'            => [ 'api_key', 'access_key' ],
            // HubSpot (leadin): the connection's OAuth tokens (uninstall.php deletes both).
            'leadin_access_token'               => true,
            'leadin_refresh_token'              => true,

            // Weglot: per-field options named weglot-translate-<field>, plus the settings arrays.
            'weglot-translate-api_key'          => true,
            'weglot-translate-api_key_private'  => true,
            'weglot-translate'                  => [ 'api_key', 'api_key_private' ],
            'weglot-translate-v3'               => [ 'api_key', 'api_key_private' ],

            // Akismet.
            'wordpress_api_key'                 => true,

            // WP MCP's own credential stores (sealed or hashed today; masked as defence in depth).
            'wpmcp_seo_data_keys'               => true,
            'wpmcp_stock_keys'                  => true,
            'wpmcp_oauth_tokens'                => true,
            'wpmcp_oauth_refresh_tokens'        => true,
            'wpmcp_oauth_codes'                 => true,
            'wpmcp_connection_passwords'        => true,
        ];
    }

    /**
     * What the registry says about one option: whether the whole value is a
     * credential, the credential key names and paths, and whether it is in
     * the registry at all. Matching folds the name like the database does.
     *
     * @return array{whole: bool, registered: bool, names: array<int, string>, paths: array<int, array<int, string>>}
     */
    private static function spec_for(string $name): array
    {
        $folded = strtolower(trim($name));
        $spec   = [ 'whole' => false, 'registered' => false, 'names' => [], 'paths' => [] ];

        foreach (self::registry() as $entry => $rule) {
            $entry = (string) $entry;
            if ('' !== $entry && '/' === $entry[0]) {
                $hit = 1 === preg_match($entry, trim($name));
            } else {
                $hit = strtolower(trim($entry)) === $folded;
            }
            if (! $hit) {
                continue;
            }
            $spec['registered'] = true;
            if (true === $rule) {
                $spec['whole'] = true;
                continue;
            }
            foreach ((array) $rule as $key) {
                $key = strtolower(trim((string) $key));
                if ('' === $key) {
                    continue;
                }
                if (false === strpos($key, '.')) {
                    $spec['names'][] = $key;
                } else {
                    $spec['paths'][] = explode('.', $key);
                }
            }
        }
        return $spec;
    }

    /** @return array<int, string> Key names the heuristic never masks, lower-cased. */
    private static function allowlist(): array
    {
        $list = apply_filters('wpmcp_secret_key_allowlist', []);
        return is_array($list) ? array_map(static fn ($key): string => strtolower(trim((string) $key)), $list) : [];
    }

    /**
     * Whether a key is a credential: registered by name or path, or the
     * heuristic says so (not for a boolean, not for an allowlisted name).
     *
     * @param mixed                                                            $item
     * @param array{names: array<int, string>, paths: array<int, array<int, string>>} $spec
     * @param array<int, string>                                               $allow
     * @param array<int, string>                                               $trail Path to the key, key included.
     */
    private static function is_secret_key(string $key, $item, array $spec, array $allow, array $trail): bool
    {
        $folded = strtolower($key);
        if (in_array($folded, $spec['names'], true)) {
            return true;
        }
        $lower = array_map('strtolower', $trail);
        foreach ($spec['paths'] as $path) {
            if (count($path) !== count($lower)) {
                continue;
            }
            $match = true;
            foreach ($path as $i => $segment) {
                if ('*' !== $segment && $segment !== $lower[ $i ]) {
                    $match = false;
                    break;
                }
            }
            if ($match) {
                return true;
            }
        }
        return self::heuristic_hit($key, $item, $allow);
    }

    /** Names whose numeric value is a limit or a size, not a credential (max_tokens, *_length, *_count, token_ttl). */
    private const BENIGN_NUMERIC = '/(^|[_-])(max|min|limit|ttl|expir\w*|lifetime|timeout|length|len|count|size|num|number|age|interval|rate|per|used|remaining|budget)([_-]|$)/i';

    /**
     * The key-name heuristic. A boolean or empty value is never a credential.
     * A string is, an array is (every leaf under it is masked), and so is a
     * number, except under a name that says it is a limit or a size
     * (max_tokens, password_min_length, token_ttl, token_count), numeric
     * string or not.
     *
     * @param mixed              $item
     * @param array<int, string> $allow
     */
    private static function heuristic_hit(string $key, $item, array $allow): bool
    {
        if (is_bool($item) || null === $item || '' === $item || [] === $item) {
            return false;
        }
        if (1 !== preg_match(self::KEY_PATTERN, $key) || in_array(strtolower($key), $allow, true)) {
            return false;
        }
        if ((is_int($item) || is_float($item) || (is_string($item) && is_numeric($item))) && 1 === preg_match(self::BENIGN_NUMERIC, $key)) {
            return false;
        }
        return is_scalar($item) || is_array($item);
    }

    /**
     * @param array<mixed>       $data
     * @param array<string, mixed> $spec
     * @param array<int, string> $allow
     * @param array<int, string> $trail
     * @return array<mixed>
     */
    private static function mask_tree(array $data, array $spec, array $allow, array $trail): array
    {
        foreach ($data as $key => $item) {
            $path = array_merge($trail, [ (string) $key ]);
            if (self::is_secret_key((string) $key, $item, $spec, $allow, $path)) {
                $data[ $key ] = self::mask_value($item);
            } elseif (is_array($item)) {
                $data[ $key ] = self::mask_tree($item, $spec, $allow, $path);
            } elseif (is_object($item)) {
                // An object nested in the array (stdClass, or the incomplete-class stand-in
                // for a class that was never instantiated): its properties are masked like
                // keys, and it comes back as a plain object. No class is ever instantiated.
                $props = [];
                foreach ((array) $item as $prop => $value) {
                    $props[ self::plain_property((string) $prop) ] = $value;
                }
                $inner = self::mask_tree($props, $spec, $allow, $path);
                if ($inner !== $props) {
                    $data[ $key ] = (object) $inner;
                }
            } elseif (is_string($item) && self::looks_encoded($item)) {
                // JSON text or a serialized string inside the array: masked in the same form.
                $decoded = Gateway_Secrets::decode($item);
                if (null !== $decoded) {
                    $inner = self::mask_tree($decoded[0], $spec, $allow, $path);
                    if ($inner !== $decoded[0]) {
                        $data[ $key ] = Gateway_Secrets::encode($inner, $decoded[1]);
                    }
                }
            }
        }
        return $data;
    }

    /**
     * A credential's value as it reads masked: a scalar becomes the mask, an
     * array keeps its shape with every non-boolean leaf masked.
     *
     * @param mixed $value
     * @return mixed
     */
    private static function mask_value($value)
    {
        if (! is_array($value)) {
            return Gateway_Secrets::mask($value);
        }
        foreach ($value as $key => $leaf) {
            if (! is_bool($leaf)) {
                $value[ $key ] = self::mask_value($leaf);
            }
        }
        return $value;
    }

    /** A property name without the null-byte prefix a private or protected one carries in an array cast. */
    private static function plain_property(string $name): string
    {
        $pos = strrpos($name, "\0");
        return false === $pos ? $name : substr($name, $pos + 1);
    }

    /** Whether a string could be JSON text or serialized data worth decoding. */
    private static function looks_encoded(string $value): bool
    {
        $trimmed = ltrim($value);
        return '' !== $trimmed && (in_array($trimmed[0], [ '{', '[' ], true) || is_serialized($value));
    }

    /**
     * $data with every credential that reads as the mask put back to its
     * stored value at the same path (or dropped when nothing is stored there).
     *
     * A list (numeric keys 0..n-1) has no stable identity per entry, so a
     * mask inside one is restored only when the stored list has the same
     * length and each entry's identity field (id, key, name, slug, uuid,
     * label), when it has one, is unchanged. Otherwise the write is refused
     * rather than putting one entry's credential into another.
     *
     * @param array<mixed>       $data
     * @param array<mixed>       $stored
     * @param array<string, mixed> $spec
     * @param array<int, string> $allow
     * @param array<int, string> $trail
     * @return array<mixed>
     * @throws \RuntimeException When a mask sits in a list whose entries changed.
     */
    private static function restore_tree(array $data, array $stored, array $spec, array $allow, array $trail): array
    {
        $is_list = [] !== $data && array_is_list($data);
        $read    = $data; // the list as sent, before any entry is restored
        foreach ($data as $key => $item) {
            $path = array_merge($trail, [ (string) $key ]);
            if ($is_list && is_array($item) && self::holds_mask($item)) {
                self::assert_list_entry_matches($read, $stored, (int) $key, $item, $spec, $allow, $trail);
            }
            if (is_array($item) && self::holds_mask($item) && self::is_secret_key((string) $key, $item, $spec, $allow, $path)) {
                // A credential array read masked leaf by leaf: back only when it is exactly what was read.
                $old = $stored[ $key ] ?? null;
                if (! is_array($old) || self::mask_value($old) !== $item) {
                    throw new \RuntimeException('Refusing to write: a masked credential list was changed, so its entries cannot be matched to the stored ones. Send the real credentials, or leave it as read.');
                }
                $data[ $key ] = $old;
                continue;
            }
            if (Gateway_Secrets::is_masked($item) && self::is_secret_key((string) $key, $item, $spec, $allow, $path)) {
                if (array_key_exists($key, $stored)) {
                    $data[ $key ] = $stored[ $key ];
                } else {
                    unset($data[ $key ]);
                }
            } elseif (is_array($item)) {
                $child        = isset($stored[ $key ]) && is_array($stored[ $key ]) ? $stored[ $key ] : [];
                $data[ $key ] = self::restore_tree($item, $child, $spec, $allow, $path);
            } elseif (is_string($item) && self::looks_encoded($item) && false !== strpos($item, self::MASK)) {
                $decoded = Gateway_Secrets::decode($item);
                $old     = isset($stored[ $key ]) && is_string($stored[ $key ]) ? Gateway_Secrets::decode($stored[ $key ]) : null;
                if (null !== $decoded) {
                    $inner = self::restore_tree($decoded[0], null === $old ? [] : $old[0], $spec, $allow, $path);
                    if ($inner !== $decoded[0]) {
                        $data[ $key ] = Gateway_Secrets::encode($inner, $decoded[1]);
                    }
                }
            }
        }
        return $data;
    }

    /** Whether an array has the mask anywhere inside it. */
    private static function holds_mask(array $data): bool
    {
        foreach ($data as $item) {
            if (Gateway_Secrets::is_masked($item) || (is_array($item) && self::holds_mask($item))) {
                return true;
            }
        }
        return false;
    }

    /**
     * Refuses a restore into list entry $index unless it is unambiguous: the
     * stored list has the same length and, when the entry has an identity
     * field (id, key, name, slug, uuid, label) it is unchanged; an entry with
     * no identity field is restored only when the whole list is what was read
     * (same entries, same order, masks in place).
     *
     * @param array<mixed>         $data
     * @param array<mixed>         $stored
     * @param array<mixed>         $entry
     * @param array<string, mixed> $spec
     * @param array<int, string>   $allow
     * @param array<int, string>   $trail
     */
    private static function assert_list_entry_matches(array $data, array $stored, int $index, array $entry, array $spec, array $allow, array $trail): void
    {
        $same = array_is_list($stored) && count($stored) === count($data) && isset($stored[ $index ]) && is_array($stored[ $index ]);
        if ($same) {
            $identified = false;
            foreach ([ 'id', 'key', 'name', 'slug', 'uuid', 'label' ] as $field) {
                if (array_key_exists($field, $entry) && array_key_exists($field, $stored[ $index ])) {
                    $identified = true;
                    if ($entry[ $field ] !== $stored[ $index ][ $field ]) {
                        $same = false;
                        break;
                    }
                }
            }
            if ($same && ! $identified) {
                $same = self::mask_tree($stored, $spec, $allow, $trail) === $data;
            }
        }
        if (! $same) {
            throw new \RuntimeException('Refusing to write: a masked credential sits in a list whose entries changed, so it cannot be matched to the stored one. Send the real credential for that entry, or leave the list as read.');
        }
    }

    /**
     * A REST response body with credentials masked. Under /wp/v2/settings the
     * body is a map of option name to value, so every entry goes through the
     * registry and heuristic by its option name; on any other route only a
     * key that is itself a registered option name is masked.
     *
     * @param mixed $body
     * @return mixed
     */
    public static function mask_rest_body(string $route, $body)
    {
        if (! is_array($body)) {
            return $body;
        }
        $settings = self::is_settings_route($route);
        $core     = self::is_core_route($route);
        $exact    = [];
        $regexes  = [];
        foreach (array_keys(self::registry()) as $entry) {
            $entry = (string) $entry;
            if ('' !== $entry && '/' === $entry[0]) {
                $regexes[] = $entry;
            } else {
                $exact[ strtolower(trim($entry)) ] = true;
            }
        }
        $names = $settings ? self::rest_setting_names() : [];
        return self::mask_rest_walk($body, $settings, $core, $exact, $regexes, $names);
    }

    /**
     * @param array<mixed>        $body
     * @param array<string, true> $exact
     * @param array<int, string>  $regexes
     * @param array<string, string> $names REST setting name to option name.
     * @return array<mixed>
     */
    private static function mask_rest_walk(array $body, bool $settings, bool $core, array $exact, array $regexes, array $names): array
    {
        $none  = [ 'names' => [], 'paths' => [] ];
        $allow = self::allowlist();
        foreach ($body as $key => $item) {
            if (is_string($key) && $settings) {
                $body[ $key ] = self::mask_registered($names[ $key ] ?? $key, $item);
                continue;
            }
            $registered = is_string($key) && isset($exact[ strtolower(trim($key)) ]);
            foreach ($regexes as $regex) {
                $registered = $registered || (is_string($key) && 1 === preg_match($regex, trim($key)));
            }
            if ($registered) {
                $body[ $key ] = self::mask_registered((string) $key, $item);
            } elseif (! $core && is_string($key) && self::heuristic_hit($key, $item, $allow)) {
                $body[ $key ] = self::mask_value($item);
            } elseif (is_array($item)) {
                $body[ $key ] = self::mask_rest_walk($item, false, $core, $exact, $regexes, $names);
            } elseif (! $core && is_string($item) && self::looks_encoded($item)) {
                $decoded = Gateway_Secrets::decode($item);
                if (null !== $decoded) {
                    $inner = self::mask_tree($decoded[0], $none, $allow, []);
                    if ($inner !== $decoded[0]) {
                        $body[ $key ] = Gateway_Secrets::encode($inner, $decoded[1]);
                    }
                }
            }
        }
        return $body;
    }

    /**
     * REST setting name to option name for every setting shown in REST, so a
     * setting exposed under another name still resolves to its option.
     *
     * @return array<string, string>
     */
    private static function rest_setting_names(): array
    {
        $map = [];
        foreach (get_registered_settings() as $option => $args) {
            $rest = $args['show_in_rest'] ?? false;
            if (is_array($rest) && isset($rest['name']) && is_string($rest['name'])) {
                $map[ $rest['name'] ] = (string) $option;
            }
        }
        return $map;
    }

    /**
     * Routes that show a secret on purpose, which the key-name heuristic
     * skips: creating an application password returns the new password once.
     * Every other route, core namespaces included (a plugin can register
     * under wp/v2), gets the heuristic.
     */
    private static function is_core_route(string $route): bool
    {
        return 1 === preg_match('#^/wp/v\d+/users/(\d+|me)/application-passwords(/|$)#i', $route);
    }

    /**
     * REST write params with every masked credential put back to the stored
     * value, on the settings route (names are option names).
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public static function unmask_rest_params(string $route, array $params): array
    {
        if (! self::is_settings_route($route)) {
            return $params;
        }
        $names = self::rest_setting_names();
        foreach ($params as $key => $value) {
            if (is_string($key)) {
                $params[ $key ] = self::unmask_option($names[ $key ] ?? $key, $value);
            }
        }
        return $params;
    }

    private static function is_settings_route(string $route): bool
    {
        return 1 === preg_match('#^/wp/v\d+/settings/?$#i', $route);
    }

    /** @param mixed $value */
    private static function is_unset($value): bool
    {
        return false === $value || null === $value || '' === $value || [] === $value;
    }
}
