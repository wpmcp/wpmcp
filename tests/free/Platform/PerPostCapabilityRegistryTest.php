<?php

namespace WPMCP\Tests\Free\Platform;

use WPMCP\MCP\Ability;
use WPMCP\MCP\Registrar;
use WPMCP\Pro\Gate;
use WPMCP\Tools\Content\Content_Guard;

/**
 * Registry-wide companion to PerPostCapabilityTest (issue #448).
 *
 * Walks every registered ability, free and pro, and aims each input key that
 * names a post at three posts: another user's published post, another
 * user's draft, and a draft the caller owns. The caller is a Contributor who
 * also holds the ability's own capability, so only the per-post check can
 * refuse. The rule, taken from the ability's registered operation:
 *
 * - read: read_post, so another user's published post is allowed and their
 *   draft is refused;
 * - create, update: edit_post; delete: delete_post. Another user's post is
 *   refused whatever its status.
 *
 * A key that only names a post the ability links to or copies from (a parent,
 * a redirect target, a menu item's object, a copy source) is read, not
 * written, so it follows the read rule. Those keys are listed below by name,
 * so loosening another one is a visible decision. The caller's own draft is
 * never refused.
 */
class PerPostCapabilityRegistryTest extends \WP_UnitTestCase
{
    /** Keys that name a post the ability reads rather than changes, on every ability. */
    private const REFERENCE_KEYS = ['parent', 'source_id', 'target_post_id', 'object_id'];

    /**
     * ability:key pairs where the post is only read although the ability
     * writes: a copy source, or a template or image applied to another post
     * named in the same call.
     */
    private const READ_ONLY_TARGETS = [
        'wpmcp/duplicate-post:post_id',
        'wpmcp/save-as-template:post_id',
    ];

    private string $granted = '';

    protected function setUp(): void
    {
        parent::setUp();
        Gate::set_pro_for_tests(true);
        add_filter('user_has_cap', [$this, 'grant_ability_capability']);
    }

    protected function tearDown(): void
    {
        remove_filter('user_has_cap', [$this, 'grant_ability_capability']);
        Gate::set_pro_for_tests(null);
        wp_set_current_user(0);
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

    private static function input(Ability $a, string $key, int $value): array
    {
        $schema = (array) ($a->input_schema['properties'][ $key ] ?? []);
        $plural = 'array' === ($schema['type'] ?? '') || 's' === substr($key, -1);
        return [$key => $plural ? [$value] : $value];
    }

    public function test_every_ability_naming_a_post_enforces_the_per_post_capability(): void
    {
        $owner       = self::factory()->user->create(['role' => 'editor']);
        $contributor = self::factory()->user->create(['role' => 'contributor']);
        $others_live = self::factory()->post->create(['post_author' => $owner]);
        $others_dft  = self::factory()->post->create(['post_author' => $owner, 'post_status' => 'draft']);
        $own_draft   = self::factory()->post->create(['post_author' => $contributor, 'post_status' => 'draft']);

        wp_set_current_user($contributor);
        $registrar = new Registrar();
        $walked    = [];
        $failures  = [];

        foreach (RegisteredAbilities::all() as $a) {
            $props   = array_keys((array) ($a->input_schema['properties'] ?? []));
            $id_keys = array_values(array_intersect($props, Content_Guard::post_id_keys($a->domain)));
            if ([] === $id_keys) {
                continue;
            }
            $walked[]      = $a->name;
            $this->granted = $a->capability;

            foreach ($id_keys as $key) {
                $reads = 'read' === $a->operation
                    || in_array($key, self::REFERENCE_KEYS, true)
                    || in_array($a->name . ':' . $key, self::READ_ONLY_TARGETS, true);

                foreach ([
                    'another user\'s published post' => [$others_live, ! $reads],
                    'another user\'s draft'          => [$others_dft, true],
                    'their own draft'                => [$own_draft, false],
                ] as $label => [$post_id, $refused]) {
                    $allowed = $registrar->would_permit($a, self::input($a, $key, $post_id));
                    if ($allowed === $refused) {
                        $failures[] = sprintf('%s (%s) %s=%s: %s', $a->name, $a->operation, $key, $label, $allowed ? 'allowed' : 'refused');
                    }
                }
            }
        }
        $this->granted = '';

        $this->assertGreaterThan(80, count($walked), 'Too few abilities walked: ' . implode(', ', $walked));
        foreach (['wpmcp/update-post', 'wpmcp/delete-post', 'wpmcp/set-post-terms', 'wpmcp/set-post-meta', 'wpmcp/restore-revision', 'wpmcp/get-post', 'wpmcp/update-blocks', 'wpmcp/update-builder-content', 'wpmcp/update-media', 'wpmcp/delete-media'] as $name) {
            $this->assertContains($name, $walked);
        }
        $this->assertSame([], $failures);
    }
}
