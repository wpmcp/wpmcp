<?php
/**
 * Test doubles for the WP Mail SMTP surface the integration touches. The
 * free suite runs without the plugin, so these stand in for wp_mail_smtp(),
 * WPMailSMTP\Options and the Pro email log collection. Every definition is
 * guarded so a live install with the real plugin wins.
 */

namespace WPMailSMTP {
    if (! class_exists(Options::class, false)) {
        class Options
        {
            /** @var array<string, array<string, mixed>> */
            public static array $data = [];

            public static function init(): self
            {
                return new self();
            }

            public function get(string $group, string $key)
            {
                return self::$data[ $group ][ $key ] ?? '';
            }

            public function get_group(string $group): array
            {
                return self::$data[ $group ] ?? [];
            }

            /** @param array<string, array<string, mixed>> $options */
            public function set(array $options, bool $once = false, bool $overwrite_existing = true): void
            {
                foreach ($options as $group => $keys) {
                    foreach ((array) $keys as $key => $value) {
                        self::$data[ $group ][ $key ] = $value;
                    }
                }
                update_option('wp_mail_smtp', self::$data);
            }
        }
    }
}

namespace WPMailSMTP\Pro\Emails\Logs {
    if (! class_exists(Logs::class, false)) {
        class Logs
        {
            public static bool $enabled = false;

            public static function is_enabled(): bool
            {
                return self::$enabled;
            }
        }
    }
}

namespace WPMailSMTP\Pro\Emails\Logs\Providers {
}

namespace WPMailSMTP\Pro\Emails\Logs {
    if (! class_exists(Email::class, false)) {
        class Email
        {
            public function __construct(private array $row)
            {
            }

            public function get_id(): int
            {
                return (int) $this->row['id'];
            }

            public function get_subject(): string
            {
                return (string) $this->row['subject'];
            }

            public function get_people(string $type = 'to'): array
            {
                return (array) ($this->row[ $type ] ?? []);
            }

            public function get_status(): int
            {
                return (int) $this->row['status'];
            }

            public function get_error_text(): string
            {
                return (string) ($this->row['error_text'] ?? '');
            }

            public function get_date_sent(): \DateTime
            {
                return new \DateTime($this->row['date_sent']);
            }

            public function get_mailer(): string
            {
                return (string) ($this->row['mailer'] ?? '');
            }
        }
    }

    if (! class_exists(EmailsCollection::class, false)) {
        class EmailsCollection
        {
            /** @var array<int, array<string, mixed>> */
            public static array $rows = [];

            public function __construct(private array $params = [])
            {
            }

            /** @return array<int, Email> */
            public function get(): array
            {
                $rows = self::$rows;
                usort($rows, static fn (array $a, array $b): int => strcmp($b['date_sent'], $a['date_sent']));
                $rows = array_slice($rows, 0, (int) ($this->params['number'] ?? 10));
                return array_map(static fn (array $row): Email => new Email($row), $rows);
            }
        }
    }
}

namespace {
    if (! function_exists('wp_mail_smtp')) {
        function wp_mail_smtp(): object
        {
            return new class {
                public function get_providers(): object
                {
                    return new class {
                        public function get_options(string $mailer): object
                        {
                            return new class {
                                public function get_title(): string
                                {
                                    return 'Fake mailer';
                                }
                            };
                        }
                    };
                }
            };
        }
    }
}
