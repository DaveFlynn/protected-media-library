<?php
/**
 * Dynamic render for pml/protected-file.
 *
 * Renders different markup depending on whether the current viewer has access
 * to the attached file. This keeps three problems off the page for visitors
 * who can't view:
 *   - the iframe preview would otherwise load the wp-login form into the page
 *     (cookie-gated handler returns 302 → wp-login.php inside the iframe);
 *   - the Download button would otherwise look like it works but actually
 *     save the redirected login HTML as a file with the original filename;
 *   - the editable link would expose the file's existence and name.
 *
 * For viewers without access we show a "sign in to view" placeholder with a
 * real login link, so the action is obvious.
 *
 * WP's `render` mechanism wraps this file in its own ob_start/ob_get_clean
 * pair, so we ECHO the output (the file's return value is discarded). Don't
 * use our own buffer here.
 *
 * In scope (provided by the block API):
 *   $attributes  array
 *   $content     string
 *   $block       WP_Block
 */

$attrs = isset( $attributes ) ? (array) $attributes : [];
$id    = isset( $attrs['id'] ) ? (int) $attrs['id'] : 0;
if ( ! $id ) {
	return;
}

$url           = isset( $attrs['url'] ) ? (string) $attrs['url'] : '';
$filename      = isset( $attrs['filename'] ) ? (string) $attrs['filename'] : '';
$display_text  = isset( $attrs['displayText'] ) && $attrs['displayText'] !== ''
	? (string) $attrs['displayText']
	: ( $filename !== '' ? $filename : __( 'Download', 'protected-media-library' ) );
$mime          = isset( $attrs['mime'] ) ? (string) $attrs['mime'] : '';
$show_button   = ! empty( $attrs['showDownloadButton'] );
$show_preview  = ! empty( $attrs['showInlinePreview'] );
$preview_h     = isset( $attrs['previewHeight'] ) ? (int) $attrs['previewHeight'] : 600;

$fresh_url = wp_get_attachment_url( $id );
if ( $fresh_url ) {
	$url = $fresh_url;
}

$user_id  = get_current_user_id();
$can_view = $user_id > 0 && class_exists( 'PML_Access' )
	? PML_Access::user_can_view( $id, $user_id )
	: false;

$wrapper_attrs = get_block_wrapper_attributes( [
	'class' => 'pml-protected-file' . ( $can_view ? '' : ' is-locked' ),
] );

if ( ! $can_view ) {
	$current_url = ( is_ssl() ? 'https' : 'http' ) . '://'
		. ( $_SERVER['HTTP_HOST'] ?? '' )
		. ( $_SERVER['REQUEST_URI'] ?? '/' );
	$login_url   = wp_login_url( $current_url );
	?>
	<div <?php echo $wrapper_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
		<div class="pml-file-locked">
			<span class="pml-file-locked-icon" aria-hidden="true">🔒</span>
			<div class="pml-file-locked-text">
				<strong><?php esc_html_e( 'Protected file', 'protected-media-library' ); ?></strong>
				<span><?php esc_html_e( 'You need to sign in to view or download this file.', 'protected-media-library' ); ?></span>
			</div>
			<a class="pml-file-locked-button" href="<?php echo esc_url( $login_url ); ?>">
				<?php esc_html_e( 'Sign in', 'protected-media-library' ); ?>
			</a>
		</div>
	</div>
	<?php
	return;
}

// Authenticated + allowed: full render.
$can_preview = $show_preview && $mime === 'application/pdf';
?>
<div <?php echo $wrapper_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
	<?php if ( $can_preview ) : ?>
		<iframe
			class="pml-file-preview"
			src="<?php echo esc_url( $url ); ?>"
			title="<?php echo esc_attr( $filename !== '' ? $filename : __( 'Preview', 'protected-media-library' ) ); ?>"
			style="height: <?php echo (int) $preview_h; ?>px;"
			loading="lazy"
		></iframe>
	<?php endif; ?>
	<div class="pml-file-card">
		<div class="pml-file-meta">
			<a class="pml-file-link" href="<?php echo esc_url( $url ); ?>"><?php echo wp_kses_post( $display_text ); ?></a>
			<?php if ( $filename !== '' ) : ?>
				<span class="pml-file-filename"><?php echo esc_html( $filename ); ?></span>
			<?php endif; ?>
		</div>
		<?php if ( $show_button ) : ?>
			<a class="pml-file-button" href="<?php echo esc_url( $url ); ?>" download>
				<?php esc_html_e( 'Download', 'protected-media-library' ); ?>
			</a>
		<?php endif; ?>
	</div>
</div>
