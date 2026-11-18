<?php
/**
 * A stand-in for WP Activity Log's WSAL\Controllers\Alert::get_message()
 * (issue #949), for tests that run without the plugin loaded.
 *
 * It builds the message the way WP Activity Log 5.7.0 does in its default
 * (HTML) context: the alert message with every %Token% replaced by the meta
 * value in <strong>, or NULL when the meta has none, then each metadata
 * line and each link, joined with <br />. The free version's extra
 * "Premium" link and the security tip link are added as the plugin adds
 * them (classes/Controllers/class-alert.php, defaults.php).
 */

namespace WSAL\Controllers;

if ( ! class_exists( '\\WSAL\\Controllers\\Alert' ) ) {
	class Alert {
		/** alert id => [ message, [ label => token ], [ label => url token or url ] ] */
		private const ALERTS = [
			2000 => [
				'Created the post %PostTitle%.',
				[ 'Post ID' => '%PostID%', 'Post type' => '%PostType%', 'Post status' => '%PostStatus%' ],
				[ 'View the post in editor' => '%EditorLinkPost%' ],
			],
			1002 => [
				'Failed login attempt - wrong password.',
				[ 'Login URL' => '%LoginPageURL%' ],
				[ 'limit users failed login attempts with Melapress Login Security' => 'https://wordpress.org/plugins/melapress-login-security/' ],
			],
			1004 => [
				'Login blocked because other session(s) already exist for this user.',
				[ 'IP address' => '%ClientIP%' ],
				[ 'Get instant alerts for events like this with Premium' => 'https://melapress.com/wordpress-activity-log/pricing/?utm_source=plugin&utm_medium=wsal&utm_campaign=viewer-cta-1004' ],
			],
		];

		public static function get_message( $meta_data, $message = null, $alert_id = 0, $occurrence_id = 0, $context = 'default' ) {
			unset( $message, $occurrence_id, $context );
			if ( ! isset( self::ALERTS[ $alert_id ] ) ) {
				return false;
			}
			list( $text, $metadata, $links ) = self::ALERTS[ $alert_id ];
			$value = static function ( string $token ) use ( $meta_data ): string {
				$key = trim( $token, '%' );
				return '<strong>' . esc_html( isset( $meta_data[ $key ] ) ? (string) $meta_data[ $key ] : 'NULL' ) . '</strong>';
			};
			$parts = [ preg_replace_callback( '/%[A-Za-z]+%/', static fn ( $m ) => $value( $m[0] ), $text ) ];
			foreach ( $metadata as $label => $token ) {
				$parts[] = $label . ': ' . $value( $token );
			}
			foreach ( $links as $label => $url ) {
				if ( '%' === substr( $url, 0, 1 ) ) {
					$key = trim( $url, '%' );
					if ( empty( $meta_data[ $key ] ) ) {
						continue;
					}
					$url = (string) $meta_data[ $key ];
				}
				$parts[] = '<strong><a  href="' . esc_url( $url ) . '"  target="_blank">' . $label . '</a></strong>';
			}
			return implode( '<br />', $parts );
		}
	}
}
