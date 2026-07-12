<?php

namespace WPMCP\Tools\Security;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * scan-security ability handler (read-only).
 *
 * Delegates to Security_Scanner and returns its scored report. The scanner is
 * injectable so tests can substitute a double and touch no filesystem or HTTP.
 */
class Scan_Security
{
    private Security_Scanner $scanner;

    public function __construct(?Security_Scanner $scanner = null)
    {
        $this->scanner = $scanner ?: new Security_Scanner();
    }

    public function handle(array $args): array
    {
        return $this->scanner->scan($args);
    }
}
