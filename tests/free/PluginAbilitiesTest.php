<?php

namespace WPMCP\Tests\Free;

use WPMCP\MCP\Ability;
use WPMCP\Plugin;

class PluginAbilitiesTest extends \WP_UnitTestCase
{
    public static function wpSetUpBeforeClass(): void
    {
        // WP_Abilities_Registry::get_instance() fires wp_abilities_api_init
        // lazily, the first time anything touches the registry (see
        // class-wp-abilities-registry.php). Nothing else in this suite
        // exercises the real Abilities API, so trigger it once here to
        // populate the Plugin's shared Registrar exactly as production would.
        if (0 === did_action('wp_abilities_api_init')) {
            do_action('wp_abilities_api_init');
        }
    }

    public function test_all_105_abilities_register_by_default(): void
    {
        $registrar = Plugin::instance()->registrar();
        $this->assertCount(105, $registrar->all());
    }

    public function test_read_ability_has_read_only_annotation(): void
    {
        $registrar = Plugin::instance()->registrar();
        $abilities = $this->index($registrar->all());

        $this->assertTrue($abilities['wpmcp/get-post']->read_only_hint);
        $this->assertFalse($abilities['wpmcp/get-post']->destructive_hint);
    }

    public function test_delete_ability_has_destructive_annotation(): void
    {
        $registrar = Plugin::instance()->registrar();
        $abilities = $this->index($registrar->all());

        $this->assertFalse($abilities['wpmcp/delete-post']->read_only_hint);
        $this->assertTrue($abilities['wpmcp/delete-post']->destructive_hint);
    }

    public function test_core_abilities_are_tagged_core_domain(): void
    {
        $abilities = $this->index(Plugin::instance()->registrar()->all());

        $this->assertSame('core', $abilities['wpmcp/get-page']->domain);
        $this->assertSame('read', $abilities['wpmcp/get-page']->operation);
        $this->assertSame('core', $abilities['wpmcp/list-operations']->domain);
        $this->assertSame('read', $abilities['wpmcp/list-operations']->operation);
        $this->assertSame('core', $abilities['wpmcp/rollback-operation']->domain);
        $this->assertSame('update', $abilities['wpmcp/rollback-operation']->operation);
    }

    public function test_content_abilities_are_tagged_content_domain(): void
    {
        $abilities = $this->index(Plugin::instance()->registrar()->all());

        $this->assertSame('content', $abilities['wpmcp/get-post']->domain);
        $this->assertSame('read', $abilities['wpmcp/get-post']->operation);
        $this->assertSame('content', $abilities['wpmcp/create-post']->domain);
        $this->assertSame('create', $abilities['wpmcp/create-post']->operation);
        $this->assertSame('content', $abilities['wpmcp/update-post']->domain);
        $this->assertSame('update', $abilities['wpmcp/update-post']->operation);
        $this->assertSame('content', $abilities['wpmcp/delete-post']->domain);
        $this->assertSame('delete', $abilities['wpmcp/delete-post']->operation);
    }

    public function test_revisions_abilities_are_tagged_content_domain(): void
    {
        $abilities = $this->index(Plugin::instance()->registrar()->all());

        $this->assertSame('content', $abilities['wpmcp/list-revisions']->domain);
        $this->assertSame('read', $abilities['wpmcp/list-revisions']->operation);
        $this->assertSame('content', $abilities['wpmcp/get-revision']->domain);
        $this->assertSame('read', $abilities['wpmcp/get-revision']->operation);
        $this->assertSame('content', $abilities['wpmcp/restore-revision']->domain);
        $this->assertSame('update', $abilities['wpmcp/restore-revision']->operation);
    }

    public function test_media_abilities_are_tagged_media_domain(): void
    {
        $abilities = $this->index(Plugin::instance()->registrar()->all());

        $this->assertSame('media', $abilities['wpmcp/get-media']->domain);
        $this->assertSame('read', $abilities['wpmcp/get-media']->operation);
        $this->assertSame('media', $abilities['wpmcp/delete-media']->domain);
        $this->assertSame('delete', $abilities['wpmcp/delete-media']->operation);
        $this->assertSame('media', $abilities['wpmcp/sideload-image']->domain);
        $this->assertSame('create', $abilities['wpmcp/sideload-image']->operation);
    }

    public function test_settings_abilities_are_tagged_settings_domain(): void
    {
        $abilities = $this->index(Plugin::instance()->registrar()->all());

        $this->assertSame('settings', $abilities['wpmcp/get-settings']->domain);
        $this->assertSame('read', $abilities['wpmcp/get-settings']->operation);
        $this->assertSame('settings', $abilities['wpmcp/update-settings']->domain);
        $this->assertSame('update', $abilities['wpmcp/update-settings']->operation);
    }

    public function test_meta_abilities_are_tagged_meta_and_settings_domain(): void
    {
        $abilities = $this->index(Plugin::instance()->registrar()->all());

        $this->assertSame('meta', $abilities['wpmcp/get-post-meta']->domain);
        $this->assertSame('read', $abilities['wpmcp/get-post-meta']->operation);
        $this->assertSame('meta', $abilities['wpmcp/set-post-meta']->domain);
        $this->assertSame('update', $abilities['wpmcp/set-post-meta']->operation);
        $this->assertSame('settings', $abilities['wpmcp/get-option']->domain);
        $this->assertSame('read', $abilities['wpmcp/get-option']->operation);
        $this->assertSame('settings', $abilities['wpmcp/update-option']->domain);
        $this->assertSame('update', $abilities['wpmcp/update-option']->operation);
    }

    public function test_diagnostics_abilities_are_tagged_diagnostics_domain(): void
    {
        $abilities = $this->index(Plugin::instance()->registrar()->all());

        $this->assertSame('diagnostics', $abilities['wpmcp/get-debug-config']->domain);
        $this->assertSame('read', $abilities['wpmcp/get-debug-config']->operation);
        $this->assertSame('diagnostics', $abilities['wpmcp/get-debug-log']->domain);
        $this->assertSame('read', $abilities['wpmcp/get-debug-log']->operation);
        $this->assertSame('diagnostics', $abilities['wpmcp/list-transients']->domain);
        $this->assertSame('read', $abilities['wpmcp/list-transients']->operation);
        $this->assertSame('diagnostics', $abilities['wpmcp/delete-transient']->domain);
        $this->assertSame('update', $abilities['wpmcp/delete-transient']->operation);
    }

    public function test_users_abilities_are_tagged_users_domain(): void
    {
        $abilities = $this->index(Plugin::instance()->registrar()->all());

        $this->assertSame('users', $abilities['wpmcp/list-users']->domain);
        $this->assertSame('read', $abilities['wpmcp/list-users']->operation);
        $this->assertSame('users', $abilities['wpmcp/create-user']->domain);
        $this->assertSame('create', $abilities['wpmcp/create-user']->operation);
        $this->assertSame('users', $abilities['wpmcp/update-user']->domain);
        $this->assertSame('update', $abilities['wpmcp/update-user']->operation);
    }

    public function test_comments_abilities_are_tagged_comments_domain(): void
    {
        $abilities = $this->index(Plugin::instance()->registrar()->all());

        $this->assertSame('comments', $abilities['wpmcp/list-comments']->domain);
        $this->assertSame('read', $abilities['wpmcp/list-comments']->operation);
        $this->assertSame('comments', $abilities['wpmcp/moderate-comment']->domain);
        $this->assertSame('update', $abilities['wpmcp/moderate-comment']->operation);
        $this->assertSame('comments', $abilities['wpmcp/delete-comment']->domain);
        $this->assertSame('delete', $abilities['wpmcp/delete-comment']->operation);
    }

    public function test_packages_abilities_are_tagged_packages_domain(): void
    {
        $abilities = $this->index(Plugin::instance()->registrar()->all());

        $this->assertSame('packages', $abilities['wpmcp/list-plugins']->domain);
        $this->assertSame('read', $abilities['wpmcp/list-plugins']->operation);
        $this->assertSame('packages', $abilities['wpmcp/install-plugin']->domain);
        $this->assertSame('create', $abilities['wpmcp/install-plugin']->operation);
        $this->assertSame('packages', $abilities['wpmcp/delete-plugin']->domain);
        $this->assertSame('delete', $abilities['wpmcp/delete-plugin']->operation);
        $this->assertSame('packages', $abilities['wpmcp/list-themes']->domain);
        $this->assertSame('read', $abilities['wpmcp/list-themes']->operation);
        $this->assertSame('packages', $abilities['wpmcp/delete-theme']->domain);
        $this->assertSame('delete', $abilities['wpmcp/delete-theme']->operation);
    }

    public function test_update_plugin_and_update_theme_keep_destructive_irreversible_hints(): void
    {
        $abilities = $this->index(Plugin::instance()->registrar()->all());

        $this->assertTrue($abilities['wpmcp/update-plugin']->destructive_hint);
        $this->assertFalse($abilities['wpmcp/update-plugin']->idempotent_hint);
        $this->assertTrue($abilities['wpmcp/update-theme']->destructive_hint);
        $this->assertFalse($abilities['wpmcp/update-theme']->idempotent_hint);
    }

    public function test_database_abilities_are_tagged_database_domain(): void
    {
        $abilities = $this->index(Plugin::instance()->registrar()->all());

        $this->assertSame('database', $abilities['wpmcp/query']->domain);
        $this->assertSame('read', $abilities['wpmcp/query']->operation);
        $this->assertSame('database', $abilities['wpmcp/insert-row']->domain);
        $this->assertSame('create', $abilities['wpmcp/insert-row']->operation);
        $this->assertSame('database', $abilities['wpmcp/delete-rows']->domain);
        $this->assertSame('delete', $abilities['wpmcp/delete-rows']->operation);
    }

    public function test_update_rows_keeps_destructive_irreversible_hint(): void
    {
        $abilities = $this->index(Plugin::instance()->registrar()->all());

        $this->assertTrue($abilities['wpmcp/update-rows']->destructive_hint);
        $this->assertFalse($abilities['wpmcp/update-rows']->idempotent_hint);
    }

    public function test_filesystem_abilities_are_tagged_filesystem_domain(): void
    {
        $abilities = $this->index(Plugin::instance()->registrar()->all());

        $this->assertSame('filesystem', $abilities['wpmcp/read-file']->domain);
        $this->assertSame('read', $abilities['wpmcp/read-file']->operation);
        $this->assertSame('filesystem', $abilities['wpmcp/write-file']->domain);
        $this->assertSame('update', $abilities['wpmcp/write-file']->operation);
        $this->assertSame('filesystem', $abilities['wpmcp/delete-file']->domain);
        $this->assertSame('delete', $abilities['wpmcp/delete-file']->operation);
    }

    public function test_performance_and_security_abilities_are_tagged(): void
    {
        $abilities = $this->index(Plugin::instance()->registrar()->all());

        $this->assertSame('performance', $abilities['wpmcp/analyze-performance']->domain);
        $this->assertSame('read', $abilities['wpmcp/analyze-performance']->operation);
        $this->assertSame('security', $abilities['wpmcp/scan-security']->domain);
        $this->assertSame('read', $abilities['wpmcp/scan-security']->operation);
    }

    public function test_woocommerce_abilities_are_tagged_woocommerce_domain(): void
    {
        $abilities = $this->index(Plugin::instance()->registrar()->all());

        $this->assertSame('woocommerce', $abilities['wpmcp/list-products']->domain);
        $this->assertSame('read', $abilities['wpmcp/list-products']->operation);
        $this->assertSame('woocommerce', $abilities['wpmcp/create-product']->domain);
        $this->assertSame('create', $abilities['wpmcp/create-product']->operation);
        $this->assertSame('woocommerce', $abilities['wpmcp/update-order-status']->domain);
        $this->assertSame('update', $abilities['wpmcp/update-order-status']->operation);
        $this->assertSame('woocommerce', $abilities['wpmcp/delete-product']->domain);
        $this->assertSame('delete', $abilities['wpmcp/delete-product']->operation);
    }

    public function test_menus_abilities_are_tagged_menus_domain(): void
    {
        $abilities = $this->index(Plugin::instance()->registrar()->all());

        $this->assertSame('menus', $abilities['wpmcp/list-menus']->domain);
        $this->assertSame('read', $abilities['wpmcp/list-menus']->operation);
        $this->assertSame('menus', $abilities['wpmcp/create-menu']->domain);
        $this->assertSame('create', $abilities['wpmcp/create-menu']->operation);
        $this->assertSame('menus', $abilities['wpmcp/update-menu-item']->domain);
        $this->assertSame('update', $abilities['wpmcp/update-menu-item']->operation);
        $this->assertSame('menus', $abilities['wpmcp/delete-menu']->domain);
        $this->assertSame('delete', $abilities['wpmcp/delete-menu']->operation);
    }

    public function test_seo_abilities_are_tagged_seo_domain(): void
    {
        $abilities = $this->index(Plugin::instance()->registrar()->all());

        $this->assertSame('seo', $abilities['wpmcp/get-seo-status']->domain);
        $this->assertSame('read', $abilities['wpmcp/get-seo-status']->operation);

        if (! isset($abilities['wpmcp/get-seo-meta'])) {
            return;
        }

        $this->assertSame('seo', $abilities['wpmcp/get-seo-meta']->domain);
        $this->assertSame('read', $abilities['wpmcp/get-seo-meta']->operation);
        $this->assertSame('seo', $abilities['wpmcp/update-seo-meta']->domain);
        $this->assertSame('update', $abilities['wpmcp/update-seo-meta']->operation);
    }

    /** @param Ability[] $abilities @return array<string, Ability> */
    private function index(array $abilities): array
    {
        $out = [];
        foreach ($abilities as $a) {
            $out[$a->name] = $a;
        }
        return $out;
    }
}
