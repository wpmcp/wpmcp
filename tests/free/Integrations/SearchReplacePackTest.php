<?php

namespace WPMCP\Tests\Free\Integrations;

use WPMCP\Integrations\Plugin_Data_Integration;
use WPMCP\Integrations\Search_Replace_Pack;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;

/**
 * Search and replace (issue #895): free-tier ops on the plugin-data pair for
 * sites running Better Search Replace or Search Regex. Neither plugin is
 * loaded here, so presence is forced through wpmcp_search_replace_active;
 * the plugins' own saved state (Search Regex presets, Better Search Replace's
 * last result) is seeded in the shape their source writes it.
 */
class SearchReplacePackTest extends \WP_UnitTestCase
{
    private const OLD = 'http://old-sr.example';
    private const NEW = 'https://new-sr.example';

    private const OPS = [
        'searchreplace-list-tables'  => 'read',
        'searchreplace-dry-run'      => 'read',
        'searchreplace-get-last-run' => 'read',
        'searchreplace-run'          => 'destructive',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        $GLOBALS['wpdb']->query('DELETE FROM ' . Snapshot_Store::table_name());
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
        add_filter('wpmcp_search_replace_active', '__return_true');
        add_filter('wpmcp_enable_search_replace_write', '__return_true');
    }

    protected function tearDown(): void
    {
        remove_all_filters('wpmcp_search_replace_active');
        remove_all_filters('wpmcp_enable_search_replace_write');
        remove_all_filters('wpmcp_search_replace_max_rows');
        delete_option(Search_Replace_Pack::LAST_RUN_OPTION);
        delete_option(Search_Replace_Pack::SEARCH_REGEX_PRESETS);
        delete_transient(Search_Replace_Pack::BSR_RESULTS);
        parent::tearDown();
    }

    private function read(string $op, array $args = []): array
    {
        $out = (new Plugin_Data_Integration())->handle_read([ 'operation' => $op, 'args' => $args ]);
        return isset($out['result']) && ! isset($out['error']) ? $out['result'] : $out;
    }

    private function write(array $args, bool $confirm = true): array
    {
        $call = [ 'operation' => 'searchreplace-run', 'args' => $args ];
        if ($confirm) {
            $call['confirm'] = true;
        }
        return (new Plugin_Data_Integration())->handle_write($call);
    }

    private function code(array $out): string
    {
        return (string) ($out['error']['code'] ?? '');
    }

    private function args(array $tables, array $extra = []): array
    {
        global $wpdb;
        $names = array_map(static fn (string $t): string => $wpdb->$t, $tables);
        return $extra + [ 'search' => self::OLD, 'replace' => self::NEW, 'tables' => $names ];
    }

    /** A post, a serialized meta value and an option, each holding the old URL. */
    private function seed(): array
    {
        $post = self::factory()->post->create([ 'post_content' => 'Visit ' . self::OLD . '/about today.', 'post_title' => 'SR seed' ]);
        update_post_meta($post, 'sr_links', [ 'home' => self::OLD, 'list' => [ self::OLD . '/a', 'other' ], self::OLD => 'key stays' ]);
        update_option('sr_test_option', [ 'url' => self::OLD . '/x' ]);
        return [ 'post' => $post ];
    }

    private function raw_meta(int $post, string $key): string
    {
        global $wpdb;
        return (string) $wpdb->get_var($wpdb->prepare("SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s", $post, $key));
    }

    private function raw_option(string $name): string
    {
        global $wpdb;
        return (string) $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name));
    }

    // ---------------------------------------------------------------
    // Catalog and availability
    // ---------------------------------------------------------------

    public function test_the_catalog_lists_every_op_with_its_mode_and_manage_options(): void
    {
        $ops = array_column((new Plugin_Data_Integration())->catalog()['operations'], null, 'name');
        foreach (self::OPS as $name => $mode) {
            $this->assertArrayHasKey($name, $ops);
            $this->assertSame($mode, $ops[ $name ]['mode'], $name);
            $this->assertSame('manage_options', $ops[ $name ]['capability'], $name);
        }
        $this->assertTrue($ops['searchreplace-run']['requires_confirm']);
    }

    public function test_without_either_plugin_the_ops_name_both(): void
    {
        remove_all_filters('wpmcp_search_replace_active');
        add_filter('wpmcp_search_replace_active', '__return_false');
        add_filter('wpmcp_wpgraphql_active', '__return_true');

        $out = $this->read('searchreplace-list-tables');
        remove_all_filters('wpmcp_wpgraphql_active');

        $this->assertSame('search_replace_inactive', $this->code($out));
        $this->assertStringContainsString('Better Search Replace', $out['error']['message']);
        $this->assertStringContainsString('Search Regex', $out['error']['message']);
    }

    // ---------------------------------------------------------------
    // Reads
    // ---------------------------------------------------------------

    public function test_list_tables_flags_protected_and_own_tables_and_lists_presets(): void
    {
        global $wpdb;
        update_option(Search_Replace_Pack::SEARCH_REGEX_PRESETS, (string) wp_json_encode([
            [ 'id' => 'p1', 'name' => 'Domain move', 'description' => 'old to new', 'search' => [ 'searchPhrase' => 'old.example', 'replacement' => 'new.example', 'searchFlags' => [ 'case' ], 'source' => [ 'posts' ] ] ],
        ]));
        set_transient(Search_Replace_Pack::BSR_RESULTS, [ 'search_for' => 'a', 'replace_with' => 'b', 'change' => 2, 'updates' => 1, 'table_reports' => [] ], DAY_IN_SECONDS);

        $out    = $this->read('searchreplace-list-tables');
        $tables = array_column($out['tables'], null, 'name');

        $this->assertTrue($tables[ $wpdb->posts ]['writable']);
        $this->assertSame([ 'ID' ], $tables[ $wpdb->posts ]['primary_key']);
        $this->assertFalse($tables[ $wpdb->users ]['writable']);
        $this->assertFalse($tables[ $wpdb->usermeta ]['writable']);
        $this->assertFalse($tables[ Snapshot_Store::table_name() ]['writable']);

        $this->assertSame('Domain move', $out['profiles'][0]['name']);
        $this->assertSame('old.example', $out['profiles'][0]['search']);
        $this->assertSame([ 'posts' ], $out['profiles'][0]['sources']);
        $this->assertTrue($out['history']['better_search_replace_last_result']);
    }

    public function test_dry_run_reports_matches_and_samples_and_changes_nothing(): void
    {
        $seed   = $this->seed();
        $before = $this->raw_meta($seed['post'], 'sr_links');

        $out = $this->read('searchreplace-dry-run', $this->args([ 'posts', 'postmeta', 'options' ]));

        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        $tables = array_column($out['tables'], null, 'table');
        global $wpdb;
        $this->assertSame(1, $tables[ $wpdb->posts ]['rows']);
        $this->assertSame(1, $tables[ $wpdb->postmeta ]['rows']);
        // home and list[0]; the array key is left alone.
        $this->assertSame(2, $tables[ $wpdb->postmeta ]['matches']);
        $this->assertSame(1, $tables[ $wpdb->options ]['rows']);
        $this->assertStringContainsString(self::OLD, $tables[ $wpdb->posts ]['samples'][0]['before']);
        $this->assertStringContainsString(self::NEW, $tables[ $wpdb->posts ]['samples'][0]['after']);
        $this->assertNotEmpty($out['dry_run_token']);
        $this->assertSame($before, $this->raw_meta($seed['post'], 'sr_links'));
    }

    public function test_dry_run_masks_emails_in_samples(): void
    {
        self::factory()->post->create([ 'post_content' => 'Mail jane.doe@example.org about ' . self::OLD ]);
        $out = $this->read('searchreplace-dry-run', $this->args([ 'posts' ]));

        $before = $out['tables'][0]['samples'][0]['before'];
        $this->assertStringNotContainsString('jane.doe@example.org', $before);
        $this->assertStringContainsString('j***@example.org', $before);
        $this->assertTrue($out['masked']);
    }

    public function test_refusals_empty_search_identical_protected_and_internal_tables(): void
    {
        global $wpdb;
        $this->assertSame('empty_search', $this->code($this->read('searchreplace-dry-run', [ 'search' => '  ', 'replace' => 'x', 'tables' => [ $wpdb->posts ] ])));
        $this->assertSame('nothing_to_replace', $this->code($this->read('searchreplace-dry-run', [ 'search' => 'a', 'replace' => 'a', 'tables' => [ $wpdb->posts ] ])));
        $this->assertSame('table_refused', $this->code($this->read('searchreplace-dry-run', $this->args([ 'users' ]))));
        $this->assertSame('table_refused', $this->code($this->read('searchreplace-dry-run', $this->args([ 'usermeta' ]))));
        $this->assertSame('table_refused', $this->code($this->read('searchreplace-dry-run', [ 'search' => 'a', 'replace' => 'b', 'tables' => [ Snapshot_Store::table_name() ] ])));
        $this->assertSame('unknown_table', $this->code($this->read('searchreplace-dry-run', [ 'search' => 'a', 'replace' => 'b', 'tables' => [ 'no_such_table_sr' ] ])));
    }

    // ---------------------------------------------------------------
    // Run
    // ---------------------------------------------------------------

    public function test_run_is_off_until_the_site_opts_in(): void
    {
        remove_all_filters('wpmcp_enable_search_replace_write');
        $out = $this->write($this->args([ 'posts' ], [ 'dry_run_token' => 'x' ]));
        $this->assertSame('opt_in_required', $this->code($out));
    }

    public function test_run_needs_confirm_and_a_valid_token(): void
    {
        $seed  = $this->seed();
        $this->assertSame('dry_run_required', $this->code($this->write($this->args([ 'posts' ], [ 'dry_run_token' => 'sr1.9999999999.1.abc' ]))));

        $token   = $this->read('searchreplace-dry-run', $this->args([ 'posts' ]))['dry_run_token'];
        $preview = $this->write($this->args([ 'posts' ], [ 'dry_run_token' => $token ]), false);
        $this->assertSame('confirmation_required', $this->code($preview));
        $this->assertSame(1, $preview['error']['data']['rows']);
        clean_post_cache($seed['post']);
        $this->assertStringContainsString(self::OLD, get_post($seed['post'])->post_content);

        // A token is bound to its arguments.
        $other = $this->write($this->args([ 'posts' ], [ 'dry_run_token' => $token, 'replace' => 'https://elsewhere.example' ]));
        $this->assertSame('dry_run_required', $this->code($other));
    }

    public function test_run_replaces_serialization_safely_and_rollback_session_restores(): void
    {
        $seed        = $this->seed();
        $meta_before = $this->raw_meta($seed['post'], 'sr_links');
        $opt_before  = $this->raw_option('sr_test_option');
        $guid_before = get_post($seed['post'])->guid;

        $args  = $this->args([ 'posts', 'postmeta', 'options' ]);
        $token = $this->read('searchreplace-dry-run', $args)['dry_run_token'];
        $out   = $this->write($args + [ 'dry_run_token' => $token ]);

        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        $this->assertTrue($out['recoverable']);
        $this->assertSame(3, $out['result']['rows_changed']);
        $this->assertCount(3, $out['operation_ids']);

        clean_post_cache($seed['post']);
        wp_cache_delete($seed['post'], 'post_meta');
        $this->assertStringContainsString(self::NEW . '/about', get_post($seed['post'])->post_content);
        $this->assertSame($guid_before, get_post($seed['post'])->guid);
        $meta = get_post_meta($seed['post'], 'sr_links', true);
        $this->assertSame(self::NEW, $meta['home']);
        $this->assertSame(self::NEW . '/a', $meta['list'][0]);
        $this->assertSame('key stays', $meta[ self::OLD ]);
        $this->assertSame([ 'url' => self::NEW . '/x' ], get_option('sr_test_option'));

        $last = $this->read('searchreplace-get-last-run');
        $this->assertSame($out['session_id'], $last['wpmcp']['session_id']);
        $this->assertSame(3, $last['wpmcp']['rows_changed']);

        Rollback_Service::restore_session($out['session_id']);
        $this->assertSame($meta_before, $this->raw_meta($seed['post'], 'sr_links'));
        $this->assertSame($opt_before, $this->raw_option('sr_test_option'));
        clean_post_cache($seed['post']);
        $this->assertStringContainsString(self::OLD . '/about', get_post($seed['post'])->post_content);
    }

    public function test_run_never_touches_salts_active_plugins_or_own_options(): void
    {
        update_option('nonce_salt', 'salt-' . self::OLD);
        update_option('wpmcp_sr_probe', 'mine ' . self::OLD);
        $plugins = get_option('active_plugins');
        update_option('active_plugins', array_merge((array) $plugins, [ 'sr/' . self::OLD . '.php' ]));
        update_option('sr_plain', 'plain ' . self::OLD);

        $args = $this->args([ 'options' ]);
        $dry  = $this->read('searchreplace-dry-run', $args);
        $this->assertSame(1, $dry['rows']);
        $this->assertSame(3, $dry['excluded_rows']);

        $out = $this->write($args + [ 'dry_run_token' => $dry['dry_run_token'] ]);
        $this->assertSame(1, $out['result']['rows_changed']);
        $this->assertSame('salt-' . self::OLD, get_option('nonce_salt'));
        $this->assertSame('mine ' . self::OLD, get_option('wpmcp_sr_probe'));
        $this->assertContains('sr/' . self::OLD . '.php', get_option('active_plugins'));
        $this->assertSame('plain ' . self::NEW, get_option('sr_plain'));

        update_option('active_plugins', $plugins);
    }

    public function test_a_serialized_object_is_skipped_and_reported(): void
    {
        global $wpdb;
        $post = self::factory()->post->create();
        $raw  = 'O:8:"stdClass":1:{s:3:"url";s:' . strlen(self::OLD) . ':"' . self::OLD . '";}';
        $wpdb->insert($wpdb->postmeta, [ 'post_id' => $post, 'meta_key' => 'sr_obj', 'meta_value' => $raw ]);

        $out = $this->read('searchreplace-dry-run', $this->args([ 'postmeta' ]));
        $this->assertSame(0, $out['rows']);
        $this->assertSame(1, $out['tables'][0]['skipped_count']);
        $this->assertNull($out['dry_run_token']);
    }

    public function test_case_insensitive_flag(): void
    {
        $post = self::factory()->post->create([ 'post_content' => 'See HTTP://OLD-SR.EXAMPLE now' ]);
        $this->assertSame(0, $this->read('searchreplace-dry-run', $this->args([ 'posts' ]))['rows']);

        $args = $this->args([ 'posts' ], [ 'case_sensitive' => false ]);
        $dry  = $this->read('searchreplace-dry-run', $args);
        $this->assertSame(1, $dry['rows']);
        $this->write($args + [ 'dry_run_token' => $dry['dry_run_token'] ]);
        clean_post_cache($post);
        $this->assertSame('See ' . self::NEW . ' now', get_post($post)->post_content);
    }

    public function test_run_refuses_when_more_rows_match_than_the_dry_run_showed(): void
    {
        $this->seed();
        $args  = $this->args([ 'posts' ]);
        $token = $this->read('searchreplace-dry-run', $args)['dry_run_token'];
        self::factory()->post->create([ 'post_content' => 'late ' . self::OLD ]);

        $out = $this->write($args + [ 'dry_run_token' => $token ]);
        $this->assertSame('dry_run_stale', $this->code($out));
        $this->assertSame(0, (int) $GLOBALS['wpdb']->get_var('SELECT COUNT(*) FROM ' . Snapshot_Store::table_name()));
    }

    public function test_the_size_limit_withholds_the_token(): void
    {
        self::factory()->post->create([ 'post_content' => 'one ' . self::OLD ]);
        self::factory()->post->create([ 'post_content' => 'two ' . self::OLD ]);
        add_filter('wpmcp_search_replace_max_rows', static fn (): int => 1);

        $out = $this->read('searchreplace-dry-run', $this->args([ 'posts' ]));
        $this->assertTrue($out['truncated']);
        $this->assertNull($out['dry_run_token']);
        $this->assertStringContainsString('More than 1 rows', $out['limit']);
    }

    public function test_get_last_run_reads_better_search_replace_results(): void
    {
        set_transient(Search_Replace_Pack::BSR_RESULTS, [
            'search_for'       => 'old',
            'replace_with'     => 'new',
            'dry_run'          => 'on',
            'case_insensitive' => 'off',
            'replace_guids'    => 'off',
            'change'           => 5,
            'updates'          => 0,
            'table_reports'    => [ 'wp_posts' => [ 'change' => 5, 'updates' => 0, 'start' => 1.0, 'end' => 2.0 ] ],
            'tables'           => 1,
        ], DAY_IN_SECONDS);

        $out = $this->read('searchreplace-get-last-run');
        $this->assertNull($out['wpmcp']);
        $this->assertTrue($out['better_search_replace']['dry_run']);
        $this->assertSame(5, $out['better_search_replace']['changes']);
        $this->assertSame([ [ 'table' => 'wp_posts', 'changes' => 5, 'updates' => 0 ] ], $out['better_search_replace']['tables']);
    }
}
