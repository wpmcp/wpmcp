<?php
/**
 * A slice of the Avada Builder element map (issue #637), recorded from the
 * real fusion-builder 3.16.1 in the live Avada leg: the global
 * $all_fusion_builder_elements after awb_maybe_init_element_maps(), on a
 * front-end request (params unkeyed, as the map is built outside the
 * builder screens). Six elements kept, two params each, and the keys that
 * carry server paths, icons, defaults and option maps left out; everything
 * kept is as recorded.
 *
 * @return array<string,array<string,mixed>>
 */

return 
[
    'fusion_builder_container' => [
        'name' => 'Container',
        'shortcode' => 'fusion_builder_container',
        'hide_from_builder' => true,
        'params' => [
            0 => [
                'type' => 'textfield',
                'heading' => 'Container Type',
                'description' => 'Select the type of container you want to use.',
                'param_name' => 'type',
                'value' => 'flex',
                'hidden' => true,
            ],
            1 => [
                'type' => 'radio_button_set',
                'heading' => 'Interior Content Width',
                'description' => 'Select if the interior content is contained to site width or 100% width.',
                'param_name' => 'hundred_percent',
                'value' => [
                    'yes' => '100% Width',
                    'no' => 'Site Width',
                ],
                'default' => 'no',
                'group' => 'General',
            ],
        ],
    ],
    'fusion_builder_column' => [
        'name' => 'Column',
        'shortcode' => 'fusion_builder_column',
        'hide_from_builder' => true,
        'params' => [
            0 => [
                'type' => 'radio_button_set',
                'heading' => 'Alignment',
                'description' => 'Defines how the column should align itself within the container. Overrides what is set on the container.',
                'param_name' => 'align_self',
                'default' => 'auto',
                'group' => 'General',
                'value' => [
                    'auto' => 'Default',
                    'flex-start' => 'Flex Start',
                    'center' => 'Center',
                    'flex-end' => 'Flex End',
                    'stretch' => 'Stretch',
                ],
                'icons' => [
                    'auto' => '<span class="fusiona-cog"></span>',
                    'flex-start' => '<span class="fusiona-align-top-columns"></span>',
                    'center' => '<span class="fusiona-align-center-columns"></span>',
                    'flex-end' => '<span class="fusiona-align-bottom-columns"></span>',
                    'stretch' => '<span class="fusiona-full-height"></span>',
                ],
                'grid_layout' => true,
                'back_icons' => true,
                'dependency' => [
                    [
                        'element' => 'fusion_builder_container',
                        'param' => 'type',
                        'value' => 'flex',
                        'operator' => '==',
                    ],
                ],
                'callback' => [
                    'function' => 'fusion_update_flex_column',
                    'args' => [
                        'selector' => '.fusion-layout-column',
                    ],
                ],
            ],
            1 => [
                'type' => 'radio_button_set',
                'heading' => 'Content Layout',
                'description' => 'Defines how the column content should be positioned. If block is selected it will not use flex positioning and will instead allow floated elements.',
                'param_name' => 'content_layout',
                'default' => 'column',
                'group' => 'General',
                'value' => [
                    'column' => 'Column',
                    'row' => 'Row',
                    'block' => 'Block',
                ],
                'dependency' => [
                    [
                        'element' => 'fusion_builder_container',
                        'param' => 'type',
                        'value' => 'flex',
                        'operator' => '==',
                    ],
                ],
                'callback' => [
                    'function' => 'fusion_update_flex_elements',
                ],
            ],
        ],
    ],
    'fusion_separator' => [
        'name' => 'Separator',
        'shortcode' => 'fusion_separator',
        'params' => [
            0 => [
                'type' => 'select',
                'heading' => 'Style',
                'description' => 'Choose the separator line style.',
                'param_name' => 'style_type',
                'value' => [
                    'default' => 'Default',
                    'none' => 'No Style',
                    'single solid' => 'Single Border Solid',
                    'double solid' => 'Double Border Solid',
                    'single|dashed' => 'Single Border Dashed',
                    'double|dashed' => 'Double Border Dashed',
                    'single|dotted' => 'Single Border Dotted',
                    'double|dotted' => 'Double Border Dotted',
                    'shadow' => 'Shadow',
                    'wavy' => 'Wavy',
                ],
                'default' => 'default',
            ],
            1 => [
                'type' => 'range',
                'heading' => 'Flex Grow',
                'description' => 'Controls the amount (in parts) of the available space inside the flex column the separator should take up. If the separator is the only element in the column using flex grow, any value above 0 will let it take up all available space. Set to 0 to disable its ability to grow.',
                'group' => 'Design',
                'param_name' => 'flex_grow',
                'value' => '0',
                'min' => '0',
                'max' => '10',
                'step' => '1',
                'dependency' => [
                    [
                        'element' => 'fusion_builder_container',
                        'param' => 'type',
                        'value' => 'flex',
                        'operator' => '==',
                    ],
                ],
            ],
        ],
    ],
    'fusion_checklist' => [
        'name' => 'Checklist',
        'shortcode' => 'fusion_checklist',
        'multi' => 'multi_element_parent',
        'element_child' => 'fusion_li_item',
        'child_ui' => true,
        'params' => [
            0 => [
                'type' => 'textfield',
                'heading' => 'Dynamic Data',
                'param_name' => 'parent_dynamic_content',
                'dynamic_data' => true,
                'dynamic_options' => [
                    'acf_repeater_parent',
                    'latest_docs_parent',
                ],
                'group' => 'children',
            ],
            1 => [
                'type' => 'tinymce',
                'heading' => 'Content',
                'description' => 'Enter some content for this content box.',
                'param_name' => 'element_content',
                'value' => '[fusion_li_item icon=""]Your Content Goes Here[/fusion_li_item]',
            ],
        ],
    ],
    'fusion_li_item' => [
        'name' => 'List Item',
        'description' => 'Enter some content for this list item.',
        'shortcode' => 'fusion_li_item',
        'hide_from_builder' => true,
        'allow_generator' => true,
        'inline_editor' => true,
        'tag_name' => 'li',
        'selectors' => [
            'class' => 'fusion-li-item',
        ],
        'params' => [
            0 => [
                'type' => 'iconpicker',
                'heading' => 'Select Icon',
                'param_name' => 'icon',
                'value' => '',
                'description' => 'This setting will override the global setting. ',
                'dynamic_data' => true,
                'dependency' => [
                    [
                        'element' => 'parent_type',
                        'value' => 'numbered',
                        'operator' => '!=',
                    ],
                ],
            ],
            1 => [
                'type' => 'tinymce',
                'heading' => 'List Item Content',
                'description' => 'Add list item content.',
                'param_name' => 'element_content',
                'value' => 'Your Content Goes Here',
                'placeholder' => true,
                'dynamic_data' => true,
            ],
        ],
    ],
    'fusion_tb_content' => [
        'name' => 'Content',
        'shortcode' => 'fusion_tb_content',
        'component' => true,
        'templates' => [
            'content',
            'post_cards',
            'awb_off_canvas',
        ],
        'components_per_template' => false,
        'params' => [
            0 => [
                'type' => 'radio_button_set',
                'heading' => 'Content Display',
                'description' => 'Controls if the blog post content is displayed as excerpt, full content or is completely disabled.',
                'param_name' => 'excerpt',
                'value' => [
                    'yes' => 'Excerpt',
                    'no' => 'Full Content',
                ],
                'default' => 'no',
            ],
            1 => [
                'type' => 'range',
                'heading' => 'Excerpt Length',
                'description' => 'Controls the number of <a href="http://example.org/wp-admin/themes.php?page=avada_options&lang=en#excerpt_base" target="_blank" rel="noopener noreferrer">words</a> in the excerpts.',
                'param_name' => 'excerpt_length',
                'value' => '55',
                'min' => '0',
                'max' => '500',
                'step' => '1',
                'dependency' => [
                    [
                        'element' => 'excerpt',
                        'value' => 'yes',
                        'operator' => '==',
                    ],
                ],
            ],
        ],
    ],
];
