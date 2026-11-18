<?php

namespace WPMCP\Integrations;

use WPMCP\MCP\Registrar;
use WPMCP\Tools\Filesystem\File_Write_Operation;
use WPMCP\Tools\Filesystem\Filesystem_Guard;
use WPMCP\Tools\Filesystem\Php_Edit_Guard;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Loco Translate's .po files (issue #691), paid-tier ops on the plugin-data
 * pair, so they add no top-level tool:
 *
 *  - loco-list-bundles: the translation files of core, each plugin and each
 *    theme, grouped by bundle (type and text domain), from the three places
 *    Loco keeps them: WordPress's languages folder ('system'), Loco's own
 *    LOCO_LANG_DIR ('custom', wp-content/languages/loco by default) and the
 *    plugin's or theme's own language folder ('author'). Optional per-file
 *    counts of translated, untranslated and fuzzy strings.
 *  - loco-get-po-strings: one file's strings, untranslated first, then
 *    fuzzy, then translated, filtered by status or a search, with the
 *    file's hash for loco-update-po-strings' expected_hash.
 *  - loco-update-po-strings: set translations by original (and context),
 *    a plural string's forms as a list, clearing the fuzzy flag unless told
 *    to keep it; only those lines of the file change. Then, unless
 *    compile:false, the .mo is compiled next to it and an existing
 *    .l10n.php cache refreshed, as Loco does on save.
 *  - loco-compile-mo: the .mo (and an existing .l10n.php) again from the
 *    .po as it is.
 *
 * The ops read and write the gettext files directly, not through Loco's
 * classes, so they act on exactly what Loco shows. Only files these
 * locations hold can be named. Every file written is a file_write undo
 * point (File_Write_Operation, the same as write-file), all in one session,
 * so rollback-session undoes the whole call and rollback-operation one file.
 * Writes are off until wpmcp_enable_loco_write is on and need file editing
 * allowed (edit_files, no DISALLOW_FILE_EDIT), since their undo does too.
 * Every op runs at manage_options. Presence is filterable
 * (wpmcp_loco_active).
 */
final class Loco_Translate_Pack
{
    public const ENABLE_FILTER = 'wpmcp_enable_loco_write';

    private const MAX_PER_PAGE = 100;

    private const MAX_BYTES = 16 * MB_IN_BYTES;

    private const STATUS_ORDER = [ 'untranslated' => 0, 'fuzzy' => 1, 'translated' => 2 ];

    public static function loco_active(): bool
    {
        return (bool) apply_filters('wpmcp_loco_active', defined('LOCO_VERSION'));
    }

    public static function active(): bool
    {
        return Registrar::tier_permitted('pro') && self::loco_active();
    }

    /** @return array<string, array<string, mixed>> */
    public static function operations(): array
    {
        // The literal name, not ENABLE_FILTER, so the hook stays greppable.
        $enabled = (bool) apply_filters('wpmcp_enable_loco_write', false);
        $base    = [
            'tier'       => 'pro',
            'capability' => 'manage_options',
            'requires'   => static function () {
                if (self::loco_active()) {
                    return true;
                }
                $refusal         = Plugin_Refusal::make('plugin_missing', 'Loco Translate');
                $refusal['code'] = 'inactive' === $refusal['data']['state'] ? 'plugin_inactive' : 'plugin_missing';
                return $refusal;
            },
        ];
        $path    = [ 'type' => 'string', 'minLength' => 4, 'maxLength' => 500 ];
        $write   = $base + [
            'mode'               => 'write',
            'enabled_by_default' => $enabled,
            'opt_in_filter'      => self::ENABLE_FILTER,
            'self_snapshotting'  => true,
        ];

        return [
            'loco-list-bundles'      => $base + [
                'mode'         => 'read',
                'description'  => 'Loco Translate .po files by bundle (core, plugin or theme text domain): path, locale, location (system, custom, author), .mo and .l10n.php presence, optional string counts',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'type'        => [ 'type' => 'string', 'enum' => [ 'core', 'plugin', 'theme' ] ],
                        'domain'      => [ 'type' => 'string', 'maxLength' => 200 ],
                        'locale'      => [ 'type' => 'string', 'maxLength' => 20 ],
                        'with_counts' => [ 'type' => 'boolean' ],
                        'per_page'    => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_PER_PAGE ],
                        'page'        => [ 'type' => 'integer', 'minimum' => 1 ],
                    ],
                ],
                'handler'      => static fn (array $args): array => self::bundles($args),
            ],
            'loco-get-po-strings'    => $base + [
                'mode'         => 'read',
                'description'  => 'Strings of one .po file (path from loco-list-bundles), untranslated first, then fuzzy, then translated; filter by status or search; returns the file hash',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'path'     => $path,
                        'status'   => [ 'type' => 'string', 'enum' => [ 'all', 'untranslated', 'fuzzy', 'translated' ] ],
                        'search'   => [ 'type' => 'string', 'maxLength' => 200 ],
                        'per_page' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_PER_PAGE ],
                        'page'     => [ 'type' => 'integer', 'minimum' => 1 ],
                    ],
                    'required'   => [ 'path' ],
                ],
                'validate'     => static fn (array $args): ?array => self::file_refusal((string) $args['path']),
                'handler'      => static fn (array $args): array => self::strings($args),
            ],
            'loco-update-po-strings' => $write + [
                'description'  => 'Set .po translations by msgid (+context): msgstr, or msgstr_plural for plural strings; clears fuzzy unless fuzzy:true; then compiles the .mo unless compile:false. Snapshotted; undo: rollback-session. Disabled by default (wpmcp_enable_loco_write filter)',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'path'          => $path,
                        'expected_hash' => [ 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$' ],
                        'compile'       => [ 'type' => 'boolean' ],
                        'strings'       => [
                            'type'     => 'array',
                            'minItems' => 1,
                            'maxItems' => self::MAX_PER_PAGE,
                            'items'    => [
                                'type'       => 'object',
                                'properties' => [
                                    'msgid'         => [ 'type' => 'string', 'minLength' => 1 ],
                                    'context'       => [ 'type' => 'string' ],
                                    'msgstr'        => [ 'type' => 'string' ],
                                    'msgstr_plural' => [ 'type' => 'array', 'minItems' => 1, 'maxItems' => 6, 'items' => [ 'type' => 'string' ] ],
                                    'fuzzy'         => [ 'type' => 'boolean' ],
                                ],
                                'required'   => [ 'msgid' ],
                            ],
                        ],
                    ],
                    'required'   => [ 'path', 'strings' ],
                ],
                'validate'     => static fn (array $args): ?array => self::update_refusal($args),
                'handler'      => static fn (array $args, array $context): array => self::update($args, (string) $context['session_id']),
            ],
            'loco-compile-mo'        => $write + [
                'description'  => 'Compile a .po file\'s .mo (and refresh an existing .l10n.php) as Loco does on save. Snapshotted; undo: rollback-session. Disabled by default (wpmcp_enable_loco_write filter)',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [ 'path' => $path ],
                    'required'   => [ 'path' ],
                ],
                'validate'     => static fn (array $args): ?array => self::write_refusal((string) $args['path']),
                'handler'      => static fn (array $args, array $context): array => self::compile($args, (string) $context['session_id']),
            ],
        ];
    }

    // ---------------------------------------------------------------
    // Discovery
    // ---------------------------------------------------------------

    /**
     * Every .po file in the places Loco keeps them, by install-relative path.
     *
     * @return array<string, array{abs: string, type: string, domain: string, name: string, locale: string, location: string}>
     */
    public static function files(): array
    {
        $custom = defined('LOCO_LANG_DIR') && is_string(LOCO_LANG_DIR) && '' !== LOCO_LANG_DIR ? LOCO_LANG_DIR : WP_LANG_DIR . '/loco';
        $places = [
            [ WP_LANG_DIR, 'core', 'system', null, '' ],
            [ WP_LANG_DIR . '/plugins', 'plugin', 'system', null, '' ],
            [ WP_LANG_DIR . '/themes', 'theme', 'system', null, '' ],
            [ $custom . '/plugins', 'plugin', 'custom', null, '' ],
            [ $custom . '/themes', 'theme', 'custom', null, '' ],
        ];
        $names = [];
        if (! function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        foreach (get_plugins() as $file => $data) {
            if (false === strpos((string) $file, '/')) {
                continue;
            }
            $slug   = dirname((string) $file);
            $domain = '' !== (string) ($data['TextDomain'] ?? '') ? (string) $data['TextDomain'] : $slug;
            $names[ 'plugin|' . $domain ] = (string) ($data['Name'] ?? $slug);
            $places[] = [ WP_PLUGIN_DIR . '/' . $slug . '/' . trim('' !== (string) ($data['DomainPath'] ?? '') ? (string) $data['DomainPath'] : 'languages', '/'), 'plugin', 'author', $domain, '' ];
        }
        foreach (wp_get_themes() as $stylesheet => $theme) {
            $domain = '' !== (string) $theme->get('TextDomain') ? (string) $theme->get('TextDomain') : (string) $stylesheet;
            $names[ 'theme|' . $domain ] = (string) $theme->get('Name');
            $places[] = [ $theme->get_stylesheet_directory() . '/' . trim('' !== (string) $theme->get('DomainPath') ? (string) $theme->get('DomainPath') : 'languages', '/'), 'theme', 'author', $domain, '' ];
        }

        $out  = [];
        $seen = [];
        foreach ($places as [ $dir, $type, $location, $domain ]) {
            foreach ((array) glob(rtrim((string) $dir, '/\\') . '/*.po') as $abs) {
                $abs  = (string) $abs;
                $real = (string) realpath($abs);
                // A file outside the install (a test core's languages folder,
                // a content folder moved out of ABSPATH) is left out: its
                // file_write undo point could not be restored.
                if ('' === $real || isset($seen[ $real ]) || ! is_file($abs) || is_link($abs) || is_wp_error(Filesystem_Guard::resolve_path($abs))) {
                    continue;
                }
                if (1 !== preg_match('/^(?:(.+)-)?([a-z]{2,3}(?:_[A-Z]{2})?(?:_[0-9a-z]+)?)$/', basename($abs, '.po'), $m)) {
                    continue;
                }
                $found = '' !== $m[1] ? $m[1] : ($domain ?? ('core' === $type ? 'default' : ''));
                if ('' === $found || (null !== $domain && 'author' === $location && '' !== $m[1] && $m[1] !== $domain)) {
                    continue;
                }
                $seen[ $real ] = true;
                $rel           = Filesystem_Guard::to_relative($abs);
                $out[ $rel ]   = [
                    'abs'      => $abs,
                    'type'     => $type,
                    'domain'   => $found,
                    'name'     => $names[ $type . '|' . $found ] ?? ('core' === $type ? 'WordPress' : $found),
                    'locale'   => $m[2],
                    'location' => $location,
                ];
            }
        }
        return $out;
    }

    /** The discovered file a path names, or null. */
    private static function file(string $path): ?array
    {
        $files = self::files();
        $rel   = ltrim(str_replace('\\', '/', $path), '/');
        if (isset($files[ $rel ])) {
            return $files[ $rel ] + [ 'path' => $rel ];
        }
        $abs = Filesystem_Guard::resolve_path($path);
        if (is_wp_error($abs)) {
            return null;
        }
        $rel = Filesystem_Guard::to_relative($abs);
        return isset($files[ $rel ]) ? $files[ $rel ] + [ 'path' => $rel ] : null;
    }

    // ---------------------------------------------------------------
    // Refusals
    // ---------------------------------------------------------------

    /** @return array{code: string, message: string, data: array}|null */
    private static function file_refusal(string $path): ?array
    {
        $file = self::file($path);
        if (null === $file) {
            return [
                'code'    => 'unknown_po_file',
                'message' => sprintf('"%s" is not a translation file Loco Translate manages on this site; take a path from loco-list-bundles.', $path),
                'data'    => [ 'path' => $path ],
            ];
        }
        if ((int) filesize($file['abs']) > self::MAX_BYTES) {
            return [ 'code' => 'po_file_too_large', 'message' => sprintf('%s is larger than 16 MB.', $file['path']), 'data' => [ 'path' => $file['path'] ] ];
        }
        return null;
    }

    /** @return array{code: string, message: string, data: array}|null */
    private static function write_refusal(string $path): ?array
    {
        $gate = Filesystem_Guard::writes_allowed();
        if (is_wp_error($gate)) {
            return [ 'code' => (string) $gate->get_error_code(), 'message' => $gate->get_error_message() . ' Translation file writes need file editing, like write-file.', 'data' => [] ];
        }
        $refusal = self::file_refusal($path);
        if (null !== $refusal) {
            return $refusal;
        }
        $abs = self::file($path)['abs'];
        if (! wp_is_writable(dirname($abs)) || ! wp_is_writable($abs)) {
            return [ 'code' => 'not_writable', 'message' => sprintf('%s or its folder is not writable by WordPress.', $path), 'data' => [ 'path' => $path ] ];
        }
        return null;
    }

    /** @return array{code: string, message: string, data: array}|null */
    private static function update_refusal(array $args): ?array
    {
        $refusal = self::write_refusal((string) $args['path']);
        if (null !== $refusal) {
            return $refusal;
        }
        $file = self::file((string) $args['path']);
        $text = self::read_file($file['abs']);
        if (isset($args['expected_hash']) && hash('sha256', $text) !== (string) $args['expected_hash']) {
            return [
                'code'    => 'stale_file',
                'message' => sprintf('%s changed since it was read (expected_hash does not match); read it again with loco-get-po-strings.', $file['path']),
                'data'    => [ 'hash' => hash('sha256', $text) ],
            ];
        }

        $index   = Po_File::index(Po_File::parse($text));
        $keys    = [];
        $missing = [];
        foreach ((array) $args['strings'] as $string) {
            $string  = (array) $string;
            $context = isset($string['context']) ? (string) $string['context'] : null;
            $key     = Po_File::key($context, (string) $string['msgid']);
            if (isset($keys[ $key ])) {
                return [ 'code' => 'duplicate_string', 'message' => sprintf('"%s" appears more than once in this call.', $string['msgid']), 'data' => [] ];
            }
            $keys[ $key ] = true;
            if (! isset($index[ $key ])) {
                $missing[] = null === $context ? (string) $string['msgid'] : $context . ' | ' . $string['msgid'];
                continue;
            }
            $refusal = self::shape_refusal($index[ $key ], $string);
            if (null !== $refusal) {
                return $refusal;
            }
        }
        if ([] !== $missing) {
            return [
                'code'    => 'unknown_string',
                'message' => sprintf('Not a string of %s (obsolete strings cannot be set): %s.', $file['path'], implode(', ', $missing)),
                'data'    => [ 'missing' => $missing ],
            ];
        }
        return null;
    }

    /** @return array{code: string, message: string, data: array}|null */
    private static function shape_refusal(array $entry, array $string): ?array
    {
        $plural = null !== $entry['msgid_plural'];
        if (! isset($string['msgstr']) && ! isset($string['msgstr_plural'])) {
            return [ 'code' => 'missing_translation', 'message' => sprintf('Give msgstr or msgstr_plural for "%s".', $string['msgid']), 'data' => [] ];
        }
        if ($plural && (isset($string['msgstr']) || count((array) ($string['msgstr_plural'] ?? [])) !== max(1, count($entry['msgstr'])))) {
            return [
                'code'    => 'plural_mismatch',
                'message' => sprintf('"%s" is a plural string: give msgstr_plural with %d forms.', $string['msgid'], max(1, count($entry['msgstr']))),
                'data'    => [ 'forms' => max(1, count($entry['msgstr'])) ],
            ];
        }
        if (! $plural && isset($string['msgstr_plural'])) {
            return [ 'code' => 'plural_mismatch', 'message' => sprintf('"%s" has no plural: give msgstr.', $string['msgid']), 'data' => [] ];
        }
        return null;
    }

    // ---------------------------------------------------------------
    // Reads
    // ---------------------------------------------------------------

    private static function bundles(array $args): array
    {
        $bundles = [];
        foreach (self::files() as $rel => $file) {
            if (
                (isset($args['type']) && $file['type'] !== $args['type'])
                || (isset($args['domain']) && '' !== $args['domain'] && $file['domain'] !== $args['domain'])
                || (isset($args['locale']) && '' !== $args['locale'] && $file['locale'] !== $args['locale'])
            ) {
                continue;
            }
            $key = $file['type'] . '|' . $file['domain'];
            $bundles[ $key ] = $bundles[ $key ] ?? [ 'type' => $file['type'], 'domain' => $file['domain'], 'name' => $file['name'], 'files' => [] ];
            $bundles[ $key ]['files'][] = [ 'path' => $rel ] + $file;
        }
        ksort($bundles);

        $per_page = (int) ($args['per_page'] ?? 50);
        $page     = (int) ($args['page'] ?? 1);
        $slice    = array_slice(array_values($bundles), ($page - 1) * $per_page, $per_page);
        foreach ($slice as &$bundle) {
            usort($bundle['files'], static fn (array $a, array $b): int => [ $a['locale'], $a['location'] ] <=> [ $b['locale'], $b['location'] ]);
            $bundle['files'] = array_map(static fn (array $file): array => self::file_summary($file, ! empty($args['with_counts'])), $bundle['files']);
        }
        unset($bundle);

        return [ 'total' => count($bundles), 'page' => $page, 'per_page' => $per_page, 'bundles' => $slice ];
    }

    private static function file_summary(array $file, bool $counts): array
    {
        $base = substr($file['abs'], 0, -3);
        $out  = [
            'path'         => $file['path'],
            'locale'       => $file['locale'],
            'location'     => $file['location'],
            'bytes'        => (int) filesize($file['abs']),
            'modified'     => gmdate('c', (int) filemtime($file['abs'])),
            'has_mo'       => is_file($base . '.mo'),
            'has_l10n_php' => is_file($base . '.l10n.php'),
        ];
        if ($counts && $out['bytes'] <= self::MAX_BYTES) {
            $out['counts'] = self::counts(Po_File::parse(self::read_file($file['abs'])));
        }
        return $out;
    }

    /** @return array{total: int, translated: int, untranslated: int, fuzzy: int} */
    private static function counts(array $parsed): array
    {
        $out = [ 'total' => count($parsed['entries']), 'translated' => 0, 'untranslated' => 0, 'fuzzy' => 0 ];
        foreach ($parsed['entries'] as $entry) {
            ++$out[ Po_File::status($entry) ];
        }
        return $out;
    }

    private static function strings(array $args): array
    {
        $file   = self::file((string) $args['path']);
        $text   = self::read_file($file['abs']);
        $parsed = Po_File::parse($text);
        $status = (string) ($args['status'] ?? 'all');
        $search = isset($args['search']) ? (string) $args['search'] : '';

        $rows = [];
        foreach ($parsed['entries'] as $n => $entry) {
            $state = Po_File::status($entry);
            if ('all' !== $status && $state !== $status) {
                continue;
            }
            if ('' !== $search && false === mb_stripos($entry['msgid'] . "\n" . $entry['msgid_plural'] . "\n" . implode("\n", $entry['msgstr']) . "\n" . $entry['msgctxt'], $search)) {
                continue;
            }
            $rows[] = [ self::STATUS_ORDER[ $state ], $n, self::shape($entry, $state) ];
        }
        usort($rows, static fn (array $a, array $b): int => [ $a[0], $a[1] ] <=> [ $b[0], $b[1] ]);

        $per_page = (int) ($args['per_page'] ?? 50);
        $page     = (int) ($args['page'] ?? 1);

        return [
            'path'         => $file['path'],
            'domain'       => $file['domain'],
            'locale'       => $file['locale'],
            'hash'         => hash('sha256', $text),
            'plural_forms' => Po_File::header($parsed, 'Plural-Forms'),
            'counts'       => self::counts($parsed),
            'total'        => count($rows),
            'page'         => $page,
            'per_page'     => $per_page,
            'strings'      => array_column(array_slice($rows, ($page - 1) * $per_page, $per_page), 2),
        ];
    }

    private static function shape(array $entry, string $state): array
    {
        ksort($entry['msgstr']);
        $out = [ 'msgid' => $entry['msgid'] ];
        if (null !== $entry['msgctxt']) {
            $out['context'] = $entry['msgctxt'];
        }
        if (null !== $entry['msgid_plural']) {
            $out['msgid_plural']  = $entry['msgid_plural'];
            $out['msgstr_plural'] = array_values($entry['msgstr']);
        } else {
            $out['msgstr'] = (string) ($entry['msgstr'][0] ?? '');
        }
        $out['status'] = $state;
        if ([] !== $entry['references']) {
            $out['references'] = array_slice($entry['references'], 0, 5);
        }
        if ([] !== $entry['comments']) {
            $out['comments'] = $entry['comments'];
        }
        return $out;
    }

    // ---------------------------------------------------------------
    // Writes
    // ---------------------------------------------------------------

    /** @return array{result: array, operation_ids: string[]} */
    private static function update(array $args, string $session): array
    {
        $file    = self::file((string) $args['path']);
        $parsed  = Po_File::parse(self::read_file($file['abs']));
        $index   = Po_File::index($parsed);
        $updates = [];
        $warn    = [];
        foreach ((array) $args['strings'] as $string) {
            $string = (array) $string;
            $key    = Po_File::key(isset($string['context']) ? (string) $string['context'] : null, (string) $string['msgid']);
            $msgstr = isset($string['msgstr_plural']) ? array_map('strval', (array) $string['msgstr_plural']) : [ (string) $string['msgstr'] ];
            $updates[ $key ] = [ 'msgstr' => $msgstr, 'fuzzy' => ! empty($string['fuzzy']) ];
            $warning = self::placeholder_warning($index[ $key ], $msgstr);
            if (null !== $warning) {
                $warn[] = $warning;
            }
        }

        $text  = Po_File::edit($parsed, $updates, gmdate('Y-m-d H:iO'));
        $ids   = [ self::write($file['abs'], $text, $session) ];
        $files = [ [ 'kind' => 'po', 'path' => $file['path'] ] ];

        if (! array_key_exists('compile', $args) || ! empty($args['compile'])) {
            try {
                $compiled = self::compile_files($file, Po_File::parse($text), $session);
                $ids      = array_merge($ids, $compiled['operation_ids']);
                $files    = array_merge($files, $compiled['files']);
                $warn     = array_merge($warn, $compiled['warnings']);
            } catch (\RuntimeException $e) {
                $warn[] = 'The .po was saved, but compiling the .mo failed: ' . $e->getMessage();
            }
        }

        return [
            'result'        => [
                'path'     => $file['path'],
                'updated'  => count($updates),
                'hash'     => hash('sha256', $text),
                'files'    => $files,
                'warnings' => $warn,
            ],
            'operation_ids' => $ids,
        ];
    }

    /** @return array{result: array, operation_ids: string[]} */
    private static function compile(array $args, string $session): array
    {
        $file = self::file((string) $args['path']);
        $done = self::compile_files($file, Po_File::parse(self::read_file($file['abs'])), $session);

        return [
            'result'        => [ 'path' => $file['path'], 'compiled' => $done['compiled'], 'files' => $done['files'], 'warnings' => $done['warnings'] ],
            'operation_ids' => $done['operation_ids'],
        ];
    }

    /**
     * Write the .mo next to the .po and refresh a .l10n.php cache that is
     * already there (WordPress 6.5+ loads it in place of the .mo, so a stale
     * one would hide the change). A cache that is not there is not created.
     *
     * @return array{compiled: int, files: array, warnings: string[], operation_ids: string[]}
     */
    private static function compile_files(array $file, array $parsed, string $session): array
    {
        $base   = substr($file['abs'], 0, -3);
        $mo     = Po_File::compile($parsed);
        $ids    = [ self::write($base . '.mo', $mo['bytes'], $session) ];
        $files  = [ [ 'kind' => 'mo', 'path' => Filesystem_Guard::to_relative($base . '.mo') ] ];
        $warn   = [];
        $l10n   = $base . '.l10n.php';
        if (is_file($l10n) && class_exists('WP_Translation_File')) {
            $php = \WP_Translation_File::transform($base . '.mo', 'php');
            if (false === $php) {
                $warn[] = sprintf('%s could not be rebuilt; WordPress keeps loading the old translations from it until it is.', Filesystem_Guard::to_relative($l10n));
            } else {
                $ids[]   = self::write($l10n, (string) $php, $session);
                $files[] = [ 'kind' => 'l10n.php', 'path' => Filesystem_Guard::to_relative($l10n) ];
            }
        }
        return [ 'compiled' => $mo['compiled'], 'files' => $files, 'warnings' => $warn, 'operation_ids' => $ids ];
    }

    /**
     * Write one file as a file_write undo point and return its operation id.
     * A .l10n.php cache is core's own export of the .mo (a returned array),
     * so it is syntax-checked but not site-checked, and restored the same way.
     */
    private static function write(string $abs, string $bytes, string $session): string
    {
        $php     = Php_Edit_Guard::applies($abs);
        $existed = is_file($abs);
        $done    = File_Write_Operation::run(
            'plugin-data-write',
            $abs,
            [ 'session_id' => $session, 'unchecked' => $php ],
            static function (string $backup) use ($abs, $bytes, $php): void {
                if ($php) {
                    Php_Edit_Guard::write($abs, $bytes, true, $backup);
                    return;
                }
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- the same direct write write-file makes, inside its undo point.
                if (false === file_put_contents($abs, $bytes)) {
                    throw new \RuntimeException('Could not write the file (check permissions).');
                }
            }
        );
        Filesystem_Guard::log($existed ? 'overwrite' : 'create', Filesystem_Guard::to_relative($abs));
        return (string) $done['operation_id'];
    }

    private static function read_file(string $abs): string
    {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local translation file, not a URL.
        $text = file_get_contents($abs);
        if (false === $text) {
            throw new Operation_Refused('po_read_failed', 'Could not read the translation file.');
        }
        return $text;
    }

    /**
     * A note when a translation's printf placeholders differ from the
     * original's, which would make the string print wrongly or warn.
     *
     * @param string[] $msgstr
     */
    private static function placeholder_warning(array $entry, array $msgstr): ?string
    {
        $want = self::placeholders($entry['msgid'] . ' ' . $entry['msgid_plural']);
        foreach ($msgstr as $value) {
            if ('' !== $value && self::placeholders($value) !== $want) {
                return sprintf('The translation of "%s" does not use the same placeholders (%s) as the original; check it before relying on it.', $entry['msgid'], implode(' ', $want));
            }
        }
        return null;
    }

    /** @return string[] the distinct printf placeholders of $text, positions dropped */
    private static function placeholders(string $text): array
    {
        preg_match_all('/%(?:\d+\$)?[-+ 0#\']*\d*(?:\.\d+)?([bcdeEfFgGosuxX])/', str_replace('%%', '', $text), $m);
        $out = array_values(array_unique($m[1]));
        sort($out);
        return $out;
    }
}
