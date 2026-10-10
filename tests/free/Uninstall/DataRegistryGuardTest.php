<?php

namespace WPMCP\Tests\Free\Uninstall;

use WPMCP\Data_Registry;
use WPMCP\Safety\File_Backup;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Backup\Restore_Files;
use WPMCP\Tools\Backup\Site_Backup_Dir;
use WPMCP\Tools\Export\Content_Mirror;
use WPMCP\Tools\Export\Export_Dir;
use WPMCP\Tools\Filesystem\Filesystem_Guard;
use WPMCP\Tools\Redirects\Redirect_Store;
use WPMCP\Tools\Search\Search_Index_Store;
use WPMCP\Tools\WidgetBuilder\Compiler\Compiled_Widget_Manifest;

/**
 * Guard for the data ownership registry (issue #480).
 *
 * With "delete all data" on, uninstall removes exactly what Data_Registry
 * lists, so a store missing from it would survive the plugin. This walks
 * every PHP file under src/ at token level and:
 *
 *  - resolves the key of every option, transient, site option and user meta
 *    write (a literal, a class constant, a concatenation that starts with
 *    one, or a local variable assigned from one), and fails when a key named
 *    like ours is not covered by the registry, or when a key named like
 *    anyone else's is not declared below as a foreign key the plugin edits
 *    on the site's behalf;
 *  - resolves every `$wpdb->prefix . 'name'` table and fails when a wpmcp
 *    table is not registered, or a file that runs dbDelta() creates a table
 *    the scan cannot see;
 *  - fails on any directory constant named like ours that is neither
 *    registered nor declared as kept.
 *
 * It also fails on registry entries nothing in src/ uses any more.
 */
class DataRegistryGuardTest extends \WP_UnitTestCase
{
    /** Write functions => [zero-based key argument, kind]. */
    private const WRITERS = [
        'update_option'         => [ 0, 'option' ],
        'add_option'            => [ 0, 'option' ],
        'set_transient'         => [ 0, 'transient' ],
        'set_site_transient'    => [ 0, 'site_transient' ],
        'update_site_option'    => [ 0, 'site_option' ],
        'add_site_option'       => [ 0, 'site_option' ],
        'update_network_option' => [ 1, 'site_option' ],
        'add_network_option'    => [ 1, 'site_option' ],
        'update_user_meta'      => [ 1, 'user_meta' ],
        'add_user_meta'         => [ 1, 'user_meta' ],
        'update_user_option'    => [ 1, 'user_meta' ],
    ];

    /**
     * Keys owned by WordPress core or another plugin that a tool writes on
     * the site's behalf. Uninstall must never delete these. An entry ending
     * in '*' is a prefix.
     */
    private const FOREIGN = [
        'option'         => [
            'sidebars_widgets',
            'bricks_global_classes_timestamp',
            'bricks_global_classes_user',
            'astra-settings',
            'woocommerce_gateway_order',
            'wpseo_taxonomy_meta',
            'oxygen_vsb_css_files_state',
            'generateblocks_dynamic_css_posts',
        ],
        'transient'      => [
            'scrape_key_*',
        ],
        'site_transient' => [],
        'site_option'    => [],
        'user_meta'      => [],
    ];

    /**
     * Files whose writes take a key the code cannot know statically, with
     * the reason. Every one of them writes a key the site already has (a
     * tool editing a named setting, a rollback putting back a recorded
     * value) or one built by a helper whose prefix is pinned by a test of
     * its own class.
     */
    private const DYNAMIC_KEY_FILES = [
        'src/Cron_Registry.php'                              => 'job store names from Cron_Registry::JOB_HOOKS, all wpmcp_',
        'src/Auth/Atomic_Option.php'                         => 'creates whichever wpmcp_ option its caller locks',
        'src/Auth/Client_Metadata_Document.php'              => 'cache key built by cache_key() from CACHE_PREFIX (wpmcp_oauth_cimd_)',
        'src/Cloud/Cloud_Credentials.php'                    => 'restores its own wpmcp_cloud_ options from a snapshot',
        'src/Cloud/Settings_Sync.php'                        => 'applies synced site settings the owner chose',
        'src/Tools/CustomCode/Custom_Code_Store.php'         => 'per-post option built from POST_OPTION_PREFIX (wpmcp_custom_code_post_)',
        'src/Tools/Settings/Update_Settings.php'             => 'edits a named core setting',
        'src/Tools/Meta/Update_Option.php'                   => 'edits a named option on request',
        'src/Tools/Packages/Manage_Updates.php'              => 'core auto-update lists',
        'src/Tools/Sync/Change_Set_Applier.php'              => 'applies a change set to named options',
        'src/Tools/Backup/Restore_Session.php'               => 'puts back the restoring user\'s own session and meta',
        'src/Tools/Builders/Builder_Design_Write.php'        => 'page builder design options',
        'src/Tools/Builders/Avada_Cache.php'                 => 'Avada cache options',
        'src/Tools/WooCommerce/Catalog/Shipping_Ops.php'     => 'WooCommerce shipping method settings',
        'src/Tools/Media/Upload_Grant.php'                   => 'grant option built from OPTION_PREFIX (wpmcp_upload_grant_)',
        'src/Tools/Analysis/SeoData/Seo_Data_Lookup.php'     => 'cache key built by cache_name() from CACHE_PREFIX (wpmcp_seod_)',
        'src/Tools/Diagnostics/Mail_Check.php'               => 'rate-limit key built from LIMIT_KEY (wpmcp_mail_test_)',
        'src/Safety/Rollback_Service.php'                    => 'puts back recorded options and user meta',
        'src/Safety/Wc_Shipping_Zone_Snapshot.php'           => 'puts back recorded WooCommerce options',
        'src/Integrations/Theme_Settings_Pack.php'           => 'theme settings options',
        'src/Integrations/Elementor_Addon_Packs.php'         => 'Elementor add-on settings',
    ];

    /**
     * Directory constants named like ours that uninstall deliberately keeps,
     * with the reason.
     */
    private const KEPT_DIRS = [
        'wpmcp-skills' => 'skills the site owner wrote by hand in wp-content; WP MCP only reads them',
    ];

    public function test_every_written_key_is_registered_or_declared_foreign(): void
    {
        $writes = $this->writes();
        $this->assertGreaterThan(50, count($writes), 'The scan found too few writes, so it is not looking in the right place.');

        $unresolved = [];
        $missing    = [];
        $unknown    = [];
        $foreign    = [];
        foreach ($writes as $write) {
            $where = sprintf('%s:%d %s(%s)', $write['file'], $write['line'], $write['function'], $write['expr']);
            if (null === $write['key']) {
                if (! isset(self::DYNAMIC_KEY_FILES[ $write['file'] ])) {
                    $unresolved[] = $where;
                }
                continue;
            }

            [ $key, $exact ] = $write['key'];
            if (self::looks_like_ours($key)) {
                if (! self::covered($write['kind'], $key, $exact)) {
                    $missing[] = $where . ' => ' . $key . ($exact ? '' : '*');
                }
                continue;
            }
            $entry = self::foreign($write['kind'], $key);
            if (null === $entry) {
                $unknown[] = $where . ' => ' . $key . ($exact ? '' : '*');
            } else {
                $foreign[ $write['kind'] . ':' . $entry ] = true;
            }
        }

        $dynamic = array_unique(array_column(array_filter($writes, static fn ($w) => null === $w['key']), 'file'));
        $this->assertSame([], array_values(array_diff(array_keys(self::DYNAMIC_KEY_FILES), $dynamic)), 'DYNAMIC_KEY_FILES lists files with no dynamic write any more.');
        $this->assertSame([], $unresolved, "Writes whose key could not be resolved; resolve it to a constant or add the file to DYNAMIC_KEY_FILES with the reason:\n" . implode("\n", $unresolved));
        $this->assertSame([], $missing, "Keys WP MCP writes that Data_Registry does not cover, so deleting the plugin would leave them behind:\n" . implode("\n", $missing));
        $declared = [];
        foreach (self::FOREIGN as $kind => $entries) {
            foreach ($entries as $entry) {
                $declared[] = $kind . ':' . $entry;
            }
        }
        $this->assertSame([], array_values(array_diff($declared, array_keys($foreign))), 'FOREIGN lists keys nothing in src/ writes any more.');
        $this->assertSame([], $unknown, "Keys written that are neither WP MCP's (wpmcp_ / _wpmcp_) nor declared FOREIGN here. A new store must use a registered prefix:\n" . implode("\n", $unknown));
    }

    public function test_every_wpmcp_table_is_registered(): void
    {
        $tables = $this->tables();
        $owned  = array_values(array_unique(array_filter(array_column($tables, 'name'), [self::class, 'looks_like_ours'])));

        $this->assertNotEmpty($owned, 'The scan found no WP MCP tables at all.');
        $this->assertSame([], array_values(array_diff($owned, Data_Registry::TABLES)), 'Tables created in src/ but missing from Data_Registry::TABLES.');
        $this->assertSame([], array_values(array_diff(Data_Registry::TABLES, $owned)), 'Data_Registry::TABLES lists tables nothing in src/ uses any more.');

        $with_tables = array_unique(array_column(array_filter($tables, static fn ($t) => self::looks_like_ours($t['name'])), 'file'));
        $creators    = [];
        foreach ($this->sources() as $relative => $src) {
            if (preg_match('/\bdbDelta\s*\(/', $src) && 'src/Tools/Backup/Sql_Importer.php' !== $relative) {
                $creators[] = $relative;
            }
        }
        $this->assertNotEmpty($creators);
        $this->assertSame([], array_values(array_diff($creators, $with_tables)), 'Files that run dbDelta() without a $wpdb->prefix . \'wpmcp_...\' table name the scan can see.');
    }

    public function test_every_wpmcp_directory_constant_is_registered_or_kept(): void
    {
        $found = [];
        foreach ($this->sources() as $relative => $src) {
            if (preg_match_all("/const\s+\w*DIR\w*\s*=\s*'(\.?wpmcp[-_][^'\/]*)'/", $src, $m)) {
                foreach ($m[1] as $name) {
                    $found[ $name ] = $relative;
                }
            }
        }
        $this->assertNotEmpty($found);

        $registered = array_merge(Data_Registry::UPLOADS_DIRS, Data_Registry::CONTENT_DIRS);
        $missing    = array_diff(array_keys($found), $registered, array_keys(self::KEPT_DIRS));
        $this->assertSame([], array_values($missing), 'Directory constants named like WP MCP\'s that are neither in Data_Registry nor KEPT_DIRS.');
        $this->assertSame([], array_values(array_diff($registered, array_keys($found))), 'Data_Registry lists directories no constant in src/ names any more.');
    }

    /**
     * The registry names stores by string so it can load in uninstall.php
     * without an autoloader and survive builds that prune classes. Pin each
     * string to the owning class's constant.
     */
    public function test_registry_entries_match_their_owners(): void
    {
        global $wpdb;

        $this->assertSame(
            [ Snapshot_Store::table_name(), Redirect_Store::table_name(), Search_Index_Store::table_name() ],
            array_map(static fn ($t) => $wpdb->prefix . $t, Data_Registry::TABLES)
        );
        $this->assertSame(
            [ File_Backup::BACKUP_DIR, Site_Backup_Dir::DIR_NAME, Export_Dir::DIR_NAME, Content_Mirror::DIR_NAME, Filesystem_Guard::BACKUP_DIR ],
            Data_Registry::UPLOADS_DIRS
        );
        $this->assertSame(
            [ Restore_Files::DIR_NAME, Compiled_Widget_Manifest::DIR_NAME ],
            Data_Registry::CONTENT_DIRS
        );
    }

    public function test_registry_covers_transients_and_both_meta_spellings(): void
    {
        foreach ([ 'wpmcp_oauth_tokens', '_transient_wpmcp_rl_x', '_transient_timeout_wpmcp_rl_x', '_site_transient_wpmcp_x', '_site_transient_timeout_wpmcp_x' ] as $option) {
            $this->assertTrue(Data_Registry::owns_option($option), $option);
        }
        foreach ([ 'wpmcp_dismissed_announcements', '_wpmcp_chat_anthropic_key' ] as $meta) {
            $this->assertTrue(Data_Registry::owns_user_meta($meta), $meta);
        }
        foreach ([ 'wpmcp_x', '_site_transient_wpmcp_x', '_site_transient_timeout_wpmcp_x' ] as $meta) {
            $this->assertTrue(Data_Registry::owns_site_meta($meta), $meta);
        }
        foreach ([ 'wpmcpx', 'other_wpmcp_x', '_transient_other', 'fs_accounts', 'active_plugins', '' ] as $foreign) {
            $this->assertFalse(Data_Registry::owns_option($foreign), $foreign);
            $this->assertFalse(Data_Registry::owns_user_meta($foreign), $foreign);
            $this->assertFalse(Data_Registry::owns_site_meta($foreign), $foreign);
        }
    }

    public function test_the_scanner_resolves_every_key_shape(): void
    {
        $src = "<?php\nnamespace WPMCP\\Auth;\nclass Demo { const OPTION = 'wpmcp_demo'; const PREFIX = 'wpmcp_p_';\n"
            . "function a() { update_option('wpmcp_literal', 1); }\n"
            . "function b() { \\set_transient(self::PREFIX . \$id, 1); }\n"
            . "function c() { \$key = 'wpmcp_var_' . md5('x'); set_transient(\$key, 1); }\n"
            . "function d() { update_user_meta(\$id, Demo::OPTION, 1); }\n"
            . "function e() { update_option(\$name, 1); }\n"
            . "function f() { update_option(\"wpmcp_enc_{\$x}\", 1); }\n"
            . "function g() { \$this->update_option('not_a_call'); }\n}\n";

        $writes = $this->writes_in('src/Demo.php', $src);

        $this->assertSame(
            [ [ 'wpmcp_literal', true ], [ 'wpmcp_p_', false ], [ 'wpmcp_var_', false ], [ 'wpmcp_demo', true ], null, [ 'wpmcp_enc_', false ] ],
            array_column($writes, 'key')
        );
        $this->assertSame([ 'option', 'transient', 'transient', 'user_meta', 'option', 'option' ], array_column($writes, 'kind'));
    }

    private static function looks_like_ours(string $key): bool
    {
        return 1 === preg_match('/^_?wpmcp/i', $key);
    }

    /** Whether the registry deletes $key (or every key starting with it). */
    private static function covered(string $kind, string $key, bool $exact): bool
    {
        $check = static function (string $name) use ($kind): bool {
            switch ($kind) {
                case 'option':
                    return Data_Registry::owns_option($name);
                case 'transient':
                    return Data_Registry::owns_option('_transient_' . $name) && Data_Registry::owns_option('_transient_timeout_' . $name);
                case 'site_transient':
                    return Data_Registry::owns_option('_site_transient_' . $name) && Data_Registry::owns_site_meta('_site_transient_' . $name);
                case 'site_option':
                    return Data_Registry::owns_option($name) && Data_Registry::owns_site_meta($name);
                default:
                    return Data_Registry::owns_user_meta($name);
            }
        };

        // A prefix is covered only when the registry owns everything that
        // can follow it, which is the case when it owns the bare prefix.
        return $exact ? $check($key) : $check($key) && $check($key . 'x');
    }

    /** The FOREIGN entry that declares $key, or null. */
    private static function foreign(string $kind, string $key): ?string
    {
        foreach (self::FOREIGN[ $kind ] ?? [] as $entry) {
            if (str_ends_with($entry, '*') ? str_starts_with($key, substr($entry, 0, -1)) : $entry === $key) {
                return $entry;
            }
        }
        return null;
    }

    /** @return array<string, string> relative path => source */
    private function sources(): array
    {
        $root  = dirname(__DIR__, 3);
        $out   = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/src', \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ('php' === $file->getExtension()) {
                $out[ ltrim(str_replace($root, '', $file->getPathname()), '/') ] = (string) file_get_contents($file->getPathname());
            }
        }
        ksort($out);
        return $out;
    }

    private function writes(): array
    {
        $writes = [];
        foreach ($this->sources() as $relative => $src) {
            $writes = array_merge($writes, $this->writes_in($relative, $src));
        }
        return $writes;
    }

    /** @return array<int, array{name:string,file:string}> */
    private function tables(): array
    {
        $tables = [];
        foreach ($this->sources() as $relative => $src) {
            $ctx    = $this->context($src);
            $tokens = $this->tokens($src);
            foreach ($tokens as $i => $t) {
                $this->track($ctx, $tokens, $i);
                if (! is_array($t) || T_VARIABLE !== $t[0] || '$wpdb' !== $t[1]) {
                    continue;
                }
                $prop = $tokens[ $i + 2 ] ?? null;
                if (! is_array($tokens[ $i + 1 ] ?? null) || T_OBJECT_OPERATOR !== $tokens[ $i + 1 ][0]
                    || ! is_array($prop) || ! in_array($prop[1], [ 'prefix', 'base_prefix' ], true)
                    || '.' !== ($tokens[ $i + 3 ] ?? null)) {
                    continue;
                }
                $resolved = $this->resolve_operand(array_slice($tokens, $i + 4, 3), $ctx);
                if (null !== $resolved) {
                    $tables[] = [ 'name' => $resolved, 'file' => $relative ];
                }
            }
        }
        return $tables;
    }

    /** Parse state, seeded with the file's own string class constants (private ones included). */
    private function context(string $src): array
    {
        preg_match_all("/const\\s+(\\w+)\\s*=\\s*'([^'\\\\]*)'\\s*;/", $src, $m, PREG_SET_ORDER);
        $consts = [];
        foreach ($m as $match) {
            $consts[ $match[1] ] = $match[2];
        }
        return [ 'namespace' => '', 'class' => '', 'uses' => [], 'function_start' => 0, 'consts' => $consts ];
    }

    private function tokens(string $src): array
    {
        return array_values(array_filter(
            token_get_all($src),
            static fn ($t) => ! is_array($t) || ! in_array($t[0], [ T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ], true)
        ));
    }

    /** Keep namespace, class, imports and the current function's start up to date. */
    private function track(array &$ctx, array $tokens, int $i): void
    {
        $t = $tokens[ $i ];
        if (! is_array($t)) {
            return;
        }
        $next = $tokens[ $i + 1 ] ?? null;
        if (T_NAMESPACE === $t[0] && is_array($next)) {
            $ctx['namespace'] = $next[1];
        } elseif (T_USE === $t[0] && '' === $ctx['class'] && is_array($next)) {
            $name = ltrim($next[1], '\\');
            $as   = $tokens[ $i + 2 ] ?? null;
            $ctx['uses'][ (is_array($as) && T_AS === $as[0]) ? $tokens[ $i + 3 ][1] : substr((string) strrchr('\\' . $name, '\\'), 1) ] = $name;
        } elseif (T_CLASS === $t[0] && is_array($next) && T_STRING === $next[0]) {
            $ctx['class'] = ltrim($ctx['namespace'] . '\\' . $next[1], '\\');
        } elseif (T_FUNCTION === $t[0] || T_FN === $t[0]) {
            $ctx['function_start'] = $i;
        }
    }

    /** @return array<int, array{file:string,line:int,function:string,kind:string,key:?array,expr:string}> */
    private function writes_in(string $relative, string $src): array
    {
        $tokens = $this->tokens($src);
        $ctx    = $this->context($src);
        $writes = [];
        $count  = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $this->track($ctx, $tokens, $i);
            $t = $tokens[ $i ];
            if (! is_array($t) || ! in_array($t[0], [ T_STRING, T_NAME_FULLY_QUALIFIED ], true)) {
                continue;
            }
            $function = strtolower(ltrim($t[1], '\\'));
            if (! isset(self::WRITERS[ $function ]) || '(' !== ($tokens[ $i + 1 ] ?? null)) {
                continue;
            }
            $prev = $tokens[ $i - 1 ] ?? null;
            if (is_array($prev) && in_array($prev[0], [ T_FUNCTION, T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_NEW ], true)) {
                continue;
            }

            [ $position, $kind ] = self::WRITERS[ $function ];
            $arg      = $this->arguments($tokens, $i + 1)[ $position ] ?? [];
            $writes[] = [
                'file'     => $relative,
                'line'     => $t[2],
                'function' => $function,
                'kind'     => $kind,
                'key'      => $this->resolve_key($arg, $ctx, $tokens, $i, 0),
                'expr'     => implode('', array_map(static fn ($x) => is_array($x) ? $x[1] : $x, $arg)),
            ];
        }

        return $writes;
    }

    /** The argument token lists of the call whose '(' is at $open. */
    private function arguments(array $tokens, int $open): array
    {
        $args  = [ [] ];
        $depth = 0;
        for ($i = $open; $i < count($tokens); $i++) {
            $t = $tokens[ $i ];
            if (in_array($t, [ '(', '[', '{' ], true) || (is_array($t) && in_array($t[0], [ T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES ], true))) {
                if (++$depth === 1) {
                    continue;
                }
            } elseif (in_array($t, [ ')', ']', '}' ], true)) {
                if (--$depth === 0) {
                    break;
                }
            } elseif (',' === $t && 1 === $depth) {
                $args[] = [];
                continue;
            }
            $args[ count($args) - 1 ][] = $t;
        }
        return $args;
    }

    /**
     * [key, exact] for an argument, or null when it is dynamic. A
     * concatenation resolves to its leading operand as a prefix; a local
     * variable resolves through its last assignment in the same function.
     *
     * @return array{0:string,1:bool}|null
     */
    private function resolve_key(array $arg, array $ctx, array $tokens, int $at, int $depth): ?array
    {
        if ([] === $arg) {
            return null;
        }

        // "wpmcp_x_{$id}": the leading literal part is a prefix.
        if ('"' === $arg[0]) {
            $first = $arg[1] ?? null;
            return is_array($first) && T_ENCAPSED_AND_WHITESPACE === $first[0] ? [ $first[1], false ] : null;
        }

        if (is_array($arg[0]) && T_VARIABLE === $arg[0][0] && $depth < 2) {
            $resolved = $this->resolve_variable($arg[0][1], $ctx, $tokens, $at, $depth);
            if (null === $resolved) {
                return null;
            }
            return 1 === count($arg) ? $resolved : [ $resolved[0], false ];
        }

        $operand = is_array($arg[0]) && T_CONSTANT_ENCAPSED_STRING === $arg[0][0] ? array_slice($arg, 0, 1) : array_slice($arg, 0, 3);
        $value   = $this->resolve_operand($operand, $ctx);
        if (null === $value) {
            return null;
        }
        $rest = array_slice($arg, count($operand));
        if ([] === $rest) {
            return [ $value, true ];
        }
        return '.' === $rest[0] ? [ $value, false ] : null;
    }

    private function resolve_variable(string $name, array $ctx, array $tokens, int $at, int $depth): ?array
    {
        for ($i = $at - 1; $i > $ctx['function_start']; $i--) {
            $t = $tokens[ $i ];
            if (is_array($t) && T_VARIABLE === $t[0] && $name === $t[1] && '=' === ($tokens[ $i + 1 ] ?? null)) {
                $rhs = [];
                for ($j = $i + 2; $j < $at && ';' !== $tokens[ $j ]; $j++) {
                    $rhs[] = $tokens[ $j ];
                }
                return $this->resolve_key($rhs, $ctx, $tokens, $i, $depth + 1);
            }
        }
        return null;
    }

    /** A string literal or Class::CONST, resolved; null for anything else. */
    private function resolve_operand(array $operand, array $ctx): ?string
    {
        if (1 <= count($operand) && is_array($operand[0]) && T_CONSTANT_ENCAPSED_STRING === $operand[0][0]) {
            return stripslashes(substr($operand[0][1], 1, -1));
        }
        if (3 !== count($operand) || ! is_array($operand[0]) || ! is_array($operand[1]) || T_DOUBLE_COLON !== $operand[1][0] || ! is_array($operand[2])) {
            return null;
        }

        $name = $operand[0][1];
        if (in_array(strtolower($name), [ 'self', 'static' ], true)) {
            $fqn = $ctx['class'];
        } elseif ('\\' === $name[0]) {
            $fqn = ltrim($name, '\\');
        } else {
            $first = explode('\\', $name)[0];
            $fqn   = isset($ctx['uses'][ $first ])
                ? $ctx['uses'][ $first ] . substr($name, strlen($first))
                : ltrim($ctx['namespace'] . '\\' . $name, '\\');
        }

        // The file's own constants first: private ones cannot be read with
        // constant() from here, and the scanner self-test's class is never loaded.
        if ($fqn === $ctx['class'] && isset($ctx['consts'][ $operand[2][1] ])) {
            return $ctx['consts'][ $operand[2][1] ];
        }

        $constant = $fqn . '::' . $operand[2][1];
        if (! defined($constant)) {
            return null;
        }
        try {
            $value = constant($constant);
        } catch (\Error $e) {
            return null;
        }
        return is_string($value) ? $value : null;
    }
}
