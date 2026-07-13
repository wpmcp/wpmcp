<?php

namespace WPMCP\Tools\Analysis;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Pure WCAG accessibility scoring over an extracted-content array (from
 * Content_Extractor). It flags common issues: images missing alt text, heading
 * order jumps, empty or non-descriptive link text, and form inputs without
 * labels. Each check carries the offending element locations (e.g. "img[2]").
 *
 * Returns a 0-100 score, severity-tagged checks (pass/warn/fail), and a summary
 * count. The form-label check is only present when the page has form controls.
 * No WordPress dependency: everything operates on the passed-in arrays.
 */
class A11y_Analyzer
{
    /** Link phrases that convey no destination out of context. */
    private const NON_DESCRIPTIVE_LINKS = [
        'click here',
        'here',
        'read more',
        'more',
        'link',
        'this',
        'this page',
        'learn more',
        'details',
    ];

    public static function analyze(array $extract): array
    {
        $headings    = $extract['headings'] ?? [];
        $images      = $extract['images'] ?? [];
        $links       = $extract['links'] ?? [];
        $form_fields = $extract['form_fields'] ?? [];

        $checks   = [];
        $checks[] = self::check_image_alts($images);
        $checks[] = self::check_heading_hierarchy($headings);
        $checks[] = self::check_link_text($links);

        if ([] !== $form_fields) {
            $checks[] = self::check_form_labels($form_fields);
        }

        return [
            'score'   => self::score($checks),
            'checks'  => $checks,
            'summary' => self::summarize($checks),
        ];
    }

    private static function check(string $id, string $label, string $status, string $message, array $locations = [], string $recommendation = ''): array
    {
        return [
            'id'             => $id,
            'label'          => $label,
            'status'         => $status,
            'message'        => $message,
            'locations'      => $locations,
            'recommendation' => $recommendation,
        ];
    }

    private static function check_image_alts(array $images): array
    {
        $missing = [];
        foreach ($images as $img) {
            if ('' === trim((string) ($img['alt'] ?? ''))) {
                $missing[] = (string) ($img['location'] ?? '');
            }
        }
        if ([] !== $missing) {
            return self::check(
                'image_alts',
                'Image alt text',
                'fail',
                sprintf('%d image(s) are missing alt text.', count($missing)),
                array_values(array_filter($missing)),
                'Every informative image needs descriptive alt text; decorative images should use alt="".'
            );
        }
        return self::check('image_alts', 'Image alt text', 'pass', 'All images have alt text (or there are none).');
    }

    private static function check_heading_hierarchy(array $headings): array
    {
        $previous = 0;
        foreach ($headings as $h) {
            $level = (int) ($h['level'] ?? 0);
            if ($previous > 0 && $level > $previous + 1) {
                return self::check(
                    'heading_hierarchy',
                    'Heading hierarchy',
                    'warn',
                    sprintf('Heading levels jump from H%d to H%d, skipping a level.', $previous, $level),
                    [sprintf('H%d "%s"', $level, (string) ($h['text'] ?? ''))],
                    'Screen-reader users navigate by heading level; step down one level at a time.'
                );
            }
            $previous = $level;
        }
        return self::check('heading_hierarchy', 'Heading hierarchy', 'pass', 'Heading levels descend without skips.');
    }

    private static function check_link_text(array $links): array
    {
        $offenders = [];
        foreach ($links as $link) {
            $text = strtolower(trim((string) ($link['text'] ?? '')));
            if ('' === $text || in_array($text, self::NON_DESCRIPTIVE_LINKS, true)) {
                $offenders[] = (string) ($link['url'] ?? '');
            }
        }
        if ([] !== $offenders) {
            return self::check(
                'link_text_quality',
                'Link text quality',
                'warn',
                sprintf('%d link(s) have empty or non-descriptive text.', count($offenders)),
                array_values(array_filter($offenders)),
                'Use link text that describes the destination (avoid "click here" / "read more").'
            );
        }
        return self::check('link_text_quality', 'Link text quality', 'pass', 'All links have descriptive text.');
    }

    private static function check_form_labels(array $form_fields): array
    {
        $unlabeled = [];
        foreach ($form_fields as $field) {
            if ('' === trim((string) ($field['label'] ?? ''))) {
                $unlabeled[] = (string) ($field['location'] ?? '');
            }
        }
        if ([] !== $unlabeled) {
            return self::check(
                'form_label_coverage',
                'Form label coverage',
                'fail',
                sprintf('%d form control(s) have no associated label.', count($unlabeled)),
                array_values(array_filter($unlabeled)),
                'Associate every input with a <label for>, a wrapping <label>, or an aria-label.'
            );
        }
        return self::check('form_label_coverage', 'Form label coverage', 'pass', 'All form controls have labels.');
    }

    private static function score(array $checks): int
    {
        if ([] === $checks) {
            return 100;
        }
        $earned = 0.0;
        foreach ($checks as $c) {
            $earned += match ($c['status']) {
                'pass'  => 1.0,
                'warn'  => 0.5,
                default => 0.0,
            };
        }
        return (int) round(($earned / count($checks)) * 100);
    }

    private static function summarize(array $checks): array
    {
        $summary = ['passes' => 0, 'warnings' => 0, 'failures' => 0];
        foreach ($checks as $c) {
            $summary[match ($c['status']) {
                'pass'  => 'passes',
                'warn'  => 'warnings',
                default => 'failures',
            }]++;
        }
        return $summary;
    }
}
