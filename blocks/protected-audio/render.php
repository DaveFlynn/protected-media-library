<?php
/**
 * Dynamic render for pml/protected-audio.
 *
 * Authenticated viewers get the <audio> element. Anon viewers get a locked
 * placeholder card with a sign-in link — never the player (which would
 * fail silently to load) and never an exposed URL.
 *
 * WP wraps this in its own ob_start/ob_get_clean — echo, don't return.
 */

$attrs = isset( $attributes ) ? (array) $attributes : [];
$id    = isset( $attrs['id'] ) ? (int) $attrs['id'] : 0;
if ( ! $id ) {
	return;
}

$url             = isset( $attrs['url'] ) ? (string) $attrs['url'] : '';
$filename        = isset( $attrs['filename'] ) ? (string) $attrs['filename'] : '';
$display_text    = isset( $attrs['displayText'] ) && $attrs['displayText'] !== ''
	? (string) $attrs['displayText']
	: ( $filename !== '' ? $filename : '' );
$mime            = isset( $attrs['mime'] ) ? (string) $attrs['mime'] : '';
$preload_raw     = isset( $attrs['preload'] ) ? (string) $attrs['preload'] : 'metadata';
$preload         = in_array( $preload_raw, [ 'none', 'metadata', 'auto' ], true ) ? $preload_raw : 'metadata';
$loop            = ! empty( $attrs['loop'] );
$show_download   = ! empty( $attrs['showDownloadButton'] );

$fresh = wp_get_attachment_url( $id );
if ( $fresh ) {
	$url = $fresh;
}

$user_id  = get_current_user_id();
$can_view = $user_id > 0 && class_exists( 'PML_Access' )
	? PML_Access::user_can_view( $id, $user_id )
	: false;

$wrapper_attrs = get_block_wrapper_attributes( [
	'class' => 'pml-protected-audio' . ( $can_view ? '' : ' is-locked' ),
] );

if ( ! $can_view ) {
	// Generic locked card — never expose attachment-specific information
	// (filename, title, mime) to viewers who can't access the file.
	// Even an episode title can leak content topic in some use cases.
	$current_url = ( is_ssl() ? 'https' : 'http' ) . '://'
		. ( $_SERVER['HTTP_HOST'] ?? '' )
		. ( $_SERVER['REQUEST_URI'] ?? '/' );
	$login_url   = wp_login_url( $current_url );
	?>
	<div <?php echo $wrapper_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
		<div class="pml-audio-locked">
			<span class="pml-audio-locked-icon" aria-hidden="true">🔒</span>
			<div class="pml-audio-locked-text">
				<strong><?php esc_html_e( 'Protected audio', 'protected-media-library' ); ?></strong>
				<span><?php esc_html_e( 'Sign in to listen.', 'protected-media-library' ); ?></span>
			</div>
			<a class="pml-audio-locked-button" href="<?php echo esc_url( $login_url ); ?>">
				<?php esc_html_e( 'Sign in', 'protected-media-library' ); ?>
			</a>
		</div>
	</div>
	<?php
	return;
}

// Authenticated + allowed: full render.
?>
<div <?php echo $wrapper_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
	<div class="pml-audio-card">
		<?php if ( $display_text !== '' ) : ?>
			<div class="pml-audio-title"><?php echo wp_kses_post( $display_text ); ?></div>
		<?php endif; ?>
		<audio
			class="pml-audio-player"
			controls
			preload="<?php echo esc_attr( $preload ); ?>"
			<?php if ( $loop ) : ?>loop<?php endif; ?>
		>
			<source src="<?php echo esc_url( $url ); ?>"<?php if ( $mime !== '' ) : ?> type="<?php echo esc_attr( $mime ); ?>"<?php endif; ?> />
			<?php esc_html_e( 'Your browser does not support the audio element.', 'protected-media-library' ); ?>
		</audio>
		<?php if ( $filename !== '' ) : ?>
			<div class="pml-audio-filename"><?php echo esc_html( $filename ); ?></div>
		<?php endif; ?>
		<?php if ( $show_download ) : ?>
			<a class="pml-audio-download" href="<?php echo esc_url( $url ); ?>" download>
				<?php esc_html_e( 'Download', 'protected-media-library' ); ?>
			</a>
		<?php endif; ?>
	</div>
</div>
