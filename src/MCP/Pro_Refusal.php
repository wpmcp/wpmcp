<?php

declare(strict_types=1);

namespace WPMCP\MCP;

/**
 * The sentence a free install answers with when an AI client asks for a
 * Pro-only tool, skill or ability.
 *
 * The moment a free user asks the agent for something Pro does is the
 * strongest upgrade trigger after the admin banner, so the refusal names
 * the plan and carries the same offer the banner does (Pro_Banner::offer),
 * with utm_medium=tool-refusal so the pricing page can tell the two apart.
 * The copy is one plain sentence pair an AI client can relay verbatim.
 *
 * The directory flavor strips Pro_Banner (scripts/flavors/wporg/policy.php),
 * so this class is guarded on it and falls back to a sentence with no link,
 * no coupon and no upgrade copy, which keeps that build clear of the
 * compliance Admin_Nag_Rule phrases.
 */
final class Pro_Refusal
{
    public const MEDIUM = 'tool-refusal';

    /**
     * @param string $subject What was asked for, as the start of a sentence:
     *                        'This tool', 'The skill "x"', 'Settings sync'.
     */
    public static function message(string $subject): string
    {
        $subject = trim($subject);
        if ('' === $subject) {
            $subject = 'This tool';
        }

        $banner = '\\WPMCP\\Admin\\Pro_Banner';
        if (! class_exists($banner) || ! method_exists($banner, 'offer')) {
            return sprintf('%s is part of WP MCP Pro and is not available on this install.', $subject);
        }

        $offer = $banner::offer(self::MEDIUM);

        return sprintf(
            '%s is part of WP MCP Pro. Upgrade at %s (code %s takes %d%% off).',
            $subject,
            $offer['url'],
            $offer['coupon'],
            $offer['percent']
        );
    }
}
