<?php
/**
 * HMAC-signed auxiliary cookie. The handler trusts only this cookie; never the
 * WP login cookie (parsing that would require booting WP). On every page load
 * for an authenticated user we re-mint if the cookie is missing or about to
 * expire.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PML_Cookie {

	public static function init(): void {
		add_action( 'wp_loaded', [ __CLASS__, 'maybe_mint' ] );
		add_action( 'wp_login', [ __CLASS__, 'on_login' ], 10, 2 );
		add_action( 'wp_logout', [ __CLASS__, 'clear' ] );
	}

	public static function maybe_mint(): void {
		if ( headers_sent() || ! is_user_logged_in() ) {
			return;
		}
		$user_id = get_current_user_id();
		$level   = PML_Access::user_max_level( $user_id );
		if ( $level === null ) {
			return;
		}
		// Re-mint if missing OR if existing payload is < halfway through TTL.
		$existing = self::verify_from_request();
		if ( $existing && ( $existing['exp'] - time() ) > ( PML_COOKIE_TTL / 2 ) ) {
			return;
		}
		self::mint( $user_id, $level );
	}

	public static function on_login( $user_login, $user ): void {
		if ( $user instanceof WP_User ) {
			$level = PML_Access::user_max_level( (int) $user->ID );
			if ( $level !== null ) {
				self::mint( (int) $user->ID, $level );
			}
		}
	}

	public static function mint( int $user_id, string $level ): void {
		$exp     = time() + PML_COOKIE_TTL;
		$payload = $user_id . '|' . $exp . '|' . $level;
		$secret  = self::secret();
		if ( $secret === '' ) {
			return;
		}
		$sig   = hash_hmac( 'sha256', $payload, $secret );
		$value = base64_encode( $payload . '|' . $sig );

		setcookie(
			PML_COOKIE_NAME,
			$value,
			[
				'expires'  => $exp,
				'path'     => '/' . PML_URL_PREFIX . '/',
				'domain'   => COOKIE_DOMAIN ?: '',
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			]
		);
		// Mirror to $_COOKIE so subsequent code in this request sees it.
		$_COOKIE[ PML_COOKIE_NAME ] = $value;
	}

	public static function clear(): void {
		setcookie(
			PML_COOKIE_NAME,
			'',
			[
				'expires'  => time() - 3600,
				'path'     => '/' . PML_URL_PREFIX . '/',
				'domain'   => COOKIE_DOMAIN ?: '',
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			]
		);
		unset( $_COOKIE[ PML_COOKIE_NAME ] );
	}

	/**
	 * Verify the cookie from the current request. Returns the decoded payload
	 * array on success, null otherwise.
	 */
	public static function verify_from_request(): ?array {
		if ( empty( $_COOKIE[ PML_COOKIE_NAME ] ) ) {
			return null;
		}
		return self::verify_value( (string) $_COOKIE[ PML_COOKIE_NAME ], self::secret() );
	}

	/**
	 * Pure verification. Exposed so the standalone handler can call it without
	 * booting WP (it requires this file directly).
	 */
	public static function verify_value( string $cookie_value, string $secret ): ?array {
		if ( $secret === '' || $cookie_value === '' ) {
			return null;
		}
		$decoded = base64_decode( $cookie_value, true );
		if ( $decoded === false ) {
			return null;
		}
		$parts = explode( '|', $decoded );
		if ( count( $parts ) !== 4 ) {
			return null;
		}
		[ $user_id, $exp, $level, $sig ] = $parts;
		$payload = $user_id . '|' . $exp . '|' . $level;
		$expect  = hash_hmac( 'sha256', $payload, $secret );
		if ( ! hash_equals( $expect, $sig ) ) {
			return null;
		}
		if ( (int) $exp < time() ) {
			return null;
		}
		return [
			'user_id' => (int) $user_id,
			'exp'     => (int) $exp,
			'level'   => $level,
		];
	}

	public static function secret(): string {
		static $cached = null;
		if ( $cached !== null ) {
			return $cached;
		}
		if ( ! is_readable( PML_SECRET_FILE ) ) {
			return $cached = '';
		}
		$secret = include PML_SECRET_FILE;
		return $cached = is_string( $secret ) ? $secret : '';
	}
}
