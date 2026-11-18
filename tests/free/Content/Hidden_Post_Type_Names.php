<?php

namespace WPMCP\Tests\Free\Content;

/**
 * The post type names and capabilities of Hidden_Post_Types. A class using
 * that trait implements this interface, because a trait may not declare
 * constants before PHP 8.2 and the plugin supports PHP 8.1 (issue #960).
 */
interface Hidden_Post_Type_Names
{
    const ENROLLMENT = 'acme_enrolled';
    const ORDER      = 'acme_order';
    const ENTRY      = 'acme_form_entry';

    /** Primitive capabilities the order type declares, granted to administrators the way shop plugins grant theirs. */
    const ORDER_CAPS = [
        'edit_acme_orders',
        'edit_others_acme_orders',
        'publish_acme_orders',
        'read_private_acme_orders',
        'delete_acme_orders',
        'delete_others_acme_orders',
        'edit_published_acme_orders',
        'edit_private_acme_orders',
        'delete_private_acme_orders',
        'delete_published_acme_orders',
    ];
}
