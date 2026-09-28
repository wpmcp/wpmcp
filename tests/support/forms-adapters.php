<?php
/**
 * The forms adapters (issue #66), in one place for the tests that need to
 * know which dispatcher pairs follow their host plugin's presence: the
 * conformance suites, the registration test and the live-registry smoke
 * test. FormsAdapterConformanceTest asserts this list is exactly the set of
 * concrete Forms_Integration subclasses in src/Integrations, so a new adapter
 * cannot be left out of it.
 */

if (! function_exists('wpmcp_forms_adapter_classes')) {
    /** @return array<string, class-string<\WPMCP\Integrations\Forms_Integration>> slug => class */
    function wpmcp_forms_adapter_classes(): array
    {
        return [
            // Free tier.
            'contactform7' => \WPMCP\Integrations\Contact_Form_7_Integration::class,
            'forminator'   => \WPMCP\Integrations\Forminator_Integration::class,
            'metform'      => \WPMCP\Integrations\MetForm_Integration::class,
            'sureforms'    => \WPMCP\Integrations\SureForms_Integration::class,
            // The forms adapter pack.
            'wpforms'      => \WPMCP\Integrations\WPForms_Integration::class,
            'gravityforms' => \WPMCP\Integrations\Gravity_Forms_Integration::class,
            'formidable'   => \WPMCP\Integrations\Formidable_Integration::class,
            'ninjaforms'   => \WPMCP\Integrations\Ninja_Forms_Integration::class,
            'fluentforms'  => \WPMCP\Integrations\Fluent_Forms_Integration::class,
        ];
    }
}

if (! function_exists('wpmcp_forms_pair_names')) {
    /** @return string[] every wpmcp/{slug}-read and -write name of a forms adapter. */
    function wpmcp_forms_pair_names(): array
    {
        $names = [];
        foreach (array_keys(wpmcp_forms_adapter_classes()) as $slug) {
            $names[] = "wpmcp/{$slug}-read";
            $names[] = "wpmcp/{$slug}-write";
        }
        return $names;
    }
}
