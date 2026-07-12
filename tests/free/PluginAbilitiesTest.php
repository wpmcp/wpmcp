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

    public function test_all_73_abilities_register_by_default(): void
    {
        $registrar = Plugin::instance()->registrar();
        $this->assertCount(73, $registrar->all());
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
