<?php

namespace WPMCP\Tests\Free\Release;

use PHPUnit\Framework\TestCase;

/**
 * The release-flavor class guard, run where developers see it.
 *
 * CI's "compliance" and "plugin-check" jobs build the wp.org and WooCommerce
 * flavors, strip the Pro-only and out-of-flavor classes, and fail when a
 * shipped file still names a stripped class. Until this test existed nothing
 * in the local gate ran those builds, so the failure only showed up on the PR.
 *
 * This runs `composer check:flavors` (bin/check-flavors.sh), which calls the
 * build scripts' own `--check-only` path, so this test and CI cannot disagree.
 * If it fails, the output names the file, the class and the fix: register the
 * integration through a hook, or add a strip rule in the same commit.
 */
class FlavorClassGuardTest extends TestCase
{
    public function test_no_release_flavor_ships_a_file_naming_a_class_it_strips(): void
    {
        $root = dirname(__DIR__, 3);
        exec('bash ' . escapeshellarg($root . '/bin/check-flavors.sh') . ' 2>&1', $output, $status);

        $this->assertSame(
            0,
            $status,
            "A release flavor names a class it does not ship. Register through a hook, or add a strip rule.\n"
            . implode("\n", $output)
        );
    }
}
