<?php
/**
 * Dynamic render for pml/protected-image.
 *
 * Same access-gated pattern as protected-file: if the current user can't view
 * the attachment, render a locked placeholder shaped to the image's aspect
 * ratio (so the page layout doesn't shift after sign-in). Otherwise render
 * the figure with image + caption.
 *
 * WP wraps this file in ob_start/ob_get_clean — we echo, don't return a string.
 */

$attrs = isset( $attributes ) ? (array) $attributes : [];
$id    = isset( $attrs['id'] ) ? (int) $attrs['id'] : 0;
if ( ! $id ) {
	return;
}

$url     = isset( $attrs['url'] ) ? (string) $attrs['url'] : '';
$alt     = isset( $attrs['alt'] ) ? (string) $attrs['alt'] : '';
$caption = isset( $attrs['caption'] ) ? (string) $attrs['caption'] : '';
$width   = isset( $attrs['width'] )  ? (int) $attrs['width']  : 0;
$height  = isset( $attrs['height'] ) ? (int) $attrs['height'] : 0;

$fresh = wp_get_attachment_url( $id );
if ( $fresh ) {
	$url = $fresh;
}

$user_id  = get_current_user_id();
$can_view = $user_id > 0 && class_exists( 'PML_Access' )
	? PML_Access::user_can_view( $id, $user_id )
	: false;

$wrapper_attrs = get_block_wrapper_attributes( [
	'class' => 'pml-protected-image' . ( $can_view ? '' : ' is-locked' ),
] );

if ( ! $can_view ) {
	// Generic compact locked card — same shape as the file/audio locked
	// states for visual consistency. No attachment data (caption, alt,
	// title, dimensions) is exposed. We dropped aspect-ratio preservation
	// because tall/wide images blew up to dominate the layout — the
	// trade-off (layout shift on sign-in) is worth it.
	$current_url = ( is_ssl() ? 'https' : 'http' ) . '://'
		. ( $_SERVER['HTTP_HOST'] ?? '' )
		. ( $_SERVER['REQUEST_URI'] ?? '/' );
	$login_url   = wp_login_url( $current_url );
	?>
	<figure <?php echo $wrapper_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
		<div class="pml-image-locked">
			<span class="pml-image-locked-icon" aria-hidden="true">🔒</span>
			<div class="pml-image-locked-text">
				<strong><?php esc_html_e( 'Protected image', 'protected-media-library' ); ?></strong>
				<span><?php esc_html_e( 'Sign in to view.', 'protected-media-library' ); ?></span>
			</div>
			<a class="pml-image-locked-button" href="<?php echo esc_url( $login_url ); ?>">
				<?php esc_html_e( 'Sign in', 'protected-media-library' ); ?>
			</a>
		</div>
	</figure>
	<?php
	return;
}

// Authenticated + allowed: full render.
?>
<figure <?php echo $wrapper_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
	<img
		src="<?php echo esc_url( $url ); ?>"
		alt="<?php echo esc_attr( $alt ); ?>"
		<?php if ( $width  > 0 ) : ?>width="<?php echo (int) $width; ?>"<?php endif; ?>
		<?php if ( $height > 0 ) : ?>height="<?php echo (int) $height; ?>"<?php endif; ?>
		data-pml-id="<?php echo (int) $id; ?>"
	/>
	<?php if ( $caption !== '' ) : ?>
		<figcaption><?php echo wp_kses_post( $caption ); ?></figcaption>
	<?php endif; ?>
</figure>
