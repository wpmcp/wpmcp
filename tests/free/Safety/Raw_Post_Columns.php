<?php

namespace WPMCP\Tests\Free\Safety;

/**
 * Issue #436 fixtures: a post row holding markup kses would rewrite, stored
 * verbatim, a user who lacks unfiltered_html, and a recorder for the status
 * transition hooks, so a status-only write can be checked for leaving the
 * text columns byte for byte while the usual hooks still fire.
 */
trait Raw_Post_Columns
{
    /** @var array<int, array{0: string, 1: string, 2: int}> new status, old status, post id. */
    private array $transitions = [];

    private static function raw_markup(): string
    {
        return "<!-- wp:paragraph {\"className\":\"x\"} -->\n<p>1 < 2 &amp; <em>three</em></p>\n<!-- /wp:paragraph -->\n"
            . "<iframe src=\"https://example.org/x\"></iframe><script>var a = 1 < 2;</script>";
    }

    private static function raw_title(): string
    {
        return 'Title <b>B</b> & 1 < 2 <script>x</script>';
    }

    private function author(): int
    {
        $author = self::factory()->user->create(['role' => 'author']);
        $this->assertFalse(user_can($author, 'unfiltered_html'));
        return $author;
    }

    /** An administrator who, like one on multisite, lacks unfiltered_html. */
    private function admin_without_unfiltered_html(): int
    {
        add_filter('map_meta_cap', [self::class, 'deny_unfiltered_html'], 10, 2);
        $admin = self::factory()->user->create(['role' => 'administrator']);
        $this->assertTrue(user_can($admin, 'manage_options'));
        $this->assertFalse(user_can($admin, 'unfiltered_html'));
        return $admin;
    }

    /**
     * @param string[] $caps
     * @return string[]
     */
    public static function deny_unfiltered_html(array $caps, string $cap): array
    {
        return 'unfiltered_html' === $cap ? ['do_not_allow'] : $caps;
    }

    /** Signs $user_id in and checks kses is now on the post save filters. */
    private function act_as(int $user_id): void
    {
        wp_set_current_user($user_id);
        $this->assert_post_save_filters_hooked();
    }

    private function assert_post_save_filters_hooked(): void
    {
        $this->assertSame(10, has_filter('content_save_pre', 'wp_filter_post_kses'));
        $this->assertSame(10, has_filter('excerpt_save_pre', 'wp_filter_post_kses'));
        $this->assertSame(10, has_filter('title_save_pre', 'wp_filter_kses'));
        $this->assertSame(10, has_filter('content_filtered_save_pre', 'wp_filter_post_kses'));
    }

    /** Overwrites the text columns of $post_id with raw markup, bypassing every filter. */
    private function make_raw(int $post_id, array $columns = ['post_content', 'post_excerpt', 'post_title']): void
    {
        global $wpdb;
        $row = [];
        foreach ($columns as $column) {
            $row[ $column ] = 'post_title' === $column ? self::raw_title() : self::raw_markup();
        }
        $wpdb->update($wpdb->posts, $row, ['ID' => $post_id]);
        clean_post_cache($post_id);
    }

    private function assert_raw(int $post_id, array $columns = ['post_content', 'post_excerpt', 'post_title']): void
    {
        clean_post_cache($post_id);
        $post = get_post($post_id);
        $this->assertNotNull($post);
        foreach ($columns as $column) {
            $this->assertSame(
                'post_title' === $column ? self::raw_title() : self::raw_markup(),
                $post->{$column},
                "{$column} of post {$post_id} changed on a write that did not touch it."
            );
        }
    }

    /** Proves kses would rewrite the raw fixture, or a byte-for-byte check proves nothing. */
    private function assert_kses_would_change_it(): void
    {
        $this->assertNotSame(self::raw_markup(), wp_unslash(wp_filter_post_kses(wp_slash(self::raw_markup()))));
        $this->assertNotSame(self::raw_title(), wp_unslash(wp_filter_kses(wp_slash(self::raw_title()))));
    }

    private function record_transitions(): void
    {
        $this->transitions = [];
        add_action('transition_post_status', [$this, 'on_transition'], 10, 3);
    }

    /** @param \WP_Post $post */
    public function on_transition(string $new_status, string $old_status, $post): void
    {
        $this->transitions[] = [$new_status, $old_status, (int) $post->ID];
    }

    private function assert_transitioned(int $post_id, string $from, string $to): void
    {
        $this->assertContains([$to, $from, $post_id], $this->transitions, "transition_post_status did not fire {$from} -> {$to} for post {$post_id}.");
    }

    public static function explode_on_insert(): void
    {
        throw new \RuntimeException('update refused');
    }
}
