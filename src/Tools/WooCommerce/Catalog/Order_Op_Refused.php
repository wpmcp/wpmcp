<?php

namespace WPMCP\Tools\WooCommerce\Catalog;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * A validation refusal inside Order_Ops, Shipping_Ops or Webhook_Ops,
 * carried to that class's prepare() boundary and turned into the
 * dispatcher's structured error there. Never escapes those classes.
 */
final class Order_Op_Refused extends \RuntimeException
{
    /** @var string */
    public $code_name;

    /** @var array<string, mixed> */
    public $data;

    /** @param array<string, mixed> $data */
    public function __construct(string $code_name, string $message, array $data = [])
    {
        parent::__construct($message);
        $this->code_name = $code_name;
        $this->data      = $data;
    }
}
