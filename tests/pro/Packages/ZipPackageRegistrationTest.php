<?php

namespace WPMCP\Tests\Pro\Packages;

use WPMCP\MCP\Registrar;
use WPMCP\Plugin;
use WPMCP\Pro\Gate;

/**
 * Registration and tier for install-package-from-zip (issue #282): a pro,
 * opt-in ability, absent from an unlicensed site's surface and registered
 * under install_plugins with non-idempotent, destructive-capable hints.
 */
class ZipPackageRegistrationTest extends \WP_UnitTestCase
{
    protected function tearDown(): void
    {
        Gate::set_pro_for_tests(null);
        parent::tearDown();
    }

    private function declared(): array
    {
        $registrar = new Registrar();
        Plugin::instance()->register_abilities_into($registrar);

        $out = [];
        foreach ($registrar->declared() as $ability) {
            $out[ $ability->name ] = $ability;
        }
        return $out;
    }

    public function test_declared_as_pro_under_install_plugins(): void
    {
        $ability = $this->declared()['wpmcp/install-package-from-zip'] ?? null;

        $this->assertNotNull($ability);
        $this->assertSame('pro', $ability->tier);
        $this->assertSame('install_plugins', $ability->capability);
        $this->assertSame('packages', $ability->domain);
        $this->assertFalse($ability->read_only_hint);
        $this->assertTrue($ability->destructive_hint, 'Replacing an installed package overwrites files.');
        $this->assertFalse($ability->idempotent_hint);
        $this->assertSame(
            ['attachment_id', 'sha256', 'type', 'confirm'],
            $ability->input_schema['required']
        );
        $this->assertSame(['plugin', 'theme'], $ability->input_schema['properties']['type']['enum']);
    }

    public function test_not_registered_on_the_free_tier(): void
    {
        $this->assertArrayNotHasKey('wpmcp/install-package-from-zip', wp_get_abilities());
    }

    public function test_search_themes_is_a_free_read(): void
    {
        $ability = $this->declared()['wpmcp/search-themes'] ?? null;

        $this->assertNotNull($ability);
        $this->assertSame('free', $ability->tier);
        $this->assertSame('install_themes', $ability->capability);
        $this->assertTrue($ability->read_only_hint);
    }
}
