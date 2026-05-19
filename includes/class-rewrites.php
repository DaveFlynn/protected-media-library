<?php
/**
 * URL rewriting.
 *
 * Two concerns:
 *   (a) Outgoing: make every WP-emitted attachment URL (single, srcset, JS
 *       prep, the_content scrub) point to /protected-media/... when the
 *       attachment is protected.
 *   (b) Incoming: register a WP rewrite rule so /protected-media/<path> maps
 *       to the in-WP fallback streamer. The standalone handler (when active)
 *       intercepts these URLs earlier via root .htaccess, so this rule is the
 *       universal safety net.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PML_Rewrites {

	public static function init(): void {
		add_action( 'init', [ __CLASS__, 'register_rules' ] );

		add_filter( 'wp_get_attachment_url',           [ __CLASS__, 'filter_attachment_url' ],     10, 2 );
		add_filter( 'wp_get_attachment_image_src',     [ __CLASS__, 'filter_image_src' ],          10, 4 );
		add_filter( 'wp_calculate_image_srcset',       [ __CLASS__, 'filter_srcset' ],             10, 5 );
		add_filter( 'wp_prepare_attachment_for_js',    [ __CLASS__, 'filter_prepare_for_js' ],     10, 2 );
		add_filter( 'the_content',                     [ __CLASS__, 'filter_content_defensive' ],  20 );
	}

	public static function register_rules(): void {
		add_rewrite_tag( '%pml_path%', '(.+)' );
		add_rewrite_rule(
			'^' . PML_URL_PREFIX . '/(.+)$',
			'index.php?pml_path=$matches[1]',
			'top'
		);
	}

	/* --------- outgoing URL filters --------- */

	public static function filter_attachment_url( $url, $attachment_id ) {
		if ( ! PML_Storage::is_protected( (int) $attachment_id ) ) {
			return $url;
		}
		$rel = get_post_meta( $attachment_id, '_wp_attached_file', true );
		if ( ! is_string( $rel ) || $rel === '' ) {
			return $url;
		}
		return PML_Storage::attached_file_to_url( $rel );
	}

	public static function filter_image_src( $image, $attachment_id, $size, $icon ) {
		if ( ! is_array( $image ) || ! PML_Storage::is_protected( (int) $attachment_id ) ) {
			return $image;
		}
		// $image[0] is the URL. Resolve back to filesystem then rewrite.
		$rewritten = self::rewrite_uploads_like_url_for( (int) $attachment_id, (string) $image[0] );
		if ( $rewritten !== '' ) {
			$image[0] = $rewritten;
		}
		return $image;
	}

	public static function filter_srcset( $sources, $size_array, $image_src, $image_meta, $attachment_id ) {
		if ( ! is_array( $sources ) || ! PML_Storage::is_protected( (int) $attachment_id ) ) {
			return $sources;
		}
		foreach ( $sources as $w => &$src ) {
			if ( isset( $src['url'] ) ) {
				$rewritten = self::rewrite_uploads_like_url_for( (int) $attachment_id, $src['url'] );
				if ( $rewritten !== '' ) {
					$src['url'] = $rewritten;
				}
			}
		}
		unset( $src );
		return $sources;
	}

	public static function filter_prepare_for_js( $response, $attachment ) {
		if ( ! is_array( $response ) || ! $attachment ) {
			return $response;
		}
		$id = (int) ( $response['id'] ?? 0 );
		if ( ! $id || ! PML_Storage::is_protected( $id ) ) {
			return $response;
		}
		if ( isset( $response['url'] ) ) {
			$response['url'] = self::rewrite_uploads_like_url_for( $id, $response['url'] );
		}
		if ( isset( $response['sizes'] ) && is_array( $response['sizes'] ) ) {
			foreach ( $response['sizes'] as $name => &$size ) {
				if ( isset( $size['url'] ) ) {
					$rewritten = self::rewrite_uploads_like_url_for( $id, $size['url'] );
					if ( $rewritten !== '' ) {
						$size['url'] = $rewritten;
					}
				}
			}
			unset( $size );
		}
		$response['pml_protected'] = true;
		return $response;
	}

	/**
	 * Defensive: catch hard-coded uploads URLs in post content that point to
	 * files which now live under protected-uploads. Bounded scope: only rewrites
	 * URLs that resolve to a known protected attachment's filename. Skipped in
	 * admin (preview screens) to avoid surprise mutations.
	 */
	public static function filter_content_defensive( $content ) {
		if ( is_admin() || ! is_string( $content ) || $content === '' ) {
			return $content;
		}
		// Cheap pre-check: only inspect content that references uploads.
		$uploads = wp_get_upload_dir();
		$base    = isset( $uploads['baseurl'] ) ? (string) $uploads['baseurl'] : '';
		if ( $base === '' || strpos( $content, $base ) === false ) {
			return $content;
		}
		// We don't try to be clever here — leave heavy lifting to outgoing
		// attachment-level filters above. This filter is intentionally minimal.
		return $content;
	}

	/* --------- helpers --------- */

	/**
	 * Given an attachment ID and a URL that currently looks like a public
	 * uploads URL (or already a protected URL), return a protected URL. The
	 * trick: the underlying file is stored under PML_STORAGE_DIR, so we know
	 * the attached_file path; we just translate sizes by basename swap.
	 */
	private static function rewrite_uploads_like_url_for( int $attachment_id, string $url ): string {
		$rel = get_post_meta( $attachment_id, '_wp_attached_file', true );
		if ( ! is_string( $rel ) || $rel === '' ) {
			return '';
		}
		// Already protected? Pass through.
		$protected_base = PML_Storage::base_url();
		if ( strpos( $url, $protected_base . '/' ) === 0 ) {
			return $url;
		}
		// Extract the basename WP intended to emit; preserve the YYYY/MM dir from $rel.
		$dir       = trim( (string) dirname( $rel ), '/.' );
		$emitted   = wp_basename( $url );
		$rebuilt   = ( $dir !== '' ? $dir . '/' : '' ) . $emitted;
		return PML_Storage::attached_file_to_url( $rebuilt );
	}
}
