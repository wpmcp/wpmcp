<?php

namespace WPMCP\Tools\Portable;

if (! defined('ABSPATH')) {
    exit;
}

/** One bundle item a store refused; the import reports it and moves on. */
class Bundle_Item_Refused extends \RuntimeException
{
}
