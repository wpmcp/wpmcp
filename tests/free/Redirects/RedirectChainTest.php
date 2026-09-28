<?php

namespace WPMCP\Tests\Free\Redirects;

use WPMCP\Tools\Redirects\Redirect_Chain;
use WPMCP\Tools\Redirects\Redirect_Store;

/**
 * Chain flattening and loop detection (issue #128).
 *
 * These are the two things a naive redirect store does not do at write time,
 * and they are the difference between a redirect table that stays one hop
 * deep and one that quietly grows A -> B -> C -> D chains (or a cycle that
 * browsers turn into ERR_TOO_MANY_REDIRECTS).
 */
class RedirectChainTest extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        global $wpdb;
        $wpdb->query('DELETE FROM ' . Redirect_Store::table_name());
    }

    public function test_a_target_nobody_redirects_is_left_alone(): void
    {
        $flat = Redirect_Chain::flatten('/old', '/new');

        $this->assertFalse($flat['flattened']);
        $this->assertSame('/new', $flat['target']);
        $this->assertSame([], $flat['chain']);
    }

    public function test_an_external_target_is_never_flattened(): void
    {
        Redirect_Store::insert(['source_path' => '/b', 'target_url' => '/c']);

        $flat = Redirect_Chain::flatten('/a', 'https://elsewhere.test/b');

        $this->assertFalse($flat['flattened']);
        $this->assertSame('https://elsewhere.test/b', $flat['target']);
    }

    public function test_a_two_hop_chain_is_collapsed_to_its_final_destination(): void
    {
        Redirect_Store::insert(['source_path' => '/b', 'target_url' => '/c']);

        $flat = Redirect_Chain::flatten('/a', '/b');

        $this->assertTrue($flat['flattened']);
        $this->assertSame('/c', $flat['target']);
        $this->assertSame(['/b'], $flat['chain']);
    }

    public function test_a_longer_chain_is_walked_all_the_way_down(): void
    {
        Redirect_Store::insert(['source_path' => '/b', 'target_url' => '/c']);
        Redirect_Store::insert(['source_path' => '/c', 'target_url' => '/d']);
        Redirect_Store::insert(['source_path' => '/d', 'target_url' => '/e']);

        $flat = Redirect_Chain::flatten('/a', '/b');

        $this->assertSame('/e', $flat['target']);
        $this->assertSame(['/b', '/c', '/d'], $flat['chain']);
    }

    public function test_flattening_inherits_a_post_id_target_instead_of_freezing_a_permalink(): void
    {
        $this->set_permalink_structure('/%postname%/');
        $post_id = self::factory()->post->create(['post_name' => 'destination', 'post_status' => 'publish']);
        Redirect_Store::insert(['source_path' => '/b', 'target_post_id' => $post_id]);

        $flat = Redirect_Chain::flatten('/a', '/b');

        $this->assertTrue($flat['flattened']);
        $this->assertSame($post_id, $flat['target_post_id']);
        $this->assertSame(get_permalink($post_id), $flat['target']);
    }

    public function test_a_disabled_hop_terminates_the_walk(): void
    {
        Redirect_Store::insert(['source_path' => '/b', 'target_url' => '/c', 'enabled' => 0]);

        $flat = Redirect_Chain::flatten('/a', '/b');

        $this->assertFalse($flat['flattened']);
        $this->assertSame('/b', $flat['target']);
    }

    public function test_a_hop_whose_target_post_is_gone_terminates_the_walk(): void
    {
        $post_id = self::factory()->post->create(['post_status' => 'publish']);
        Redirect_Store::insert(['source_path' => '/b', 'target_post_id' => $post_id]);
        wp_delete_post($post_id, true);

        $flat = Redirect_Chain::flatten('/a', '/b');

        $this->assertFalse($flat['flattened']);
        $this->assertSame('/b', $flat['target']);
    }

    public function test_a_self_pointing_redirect_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('would point at itself');

        Redirect_Chain::flatten('/a', 'http://example.org/a/');
    }

    public function test_a_cycle_back_to_the_source_is_refused(): void
    {
        Redirect_Store::insert(['source_path' => '/b', 'target_url' => '/a']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('redirect loop');

        Redirect_Chain::flatten('/a', '/b');
    }

    public function test_a_cycle_that_does_not_include_the_source_is_refused(): void
    {
        Redirect_Store::insert(['source_path' => '/b', 'target_url' => '/c']);
        Redirect_Store::insert(['source_path' => '/c', 'target_url' => '/b']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('redirect loop');

        Redirect_Chain::flatten('/a', '/b');
    }

    public function test_an_over_long_chain_is_refused_rather_than_walked_forever(): void
    {
        for ($i = 0; $i < Redirect_Store::MAX_CHAIN_DEPTH + 2; $i++) {
            Redirect_Store::insert([
                'source_path' => '/hop-' . $i,
                'target_url'  => '/hop-' . ($i + 1),
            ]);
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('more than ' . Redirect_Store::MAX_CHAIN_DEPTH);

        Redirect_Chain::flatten('/start', '/hop-0');
    }

    public function test_the_row_being_edited_is_not_treated_as_a_hop_in_its_own_chain(): void
    {
        $id = Redirect_Store::insert(['source_path' => '/b', 'target_url' => '/c']);

        $flat = Redirect_Chain::flatten('/a', '/b', $id);

        $this->assertFalse($flat['flattened']);
        $this->assertSame('/b', $flat['target']);
    }
}
