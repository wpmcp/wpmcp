<?php

namespace WPMCP\Tools\Analysis;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Read-only utility: given a foreground and background hex color, compute the
 * WCAG contrast ratio and report AA/AAA pass/fail for normal and large text.
 * Pure math with nothing to roll back, so this never touches Safe_Mutation.
 */
class Check_Contrast
{
    public function handle(array $args): array
    {
        $foreground = (string) ($args['foreground'] ?? '');
        $background = (string) ($args['background'] ?? '');

        if ('' === $foreground || '' === $background) {
            throw new \InvalidArgumentException('Both foreground and background colors are required.');
        }

        $ratio = Color_Contrast::contrast_ratio($foreground, $background);
        if (null === $ratio) {
            throw new \InvalidArgumentException('Both colors must be valid hex values (e.g. #112233).');
        }

        return [
            'foreground'  => $foreground,
            'background'  => $background,
            'ratio'       => round($ratio, 2),
            'normal_text' => [
                'aa'  => Color_Contrast::passes($ratio, false, 'AA'),
                'aaa' => Color_Contrast::passes($ratio, false, 'AAA'),
            ],
            'large_text'  => [
                'aa'  => Color_Contrast::passes($ratio, true, 'AA'),
                'aaa' => Color_Contrast::passes($ratio, true, 'AAA'),
            ],
        ];
    }
}
