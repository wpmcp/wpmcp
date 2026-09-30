<?php

namespace WPMCP\Tests\Free\Platform;

use WPMCP\MCP\Ability;
use WPMCP\Tests\Free\Content\Hidden_Post_Types;
use WPMCP\Tools\Content\Content_Guard;

/**
 * Registry-wide companion to PostTypeCapabilityGuardTest (issue #446).
 *
 * Walks every registered ability, free and pro, and for every input key
 * that names a post id or a post type checks two things:
 *
 * 1. Coverage: the key is one the permission-time guard inspects for that
 *    ability's domain. A new tool that takes, say, a revision_id or a
 *    post_types list is caught here rather than shipping unguarded.
 * 2. Enforcement: aimed at an order or form-entry row (or type), the guard
 *    refuses a caller who holds the ability's own capability but not the
 *    post type's, and aimed at an enrollment row it refuses anyone below
 *    administrator level. An administrator, and any caller aimed at an
 *    ordinary post, is never refused.
 */
class PostTargetGuardRegistryTest extends \WP_UnitTestCase
{
    use Hidden_Post_Types;

    /** Property names that name a post or post type wherever they appear. */
    private const POST_NAMING = '/^(post|page|source|revision|from_revision|to_revision|target_post|media|attachment)_ids?$|^post_types?$/';

    private string $granted = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->register_hidden_post_types();
        add_filter('user_has_cap', [$this, 'grant_ability_capability']);
    }

    protected function tearDown(): void
    {
        remove_filter('user_has_cap', [$this, 'grant_ability_capability']);
        $this->unregister_hidden_post_types();
        parent::tearDown();
    }

    /** @param array<string,bool> $allcaps */
    public function grant_ability_capability(array $allcaps): array
    {
        if ('' !== $this->granted) {
            $allcaps[ $this->granted ] = true;
        }
        return $allcaps;
    }

    /** @return array<string,mixed> the ability's input properties */
    private static function properties(Ability $a): array
    {
        return (array) ($a->input_schema['properties'] ?? []);
    }

    private static function input(Ability $a, string $key, $value): array
    {
        $schema = (array) (self::properties($a)[ $key ] ?? []);
        $plural = 'array' === ($schema['type'] ?? '') || 's' === substr($key, -1);
        return [$key => $plural ? [$value] : $value];
    }

    public function test_every_post_naming_input_key_is_inspected_by_the_guard(): void
    {
        $unguarded = [];
        foreach (RegisteredAbilities::all() as $a) {
            $inspected = array_merge(Content_Guard::post_id_keys($a->domain), Content_Guard::post_type_keys());
            foreach (array_keys(self::properties($a)) as $key) {
                if (preg_match(self::POST_NAMING, (string) $key) && ! in_array($key, $inspected, true)) {
                    $unguarded[] = $a->name . ':' . $key;
                }
            }
        }
        $this->assertSame([], $unguarded, 'Input keys that name a post but are not checked at permission time.');
    }

    public function test_every_ability_taking_a_post_id_or_type_refuses_hidden_rows(): void
    {
        $walked   = [];
        $failures = [];

        foreach (RegisteredAbilities::all() as $a) {
            $props    = array_keys(self::properties($a));
            $id_keys  = array_values(array_intersect($props, Content_Guard::post_id_keys($a->domain)));
            $typ_keys = array_values(array_intersect($props, Content_Guard::post_type_keys()));
            if ([] === $id_keys && [] === $typ_keys) {
                continue;
            }
            $walked[] = $a->name;

            // An administrator-level ability (manage_options) is let into
            // no-UI types; everything else below administrator is not.
            $admin_level = 'manage_options' === $a->capability;
            $public_post = self::factory()->post->create();

            foreach (['contributor', 'administrator'] as $role) {
                wp_set_current_user($this->role_users[ $role ]);
                $this->granted = $a->capability;

                foreach (self::hidden_types() as $type) {
                    $expect_refused = 'contributor' === $role && ! (self::ENROLLMENT === $type && $admin_level);
                    foreach ($id_keys as $key) {
                        $got = Content_Guard::input_targets_private_post($a->domain, self::input($a, $key, $this->hidden_rows[ $type ]));
                        if ($got !== $expect_refused) {
                            $failures[] = sprintf('%s %s=%s row as %s: %s', $a->name, $key, $type, $role, $got ? 'refused' : 'allowed');
                        }
                    }
                    foreach ($typ_keys as $key) {
                        $got = Content_Guard::input_targets_private_post($a->domain, self::input($a, $key, $type));
                        if ($got !== $expect_refused) {
                            $failures[] = sprintf('%s %s=%s as %s: %s', $a->name, $key, $type, $role, $got ? 'refused' : 'allowed');
                        }
                    }
                }

                foreach ($id_keys as $key) {
                    if (Content_Guard::input_targets_private_post($a->domain, self::input($a, $key, $public_post))) {
                        $failures[] = sprintf('%s %s=<ordinary post> as %s: refused', $a->name, $key, $role);
                    }
                }
                foreach ($typ_keys as $key) {
                    if (Content_Guard::input_targets_private_post($a->domain, self::input($a, $key, 'post'))) {
                        $failures[] = sprintf('%s %s=post as %s: refused', $a->name, $key, $role);
                    }
                }
            }
            $this->granted = '';
        }

        // The content, revision, block, builder, SEO and meta tools alone are
        // well over a hundred; a much smaller walk means the enumeration broke.
        $this->assertGreaterThan(80, count($walked), 'Too few abilities walked: ' . implode(', ', $walked));
        foreach (['wpmcp/get-post', 'wpmcp/list-posts', 'wpmcp/search-content', 'wpmcp/count-content', 'wpmcp/export-content', 'wpmcp/get-revision', 'wpmcp/diff-revisions', 'wpmcp/restore-revision', 'wpmcp/extract-content', 'wpmcp/get-post-meta'] as $name) {
            $this->assertContains($name, $walked);
        }
        $this->assertSame([], $failures);
    }
}
