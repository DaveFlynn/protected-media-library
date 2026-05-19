<?php
/**
 * Dynamic render for pml/protected-gallery.
 *
 * Authenticated viewers get a CSS-grid of images. Anon viewers get one
 * generic locked card (we don't leak image count or any per-item data).
 *
 * For v1, all-or-nothing per page: if the viewer can view one protected
 * image they can view them all, so we check access against the first id
 * and gate the whole gallery. If per-item ACLs land (v2), this loop
 * should evaluate each id independently and render a mix.
 *
 * WP wraps this in ob_start/ob_get_clean — echo, don't return.
 */

$attrs = isset( $attributes ) ? (array) $attributes : [];
$ids   = isset( $attrs['ids'] ) && is_array( $attrs['ids'] )
	? array_values( array_filter( array_map( 'intval', $attrs['ids'] ) ) )
	: [];
if ( empty( $ids ) ) {
	return;
}

$columns    = isset( $attrs['columns'] )   ? max( 1, min( 8, (int) $attrs['columns'] ) ) : 3;
$image_crop = ! empty( $attrs['imageCrop'] );

// Access check on the FIRST id is sufficient for v1 (logged-in or not).
// When per-attachment ACLs ship, evaluate per id and render a mix.
$user_id  = get_current_user_id();
$can_view = $user_id > 0 && class_exists( 'PML_Access' )
	? PML_Access::user_can_view( $ids[0], $user_id )
	: false;

$wrapper_attrs = get_block_wrapper_attributes( [
	'class' => 'pml-protected-gallery' . ( $can_view ? '' : ' is-locked' ),
] );

if ( ! $can_view ) {
	$current_url = ( is_ssl() ? 'https' : 'http' ) . '://'
		. ( $_SERVER['HTTP_HOST'] ?? '' )
		. ( $_SERVER['REQUEST_URI'] ?? '/' );
	$login_url   = wp_login_url( $current_url );
	?>
	<div <?php echo $wrapper_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
		<a class="pml-gallery-locked" href="<?php echo esc_url( $login_url ); ?>">
			<span class="pml-gallery-locked-icon" aria-hidden="true">🔒</span>
			<span class="pml-gallery-locked-text">
				<strong><?php esc_html_e( 'Protected gallery', 'protected-media-library' ); ?></strong>
				<span><?php esc_html_e( 'Sign in to view', 'protected-media-library' ); ?></span>
			</span>
		</a>
	</div>
	<?php
	return;
}

$grid_style = 'grid-template-columns: repeat(' . $columns . ', minmax(0, 1fr));';
?>
<div <?php echo $wrapper_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
	<div class="pml-gallery-grid<?php echo $image_crop ? ' is-cropped' : ''; ?>" style="<?php echo esc_attr( $grid_style ); ?>">
		<?php foreach ( $ids as $id ) :
			$src = wp_get_attachment_image_src( $id, 'medium' );
			if ( ! $src ) {
				continue;
			}
			$alt = (string) get_post_meta( $id, '_wp_attachment_image_alt', true );
			?>
			<figure class="pml-gallery-item">
				<img
					src="<?php echo esc_url( $src[0] ); ?>"
					alt="<?php echo esc_attr( $alt ); ?>"
					width="<?php echo (int) $src[1]; ?>"
					height="<?php echo (int) $src[2]; ?>"
					loading="lazy"
					data-pml-id="<?php echo (int) $id; ?>"
				/>
			</figure>
		<?php endforeach; ?>
	</div>
</div>
