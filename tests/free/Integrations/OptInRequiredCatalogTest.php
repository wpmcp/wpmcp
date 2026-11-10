<?php

namespace WPMCP\Tests\Free\Integrations;

use WPMCP\Integrations\Integration_Dispatcher;
use WPMCP\MCP\Opt_In_Required;
use WPMCP\Tools\WooCommerce\Catalog\Op_Catalog;
use WPMCP\Tools\WooCommerce\Catalog\Woo_Write;

/**
 * Walks every operation catalog and checks that each op that ships off
 * refuses with opt_in_required, names a filter that really opens it, and
 * that a default-on op a site switched off keeps operation_disabled.
 *
 * The integrations are discovered from src/Integrations rather than listed,
 * so a new pack is covered the day it lands.
 */
class OptInRequiredCatalogTest extends \WP_UnitTestCase
{
    /**
     * Default-off ops with no switch of their own: the per-op filter is the
     * only way in, and the refusal names it with the exact arguments.
     */
    private const PER_OP_ONLY = [
        'contactform7'   => [ 'delete-entry' ],
        'forminator'     => [ 'delete-entry' ],
        'metform'        => [ 'delete-entry' ],
        'sureforms'      => [ 'delete-entry' ],
    ];

    /** @var callable */
    private $astra;

    protected function setUp(): void
    {
        parent::setUp();
        // Theme packs list their ops only for a supported active theme.
        $this->astra = static fn () => 'astra';
        add_filter('template', $this->astra);
    }

    protected function tearDown(): void
    {
        remove_filter('template', $this->astra);
        parent::tearDown();
    }

    public function test_every_default_off_integration_op_refuses_with_opt_in_required_naming_a_working_filter(): void
    {
        $catalog = $this->integration_ops();
        $filters = [];
        foreach ($catalog as $entry) {
            if ('' !== (string) ($entry['def']['opt_in_filter'] ?? '')) {
                $filters[ $entry['def']['opt_in_filter'] ] = true;
            }
        }
        $this->assertNotEmpty($filters, 'the walk found the pack switches');

        // Close every pack switch, whatever an earlier test left behind.
        foreach (array_keys($filters) as $filter) {
            remove_all_filters($filter);
        }
        remove_all_filters('wpmcp_integration_op_enabled');

        $checked = 0;
        foreach ($this->integration_ops() as $entry) {
            [ 'dispatcher' => $dispatcher, 'op' => $op, 'def' => $def ] = $entry;
            if (false !== ($def['enabled_by_default'] ?? true)) {
                continue;
            }
            $label  = $dispatcher->integration() . ' ' . $op;
            $filter = (string) ($def['opt_in_filter'] ?? '');
            if ('' === $filter) {
                $this->assertContains(
                    $op,
                    self::PER_OP_ONLY[ $dispatcher->integration() ] ?? [],
                    "{$label} is default-off: give its definition an opt_in_filter, or list it as per-op only"
                );
            } else {
                $this->assertMatchesRegularExpression('/^wpmcp_[a-z_]+$/', $filter, $label);
            }

            $out = $this->refusal($dispatcher, $op, $def);
            $this->assertSame(Opt_In_Required::CODE, $out['error']['code'], $label);
            $this->assertStringContainsString(
                sprintf("'%s' === \$integration && '%s' === \$op", $dispatcher->integration(), $op),
                $out['error']['message'],
                "{$label}: the refusal gives the exact per-op filter call"
            );
            if ('' !== $filter) {
                $this->assertSame($filter, $out['error']['data']['filter'], $label);
                $this->assertStringContainsString("add_filter('{$filter}', '__return_true');", $out['error']['message'], $label);
            }
            ++$checked;
        }
        $this->assertGreaterThanOrEqual(10, $checked, 'the walk reached the default-off ops');

        // The named switch really opens each op it is named for.
        foreach (array_keys($filters) as $filter) {
            add_filter($filter, '__return_true');
        }
        try {
            foreach ($this->integration_ops() as $entry) {
                if ('' !== (string) ($entry['def']['opt_in_filter'] ?? '')) {
                    $this->assertTrue(
                        (bool) ($entry['def']['enabled_by_default'] ?? true),
                        $entry['dispatcher']->integration() . ' ' . $entry['op'] . ' opens with ' . $entry['def']['opt_in_filter']
                    );
                }
            }
        } finally {
            foreach (array_keys($filters) as $filter) {
                remove_filter($filter, '__return_true');
            }
        }
    }

    public function test_a_default_on_integration_op_switched_off_answers_operation_disabled(): void
    {
        foreach ($this->integration_ops() as $entry) {
            if (true === ($entry['def']['enabled_by_default'] ?? true)) {
                $out = $this->refusal($entry['dispatcher'], $entry['op'], $entry['def']);
                $this->assertSame('operation_disabled', $out['error']['code']);
                $this->assertSame('wpmcp_integration_op_enabled', $out['error']['data']['filter']);
                return;
            }
        }
        $this->fail('no default-on op found');
    }

    public function test_every_destructive_woo_op_refuses_with_opt_in_required_and_a_write_op_switched_off_does_not(): void
    {
        $destructive = 0;
        foreach (Op_Catalog::ops() as $op => $def) {
            if ('read' === $def['mode']) {
                continue;
            }
            $out = $this->woo_refusal($op, $def);
            if ('destructive' === $def['mode']) {
                $this->assertSame(Opt_In_Required::CODE, $out['error']['code'], $op);
                $this->assertSame(Woo_Write::ENABLE_FILTER, $out['error']['data']['filter'], $op);
                $this->assertStringContainsString("'{$op}' === \$op", $out['error']['message'], $op);
                $this->assertFalse(Woo_Write::is_op_enabled($op, $def), "{$op} ships off");
                ++$destructive;
            } else {
                $this->assertSame('operation_disabled', $out['error']['code'], $op);
                $this->assertTrue(Woo_Write::is_op_enabled($op, $def), "{$op} ships on");
            }
        }
        $this->assertGreaterThan(0, $destructive);
    }

    /**
     * Every concrete dispatcher under src/Integrations with its ops.
     *
     * @return list<array{dispatcher: Integration_Dispatcher, op: string, def: array<string, mixed>}>
     */
    private function integration_ops(): array
    {
        $out = [];
        foreach (glob(dirname(__DIR__, 3) . '/src/Integrations/*.php') as $file) {
            $class = 'WPMCP\\Integrations\\' . basename($file, '.php');
            if (! class_exists($class) || ! is_subclass_of($class, Integration_Dispatcher::class)) {
                continue;
            }
            $reflection = new \ReflectionClass($class);
            $ctor       = $reflection->getConstructor();
            if ($reflection->isAbstract() || (null !== $ctor && $ctor->getNumberOfRequiredParameters() > 0)) {
                continue;
            }
            $dispatcher = new $class();
            $operations = new \ReflectionMethod($dispatcher, 'operations');
            $operations->setAccessible(true);
            foreach ((array) $operations->invoke($dispatcher) as $op => $def) {
                if ('read' !== ($def['mode'] ?? 'read')) {
                    $out[] = [ 'dispatcher' => $dispatcher, 'op' => (string) $op, 'def' => (array) $def ];
                }
            }
        }
        return $out;
    }

    /** @return array<string, mixed> */
    private function refusal(Integration_Dispatcher $dispatcher, string $op, array $def): array
    {
        $method = new \ReflectionMethod(Integration_Dispatcher::class, 'disabled_refusal');
        $method->setAccessible(true);
        return (array) $method->invoke($dispatcher, $op, $def);
    }

    /** @return array<string, mixed> */
    private function woo_refusal(string $op, array $def): array
    {
        $method = new \ReflectionMethod(Woo_Write::class, 'disabled_refusal');
        $method->setAccessible(true);
        return (array) $method->invoke(null, $op, $def);
    }
}
