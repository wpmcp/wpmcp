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

    public function test_all_144_abilities_register_by_default(): void
    {
        $registrar = Plugin::instance()->registrar();
        $this->assertCount(144, $registrar->all());
    }

    public function test_no_pro_tier_ability_registers_without_a_license(): void
    {
        // Real license path (no Gate test override): the harness boots the
        // live Freemius SDK with an unlicensed install, so every 'pro' tier
        // ability must have been skipped at registration time (issue #54).
        $pro = array_filter(
            Plugin::instance()->registrar()->all(),
            fn ($ability) => 'pro' === $ability->tier
        );

        $this->assertCount(0, $pro);
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

    /**
     * Until issue #82, update-rows carried explicit destructive/non-idempotent
     * hint overrides because a raw row update was irreversible. Now that the
     * write is snapshot-backed and restorable (when the table has a primary
     * key), it uses the standard 'update' operation hint mapping, exactly
     * like update-post: not read-only, not destructive, idempotent.
     */
    public function test_update_rows_uses_standard_update_hints_now_snapshot_backed(): void
    {
        $abilities = $this->index(Plugin::instance()->registrar()->all());

        $this->assertFalse($abilities['wpmcp/update-rows']->read_only_hint);
        $this->assertFalse($abilities['wpmcp/update-rows']->destructive_hint);
        $this->assertTrue($abilities['wpmcp/update-rows']->idempotent_hint);
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

    public function test_i18n_abilities_are_tagged_translation_domain(): void
    {
        $abilities = $this->index(Plugin::instance()->registrar()->all());

        if (! isset($abilities['wpmcp/list-languages'])) {
            $this->markTestSkipped('No i18n plugin active in this test environment.');
        }

        $this->assertSame('translation', $abilities['wpmcp/list-languages']->domain);
        $this->assertSame('read', $abilities['wpmcp/list-languages']->operation);
        $this->assertSame('translation', $abilities['wpmcp/get-post-translations']->domain);
        $this->assertSame('read', $abilities['wpmcp/get-post-translations']->operation);
        $this->assertSame('translation', $abilities['wpmcp/set-post-language']->domain);
        $this->assertSame('update', $abilities['wpmcp/set-post-language']->operation);
        $this->assertSame('translation', $abilities['wpmcp/link-post-translations']->domain);
        $this->assertSame('update', $abilities['wpmcp/link-post-translations']->operation);
    }

    public function test_connect_abilities_are_tagged_connect_domain(): void
    {
        $abilities = $this->index(Plugin::instance()->registrar()->all());

        $this->assertSame('connect', $abilities['wpmcp/get-connection-info']->domain);
        $this->assertSame('read', $abilities['wpmcp/get-connection-info']->operation);
        $this->assertSame('manage_options', $abilities['wpmcp/get-connection-info']->capability);
        $this->assertTrue($abilities['wpmcp/get-connection-info']->read_only_hint);

        $this->assertSame('connect', $abilities['wpmcp/list-tool-catalog']->domain);
        $this->assertSame('read', $abilities['wpmcp/list-tool-catalog']->operation);
        $this->assertSame('manage_options', $abilities['wpmcp/list-tool-catalog']->capability);
        $this->assertTrue($abilities['wpmcp/list-tool-catalog']->read_only_hint);
    }

    public function test_governance_abilities_are_tagged_governance_domain(): void
    {
        $abilities = $this->index(Plugin::instance()->registrar()->all());

        $this->assertSame('governance', $abilities['wpmcp/get-governance-settings']->domain);
        $this->assertSame('read', $abilities['wpmcp/get-governance-settings']->operation);
        $this->assertSame('governance', $abilities['wpmcp/update-governance-settings']->domain);
        $this->assertSame('update', $abilities['wpmcp/update-governance-settings']->operation);
        $this->assertSame('governance', $abilities['wpmcp/list-governance-audit-log']->domain);
        $this->assertSame('read', $abilities['wpmcp/list-governance-audit-log']->operation);
        $this->assertSame('governance', $abilities['wpmcp/create-identity']->domain);
        $this->assertSame('create', $abilities['wpmcp/create-identity']->operation);
        $this->assertSame('governance', $abilities['wpmcp/list-identities']->domain);
        $this->assertSame('read', $abilities['wpmcp/list-identities']->operation);
        $this->assertSame('governance', $abilities['wpmcp/delete-identity']->domain);
        $this->assertSame('delete', $abilities['wpmcp/delete-identity']->operation);
    }

    public function test_analytics_abilities_are_tagged_analytics_domain(): void
    {
        $abilities = $this->index(Plugin::instance()->registrar()->all());

        $this->assertSame('analytics', $abilities['wpmcp/get-analytics-connection-status']->domain);
        $this->assertSame('read', $abilities['wpmcp/get-analytics-connection-status']->operation);
        $this->assertSame('manage_options', $abilities['wpmcp/get-analytics-connection-status']->capability);

        $this->assertSame('analytics', $abilities['wpmcp/get-analytics-summary']->domain);
        $this->assertSame('read', $abilities['wpmcp/get-analytics-summary']->operation);
        $this->assertSame('manage_options', $abilities['wpmcp/get-analytics-summary']->capability);

        $this->assertSame('analytics', $abilities['wpmcp/get-top-pages']->domain);
        $this->assertSame('read', $abilities['wpmcp/get-top-pages']->operation);

        $this->assertSame('analytics', $abilities['wpmcp/get-search-console-summary']->domain);
        $this->assertSame('read', $abilities['wpmcp/get-search-console-summary']->operation);

        $this->assertSame('analytics', $abilities['wpmcp/get-search-console-queries']->domain);
        $this->assertSame('read', $abilities['wpmcp/get-search-console-queries']->operation);
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
