<?php

namespace WPMCP\Tests\Free\Integrations;

use WPMCP\Integrations\Give_Integration;
use WPMCP\Integrations\Integration_Dispatcher;
use WPMCP\Integrations\MetForm_Integration;
use WPMCP\Integrations\Modern_Events_Calendar_Integration;
use WPMCP\Integrations\SureForms_Integration;
use WPMCP\Integrations\The_Events_Calendar_Integration;

require_once __DIR__ . '/../../support/sureforms-stubs.php';

/**
 * The events and donation form listings of The Events Calendar, Modern Events
 * Calendar and GiveWP list every status by default. They keep the rows to
 * what core's read_post allows the caller (issue #461), the way list-posts
 * does since #448: published rows, the caller's own rows, private rows with
 * read_private_posts and other users' drafts with edit_others_posts. The
 * filter runs in SQL, so total counts only the rows the caller may see.
 *
 * The SureForms and MetForm form listings follow the same rule (issue #465).
 *
 * Checked as Contributor, Author, Editor and Administrator.
 */
class PackListingVisibilityTest extends \WP_UnitTestCase
{
    /** @return array<string, array{0: Integration_Dispatcher, 1: string, 2: string, 3: string}> */
    private static function packs(): array
    {
        return [
            'tec'  => [ new The_Events_Calendar_Integration(), The_Events_Calendar_Integration::POST_TYPE, 'list-events', 'events' ],
            'mec'  => [ new Modern_Events_Calendar_Integration(), Modern_Events_Calendar_Integration::POST_TYPE, 'list-events', 'events' ],
            'give' => [ new Give_Integration(), Give_Integration::POST_TYPE, 'list-forms', 'forms' ],
        ];
    }

    /** @return array<string, array{0: Integration_Dispatcher, 1: string}> form listings: no status argument, page_size paging */
    private static function form_packs(): array
    {
        return [
            'sureforms' => [ new SureForms_Integration(), 'sureforms_form' ],
            'metform'   => [ new MetForm_Integration(), 'metform-form' ],
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        foreach (self::packs() as [, $type]) {
            register_post_type($type, [ 'public' => true, 'label' => $type ]);
        }
        foreach (self::form_packs() as [, $type]) {
            register_post_type($type, [ 'public' => true, 'label' => $type ]);
        }
        register_post_type('metform-entry', [ 'public' => true, 'label' => 'Entries' ]);
    }

    protected function tearDown(): void
    {
        foreach (self::packs() as [, $type]) {
            unregister_post_type($type);
        }
        foreach (self::form_packs() as [, $type]) {
            unregister_post_type($type);
        }
        unregister_post_type('metform-entry');
        wp_set_current_user(0);
        parent::tearDown();
    }

    /** @return array<string, array{0: string, 1: bool}> role => [role, may see others' drafts and private rows] */
    public static function roles(): array
    {
        return [
            'contributor'   => [ 'contributor', false ],
            'author'        => [ 'author', false ],
            'editor'        => [ 'editor', true ],
            'administrator' => [ 'administrator', true ],
        ];
    }

    /**
     * @dataProvider roles
     */
    public function test_listings_show_only_rows_the_caller_may_read(string $role, bool $elevated): void
    {
        $owner  = self::factory()->user->create([ 'role' => 'editor' ]);
        $caller = self::factory()->user->create([ 'role' => $role ]);

        foreach (self::packs() as $slug => [$pack, $type, $op, $key]) {
            $rows = [];
            foreach ([ 'publish', 'draft', 'private', 'pending' ] as $status) {
                $rows[ $status ] = self::factory()->post->create([ 'post_type' => $type, 'post_author' => $owner, 'post_status' => $status, 'post_title' => "others {$status}" ]);
            }
            $mine = self::factory()->post->create([ 'post_type' => $type, 'post_author' => $caller, 'post_status' => 'draft', 'post_title' => 'my draft' ]);

            wp_set_current_user($caller);
            $out    = $pack->handle_read([ 'operation' => $op ])['result'];
            $listed = array_column($out[ $key ], 'id');
            sort($listed);

            $expected = $elevated ? array_merge(array_values($rows), [ $mine ]) : [ $rows['publish'], $mine ];
            sort($expected);
            $this->assertSame($expected, $listed, "{$slug} {$op} as {$role}");
            $this->assertSame(count($expected), $out['total'], "{$slug} total as {$role}");

            // An explicit status is narrowed the same way, and the total
            // stays honest when the page is smaller than the result.
            $drafts = $pack->handle_read([ 'operation' => $op, 'args' => [ 'status' => 'draft', 'page_size' => 1 ] ])['result'];
            $this->assertSame($elevated ? 2 : 1, $drafts['total'], "{$slug} draft total as {$role}");
            $this->assertCount(1, $drafts[ $key ]);
            if (! $elevated) {
                $this->assertSame($mine, $drafts[ $key ][0]['id'], "{$slug} lists only the caller's own draft");
            }

            $private = $pack->handle_read([ 'operation' => $op, 'args' => [ 'status' => 'private' ] ])['result'];
            $this->assertSame($elevated ? 1 : 0, $private['total'], "{$slug} private total as {$role}");

            wp_set_current_user(0);
            foreach (array_merge(array_values($rows), [ $mine ]) as $id) {
                wp_delete_post($id, true);
            }
        }
    }

    /**
     * @dataProvider roles
     */
    public function test_form_listings_show_only_rows_the_caller_may_read(string $role, bool $elevated): void
    {
        $owner  = self::factory()->user->create([ 'role' => 'editor' ]);
        $caller = self::factory()->user->create([ 'role' => $role ]);

        foreach (self::form_packs() as $slug => [$pack, $type]) {
            $this->assertTrue($pack->is_available(), "{$slug} is available");
            $rows = [];
            foreach ([ 'publish', 'draft', 'private', 'pending' ] as $status) {
                $rows[ $status ] = self::factory()->post->create([ 'post_type' => $type, 'post_author' => $owner, 'post_status' => $status, 'post_title' => "others {$status} form" ]);
            }
            $mine = self::factory()->post->create([ 'post_type' => $type, 'post_author' => $caller, 'post_status' => 'draft', 'post_title' => 'my draft form' ]);

            wp_set_current_user($caller);
            $out = $pack->handle_read([ 'operation' => 'list-forms' ]);
            $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
            $listed = array_column($out['result']['forms'], 'id');
            sort($listed);

            $expected = $elevated ? array_merge(array_values($rows), [ $mine ]) : [ $rows['publish'], $mine ];
            sort($expected);
            $this->assertSame($expected, $listed, "{$slug} list-forms as {$role}");
            $this->assertSame(count($expected), $out['result']['total'], "{$slug} total as {$role}");

            // A page smaller than the result still reports the visible total.
            $paged = $pack->handle_read([ 'operation' => 'list-forms', 'args' => [ 'page_size' => 1 ] ])['result'];
            $this->assertCount(1, $paged['forms']);
            $this->assertSame(count($expected), $paged['total'], "{$slug} paged total as {$role}");

            wp_set_current_user(0);
            foreach (array_merge(array_values($rows), [ $mine ]) as $id) {
                wp_delete_post($id, true);
            }
        }
    }
}
