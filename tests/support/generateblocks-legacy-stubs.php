<?php
/**
 * Stand-in for generateblocks_get_block_defaults() (GenerateBlocks 2.5,
 * includes/defaults.php), the PHP defaults GenerateBlocks renders its 1.x
 * blocks with (issue #794), keyed as GenerateBlocks keys them. Only a few
 * of each block's defaults are reproduced. Real GenerateBlocks always wins.
 */

if (! function_exists('generateblocks_get_block_defaults')) {
    function generateblocks_get_block_defaults()
    {
        return [
            'container'       => [ 'tagName' => 'div', 'backgroundColor' => '', 'isGrid' => false, 'width' => 50 ],
            'buttonContainer' => [ 'alignment' => '' ],
            'button'          => [ 'backgroundColor' => '', 'textColor' => '', 'hasIcon' => false ],
            'gridContainer'   => [ 'horizontalGap' => '', 'verticalAlignment' => '' ],
            'headline'        => [ 'element' => 'h2', 'textColor' => '', 'googleFont' => false, 'backgroundColorOpacity' => 1 ],
            'image'           => [ 'sizeSlug' => '' ],
        ];
    }
}
