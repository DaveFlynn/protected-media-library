/**
 * Protected Image block.
 *
 * Uses the shared picker (window.pmlPicker) for browsing the library and
 * uploading. Block-specific concerns kept here:
 *   - empty-state placeholder (lock icon, label, instructions)
 *   - filled-state render (figure + img + caption)
 *   - attribute shape (id/url/alt/caption/width/height)
 *
 * Dynamic block: save() returns null, real frontend rendering is in render.php.
 */
( function ( wp ) {
	if ( ! wp || ! wp.blocks || ! wp.element || ! wp.components || ! window.pmlPicker ) {
		return;
	}

	var el       = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var useState = wp.element.useState;
	var __       = ( wp.i18n && wp.i18n.__ ) || function ( s ) { return s; };

	var Placeholder    = wp.components.Placeholder;
	var Button         = wp.components.Button;
	var FormFileUpload = wp.components.FormFileUpload;
	var TextControl    = wp.components.TextControl;
	var PanelBody      = wp.components.PanelBody;
	var ToolbarGroup   = wp.components.ToolbarGroup;
	var ToolbarButton  = wp.components.ToolbarButton;

	var blockEditor       = wp.blockEditor || wp.editor;
	var useBlockProps     = blockEditor.useBlockProps;
	var RichText          = blockEditor.RichText;
	var BlockControls     = blockEditor.BlockControls;
	var InspectorControls = blockEditor.InspectorControls;

	var picker = window.pmlPicker;

	// v0.1 attribute snapshot. If a future release changes save() shape or
	// renames/drops attributes, add a new entry here with the v0.1 attributes
	// and a `migrate(attrs)` function so existing posts don't break.
	var v0_1_attrs = {
		id:      { type: 'number' },
		url:     { type: 'string' },
		alt:     { type: 'string', default: '' },
		caption: { type: 'string', default: '' },
		width:   { type: 'number' },
		height:  { type: 'number' },
		align:   { type: 'string' },
	};

	wp.blocks.registerBlockType( 'pml/protected-image', {
		edit: EditComponent,
		save: function () { return null; },
		deprecated: [
			{ attributes: v0_1_attrs, save: function () { return null; } },
		],
	} );

	function EditComponent( props ) {
		var attributes    = props.attributes;
		var setAttributes = props.setAttributes;
		var blockProps    = useBlockProps( { className: 'pml-protected-image' } );

		var pickerOpen = useState( false );
		var uploading  = useState( false );

		function openPicker()  { pickerOpen[ 1 ]( true ); }
		function closePicker() { pickerOpen[ 1 ]( false ); }

		function pick( att ) {
			var full = att.sizes && att.sizes.full ? att.sizes.full : null;
			setAttributes( {
				id:     att.id,
				url:    att.url,
				alt:    att.alt || '',
				width:  full ? full.width  : undefined,
				height: full ? full.height : undefined,
			} );
			closePicker();
		}

		function clearImage() {
			setAttributes( { id: undefined, url: undefined, alt: '', width: undefined, height: undefined } );
		}

		function upload( files ) {
			if ( ! files || ! files.length ) { return; }
			uploading[ 1 ]( true );
			picker.upload( files[ 0 ] )
				.then( function ( att ) {
					uploading[ 1 ]( false );
					pick( att );
				} )
				.catch( function ( err ) {
					uploading[ 1 ]( false );
					window.alert( ( err && err.message ) || __( 'Upload failed.', 'protected-media-library' ) );
				} );
		}

		var pickerEl = el( picker.PickerModal, {
			isOpen:            pickerOpen[ 0 ],
			onClose:           closePicker,
			onPick:            pick,
			mime:              'image',
			mode:              'grid',
			title:             __( 'Protected Library — Images', 'protected-media-library' ),
			searchPlaceholder: __( 'Search protected images…', 'protected-media-library' ),
			emptyMessage:      __( 'No protected images yet. Upload one with the Upload button on the block.', 'protected-media-library' ),
		} );

		/* ---- empty state ---- */
		if ( ! attributes.url ) {
			return el( 'div', blockProps,
				el( Placeholder, {
					icon:         'lock',
					label:        __( 'Protected Image', 'protected-media-library' ),
					instructions: __( 'Upload a new protected image, or pick one from the Protected Library. Protected files live outside public uploads and require authentication.', 'protected-media-library' ),
				},
					el( FormFileUpload, {
						accept:   'image/*',
						onChange: function ( e ) { upload( e.target.files ); },
						variant:  'primary',
						disabled: uploading[ 0 ],
					}, uploading[ 0 ] ? __( 'Uploading…', 'protected-media-library' ) : __( 'Upload', 'protected-media-library' ) ),
					' ',
					el( Button, {
						variant: 'secondary',
						onClick: openPicker,
					}, __( 'Browse Protected Library', 'protected-media-library' ) )
				),
				pickerEl
			);
		}

		/* ---- filled state ---- */
		return el( Fragment, null,
			el( BlockControls, null,
				el( ToolbarGroup, null,
					el( ToolbarButton, {
						icon:    'edit',
						label:   __( 'Replace from Protected Library', 'protected-media-library' ),
						onClick: openPicker,
					} ),
					el( ToolbarButton, {
						icon:    'no',
						label:   __( 'Remove image', 'protected-media-library' ),
						onClick: clearImage,
					} )
				)
			),
			el( InspectorControls, null,
				el( PanelBody, { title: __( 'Image settings', 'protected-media-library' ), initialOpen: true },
					el( TextControl, {
						label:    __( 'Alt text (alternative text)', 'protected-media-library' ),
						help:     __( 'Describe the purpose of the image. Leave blank if decorative.', 'protected-media-library' ),
						value:    attributes.alt,
						onChange: function ( v ) { setAttributes( { alt: v } ); },
					} )
				)
			),
			el( 'figure', blockProps,
				el( 'span', { className: 'pml-protected-badge', 'aria-label': __( 'Protected', 'protected-media-library' ) }, '🔒 Protected' ),
				el( 'img', { src: attributes.url, alt: attributes.alt, 'data-pml-id': attributes.id } ),
				el( RichText, {
					tagName:     'figcaption',
					placeholder: __( 'Add caption…', 'protected-media-library' ),
					value:       attributes.caption,
					onChange:    function ( v ) { setAttributes( { caption: v } ); },
				} ),
				pickerEl
			)
		);
	}
}( window.wp ) );
