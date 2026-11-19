<?php

namespace WPMCP\Tests\Pro\Integrations;

use WPMCP\Integrations\Block_Suite;
use WPMCP\Integrations\Block_Suites_Integration;
use WPMCP\Pro\Gate;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;

/**
 * Block suites, Stackable (issue #649) through the block-suites dispatcher
 * pair.
 *
 * Stackable 3 registers every block on the server from a block.json that
 * declares no attributes: the attributes live in its editor script. Each
 * block keeps a seven character uniqueId (the first seven characters of
 * the editor's client id), carries it in its markup as the stk-{id} class
 * and data-block-id, and saves its own CSS in a style tag inside the
 * markup. On save_post Stackable gathers those styles into the
 * stackable_optimized_css and stackable_optimized_css_raw post meta and,
 * when that meta is present, prints it in the head and strips the inline
 * styles it holds. Without the meta the inline styles render as saved.
 *
 * Stackable is not installed in the test core: its blocks are stood in by
 * registering them the way it does (no attributes) and presence is toggled
 * through the wpmcp_block_suite_active filter.
 */
class BlockSuitesStackableTest extends \WP_UnitTestCase
{
    /** @var array<string,bool> suite slug => active */
    private array $active = [ 'stackable' => true ];

    /** @var callable */
    private $presence;

    /** @var callable */
    private $kses;

    /** @var string[] */
    private array $registered = [];

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        Gate::set_pro_for_tests(true);
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
        $this->presence = fn ($default, string $suite): bool => $this->active[ $suite ] ?? false;
        add_filter('wpmcp_block_suite_active', $this->presence, 10, 2);

        // Stackable's kses.php lets users who can edit posts keep its style
        // tags; without it a multisite admin (no unfiltered_html) loses them.
        $this->kses = static function ($tags) {
            if (current_user_can('edit_posts')) {
                $tags['style'] = [ 'id' => true, 'class' => true, 'type' => true ];
            }
            return $tags;
        };
        add_filter('wp_kses_allowed_html', $this->kses, 10, 1);

        $this->register('stackable/text', []);
        $this->register('stackable/columns', []);
        $this->register('stackable/column', []);
        // icon-list is the one Stackable block.json that declares uniqueId.
        $this->register('stackable/icon-list', [ 'uniqueId' => [ 'type' => 'string' ] ]);
    }

    protected function tearDown(): void
    {
        remove_filter('wpmcp_block_suite_active', $this->presence, 10);
        remove_filter('wp_kses_allowed_html', $this->kses, 10);
        foreach ($this->registered as $name) {
            if (\WP_Block_Type_Registry::get_instance()->is_registered($name)) {
                unregister_block_type($name);
            }
        }
        Gate::set_pro_for_tests(null);
        parent::tearDown();
    }

    private function register(string $name, array $attributes): void
    {
        register_block_type($name, [ 'title' => $name, 'attributes' => $attributes ]);
        $this->registered[] = $name;
    }

    private function read(string $op, array $args = []): array
    {
        return ( new Block_Suites_Integration() )->handle_read([ 'operation' => $op, 'args' => $args ]);
    }

    private function write(string $op, array $args): array
    {
        return ( new Block_Suites_Integration() )->handle_write([ 'operation' => $op, 'args' => $args ]);
    }

    /** @return array{0:int,1:string} */
    private function post(string $content = ''): array
    {
        $id = self::factory()->post->create([ 'post_type' => 'page', 'post_content' => $content ]);
        return [ $id, hash('sha256', (string) get_post($id)->post_content) ];
    }

    private function content(int $id): string
    {
        clean_post_cache($id);
        return (string) get_post($id)->post_content;
    }

    private function blocks(int $id): array
    {
        return array_values(array_filter(parse_blocks($this->content($id)), static fn ($b) => null !== $b['blockName']));
    }

    /** A Stackable text block as its editor saves it, with its own style tag. */
    private function text(string $uid = '__UNIQUE_ID__', string $extra = ''): string
    {
        $attrs = '__UNIQUE_ID__' === $uid ? '{"textColor1":"#ff0000"' . $extra . '}' : sprintf('{"uniqueId":"%s","textColor1":"#ff0000"%s}', $uid, $extra);
        return sprintf(
            '<!-- wp:stackable/text %1$s --><div class="wp-block-stackable-text stk-block-text stk-block stk-%2$s" data-block-id="%2$s"><style>.stk-%2$s .stk-block-text__text{color:#ff0000 !important}</style><p class="stk-block-text__text has-text-color">Hi</p></div><!-- /wp:stackable/text -->',
            $attrs,
            $uid
        );
    }

    private function seed_optimized_css(int $id): void
    {
        update_post_meta($id, 'stackable_optimized_css', '.stk-abc1234 .stk-block-text__text{color:red}');
        update_post_meta($id, 'stackable_optimized_css_raw', [ 'abc1234' => [ [ 'stackable/text', '.stk-abc1234 .stk-block-text__text{color:red}' ] ] ]);
    }

    // ------------------------------------------------------------ surface

    public function test_list_suites_reports_stackable(): void
    {
        $by = array_column($this->read('list-suites')['result']['suites'], null, 'suite');

        $this->assertArrayHasKey('stackable', $by);
        $this->assertSame('Stackable', $by['stackable']['label'] ?? 'Stackable');
        $this->assertSame('uniqueId', $by['stackable']['unique_id_attribute']);
        $this->assertSame('stackable/', $by['stackable']['namespace']);
        $this->assertSame('per_post_cache', $by['stackable']['css_model']);
        $this->assertTrue($by['stackable']['active']);
    }

    public function test_the_pair_registers_while_only_stackable_is_active(): void
    {
        $this->assertTrue(( new Block_Suites_Integration() )->should_register());
        $this->active = [];
        $this->assertFalse(( new Block_Suites_Integration() )->should_register());
    }

    public function test_stackable_is_detected_by_its_version_constant(): void
    {
        $this->assertSame('STACKABLE_VERSION', Block_Suite::spec('stackable')['constant']);
        $this->assertSame('STACKABLE_VERSION', Block_Suite::spec('stackable')['version']);
    }

    public function test_schemas_list_the_registered_blocks_with_their_unique_id_and_allow_editor_attributes(): void
    {
        $out = $this->read('get-block-schemas', [ 'suite' => 'stackable' ]);
        $this->assertArrayNotHasKey('error', $out, wp_json_encode($out));
        $by = array_column($out['result']['blocks'], null, 'name');

        $this->assertArrayHasKey('stackable/text', $by);
        $this->assertArrayHasKey('stackable/icon-list', $by);
        $this->assertContains('uniqueId', $by['stackable/text']['attributes']);
        $this->assertSame('allowed', $out['result']['unknown_attributes'], 'Stackable declares its attributes only in the editor');

        $registered = \WP_Block_Type_Registry::get_instance()->get_registered('stackable/text');
        $this->assertArrayNotHasKey('uniqueId', (array) $registered->attributes, 'the registered type is never widened');
    }

    // ------------------------------------------------------------ writes

    public function test_insert_generates_a_seven_character_unique_id_and_fills_the_markup(): void
    {
        [ $id, $hash ] = $this->post('<!-- wp:paragraph --><p>a</p><!-- /wp:paragraph -->');
        $out           = $this->write('insert-block', [
            'suite'         => 'stackable',
            'id'            => $id,
            'expected_hash' => $hash,
            'path'          => [ 1 ],
            'markup'        => $this->text(),
        ]);
        $this->assertArrayNotHasKey('error', $out, wp_json_encode($out));

        $node = $this->blocks($id)[1];
        $uid  = (string) $node['attrs']['uniqueId'];
        $this->assertMatchesRegularExpression('/^[0-9a-f]{7}$/', $uid);
        $this->assertSame('#ff0000', $node['attrs']['textColor1'], 'an editor-only attribute is kept');
        $this->assertStringContainsString('stk-' . $uid . '"', $node['innerHTML']);
        $this->assertStringContainsString('data-block-id="' . $uid . '"', $node['innerHTML']);
        $this->assertStringContainsString('<style>.stk-' . $uid . ' .stk-block-text__text', $node['innerHTML'], 'the inline style follows the id');
        $this->assertStringNotContainsString('__UNIQUE_ID__', $this->content($id));
        $this->assertSame($uid, $out['result']['unique_ids'][0]['unique_id']);
    }

    public function test_nested_blocks_each_get_their_own_id_and_a_duplicate_is_regenerated(): void
    {
        [ $id, ] = $this->post('');
        wp_update_post([ 'ID' => $id, 'post_content' => wp_slash($this->text('abc1234')) ]);
        $hash = hash('sha256', $this->content($id));

        $markup = '<!-- wp:stackable/columns {"uniqueId":"__UNIQUE_ID__"} --><div class="wp-block-stackable-columns stk-block-columns stk-block stk-__UNIQUE_ID__" data-block-id="__UNIQUE_ID__"><div class="stk-row stk-inner-blocks stk-block-content stk-content-align stk-__UNIQUE_ID__-column">'
            . '<!-- wp:stackable/column --><div class="wp-block-stackable-column stk-block-column stk-column stk-block stk-__UNIQUE_ID__" data-block-id="__UNIQUE_ID__"><div class="stk-column-wrapper stk-block-column__content stk-container stk-__UNIQUE_ID__-container"><div class="stk-block-content stk-inner-blocks stk-__UNIQUE_ID__-inner-blocks">'
            . $this->text('abc1234')
            . '</div></div></div><!-- /wp:stackable/column --></div></div><!-- /wp:stackable/columns -->';

        $out = $this->write('insert-block', [ 'suite' => 'stackable', 'id' => $id, 'expected_hash' => $hash, 'path' => [ 1 ], 'markup' => $markup ]);
        $this->assertArrayNotHasKey('error', $out, wp_json_encode($out));

        $columns = $this->blocks($id)[1];
        $column  = $columns['innerBlocks'][0];
        $text    = $column['innerBlocks'][0];
        $ids     = [ $columns['attrs']['uniqueId'], $column['attrs']['uniqueId'], $text['attrs']['uniqueId'] ];
        $this->assertCount(3, array_unique($ids));
        $this->assertNotSame('abc1234', $text['attrs']['uniqueId'], 'an id already used in the post is replaced');
        $this->assertStringContainsString('stk-' . $text['attrs']['uniqueId'] . ' .stk-block-text__text', $text['innerHTML'], 'the old id is rewritten in the style tag');
        $this->assertStringNotContainsString('abc1234', $text['innerHTML']);
        $this->assertStringContainsString('stk-' . $column['attrs']['uniqueId'] . '-container', implode('', array_filter($column['innerContent'], 'is_string')));
        $this->assertCount(3, $out['result']['unique_ids']);
    }

    public function test_update_keeps_the_unique_id(): void
    {
        [ $id, ] = $this->post('');
        wp_update_post([ 'ID' => $id, 'post_content' => wp_slash($this->text('abc1234')) ]);
        $before = $this->content($id);

        $out = $this->write('update-block', [
            'suite' => 'stackable', 'id' => $id, 'expected_hash' => hash('sha256', $before), 'path' => [ 0 ],
            'attrs' => [ 'uniqueId' => 'fffffff' ],
        ]);
        $this->assertSame('unique_id_immutable', $out['error']['code'] ?? null);

        $out = $this->write('update-block', [
            'suite' => 'stackable', 'id' => $id, 'expected_hash' => hash('sha256', $before), 'path' => [ 0 ],
            'attrs' => [ 'textColor1' => '#00ff00' ],
        ]);
        $this->assertArrayNotHasKey('error', $out, wp_json_encode($out));
        $node = $this->blocks($id)[0];
        $this->assertSame('abc1234', $node['attrs']['uniqueId']);
        $this->assertSame('#00ff00', $node['attrs']['textColor1']);
    }

    public function test_write_and_rollback_drop_the_optimized_css_so_the_inline_styles_render(): void
    {
        [ $id, $hash ] = $this->post('<!-- wp:paragraph --><p>a</p><!-- /wp:paragraph -->');
        $original      = $this->content($id);
        $this->seed_optimized_css($id);

        $out = $this->write('insert-block', [ 'suite' => 'stackable', 'id' => $id, 'expected_hash' => $hash, 'path' => [ 0 ], 'markup' => $this->text() ]);
        $this->assertArrayNotHasKey('error', $out, wp_json_encode($out));
        $this->assertSame('per_post_cache', $out['result']['css']['model']);
        $this->assertTrue($out['result']['css']['refreshed']);
        $this->assertSame('', get_post_meta($id, 'stackable_optimized_css', true));
        $this->assertSame('', get_post_meta($id, 'stackable_optimized_css_raw', true));

        // Stackable rebuilt the meta on a later save, then the write is undone.
        $this->seed_optimized_css($id);
        $this->assertTrue(Rollback_Service::restore_operation($out['operation_ids'][0]));
        $this->assertSame($original, $this->content($id), 'rollback restores the exact bytes');
        $this->assertSame('', get_post_meta($id, 'stackable_optimized_css', true), 'the cache for the undone content is dropped');
        $this->assertSame('', get_post_meta($id, 'stackable_optimized_css_raw', true));
    }

    public function test_an_inactive_stackable_is_refused_and_its_cache_left_alone(): void
    {
        [ $id, ] = $this->post('');
        $this->seed_optimized_css($id);
        $this->active['stackable'] = false;
        $this->active['spectra']   = true; // keep the pair registered

        $out = $this->read('get-block-schemas', [ 'suite' => 'stackable' ]);
        $this->assertSame('suite_unavailable', $out['error']['code']);

        Block_Suite::refresh_after_restore($id);
        $this->assertNotSame('', get_post_meta($id, 'stackable_optimized_css', true));
    }
}
