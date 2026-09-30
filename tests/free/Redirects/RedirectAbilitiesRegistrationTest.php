<?php

namespace WPMCP\Tests\Free\Redirects;

use WPMCP\MCP\Ability;
use WPMCP\MCP\Registrar;
use WPMCP\Plugin;

/**
 * The redirect manager's MCP contract (issue #128): five free abilities, the
 * right capability on each, and annotations that tell a client the truth
 * about what each one does.
 *
 * The capability split is the interesting assertion. Seeing why a URL bounces
 * or which links are dead is editor-level insight, but changing where a URL
 * sends every visitor is a site-wide decision, so the three writes are
 * manage_options while the two reads are edit_posts.
 */
class RedirectAbilitiesRegistrationTest extends \WP_UnitTestCase
{
    /** @return array<string, Ability> */
    private function abilities(): array
    {
        $registrar = new Registrar();
        Plugin::instance()->register_abilities_into($registrar);

        $indexed = [];
        foreach ($registrar->all() as $ability) {
            $indexed[ $ability->name ] = $ability;
        }
        return $indexed;
    }

    public function test_all_five_redirect_tools_register_on_the_free_tier(): void
    {
        $abilities = $this->abilities();

        foreach ([
            'wpmcp/list-redirects',
            'wpmcp/create-redirect',
            'wpmcp/update-redirect',
            'wpmcp/delete-redirect',
            'wpmcp/find-broken-links',
        ] as $name) {
            $this->assertArrayHasKey($name, $abilities, "Expected {$name} to be registered");
            $this->assertSame('free', $abilities[ $name ]->tier);
            $this->assertSame('seo', $abilities[ $name ]->domain);
        }
    }

    public function test_reads_are_editor_level_and_writes_are_admin_level(): void
    {
        $abilities = $this->abilities();

        $this->assertSame('edit_posts', $abilities['wpmcp/list-redirects']->capability);
        $this->assertSame('edit_posts', $abilities['wpmcp/find-broken-links']->capability);
        $this->assertSame('manage_options', $abilities['wpmcp/create-redirect']->capability);
        $this->assertSame('manage_options', $abilities['wpmcp/update-redirect']->capability);
        $this->assertSame('manage_options', $abilities['wpmcp/delete-redirect']->capability);
    }

    public function test_annotations_match_what_each_tool_actually_does(): void
    {
        $abilities = $this->abilities();

        $this->assertTrue($abilities['wpmcp/list-redirects']->read_only_hint);
        $this->assertFalse($abilities['wpmcp/find-broken-links']->read_only_hint);
        $this->assertFalse($abilities['wpmcp/create-redirect']->read_only_hint);
        $this->assertTrue($abilities['wpmcp/update-redirect']->idempotent_hint);
        $this->assertTrue($abilities['wpmcp/delete-redirect']->destructive_hint);
    }

    public function test_the_write_schemas_require_what_the_tools_require(): void
    {
        $abilities = $this->abilities();

        $this->assertSame(['source'], $abilities['wpmcp/create-redirect']->input_schema['required']);
        $this->assertSame(['redirect_id'], $abilities['wpmcp/update-redirect']->input_schema['required']);
        $this->assertSame(['redirect_id'], $abilities['wpmcp/delete-redirect']->input_schema['required']);
        $this->assertArrayNotHasKey('required', $abilities['wpmcp/find-broken-links']->input_schema);
    }

    /**
     * The WooCommerce vertical build keeps the redirect group. A store that
     * renames a product slug is the canonical reason to need a redirect, and
     * the content tools in that build already emit suggestions, so shipping
     * the suggestions with no way to act on them would be worse than useless.
     */
    public function test_the_woocommerce_flavor_keeps_the_redirect_group(): void
    {
        Plugin::set_flavor_for_tests('woocommerce');
        try {
            $abilities = $this->abilities();
        } finally {
            Plugin::set_flavor_for_tests(null);
        }

        $this->assertArrayHasKey('wpmcp/create-redirect', $abilities);
        $this->assertArrayHasKey('wpmcp/find-broken-links', $abilities);
    }
}
