<?php

namespace WPMCP\Tests\Pro\WooCommerce;

use WPMCP\MCP\Ability;
use WPMCP\MCP\Registrar;
use WPMCP\Pro\Gate;
use WPMCP\Tests\Free\Platform\RegisteredAbilities;
use WPMCP\Tools\WooCommerce\Catalog\Op_Catalog;

/**
 * The WooCommerce catalog ops (woo-read / woo-write) name store objects by id
 * in their params, and the permission decision checks the per-object
 * capability for each one the same way it does for the integration packs
 * (issue #461, following #450): every catalog row declares its object keys
 * ('objects'), and Object_Guard checks read_post / edit_post / delete_post
 * for a product, variation or coupon, the order capability for an order or
 * refund, list_users / edit_user for a customer, edit_comment for a review
 * and edit_term / delete_term for a brand.
 *
 * The first test is the registry walk: every path param of every op that
 * names an id, plus the id-bearing body params the in-process handlers read,
 * is either declared or listed as a row in one of WooCommerce's own tables
 * (a shipping zone, a webhook, a gateway, a setting), which the op's store
 * capability already covers. The second aims each declared key at another
 * user's object as a Contributor holding only the dispatcher's capability.
 * The last ones run the real roles: Contributor, Author, Editor, Shop Manager
 * and Administrator.
 */
class WooObjectCapabilityTest extends \WP_UnitTestCase
{
    /**
     * op:key => what the id names. Each is a row in one of WooCommerce's own
     * tables or a string id, behind the op's store capability.
     */
    private const NOT_WP_OBJECTS = [
        'shipping.zone:id'                    => 'a shipping zone row',
        'shipping.zone-methods:zone_id'       => 'a shipping zone row',
        'shipping.update-zone:id'             => 'a shipping zone row',
        'shipping.delete-zone:id'             => 'a shipping zone row',
        'shipping.add-method:zone_id'         => 'a shipping zone row',
        'shipping.update-method:zone_id'      => 'a shipping zone row',
        'shipping.update-method:instance_id'  => 'a shipping method instance row',
        'shipping.remove-method:zone_id'      => 'a shipping zone row',
        'shipping.remove-method:instance_id'  => 'a shipping method instance row',
        'gateways.get:id'                     => 'a payment gateway id (a string)',
        'gateways.update:id'                  => 'a payment gateway id (a string)',
        'system-status.run-tool:id'           => 'a status tool id (a string)',
        'webhooks.get:id'                     => 'a webhook row',
        'webhooks.update:id'                  => 'a webhook row',
        'webhooks.pause:id'                   => 'a webhook row',
        'webhooks.delete:id'                  => 'a webhook row',
        'settings.options:group_id'           => 'a settings group id (a string)',
        'settings.update:group_id'            => 'a settings group id (a string)',
        'settings.update:id'                  => 'a setting id (a string)',
        'orders.update:line_items.*.id'       => 'an order item row of the order being edited',
    ];

    /**
     * Id-bearing params an op reads outside its route, which a route template
     * cannot show: the order ops' customer and line items, the review reply's
     * target and the review listing's product filter.
     */
    private const BODY_ID_KEYS = [
        'orders.create' => [ 'customer_id', 'line_items.*.product_id', 'line_items.*.variation_id' ],
        'orders.update' => [ 'customer_id', 'line_items.*.product_id', 'line_items.*.variation_id', 'line_items.*.id' ],
        'reviews.reply' => [ 'id' ],
        'reviews.list'  => [ 'product_id' ],
    ];

    private string $granted = '';

    protected function setUp(): void
    {
        parent::setUp();
        if (! class_exists('WooCommerce')) {
            $this->markTestSkipped('WooCommerce is not active in this environment.');
        }
        Gate::set_pro_for_tests(true);
        \WC_Install::create_roles();
        $GLOBALS['wp_roles'] = null;
        wp_roles();
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
        if ('' !== $this->granted) {
            $allcaps[ $this->granted ] = true;
        }
        return $allcaps;
    }

    /** @return array{0: Ability, 1: Ability} woo-read, woo-write */
    private static function pair(): array
    {
        $read  = null;
        $write = null;
        foreach (RegisteredAbilities::all() as $a) {
            if ('wpmcp/woo-read' === $a->name) {
                $read = $a;
            } elseif ('wpmcp/woo-write' === $a->name) {
                $write = $a;
            }
        }
        self::assertNotNull($read);
        self::assertNotNull($write);
        return [ $read, $write ];
    }

    /** The params that put $value at $path. */
    private static function params_at(string $path, int $value): array
    {
        $parts = explode('.', $path);
        $leaf  = array_pop($parts);
        $args  = [ $leaf => $value ];
        while ([] !== $parts) {
            $part = array_pop($parts);
            $args = '*' === $part ? [ $args ] : [ $part => $args ];
        }
        return $args;
    }

    private function permits(string $op, array $params): bool
    {
        [$read, $write] = self::pair();
        $def            = Op_Catalog::get($op);
        $ability        = 'read' === $def['mode'] ? $read : $write;
        return (new Registrar())->would_permit($ability, [ 'op' => $op, 'params' => $params ]);
    }

    private function product(int $author, string $status = 'publish'): int
    {
        return self::factory()->post->create([ 'post_type' => 'product', 'post_author' => $author, 'post_status' => $status ]);
    }

    public function test_every_catalog_op_that_names_an_id_declares_it(): void
    {
        $undeclared = [];
        $stale      = self::NOT_WP_OBJECTS;
        foreach (Op_Catalog::ops() as $name => $def) {
            $keys = array_merge(
                array_values(array_filter($def['path_params'], static fn (string $p): bool => 1 === preg_match('/(^|_)ids?$/', $p))),
                self::BODY_ID_KEYS[ $name ] ?? []
            );
            foreach ($keys as $key) {
                $label = $name . ':' . $key;
                unset($stale[ $label ]);
                if (! isset($def['objects'][ $key ]) && ! isset(self::NOT_WP_OBJECTS[ $label ])) {
                    $undeclared[] = $label;
                }
            }
        }
        $this->assertSame([], $undeclared, 'Declare these in the op\'s objects map');
        $this->assertSame([], array_keys($stale), 'No longer an op key; drop it from NOT_WP_OBJECTS');
    }

    public function test_every_declared_object_is_refused_to_a_contributor_on_another_users_object(): void
    {
        $owner       = self::factory()->user->create([ 'role' => 'administrator' ]);
        $contributor = self::factory()->user->create([ 'role' => 'contributor' ]);
        $live        = $this->product($owner);
        $draft       = $this->product($owner, 'draft');
        $own_draft   = $this->product($contributor, 'draft');
        $held        = self::factory()->comment->create([ 'comment_post_ID' => $live, 'comment_type' => 'review', 'comment_approved' => '0' ]);
        $order       = wc_create_order()->get_id();
        $brand       = taxonomy_exists('product_brand') ? (int) wp_insert_term('Acme ' . wp_generate_password(6, false), 'product_brand')['term_id'] : 0;

        [$read, $write] = self::pair();
        wp_set_current_user($contributor);
        $this->granted = 'manage_woocommerce';
        $registrar     = new Registrar();
        $walked        = [];
        $failures      = [];

        foreach (Op_Catalog::ops() as $op => $def) {
            $ability = 'read' === $def['mode'] ? $read : $write;
            foreach ($def['objects'] as $path => $spec) {
                $walked[] = $op . ':' . $path;
                $cases    = match ($spec['type']) {
                    'post'    => array_filter([
                        'another user\'s draft'          => [ $draft, true ],
                        'another user\'s published post' => 'read' === $spec['access'] ? null : [ $live, true ],
                        'their own draft'                => [ $own_draft, false ],
                    ]),
                    'user'    => [ 'another user' => [ $owner, true ] ],
                    'comment' => [ 'a held review' => [ $held, true ] ],
                    'order'   => [ 'an order' => [ $order, true ] ],
                    'term'    => 'read' === $spec['access'] || 0 === $brand ? [] : [ 'a brand' => [ $brand, true ] ],
                    default   => [ 'unknown kind ' . $spec['type'] => [ 0, true ] ],
                };
                foreach ($cases as $label => [$id, $refused]) {
                    $params = self::params_at($path, $id);
                    $inputs = [ [ 'op' => $op, 'params' => $params ] ];
                    if ($ability === $write) {
                        $inputs[] = [ 'batch' => [ [ 'op' => $op, 'params' => $params ] ] ];
                    }
                    foreach ($inputs as $input) {
                        $allowed = $registrar->would_permit($ability, $input);
                        if ($allowed === $refused) {
                            $failures[] = sprintf('%s %s=%s%s: %s', $op, $path, $label, isset($input['batch']) ? ' (batch)' : '', $allowed ? 'allowed' : 'refused');
                        }
                    }
                }
            }
        }
        $this->granted = '';

        foreach ([
            'products.get:id',
            'products.update:id',
            'products.delete:id',
            'variations.update:product_id',
            'orders.get:id',
            'orders.update:customer_id',
            'orders.create:line_items.*.product_id',
            'refunds.create:order_id',
            'coupons.update:id',
            'customers.update:id',
            'reviews.approve:id',
            'brands.assign:product_id',
        ] as $label) {
            $this->assertContains($label, $walked);
        }
        $this->assertSame([], $failures);
    }

    /** @return array<string, array{0: string, 1: bool}> role => [role, is a store manager] */
    public static function roles(): array
    {
        return [
            'contributor'   => [ 'contributor', false ],
            'author'        => [ 'author', false ],
            'editor'        => [ 'editor', false ],
            'shop manager'  => [ 'shop_manager', true ],
            'administrator' => [ 'administrator', true ],
        ];
    }

    /**
     * @dataProvider roles
     */
    public function test_store_roles_keep_the_catalog_and_others_are_refused(string $role, bool $store): void
    {
        $owner    = self::factory()->user->create([ 'role' => 'administrator' ]);
        $live     = $this->product($owner);
        $draft    = $this->product($owner, 'draft');
        $held     = self::factory()->comment->create([ 'comment_post_ID' => $live, 'comment_type' => 'review', 'comment_approved' => '0' ]);
        $order    = wc_create_order()->get_id();
        $customer = self::factory()->user->create([ 'role' => 'customer' ]);
        wp_set_current_user(self::factory()->user->create([ 'role' => $role ]));

        $this->assertSame($store, $this->permits('products.get', [ 'id' => $draft ]), 'read a draft product');
        $this->assertSame($store, $this->permits('products.update', [ 'id' => $live, 'name' => 'x' ]), 'update a product');
        $this->assertSame($store, $this->permits('variations.list', [ 'product_id' => $draft ]), 'list a draft product\'s variations');
        $this->assertSame($store, $this->permits('orders.get', [ 'id' => $order ]), 'read an order');
        $this->assertSame($store, $this->permits('orders.add-note', [ 'order_id' => $order, 'note' => 'x' ]), 'note an order');
        $this->assertSame($store, $this->permits('reviews.approve', [ 'id' => $held ]), 'approve a held review');
        $this->assertSame($store, $this->permits('customers.get', [ 'id' => $customer ]), 'read a customer');
    }

    /**
     * A store manager edits customers, never an administrator: the customer
     * key checks edit_user on the named account, which WooCommerce itself
     * narrows for shop managers to customer accounts.
     *
     * @dataProvider roles
     */
    public function test_editing_an_administrator_account_needs_edit_user_on_it(string $role, bool $store): void
    {
        $admin = self::factory()->user->create([ 'role' => 'administrator' ]);
        wp_set_current_user(self::factory()->user->create([ 'role' => $role ]));
        $this->granted = 'manage_woocommerce';

        $this->assertSame('administrator' === $role, $this->permits('customers.update', [ 'id' => $admin, 'first_name' => 'x' ]));
    }

    /**
     * A woo-write batch naming a session another user started is refused as
     * a whole before any item runs (issue #461); the caller's own or a new
     * session is not.
     */
    public function test_a_batch_into_another_users_session_is_refused_whole(): void
    {
        $admin   = self::factory()->user->create([ 'role' => 'administrator' ]);
        $product = $this->product($admin);
        $session = wp_generate_uuid4();
        wp_set_current_user($admin);
        \WPMCP\Safety\Safe_Mutation::run(
            [ 'object_type' => 'post', 'object_id' => $product, 'session_id' => $session, 'tool_name' => 'woo-write', 'args' => [] ],
            static fn () => wp_update_post([ 'ID' => $product, 'post_title' => 'by admin' ])
        );

        wp_set_current_user(self::factory()->user->create([ 'role' => 'shop_manager' ]));
        $batch = [ [ 'op' => 'products.update', 'params' => [ 'id' => $product, 'name' => 'by manager' ] ] ];
        $out   = (new \WPMCP\Tools\WooCommerce\Catalog\Woo_Write())->handle([ 'batch' => $batch, 'session_id' => $session ]);

        $this->assertSame('session_not_owned', $out['error']['code'] ?? null);
        $this->assertStringContainsString('another user', $out['error']['message']);
        $this->assertSame('by admin', get_post_field('post_title', $product));
        $this->assertCount(1, \WPMCP\Safety\Snapshot_Store::list_by_session($session));
    }
}
