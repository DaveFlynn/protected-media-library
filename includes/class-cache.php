<?php
/**
 * Page-cache safety for posts that render protected media.
 *
 * Why: every protected block's render.php produces DIFFERENT HTML for
 * authenticated vs anonymous viewers (full player vs locked placeholder).
 * A naïve full-page cache (WP Super Cache, hosting page caching, Cloudflare
 * "Cache Everything", etc.) keyed only on URL would freeze whichever
 * variant the cache warmer hit first, so subsequent viewers in the OTHER
 * auth state see wrong markup.
 *
 * Fix: on singular views whose content contains any pml/* block or [pml-*]
 * shortcode, emit `Cache-Control: private, no-cache` + `Vary: Cookie`.
 * `private` tells shared caches (CDNs, reverse proxies) not to store the
 * response at all; `no-cache` forces revalidation; `Vary: Cookie` is belt
 * and braces for any cache that does ignore `private`.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PML_Cache {

	public static function init(): void {
		add_action( 'template_redirect', [ __CLASS__, 'maybe_send_headers' ] );
	}

	public static function maybe_send_headers(): void {
		if ( headers_sent() ) {
			return;
		}
		if ( ! is_singular() ) {
			return;
		}
		$post = get_post();
		if ( ! $post || ! self::content_has_protected_media( $post->post_content ) ) {
			return;
		}
		// `private` blocks shared/CDN caching. `no-cache` requires revalidation
		// even for browsers. `Vary: Cookie` keeps any cache that does ignore
		// `private` from cross-mixing logged-in vs logged-out responses.
		header( 'Cache-Control: private, no-cache, max-age=0', true );
		header( 'Vary: Cookie', false );
	}

	/**
	 * Cheap string scan — avoids running `parse_blocks()` on every singular
	 * view. Covers both block markers and shortcode forms.
	 */
	public static function content_has_protected_media( string $content ): bool {
		if ( $content === '' ) {
			return false;
		}
		if ( strpos( $content, '<!-- wp:pml/protected-' ) !== false ) {
			return true;
		}
		if ( strpos( $content, '[pml-' ) !== false ) {
			return true;
		}
		return false;
	}
}
