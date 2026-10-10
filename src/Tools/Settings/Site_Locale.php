<?php

namespace WPMCP\Tools\Settings;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Validation for the settings that shape how the site prints dates and in
 * which language (issue #472): WPLANG, timezone_string, gmt_offset,
 * date_format and time_format. Each check mirrors what the General Settings
 * screen accepts, so update-settings never stores a value that screen could
 * not have stored.
 *
 * Every validator returns the same shape Update_Settings::validate() uses:
 * ['ok' => false, 'reason' => ...] or ['ok' => true, 'value' => ...], plus
 * 'writes' when one key sets more than one option (a UTC offset clears
 * timezone_string and sets gmt_offset, exactly like the settings screen).
 */
class Site_Locale
{
    /** The two options a timezone change spans, snapshotted together. */
    public const TIMEZONE_OPTIONS = [ 'gmt_offset', 'timezone_string' ];

    /** The shape of a WordPress locale code (de_DE, de_DE_formal, pt_PT_ao90, art_xemoji, ary). */
    private const LOCALE_PATTERN = '/^[a-z]{2,3}(?:_[A-Za-z0-9]+){0,2}$/';

    /** Longest date or time format accepted; the screen's own presets are a handful of characters. */
    private const FORMAT_MAX = 100;

    /**
     * A locale code: '' or en_US selects English (United States), stored as ''
     * the way core stores it. Whether a code that is not installed can be
     * installed is decided later, by ensure_installed(), and only when the
     * value actually changes.
     */
    public static function validate_locale($raw): array
    {
        if (! is_string($raw)) {
            return [ 'ok' => false, 'reason' => 'locale must be a string such as de_DE, or empty for English (United States)' ];
        }
        $locale = trim($raw);
        if ('' === $locale || 'en_US' === $locale) {
            return [ 'ok' => true, 'value' => '' ];
        }
        if (! preg_match(self::LOCALE_PATTERN, $locale)) {
            return [ 'ok' => false, 'reason' => 'invalid locale code' ];
        }
        return [ 'ok' => true, 'value' => $locale ];
    }

    /**
     * Make sure $locale's core language pack is installed, installing it
     * through core's translation API when it is missing. Returns null when the
     * locale is ready to select, or the reason it is not. Nothing is written
     * to the options table here, so a refusal leaves the site as it was.
     */
    public static function ensure_installed(string $locale): ?string
    {
        if ('' === $locale || in_array($locale, get_available_languages(), true)) {
            return null;
        }
        if (! function_exists('wp_can_install_language_pack')) {
            require_once ABSPATH . 'wp-admin/includes/translation-install.php';
        }
        if (! function_exists('get_filesystem_method')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        if (! wp_is_file_mod_allowed('can_install_language_pack') || 'direct' !== get_filesystem_method()) {
            return 'language pack not installed, and this site does not allow installing it (no direct filesystem access or file changes are disabled)';
        }
        // Checked after the filesystem: core denies install_languages whenever
        // file changes are disabled, and that refusal should say so.
        if (! current_user_can('install_languages')) {
            return 'language pack not installed, and installing it requires the install_languages capability';
        }

        $translation = null;
        $available   = wp_get_available_translations();
        if ([] === $available) {
            return 'language pack not installed, and the list of available languages could not be fetched from WordPress.org';
        }
        foreach ($available as $row) {
            if (($row['language'] ?? null) === $locale) {
                $translation = (object) $row;
                break;
            }
        }
        if (null === $translation) {
            return 'unknown locale: not installed and not available from WordPress.org';
        }

        if (! class_exists('Language_Pack_Upgrader')) {
            require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        }
        $translation->type = 'core';
        $upgrader          = new \Language_Pack_Upgrader(new \Automatic_Upgrader_Skin());
        // The upgrader skin can leave an output buffer open on a failed
        // download; close any it opened so the response is not swallowed.
        $buffers = ob_get_level();
        $result  = $upgrader->upgrade($translation, [ 'clear_update_cache' => false ]);
        while (ob_get_level() > $buffers) {
            ob_end_clean();
        }

        if (is_wp_error($result) || ! $result || ! in_array($locale, get_available_languages(), true)) {
            $detail = is_wp_error($result) ? ': ' . $result->get_error_message() : '';
            return 'language pack install failed' . $detail;
        }
        return null;
    }

    /**
     * A timezone for timezone_string (an identifier such as Europe/Berlin, or
     * a UTC+N / UTC-N offset) or gmt_offset (a number of hours). An offset
     * clears timezone_string and sets gmt_offset, as the settings screen does.
     */
    public static function validate_timezone(string $key, $raw): array
    {
        if ('gmt_offset' === $key) {
            return self::offset($raw);
        }
        if (! is_string($raw)) {
            return [ 'ok' => false, 'reason' => 'invalid timezone' ];
        }
        $zone = trim($raw);
        if (preg_match('/^UTC([+-].*)$/', $zone, $m)) {
            $out = self::offset($m[1]);
            return $out['ok'] ? [ 'ok' => true, 'value' => '' ] + $out : $out;
        }
        if ('' === $zone || ! in_array($zone, timezone_identifiers_list(\DateTimeZone::ALL_WITH_BC), true)) {
            return [ 'ok' => false, 'reason' => 'invalid timezone: use an identifier such as Europe/Berlin or an offset such as UTC+5' ];
        }
        return [ 'ok' => true, 'value' => $zone, 'writes' => [ 'timezone_string' => $zone ] ];
    }

    /** A UTC offset in hours: -12 to +14, in the quarter-hour steps the settings screen offers. */
    private static function offset($raw): array
    {
        if (! is_numeric($raw)) {
            return [ 'ok' => false, 'reason' => 'invalid UTC offset' ];
        }
        $hours = (float) $raw;
        if ($hours < -12 || $hours > 14 || floor($hours * 4) !== $hours * 4) {
            return [ 'ok' => false, 'reason' => 'invalid UTC offset: use -12 to 14 hours in quarter-hour steps' ];
        }
        $hours = 0.0 === $hours ? 0.0 : $hours;
        return [
            'ok'     => true,
            'value'  => $hours,
            'writes' => [ 'timezone_string' => '', 'gmt_offset' => $hours ],
        ];
    }

    /**
     * A PHP date or time format string. Refused when empty, overlong, or when
     * the settings screen's sanitizing (tags stripped, then wp_kses_data) would
     * change it, so markup never reaches a format every date is printed with.
     */
    public static function validate_format($raw): array
    {
        $format = is_string($raw) ? trim($raw) : '';
        if ('' === $format) {
            return [ 'ok' => false, 'reason' => 'format must be a non-empty string' ];
        }
        if (strlen($format) > self::FORMAT_MAX || preg_match('/[\x00-\x1F\x7F]/', $format) || wp_kses_data(wp_strip_all_tags($format)) !== $format) {
            return [ 'ok' => false, 'reason' => 'unsafe format string' ];
        }
        return [ 'ok' => true, 'value' => $format ];
    }
}
