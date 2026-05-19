<?php
/**
 * Shortcodes that mirror the protected media blocks.
 *
 * For Classic Editor users, ACF / page builders, custom fields, and any
 * context where blocks aren't an option. Each shortcode delegates to
 * `render_block()` so the rendering, access gating, and URL rewrites are
 * identical to the block — zero duplication.
 *
 * Examples:
 *   [pml-image id="12" alt="alt text"]
 *   [pml-file id="14" display="Sermon Notes" mime="application/pdf" preview="true"]
 *   [pml-audio id="18" preload="metadata"]
 *   [pml-gallery ids="12,14,18" columns="3" crop="true"]
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PML_Shortcodes {

	public static function init(): void {
		add_shortcode( 'pml-image',   [ __CLASS__, 'shortcode_image' ] );
		add_shortcode( 'pml-file',    [ __CLASS__, 'shortcode_file' ] );
		add_shortcode( 'pml-audio',   [ __CLASS__, 'shortcode_audio' ] );
		add_shortcode( 'pml-video',   [ __CLASS__, 'shortcode_video' ] );
		add_shortcode( 'pml-gallery', [ __CLASS__, 'shortcode_gallery' ] );
	}

	public static function shortcode_image( $atts ): string {
		$atts = shortcode_atts( [
			'id'      => 0,
			'alt'     => '',
			'caption' => '',
			'width'   => 0,
			'height'  => 0,
		], $atts, 'pml-image' );

		return self::delegate( 'pml/protected-image', [
			'id'      => (int) $atts['id'],
			'alt'     => (string) $atts['alt'],
			'caption' => (string) $atts['caption'],
			'width'   => (int) $atts['width'],
			'height'  => (int) $atts['height'],
		] );
	}

	public static function shortcode_file( $atts ): string {
		$atts = shortcode_atts( [
			'id'       => 0,
			'filename' => '',
			'display'  => '',
			'mime'     => '',
			'download' => 'true',
			'preview'  => 'true',
			'height'   => 600,
		], $atts, 'pml-file' );

		return self::delegate( 'pml/protected-file', [
			'id'                 => (int) $atts['id'],
			'filename'           => (string) $atts['filename'],
			'displayText'        => (string) $atts['display'],
			'mime'               => (string) $atts['mime'],
			'showDownloadButton' => self::truthy( $atts['download'] ),
			'showInlinePreview'  => self::truthy( $atts['preview'] ),
			'previewHeight'      => (int) $atts['height'],
		] );
	}

	public static function shortcode_audio( $atts ): string {
		$atts = shortcode_atts( [
			'id'       => 0,
			'filename' => '',
			'display'  => '',
			'mime'     => '',
			'preload'  => 'metadata',
			'loop'     => 'false',
			'download' => 'false',
		], $atts, 'pml-audio' );

		return self::delegate( 'pml/protected-audio', [
			'id'                 => (int) $atts['id'],
			'filename'           => (string) $atts['filename'],
			'displayText'        => (string) $atts['display'],
			'mime'               => (string) $atts['mime'],
			'preload'            => (string) $atts['preload'],
			'loop'               => self::truthy( $atts['loop'] ),
			'showDownloadButton' => self::truthy( $atts['download'] ),
		] );
	}

	public static function shortcode_video( $atts ): string {
		$atts = shortcode_atts( [
			'id'          => 0,
			'filename'    => '',
			'display'     => '',
			'mime'        => '',
			'poster'      => 0,
			'preload'     => 'metadata',
			'loop'        => 'false',
			'muted'       => 'false',
			'playsinline' => 'false',
			'download'    => 'false',
		], $atts, 'pml-video' );

		return self::delegate( 'pml/protected-video', [
			'id'                 => (int) $atts['id'],
			'filename'           => (string) $atts['filename'],
			'displayText'        => (string) $atts['display'],
			'mime'               => (string) $atts['mime'],
			'posterId'           => (int) $atts['poster'],
			'preload'            => (string) $atts['preload'],
			'loop'               => self::truthy( $atts['loop'] ),
			'muted'              => self::truthy( $atts['muted'] ),
			'playsInline'        => self::truthy( $atts['playsinline'] ),
			'showDownloadButton' => self::truthy( $atts['download'] ),
		] );
	}

	public static function shortcode_gallery( $atts ): string {
		$atts = shortcode_atts( [
			'ids'     => '',
			'columns' => 3,
			'crop'    => 'true',
		], $atts, 'pml-gallery' );

		$ids = array_values( array_filter( array_map( 'intval', explode( ',', (string) $atts['ids'] ) ) ) );

		return self::delegate( 'pml/protected-gallery', [
			'ids'       => $ids,
			'columns'   => (int) $atts['columns'],
			'imageCrop' => self::truthy( $atts['crop'] ),
		] );
	}

	/**
	 * Delegate to the named block's render_callback via render_block(). This
	 * keeps the shortcode output 100% in sync with the block output (including
	 * the access-gated locked placeholder for anon viewers).
	 */
	private static function delegate( string $block_name, array $attrs ): string {
		// Block must be registered (happens on `init`, same hook as us).
		if ( ! WP_Block_Type_Registry::get_instance()->is_registered( $block_name ) ) {
			return '';
		}
		return (string) render_block( [
			'blockName'    => $block_name,
			'attrs'        => $attrs,
			'innerBlocks'  => [],
			'innerHTML'    => '',
			'innerContent' => [],
		] );
	}

	private static function truthy( $value ): bool {
		if ( is_bool( $value ) ) {
			return $value;
		}
		$v = strtolower( trim( (string) $value ) );
		return $v === '1' || $v === 'true' || $v === 'yes' || $v === 'on';
	}
}
