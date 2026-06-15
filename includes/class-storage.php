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
		// Resolve get_attached_file() to the real protected-storage path. Storage
		// is only redirected via upload_dir DURING the upload request; on every
		// later request WP would otherwise point at the standard uploads basedir,
		// where the file does not exist. Fixes Regenerate Thumbnails, metadata
		// re-runs, third-party get_attached_file() callers, and core's own
		// delete routine's size/original resolution.
		add_filter( 'get_attached_file', [ __CLASS__, 'filter_attached_file' ], 10, 2 );
		// Delete the real files when a protected attachment is deleted. Core's
		// wp_delete_attachment_files() containment-checks the MAIN file against
		// the standard uploads basedir and so refuses to unlink anything outside
		// the docroot, orphaning protected files on disk. We clean them up here.
		add_action( 'delete_attachment', [ __CLASS__, 'cleanup_protected_files_on_delete' ] );
	}

	/** @var bool|null Per-request cache for request_is_protected(). */
	private static $request_protected = null;

	/**
	 * Is the current request a protected upload? Looks at POST/GET for the
	 * nonce field. Verified once and cached per-request.
	 */
	public static function request_is_protected(): bool {
		if ( self::$request_protected !== null ) {
			return self::$request_protected;
		}
		$nonce_raw = $_REQUEST[ self::FLAG_FIELD ] ?? '';
		$nonce     = is_string( $nonce_raw ) ? wp_unslash( $nonce_raw ) : '';
		if ( $nonce && wp_verify_nonce( $nonce, self::NONCE_KEY ) ) {
			return self::$request_protected = true;
		}
		// Explicit per-process flag set by code (e.g. REST upload handler).
		if ( ! empty( $GLOBALS['pml_force_protected'] ) ) {
			return self::$request_protected = true;
		}
		return self::$request_protected = false;
	}

	public static function force_protected_for_request(): void {
		$GLOBALS['pml_force_protected'] = true;
		// Anything that called wp_upload_dir() during boot (and almost
		// everything does) has already cached a `false` answer; invalidate it
		// so programmatic callers (CLI migrations, sideloads) take effect.
		self::$request_protected = null;
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

	/**
	 * Map get_attached_file() for protected attachments onto PML_STORAGE_DIR.
	 *
	 * WP computes the incoming $file as `standard_uploads_basedir + relative`,
	 * which is wrong for us — the bytes live under PML_STORAGE_DIR. We only
	 * rewrite when the stored `_wp_attached_file` value is relative (the normal
	 * case); absolute stored paths are passed through untouched.
	 */
	public static function filter_attached_file( $file, $attachment_id ) {
		if ( ! self::is_protected( (int) $attachment_id ) ) {
			return $file;
		}
		$rel = get_post_meta( (int) $attachment_id, '_wp_attached_file', true );
		if ( ! is_string( $rel ) || $rel === '' ) {
			return $file;
		}
		// Already absolute (*nix or Windows drive path)? Leave as stored.
		if ( $rel[0] === '/' || preg_match( '|^[a-zA-Z]:\\\\|', $rel ) ) {
			return $rel;
		}
		return rtrim( PML_STORAGE_DIR, '/' ) . '/' . ltrim( $rel, '/' );
	}

	/**
	 * On deletion of a protected attachment, remove its files from protected
	 * storage (main file + intermediate sizes + original_image).
	 *
	 * Fires on `delete_attachment`, before core's own file deletion. Core can
	 * delete the sizes (it derives their directory from dirname($file)) but
	 * NOT the main file — `wp_delete_file_from_directory( $file, basedir )` in
	 * wp-includes/post.php containment-checks against the standard uploads
	 * basedir, and our files live outside it. Deleting the full set here is
	 * idempotent with whatever core manages to remove afterward.
	 *
	 * Every unlink is hard-scoped to within PML_STORAGE_DIR via realpath().
	 */
	public static function cleanup_protected_files_on_delete( $attachment_id ): void {
		$attachment_id = (int) $attachment_id;
		if ( ! self::is_protected( $attachment_id ) ) {
			return;
		}
		$rel = get_post_meta( $attachment_id, '_wp_attached_file', true );
		if ( ! is_string( $rel ) || $rel === '' ) {
			return;
		}

		$base = rtrim( wp_normalize_path( PML_STORAGE_DIR ), '/' );
		$full = $base . '/' . ltrim( wp_normalize_path( $rel ), '/' );
		$dir  = dirname( $full );

		$targets = [ $full ];

		$meta = wp_get_attachment_metadata( $attachment_id );
		if ( is_array( $meta ) ) {
			if ( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
				foreach ( $meta['sizes'] as $size ) {
					if ( ! empty( $size['file'] ) ) {
						$targets[] = $dir . '/' . $size['file'];
					}
				}
			}
			if ( ! empty( $meta['original_image'] ) ) {
				$targets[] = $dir . '/' . $meta['original_image'];
			}
		}

		foreach ( array_unique( $targets ) as $target ) {
			$real = realpath( wp_normalize_path( $target ) );
			if ( $real === false ) {
				continue;
			}
			$real = wp_normalize_path( $real );
			// Never unlink anything outside protected storage.
			if ( strpos( $real, $base . '/' ) !== 0 ) {
				continue;
			}
			@unlink( $real );
		}
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
