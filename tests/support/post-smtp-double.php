<?php
/**
 * A stand-in for Post SMTP for the Post SMTP ops on the theme pair
 * (Post_SMTP_Status), for tests that run without the plugin loaded.
 *
 * Kept the way Post SMTP 4.0 keeps its settings (read from the wordpress.org
 * release): one `postman_options` option read through the PostmanOptions
 * singleton, which loads it once and keeps it until reload(). The API keys and
 * the basic auth password are stored base64 encoded. The log is the
 * `post_smtp_logs` table, created by the test (columns as in
 * Postman/PostmanEmailLogs.php).
 *
 * Guarded, so the real plugin wins wherever it is loaded. POST_SMTP_VER is
 * never defined here, so the ops count Post SMTP as active only where a test
 * turns wpmcp_post_smtp_active on.
 */

// phpcs:disable

namespace {
    if (! class_exists('PostmanOptions', false)) {
        class PostmanOptions
        {
            const POSTMAN_OPTIONS = 'postman_options';

            private static $instance = null;
            private $options;

            public static $reloads = 0;

            public static function getInstance()
            {
                return self::$instance ?? (self::$instance = new self());
            }

            public static function forget(): void
            {
                self::$instance = null;
                self::$reloads  = 0;
            }

            private function __construct()
            {
                $this->options = get_option(self::POSTMAN_OPTIONS);
            }

            public function reload()
            {
                self::$reloads++;
                $this->options = get_option(self::POSTMAN_OPTIONS);
            }

            public function getMessageSenderEmail()
            {
                return $this->options['sender_email'] ?? null;
            }

            public function getMessageSenderName()
            {
                return $this->options['sender_name'] ?? null;
            }
        }
    }
}
