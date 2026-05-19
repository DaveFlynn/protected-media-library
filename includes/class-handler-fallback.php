<?php
/**
 * Universal fallback streamer. Runs inside WordPress on `parse_request` so it
 * fires before the template engine. Used when:
 *   - The root .htaccess fast-path isn't installed (Nginx, locked-down host).
 *   - A request slips past the fast-path handler for any reason.
 *
 * Auth model is identical to the standalone handler: validate the HMAC cookie,
 * fall back to is_user_logged_in() for the very first request after login (the
 * cookie may not be set yet on the same request that mints it), serve the
 * file, exit.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PML_Handler_Fallback {

	public static function init(): void {
		add_action( 'parse_request', [ __CLASS__, 'maybe_serve' ], 0 );
	}

	public static function maybe_serve( WP $wp ): void {
		$path = $wp->query_vars['pml_path'] ?? '';
		if ( ! is_string( $path ) || $path === '' ) {
			// Also catch unparsed REQUEST_URI form when permalinks are off.
			$req = $_SERVER['REQUEST_URI'] ?? '';
			$prefix = '/' . PML_URL_PREFIX . '/';
			$pos = strpos( $req, $prefix );
			if ( $pos === false ) {
				return;
			}
			$path = substr( $req, $pos + strlen( $prefix ) );
			$path = explode( '?', $path, 2 )[0];
		}
		if ( $path === '' ) {
			return;
		}

		self::serve( (string) $path );
		exit;
	}

	public static function serve( string $requested_path ): void {
		$abs = self::resolve_safe_path( $requested_path );
		if ( $abs === null ) {
			self::respond_404();
			return;
		}

		// Auth: HMAC cookie first (cheap), then WP session as fallback for the
		// first request after login (cookie may not have been sent yet).
		$ok = false;
		if ( PML_Cookie::verify_from_request() ) {
			$ok = true;
		} elseif ( is_user_logged_in() && PML_Access::user_max_level( get_current_user_id() ) ) {
			$ok = true;
		}

		if ( ! $ok ) {
			self::respond_login_redirect();
			return;
		}

		self::stream_file( $abs );
	}

	private static function resolve_safe_path( string $requested_path ): ?string {
		// Reject queries, fragments, NUL.
		if ( str_contains( $requested_path, "\0" ) ) {
			return null;
		}
		$requested_path = ltrim( rawurldecode( $requested_path ), '/' );
		// Normalize and reject traversal.
		$base = wp_normalize_path( PML_STORAGE_DIR );
		$abs  = wp_normalize_path( $base . '/' . $requested_path );
		// Realpath the directory portion to canonicalize; tolerate file not yet existing.
		$real_base = realpath( $base );
		if ( $real_base === false ) {
			return null;
		}
		if ( ! file_exists( $abs ) || ! is_file( $abs ) ) {
			return null;
		}
		$real_abs = realpath( $abs );
		if ( $real_abs === false ) {
			return null;
		}
		if ( strpos( $real_abs, $real_base . DIRECTORY_SEPARATOR ) !== 0 && $real_abs !== $real_base ) {
			return null;
		}
		return $real_abs;
	}

	private static function stream_file( string $abs ): void {
		$mime  = self::mime_for( $abs );
		$size  = filesize( $abs );
		$mtime = filemtime( $abs );
		$etag  = '"' . md5( $abs . '|' . $size . '|' . $mtime ) . '"';

		// Conditional GET. 304 wins over Range per RFC 7233.
		// wp_unslash because wp_magic_quotes() escapes $_SERVER values — the
		// browser's `"..."` arrives as `\"...\"` otherwise and the compare fails.
		$if_none_match = isset( $_SERVER['HTTP_IF_NONE_MATCH'] ) ? wp_unslash( (string) $_SERVER['HTTP_IF_NONE_MATCH'] ) : '';
		$if_mod_since  = isset( $_SERVER['HTTP_IF_MODIFIED_SINCE'] ) ? wp_unslash( (string) $_SERVER['HTTP_IF_MODIFIED_SINCE'] ) : '';
		$not_modified  = ( $if_none_match === $etag )
			|| ( $if_mod_since && strtotime( $if_mod_since ) >= $mtime );

		header( 'X-Content-Type-Options: nosniff' );
		header( 'Cache-Control: private, max-age=300' );
		header( 'ETag: ' . $etag );
		header( 'Last-Modified: ' . gmdate( 'D, d M Y H:i:s', $mtime ) . ' GMT' );
		header( 'Accept-Ranges: bytes' );

		if ( $not_modified ) {
			status_header( 304 );
			return;
		}

		header( 'Content-Type: ' . $mime );
		header( 'X-PML-Delivery: fallback' );

		// Range handling: parse, then 206 or 416. Fall back to full 200 if
		// header is missing or unparseable (single-range only — multi-range
		// is rarely used by players and significantly more code to support).
		$range_header = $_SERVER['HTTP_RANGE'] ?? '';
		if ( $range_header !== '' ) {
			$range = self::parse_range( $range_header, $size );
			if ( $range !== null ) {
				if ( ! empty( $range['invalid'] ) ) {
					status_header( 416 );
					header( 'Content-Range: bytes */' . $size );
					return;
				}
				status_header( 206 );
				header( 'Content-Range: bytes ' . $range['start'] . '-' . $range['end'] . '/' . $size );
				header( 'Content-Length: ' . $range['length'] );
				self::stream_range( $abs, $range['start'], $range['length'] );
				return;
			}
		}

		// Full 200.
		header( 'Content-Length: ' . $size );
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}
		readfile( $abs );
	}

	/**
	 * Parse single-range "Range: bytes=..." header.
	 * @return array|null  null = malformed/multi-range (caller does full 200);
	 *                     ['invalid'=>true] = unsatisfiable (caller does 416);
	 *                     ['start','end','length'] = satisfiable.
	 */
	private static function parse_range( string $header, int $filesize ): ?array {
		if ( ! preg_match( '/^bytes=(\d*)-(\d*)$/', trim( $header ), $m ) ) {
			return null;
		}
		$start_str = $m[1];
		$end_str   = $m[2];
		if ( $start_str === '' && $end_str === '' ) {
			return null;
		}
		if ( $start_str === '' ) {
			$n = (int) $end_str;
			if ( $n <= 0 ) {
				return [ 'invalid' => true ];
			}
			$start = max( 0, $filesize - $n );
			$end   = $filesize - 1;
		} else {
			$start = (int) $start_str;
			$end   = $end_str === '' ? $filesize - 1 : (int) $end_str;
		}
		if ( $start < 0 || $start >= $filesize || $end < $start ) {
			return [ 'invalid' => true ];
		}
		if ( $end >= $filesize ) {
			$end = $filesize - 1;
		}
		return [
			'start'  => $start,
			'end'    => $end,
			'length' => $end - $start + 1,
		];
	}

	private static function stream_range( string $abs, int $start, int $length ): void {
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}
		$fp = fopen( $abs, 'rb' );
		if ( ! $fp ) {
			return;
		}
		if ( fseek( $fp, $start ) !== 0 ) {
			fclose( $fp );
			return;
		}
		$chunk     = 8192;
		$remaining = $length;
		while ( $remaining > 0 && ! feof( $fp ) ) {
			if ( connection_aborted() ) {
				break;
			}
			$to_read = (int) min( $chunk, $remaining );
			$data    = fread( $fp, $to_read );
			if ( $data === false || $data === '' ) {
				break;
			}
			echo $data;
			flush();
			$remaining -= strlen( $data );
		}
		fclose( $fp );
	}

	private static function respond_404(): void {
		status_header( 404 );
		nocache_headers();
		header( 'Content-Type: text/plain; charset=utf-8' );
		echo '404 Not Found';
	}

	private static function respond_login_redirect(): void {
		$current = ( is_ssl() ? 'https://' : 'http://' ) . ( $_SERVER['HTTP_HOST'] ?? '' ) . ( $_SERVER['REQUEST_URI'] ?? '/' );
		wp_safe_redirect( wp_login_url( $current ), 302 );
	}

	private static function mime_for( string $abs ): string {
		$ft = wp_check_filetype( $abs );
		if ( ! empty( $ft['type'] ) ) {
			return $ft['type'];
		}
		return 'application/octet-stream';
	}
}
