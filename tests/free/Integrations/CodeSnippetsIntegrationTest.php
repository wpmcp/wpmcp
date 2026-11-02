<?php

/**
 * Fakes for the host snippet-manager plugin's API surface, so the integration
 * is exercised without the third-party plugin. They write to a real
 * {prefix}snippets table created by the test, so the db_rows snapshot path is
 * genuinely verified.
 */

namespace Code_Snippets {
    if (! class_exists('Code_Snippets\Snippet')) {
        class Snippet
        {
            public int $id = 0;
            public string $name = '';
            public string $desc = '';
            public string $code = '';
            public array $tags = [];
            public string $scope = 'global';
            public int $priority = 10;
            public bool $active = false;

            public function __construct(array $fields = [])
            {
                foreach ($fields as $k => $v) {
                    if ('tags' === $k && is_string($v)) {
                        $v = array_filter(array_map('trim', explode(',', $v)));
                    }
                    if (property_exists($this, $k)) {
                        settype($v, gettype($this->$k));
                        $this->$k = $v;
                    }
                }
            }
        }
    }

    if (! function_exists('Code_Snippets\code_snippets')) {
        function table(): string
        {
            global $wpdb;
            return $wpdb->prefix . 'snippets';
        }

        function code_snippets(): object
        {
            return (object) [ 'db' => (object) [ 'table' => table() ] ];
        }

        function get_snippets(array $ids = [], $network = null): array
        {
            global $wpdb;
            $rows = $wpdb->get_results('SELECT * FROM `' . table() . '` ORDER BY id ASC', ARRAY_A);
            return array_map(fn ($r) => new Snippet($r), (array) $rows);
        }

        function get_snippet($id, $network = null): Snippet
        {
            global $wpdb;
            $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM `' . table() . '` WHERE id = %d', (int) $id), ARRAY_A);
            return new Snippet($row ?: []);
        }

        function save_snippet(Snippet $s): int
        {
            global $wpdb;
            $data = [
                'name'        => $s->name,
                'description' => $s->desc,
                'code'        => $s->code,
                'tags'        => implode(', ', $s->tags),
                'scope'       => $s->scope,
                'priority'    => $s->priority,
                'active'      => $s->active ? 1 : 0,
            ];
            if ($s->id > 0) {
                $wpdb->update(table(), $data, [ 'id' => $s->id ]);
                return $s->id;
            }
            $wpdb->insert(table(), $data);
            return (int) $wpdb->insert_id;
        }

        function activate_snippet($id, $network = null)
        {
            global $wpdb;
            $wpdb->update(table(), [ 'active' => 1 ], [ 'id' => (int) $id ]);
            return get_snippet($id);
        }

        function deactivate_snippet($id, $network = null): void
        {
            global $wpdb;
            $wpdb->update(table(), [ 'active' => 0 ], [ 'id' => (int) $id ]);
        }
    }
}

namespace WPMCP\Tests\Free\Integrations {

    use WPMCP\Integrations\Code_Snippets_Integration;
    use WPMCP\Tools\Code\Php_Snippet_Guard;

    class CodeSnippetsIntegrationTest extends \WP_UnitTestCase
    {
        private Code_Snippets_Integration $integration;

        private static function table(): string
        {
            global $wpdb;
            return $wpdb->prefix . 'snippets';
        }

        protected function setUp(): void
        {
            parent::setUp();
            global $wpdb;
            $t = self::table();
            // The test framework rewrites CREATE TABLE to a TEMPORARY table, which SHOW TABLES
            // does not list; Database_Guard::valid_table needs a real table for the snapshot.
            remove_filter('query', [ $this, '_create_temporary_tables' ]);
            remove_filter('query', [ $this, '_drop_temporary_tables' ]);
            $wpdb->query("CREATE TABLE IF NOT EXISTS `{$t}` (
                id BIGINT(20) NOT NULL AUTO_INCREMENT, name TINYTEXT NOT NULL, description TEXT NOT NULL,
                code LONGTEXT NOT NULL, tags LONGTEXT NOT NULL, scope VARCHAR(15) NOT NULL DEFAULT 'global',
                priority SMALLINT NOT NULL DEFAULT 10, active TINYINT(1) NOT NULL DEFAULT 0,
                modified DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00', PRIMARY KEY (id))");
            $wpdb->query("TRUNCATE TABLE `{$t}`");
            $wpdb->insert($t, [ 'name' => 'Footer note', 'description' => '', 'code' => 'echo "hi";', 'tags' => 'front, footer', 'scope' => 'front-end', 'priority' => 5, 'active' => 1 ]);
            $wpdb->insert($t, [ 'name' => 'Admin tweak', 'description' => 'd', 'code' => 'add_filter("x", "__return_true");', 'tags' => 'admin', 'scope' => 'admin', 'priority' => 10, 'active' => 0 ]);

            wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
            add_filter('wpmcp_allow_php_exec', '__return_true');
            Php_Snippet_Guard::set_environment_override('development');
            $this->integration = new Code_Snippets_Integration();
        }

        protected function tearDown(): void
        {
            global $wpdb;
            remove_filter('wpmcp_allow_php_exec', '__return_true');
            Php_Snippet_Guard::set_environment_override(null);
            $wpdb->query('DROP TABLE IF EXISTS `' . self::table() . '`');
            parent::tearDown();
        }

        private function row(int $id): array
        {
            global $wpdb;
            return (array) $wpdb->get_row($wpdb->prepare('SELECT * FROM `' . self::table() . '` WHERE id = %d', $id), ARRAY_A);
        }

        public function test_available_when_host_functions_exist(): void
        {
            $this->assertTrue($this->integration->is_available());
            $this->assertSame('code-snippets', $this->integration->integration());
        }

        public function test_list_snippets_reports_scope_status_and_tags_without_code(): void
        {
            $out = $this->integration->handle_read([ 'operation' => 'list-snippets' ]);

            $this->assertArrayNotHasKey('error', $out);
            $this->assertSame(2, $out['result']['total']);
            $first = $out['result']['snippets'][0];
            $this->assertSame('Footer note', $first['name']);
            $this->assertSame('front-end', $first['scope']);
            $this->assertSame('active', $first['status']);
            $this->assertSame([ 'front', 'footer' ], $first['tags']);
            $this->assertArrayNotHasKey('code', $first);
        }

        public function test_list_snippets_filters_by_status_scope_and_tag(): void
        {
            $inactive = $this->integration->handle_read([ 'operation' => 'list-snippets', 'args' => [ 'status' => 'inactive' ] ]);
            $this->assertSame([ 'Admin tweak' ], array_column($inactive['result']['snippets'], 'name'));

            $scoped = $this->integration->handle_read([ 'operation' => 'list-snippets', 'args' => [ 'scope' => 'front-end' ] ]);
            $this->assertSame([ 'Footer note' ], array_column($scoped['result']['snippets'], 'name'));

            $tagged = $this->integration->handle_read([ 'operation' => 'list-snippets', 'args' => [ 'tag' => 'admin' ] ]);
            $this->assertSame([ 'Admin tweak' ], array_column($tagged['result']['snippets'], 'name'));
        }

        public function test_get_snippet_returns_code_and_validation(): void
        {
            $out = $this->integration->handle_read([ 'operation' => 'get-snippet', 'args' => [ 'id' => 2 ] ]);

            $this->assertArrayNotHasKey('error', $out);
            $this->assertSame('add_filter("x", "__return_true");', $out['result']['snippet']['code']);
            $this->assertTrue($out['result']['validation']['syntax_valid']);

            $missing = $this->integration->handle_read([ 'operation' => 'get-snippet', 'args' => [ 'id' => 999 ] ]);
            $this->assertSame('snippet_missing', $missing['error']['code']);
        }

        public function test_create_snippet_is_inactive_by_default_and_reports_validation(): void
        {
            $out = $this->integration->handle_write([
                'operation' => 'create-snippet',
                'args'      => [ 'name' => 'New one', 'code' => 'add_action("init", "__return_null");', 'tags' => [ 'a', 'b' ] ],
            ]);

            $this->assertArrayNotHasKey('error', $out);
            $id = $out['result']['snippet']['id'];
            $this->assertSame('inactive', $out['result']['snippet']['status']);
            $this->assertSame('0', $this->row($id)['active']);
            $this->assertSame('a, b', $this->row($id)['tags']);
            $this->assertTrue($out['result']['validation']['syntax_valid']);
        }

        public function test_create_snippet_refuses_invalid_php(): void
        {
            $out = $this->integration->handle_write([
                'operation' => 'create-snippet',
                'args'      => [ 'name' => 'Broken', 'code' => 'echo "unterminated;' ],
            ]);

            $this->assertSame('validation_failed', $out['error']['code']);
            $this->assertSame(2, (int) $this->integration->handle_read([ 'operation' => 'list-snippets' ])['result']['total'], 'nothing was written');
        }

        public function test_update_code_is_snapshot_wrapped(): void
        {
            $out = $this->integration->handle_write([
                'operation' => 'update-snippet-code',
                'args'      => [ 'id' => 2, 'code' => 'add_filter("y", "__return_false");' ],
            ]);

            $this->assertArrayNotHasKey('error', $out);
            $this->assertTrue($out['recoverable']);
            $this->assertNotEmpty($out['operation_id']);
            $this->assertSame('add_filter("y", "__return_false");', $this->row(2)['code']);
        }

        public function test_update_code_of_active_snippet_refuses_invalid_php(): void
        {
            $out = $this->integration->handle_write([
                'operation' => 'update-snippet-code',
                'args'      => [ 'id' => 1, 'code' => 'echo "broken;' ],
            ]);

            $this->assertSame('validation_failed', $out['error']['code']);
            $this->assertSame('echo "hi";', $this->row(1)['code'], 'active snippet code untouched');
        }

        public function test_activate_snippet_runs_when_code_passes_lint(): void
        {
            $out = $this->integration->handle_write([ 'operation' => 'activate-snippet', 'args' => [ 'id' => 2 ] ]);

            $this->assertArrayNotHasKey('error', $out);
            $this->assertSame('active', $out['result']['snippet']['status']);
            $this->assertSame('1', $this->row(2)['active']);
            $this->assertTrue($out['recoverable']);
        }

        public function test_activate_snippet_is_refused_when_php_is_invalid(): void
        {
            global $wpdb;
            $wpdb->update(self::table(), [ 'code' => 'function ( {' ], [ 'id' => 2 ]);

            $out = $this->integration->handle_write([ 'operation' => 'activate-snippet', 'args' => [ 'id' => 2 ] ]);

            $this->assertSame('validation_failed', $out['error']['code']);
            $this->assertSame('0', $this->row(2)['active'], 'snippet stays inactive');
        }

        public function test_activate_snippet_is_refused_when_code_is_unsafe(): void
        {
            global $wpdb;
            $wpdb->update(self::table(), [ 'code' => 'eval($_GET["x"]);' ], [ 'id' => 2 ]);

            $out = $this->integration->handle_write([ 'operation' => 'activate-snippet', 'args' => [ 'id' => 2 ] ]);

            $this->assertSame('validation_failed', $out['error']['code']);
            $this->assertSame('0', $this->row(2)['active']);
        }

        public function test_activate_snippet_is_refused_when_php_execution_is_gated(): void
        {
            remove_filter('wpmcp_allow_php_exec', '__return_true');
            add_filter('wpmcp_allow_php_exec', '__return_false');

            $out = $this->integration->handle_write([ 'operation' => 'activate-snippet', 'args' => [ 'id' => 2 ] ]);
            remove_filter('wpmcp_allow_php_exec', '__return_false');

            $this->assertSame('php_execution_disabled', $out['error']['code']);
            $this->assertSame('0', $this->row(2)['active']);
        }

        public function test_deactivate_snippet(): void
        {
            $out = $this->integration->handle_write([ 'operation' => 'deactivate-snippet', 'args' => [ 'id' => 1 ] ]);

            $this->assertArrayNotHasKey('error', $out);
            $this->assertSame('inactive', $out['result']['snippet']['status']);
            $this->assertSame('0', $this->row(1)['active']);
        }
    }
}
