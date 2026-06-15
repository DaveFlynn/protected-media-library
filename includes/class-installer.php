<?php
/**
 * Activation / deactivation.
 *
 * Design goals:
 *  - Be operational with zero manual server config.
 *  - On Apache: auto-write a root .htaccess rewrite to the fast-path handler.
 *  - On Nginx (or anywhere the rewrite can't be installed): fall back to the
 *    in-WP streamer. Same URL pattern, same access checks, slower.
 *  - The storage directory MUST live somewhere not directly reachable by URL.
 *    On Nginx, `.htaccess` is ignored, so a deny-all there is meaningless;
 *    instead we place storage outside the document root when we can. If we
 *    can't, we fall back to wp-content/ and run an HTTP probe — if the probe
 *    confirms a leak, a persistent admin warning is shown.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PML_Installer {

	const ROOT_HTACCESS_MARKER = 'Protected Media Library';
	const NOTICE_OPTION        = 'pml_install_notice';
	const DELIVERY_OPTION      = 'pml_delivery_mode'; // 'fast' | 'fallback'
	const STORAGE_DIR_OPTION   = 'pml_storage_dir';
	const LEAK_OPTION          = 'pml_storage_leaks';
	const PENDING_WEB_OPTION   = 'pml_pending_web_setup';

	public static function activate(): void {
		$results = [];

		// 1. Pick storage location (prefer outside docroot), persist it.
		$chosen = self::choose_storage_dir();
		update_option( self::STORAGE_DIR_OPTION, $chosen['path'] );
		$results['storage_chosen']  = $chosen['path'];
		$results['storage_outside'] = $chosen['outside_docroot'];

		$path = $chosen['path'];

		$results['storage_dir'] = self::ensure_storage_dir( $path );
		$results['secret']      = self::ensure_secret( $path );
		$results['storage_ht']  = self::write_storage_htaccess( $path );
		$results['handler_cfg'] = self::write_handler_config( $path );
		$results['root_ht']     = self::write_root_htaccess();

		// WP-CLI has no SERVER_SOFTWARE and no apache_get_modules(), so
		// is_apache() returns false even on Apache hosts. Redo the
		// server-dependent steps on the first real web request.
		if ( 'not_apache' === $results['root_ht'] && PHP_SAPI === 'cli' ) {
			update_option( self::PENDING_WEB_OPTION, 1 );
		}

		// 2. HTTP self-test: ensure the storage dir is NOT reachable via URL.
		$results['leak_check'] = self::self_test_leak( $path );
		update_option( self::LEAK_OPTION, ! empty( $results['leak_check']['leaks'] ) );

		// 3. Delivery mode: fast iff we wrote the root .htaccess AND fast handler is reachable.
		$delivery = ( $results['root_ht'] === true ) ? 'fast' : 'fallback';
		update_option( self::DELIVERY_OPTION, $delivery );

		// 4. Flush rules so /protected-media/... matches our rewrite tag.
		PML_Rewrites::register_rules();
		flush_rewrite_rules( false );

		update_option( self::NOTICE_OPTION, $results );
	}

	public static function deactivate(): void {
		self::remove_root_htaccess_block();
		flush_rewrite_rules( false );
		delete_option( self::NOTICE_OPTION );
		delete_option( self::PENDING_WEB_OPTION );
	}

	/**
	 * Finish server-dependent setup deferred from a CLI activation.
	 * Hooked on admin_init; runs once, in a real web context where
	 * is_apache() can actually detect the server.
	 */
	public static function maybe_finish_web_setup(): void {
		if ( PHP_SAPI === 'cli' || ! get_option( self::PENDING_WEB_OPTION ) ) {
			return;
		}
		delete_option( self::PENDING_WEB_OPTION );

		$results = get_option( self::NOTICE_OPTION, [] );
		if ( ! is_array( $results ) ) {
			$results = [];
		}

		$results['root_ht'] = self::write_root_htaccess();
		$delivery = ( $results['root_ht'] === true ) ? 'fast' : 'fallback';
		update_option( self::DELIVERY_OPTION, $delivery );

		$path = get_option( self::STORAGE_DIR_OPTION );
		if ( is_string( $path ) && $path !== '' ) {
			$results['leak_check'] = self::self_test_leak( $path );
			update_option( self::LEAK_OPTION, ! empty( $results['leak_check']['leaks'] ) );
		}

		update_option( self::NOTICE_OPTION, $results );
	}

	/* --------- storage location selection --------- */

	/**
	 * Pick the best storage path:
	 *   0. PML_STORAGE_PATH constant, if defined — explicit override for hosts
	 *      where the auto-choice is wrong (e.g. dirname(ABSPATH) is writable
	 *      but ephemeral, as in Docker-based dev environments).
	 *   1. dirname(ABSPATH) + suffix — outside docroot on most installs.
	 *   2. WP_CONTENT_DIR/protected-uploads — always reachable, may leak on Nginx.
	 *
	 * Returns ['path' => string, 'outside_docroot' => bool].
	 */
	public static function choose_storage_dir(): array {
		$docroot = self::normalized_docroot();

		if ( defined( 'PML_STORAGE_PATH' ) && PML_STORAGE_PATH !== '' ) {
			$cand = rtrim( wp_normalize_path( PML_STORAGE_PATH ), '/' );
			return [
				'path'            => $cand,
				'outside_docroot' => $docroot !== '' && strpos( $cand, $docroot . '/' ) !== 0,
			];
		}

		$suffix = '/pml-storage-' . substr( hash( 'sha256', wp_salt( 'auth' ) ), 0, 12 );

		$candidates = [];

		// Preferred: one level above ABSPATH.
		$above = rtrim( dirname( ABSPATH ), '/' ) . $suffix;
		$candidates[] = $above;

		// Fallback inside wp-content (always writable on a working WP install).
		$candidates[] = WP_CONTENT_DIR . '/protected-uploads';

		foreach ( $candidates as $cand ) {
			// Can we create / write here?
			if ( ! wp_mkdir_p( $cand ) ) {
				continue;
			}
			if ( ! is_writable( $cand ) ) {
				continue;
			}
			$outside = $docroot !== '' && strpos( wp_normalize_path( $cand ), $docroot . '/' ) !== 0;
			return [
				'path'            => $cand,
				'outside_docroot' => $outside,
			];
		}

		// Last resort — still return wp-content path even if mkdir failed; ensure_storage_dir will retry.
		return [
			'path'            => WP_CONTENT_DIR . '/protected-uploads',
			'outside_docroot' => false,
		];
	}

	private static function normalized_docroot(): string {
		$dr = $_SERVER['DOCUMENT_ROOT'] ?? '';
		if ( $dr === '' ) {
			return '';
		}
		$real = realpath( $dr );
		return $real ? wp_normalize_path( $real ) : wp_normalize_path( $dr );
	}

	/* --------- storage directory --------- */

	public static function ensure_storage_dir( string $path ): bool {
		if ( ! file_exists( $path ) ) {
			if ( ! wp_mkdir_p( $path ) ) {
				return false;
			}
		}
		// index.php to defeat directory listing on misconfigured servers.
		$index = $path . '/index.php';
		if ( ! file_exists( $index ) ) {
			@file_put_contents( $index, "<?php // Silence is golden.\n" );
		}
		return is_dir( $path ) && is_writable( $path );
	}

	/* --------- secret --------- */

	public static function ensure_secret( string $storage_path ): bool {
		$file = $storage_path . '/.pml-secret.php';
		if ( is_readable( $file ) ) {
			$existing = include $file;
			if ( is_string( $existing ) && strlen( $existing ) >= 32 ) {
				return true;
			}
		}
		$secret = bin2hex( random_bytes( 32 ) );
		$body   = "<?php\n// Auto-generated. Do not edit. Do not commit.\nreturn " . var_export( $secret, true ) . ";\n";
		$ok     = (bool) @file_put_contents( $file, $body, LOCK_EX );
		if ( $ok ) {
			@chmod( $file, 0600 );
		}
		return $ok;
	}

	/* --------- storage-dir .htaccess (only effective on Apache) --------- */

	public static function write_storage_htaccess( string $storage_path ): bool {
		$file = $storage_path . '/.htaccess';
		$body = <<<HT
# Protected Media Library — deny all direct web access (Apache only).
# On Nginx this file is ignored; storage should be outside the docroot.
<IfModule mod_authz_core.c>
    Require all denied
</IfModule>
<IfModule !mod_authz_core.c>
    Order allow,deny
    Deny from all
</IfModule>
<FilesMatch "\\.(php|phtml|phar|phps)\$">
    <IfModule mod_authz_core.c>
        Require all denied
    </IfModule>
    <IfModule !mod_authz_core.c>
        Order allow,deny
        Deny from all
    </IfModule>
</FilesMatch>
HT;
		return (bool) @file_put_contents( $file, $body );
	}

	/* --------- handler config (so standalone handler knows where storage lives) --------- */

	public static function write_handler_config( string $storage_path ): bool {
		$file = PML_DIR . 'handler-config.php';
		$body = "<?php\n// Auto-generated. Do not edit.\nreturn " . var_export(
			[
				'storage_dir'  => $storage_path,
				'secret_file'  => $storage_path . '/.pml-secret.php',
				'cookie_name'  => PML_COOKIE_NAME,
				'url_prefix'   => PML_URL_PREFIX,
			],
			true
		) . ";\n";
		return (bool) @file_put_contents( $file, $body );
	}

	/* --------- root .htaccess rewrite (fast path) --------- */

	public static function write_root_htaccess() {
		if ( ! self::is_apache() ) {
			return 'not_apache';
		}
		$ht = ABSPATH . '.htaccess';
		if ( file_exists( $ht ) && ! is_writable( $ht ) ) {
			return 'not_writable';
		}
		if ( ! file_exists( $ht ) && ! is_writable( dirname( $ht ) ) ) {
			return 'parent_not_writable';
		}

		$handler_url_path = self::handler_url_path();
		if ( $handler_url_path === '' ) {
			return 'no_handler_path';
		}

		$rules = self::root_htaccess_rules( $handler_url_path );

		if ( ! function_exists( 'insert_with_markers' ) ) {
			require_once ABSPATH . 'wp-admin/includes/misc.php';
		}
		$ok = insert_with_markers( $ht, self::ROOT_HTACCESS_MARKER, $rules );
		return $ok ? true : 'insert_failed';
	}

	public static function remove_root_htaccess_block(): void {
		$ht = ABSPATH . '.htaccess';
		if ( ! file_exists( $ht ) || ! is_writable( $ht ) ) {
			return;
		}
		if ( ! function_exists( 'insert_with_markers' ) ) {
			require_once ABSPATH . 'wp-admin/includes/misc.php';
		}
		insert_with_markers( $ht, self::ROOT_HTACCESS_MARKER, [] );
	}

	private static function root_htaccess_rules( string $handler_url_path ): array {
		$prefix = PML_URL_PREFIX;
		return [
			'<IfModule mod_rewrite.c>',
			'    RewriteEngine On',
			"    # Map /{$prefix}/<path> to the standalone handler.",
			"    RewriteRule ^{$prefix}/(.+)\$ {$handler_url_path}?pml=\$1 [QSA,L]",
			'</IfModule>',
		];
	}

	private static function handler_url_path(): string {
		$content_url = trailingslashit( content_url() );
		$parts       = wp_parse_url( $content_url );
		$path        = isset( $parts['path'] ) ? $parts['path'] : '/wp-content/';
		return $path . 'plugins/' . PML_SLUG . '/handler.php';
	}

	/* --------- HTTP leak self-test --------- */

	/**
	 * Drop a unique probe file inside storage, then HTTP-fetch the storage URL
	 * for that file (i.e. the *direct* URL, bypassing the handler). If it
	 * succeeds, the storage dir is publicly served and we record a leak.
	 *
	 * Only meaningful when storage lives inside the docroot; harmless otherwise.
	 */
	public static function self_test_leak( string $storage_path ): array {
		$result = [ 'leaks' => false, 'tested_url' => null, 'http' => null ];

		$docroot = self::normalized_docroot();
		$norm    = wp_normalize_path( $storage_path );
		if ( $docroot === '' || strpos( $norm, $docroot . '/' ) !== 0 ) {
			// Outside docroot: by definition not reachable.
			$result['tested_url'] = '(skipped: outside docroot)';
			return $result;
		}

		$rel = ltrim( substr( $norm, strlen( $docroot ) ), '/' );
		$probe_name = '.pml-probe-' . bin2hex( random_bytes( 8 ) );
		$probe_abs  = $storage_path . '/' . $probe_name;
		$probe_body = 'PML-PROBE-' . bin2hex( random_bytes( 16 ) );
		if ( ! @file_put_contents( $probe_abs, $probe_body ) ) {
			return $result;
		}
		try {
			$probe_url = home_url( '/' . $rel . '/' . $probe_name );
			$response  = wp_remote_get( $probe_url, [
				'timeout'   => 4,
				'sslverify' => false,
				'headers'   => [ 'User-Agent' => 'PML/probe' ],
			] );
			$result['tested_url'] = $probe_url;
			if ( is_wp_error( $response ) ) {
				$result['http'] = $response->get_error_code();
				return $result;
			}
			$code = wp_remote_retrieve_response_code( $response );
			$body = wp_remote_retrieve_body( $response );
			$result['http']  = $code;
			$result['leaks'] = ( $code === 200 && strpos( $body, $probe_body ) !== false );
		} finally {
			@unlink( $probe_abs );
		}
		return $result;
	}

	/* --------- environment --------- */

	public static function is_apache(): bool {
		if ( function_exists( 'apache_get_modules' ) ) {
			return true;
		}
		$srv = $_SERVER['SERVER_SOFTWARE'] ?? '';
		return stripos( $srv, 'apache' ) !== false || stripos( $srv, 'litespeed' ) !== false;
	}

	public static function is_nginx(): bool {
		$srv = $_SERVER['SERVER_SOFTWARE'] ?? '';
		return stripos( $srv, 'nginx' ) !== false;
	}

	public static function delivery_mode(): string {
		$mode = get_option( self::DELIVERY_OPTION, 'fallback' );
		return $mode === 'fast' ? 'fast' : 'fallback';
	}
}
