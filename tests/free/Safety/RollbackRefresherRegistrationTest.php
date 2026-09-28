<?php

namespace WPMCP\Tests\Free\Safety;

use WPMCP\Plugin;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot;

/**
 * Every option rollback fires wpmcp_rollback_options_restored, and the
 * integrations that derive state from options (theme framework packs, #316;
 * Elementor addon packs, #286) listen on it. The WooCommerce build ships
 * without src/Integrations, so a listener registered unconditionally named a
 * class that build does not contain and every option rollback there fataled.
 *
 * A refresher is wired only when its class exists. The missing class is
 * simulated with a class name no build ships, since the suite runs against
 * the full tree where the real integration classes always autoload.
 */
class RollbackRefresherRegistrationTest extends \WP_UnitTestCase
{
    private const HOOK = 'wpmcp_rollback_options_restored';

    private const MISSING = 'WPMCP\\Integrations\\Pruned_By_This_Build';

    protected function tearDown(): void
    {
        remove_all_actions(self::HOOK);
        delete_option('wpmcp_rollback_refresher_probe');
        parent::tearDown();
    }

    public function test_a_refresher_whose_class_the_build_does_not_ship_is_not_wired(): void
    {
        remove_all_actions(self::HOOK);

        Plugin::register_rollback_refreshers([ [ self::MISSING, 'refresh_after_restore' ] ]);

        $this->assertFalse(has_action(self::HOOK), 'a listener naming a missing class would fatal every option rollback');
    }

    public function test_an_option_rollback_completes_when_an_integration_class_is_missing(): void
    {
        remove_all_actions(self::HOOK);
        Plugin::register_rollback_refreshers([
            [ self::MISSING, 'refresh_after_restore' ],
            [ \WPMCP\Integrations\Theme_Framework_Pack::class, 'refresh_after_restore' ],
        ]);

        update_option('wpmcp_rollback_refresher_probe', 'before');
        $snapshot = Snapshot::capture('option', 'wpmcp_rollback_refresher_probe');
        update_option('wpmcp_rollback_refresher_probe', 'after');

        Rollback_Service::apply_snapshot($snapshot);

        $this->assertSame('before', get_option('wpmcp_rollback_refresher_probe'));
    }

    public function test_the_full_build_still_wires_every_shipped_refresher(): void
    {
        remove_all_actions(self::HOOK);

        Plugin::register_rollback_refreshers();

        $this->assertNotFalse(has_action(self::HOOK, [ \WPMCP\Integrations\Theme_Framework_Pack::class, 'refresh_after_restore' ]));
        $this->assertNotFalse(has_action(self::HOOK, [ \WPMCP\Integrations\Elementor_Addon_Packs::class, 'after_restore' ]));
    }
}
