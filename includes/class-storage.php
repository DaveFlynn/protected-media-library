<?php
/**
 * Upload routing + path translation.
 *
 * When an upload is flagged as protected (via nonce in the request), redirect
 * its filesystem destination to `protected-uploads/YYYY/MM/`. Only for that
 * single request — never globally.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PML_Storage {

	const FLAG_META  = '_protected_media';
	const FLAG_FIELD = 'pml_protected_upload';
	const NONCE_KEY  = 'pml_upload';

	public static function init(): void {
		// Detect "this upload is protected" flag and switch upload_dir for it only.
		add_filter( 'upload_dir', [ __CLASS__, 'maybe_redirect_upload_dir' ] );
		// Mark the attachment after creation so later filters know it's protected.
		add_action( 'add_attachment', [ __CLASS__, 'maybe_mark_attachment' ] );
		// Guard wp-admin upload screen to set the flag based on referer.
		add_filter( 'wp_handle_upload_prefilter', [ __CLASS__, 'maybe_flag_upload' ] );
		// Restore filename-derived title after wp_generate_attachment_metadata
		// applies ID3 / EXIF overrides (audio files in particular: WP rewrites
		// post_title to the ID3 "title" tag, which is often junk like "track 4").
		add_filter( 'wp_generate_attachment_metadata', [ __CLASS__, 'restore_filename_title' ], 10, 2 );
	}

	/**
	 * Is the current request a protected upload? Looks at POST/GET for the
	 * nonce field. Verified once and cached per-request.
	 */
	public static function request_is_protected(): bool {
		static $cached = null;
		if ( $cached !== null ) {
			return $cached;
		}
		$nonce_raw = $_REQUEST[ self::FLAG_FIELD ] ?? '';
		$nonce     = is_string( $nonce_raw ) ? wp_unslash( $nonce_raw ) : '';
		if ( $nonce && wp_verify_nonce( $nonce, self::NONCE_KEY ) ) {
			return $cached = true;
		}
		// Explicit per-process flag set by code (e.g. REST upload handler).
		if ( ! empty( $GLOBALS['pml_force_protected'] ) ) {
			return $cached = true;
		}
		return $cached = false;
	}

	public static function force_protected_for_request(): void {
		$GLOBALS['pml_force_protected'] = true;
	}

	public static function maybe_flag_upload( array $file ): array {
		// No mutation; this filter exists so we can short-circuit pathological
		// uploads in future. Storage routing happens via upload_dir below.
		return $file;
	}

	public static function maybe_redirect_upload_dir( array $dirs ): array {
		if ( ! self::request_is_protected() ) {
			return $dirs;
		}
		$base_dir = PML_STORAGE_DIR;
		$base_url = self::base_url();

		// Mirror WP's YYYY/MM convention (respect the option just like core does).
		$subdir = $dirs['subdir'] ?? '';
		if ( get_option( 'uploads_use_yearmonth_folders' ) ) {
			$time   = current_time( 'mysql' );
			$y      = substr( $time, 0, 4 );
			$m      = substr( $time, 5, 2 );
			$subdir = "/$y/$m";
		}

		$dirs['path']    = $base_dir . $subdir;
		$dirs['url']     = $base_url . $subdir;
		$dirs['subdir']  = $subdir;
		$dirs['basedir'] = $base_dir;
		$dirs['baseurl'] = $base_url;
		$dirs['error']   = false;

		wp_mkdir_p( $dirs['path'] );
		return $dirs;
	}

	public static function maybe_mark_attachment( int $attachment_id ): void {
		if ( ! self::request_is_protected() ) {
			return;
		}
		update_post_meta( $attachment_id, self::FLAG_META, 1 );
		update_post_meta( $attachment_id, PML_Access::META_RULE, 'logged_in' );
	}

	/**
	 * For protected attachments, restore the post_title to the upload's
	 * original filename (without extension) after WP's metadata generator
	 * has had a chance to rewrite it from ID3 / EXIF tags. Filename wins —
	 * editors named the file deliberately; ID3 tags are often nonsense.
	 *
	 * Fires on `wp_generate_attachment_metadata`, which runs AFTER
	 * `wp_read_audio_metadata` has already pushed any ID3 "title" into
	 * post_title via wp_update_post.
	 */
	public static function restore_filename_title( $metadata, $attachment_id ) {
		if ( ! self::is_protected( (int) $attachment_id ) ) {
			return $metadata;
		}
		$file = get_attached_file( (int) $attachment_id );
		if ( ! $file ) {
			return $metadata;
		}
		$basename = wp_basename( $file );
		$title    = pathinfo( $basename, PATHINFO_FILENAME );
		// Use sanitize_text_field to keep it safe but preserve dashes/spaces.
		$title = sanitize_text_field( $title );
		if ( $title === '' ) {
			return $metadata;
		}
		// Only update if it actually differs — avoid extra DB writes.
		$current = get_post_field( 'post_title', (int) $attachment_id );
		if ( $current !== $title ) {
			wp_update_post( [
				'ID'         => (int) $attachment_id,
				'post_title' => $title,
			] );
		}
		return $metadata;
	}

	/* --------- helpers --------- */

	public static function is_protected( int $attachment_id ): bool {
		return (bool) get_post_meta( $attachment_id, self::FLAG_META, true );
	}

	public static function base_dir(): string {
		return PML_STORAGE_DIR;
	}

	/**
	 * Public-facing base URL for protected files. Routed through the handler.
	 */
	public static function base_url(): string {
		return home_url( '/' . PML_URL_PREFIX );
	}

	/**
	 * Translate a stored attached_file (relative path) to a public protected URL.
	 */
	public static function attached_file_to_url( string $relative ): string {
		$relative = ltrim( $relative, '/' );
		return self::base_url() . '/' . $relative;
	}

	/**
	 * Translate a real absolute filesystem path inside protected-uploads/ to a
	 * protected URL. Returns '' if the path is not inside the storage root.
	 */
	public static function abs_path_to_url( string $abs_path ): string {
		$base = wp_normalize_path( PML_STORAGE_DIR );
		$abs  = wp_normalize_path( $abs_path );
		if ( strpos( $abs, $base . '/' ) !== 0 ) {
			return '';
		}
		$rel = ltrim( substr( $abs, strlen( $base ) ), '/' );
		return self::base_url() . '/' . $rel;
	}
}
