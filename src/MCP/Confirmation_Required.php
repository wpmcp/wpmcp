<?php

namespace WPMCP\MCP;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The refusal a confirm gate throws when a call lacks confirm:true
 * (issue #387).
 *
 * It is still an InvalidArgumentException, so every caller that caught the
 * old refusal behaves exactly as before. The type is what lets a transport
 * tell "this needs the user's confirmation" apart from every other refusal
 * and, on a client that supports elicitation, ask the user instead of
 * failing the call (see Elicitation).
 */
class Confirmation_Required extends \InvalidArgumentException
{
    /** The refusal most recently seen leaving an ability, until taken. */
    private static ?self $observed = null;

    /**
     * Records a refusal as it leaves an ability's callback.
     *
     * WordPress 7.1 wraps anything an ability callback throws into a
     * generic ability_callback_exception WP_Error, which drops the type.
     * Registrar::throttled() is the last place every ability's exception
     * is still typed, so it reports the refusal here before re-throwing,
     * and the transport reads it back with take() after the call.
     */
    public static function observe(self $refusal): void
    {
        self::$observed = $refusal;
    }

    /** The observed refusal, if any, clearing it. */
    public static function take(): ?self
    {
        $refusal        = self::$observed;
        self::$observed = null;

        return $refusal;
    }
}
