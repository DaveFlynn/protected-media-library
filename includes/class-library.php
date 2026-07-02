<?php
/**
 * Library separation + admin pages.
 *
 * - Default Media Library queries are filtered to exclude protected attachments.
 * - Our Protected Library page filters to only protected attachments.
 * - Adds menu items: "Protected Library" and "Add Protected Media File".
 *
 * Implementation uses the native media list table (upload.php) by passing a
 * mode query var that the library_query filter inspects. This way we get all
 * of WP's stock UX (grid/list, search, bulk actions, edit modal) for free.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PML_Library {

	const MODE_QV = 'pml_mode'; // 'protected' on our library page

	public static function init(): void {
		// Hide protected attachments from default media queries unless explicitly requested.
		add_action( 'pre_get_posts',                     [ __CLASS__, 'filter_pre_get_posts' ] );
		add_filter( 'ajax_query_attachments_args',       [ __CLASS__, 'filter_ajax_query' ] );

		// Admin menu.
		add_action( 'admin_menu',                        [ __CLASS__, 'register_menu' ] );
		// Highlight the right menu / submenu item when on the protected library page.
		add_filter( 'submenu_file',                      [ __CLASS__, 'highlight_submenu' ], 10, 2 );
		add_filter( 'parent_file',                       [ __CLASS__, 'override_parent_file' ] );


		// Hook the upload.php list view to inject our banner / nav while
		// in protected mode. Uploads on this page (drag-drop) are intentionally
		// NOT routed to protected storage — there's a dedicated uploader
		// page under "Protected Media" for that.
		add_action( 'load-upload.php',                   [ __CLASS__, 'maybe_mark_library_page' ] );
	}

	/* --------- query filtering --------- */

	public static function filter_pre_get_posts( WP_Query $q ): void {
		if ( ! is_admin() ) {
			return;
		}
		// AJAX (query-attachments etc.) is handled by ajax_query_attachments_args.
		// Don't double-filter — this hook would re-evaluate is_protected_mode()
		// against $_GET which is empty in a POST AJAX context and stomp the
		// already-correct meta_query.
		if ( wp_doing_ajax() ) {
			return;
		}
		if ( $q->get( 'post_type' ) !== 'attachment' ) {
			return;
		}
		$meta_query = (array) $q->get( 'meta_query' );

		if ( self::is_protected_mode() ) {
			$meta_query[] = [
				'key'   => PML_Storage::FLAG_META,
				'value' => '1',
			];
		} else {
			$meta_query[] = [
				'key'     => PML_Storage::FLAG_META,
				'compare' => 'NOT EXISTS',
			];
		}
		$q->set( 'meta_query', $meta_query );
	}

	public static function filter_ajax_query( array $args ): array {
		$is_protected_ctx = self::ajax_request_is_protected();
		$meta_query       = $args['meta_query'] ?? [];
		if ( $is_protected_ctx ) {
			$meta_query[] = [ 'key' => PML_Storage::FLAG_META, 'value' => '1' ];
		} else {
			$meta_query[] = [ 'key' => PML_Storage::FLAG_META, 'compare' => 'NOT EXISTS' ];
		}
		$args['meta_query'] = $meta_query;
		return $args;
	}

	/**
	 * Decide whether the current AJAX media query is for the protected library.
	 * Checks: (a) explicit POST param, (b) Referer URL containing pml_mode.
	 * (b) is the workhorse — the grid view JS doesn't know about our param.
	 */
	private static function ajax_request_is_protected(): bool {
		if ( ! empty( $_POST['query']['pml_mode'] ) && $_POST['query']['pml_mode'] === 'protected' ) {
			return true;
		}
		$ref = wp_get_referer();
		if ( $ref && strpos( $ref, 'pml_mode=protected' ) !== false ) {
			return true;
		}
		return false;
	}

	public static function is_protected_mode(): bool {
		return ! empty( $_GET[ self::MODE_QV ] ) && $_GET[ self::MODE_QV ] === 'protected';
	}

	/* --------- menu --------- */

	const MENU_SLUG     = 'pml-protected-media';
	const ADD_PAGE_SLUG = 'pml-add-new';

	public static function register_menu(): void {
		// Top-level "Protected Media" — a sibling of the standard "Media" menu.
		// The parent's own page just redirects to the Library (upload.php?pml_mode=protected)
		// — handled by the load-* action below.
		add_menu_page(
			__( 'Protected Media', 'protected-media-library' ),
			__( 'Protected Media', 'protected-media-library' ),
			'upload_files',
			self::MENU_SLUG,
			[ __CLASS__, 'render_library_redirect' ],
			'dashicons-lock',
			11 // just under Media (which is 10).
		);

		// First submenu: Library. Same slug as parent so clicking the
		// top-level label lands here. The "default" submenu in WP shares
		// the parent slug.
		add_submenu_page(
			self::MENU_SLUG,
			__( 'Protected Library', 'protected-media-library' ),
			__( 'Library', 'protected-media-library' ),
			'upload_files',
			self::MENU_SLUG,
			[ __CLASS__, 'render_library_redirect' ]
		);

		// Second submenu: Add New File. Custom page (rendered by us).
		add_submenu_page(
			self::MENU_SLUG,
			__( 'Add Protected Media File', 'protected-media-library' ),
			__( 'Add New File', 'protected-media-library' ),
			'upload_files',
			self::ADD_PAGE_SLUG,
			[ 'PML_Add_Page', 'render' ]
		);

		// Redirect the parent slug load to the existing protected library
		// view at upload.php?pml_mode=protected.
		add_action( 'load-toplevel_page_' . self::MENU_SLUG, [ __CLASS__, 'redirect_to_library' ] );
	}

	public static function render_library_redirect(): void {
		// Output never seen — load_* hook redirects before render.
	}

	public static function redirect_to_library(): void {
		wp_safe_redirect( admin_url( 'upload.php?' . self::MODE_QV . '=protected' ) );
		exit;
	}

	/**
	 * Keep the "Protected Media" top-level item highlighted when the user is
	 * on the existing upload.php?pml_mode=protected library view.
	 */
	public static function highlight_submenu( $submenu_file, $parent_file ) {
		if ( ! self::is_protected_mode() ) {
			return $submenu_file;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && $screen->base === 'upload' ) {
			// Mark the Library submenu as active.
			return self::MENU_SLUG;
		}
		return $submenu_file;
	}

	/**
	 * Highlight the top-level "Protected Media" menu item when we're on the
	 * protected library page (which lives at /wp-admin/upload.php — WP would
	 * otherwise highlight "Media").
	 */
	public static function override_parent_file( $parent_file ) {
		if ( self::is_protected_mode() ) {
			$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
			if ( $screen && $screen->base === 'upload' ) {
				return self::MENU_SLUG;
			}
		}
		return $parent_file;
	}

	/* --------- page hooks --------- */

	/**
	 * On the standard upload.php page when ?pml_mode=protected is present,
	 * inject a banner and a header note so the user is unambiguous about which
	 * library they're in.
	 */
	public static function maybe_mark_library_page(): void {
		if ( ! self::is_protected_mode() ) {
			return;
		}
		add_filter( 'admin_title', static fn( $t ) => __( 'Protected Library', 'protected-media-library' ) . ' — ' . $t );
		add_action( 'all_admin_notices',          [ __CLASS__, 'render_protected_banner' ], 1 );
		add_action( 'admin_print_footer_scripts', [ __CLASS__, 'inject_protected_mode_script' ] );
	}

	/**
	 * On the Protected Library page, glue `pml_mode=protected` to:
	 *   - every <a href> pointing at upload.php (so the grid/list toggle
	 *     and any view-switcher links don't drop the protected context)
	 *   - the wp.media.model.Query AJAX args (so the grid view's
	 *     `query-attachments` AJAX carries the flag explicitly, not via
	 *     referer guessing)
	 */
	public static function inject_protected_mode_script(): void {
		$add_url   = admin_url( 'admin.php?page=' . self::ADD_PAGE_SLUG );
		$add_label = __( 'Add Protected Media File', 'protected-media-library' );
		?>
		<script>
		(function () {
			var MODE_KEY = 'pml_mode';
			var MODE_VAL = 'protected';
			var ADD_URL   = <?php echo wp_json_encode( $add_url ); ?>;
			var ADD_LABEL = <?php echo wp_json_encode( $add_label ); ?>;

			// Only touch links that are the grid/list view-switcher — they're
			// the ones that drop our query var while staying on this library.
			// Everything else (sidebar "Library", "View Public Library", etc.)
			// must keep its original target.
			function rewriteLinks( root ) {
				if ( ! root || ! root.querySelectorAll ) { return; }
				root.querySelectorAll( 'a[href*="upload.php"][href*="mode="]' ).forEach( function ( a ) {
					var href = a.getAttribute( 'href' );
					if ( ! href || href.indexOf( MODE_KEY + '=' ) !== -1 ) { return; }
					var sep = href.indexOf( '?' ) === -1 ? '?' : '&';
					a.setAttribute( 'href', href + sep + MODE_KEY + '=' + MODE_VAL );
				} );
			}

			// Core's "Add Media File" button on upload.php is hardcoded to
			// media-new.php (the PUBLIC uploader) with no server-side filter.
			// On the Protected Library page that's a trap — it silently uploads
			// to public storage. Repoint it at our protected uploader instead.
			function rewriteAddButton( root ) {
				if ( ! root || ! root.querySelectorAll ) { return; }
				root.querySelectorAll( '.wrap .page-title-action' ).forEach( function ( a ) {
					if ( a.getAttribute( 'data-pml-repointed' ) ) { return; }
					a.setAttribute( 'href', ADD_URL );
					a.textContent = ADD_LABEL;
					a.setAttribute( 'data-pml-repointed', '1' );
				} );
			}

			// Core's media grid view binds its own drag-and-drop uploader to
			// the window/document (Plupload, with no protected-storage routing
			// and no visual feedback — it silently uploads to the PUBLIC
			// library). We previously tried and abandoned patching that
			// pipeline directly (see gotchas.md "Plupload nonce" note /
			// class-add-page.php docblock) because timing into
			// _wpPluploadSettings is fragile. Simplest safe fix: capture the
			// drag/drop events on `document` BEFORE core's bubble-phase
			// listeners run, and swallow them. Never touches Plupload
			// internals — just wins the race for the event.
			function blockNativeDropzone( e ) {
				e.preventDefault();
				e.stopImmediatePropagation();
				if ( e.type === 'drop' ) {
					window.alert( <?php echo wp_json_encode( __( 'Drag-and-drop upload is disabled on the Protected Library. Use "Add New File" to upload protected media.', 'protected-media-library' ) ); ?> );
				}
			}
			[ 'dragenter', 'dragover', 'drop' ].forEach( function ( evt ) {
				document.addEventListener( evt, blockNativeDropzone, true );
			} );

			document.addEventListener( 'DOMContentLoaded', function () {
				rewriteLinks( document );
				rewriteAddButton( document );

				// wp.media.model.Query.prototype.sync is what actually issues
				// the `query-attachments` AJAX. It serializes this.args into
				// the request — so we inject pml_mode there. (Attachments
				// doesn't override sync; patching it was a dead path.)
				if ( window.wp && wp.media && wp.media.model && wp.media.model.Query ) {
					var qproto   = wp.media.model.Query.prototype;
					var origSync = qproto.sync;
					qproto.sync = function ( method, model, options ) {
						this.args = this.args || {};
						this.args[ MODE_KEY ] = MODE_VAL;
						return origSync.call( this, method, model, options );
					};
				}

				// Re-rewrite view-switcher links when the media UI swaps DOM.
				var observer = new MutationObserver( function ( mutations ) {
					mutations.forEach( function ( m ) {
						m.addedNodes.forEach( function ( n ) {
							if ( n.nodeType === 1 ) { rewriteLinks( n ); rewriteAddButton( n ); }
						} );
					} );
				} );
				observer.observe( document.body, { childList: true, subtree: true } );
			} );
		}());
		</script>
		<?php
	}

	public static function render_protected_banner(): void {
		echo '<div class="pml-banner pml-banner--protected" role="status">'
			. '<strong>' . esc_html__( 'Protected Library', 'protected-media-library' ) . '</strong> '
			. esc_html__( 'You are viewing protected media only. Files here are stored outside the public uploads directory and require an authenticated session to view.', 'protected-media-library' )
			. ' <a href="' . esc_url( admin_url( 'upload.php' ) ) . '">' . esc_html__( '← View Public Library', 'protected-media-library' ) . '</a>'
			. '</div>';
	}

}
