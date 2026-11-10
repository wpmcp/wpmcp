<?php
/**
 * Three entries of WPBakery Page Builder 9.1's element map, as
 * WPBMap::getAllShortCodes() returns them (recorded from the real plugin in
 * the live WPBakery leg, issue #639), trimmed to a few params each. The
 * stub-backed catalog tests hand this to the wpmcp_wpbakery_map filter in
 * place of the plugin.
 */

return [
    'vc_row' => [
        'name' => 'Row',
        'base' => 'vc_row',
        'category' => 'Content',
        'description' => 'Place content elements inside the row',
        'is_container' => true,
        'icon' => 'icon-wpb-row',
        'params' => [
            [
                'type' => 'dropdown',
                'heading' => 'Row stretch',
                'param_name' => 'full_width',
                'value' => [
                    'None' => '',
                    'Stretch row' => 'stretch_row',
                    'Stretch row and content' => 'stretch_row_content',
                    'Stretch row and content (no paddings)' => 'stretch_row_content_no_spaces',
                ],
                'description' => 'Select stretching options for row and content (Note: stretched may not work properly if parent container has &quot;overflow: hidden&quot; CSS property).',
            ],
            [
                'type' => 'number',
                'heading' => 'Column gap',
                'param_name' => 'gap',
                'value' => '0',
                'description' => 'Select gap between columns in row.',
                'std' => '0',
            ],
            [
                'type' => 'el_id',
                'heading' => 'Element ID',
                'param_name' => 'el_id',
                'description' => 'Enter element ID (Note: make sure it is unique and valid according to <a href="https://www.w3schools.com/tags/att_global_id.asp" target="_blank">w3c specification</a>).',
            ],
            [
                'type' => 'css_editor',
                'param_name' => 'css',
                'group' => 'Design options',
            ],
        ],
    ],
    'vc_column_text' => [
        'name' => 'Text block',
        'base' => 'vc_column_text',
        'category' => 'Content',
        'description' => 'A block of text with WYSIWYG editor',
        'icon' => 'icon-wpb-layer-shape-text',
        'params' => [
            [
                'type' => 'textarea_html',
                'heading' => 'Text',
                'param_name' => 'content',
                'value' => '<p>I am text block. Click edit button to change this text. Lorem ipsum dolor sit amet, consectetur adipiscing elit. Ut elit tellus, luctus nec ullamcorper mattis, pulvinar dapibus leo.</p>',
            ],
            [
                'type' => 'textfield',
                'heading' => 'Extra class name',
                'param_name' => 'el_class',
                'description' => 'Style particular content element differently - add a class name and refer to it in custom CSS.',
            ],
            [
                'type' => 'css_editor',
                'param_name' => 'css',
                'group' => 'Design options',
                'value' => [
                    'margin-bottom' => '35px',
                ],
            ],
        ],
    ],
    'vc_btn' => [
        'name' => 'Old Button',
        'base' => 'vc_btn',
        'category' => [
            'Content',
        ],
        'description' => 'Eye catching button',
        'deprecated' => '9.1',
        'icon' => 'icon-wpb-ui-button',
        'params' => [
            [
                'type' => 'textfield',
                'heading' => 'Text',
                'param_name' => 'title',
                'value' => 'Text on the button',
            ],
            [
                'type' => 'link',
                'heading' => 'URL',
                'param_name' => 'link',
            ],
            [
                'type' => 'hidden',
                'param_name' => 'color',
                'value' => '',
            ],
        ],
    ],
];
