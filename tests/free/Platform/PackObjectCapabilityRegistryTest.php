<?php

namespace WPMCP\Tests\Free\Platform;

use WPMCP\Integrations\Integration_Dispatcher;
use WPMCP\MCP\Ability;
use WPMCP\MCP\Registrar;
use WPMCP\Pro\Gate;

/**
 * Registry-wide companion to PackObjectCapabilityTest (issue #450).
 *
 * Walks every operation of every integration pack, free and pro, and finds
 * each args key that names an object by id (a key named id, ids or ending
 * in _id/_ids, nested ones included). Every such key must either be declared
 * in the op's 'objects' map, so the permission decision checks the matching
 * per-object capability, or be listed below as naming something that is not
 * a WordPress object with a per-object capability (a row in the host plugin's
 * own table, behind the op's own capability). Adding a key to that list is a
 * visible decision.
 *
 * Every declared key is then aimed, as a Contributor holding the pair's own
 * capability, at another user's object: a draft post for a post key (and
 * another user's published post when the op writes), another user for a
 * user key, a held comment for a comment key, and any entry for an entry
 * key. Each must be refused. A post key aimed at the Contributor's own draft
 * must not be.
 */
class PackObjectCapabilityRegistryTest extends \WP_UnitTestCase
{
    /**
     * integration:op:key => what the id names. Each of these is a row in the
     * host plugin's own table rather than a post, term, user, comment, order
     * or entry, and the op either requires manage_options (or the plugin's
     * own management capability) or applies the plugin's own visibility.
     */
    private const NOT_WP_OBJECTS = [
        'gravitytables:get-table:table_id'                     => 'a Gravity Tables table row',
        'pmpro:get-level:level_id'                             => 'a membership level, site configuration',
        'plugin-data:buddypress-get-group:id'                  => 'a BuddyPress group; hidden groups are filtered by the pack',
        'plugin-data:buddypress-list-group-members:group_id'   => 'a BuddyPress group; hidden groups are filtered by the pack',
        'plugin-data:buddypress-list-activity:user_id'         => 'filters the public activity stream by member',
        'plugin-data:buddypress-update-group:id'               => 'a BuddyPress group row, manage_options',
        'plugin-data:buddypress-update-profile-field:id'       => 'a BuddyPress profile field definition, manage_options',
        'plugin-data:buddypress-hide-activity:id'              => 'a BuddyPress activity row, manage_options',
        'plugin-data:buddypress-delete-activity:id'            => 'a BuddyPress activity row, manage_options',
        'theme:list-redirection-redirects:group_id'            => 'a Redirection group row, manage_options',
        'theme:get-redirection-redirect:id'                    => 'a Redirection row, manage_options',
        'theme:create-redirection-redirect:group_id'           => 'a Redirection group row, manage_options',
        'theme:update-redirection-redirect:id'                 => 'a Redirection row, manage_options',
        'theme:update-redirection-redirect:group_id'           => 'a Redirection group row, manage_options',
        'theme:enable-redirection-redirect:id'                 => 'a Redirection row, manage_options',
        'theme:disable-redirection-redirect:id'                => 'a Redirection row, manage_options',
        'theme:delete-redirection-redirect:id'                 => 'a Redirection row, manage_options',
        'theme:get-funnelkit-funnel:id'                        => 'a FunnelKit funnel row, manage_woocommerce',
        'gravityforms:get-form:form_id'                        => 'a Gravity Forms form row',
        'gravityforms:list-fields:form_id'                     => 'a Gravity Forms form row',
        'gravityforms:list-notifications:form_id'              => 'a Gravity Forms form row',
        'gravityforms:list-entries:form_id'                    => 'a Gravity Forms form row',
        'formidable:get-form:form_id'                          => 'a Formidable form row',
        'formidable:list-fields:form_id'                       => 'a Formidable form row',
        'formidable:list-notifications:form_id'                => 'a Formidable form row',
        'formidable:list-entries:form_id'                      => 'a Formidable form row',
        'ninjaforms:get-form:form_id'                          => 'a Ninja Forms form row',
        'ninjaforms:list-fields:form_id'                       => 'a Ninja Forms form row',
        'ninjaforms:list-notifications:form_id'                => 'a Ninja Forms form row',
        'ninjaforms:list-entries:form_id'                      => 'a Ninja Forms form row',
        'fluentforms:get-form:form_id'                         => 'a Fluent Forms form row',
        'fluentforms:list-fields:form_id'                      => 'a Fluent Forms form row',
        'fluentforms:list-notifications:form_id'               => 'a Fluent Forms form row',
        'fluentforms:list-entries:form_id'                     => 'a Fluent Forms form row',
    ];

    private string $granted = '';

    protected function setUp(): void
    {
        parent::setUp();
        Gate::set_pro_for_tests(true);
        add_filter('user_has_cap', [$this, 'grant_pair_capability']);
    }

    protected function tearDown(): void
    {
        remove_filter('user_has_cap', [$this, 'grant_pair_capability']);
        Gate::set_pro_for_tests(null);
        wp_set_current_user(0);
        parent::tearDown();
    }

    /** @param array<string,bool> $allcaps */
    public function grant_pair_capability(array $allcaps): array
    {
        if ('' !== $this->granted) {
            $allcaps[ $this->granted ] = true;
        }
        return $allcaps;
    }

    /** @return array<string, Integration_Dispatcher> integration slug => pack */
    private static function packs(): array
    {
        $packs = [];
        foreach (RegisteredAbilities::all() as $a) {
            $handler = $a->handler;
            if (is_array($handler) && $handler[0] instanceof Integration_Dispatcher) {
                $packs[ $handler[0]->integration() ] = $handler[0];
            }
        }
        return $packs;
    }

    /**
     * Every args key path in a schema that names an id: 'post_id', or
     * 'updates.*.post_id' for one inside an array of objects.
     *
     * @return string[]
     */
    private static function id_paths(array $schema, string $prefix = ''): array
    {
        $paths = [];
        foreach ((array) ($schema['properties'] ?? []) as $key => $sub) {
            $path = $prefix . $key;
            if (1 === preg_match('/(^|_)ids?$/', (string) $key)) {
                $paths[] = $path;
            }
            $sub = (array) $sub;
            if (isset($sub['properties'])) {
                $paths = array_merge($paths, self::id_paths($sub, $path . '.'));
            }
            if (isset($sub['items']['properties'])) {
                $paths = array_merge($paths, self::id_paths((array) $sub['items'], $path . '.*.'));
            }
        }
        return $paths;
    }

    /** The args that put $value at $path. */
    private static function args_at(string $path, int $value): array
    {
        $parts = explode('.', $path);
        $leaf  = array_pop($parts);
        $args  = [$leaf => $value];
        while ([] !== $parts) {
            $part = array_pop($parts);
            $args = '*' === $part ? [$args] : [$part => $args];
        }
        return $args;
    }

    public function test_every_pack_operation_that_names_an_id_declares_it(): void
    {
        $packs = self::packs();
        $this->assertGreaterThanOrEqual(19, count($packs), 'Too few packs: ' . implode(', ', array_keys($packs)));

        $undeclared = [];
        $stale      = self::NOT_WP_OBJECTS;
        foreach ($packs as $slug => $pack) {
            $declared = $pack->declared_objects();
            foreach ($pack->catalog()['operations'] as $op) {
                foreach (self::id_paths((array) $op['input_schema']) as $path) {
                    $label = $slug . ':' . $op['name'] . ':' . $path;
                    unset($stale[ $label ]);
                    if (! isset($declared[ $op['name'] ]['objects'][ $path ]) && ! isset(self::NOT_WP_OBJECTS[ $label ])) {
                        $undeclared[] = $label;
                    }
                }
            }
        }
        $this->assertSame([], $undeclared, 'Declare these in the op\'s objects map');
        $this->assertSame([], array_keys($stale), 'No longer an op key; drop it from NOT_WP_OBJECTS');
    }

    public function test_every_declared_object_is_refused_to_a_contributor_on_another_users_object(): void
    {
        $owner       = self::factory()->user->create(['role' => 'editor']);
        $contributor = self::factory()->user->create(['role' => 'contributor']);
        $others_live = self::factory()->post->create(['post_author' => $owner]);
        $others_dft  = self::factory()->post->create(['post_author' => $owner, 'post_status' => 'draft']);
        $own_draft   = self::factory()->post->create(['post_author' => $contributor, 'post_status' => 'draft']);
        $held        = self::factory()->comment->create(['comment_post_ID' => $others_live, 'user_id' => $owner, 'comment_approved' => '0']);
        $term        = self::factory()->category->create();

        $packs = self::packs();
        wp_set_current_user($contributor);
        $registrar = new Registrar();
        $walked    = [];
        $failures  = [];

        foreach ($packs as $slug => $pack) {
            [$read, $write] = $pack->abilities();
            foreach ($pack->declared_objects() as $op => $def) {
                $ability       = 'read' === $def['mode'] ? $read : $write;
                $this->granted = $ability->capability;
                foreach ($def['objects'] as $path => $spec) {
                    $walked[] = $slug . ':' . $op . ':' . $path;
                    $cases    = match ($spec['type']) {
                        'post'    => array_filter([
                            'another user\'s draft'          => [$others_dft, true],
                            'another user\'s published post' => 'read' === $spec['access'] ? null : [$others_live, true],
                            'their own draft'                => [$own_draft, false],
                        ]),
                        'user'    => ['another user' => [$owner, true]],
                        'comment' => ['a held comment on another user\'s post' => [$held, true]],
                        'term'    => 'read' === $spec['access'] ? [] : ['a category' => [$term, true]],
                        'entry'   => ['an entry' => [1, true]],
                        default   => ['unknown kind ' . $spec['type'] => [0, true]],
                    };
                    foreach ($cases as $label => [$id, $refused]) {
                        $allowed = $registrar->would_permit($ability, ['operation' => $op, 'args' => self::args_at($path, $id)]);
                        if ($allowed === $refused) {
                            $failures[] = sprintf('%s:%s %s=%s: %s', $slug, $op, $path, $label, $allowed ? 'allowed' : 'refused');
                        }
                    }
                }
            }
        }
        $this->granted = '';

        $this->assertGreaterThan(50, count($walked), 'Too few pack keys walked: ' . implode(', ', $walked));
        foreach ([
            'acf:get-fields:post_id',
            'acf:update-fields:post_id',
            'acf:batch-update-fields:updates.*.post_id',
            'metabox:update-fields:post_id',
            'plugin-data:jetengine-update-fields:post_id',
            'plugin-data:pods-get-fields:post_id',
            'plugin-data:tutor-update-item:id',
            'plugin-data:lifterlms-move-item:parent_id',
            'block-suites:update-block:id',
            'mec:get-event:event_id',
            'contactform7:get-entry:entry_id',
            'forminator:delete-entry:entry_id',
        ] as $label) {
            $this->assertContains($label, $walked);
        }
        $this->assertSame([], $failures);
    }
}
