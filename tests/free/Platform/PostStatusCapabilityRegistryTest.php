<?php

namespace WPMCP\Tests\Free\Platform;

use WPMCP\MCP\Ability;
use WPMCP\Pro\Gate;
use WPMCP\Safety\Snapshot_Store;

/**
 * Registry-wide companion to PublishCapabilityTest (issue #482).
 *
 * Walks every registered ability, free and pro, that writes: any whose input
 * takes a status or a post type, plus the ones that carry a status inside a
 * nested input (a page spec, a list of items). Each one is either probed
 * here or listed as exempt with the reason, so a new ability that sets a
 * status is a visible decision rather than a silent gap.
 *
 * Each probe runs the ability's handler as a Contributor who also holds the
 * ability's own capability (and, for WooCommerce, the type's edit
 * capabilities), so only the type's create_posts and publish_posts can
 * refuse it. Asking for a publishing status (publish, future, private) or a
 * type the caller may not create must be refused with that capability named;
 * the same call in a status the caller may set must still succeed.
 */
class PostStatusCapabilityRegistryTest extends \WP_UnitTestCase
{
    /** Input keys that set a post status or pick the type a write creates. */
    private const STATUS_KEYS = ['status', 'post_status', 'post_type'];

    /** Writes that set a status inside a nested input the keys above do not reach. */
    private const NESTED = [
        'wpmcp/build-page',
        'wpmcp/bulk-update-products',
        'wpmcp/apply-product-import',
        'wpmcp/import-content',
        'wpmcp/apply-change-set',
    ];

    /** Writes whose status is not a post's publishing status, or that are not the caller's choice, with why. */
    private const EXEMPT = [
        'wpmcp/moderate-comment'     => 'a comment status, not a post status',
        'wpmcp/create-comment'       => 'a comment status, not a post status',
        'wpmcp/reply-to-comment'     => 'a comment status, not a post status',
        'wpmcp/update-order-status'  => 'an order status, gated on edit_shop_orders as WooCommerce\'s order screens are',
        'wpmcp/set-widget-status'    => 'switches a plugin-owned widget definition on or off; manage_options only',
        'wpmcp/set-block-status'     => 'switches a plugin-owned block definition on or off; manage_options only',
        'wpmcp/set-site-part-status' => 'switches a plugin-owned site part on or off; manage_options only',
        'wpmcp/create-code-snippet'  => 'an Elementor custom-code row, not content; manage_options only',
        'wpmcp/import-content'       => 'a site import (manage_options); WordPress\'s importer keeps each item\'s status too',
        'wpmcp/apply-change-set'     => 'a site-to-site sync (manage_options) that never publishes or trashes from a change set on its own',
        'wpmcp/apply-product-import' => 'creates products under the product rule (see WooCommerce probes) and delegates every other write to create-variation and update-product',
    ];

    /** @var string[] */
    private array $granted = [];

    /**
     * bulk-update-products asks the Plugin's Registrar about each item, so
     * it must be populated the way production populates it.
     */
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
        if (class_exists('WC_Install')) {
            \WC_Install::create_roles();
            $GLOBALS['wp_roles'] = null;
            wp_roles();
        }
        add_filter('user_has_cap', [$this, 'grant']);
    }

    protected function tearDown(): void
    {
        remove_filter('user_has_cap', [$this, 'grant']);
        Gate::set_pro_for_tests(null);
        wp_set_current_user(0);
        parent::tearDown();
    }

    /** @param array<string,bool> $allcaps */
    public function grant(array $allcaps): array
    {
        foreach ($this->granted as $cap) {
            $allcaps[ $cap ] = true;
        }
        return $allcaps;
    }

    /**
     * ability => fn (int $caller): [refused input, capability the refusal
     * names, allowed input or null, extra capabilities to grant].
     *
     * @return array<string, \Closure>
     */
    private function probes(): array
    {
        $woo  = ['edit_products', 'edit_others_products', 'edit_published_products', 'edit_private_products', 'read_private_products'];
        $coup = ['edit_shop_coupons', 'edit_others_shop_coupons', 'edit_published_shop_coupons', 'edit_private_shop_coupons', 'read_private_shop_coupons'];

        return [
            'wpmcp/create-post'          => static fn (int $caller): array => [
                ['title' => 'Probe', 'status' => 'publish'],
                'publish_posts',
                ['title' => 'Probe', 'status' => 'pending'],
                [],
            ],
            'wpmcp/update-post'          => fn (int $caller): array => [
                ['post_id' => $this->own_draft($caller), 'status' => 'private'],
                'publish_posts',
                ['post_id' => $this->own_draft($caller), 'status' => 'pending'],
                [],
            ],
            'wpmcp/duplicate-post'       => fn (int $caller): array => [
                ['post_id' => $this->own_draft($caller), 'status' => 'publish'],
                'publish_posts',
                ['post_id' => $this->own_draft($caller), 'status' => 'pending'],
                [],
            ],
            'wpmcp/build-page'           => static fn (int $caller): array => [
                ['spec' => ['title' => 'Probe page', 'status' => 'publish', 'content' => [['type' => 'paragraph', 'settings' => ['text' => 'x']]]]],
                'publish_pages',
                ['spec' => ['title' => 'Probe page', 'content' => [['type' => 'paragraph', 'settings' => ['text' => 'x']]]]],
                ['edit_pages'],
            ],
            'wpmcp/create-product'       => static fn (int $caller): array => [
                ['name' => 'Probe product', 'status' => 'publish'],
                'publish_products',
                ['name' => 'Probe product', 'status' => 'draft'],
                $woo,
            ],
            'wpmcp/update-product'       => fn (int $caller): array => [
                ['id' => $this->draft_product(), 'status' => 'publish'],
                'publish_products',
                ['id' => $this->draft_product(), 'status' => 'pending'],
                $woo,
            ],
            'wpmcp/bulk-update-products' => fn (int $caller): array => [
                ['items' => [['id' => $this->draft_product(), 'status' => 'private']]],
                'publish_products',
                ['items' => [['id' => $this->draft_product(), 'status' => 'pending']]],
                $woo,
            ],
            'wpmcp/create-variation'     => fn (int $caller): array => [
                ['product_id' => $this->variable_product(), 'attributes' => ['size' => 'large']],
                'publish_products',
                null,
                $woo,
            ],
            'wpmcp/update-variation'     => fn (int $caller): array => [
                ['id' => $this->variation(), 'status' => 'private'],
                'publish_products',
                ['id' => $this->variation(), 'sku' => 'probe-' . wp_generate_password(6, false)],
                $woo,
            ],
            'wpmcp/create-coupon'        => static fn (int $caller): array => [
                ['code' => 'probe-live', 'status' => 'publish'],
                'publish_shop_coupons',
                ['code' => 'probe-draft'],
                $coup,
            ],
            'wpmcp/update-coupon'        => fn (int $caller): array => [
                ['id' => $this->draft_coupon(), 'status' => 'publish'],
                'publish_shop_coupons',
                ['id' => $this->draft_coupon(), 'status' => 'pending'],
                $coup,
            ],
        ];
    }

    private function own_draft(int $caller): int
    {
        return self::factory()->post->create(['post_author' => $caller, 'post_status' => 'draft']);
    }

    private function draft_product(): int
    {
        $product = new \WC_Product_Simple();
        $product->set_name('Probe');
        $product->set_status('draft');
        return (int) $product->save();
    }

    private function variable_product(): int
    {
        $attribute = new \WC_Product_Attribute();
        $attribute->set_name('size');
        $attribute->set_options(['small', 'large']);
        $attribute->set_variation(true);
        $parent = new \WC_Product_Variable();
        $parent->set_name('Probe shirt');
        $parent->set_attributes([$attribute]);
        return (int) $parent->save();
    }

    private function variation(): int
    {
        $variation = new \WC_Product_Variation();
        $variation->set_parent_id($this->variable_product());
        $variation->set_attributes(['size' => 'small']);
        return (int) $variation->save();
    }

    private function draft_coupon(): int
    {
        $coupon = new \WC_Coupon();
        $coupon->set_code('probe-' . wp_generate_password(6, false));
        $coupon->set_status('draft');
        return (int) $coupon->save();
    }

    /** The refusal text of a call: the exception message, or the reply when it reports failures itself. */
    private static function outcome(Ability $a, array $input): string
    {
        try {
            $out = ($a->handler)($input);
        } catch (\Throwable $e) {
            return 'refused: ' . $e->getMessage();
        }
        if (is_wp_error($out)) {
            return 'refused: ' . $out->get_error_message();
        }
        $failed = is_array($out) && ! empty($out['failed']);
        return ($failed ? 'refused: ' : 'ok: ') . (string) wp_json_encode($out);
    }

    public function test_every_ability_that_sets_a_post_status_applies_the_publish_rule(): void
    {
        $contributor = self::factory()->user->create(['role' => 'contributor']);
        wp_set_current_user($contributor);

        $probes   = $this->probes();
        $walked   = [];
        $failures = [];

        foreach (RegisteredAbilities::all() as $a) {
            $props    = array_keys((array) ($a->input_schema['properties'] ?? []));
            $sets     = [] !== array_intersect($props, self::STATUS_KEYS) || in_array($a->name, self::NESTED, true);
            if ('read' === $a->operation || ! $sets) {
                continue;
            }
            if (isset(self::EXEMPT[ $a->name ])) {
                continue;
            }
            if (! isset($probes[ $a->name ])) {
                $failures[] = $a->name . ': sets a status or creates a typed post but is neither probed nor exempt';
                continue;
            }
            $walked[] = $a->name;

            [$refused, $needle, $allowed, $caps] = $probes[ $a->name ]($contributor);
            $this->granted = array_merge([$a->capability], $caps);

            $outcome = self::outcome($a, $refused);
            if (0 !== strpos($outcome, 'refused: ') || false === strpos($outcome, $needle)) {
                $failures[] = sprintf('%s: a publishing call was not refused on %s (%s)', $a->name, $needle, $outcome);
            }
            if (null !== $allowed) {
                $outcome = self::outcome($a, $allowed);
                if (0 !== strpos($outcome, 'ok: ')) {
                    $failures[] = sprintf('%s: a call the caller may make was refused (%s)', $a->name, $outcome);
                }
            }
            $this->granted = [];
        }

        foreach (['wpmcp/create-post', 'wpmcp/update-post', 'wpmcp/duplicate-post', 'wpmcp/build-page'] as $name) {
            $this->assertContains($name, $walked);
        }
        $this->assertSame([], $failures);
    }

    public function test_every_exemption_names_a_registered_ability(): void
    {
        $names = array_map(static fn (Ability $a): string => $a->name, RegisteredAbilities::all());
        foreach (array_keys(self::EXEMPT) as $name) {
            if (in_array($name, ['wpmcp/update-order-status', 'wpmcp/apply-product-import'], true) && ! class_exists('WooCommerce')) {
                continue;
            }
            $this->assertContains($name, $names, $name . ' is exempt but not registered');
        }
    }
}
