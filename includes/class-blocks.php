<?php
/**
 * Gutenberg block + REST endpoints for protected media.
 *
 * Editor strategy: separate blocks (Protected Image first; File/Gallery later)
 * with their own picker and uploader, owned end-to-end by this plugin. We do
 * NOT extend wp.media frames — those are version-fragile and ambiguous from
 * the user's perspective ("did Upload go to public or protected?"). A
 * dedicated block makes the answer obvious.
 *
 * REST routes:
 *   GET  /wp-json/pml/v1/library?page=&search=&per_page=  list protected images
 *   POST /wp-json/pml/v1/upload   multipart: file=<binary>  protected upload
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PML_Blocks {

	const REST_NS = 'pml/v1';

	public static function init(): void {
		add_action( 'init',          [ __CLASS__, 'register_block' ] );
		add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
	}

	public static function register_block(): void {
		// Shared picker assets — must be registered BEFORE the blocks so each
		// block can declare `pml-picker` as a dependency (script via
		// index.asset.php, style via block.json editorStyle).
		wp_register_script(
			'pml-picker',
			PML_URL . 'assets/js/picker.js',
			[ 'wp-element', 'wp-components', 'wp-i18n', 'wp-api-fetch', 'wp-data' ],
			PML_VERSION,
			true
		);
		wp_register_style(
			'pml-picker',
			PML_URL . 'assets/css/picker.css',
			[],
			PML_VERSION
		);

		register_block_type( PML_DIR . 'blocks/protected-image' );
		register_block_type( PML_DIR . 'blocks/protected-file' );
		register_block_type( PML_DIR . 'blocks/protected-audio' );
		register_block_type( PML_DIR . 'blocks/protected-video' );
		register_block_type( PML_DIR . 'blocks/protected-gallery' );
	}

	public static function register_routes(): void {
		register_rest_route( self::REST_NS, '/library', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'rest_library' ],
			'permission_callback' => [ __CLASS__, 'can_use' ],
			'args'                => [
				'page'     => [ 'type' => 'integer', 'default' => 1, 'minimum' => 1 ],
				'per_page' => [ 'type' => 'integer', 'default' => 24, 'minimum' => 1, 'maximum' => 100 ],
				'search'   => [ 'type' => 'string', 'default' => '' ],
				'mime'     => [ 'type' => 'string', 'default' => 'image' ],
				'ids'      => [ 'type' => 'string', 'default' => '' ],
			],
		] );
		register_rest_route( self::REST_NS, '/upload', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'rest_upload' ],
			'permission_callback' => [ __CLASS__, 'can_use' ],
		] );
		register_rest_route( self::REST_NS, '/attach', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'rest_attach' ],
			'permission_callback' => [ __CLASS__, 'can_use' ],
			'args'                => [
				'ids'     => [ 'type' => 'string',  'required' => true ],
				'post_id' => [ 'type' => 'integer', 'required' => true, 'minimum' => 1 ],
			],
		] );
	}

	public static function can_use(): bool {
		return current_user_can( 'upload_files' );
	}

	/* --------- /library --------- */

	public static function rest_library( WP_REST_Request $req ) {
		$page     = max( 1, (int) $req->get_param( 'page' ) );
		$per_page = (int) $req->get_param( 'per_page' );
		$search   = (string) $req->get_param( 'search' );
		$mime     = (string) $req->get_param( 'mime' );
		$ids_raw  = (string) $req->get_param( 'ids' );

		$args = [
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'meta_query'     => [ [
				'key'   => PML_Storage::FLAG_META,
				'value' => '1',
			] ],
		];

		// `ids` short-circuits paging/search: caller wants specific attachments.
		// Order results to match the caller's input order so galleries render
		// in the order the editor picked.
		$id_order = [];
		if ( $ids_raw !== '' ) {
			$id_order = array_values( array_filter( array_map( 'intval', explode( ',', $ids_raw ) ) ) );
			if ( ! empty( $id_order ) ) {
				$args['post__in']       = $id_order;
				$args['orderby']        = 'post__in';
				$args['posts_per_page'] = count( $id_order );
				$args['paged']          = 1;
			}
		}

		if ( $search !== '' ) {
			$args['s'] = $search;
		}
		if ( $mime !== '' ) {
			$args['post_mime_type'] = $mime;
		}

		$q     = new WP_Query( $args );
		$items = array_map( [ __CLASS__, 'serialize_attachment' ], $q->posts );

		return [
			'items'      => $items,
			'total'      => (int) $q->found_posts,
			'totalPages' => (int) $q->max_num_pages,
			'page'       => $page,
		];
	}

	/* --------- /upload --------- */

	public static function rest_upload( WP_REST_Request $req ) {
		// If post_max_size was exceeded, PHP discards $_POST and $_FILES entirely
		// before we run. Detect by comparing CONTENT_LENGTH against the limit and
		// surface a clear error instead of a confusing "no file provided."
		$content_length = isset( $_SERVER['CONTENT_LENGTH'] ) ? (int) $_SERVER['CONTENT_LENGTH'] : 0;
		$post_max       = self::ini_bytes( ini_get( 'post_max_size' ) );
		if ( empty( $_FILES['file'] ) && $content_length > 0 && $post_max > 0 && $content_length > $post_max ) {
			return new WP_Error(
				'pml_post_too_large',
				sprintf(
					/* translators: 1: payload size, 2: post_max_size limit */
					__( 'Upload (%1$s) exceeds the server\'s post_max_size limit (%2$s). Raise post_max_size and upload_max_filesize in php.ini.', 'protected-media-library' ),
					size_format( $content_length ),
					size_format( $post_max )
				),
				[ 'status' => 413 ]
			);
		}

		if ( empty( $_FILES['file'] ) ) {
			return new WP_Error( 'pml_no_file', __( 'No file provided.', 'protected-media-library' ), [ 'status' => 400 ] );
		}

		// Map PHP upload error codes to clean messages. Otherwise WP's media_handle_upload
		// surfaces them as generic "Sorry, this file type is not permitted" or worse.
		$err = (int) ( $_FILES['file']['error'] ?? UPLOAD_ERR_OK );
		if ( $err !== UPLOAD_ERR_OK ) {
			$ini_max    = self::ini_bytes( ini_get( 'upload_max_filesize' ) );
			$msg_by_err = [
				UPLOAD_ERR_INI_SIZE   => sprintf( __( 'File exceeds upload_max_filesize (%s). Raise it in php.ini.', 'protected-media-library' ), size_format( $ini_max ) ),
				UPLOAD_ERR_FORM_SIZE  => __( 'File exceeds the form\'s MAX_FILE_SIZE.', 'protected-media-library' ),
				UPLOAD_ERR_PARTIAL    => __( 'Upload was interrupted before it finished.', 'protected-media-library' ),
				UPLOAD_ERR_NO_FILE    => __( 'No file received by the server.', 'protected-media-library' ),
				UPLOAD_ERR_NO_TMP_DIR => __( 'Server is missing a temp directory for uploads.', 'protected-media-library' ),
				UPLOAD_ERR_CANT_WRITE => __( 'Server failed to write the upload to disk.', 'protected-media-library' ),
				UPLOAD_ERR_EXTENSION  => __( 'A PHP extension blocked the upload.', 'protected-media-library' ),
			];
			return new WP_Error(
				'pml_upload_error',
				$msg_by_err[ $err ] ?? __( 'Upload failed.', 'protected-media-library' ),
				[ 'status' => 413 ]
			);
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		// Flip storage routing for THIS request only — storage layer reads this
		// flag from its prefilter / upload_dir hook.
		PML_Storage::force_protected_for_request();

		// If the caller (block editor) tells us which post the upload belongs
		// to, attach it. Otherwise it lands as "(Unattached)" in the library,
		// which is misleading when the file is in fact used by a page.
		// Validate edit permission to prevent attaching to arbitrary posts.
		$post_id = (int) $req->get_param( 'post_id' );
		if ( $post_id > 0 ) {
			if ( ! get_post( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
				$post_id = 0;
			}
		}

		$id = media_handle_upload( 'file', $post_id );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		update_post_meta( $id, PML_Storage::FLAG_META, 1 );
		update_post_meta( $id, PML_Access::META_RULE, 'logged_in' );

		return self::serialize_attachment( get_post( $id ) );
	}

	/**
	 * Parse a php.ini shorthand byte value ("100M", "2G") into bytes.
	 * Returns 0 for empty/invalid input so callers can skip the check.
	 */
	private static function ini_bytes( $value ): int {
		$value = trim( (string) $value );
		if ( $value === '' ) {
			return 0;
		}
		$last  = strtolower( $value[ strlen( $value ) - 1 ] );
		$num   = (int) $value;
		switch ( $last ) {
			case 'g': $num *= 1024;
			case 'm': $num *= 1024;
			case 'k': $num *= 1024;
		}
		return $num;
	}

	/* --------- /attach --------- */

	/**
	 * Reparent already-uploaded protected attachments to the current post.
	 *
	 * Only updates rows whose post_parent is currently 0 — never steals an
	 * attachment from another post. WP's own media frame applies the same
	 * "attach on insert" rule.
	 */
	public static function rest_attach( WP_REST_Request $req ) {
		$post_id = (int) $req->get_param( 'post_id' );
		if ( ! get_post( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error( 'pml_no_perm', __( 'Cannot edit target post.', 'protected-media-library' ), [ 'status' => 403 ] );
		}

		$ids = array_values( array_filter( array_map( 'intval', explode( ',', (string) $req->get_param( 'ids' ) ) ) ) );
		$updated = [];
		foreach ( $ids as $id ) {
			$att = get_post( $id );
			if ( ! $att || $att->post_type !== 'attachment' ) {
				continue;
			}
			if ( ! get_post_meta( $id, PML_Storage::FLAG_META, true ) ) {
				continue;
			}
			if ( (int) $att->post_parent !== 0 ) {
				continue;
			}
			wp_update_post( [ 'ID' => $id, 'post_parent' => $post_id ] );
			$updated[] = $id;
		}

		return [ 'updated' => $updated ];
	}

	/* --------- serializer --------- */

	public static function serialize_attachment( WP_Post $att ): array {
		$id    = (int) $att->ID;
		$sizes = [];

		$thumb = wp_get_attachment_image_src( $id, 'thumbnail' );
		if ( $thumb ) {
			$sizes['thumbnail'] = [
				'url'    => $thumb[0],
				'width'  => $thumb[1],
				'height' => $thumb[2],
			];
		}
		$medium = wp_get_attachment_image_src( $id, 'medium' );
		if ( $medium ) {
			$sizes['medium'] = [
				'url'    => $medium[0],
				'width'  => $medium[1],
				'height' => $medium[2],
			];
		}
		$full = wp_get_attachment_image_src( $id, 'full' );
		if ( $full ) {
			$sizes['full'] = [
				'url'    => $full[0],
				'width'  => $full[1],
				'height' => $full[2],
			];
		}

		return [
			'id'    => $id,
			'title' => get_the_title( $att ),
			'url'   => wp_get_attachment_url( $id ),
			'alt'   => (string) get_post_meta( $id, '_wp_attachment_image_alt', true ),
			'mime'  => $att->post_mime_type,
			'sizes' => $sizes,
			'date'  => mysql2date( 'c', $att->post_date_gmt ),
		];
	}
}
