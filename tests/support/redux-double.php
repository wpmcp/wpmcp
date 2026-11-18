<?php

/**
 * Stand-ins for a Redux Framework panel (issue #897), recorded from Redux
 * Framework 4.5.18 (redux-core/framework.php and
 * inc/classes/class-redux-options-constructor.php): a ReduxFramework
 * instance carries its args (opt_name, database, page_permissions), its
 * sections with their fields, the loaded options, the defaults and an
 * options_class whose validate_options() runs the per-field 'validate'
 * rules and whose set() stores the array where args['database'] says.
 *
 * The doubles are plain objects in this namespace, handed to the pack
 * through the wpmcp_redux_instances filter, so no global Redux class is
 * defined and no other test sees Redux as active.
 */

namespace WPMCP\Tests\Support;

final class Redux_Panel_Double
{
    /** @var array<string, mixed> */
    public $args = [];

    /** @var array<int|string, array<string, mixed>> */
    public $sections = [];

    /** @var array<string, mixed> */
    public $options = [];

    /** @var array<string, mixed>|null */
    public $options_defaults = null;

    /** @var array<string, mixed> */
    public $transients = [];

    /** @var array<string, mixed> */
    public $errors = [];

    /** @var Redux_Options_Double */
    public $options_class;

    /** @var array<int, array<string, mixed>> compiler actions seen, for assertions. */
    public $compiled = [];

    /**
     * @param array<string, mixed>              $args
     * @param array<int, array<string, mixed>>  $sections
     */
    public function __construct(array $args, array $sections)
    {
        $this->args          = $args + [ 'database' => '', 'page_permissions' => 'manage_options', 'display_name' => '', 'page_slug' => '' ];
        $this->sections      = $sections;
        $this->options_class = new Redux_Options_Double($this);
        $this->options       = $this->options_class->stored();
    }
}

final class Redux_Options_Double
{
    /** @var Redux_Panel_Double */
    private $core;

    /** @var int how often set() ran */
    public $sets = 0;

    public function __construct(Redux_Panel_Double $core)
    {
        $this->core = $core;
    }

    /** @return array<string, mixed> */
    public function default_values(): array
    {
        if (null === $this->core->options_defaults) {
            $defaults = [];
            foreach ($this->core->sections as $section) {
                foreach ((array) ($section['fields'] ?? []) as $field) {
                    if (isset($field['id'], $field['default'])) {
                        $defaults[ $field['id'] ] = $field['default'];
                    }
                }
            }
            $this->core->options_defaults = $defaults;
        }
        return $this->core->options_defaults;
    }

    /** @return array<string, mixed> what get() would load, by database. */
    public function stored(): array
    {
        $opt = (string) $this->core->args['opt_name'];
        switch ($this->core->args['database']) {
            case 'theme_mods':
                $value = get_theme_mod($opt . '-mods');
                break;
            case 'transient':
                $value = get_transient($opt . '-transient');
                break;
            case 'network':
                $value = get_site_option($opt, []);
                break;
            default:
                $value = get_option($opt, []);
        }
        return is_array($value) ? $value : [];
    }

    /**
     * Redux's validate_options(): field 'validate' rules put back the old
     * value and record an error; a 'compiler' key marks run_compiler; the
     * theme_mods databases save here and return null.
     *
     * @param array<string, mixed> $plugin_options
     * @return array<string, mixed>|null
     */
    public function validate_options(array $plugin_options)
    {
        foreach ($this->core->sections as $section) {
            foreach ((array) ($section['fields'] ?? []) as $field) {
                $id = (string) ($field['id'] ?? '');
                if ('' === $id || ! array_key_exists($id, $plugin_options)) {
                    continue;
                }
                if ('email' === ($field['validate'] ?? '') && ! is_email((string) $plugin_options[ $id ])) {
                    $plugin_options[ $id ]     = $this->core->options[ $id ] ?? '';
                    $this->core->errors[]      = [ 'id' => $id, 'msg' => 'You must provide a valid email for this option.' ];
                }
            }
        }
        if (! empty($plugin_options['compiler'])) {
            unset($plugin_options['compiler']);
            $this->core->transients['run_compiler'] = 1;
        }
        $changed = [];
        foreach ($this->core->options as $key => $value) {
            if (isset($plugin_options[ $key ]) && $plugin_options[ $key ] !== $value) {
                $changed[ $key ] = $value;
            }
        }
        $this->core->transients['changed_values'] = $changed;
        if (in_array($this->core->args['database'], [ 'transient', 'theme_mods', 'theme_mods_expanded' ], true)) {
            $this->set($plugin_options);
            return null;
        }
        return $plugin_options;
    }

    /** @param array<string, mixed> $value */
    public function set($value = ''): void
    {
        if (empty($value)) {
            return;
        }
        ++$this->sets;
        $opt = (string) $this->core->args['opt_name'];
        switch ($this->core->args['database']) {
            case 'theme_mods':
                set_theme_mod($opt . '-mods', $value);
                break;
            case 'transient':
                set_transient($opt . '-transient', $value);
                break;
            case 'network':
                update_site_option($opt, $value);
                break;
            default:
                update_option($opt, $value);
        }
        $this->core->options = $value;
    }
}
