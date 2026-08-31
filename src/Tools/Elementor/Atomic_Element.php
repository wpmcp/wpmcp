<?php

namespace WPMCP\Tools\Elementor;

use WPMCP\Safety\Mutation_Failed;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Safe_Mutation;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Builders and a raw snapshot-writer for Elementor 4.0+ atomic elements.
 *
 * Atomic elements are written directly to `_elementor_data` (not through
 * Document::save, which drops unregistered atomic elements when the Editor-V4
 * experiment is off), so their typed `$$type` props round-trip exactly. The
 * write is still snapshot-first through Safe_Mutation, so it is undoable via
 * rollback-operation like every other Elementor edit.
 */
class Atomic_Element
{
    /** Element types that are atomic containers in Elementor's v4 builder. */
    private const ATOMIC_CONTAINERS = ['e-flexbox', 'e-div-block'];

    /** Test-only override of the capability probe (see set_supported_for_tests). */
    private static ?bool $supported_for_tests = null;

    /**
     * Force the atomic capability on or off for a test, or null to go back to
     * probing the live builder. Matches Gate::set_pro_for_tests(): a seam, not
     * a site override, so there is exactly one authority on whether these
     * tools may run and it is the builder itself.
     */
    public static function set_supported_for_tests(?bool $supported): void
    {
        self::$supported_for_tests = $supported;
        self::$registration_outcome = null;
    }

    /** What registration actually decided, or null before registration ran. */
    private static ?bool $registration_outcome = null;

    /**
     * Record whether the atomic write tools survived a registration pass.
     * Called by Plugin::register_elementor_abilities() with the answer read
     * back off the Registrar, so it accounts for everything that can drop an
     * ability (the pro gate, governance) and not only the builder predicate.
     */
    public static function note_registration(bool $registered): void
    {
        self::$registration_outcome = $registered;
    }

    /**
     * Whether the atomic write tools are on the live tool list. Falls back to
     * the registration predicate only when registration has not run in this
     * request, so the discoverability path cannot claim a tool exists that the
     * registrar dropped.
     */
    public static function registration_outcome(): bool
    {
        return self::$registration_outcome ?? self::registration_supported();
    }

    /**
     * Whether this Elementor install can render atomic (v4) elements.
     *
     * Elementor 4.0+ ships the atomic-widgets module unconditionally. Some
     * 3.3x builds ship it behind the Editor-V4 experiment, and when a site has
     * that experiment on the module is loaded and these tools work, so support
     * is not decided by version number alone.
     */
    public static function is_supported(): bool
    {
        if (defined('WPMCP_TESTING') && WPMCP_TESTING && null !== self::$supported_for_tests) {
            return self::$supported_for_tests;
        }

        if (! defined('ELEMENTOR_VERSION') || ! class_exists('\\Elementor\\Modules\\AtomicWidgets\\Module')) {
            return false;
        }

        if (version_compare(ELEMENTOR_VERSION, '4.0.0', '>=')) {
            return true;
        }

        return self::atomic_module_loaded();
    }

    /** Whether Elementor actually loaded the atomic-widgets module this request. */
    private static function atomic_module_loaded(): bool
    {
        if (! class_exists('\\Elementor\\Plugin')) {
            return false;
        }

        try {
            $modules = \Elementor\Plugin::instance()->modules_manager ?? null;

            return null !== $modules && null !== $modules->get_modules('atomic-widgets');
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Whether the atomic write tools should register at all (issue #62).
     * Registration and the per-call guard read the same predicate, so a
     * registered tool can never be one that refuses every call.
     */
    public static function registration_supported(): bool
    {
        return self::is_supported();
    }

    /**
     * Null when this install can render atomic elements, a WP_Error when it
     * cannot. Re-checked inside every atomic write, not only at registration,
     * because a site can activate or downgrade Elementor between the init hook
     * that registered the tool and the call that runs it.
     *
     * @return \WP_Error|null
     */
    public static function require_supported()
    {
        if (self::is_supported()) {
            return null;
        }

        return new \WP_Error(
            'atomic_unsupported',
            sprintf(
                'Atomic (v4) elements need Elementor 4.0+ with the atomic-widgets module; this site runs %s. Use the classic container/widget tools instead.',
                defined('ELEMENTOR_VERSION') ? 'Elementor ' . ELEMENTOR_VERSION : 'no Elementor'
            )
        );
    }

    /**
     * Whether a stored node is an atomic (v4) element: an atomic container, or
     * a widget whose widgetType is one of Elementor's `e-` atomic types. The
     * atomic tools refuse anything else rather than decorating a classic
     * element with typed props and a `styles` blob its renderer ignores.
     */
    public static function is_atomic_node(array $node): bool
    {
        $el_type = (string) ($node['elType'] ?? '');

        if (in_array($el_type, self::ATOMIC_CONTAINERS, true) || 0 === strpos($el_type, 'e-')) {
            return true;
        }

        return 'widget' === $el_type && 0 === strpos((string) ($node['widgetType'] ?? ''), 'e-');
    }

    /**
     * The kind of element a node is, for an error message that names what the
     * caller actually pointed at.
     */
    public static function describe_node(array $node): string
    {
        $el_type = (string) ($node['elType'] ?? 'unknown');

        return 'widget' === $el_type
            ? sprintf('widget "%s"', (string) ($node['widgetType'] ?? 'unknown'))
            : sprintf('"%s"', $el_type);
    }

    public static function container(string $el_type, array $settings): array
    {
        if (! isset($settings['classes'])) {
            $settings['classes'] = Atomic_Props::classes();
        }
        if (! isset($settings['tag'])) {
            $settings['tag'] = Atomic_Props::string('div');
        }

        return [
            'id'              => Element_Id::generate(),
            'elType'          => $el_type,
            'settings'        => $settings,
            'elements'        => [],
            'isInner'         => false,
            'styles'          => [],
            'editor_settings' => [],
            'version'         => defined('ELEMENTOR_VERSION') ? ELEMENTOR_VERSION : '',
        ];
    }

    public static function widget(string $widget_type, array $settings): array
    {
        if (! isset($settings['classes'])) {
            $settings['classes'] = Atomic_Props::classes();
        }

        return [
            'id'              => Element_Id::generate(),
            'elType'          => 'widget',
            'widgetType'      => $widget_type,
            'settings'        => $settings,
            'elements'        => [],
            'isInner'         => false,
            'styles'          => [],
            'editor_settings' => [],
            'version'         => defined('ELEMENTOR_VERSION') ? ELEMENTOR_VERSION : '',
        ];
    }

    /**
     * The `coerced` / `warnings` tail every atomic tool appends to its result
     * when the shared prop mapper had to repair or refuse something. Empty
     * lists are omitted so a clean write keeps its existing response shape.
     *
     * @param array{coerced: array, warnings: array} $mapped Result of Atomic_Props::map().
     */
    public static function report(array $mapped): array
    {
        $out = [];

        if ([] !== ($mapped['coerced'] ?? [])) {
            $out['coerced'] = array_values($mapped['coerced']);
        }
        if ([] !== ($mapped['warnings'] ?? [])) {
            $out['warnings'] = array_values($mapped['warnings']);
        }

        return $out;
    }

    /**
     * Raw snapshot-first write of an element tree to `_elementor_data`,
     * verifying the normalized stored tree matches what was intended.
     *
     * @return array|\WP_Error operation result or a mutation_failed error.
     */
    public static function write(int $post_id, array $elements, string $tool_name, array $args)
    {
        $operation_id = wp_generate_uuid4();
        // Compare what the JSON meta can actually represent: wp_json_encode
        // writes a float 32.0 as `32`, which decodes back as int, so an
        // intended tree built in PHP is never identical to the stored one
        // until both sides have been through the same encoder.
        $intended = Element_Tree::normalize(self::as_stored($elements), true);

        try {
            Safe_Mutation::run(
                [
                    'operation_id' => $operation_id,
                    'object_type'  => 'post',
                    'object_id'    => $post_id,
                    'session_id'   => (string) ($args['session_id'] ?? 'default'),
                    'tool_name'    => $tool_name,
                    'args'         => $args,
                ],
                function () use ($post_id, $elements) {
                    Elementor_Page_Data::save($post_id, $elements);
                    return true;
                },
                function () use ($post_id, $intended) {
                    clean_post_cache($post_id);
                    return Element_Tree::normalize(Elementor_Page_Data::get($post_id), true) === $intended;
                }
            );
        } catch (Mutation_Failed $e) {
            return new \WP_Error('mutation_failed', 'The atomic write did not store the intended tree; the page was rolled back.');
        } catch (\Throwable $e) {
            Rollback_Service::restore_operation($operation_id);
            return new \WP_Error('mutation_failed', 'The write failed mid-save and the page was rolled back: ' . $e->getMessage());
        }

        return [
            'operation_id' => $operation_id,
            'post_id'      => $post_id,
            'data_hash'    => Element_Tree::data_hash($post_id),
        ];
    }

    /** The tree as `_elementor_data` will hold it, after a JSON round trip. */
    private static function as_stored(array $elements): array
    {
        $decoded = json_decode((string) wp_json_encode($elements), true);

        return is_array($decoded) ? $decoded : $elements;
    }
}
