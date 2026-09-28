<?php

namespace WPMCP\Tests\Pro\Elementor;

use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Brand\Apply_Brand_Kit;
use WPMCP\Tools\Brand\Brand_Kit_Store;
use WPMCP\Tools\Brand\Get_Brand_Kit;
use WPMCP\Tools\Brand\List_Brand_Kits;
use WPMCP\Tools\Brand\Rollback_Brand_Kit;
use WPMCP\Tools\Elementor\Get_Global_Settings;

/**
 * Brand kits (issue #75).
 *
 * A brand kit is a named design system stored as data (bundled presets plus
 * the `wpmcp_brand_kits` option/filter) that apply-brand-kit folds into ONE
 * `_elementor_page_settings` patch, so the entire rebrand is a single
 * snapshot and a single operation_id. These tests pin the two properties
 * that matter: the apply is atomic and fully reversible in one step, and it
 * refuses to write on an unconfirmed call, a stale hash or a kit whose
 * definition does not validate.
 */
class BrandKitsTest extends Structural_Harness
{
    protected function setUp(): void
    {
        parent::setUp();
        delete_option(Brand_Kit_Store::OPTION_KITS);
        delete_option(Brand_Kit_Store::OPTION_APPLIES);
    }

    protected function tearDown(): void
    {
        delete_option(Brand_Kit_Store::OPTION_KITS);
        delete_option(Brand_Kit_Store::OPTION_APPLIES);
        remove_all_filters('wpmcp_brand_kits');
        parent::tearDown();
    }

    private function kit_id(): int
    {
        return (int) \Elementor\Plugin::instance()->kits_manager->get_active_id();
    }

    private function seed_kit(array $settings): void
    {
        update_post_meta($this->kit_id(), '_elementor_page_settings', $settings);
        clean_post_cache($this->kit_id());
    }

    private function kit_meta(): array
    {
        $settings = get_post_meta($this->kit_id(), '_elementor_page_settings', true);
        return is_array($settings) ? $settings : [];
    }

    private function entry_by_id(array $entries, string $id): ?array
    {
        foreach ($entries as $entry) {
            if (($entry['_id'] ?? null) === $id) {
                return $entry;
            }
        }
        return null;
    }

    private function hash(): string
    {
        return (new Get_Global_Settings())->handle([])['settings_hash'];
    }

    private function apply(string $slug = 'modern-saas', array $extra = []): array
    {
        $out = (new Apply_Brand_Kit())->handle(array_merge(
            ['slug' => $slug, 'confirm' => true, 'expected_hash' => $this->hash()],
            $extra
        ));
        $this->assertIsArray($out, 'apply-brand-kit should have succeeded');
        return $out;
    }

    // ---- store --------------------------------------------------------------

    public function test_bundled_kits_all_normalize_and_are_valid(): void
    {
        $kits = Brand_Kit_Store::all();

        $this->assertNotEmpty($kits);
        foreach (Brand_Kit_Store::bundled() as $slug => $_definition) {
            $this->assertArrayHasKey($slug, $kits);
            $this->assertSame('bundled', $kits[ $slug ]['source']);
            $this->assertSame([], $kits[ $slug ]['invalid'], sprintf('Bundled kit "%s" must validate cleanly.', $slug));
            $this->assertSame(Brand_Kit_Store::SYSTEM_SLOTS, array_keys($kits[ $slug ]['colors']));
        }
    }

    public function test_friendly_typography_keys_map_to_elementor_fields(): void
    {
        $primary = Brand_Kit_Store::get('modern-saas')['typography']['primary'];

        $this->assertSame('Inter', $primary['typography_font_family']);
        $this->assertSame('700', $primary['typography_font_weight']);
        $this->assertSame(['unit' => 'px', 'size' => 40.0, 'sizes' => []], $primary['typography_font_size']);
        $this->assertSame(['unit' => 'em', 'size' => 1.2, 'sizes' => []], $primary['typography_line_height']);
        // A font only renders once the token is switched to custom.
        $this->assertSame('custom', $primary['typography_typography']);
    }

    public function test_measure_accepts_bare_numbers_and_explicit_objects(): void
    {
        update_option(Brand_Kit_Store::OPTION_KITS, [
            'measures' => [
                'title'      => 'Measures',
                'colors'     => ['primary' => '#101010'],
                'typography' => [
                    'text' => [
                        'font_size'      => 18,
                        'letter_spacing' => ['size' => 0.5, 'unit' => 'rem'],
                    ],
                ],
            ],
        ]);

        $text = Brand_Kit_Store::get('measures')['typography']['text'];

        $this->assertSame(['unit' => 'px', 'size' => 18.0, 'sizes' => []], $text['typography_font_size']);
        $this->assertSame(['unit' => 'rem', 'size' => 0.5, 'sizes' => []], $text['typography_letter_spacing']);
    }

    public function test_logo_accepts_a_url_only_reference_and_rejects_an_empty_one(): void
    {
        update_option(Brand_Kit_Store::OPTION_KITS, [
            'url-logo'  => [
                'colors' => ['primary' => '#101010'],
                'logo'   => ['url' => 'https://example.com/mark.svg'],
            ],
            'no-logo'   => [
                'colors' => ['primary' => '#101010'],
                'logo'   => ['caption' => 'nothing usable'],
            ],
            'bad-sizes' => [
                'colors'     => ['primary' => '#101010'],
                'typography' => ['text' => ['font_size' => ['size' => 'big', 'unit' => 'px']]],
            ],
        ]);

        $url_logo = Brand_Kit_Store::get('url-logo');
        $this->assertSame(['id' => 0, 'url' => 'https://example.com/mark.svg'], $url_logo['logo']);
        $this->assertSame([], $url_logo['invalid']);
        // Titles fall back to a readable form of the slug.
        $this->assertSame('Url Logo', $url_logo['title']);

        $no_logo = Brand_Kit_Store::get('no-logo');
        $this->assertNull($no_logo['logo']);
        $this->assertContains('logo: needs an attachment_id or a url.', $no_logo['invalid']);

        $bad_sizes = Brand_Kit_Store::get('bad-sizes');
        $this->assertSame([], $bad_sizes['typography']);
        $this->assertNotEmpty($bad_sizes['invalid']);
    }

    public function test_site_option_kit_is_listed_as_site_source(): void
    {
        update_option(Brand_Kit_Store::OPTION_KITS, [
            'house-style' => [
                'title'    => 'House Style',
                'category' => 'internal',
                'colors'   => ['primary' => '#ff0000'],
            ],
        ]);

        $kit = Brand_Kit_Store::get('house-style');

        $this->assertNotNull($kit);
        $this->assertSame('site', $kit['source']);
        $this->assertSame('internal', $kit['category']);
        $this->assertSame(['primary' => '#ff0000'], $kit['colors']);
    }

    public function test_site_definition_shadows_a_bundled_slug_and_is_marked_site(): void
    {
        update_option(Brand_Kit_Store::OPTION_KITS, [
            'modern-saas' => ['title' => 'Retuned SaaS', 'colors' => ['primary' => '#000fff']],
        ]);

        $kit = Brand_Kit_Store::get('modern-saas');

        $this->assertSame('site', $kit['source']);
        $this->assertSame('Retuned SaaS', $kit['title']);
        $this->assertSame('#000fff', $kit['colors']['primary']);
    }

    public function test_filter_can_register_a_kit(): void
    {
        add_filter('wpmcp_brand_kits', static function ($kits) {
            $kits['filtered'] = ['title' => 'Filtered', 'colors' => ['accent' => '#123456']];
            return $kits;
        });

        $this->assertSame('#123456', Brand_Kit_Store::get('filtered')['colors']['accent']);
    }

    public function test_filter_returning_a_non_array_is_ignored(): void
    {
        add_filter('wpmcp_brand_kits', static fn () => 'nonsense');

        $this->assertNotNull(Brand_Kit_Store::get('modern-saas'));
    }

    public function test_invalid_entries_are_collected_not_silently_dropped(): void
    {
        update_option(Brand_Kit_Store::OPTION_KITS, [
            'broken' => [
                'title'         => 'Broken',
                'colors'        => ['primary' => '#0a0a0a', 'nope' => '#111111', 'accent' => 'rebeccapurple'],
                'custom_colors' => [['title' => 'Bad', 'color' => 'zzz'], ['color' => '#ffffff'], 'not-an-object'],
                'typography'    => [
                    'primary' => ['font_size' => 'huge'],
                    'text'    => ['mystery' => 'x'],
                    'nope'    => ['font_family' => 'X'],
                    'accent'  => 'not-an-object',
                ],
                'logo'          => 'not-an-object',
            ],
        ]);

        $kit = Brand_Kit_Store::get('broken');

        $this->assertSame(['primary' => '#0a0a0a'], $kit['colors']);
        $this->assertSame([], $kit['custom_colors']);
        $this->assertSame([], $kit['typography']);
        $this->assertNull($kit['logo']);
        $this->assertGreaterThanOrEqual(8, count($kit['invalid']));
    }

    public function test_definition_with_nothing_appliable_is_not_a_kit(): void
    {
        update_option(Brand_Kit_Store::OPTION_KITS, [
            'empty'   => ['title' => 'Empty'],
            'notarray' => 'string',
            ''        => ['colors' => ['primary' => '#ffffff']],
        ]);

        $kits = Brand_Kit_Store::all();

        $this->assertArrayNotHasKey('empty', $kits);
        $this->assertArrayNotHasKey('notarray', $kits);
        $this->assertArrayNotHasKey('', $kits);
    }

    // ---- list-brand-kits / get-brand-kit ------------------------------------

    public function test_list_returns_every_kit_with_a_palette_and_font_summary(): void
    {
        $out = (new List_Brand_Kits())->handle([]);

        $this->assertSame(count($out['kits']), $out['count']);
        $slugs = array_column($out['kits'], 'slug');
        $this->assertContains('modern-saas', $slugs);

        $modern = $out['kits'][ array_search('modern-saas', $slugs, true) ];
        $this->assertSame(['Inter'], $modern['fonts']);
        $this->assertFalse($modern['has_logo']);
        $this->assertSame('bundled', $modern['source']);
    }

    public function test_list_filters_by_search_category_and_source(): void
    {
        update_option(Brand_Kit_Store::OPTION_KITS, [
            'house-style' => ['title' => 'House Style', 'category' => 'internal', 'colors' => ['primary' => '#ff0000']],
        ]);

        $by_search = (new List_Brand_Kits())->handle(['search' => 'EDITORIAL']);
        $this->assertSame(['editorial-serif'], array_column($by_search['kits'], 'slug'));

        $by_category = (new List_Brand_Kits())->handle(['category' => 'product']);
        $this->assertSame(['dark-product', 'modern-saas'], array_column($by_category['kits'], 'slug'));

        $by_source = (new List_Brand_Kits())->handle(['source' => 'site']);
        $this->assertSame(['house-style'], array_column($by_source['kits'], 'slug'));
    }

    public function test_get_returns_the_definition_and_errors_on_unknown_slugs(): void
    {
        $kit = (new Get_Brand_Kit())->handle(['slug' => 'bold-agency']);
        $this->assertSame('Bold Agency', $kit['title']);
        $this->assertSame('#ff3d2e', $kit['colors']['accent']);
        $this->assertSame('canvas', $kit['custom_colors'][0]['_id']);

        $missing = (new Get_Brand_Kit())->handle(['slug' => 'no-such-kit']);
        $this->assertInstanceOf(\WP_Error::class, $missing);
        $this->assertSame('brand_kit_not_found', $missing->get_error_code());

        $blank = (new Get_Brand_Kit())->handle([]);
        $this->assertSame('missing_slug', $blank->get_error_code());
    }

    // ---- apply-brand-kit: refusals ------------------------------------------

    public function test_apply_without_confirm_previews_the_diff_and_writes_nothing(): void
    {
        $this->seed_kit(['system_colors' => [['_id' => 'primary', 'title' => 'Primary', 'color' => '#111111']]]);

        $out = (new Apply_Brand_Kit())->handle(['slug' => 'modern-saas']);

        $this->assertFalse($out['applied']);
        $this->assertTrue($out['preview']);
        $this->assertSame($this->hash(), $out['settings_hash']);
        $this->assertSame(
            ['_id' => 'primary', 'before' => '#111111', 'after' => '#2563eb'],
            $out['changes']['system_colors'][0]
        );
        $this->assertSame(4, $out['counts']['typography_applied']);
        // Untouched: the preview is a read.
        $this->assertSame('#111111', $this->entry_by_id($this->kit_meta()['system_colors'], 'primary')['color']);
        $this->assertArrayNotHasKey('system_typography', $this->kit_meta());
    }

    public function test_apply_refuses_a_stale_expected_hash(): void
    {
        $this->seed_kit(['system_colors' => [['_id' => 'primary', 'title' => 'Primary', 'color' => '#111111']]]);

        $out = (new Apply_Brand_Kit())->handle([
            'slug'          => 'modern-saas',
            'confirm'       => true,
            'expected_hash' => 'deadbeef',
        ]);

        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('stale_expected_hash', $out->get_error_code());
        $this->assertSame('#111111', $this->entry_by_id($this->kit_meta()['system_colors'], 'primary')['color']);
    }

    public function test_apply_refuses_unknown_and_unnamed_slugs(): void
    {
        $missing = (new Apply_Brand_Kit())->handle(['slug' => 'no-such-kit', 'confirm' => true]);
        $this->assertSame('brand_kit_not_found', $missing->get_error_code());

        $blank = (new Apply_Brand_Kit())->handle(['confirm' => true]);
        $this->assertSame('missing_slug', $blank->get_error_code());
    }

    public function test_apply_refuses_a_kit_with_invalid_entries_rather_than_half_applying(): void
    {
        update_option(Brand_Kit_Store::OPTION_KITS, [
            'broken' => ['title' => 'Broken', 'colors' => ['primary' => '#0a0a0a', 'accent' => 'nope']],
        ]);
        $this->seed_kit(['system_colors' => [['_id' => 'primary', 'title' => 'Primary', 'color' => '#111111']]]);

        $out = (new Apply_Brand_Kit())->handle([
            'slug'          => 'broken',
            'confirm'       => true,
            'expected_hash' => $this->hash(),
        ]);

        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('invalid_brand_kit', $out->get_error_code());
        $this->assertSame('#111111', $this->entry_by_id($this->kit_meta()['system_colors'], 'primary')['color']);
    }

    public function test_apply_refuses_a_logo_that_is_not_in_the_media_library(): void
    {
        update_option(Brand_Kit_Store::OPTION_KITS, [
            'logo-kit' => [
                'title'  => 'Logo Kit',
                'colors' => ['primary' => '#0a0a0a'],
                'logo'   => ['attachment_id' => 987654],
            ],
        ]);

        $out = (new Apply_Brand_Kit())->handle([
            'slug'          => 'logo-kit',
            'confirm'       => true,
            'expected_hash' => $this->hash(),
        ]);

        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('logo_not_found', $out->get_error_code());
        $this->assertArrayNotHasKey('site_logo', $this->kit_meta());
    }

    // ---- apply-brand-kit: the write -----------------------------------------

    public function test_apply_writes_colors_swatches_and_typography_in_one_operation(): void
    {
        $this->seed_kit([
            'system_colors' => [
                ['_id' => 'primary', 'title' => 'Primary', 'color' => '#111111'],
                ['_id' => 'secondary', 'title' => 'Secondary', 'color' => '#222222'],
            ],
            'custom_colors' => [['_id' => 'surface', 'title' => 'Surface', 'color' => '#000000']],
        ]);

        $out = $this->apply();

        $this->assertTrue($out['applied']);
        $this->assertNotEmpty($out['operation_id']);

        $meta = $this->kit_meta();
        $this->assertSame('#2563eb', $this->entry_by_id($meta['system_colors'], 'primary')['color']);
        $this->assertSame('#1e293b', $this->entry_by_id($meta['system_colors'], 'secondary')['color']);
        // Slots the seeded kit never stored are still written.
        $this->assertSame('#334155', $this->entry_by_id($meta['system_colors'], 'text')['color']);
        // An existing swatch is updated in place rather than duplicated.
        $this->assertSame('#f8fafc', $this->entry_by_id($meta['custom_colors'], 'surface')['color']);
        $this->assertCount(2, $meta['custom_colors']);
        $this->assertSame('Inter', $this->entry_by_id($meta['system_typography'], 'text')['typography_font_family']);

        // Exactly one snapshot for the whole rebrand.
        $rows = Snapshot_Store::recent(50);
        $mine = array_values(array_filter($rows, static fn ($row) => 'apply-brand-kit' === $row['tool_name']));
        $this->assertCount(1, $mine);
        $this->assertSame($out['operation_id'], $mine[0]['operation_id']);
    }

    public function test_apply_writes_the_logo_and_include_logo_false_skips_it(): void
    {
        $attachment_id = self::factory()->attachment->create_object([
            'file'           => 'logo.png',
            'post_mime_type' => 'image/png',
        ]);
        update_option(Brand_Kit_Store::OPTION_KITS, [
            'logo-kit' => [
                'title'  => 'Logo Kit',
                'colors' => ['primary' => '#0a0a0a'],
                'logo'   => ['attachment_id' => $attachment_id, 'url' => 'https://example.com/logo.png'],
            ],
        ]);

        $skipped = $this->apply('logo-kit', ['include_logo' => false]);
        $this->assertFalse($skipped['counts']['logo_applied']);
        $this->assertArrayNotHasKey('site_logo', $this->kit_meta());

        $applied = $this->apply('logo-kit');
        $this->assertTrue($applied['counts']['logo_applied']);
        $this->assertSame(
            ['id' => $attachment_id, 'url' => 'https://example.com/logo.png'],
            $this->kit_meta()['site_logo']
        );
    }

    public function test_reapplying_the_same_kit_reports_no_token_changes(): void
    {
        $this->apply();

        $second = (new Apply_Brand_Kit())->handle(['slug' => 'modern-saas']);

        $this->assertSame(0, $second['counts']['tokens_changed']);
        $this->assertSame([], $second['changes']['system_colors']);
        $this->assertSame([], $second['changes']['typography']);
    }

    // ---- rollback-brand-kit -------------------------------------------------

    public function test_rollback_undoes_the_whole_rebrand_from_one_snapshot(): void
    {
        $this->seed_kit([
            'system_colors'     => [['_id' => 'primary', 'title' => 'Primary', 'color' => '#111111']],
            'custom_colors'     => [['_id' => 'surface', 'title' => 'Surface', 'color' => '#000000']],
            'system_typography' => [['_id' => 'text', 'title' => 'Text', 'typography_font_family' => 'Georgia']],
        ]);
        $before = $this->kit_meta();

        $applied = $this->apply();
        $this->assertNotSame($before, $this->kit_meta());

        $out = (new Rollback_Brand_Kit())->handle([]);

        $this->assertTrue($out['restored']);
        $this->assertSame($applied['operation_id'], $out['operation_id']);
        $this->assertSame('modern-saas', $out['slug']);
        clean_post_cache($this->kit_id());
        $this->assertSame($before, $this->kit_meta());
    }

    public function test_rollback_targets_the_named_operation(): void
    {
        $first = $this->apply('modern-saas');
        $this->apply('bold-agency');

        $out = (new Rollback_Brand_Kit())->handle(['operation_id' => $first['operation_id']]);

        $this->assertSame($first['operation_id'], $out['operation_id']);
        $this->assertSame('modern-saas', $out['slug']);
        // Restored to the state captured before the FIRST apply.
        clean_post_cache($this->kit_id());
        $this->assertNull($this->entry_by_id($this->kit_meta()['system_colors'] ?? [], 'primary'));
    }

    public function test_rollback_without_any_apply_is_a_structured_refusal(): void
    {
        $out = (new Rollback_Brand_Kit())->handle([]);

        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('no_brand_kit_apply', $out->get_error_code());
    }

    public function test_rollback_reports_when_everything_is_already_rolled_back(): void
    {
        $this->apply();
        (new Rollback_Brand_Kit())->handle([]);

        $out = (new Rollback_Brand_Kit())->handle([]);

        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('no_brand_kit_apply', $out->get_error_code());
        $this->assertStringContainsString('already been rolled back', $out->get_error_message());
    }

    public function test_rollback_rejects_an_unknown_operation_id(): void
    {
        $this->apply();

        $out = (new Rollback_Brand_Kit())->handle(['operation_id' => 'not-an-operation']);

        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('brand_kit_apply_not_found', $out->get_error_code());
    }

    public function test_rollback_explains_a_pruned_snapshot_instead_of_failing_silently(): void
    {
        // An apply whose snapshot has since aged out of the history cap.
        update_option(Brand_Kit_Store::OPTION_APPLIES, [[
            'operation_id'   => 'aged-out-operation',
            'slug'           => 'modern-saas',
            'title'          => 'Modern SaaS',
            'kit_id'         => $this->kit_id(),
            'applied_at'     => gmdate('c'),
            'rolled_back_at' => null,
        ]]);

        $out = (new Rollback_Brand_Kit())->handle([]);

        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('snapshot_unavailable', $out->get_error_code());
    }
}
