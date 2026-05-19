<?php
/**
 * Classic Editor integration.
 *
 * Adds two buttons next to "Add Media" on post/page edit screens when the
 * Classic Editor is active:
 *   - Add Protected Media     (single-select; inserts [pml-image] / [pml-file] / [pml-audio])
 *   - Add Protected Gallery   (multi-select; inserts [pml-gallery])
 *
 * Clicking opens the same React picker module used by the blocks. We enqueue
 * the picker + its dependencies (wp-element, wp-components, etc.) on
 * editor-bearing admin screens. The JS handles the picker mount and the
 * shortcode insertion via `wp.media.editor.insert()`, which works in both
 * Visual and Text modes.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PML_Classic_Editor {

	public static function init(): void {
		add_action( 'media_buttons',        [ __CLASS__, 'render_buttons' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue' ] );
	}

	public static function render_buttons(): void {
		if ( ! current_user_can( 'upload_files' ) ) {
			return;
		}
		?>
		<button type="button" class="button pml-classic-insert" data-pml-mode="single">
			<span class="dashicons dashicons-lock" style="vertical-align: text-top;"></span>
			<?php esc_html_e( 'Add Protected Media', 'protected-media-library' ); ?>
		</button>
		<button type="button" class="button pml-classic-insert" data-pml-mode="gallery">
			<span class="dashicons dashicons-format-gallery" style="vertical-align: text-top;"></span>
			<?php esc_html_e( 'Add Protected Gallery', 'protected-media-library' ); ?>
		</button>
		<?php
	}

	public static function enqueue( string $hook ): void {
		// Only on the post/page edit screens.
		if ( ! in_array( $hook, [ 'post.php', 'post-new.php' ], true ) ) {
			return;
		}
		// And only when the Classic Editor is in play. The block editor still
		// fires media_buttons in some compat paths, but blocks already cover
		// insertion, so we'd be duplicating UI.
		if ( self::is_block_editor_screen() ) {
			return;
		}

		wp_enqueue_script(
			'pml-classic-editor',
			PML_URL . 'assets/js/classic-editor.js',
			[ 'jquery', 'wp-element', 'wp-components', 'wp-i18n', 'wp-api-fetch', 'wp-data', 'pml-picker' ],
			PML_VERSION,
			true
		);
		wp_enqueue_style( 'wp-components' );
		wp_enqueue_style( 'pml-picker' );
	}

	private static function is_block_editor_screen(): bool {
		if ( ! function_exists( 'get_current_screen' ) ) {
			return false;
		}
		$screen = get_current_screen();
		if ( $screen && method_exists( $screen, 'is_block_editor' ) && $screen->is_block_editor() ) {
			return true;
		}
		return false;
	}
}
