<?php

namespace WPMCP\Tests\Pro\Elementor;

use WPMCP\MCP\Ability;
use WPMCP\Plugin;
use WPMCP\Pro\Gate;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Elementor\Export_Template;
use WPMCP\Tools\Elementor\Save_As_Template;

/**
 * Issue #504: save-as-template must not turn a password-protected, private
 * or unpublished page into a published, password-less elementor_library
 * template. The template keeps the source's password and is created private
 * when the source is not publicly readable, so a caller refused the page
 * (protected-post / post-capability) is refused the template too, through
 * export-template, get-elementor-data and apply-template.
 */
class SaveAsTemplateProtectedSourceTest extends \WP_UnitTestCase
{
    private const SECRET = 'marmalade-cipher-elementor';

    public static function wpSetUpBeforeClass(): void
    {
        if (0 === did_action('wp_abilities_api_init')) {
            do_action('wp_abilities_api_init');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        Gate::set_pro_for_tests(true);
        Snapshot_Store::install();
        if (! wpmcp_elementor_active()) {
            $this->markTestSkipped('Elementor not active');
        }
    }

    protected function tearDown(): void
    {
        Gate::set_pro_for_tests(null);
        wp_set_current_user(0);
        parent::tearDown();
    }

    private function permits(string $ability, array $input): bool
    {
        $registrar = new \WPMCP\MCP\Registrar();
        Plugin::instance()->register_abilities_into($registrar);
        $a = $registrar->get($ability);
        $this->assertInstanceOf(Ability::class, $a, $ability . ' is not registered');
        return $registrar->would_permit($a, $input);
    }

    /**
     * An Author's Elementor page carrying SECRET, with $fields applied.
     *
     * @param array<string, string> $fields
     */
    private function source_page(array $fields): int
    {
        $owner = self::factory()->user->create([ 'role' => 'author' ]);
        $page  = self::factory()->post->create([ 'post_type' => 'page', 'post_author' => $owner, 'post_title' => 'Members only' ] + $fields);
        $tree  = [[
            'id'         => 'sec0001',
            'elType'     => 'widget',
            'widgetType' => 'text-editor',
            'settings'   => [ 'editor' => '<p>' . self::SECRET . '</p>' ],
            'elements'   => [],
            'isInner'    => false,
        ]];
        update_post_meta($page, '_elementor_data', wp_slash(wp_json_encode($tree)));
        update_post_meta($page, '_elementor_edit_mode', 'builder');
        return $page;
    }

    /** An Editor saves $page as a template; returns the template id. */
    private function save_as_editor(int $page): int
    {
        $editor = self::factory()->user->create([ 'role' => 'editor' ]);
        wp_set_current_user($editor);
        $this->assertTrue($this->permits('wpmcp/save-as-template', [ 'post_id' => $page, 'title' => 'Copy' ]));
        $out = (new Save_As_Template())->handle([ 'post_id' => $page, 'title' => 'Copy' ]);
        $this->assertIsArray($out);
        return (int) $out['template_id'];
    }

    /** @return array<string, array{0: array<string, string>}> */
    public static function restricted_sources(): array
    {
        return [
            'password-protected' => [ [ 'post_status' => 'publish', 'post_password' => 'hunter2' ] ],
            'private'            => [ [ 'post_status' => 'private' ] ],
            'draft'              => [ [ 'post_status' => 'draft' ] ],
            'pending'            => [ [ 'post_status' => 'pending' ] ],
        ];
    }

    /**
     * @dataProvider restricted_sources
     */
    public function test_a_template_of_a_restricted_page_is_refused_to_who_cannot_read_the_page(array $restriction): void
    {
        $page = $this->source_page($restriction);

        $contributor = self::factory()->user->create([ 'role' => 'contributor' ]);
        $own_page    = self::factory()->post->create([ 'post_type' => 'page', 'post_author' => $contributor, 'post_status' => 'draft' ]);
        wp_set_current_user($contributor);
        $this->assertFalse($this->permits('wpmcp/get-elementor-data', [ 'post_id' => $page ]), 'precondition: the contributor may not read the page');

        $tid = $this->save_as_editor($page);

        wp_set_current_user($contributor);
        $this->assertFalse($this->permits('wpmcp/export-template', [ 'template_id' => $tid ]), 'export-template must refuse the template');
        $this->assertFalse($this->permits('wpmcp/get-elementor-data', [ 'post_id' => $tid ]), 'get-elementor-data must refuse the template');
        $this->assertFalse(
            $this->permits('wpmcp/apply-template', [ 'post_id' => $own_page, 'template_id' => $tid, 'expected_hash' => 'x' ]),
            'apply-template must refuse to copy the template into the contributor\'s own page'
        );
    }

    public function test_the_template_keeps_the_source_password(): void
    {
        $page = $this->source_page([ 'post_status' => 'publish', 'post_password' => 'hunter2' ]);
        $tid  = $this->save_as_editor($page);

        $this->assertSame('hunter2', get_post_field('post_password', $tid));
        $this->assertSame('publish', get_post_status($tid));
    }

    /** @return array<string, array{0: string}> */
    public static function unpublished_statuses(): array
    {
        return [
            'private' => [ 'private' ],
            'draft'   => [ 'draft' ],
            'pending' => [ 'pending' ],
            'future'  => [ 'future' ],
        ];
    }

    /**
     * @dataProvider unpublished_statuses
     */
    public function test_a_private_or_unpublished_source_yields_a_private_template(string $status): void
    {
        $fields = [ 'post_status' => $status ];
        if ('future' === $status) {
            $fields['post_date'] = gmdate('Y-m-d H:i:s', time() + DAY_IN_SECONDS);
        }
        $page = $this->source_page($fields);
        $tid  = $this->save_as_editor($page);

        $this->assertSame('private', get_post_status($tid));
    }

    public function test_an_ordinary_published_page_still_yields_a_published_readable_template(): void
    {
        $page = $this->source_page([ 'post_status' => 'publish' ]);
        $tid  = $this->save_as_editor($page);

        $this->assertSame('publish', get_post_status($tid));
        $this->assertSame('', get_post_field('post_password', $tid));

        $contributor = self::factory()->user->create([ 'role' => 'contributor' ]);
        wp_set_current_user($contributor);
        $this->assertTrue($this->permits('wpmcp/export-template', [ 'template_id' => $tid ]));
        $exported = (new Export_Template())->handle([ 'template_id' => $tid ]);
        $this->assertStringContainsString(self::SECRET, (string) wp_json_encode($exported));
    }
}
