<?php

namespace WPMCP\Tools\Export\Static_Site;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Thrown inside a static export step when its job is no longer running
 * (canceled from another request): the step stops and removes its work.
 */
class Static_Export_Stopped extends \RuntimeException
{
}
