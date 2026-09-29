<?php

namespace WPMCP\Tools\Builders;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * get-builder-content's site-wide scopes (issue #391): with `scope` set, the
 * tool reads a builder's design system (global classes, variables and color
 * palettes), its local templates (header and footer areas included) or its
 * element catalog with control schemas, instead of one post's layout.
 *
 * Bricks, Breakdance and Oxygen 6 are supported; the builder comes from the
 * `builder` argument, or is detected from `post_id` when that is given
 * instead. Reads only: writes to global design data are a later phase.
 */
class Builder_Design
{
    public const SCOPES = ['design_system', 'templates', 'catalog'];

    public const BUILDERS = ['bricks', 'breakdance', 'oxygen'];

    /**
     * @param array<string,mixed> $args
     * @return array<string,mixed>|\WP_Error
     */
    public static function read(array $args)
    {
        $scope = (string) $args['scope'];
        if (! in_array($scope, self::SCOPES, true)) {
            return new \WP_Error('invalid_scope', "scope must be 'design_system', 'templates' or 'catalog'; got '{$scope}'.");
        }

        $builder = (string) ($args['builder'] ?? '');
        $post_id = (int) ($args['post_id'] ?? 0);

        if ('' === $builder && $post_id > 0) {
            if (! get_post($post_id)) {
                return new \WP_Error('post_not_found', "No post found with id '{$post_id}'.");
            }
            $builder = Builder_Detector::detect($post_id);
        }

        if ('' === $builder) {
            return new \WP_Error('missing_builder', 'A scope read needs builder (bricks, breakdance or oxygen) or a post_id to detect it from.');
        }

        if (! in_array($builder, self::BUILDERS, true)) {
            return new \WP_Error('unsupported_builder', "scope reads support 'bricks', 'breakdance' and 'oxygen'; got '{$builder}'.");
        }

        $element = (string) ($args['element'] ?? '');

        if ('bricks' === $builder) {
            if ('design_system' === $scope) {
                return Bricks_Design::design_system();
            }

            return 'templates' === $scope ? Bricks_Design::templates() : Bricks_Design::catalog($element);
        }

        if ('design_system' === $scope) {
            return Breakdance_Design::design_system($builder);
        }

        return 'templates' === $scope ? Breakdance_Design::templates($builder) : Breakdance_Design::catalog($builder, $element);
    }
}
