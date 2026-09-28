<?php
/**
 * Test doubles and tables for the plugin-data pair (issue #299): JetEngine,
 * Pods and TranslatePress, none of which is installed into the shared test
 * core.
 *
 * - jet_engine(): the public field registry the integration calls,
 *   jet_engine()->meta_boxes->get_fields_for_context('post_type', $slug),
 *   returning JetEngine's field arrays (name, title, type, object_type).
 * - pods_api(): PodsAPI::load_pod(['name' => $post_type]) returning the pod
 *   with its storage, pod_table and fields keyed by name, the way a
 *   Pods\Whatsit\Pod answers array access.
 * - The Pods table-storage table and the TranslatePress dictionary table,
 *   copied from the plugins' own DDL: PodsAPI::save_pod() (Pods 3.3.9.2,
 *   `id` BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY plus one column per
 *   simple field, from each field type's schema()) and
 *   TRP_Query::check_table() (TranslatePress 3.3.6).
 *
 * Presence is driven through the wpmcp_jetengine_active, wpmcp_pods_active
 * and wpmcp_translatepress_active filters, so loading these doubles never
 * makes a plugin count as active by itself. DDL commits implicitly, so the
 * tables are created in wpSetUpBeforeClass() and dropped in
 * wpTearDownAfterClass().
 */

class Wpmcp_Test_JetEngine_Meta_Boxes
{
    /** @var array<string, array<int, array<string, mixed>>> post type => field arrays */
    public static array $fields = [];

    public function get_fields_for_context($context, $object = null)
    {
        if ('post_type' !== $context) {
            return [];
        }
        return self::$fields[ (string) $object ] ?? [];
    }
}

class Wpmcp_Test_JetEngine
{
    public $meta_boxes;

    public function __construct()
    {
        $this->meta_boxes = new Wpmcp_Test_JetEngine_Meta_Boxes();
    }
}

if (! function_exists('jet_engine')) {
    function jet_engine()
    {
        static $instance = null;
        if (null === $instance) {
            $instance = new Wpmcp_Test_JetEngine();
        }
        return $instance;
    }
}

class Wpmcp_Test_Pods_API
{
    /** @var array<string, array<string, mixed>> pod name => pod */
    public static array $pods = [];

    public function load_pod($params, $strict = false)
    {
        $name = is_array($params) ? (string) ($params['name'] ?? '') : (string) $params;
        return self::$pods[ $name ] ?? false;
    }
}

if (! function_exists('pods_api')) {
    function pods_api($pod = null, $format = null)
    {
        return new Wpmcp_Test_Pods_API();
    }
}

if (! function_exists('wpmcp_test_pods_table')) {
    function wpmcp_test_pods_table(string $pod): string
    {
        global $wpdb;
        return $wpdb->prefix . 'pods_' . $pod;
    }

    /** A Pods table-storage table with a text, a number and a boolean column. */
    function wpmcp_test_create_pods_table(string $pod): void
    {
        global $wpdb;
        $table = wpmcp_test_pods_table($pod);
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test fixture DDL built from literals.
        $wpdb->query("DROP TABLE IF EXISTS `{$table}`");
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test fixture DDL built from literals.
        $wpdb->query("CREATE TABLE `{$table}` (`id` BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY, `isbn` VARCHAR(255), `pages` DECIMAL(12,0), `in_print` BOOL DEFAULT 0) {$wpdb->get_charset_collate()}");
    }

    function wpmcp_test_drop_pods_table(string $pod): void
    {
        global $wpdb;
        $table = wpmcp_test_pods_table($pod);
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test fixture DDL built from literals.
        $wpdb->query("DROP TABLE IF EXISTS `{$table}`");
    }

    function wpmcp_test_pods_rows(string $pod, int $id): array
    {
        global $wpdb;
        return (array) $wpdb->get_results($wpdb->prepare('SELECT * FROM %i WHERE id = %d', wpmcp_test_pods_table($pod), $id), ARRAY_A);
    }

    function wpmcp_test_trp_table(string $default, string $language): string
    {
        global $wpdb;
        return $wpdb->prefix . 'trp_dictionary_' . strtolower($default) . '_' . strtolower($language);
    }

    function wpmcp_test_create_trp_table(string $default, string $language): void
    {
        global $wpdb;
        $table = wpmcp_test_trp_table($default, $language);
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test fixture DDL built from literals.
        $wpdb->query("DROP TABLE IF EXISTS `{$table}`");
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test fixture DDL built from literals.
        $wpdb->query("CREATE TABLE `{$table}`(
            id bigint(20) AUTO_INCREMENT NOT NULL PRIMARY KEY,
            original  longtext NOT NULL,
            translated  longtext,
            status int(20) DEFAULT 0,
            block_type int(20) DEFAULT 0,
            original_id bigint(20) DEFAULT NULL,
            UNIQUE KEY id (id) ) {$wpdb->get_charset_collate()}");
    }

    function wpmcp_test_drop_trp_table(string $default, string $language): void
    {
        global $wpdb;
        $table = wpmcp_test_trp_table($default, $language);
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test fixture DDL built from literals.
        $wpdb->query("DROP TABLE IF EXISTS `{$table}`");
    }

    function wpmcp_test_trp_insert(string $default, string $language, string $original, ?string $translated = null, int $status = 0): int
    {
        global $wpdb;
        $wpdb->insert(wpmcp_test_trp_table($default, $language), [
            'original'    => $original,
            'translated'  => $translated,
            'status'      => $status,
            'block_type'  => 0,
            'original_id' => null,
        ]);
        return (int) $wpdb->insert_id;
    }

    function wpmcp_test_trp_rows(string $default, string $language): array
    {
        global $wpdb;
        return (array) $wpdb->get_results($wpdb->prepare('SELECT * FROM %i ORDER BY id ASC', wpmcp_test_trp_table($default, $language)), ARRAY_A);
    }
}
