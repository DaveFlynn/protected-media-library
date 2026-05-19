<?php
/**
 * Cosmetic admin integration: badges in list/grid views, indicator in the
 * attachment edit screen, activation notices, and the cross-nav button on the
 * standard library page.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PML_Admin {

	public static function init(): void {
		add_action( 'admin_enqueue_scripts',         [ __CLASS__, 'enqueue' ] );

		// List view badge column.
		add_filter( 'manage_media_columns',          [ __CLASS__, 'add_column' ] );
		add_action( 'manage_media_custom_column',    [ __CLASS__, 'render_column' ], 10, 2 );

		// Attachment fields (edit modal + edit screen).
		add_filter( 'attachment_fields_to_edit',     [ __CLASS__, 'attachment_field' ], 10, 2 );

		// Standard library cross-nav.
		add_action( 'all_admin_notices',             [ __CLASS__, 'maybe_render_public_lib_crossnav' ] );

		// Activation notice.
		add_action( 'admin_notices',                 [ __CLASS__, 'maybe_render_activation_notice' ] );
		// Persistent leak warning.
		add_action( 'admin_notices',                 [ __CLASS__, 'maybe_render_leak_warning' ] );

		// Row meta on plugins page.
		add_filter( 'plugin_action_links_' . plugin_basename( PML_FILE ), [ __CLASS__, 'plugin_action_links' ] );
	}

	public static function enqueue( string $hook ): void {
		wp_enqueue_style( 'pml-admin', PML_URL . 'assets/css/admin.css', [], PML_VERSION );
	}

	public static function add_column( array $cols ): array {
		$new = [];
		foreach ( $cols as $k => $v ) {
			$new[ $k ] = $v;
			if ( $k === 'title' ) {
				$new['pml_protected'] = __( 'Protected', 'protected-media-library' );
				// Size column is only useful in Protected Library mode — adding
				// it on the public library would duplicate WP's own future plans
				// and confuse users on shared screens.
				if ( PML_Library::is_protected_mode() ) {
					$new['pml_size'] = __( 'Size', 'protected-media-library' );
				}
			}
		}
		return $new;
	}

	public static function render_column( string $col, int $post_id ): void {
		if ( $col === 'pml_protected' ) {
			if ( PML_Storage::is_protected( $post_id ) ) {
				echo '<span class="pml-badge pml-badge--protected" title="' . esc_attr__( 'Protected media — served via authenticated handler.', 'protected-media-library' ) . '">🔒 ' . esc_html__( 'Protected', 'protected-media-library' ) . '</span>';
			} else {
				echo '<span class="pml-badge pml-badge--public">' . esc_html__( 'Public', 'protected-media-library' ) . '</span>';
			}
			return;
		}
		if ( $col === 'pml_size' ) {
			$bytes = self::attachment_size_bytes( $post_id );
			echo $bytes > 0
				? esc_html( size_format( $bytes, 1 ) )
				: '<span aria-hidden="true">—</span>';
			return;
		}
	}

	/**
	 * File size in bytes for an attachment, looking in protected storage for
	 * protected attachments (where get_attached_file's path is wrong because
	 * WP's wp_upload_dir() doesn't know about our parallel root).
	 */
	private static function attachment_size_bytes( int $post_id ): int {
		$rel = (string) get_post_meta( $post_id, '_wp_attached_file', true );
		if ( $rel === '' ) {
			return 0;
		}
		if ( PML_Storage::is_protected( $post_id ) ) {
			$path = rtrim( PML_Storage::base_dir(), '/' ) . '/' . ltrim( $rel, '/' );
		} else {
			$path = (string) get_attached_file( $post_id );
		}
		return ( $path && is_readable( $path ) ) ? (int) filesize( $path ) : 0;
	}

	public static function attachment_field( array $fields, WP_Post $post ): array {
		$is_protected = PML_Storage::is_protected( (int) $post->ID );
		$rule         = PML_Access::rule_for( (int) $post->ID );

		$html  = '<div class="pml-attachment-status pml-attachment-status--' . ( $is_protected ? 'protected' : 'public' ) . '">';
		$html .= $is_protected
			? '<strong>🔒 ' . esc_html__( 'Protected', 'protected-media-library' ) . '</strong><br>'
			  . esc_html__( 'Access:', 'protected-media-library' ) . ' <code>' . esc_html( $rule ) . '</code><br>'
			  . '<em>' . esc_html__( 'Protected/public state is set at upload and cannot be changed.', 'protected-media-library' ) . '</em>'
			: '<strong>' . esc_html__( 'Public', 'protected-media-library' ) . '</strong>';
		$html .= '</div>';

		$fields['pml_status'] = [
			'label' => __( 'Library', 'protected-media-library' ),
			'input' => 'html',
			'html'  => $html,
		];
		return $fields;
	}

	public static function maybe_render_public_lib_crossnav(): void {
		$screen = get_current_screen();
		if ( ! $screen || $screen->base !== 'upload' || PML_Library::is_protected_mode() ) {
			return;
		}
		echo '<div class="pml-banner pml-banner--public" role="status">'
			. '<a href="' . esc_url( admin_url( 'upload.php?' . PML_Library::MODE_QV . '=protected' ) ) . '">'
			. esc_html__( 'View Protected Library →', 'protected-media-library' )
			. '</a></div>';
	}

	public static function maybe_render_activation_notice(): void {
		$data = get_option( PML_Installer::NOTICE_OPTION );
		if ( ! is_array( $data ) ) {
			return;
		}
		// Show once.
		delete_option( PML_Installer::NOTICE_OPTION );

		$mode = PML_Installer::delivery_mode();
		echo '<div class="notice notice-' . ( $mode === 'fast' ? 'success' : 'warning' ) . ' is-dismissible">';
		echo '<p><strong>Protected Media Library</strong> — ';
		if ( $mode === 'fast' ) {
			esc_html_e( 'Active. Fast-path delivery is enabled (standalone handler, no WordPress bootstrap per file).', 'protected-media-library' );
		} else {
			esc_html_e( 'Active. Fast-path delivery could not be installed automatically; falling back to WordPress-routed delivery (still secure, slower for pages with many protected files).', 'protected-media-library' );
			echo ' ';
			if ( PML_Installer::is_nginx() ) {
				esc_html_e( 'Detected Nginx — the plugin works without changes; for maximum speed, add the documented location block.', 'protected-media-library' );
			} elseif ( $data['root_ht'] !== true ) {
				esc_html_e( 'Root .htaccess could not be written. Check file permissions or contact your host.', 'protected-media-library' );
			}
		}
		echo '</p></div>';
	}

	public static function maybe_render_leak_warning(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! get_option( PML_Installer::LEAK_OPTION ) ) {
			return;
		}
		echo '<div class="notice notice-error">';
		echo '<p><strong>Protected Media Library — files are NOT actually protected.</strong> ';
		echo esc_html__( 'The plugin detected that the storage directory is publicly reachable via a direct URL. This means anyone who guesses or learns a filename can download protected files without authentication.', 'protected-media-library' );
		echo '</p><p>';
		echo esc_html__( 'This happens on Nginx (and other servers that ignore .htaccess) when the storage directory cannot be placed outside the document root. To fix:', 'protected-media-library' );
		echo '</p><ol>';
		echo '<li>' . esc_html__( 'Deactivate the plugin.', 'protected-media-library' ) . '</li>';
		echo '<li>' . esc_html__( 'Ensure the directory ONE LEVEL ABOVE your WordPress install is writable by PHP, then reactivate.', 'protected-media-library' ) . '</li>';
		echo '<li>' . esc_html__( 'Or, add a server rule that denies direct access to the storage path (Nginx example available in the plugin readme).', 'protected-media-library' ) . '</li>';
		echo '</ol></div>';
	}

	public static function plugin_action_links( array $links ): array {
		$links[] = '<a href="' . esc_url( admin_url( 'upload.php?' . PML_Library::MODE_QV . '=protected' ) ) . '">' . esc_html__( 'Protected Library', 'protected-media-library' ) . '</a>';
		return $links;
	}
}
