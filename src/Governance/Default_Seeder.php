<?php

namespace WPMCP\Governance;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Versioned default-disabled seeder (issue #78): newly shipped risky
 * abilities arrive OFF for upgraders by policy, not by luck.
 *
 * The shipped defaults are a map of defaults-version => ability names that
 * should be disabled by default FROM that version on. seed() applies every
 * version newer than the last one recorded (wpmcp_governance_defaults_version)
 * by writing an ordinary Governance ability toggle — never a new mechanism —
 * and then records the latest version. Two properties fall out of that:
 *
 *  - Explicit admin decisions are never clobbered: a default is only written
 *    when NO stored toggle exists for that ability. An admin who re-enabled a
 *    seeded-off ability keeps it enabled across every future upgrade, even if
 *    a later version names the same ability again.
 *  - Enforcement lives entirely in the registration/permission path:
 *    Governance::is_ability_enabled() reads the stored toggle, so a seeded
 *    disable holds with no Admin class loaded at all.
 *
 * The shipped map currently disables nothing (version 1 is empty): today's
 * dangerous abilities (exec, db writes, fs writes) are already default-off
 * via their execution opt-in filters, which remain the master gates. The
 * seeder exists so a FUTURE dangerous ability can ship auto-off for
 * upgraders by adding it under a new version number here.
 */
class Default_Seeder
{
    public const VERSION_OPTION = 'wpmcp_governance_defaults_version';

    /**
     * defaults-version => ability names disabled by default from that
     * version on. Append new versions; never edit an already-shipped one
     * (installs that already applied it will not re-run it).
     *
     * @var array<int, string[]>
     */
    private const DEFAULT_DISABLED = [
        1 => [],
    ];

    /** @var array<int, string[]>|null */
    private static ?array $versions_override = null;

    /** @param array<int, string[]>|null $versions */
    public static function set_versions_for_tests(?array $versions): void
    {
        if (! defined('WPMCP_TESTING') || ! WPMCP_TESTING) {
            return;
        }
        self::$versions_override = $versions;
    }

    /** The defaults version this install has already applied (0 = never seeded). */
    public static function applied_version(): int
    {
        return (int) get_option(self::VERSION_OPTION, 0);
    }

    /**
     * Apply every not-yet-applied defaults version, skipping any ability the
     * admin (or a previous seeding) has already stored a decision for, then
     * record the latest version. Cheap no-op when already up to date, so it
     * is safe to call on every load (Plugin::boot()).
     */
    public static function seed(): void
    {
        $versions = self::$versions_override ?? self::DEFAULT_DISABLED;
        $latest   = [] === $versions ? 0 : max(array_keys($versions));
        $applied  = self::applied_version();
        if ($applied >= $latest) {
            return;
        }

        $decided = Governance::ability_toggles();
        foreach ($versions as $version => $names) {
            if ($version <= $applied) {
                continue;
            }
            foreach ($names as $name) {
                if (array_key_exists($name, $decided)) {
                    continue; // An explicit decision always wins over a default.
                }
                Governance::set_ability_toggle($name, false);
                $decided[ $name ] = false;
            }
        }

        update_option(self::VERSION_OPTION, $latest);
    }
}
