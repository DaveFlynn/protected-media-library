<?php
/**
 * ACF field type: "Protected Image".
 *
 * A drop-in replacement for ACF's native Image field that selects from / uploads
 * to PROTECTED storage instead of the public Media Library. It stores a plain
 * attachment ID — identical to the native field — so it is value-compatible with
 * an existing image field, and the plugin's outgoing URL filters
 * (wp_get_attachment_url / image_src / srcset) turn that ID into a
 * /protected-media/... URL automatically on the front end.
 *
 * Edit UI reuses the shared React picker (window.pmlPicker) — the same one the
 * blocks use — so we never touch the wp.media frame.
 *
 * Registered only when ACF is present (see acf/include_field_types in the
 * plugin bootstrap), so the plugin has no hard dependency on ACF.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'acf_field' ) ) {
	return;
}

class PML_ACF_Field_Image extends acf_field {

	public function initialize() {
		$this->name     = 'pml_protected_image';
		$this->label    = __( 'Protected Image', 'protected-media-library' );
		$this->category = 'content';
		$this->defaults = [
			'return_format' => 'array',   // 'array' | 'url' | 'id'
			'preview_size'  => 'medium',
		];
	}

	/* --------- field group settings (admin: editing the field) --------- */

	public function render_field_settings( $field ) {
		acf_render_field_setting( $field, [
			'label'   => __( 'Return Value', 'protected-media-library' ),
			'name'    => 'return_format',
			'type'    => 'radio',
			'layout'  => 'horizontal',
			'choices' => [
				'array' => __( 'Image Array', 'protected-media-library' ),
				'url'   => __( 'Image URL', 'protected-media-library' ),
				'id'    => __( 'Image ID', 'protected-media-library' ),
			],
		] );

		acf_render_field_setting( $field, [
			'label'   => __( 'Preview Size', 'protected-media-library' ),
			'name'    => 'preview_size',
			'type'    => 'select',
			'choices' => function_exists( 'acf_get_image_sizes' ) ? acf_get_image_sizes() : [ 'medium' => 'medium', 'large' => 'large', 'full' => 'full' ],
		] );
	}

	/* --------- the input on the post edit screen --------- */

	public function render_field( $field ) {
		$id           = $field['value'] ? (int) $field['value'] : 0;
		$preview_size = $field['preview_size'] ?: 'medium';
		$preview_url  = '';

		if ( $id ) {
			$src = wp_get_attachment_image_src( $id, $preview_size );
			if ( $src ) {
				$preview_url = $src[0];
			}
		}

		$has = $id ? ' has-value' : '';
		?>
		<div class="pml-acf-image<?php echo esc_attr( $has ); ?>">
			<input
				type="hidden"
				name="<?php echo esc_attr( $field['name'] ); ?>"
				value="<?php echo esc_attr( $id ?: '' ); ?>"
				class="pml-acf-image-input"
				data-preview-size="<?php echo esc_attr( $preview_size ); ?>"
			/>

			<div class="pml-acf-image-preview">
				<?php if ( $preview_url ) : ?>
					<img src="<?php echo esc_url( $preview_url ); ?>" alt="" />
				<?php endif; ?>
			</div>

			<div class="pml-acf-image-actions">
				<button type="button" class="button button-primary pml-acf-image-upload">
					<?php esc_html_e( 'Upload', 'protected-media-library' ); ?>
				</button>
				<button type="button" class="button pml-acf-image-select">
					<?php esc_html_e( 'Select from Protected Library', 'protected-media-library' ); ?>
				</button>
				<button type="button" class="button-link pml-acf-image-remove"<?php echo $id ? '' : ' style="display:none"'; ?>>
					<?php esc_html_e( 'Remove', 'protected-media-library' ); ?>
				</button>
			</div>
		</div>
		<?php
	}

	/* --------- assets --------- */

	public function input_admin_enqueue_scripts() {
		// pml-picker is registered on `init` by PML_Blocks for every request,
		// including admin, so it is available to depend on here.
		wp_enqueue_script( 'pml-picker' );
		wp_enqueue_style( 'pml-picker' );

		wp_enqueue_script(
			'pml-acf-image',
			PML_URL . 'assets/js/acf-field-image.js',
			[ 'jquery', 'pml-picker', 'acf-input' ],
			PML_VERSION,
			true
		);
		wp_enqueue_style(
			'pml-acf-image',
			PML_URL . 'assets/css/acf-field-image.css',
			[],
			PML_VERSION
		);
	}

	/* --------- value storage + return formatting --------- */

	/**
	 * Persist a plain integer attachment ID (or '' when cleared) — same shape as
	 * ACF's native image field, so switching field types keeps existing values.
	 */
	public function update_value( $value, $post_id, $field ) {
		if ( empty( $value ) ) {
			return '';
		}
		return (int) $value;
	}

	/**
	 * Shape the value for get_field()/the_field(). All three formats flow through
	 * WP attachment functions that the plugin's URL filters intercept, so the
	 * emitted URLs are protected automatically.
	 */
	public function format_value( $value, $post_id, $field ) {
		if ( empty( $value ) ) {
			return false;
		}
		$id = (int) $value;

		switch ( $field['return_format'] ?? 'array' ) {
			case 'url':
				return wp_get_attachment_url( $id );
			case 'id':
				return $id;
			case 'array':
			default:
				return function_exists( 'acf_get_attachment' ) ? acf_get_attachment( $id ) : $id;
		}
	}
}
