<?php
/**
 * Access rule abstraction. v1 = "any logged-in user". Designed so v2 can swap
 * in per-attachment ACLs without touching the cookie minter or handler.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PML_Access {

	const META_RULE = '_protected_media_access';

	/**
	 * Resolve the access "level" string for an attachment. Cookie carries this
	 * value so the handler can re-evaluate without a DB hit.
	 */
	public static function rule_for( int $attachment_id ): string {
		$rule = get_post_meta( $attachment_id, self::META_RULE, true );
		if ( ! is_string( $rule ) || $rule === '' ) {
			$rule = 'logged_in';
		}
		return $rule;
	}

	/**
	 * Can $user_id view $attachment_id? Used by the cookie minter (full check)
	 * and by the fallback streamer.
	 */
	public static function user_can_view( int $attachment_id, int $user_id ): bool {
		$rule = self::rule_for( $attachment_id );
		return self::evaluate( $rule, $user_id );
	}

	/**
	 * Evaluate an access-level string. The handler also calls this (indirectly)
	 * via the cookie payload — keep it pure and free of DB calls where possible.
	 */
	public static function evaluate( string $rule, int $user_id ): bool {
		if ( $user_id <= 0 ) {
			return false;
		}
		switch ( $rule ) {
			case 'logged_in':
				return true;
			// v2: 'role:editor', 'capability:read_private_posts', 'users:1,5,12' ...
		}
		return false;
	}

	/**
	 * "Best" access level a user has globally. Cookie carries this so the
	 * handler can short-circuit common cases. v1: 'logged_in' if logged in.
	 */
	public static function user_max_level( int $user_id ): ?string {
		return $user_id > 0 ? 'logged_in' : null;
	}
}
