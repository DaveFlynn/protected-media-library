<?php
/**
 * Standalone fast-path handler for protected media.
 *
 * This file is intentionally NOT booting WordPress. It is reached by a root
 * .htaccess rewrite that maps /protected-media/<path> to this script with the
 * requested path in ?pml=<path>. Cold start cost is just this file + a
 * filesystem read of the secret + an HMAC verify + a readfile() (or sendfile).
 *
 * If anything in this file fails to behave, the in-WP fallback streamer takes
 * over on the next request — the cookie format and access semantics are shared.
 */

declare( strict_types = 1 );

// --- locate roots without WP ---
// Config file written on activation. Carries the storage path (which may be
// outside the docroot) so we don't have to guess.
//
// Lives in wp-content/, NOT this plugin's own directory: WordPress's plugin
// updater deletes and replaces this whole directory on every update (and
// never re-fires the activation hook), so anything generated at install
// time that must survive an update can't live in __DIR__. This file assumes
// the standard wp-content/plugins/<slug>/handler.php layout to find it
// without booting WP.
$cfg_file = dirname( __DIR__, 2 ) . '/pml-handler-config.php';
if ( ! is_readable( $cfg_file ) ) {
	// Pre-0.1.4 installs (or a request that raced an in-progress update
	// before the admin_init repair hook ran) may still have it here.
	$cfg_file = __DIR__ . '/handler-config.php';
}
if ( ! is_readable( $cfg_file ) ) {
	pml_handler_404();
}
$cfg = include $cfg_file;
if ( ! is_array( $cfg ) || empty( $cfg['storage_dir'] ) || empty( $cfg['secret_file'] ) ) {
	pml_handler_404();
}
$storage_dir = (string) $cfg['storage_dir'];
$secret_file = (string) $cfg['secret_file'];
$cookie_name = (string) ( $cfg['cookie_name'] ?? 'wp_protected_media' );

// --- read the requested path ---
$requested = isset( $_GET['pml'] ) ? (string) $_GET['pml'] : '';
if ( $requested === '' || strpos( $requested, "\0" ) !== false ) {
	pml_handler_404();
}
$requested = rawurldecode( $requested );
$requested = ltrim( $requested, '/' );

// --- read secret ---
if ( ! is_readable( $secret_file ) ) {
	// Nothing we can do without the secret; bail to login redirect.
	pml_handler_login_redirect( $requested );
}
$secret = include $secret_file;
if ( ! is_string( $secret ) || $secret === '' ) {
	pml_handler_login_redirect( $requested );
}

// --- verify cookie ---
$cookie_val  = $_COOKIE[ $cookie_name ] ?? '';
$payload     = pml_handler_verify( (string) $cookie_val, $secret );
if ( $payload === null ) {
	pml_handler_login_redirect( $requested );
}

// --- safe path resolution ---
$abs = pml_handler_safe_path( $storage_dir, $requested );
if ( $abs === null ) {
	pml_handler_404();
}

// --- serve ---
pml_handler_serve( $abs );
exit;

// ---------------------------------------------------------------------------

function pml_handler_verify( string $cookie_value, string $secret ): ?array {
	if ( $cookie_value === '' ) {
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
	if ( $level === '' ) {
		return null;
	}
	return [ 'user_id' => (int) $user_id, 'exp' => (int) $exp, 'level' => $level ];
}

function pml_handler_safe_path( string $storage_dir, string $rel ): ?string {
	$base = rtrim( str_replace( '\\', '/', $storage_dir ), '/' );
	$abs  = $base . '/' . str_replace( '\\', '/', $rel );

	if ( ! file_exists( $abs ) || ! is_file( $abs ) ) {
		return null;
	}
	$real_base = realpath( $base );
	$real_abs  = realpath( $abs );
	if ( $real_base === false || $real_abs === false ) {
		return null;
	}
	if ( strpos( $real_abs, $real_base . DIRECTORY_SEPARATOR ) !== 0 && $real_abs !== $real_base ) {
		return null;
	}
	// Hard-block executable types even if they somehow landed in storage.
	$ext = strtolower( pathinfo( $real_abs, PATHINFO_EXTENSION ) );
	if ( in_array( $ext, [ 'php', 'phtml', 'phar', 'phps', 'pl', 'py', 'sh', 'cgi' ], true ) ) {
		return null;
	}
	return $real_abs;
}

function pml_handler_serve( string $abs ): void {
	$size  = filesize( $abs );
	$mtime = filemtime( $abs );
	$etag  = '"' . md5( $abs . '|' . $size . '|' . $mtime ) . '"';
	$mime  = pml_handler_mime( $abs );
	$proto = $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.1';

	header( 'X-Content-Type-Options: nosniff' );
	header( 'Cache-Control: private, max-age=300' );
	header( 'ETag: ' . $etag );
	header( 'Last-Modified: ' . gmdate( 'D, d M Y H:i:s', $mtime ) . ' GMT' );
	header( 'Accept-Ranges: bytes' );

	// Conditional GET: 304 wins over Range per RFC 7233.
	$if_none_match = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';
	$if_mod_since  = $_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? '';
	if ( $if_none_match === $etag || ( $if_mod_since && strtotime( $if_mod_since ) >= $mtime ) ) {
		header( $proto . ' 304 Not Modified', true, 304 );
		return;
	}

	header( 'Content-Type: ' . $mime );
	header( 'X-PML-Delivery: fast' );

	// X-Sendfile-style off-loading handles Range natively in the web server.
	if ( function_exists( 'apache_get_modules' ) && in_array( 'mod_xsendfile', apache_get_modules(), true ) ) {
		header( 'X-Sendfile: ' . $abs );
		return;
	}

	// Range request? Parse and respond 206 or 416.
	$range_header = $_SERVER['HTTP_RANGE'] ?? '';
	if ( $range_header !== '' ) {
		$range = pml_handler_parse_range( $range_header, $size );
		if ( $range === null ) {
			// Malformed or unsupported (multi-range). Fall through to full 200.
		} elseif ( ! empty( $range['invalid'] ) ) {
			header( $proto . ' 416 Range Not Satisfiable', true, 416 );
			header( 'Content-Range: bytes */' . $size );
			return;
		} else {
			header( $proto . ' 206 Partial Content', true, 206 );
			header( 'Content-Range: bytes ' . $range['start'] . '-' . $range['end'] . '/' . $size );
			header( 'Content-Length: ' . $range['length'] );
			pml_handler_stream_range( $abs, $range['start'], $range['length'] );
			return;
		}
	}

	// Full file (200).
	header( 'Content-Length: ' . $size );
	readfile( $abs );
}

/**
 * Parse a single-range "Range: bytes=..." header. Returns:
 *   null                — header malformed or multi-range (caller falls back to 200)
 *   ['invalid' => true] — well-formed but unsatisfiable (respond 416)
 *   ['start','end','length']  — satisfiable range
 */
function pml_handler_parse_range( string $header, int $filesize ): ?array {
	if ( ! preg_match( '/^bytes=(\d*)-(\d*)$/', trim( $header ), $m ) ) {
		return null;
	}
	$start_str = $m[1];
	$end_str   = $m[2];

	if ( $start_str === '' && $end_str === '' ) {
		return null;
	}

	if ( $start_str === '' ) {
		// Suffix-byte range: "bytes=-N" → last N bytes.
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

/**
 * Stream a byte range from the file, chunked so memory stays bounded even on
 * GB-scale media. Stops promptly if the client disconnects (audio/video
 * players hop around with Range and abandon in-flight requests).
 */
function pml_handler_stream_range( string $abs, int $start, int $length ): void {
	// Disable PHP output buffers so chunks actually flush.
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

function pml_handler_mime( string $abs ): string {
	$ext = strtolower( pathinfo( $abs, PATHINFO_EXTENSION ) );
	static $map = [
		'jpg'  => 'image/jpeg',
		'jpeg' => 'image/jpeg',
		'png'  => 'image/png',
		'gif'  => 'image/gif',
		'webp' => 'image/webp',
		'avif' => 'image/avif',
		'svg'  => 'image/svg+xml',
		'pdf'  => 'application/pdf',
		'mp4'  => 'video/mp4',
		'webm' => 'video/webm',
		'mov'  => 'video/quicktime',
		'mp3'  => 'audio/mpeg',
		'm4a'  => 'audio/mp4',
		'wav'  => 'audio/wav',
		'ogg'  => 'audio/ogg',
		'txt'  => 'text/plain; charset=utf-8',
		'csv'  => 'text/csv; charset=utf-8',
		'json' => 'application/json',
		'zip'  => 'application/zip',
		'doc'  => 'application/msword',
		'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
		'xls'  => 'application/vnd.ms-excel',
		'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
	];
	return $map[ $ext ] ?? 'application/octet-stream';
}

function pml_handler_404(): void {
	header( $_SERVER['SERVER_PROTOCOL'] . ' 404 Not Found', true, 404 );
	header( 'Cache-Control: no-store' );
	header( 'Content-Type: text/plain; charset=utf-8' );
	echo '404 Not Found';
	exit;
}

function pml_handler_login_redirect( string $requested ): void {
	// Reconstruct the originally requested public URL.
	$proto = ! empty( $_SERVER['HTTPS'] ) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http';
	$host  = $_SERVER['HTTP_HOST'] ?? 'localhost';
	$orig  = $proto . '://' . $host . '/protected-media/' . $requested;

	// Best-effort login URL: assume standard /wp-login.php at the site root.
	// We can't call wp_login_url() without booting WP — this is the trade-off.
	$login = $proto . '://' . $host . '/wp-login.php?redirect_to=' . rawurlencode( $orig );

	header( 'Location: ' . $login, true, 302 );
	header( 'Cache-Control: no-store' );
	exit;
}
