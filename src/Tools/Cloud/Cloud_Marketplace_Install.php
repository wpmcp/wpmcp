<?php

namespace WPMCP\Tools\Cloud;

use WPMCP\Cloud\Cloud_Client;
use WPMCP\Tools\BlockBuilder\Block_Spec;
use WPMCP\Tools\BlockBuilder\Block_Spec_Store;
use WPMCP\Tools\WidgetBuilder\Widget_Spec;
use WPMCP\Tools\WidgetBuilder\Widget_Spec_Store;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Install a WP MCP Cloud marketplace listing (issue #135).
 *
 * Contract: GET /marketplace/{slug} returns
 * { listing: { slug, type: widget|block, title, version?, spec } }.
 *
 * The listing is third-party content, so it is held to the strictest gate the
 * plugin has for specs:
 *  - The spec must pass Widget_Spec::validate() / Block_Spec::validate(), the
 *    exact checks validate-widget-spec and validate-block-spec run and the
 *    gate cloud-pull-assets already enforces.
 *  - The template goes through wp_kses_post even when the installing user
 *    holds unfiltered_html. That capability says the user may author raw
 *    markup, not that markup somebody else published is safe.
 *  - It lands as an INACTIVE draft. Nothing registers in the editor or
 *    renders on the front end until someone reviews it and activates it with
 *    set-widget-status / set-block-status.
 *  - A listing whose machine name collides with an existing local spec is
 *    refused rather than installed alongside it.
 *
 * Not routed through Safe_Mutation, for the same reason Create_Post,
 * create-custom-widget and cloud-pull-assets are not: this only ever inserts
 * a new draft post, so nothing existing can be overwritten or lost, and the
 * draft is removed with delete-custom-widget / delete-custom-block.
 */
class Cloud_Marketplace_Install
{
    /** Lowercase slug, no path or query characters: it is interpolated into the request path. */
    private const SLUG_PATTERN = '/^[a-z0-9][a-z0-9_-]{0,99}$/';

    public function handle(array $args)
    {
        if (! current_user_can('manage_options')) {
            return new \WP_Error('marketplace_forbidden', 'Installing a marketplace listing requires the manage_options capability.');
        }

        $slug = (string) ($args['slug'] ?? '');
        if (1 !== preg_match(self::SLUG_PATTERN, $slug)) {
            return new \WP_Error('invalid_slug', 'slug must be a marketplace listing slug (lowercase letters, digits, hyphens, underscores); find one with cloud-marketplace-browse.');
        }

        $result = (new Cloud_Client())->get('/marketplace/' . $slug);
        if (is_wp_error($result)) {
            return $result;
        }

        $listing = $result['listing'] ?? null;
        if (! is_array($listing) || ($listing['slug'] ?? null) !== $slug || ! is_array($listing['spec'] ?? null)) {
            return new \WP_Error('marketplace_invalid_listing', 'WP MCP Cloud did not return a usable listing for that slug.');
        }
        $type = $listing['type'] ?? null;
        if (! in_array($type, Cloud_Marketplace_Browse::TYPES, true)) {
            return new \WP_Error('marketplace_invalid_listing', 'The listing is not a widget or block spec, so it cannot be installed.');
        }

        $spec  = $listing['spec'];
        $valid = 'widget' === $type ? Widget_Spec::validate($spec) : Block_Spec::validate($spec);
        if (is_wp_error($valid)) {
            return new \WP_Error('marketplace_invalid_spec', 'The listing failed spec validation and was not installed: ' . $valid->get_error_message());
        }

        $template         = (string) $spec['template'];
        $spec['template'] = wp_kses_post($template);
        $filtered         = $spec['template'] !== $template;
        // kses can empty a template that was nothing but disallowed markup.
        $valid = 'widget' === $type ? Widget_Spec::validate($spec) : Block_Spec::validate($spec);
        if (is_wp_error($valid)) {
            return new \WP_Error('marketplace_invalid_spec', 'The listing template is empty once unsafe markup is removed, so it was not installed.');
        }

        $name  = 'widget' === $type ? Widget_Spec::normalize($spec)['name'] : Block_Spec::normalize($spec)['name'];
        $taken = array_column('widget' === $type ? Widget_Spec_Store::all() : Block_Spec_Store::all(), 'name');
        if (in_array($name, $taken, true)) {
            return new \WP_Error('marketplace_name_taken', sprintf('A custom %1$s named "%2$s" already exists on this site; delete or rename it before installing this listing.', $type, $name));
        }

        $id = 'widget' === $type ? Widget_Spec_Store::create($spec, 'draft') : Block_Spec_Store::create($spec, 'draft');
        if (is_wp_error($id)) {
            return $id;
        }

        update_post_meta($id, '_wpmcp_marketplace_source', [
            'slug'         => $slug,
            'version'      => is_scalar($listing['version'] ?? null) ? sanitize_text_field((string) $listing['version']) : '',
            'installed_at' => time(),
        ]);

        return [
            'installed'         => true,
            'type'              => $type,
            'id'                => (int) $id,
            'name'              => $name,
            'status'            => 'draft',
            'template_filtered' => $filtered,
            'next'              => 'widget' === $type
                ? 'Review the spec with get-custom-widget, then activate it with set-widget-status status=publish.'
                : 'Review the spec with get-custom-block, then activate it with set-block-status status=publish.',
        ];
    }
}
