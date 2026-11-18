<?php
/**
 * References the WooCommerce build may ship although it prunes the class
 * (issue #935). build-woo-release.sh scans every shipped file under src for
 * imports, `new`, static calls and `::class` naming a pruned class; an entry
 * here excuses one file naming one class, and only because the file reaches
 * it behind a runtime check. A stale entry fails the build, so remove it when
 * the reference goes.
 *
 * Never add an entry to silence a real reference: register through a hook or
 * filter instead, or add a strip rule (see bin/check-flavors.sh).
 */

declare(strict_types=1);

return [
    // Content_Mirror::available() reads each builder adapter through
    // class_exists() before export or import touches it, so a mirror entry
    // for Elementor or Bricks is refused as unsupported in this flavor.
    'src/Tools/Export/Content_Mirror.php' => [
        'WPMCP\\Tools\\Builders\\Bricks_Content',
        'WPMCP\\Tools\\Elementor\\Elementor_Page_Data',
    ],
    // The gate callables run only from Opt_In_Gates::is_open() for a
    // registered ability; the bridge abilities never register here (the
    // flavor drops the 'bridge' group), so Bridge_Guard is never called.
    'src/Governance/Opt_In_Gates.php' => [
        'WPMCP\\Tools\\Bridge\\Bridge_Guard',
    ],
];
