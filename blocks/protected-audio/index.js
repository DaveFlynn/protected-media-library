/**
 * Protected Audio block.
 *
 * Uses the shared picker (window.pmlPicker). Block-specific: native
 * <audio controls preload="metadata"> render in the editor + sidebar
 * controls for preload behavior, loop, and download button.
 *
 * Dynamic block — save() returns null; render.php handles frontend output
 * with access gating.
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
	var ToggleControl  = wp.components.ToggleControl;
	var SelectControl  = wp.components.SelectControl;
	var PanelBody      = wp.components.PanelBody;
	var ToolbarGroup   = wp.components.ToolbarGroup;
	var ToolbarButton  = wp.components.ToolbarButton;

	var blockEditor       = wp.blockEditor || wp.editor;
	var useBlockProps     = blockEditor.useBlockProps;
	var RichText          = blockEditor.RichText;
	var BlockControls     = blockEditor.BlockControls;
	var InspectorControls = blockEditor.InspectorControls;

	var picker = window.pmlPicker;

	// v0.1 attribute snapshot — see protected-image/index.js for rationale.
	var v0_1_attrs = {
		id:                 { type: 'number' },
		url:                { type: 'string' },
		filename:           { type: 'string',  default: '' },
		displayText:        { type: 'string',  default: '' },
		mime:               { type: 'string',  default: '' },
		preload:            { type: 'string',  default: 'metadata', enum: [ 'none', 'metadata', 'auto' ] },
		loop:               { type: 'boolean', default: false },
		showDownloadButton: { type: 'boolean', default: false },
	};

	wp.blocks.registerBlockType( 'pml/protected-audio', {
		edit: EditComponent,
		save: function () { return null; },
		deprecated: [
			{ attributes: v0_1_attrs, save: function () { return null; } },
		],
	} );

	function EditComponent( props ) {
		var attributes    = props.attributes;
		var setAttributes = props.setAttributes;
		var blockProps    = useBlockProps( { className: 'pml-protected-audio' } );

		var pickerOpen = useState( false );
		var uploading  = useState( false );

		function openPicker()  { pickerOpen[ 1 ]( true ); }
		function closePicker() { pickerOpen[ 1 ]( false ); }

		function pick( att ) {
			setAttributes( {
				id:          att.id,
				url:         att.url,
				filename:    att.title || '',
				displayText: attributes.displayText || att.title || '',
				mime:        att.mime || '',
			} );
			closePicker();
		}

		function clearAudio() {
			setAttributes( { id: undefined, url: undefined, filename: '', displayText: '', mime: '' } );
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
			mime:              'audio',
			mode:              'list',
			title:             __( 'Protected Library — Audio', 'protected-media-library' ),
			searchPlaceholder: __( 'Search audio files…', 'protected-media-library' ),
			emptyMessage:      __( 'No protected audio files yet. Upload one with the Upload button on the block.', 'protected-media-library' ),
		} );

		/* ---- empty state ---- */
		if ( ! attributes.url ) {
			return el( 'div', blockProps,
				el( Placeholder, {
					icon:         'lock',
					label:        __( 'Protected Audio', 'protected-media-library' ),
					instructions: __( 'Upload a new protected audio file (sermon, podcast episode, etc.), or pick one from the Protected Library.', 'protected-media-library' ),
				},
					el( FormFileUpload, {
						accept:   'audio/*',
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
						label:   __( 'Remove audio', 'protected-media-library' ),
						onClick: clearAudio,
					} )
				)
			),
			el( InspectorControls, null,
				el( PanelBody, { title: __( 'Audio settings', 'protected-media-library' ), initialOpen: true },
					el( SelectControl, {
						label:    __( 'Preload', 'protected-media-library' ),
						help:     __( '"Metadata" is recommended for podcasts: shows duration without downloading the whole file.', 'protected-media-library' ),
						value:    attributes.preload || 'metadata',
						options:  [
							{ label: __( 'None',     'protected-media-library' ), value: 'none' },
							{ label: __( 'Metadata', 'protected-media-library' ), value: 'metadata' },
							{ label: __( 'Auto',     'protected-media-library' ), value: 'auto' },
						],
						onChange: function ( v ) { setAttributes( { preload: v } ); },
					} ),
					el( ToggleControl, {
						label:    __( 'Loop', 'protected-media-library' ),
						checked:  !! attributes.loop,
						onChange: function ( v ) { setAttributes( { loop: !! v } ); },
					} ),
					el( ToggleControl, {
						label:    __( 'Show download button', 'protected-media-library' ),
						help:     __( 'Adds a separate Download link below the player.', 'protected-media-library' ),
						checked:  !! attributes.showDownloadButton,
						onChange: function ( v ) { setAttributes( { showDownloadButton: !! v } ); },
					} )
				)
			),
			el( 'div', blockProps,
				el( 'span', { className: 'pml-protected-badge', 'aria-label': __( 'Protected', 'protected-media-library' ) }, '🔒 Protected' ),
				el( 'div', { className: 'pml-audio-card' },
					el( RichText, {
						tagName:        'div',
						className:      'pml-audio-title',
						placeholder:    __( 'Episode title…', 'protected-media-library' ),
						value:          attributes.displayText,
						onChange:       function ( v ) { setAttributes( { displayText: v } ); },
						allowedFormats: [ 'core/bold', 'core/italic' ],
					} ),
					el( 'audio', {
						className: 'pml-audio-player',
						controls:  true,
						preload:   attributes.preload || 'metadata',
						loop:      !! attributes.loop,
						src:       attributes.url,
					} ),
					attributes.filename && el( 'div', { className: 'pml-audio-filename' }, attributes.filename )
				)
			),
			pickerEl
		);
	}
}( window.wp ) );
