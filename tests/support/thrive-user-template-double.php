<?php

/**
 * A stand-in for Thrive Architect's user template model (issue #640) for
 * the suite without the commercial plugin, recorded from Thrive Architect
 * 11.3 (inc/classes/user-templates, Has_Post_Type::insert_post()): insert()
 * creates a published tve_user_template post whose fields are
 * template_<field> meta rows. The live Thrive leg loads the real class
 * first, so this is skipped there.
 */

namespace TCB\UserTemplates;

if (! class_exists(Template::class)) {
    class Template // phpcs:ignore
    {
        /** @return int|\WP_Error */
        public static function insert($data)
        {
            $meta = [];
            foreach (['name', 'content', 'type', 'css', 'media_css', 'thumb'] as $key) {
                $meta[ 'template_' . $key ] = $data[ $key ];
            }

            return wp_insert_post([
                'post_title'  => 'User template',
                'post_type'   => 'tve_user_template',
                'post_status' => 'publish',
                'meta_input'  => $meta,
            ]);
        }
    }
}
