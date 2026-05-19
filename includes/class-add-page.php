<?php
/**
 * "Add Protected Media File" — custom admin page.
 *
 * Owned end-to-end by this plugin. Uses the existing REST endpoint
 * `/pml/v1/upload` (the same one block uploads go through), so all the
 * protected-flagging server-side logic is shared.
 *
 * Why custom instead of hooking media-new.php: WP's plupload pipeline is
 * fragile to filter into. We chased timing bugs trying to inject a nonce
 * into _wpPluploadSettings. Going direct via REST + XHR is simpler, more
 * reliable, and gives us full control over the UX.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PML_Add_Page {

	const SCRIPT_HANDLE = 'pml-add-page';

	public static function init(): void {
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue' ] );
	}

	public static function enqueue( string $hook ): void {
		// admin.php?page=pml-add-new → hook is "protected-media_page_pml-add-new"
		if ( ! str_ends_with( $hook, PML_Library::ADD_PAGE_SLUG ) ) {
			return;
		}
		wp_enqueue_script(
			self::SCRIPT_HANDLE,
			PML_URL . 'assets/js/add-page.js',
			[ 'wp-api-fetch' ],
			PML_VERSION,
			true
		);
		wp_localize_script( self::SCRIPT_HANDLE, 'PMLAddPage', [
			'libraryUrl' => admin_url( 'upload.php?' . PML_Library::MODE_QV . '=protected' ),
			'i18n'       => [
				'dropHere'  => __( 'Drop files here to upload', 'protected-media-library' ),
				'browse'    => __( 'or click to browse', 'protected-media-library' ),
				'uploading' => __( 'Uploading…', 'protected-media-library' ),
				'failed'    => __( 'Upload failed', 'protected-media-library' ),
				'viewInLib' => __( 'View in Protected Library →', 'protected-media-library' ),
				'remove'    => __( 'Remove', 'protected-media-library' ),
			],
		] );
		wp_enqueue_style(
			self::SCRIPT_HANDLE,
			PML_URL . 'assets/css/add-page.css',
			[],
			PML_VERSION
		);
	}

	public static function render(): void {
		if ( ! current_user_can( 'upload_files' ) ) {
			wp_die( esc_html__( 'You do not have permission to upload files.', 'protected-media-library' ) );
		}
		$library_url = admin_url( 'upload.php?' . PML_Library::MODE_QV . '=protected' );
		?>
		<div class="wrap pml-add-page">
			<h1><?php esc_html_e( 'Add Protected Media File', 'protected-media-library' ); ?></h1>

			<div class="pml-add-banner">
				<strong><?php esc_html_e( 'Protected uploader.', 'protected-media-library' ); ?></strong>
				<?php esc_html_e( 'Files uploaded here are stored outside the public uploads directory and require an authenticated session to view.', 'protected-media-library' ); ?>
				<a href="<?php echo esc_url( $library_url ); ?>">
					<?php esc_html_e( 'View Protected Library →', 'protected-media-library' ); ?>
				</a>
			</div>

			<p class="pml-upload-limit">
				<?php
				printf(
					/* translators: 1: per-file limit, 2: per-request limit */
					esc_html__( 'Server upload limits: %1$s per file, %2$s per request. Files larger than these will be rejected.', 'protected-media-library' ),
					'<code>' . esc_html( size_format( wp_max_upload_size() ) ) . '</code>',
					'<code>' . esc_html( ini_get( 'post_max_size' ) ) . '</code>'
				);
				?>
			</p>

			<div class="pml-dropzone" id="pml-dropzone" tabindex="0">
				<input type="file" id="pml-file-input" multiple style="display:none;" />
				<div class="pml-dropzone-prompt">
					<span class="pml-dropzone-icon" aria-hidden="true">🔒</span>
					<strong class="pml-dropzone-title"><?php esc_html_e( 'Drop files here to upload', 'protected-media-library' ); ?></strong>
					<span class="pml-dropzone-subtitle"><?php esc_html_e( 'or click to browse', 'protected-media-library' ); ?></span>
				</div>
			</div>

			<ul class="pml-upload-list" id="pml-upload-list" aria-live="polite"></ul>
		</div>
		<?php
	}
}
