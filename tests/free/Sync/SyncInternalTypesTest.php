<?php

namespace WPMCP\Tests\Free\Sync;

use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Sync\Change_Set_Applier;
use WPMCP\Tools\Sync\Change_Set_Builder;
use WPMCP\Tools\Sync\Change_Set_Format;

/**
 * Issue #506: every post type and meta key WP MCP itself creates is either
 * deliberately syncable or deliberately kept out of change sets. A new
 * internal store has to be classified here, so it cannot leak through sync
 * the way the chat conversation store did.
 */
class SyncInternalTypesTest extends \WP_UnitTestCase
{
    /**
     * Site design content an agent builds locally and pushes live on
     * purpose: block specs, widget specs and theme-builder site parts.
     */
    private const SYNCABLE_ON_PURPOSE_POST_TYPES = [
        'wpmcp_block',
        'wpmcp_widget',
        'wpmcp_template',
    ];

    /**
     * Plugin-internal stores that must never travel in a change set:
     * per-user private chat conversations, and agent memory (an apply would
     * otherwise publish a guardrail rule without the administrator's
     * review step).
     */
    private const NEVER_SYNCED_POST_TYPES = [
        'wpmcp_chat_convo',
        'wpmcp_memory',
    ];

    /**
     * WP MCP post meta that describes the content itself and travels with
     * it: builder specs, site-part targeting, media provenance and the
     * media optimization record.
     */
    private const SYNCABLE_ON_PURPOSE_META = [
        '_wpmcp_block_spec',
        '_wpmcp_widget_spec',
        '_wpmcp_template_conditions',
        '_wpmcp_template_priority',
        '_wpmcp_template_type',
        '_wpmcp_ai_model',
        '_wpmcp_ai_prompt',
        '_wpmcp_ai_provider',
        '_wpmcp_stock_attribution',
        '_wpmcp_stock_license',
        '_wpmcp_stock_license_url',
        '_wpmcp_stock_provider',
        '_wpmcp_stock_source_url',
        '_wpmcp_import_source_url',
        '_wpmcp_marketplace_source',
        '_wpmcp_optimized',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    /** @return string[] every PHP file under src/ */
    private static function sources(): array
    {
        $root  = dirname(__DIR__, 3) . '/src';
        $files = [];
        $it    = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ('php' === $file->getExtension()) {
                $files[] = $file->getPathname();
            }
        }
        sort($files);
        return $files;
    }

    /** @return string[] distinct matches of $pattern's first group across src/ */
    private static function scan(string $pattern): array
    {
        $found = [];
        foreach (self::sources() as $file) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading local plugin source in a test.
            if (preg_match_all($pattern, (string) file_get_contents($file), $m)) {
                foreach ($m[1] as $name) {
                    $found[ $name ] = true;
                }
            }
        }
        $names = array_keys($found);
        sort($names);
        return $names;
    }

    /** @return string[] WP MCP post types, from source and from the runtime registry */
    private static function own_post_types(): array
    {
        $types = array_merge(
            self::scan("/POST_TYPE\\s*=\\s*'(wpmcp_[a-z0-9_]+)'/"),
            self::scan("/register_post_type\\(\\s*'(wpmcp_[a-z0-9_]+)'/"),
            array_values(array_filter(get_post_types(), static fn ($t) => str_starts_with((string) $t, 'wpmcp_')))
        );
        $types = array_values(array_unique($types));
        sort($types);
        return $types;
    }

    public function test_the_scan_finds_the_known_stores(): void
    {
        $types = self::own_post_types();
        foreach (array_merge(self::SYNCABLE_ON_PURPOSE_POST_TYPES, self::NEVER_SYNCED_POST_TYPES) as $known) {
            $this->assertContains($known, $types, 'the source scan is not finding WP MCP post types, so the classification below proves nothing');
        }
    }

    public function test_every_wpmcp_post_type_is_deliberately_classified_for_sync(): void
    {
        foreach (self::own_post_types() as $type) {
            $on_purpose = in_array($type, self::SYNCABLE_ON_PURPOSE_POST_TYPES, true);
            $never      = in_array($type, self::NEVER_SYNCED_POST_TYPES, true);
            $this->assertTrue(
                $on_purpose xor $never,
                "WP MCP post type {$type} is not classified: add it to Change_Set_Format's non-syncable list, or to SYNCABLE_ON_PURPOSE_POST_TYPES here if pushing it from local to live is intended"
            );
            $this->assertSame($on_purpose, Change_Set_Format::is_syncable_post_type($type), "{$type}: sync behavior does not match its classification");
        }
    }

    public function test_internal_post_types_stay_non_syncable_whatever_the_filter_says(): void
    {
        $empty = static fn () => [];
        add_filter('wpmcp_sync_non_syncable_post_types', $empty);
        try {
            foreach (self::NEVER_SYNCED_POST_TYPES as $type) {
                $this->assertFalse(Change_Set_Format::is_syncable_post_type($type), $type);
            }
        } finally {
            remove_filter('wpmcp_sync_non_syncable_post_types', $empty);
        }
    }

    public function test_every_wpmcp_meta_key_is_deliberately_classified_for_sync(): void
    {
        $keys = self::scan("/'(_wpmcp_[a-z0-9_]+)'/");
        $this->assertContains('_wpmcp_chat_messages', $keys, 'the meta scan is not finding WP MCP meta keys');

        foreach ($keys as $key) {
            $on_purpose = in_array($key, self::SYNCABLE_ON_PURPOSE_META, true);
            $internal   = Change_Set_Format::is_internal_meta($key);
            $this->assertTrue(
                $on_purpose xor $internal,
                "WP MCP meta key {$key} is not classified: make Change_Set_Format::is_internal_meta() cover it, or add it to SYNCABLE_ON_PURPOSE_META here if it describes the content"
            );
        }
    }

    public function test_internal_meta_is_never_exported(): void
    {
        $post = self::factory()->post->create(['post_title' => 'staged copy']);
        add_post_meta($post, '_wpmcp_stage_of', 123);
        add_post_meta($post, '_wpmcp_stage_base', 'abc');
        add_post_meta($post, 'ordinary_key', 'kept');

        $set  = (new Change_Set_Builder())->build(['objects' => ['post:' . $post]]);
        $meta = (array) $set['objects'][0]['meta'];

        $this->assertArrayNotHasKey('_wpmcp_stage_of', $meta);
        $this->assertArrayNotHasKey('_wpmcp_stage_base', $meta);
        $this->assertArrayHasKey('ordinary_key', $meta);
    }

    public function test_internal_meta_in_a_crafted_change_set_is_never_written(): void
    {
        $post = self::factory()->post->create(['post_title' => 'target']);
        $set  = (new Change_Set_Builder())->build(['objects' => ['post:' . $post]]);

        $set['objects'][0]['meta']['_wpmcp_chat_owner'] = ['999'];
        $set['objects'][0]['meta']['_wpmcp_stage_of']   = ['1'];
        $set['objects'][0]['meta']['ordinary_key']      = ['written'];
        $set['objects'][0]['data']['post_title']        = 'target edited';
        $o                         = $set['objects'][0];
        $set['objects'][0]['hash'] = Change_Set_Format::post_hash(
            $o['data'],
            $o['meta'],
            $o['terms'],
            $o['hash_scope']['meta_keys'],
            $o['hash_scope']['taxonomies'],
            Change_Set_Format::url_tokens(home_url(), site_url())
        );
        $set['checksum'] = Change_Set_Format::checksum($set);

        (new Change_Set_Applier())->apply($set, ['dry_run' => false, 'session_id' => 'sync-506', 'force' => ['post:' . $post]]);

        $this->assertSame('target edited', get_post($post)->post_title, 'the ordinary part of the entry still applies');
        $this->assertSame('written', get_post_meta($post, 'ordinary_key', true));
        $this->assertSame('', get_post_meta($post, '_wpmcp_chat_owner', true));
        $this->assertSame('', get_post_meta($post, '_wpmcp_stage_of', true));
    }
}
