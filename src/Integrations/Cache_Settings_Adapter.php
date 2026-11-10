<?php

namespace WPMCP\Integrations;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * One cache or performance plugin's settings, for Cache_Settings_Pack (issue
 * #692). A concrete adapter names the option the plugin keeps its settings
 * in, the settings an agent may read and write (each with a rule: bool,
 * list, enum, int, url or text), the credential keys that are only ever
 * reported masked, and how a canonical value is stored (WP Rocket keeps a
 * switch as 1 or 0, Perfmatters as "1" or an absent key, FlyingPress as a
 * boolean).
 *
 * Keys are the plugin's own option keys; a key inside a nested section is
 * written with a dot (Perfmatters "lazyload.lazy_loading").
 */
abstract class Cache_Settings_Adapter
{
    /** Longest list a set op accepts, and longest single string. */
    public const MAX_ITEMS  = 200;
    public const MAX_LENGTH = 500;

    /** Op slug, e.g. "wp-rocket" (get-wp-rocket-settings). */
    abstract public function slug(): string;

    /** The plugin's name, e.g. "WP Rocket". */
    abstract public function label(): string;

    /** The option the plugin keeps its settings in. */
    abstract public function option(): string;

    /** The plugin's main file relative to the plugins directory. */
    abstract public function plugin_file(): string;

    /** Whether the plugin's code is loaded (its version constant), before the presence filter. */
    abstract protected function loaded(): bool;

    abstract public function version(): string;

    /**
     * The writable settings, key => rule. A rule is ['type' => 'bool'],
     * ['type' => 'list'], ['type' => 'enum', 'values' => [...]],
     * ['type' => 'int', 'min' => n, 'max' => n], ['type' => 'url'] or
     * ['type' => 'text'].
     *
     * @return array<string, array<string, mixed>>
     */
    abstract public function rules(): array;

    /**
     * Settings reported by the read but not writable, key => rule (the rule
     * says how to report the stored value).
     *
     * @return array<string, array<string, mixed>>
     */
    public function read_only(): array
    {
        return [];
    }

    /** @return string[] keys holding credentials: reported only as masked, never written. */
    abstract public function secret_keys(): array;

    /**
     * The common summary (lazy load, minify, delay JS, CDN, preload): name =>
     * the key whose switch answers it, or null when the plugin has no such
     * setting.
     *
     * @return array<string, string|null>
     */
    abstract public function summary_keys(): array;

    /** Stored form of a switch that is on or off; null removes the key. */
    abstract protected function store_bool(bool $on);

    /** Whether the plugin is active here, through its own presence filter. */
    public function active(): bool
    {
        return (bool) apply_filters('wpmcp_' . str_replace('-', '_', $this->slug()) . '_active', $this->loaded());
    }

    /** Whether the plugin's files are installed (for plugin_inactive against plugin_missing). */
    public function installed(): bool
    {
        return defined('WP_PLUGIN_DIR') && file_exists(WP_PLUGIN_DIR . '/' . $this->plugin_file());
    }

    /** @return array<string, mixed> the stored option, or an empty array. */
    public function stored(): array
    {
        $stored = get_option($this->option(), []);
        return is_array($stored) ? $stored : [];
    }

    /**
     * Every readable setting, canonical: bools as booleans, lists as lists
     * of strings, the rest as stored scalars.
     *
     * @return array<string, mixed>
     */
    public function current(): array
    {
        $stored = $this->stored();
        $out    = [];
        foreach ($this->rules() + $this->read_only() as $key => $rule) {
            $out[ $key ] = $this->canonical($rule, self::path_get($stored, $key));
        }
        return $out;
    }

    /**
     * Write $changes (key => canonical value, already validated) to the
     * stored option. The default merges them into the option and saves it
     * with update_option(), so the plugin's own update_option_{option}
     * listeners run as they do for a save from its settings screen.
     *
     * @param array<string, mixed> $changes
     */
    public function apply(array $changes): void
    {
        update_option($this->option(), $this->merged($changes));
    }

    /**
     * The stored option with $changes written in, in the plugin's own
     * stored form.
     *
     * @param array<string, mixed> $changes
     * @return array<string, mixed>
     */
    protected function merged(array $changes): array
    {
        $stored = $this->stored();
        $rules  = $this->rules();
        foreach ($changes as $key => $value) {
            $stored = self::path_set($stored, (string) $key, $this->to_stored($rules[ $key ], $value));
        }
        return $stored;
    }

    /**
     * Refusal for a set of changes that is valid key by key but not as a
     * whole (two settings the plugin treats as exclusive), or null.
     *
     * @param array<string, mixed> $after every setting as it would be after the write
     * @return array{code: string, message: string}|null
     */
    public function conflict(array $after): ?array
    {
        return null;
    }

    /**
     * Extra changes a write implies (a CDN host list and its zone list stay
     * the same length), given the requested changes.
     *
     * @param array<string, mixed> $changes
     * @return array<string, mixed>
     */
    public function implied(array $changes): array
    {
        return $changes;
    }

    /** Whether the plugin can warm its page cache here (a preload op is offered). */
    public function supports_preload(): bool
    {
        return false;
    }

    /**
     * Why a preload cannot start now, or null.
     *
     * @return array{code: string, message: string}|null
     */
    public function preload_refusal(): ?array
    {
        return null;
    }

    /** @return array<string, mixed> what the plugin's preload started. */
    public function preload(): array
    {
        return [];
    }

    /** After a rollback put this plugin's option back: refresh what the plugin derived from it. */
    public function after_restore(): void
    {
    }

    /**
     * A canonical value for a rule, from what is stored.
     *
     * @param array<string, mixed> $rule
     * @param mixed                $value
     * @return mixed
     */
    public function canonical(array $rule, $value)
    {
        switch ($rule['type']) {
            case 'bool':
                return ! empty($value);
            case 'list':
                if (is_string($value)) {
                    $value = preg_split('/\r\n|\r|\n/', $value);
                }
                if (! is_array($value)) {
                    return [];
                }
                $items = [];
                foreach ($value as $item) {
                    if (is_scalar($item) && '' !== trim((string) $item)) {
                        $items[] = trim((string) $item);
                    }
                }
                return $items;
            case 'int':
                return is_numeric($value) ? (int) $value : 0;
            case 'raw':
                return $value;
            default:
                return is_scalar($value) ? (string) $value : '';
        }
    }

    /**
     * The stored form of a canonical value.
     *
     * @param array<string, mixed> $rule
     * @param mixed                $value
     * @return mixed null removes the key
     */
    protected function to_stored(array $rule, $value)
    {
        switch ($rule['type']) {
            case 'bool':
                return $this->store_bool((bool) $value);
            case 'int':
                return (int) $value;
            case 'list':
                return array_values((array) $value);
            default:
                return (string) $value;
        }
    }

    /**
     * The value at a dotted key path, or null.
     *
     * @param array<string, mixed> $data
     * @return mixed
     */
    public static function path_get(array $data, string $key)
    {
        foreach (explode('.', $key) as $part) {
            if (! is_array($data) || ! array_key_exists($part, $data)) {
                return null;
            }
            $data = $data[ $part ];
        }
        return $data;
    }

    /**
     * $data with $value at a dotted key path; null removes the key.
     *
     * @param array<string, mixed> $data
     * @param mixed                $value
     * @return array<string, mixed>
     */
    public static function path_set(array $data, string $key, $value): array
    {
        $parts = explode('.', $key);
        $head  = array_shift($parts);
        if ([] === $parts) {
            if (null === $value) {
                unset($data[ $head ]);
            } else {
                $data[ $head ] = $value;
            }
            return $data;
        }
        $child         = isset($data[ $head ]) && is_array($data[ $head ]) ? $data[ $head ] : [];
        $data[ $head ] = self::path_set($child, implode('.', $parts), $value);
        return $data;
    }
}
