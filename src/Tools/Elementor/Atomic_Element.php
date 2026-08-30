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
    /** Whether this Elementor install ships the atomic-widgets module (v4.0+). */
    public static function is_supported(): bool
    {
        return defined('ELEMENTOR_VERSION')
            && version_compare(ELEMENTOR_VERSION, '4.0.0', '>=')
            && class_exists('\\Elementor\\Modules\\AtomicWidgets\\Module');
    }

    /**
     * Whether the atomic write tools should register at all (issue #62): the
     * builder version gate, overridable by tests or site code via filter.
     */
    public static function registration_supported(): bool
    {
        return (bool) apply_filters('wpmcp_elementor_atomic_supported', self::is_supported());
    }

    /**
     * Null when this install can render atomic elements, a WP_Error when it
     * cannot. Registration is gated on the filterable registration_supported(),
     * whose documented purpose is a test/site override, so every atomic write
     * re-checks the real capability: a forced-open gate on a legacy builder
     * must fail the call rather than write elements Elementor cannot render.
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
        $intended     = Element_Tree::normalize($elements);

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
                    return Element_Tree::normalize(Elementor_Page_Data::get($post_id)) === $intended;
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
}
