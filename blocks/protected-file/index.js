/**
 * Protected File block.
 *
 * Uses the shared picker (window.pmlPicker) for browsing and uploading.
 * Block-specific: file card UI (icon + display text + filename + optional
 * Download button), optional inline PDF preview via <iframe>.
 *
 * Dynamic block — save() returns null; render.php gates on viewer access.
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
	var RangeControl   = wp.components.RangeControl;
	var PanelBody      = wp.components.PanelBody;
	var ToolbarGroup   = wp.components.ToolbarGroup;
	var ToolbarButton  = wp.components.ToolbarButton;

	var blockEditor       = wp.blockEditor || wp.editor;
	var useBlockProps     = blockEditor.useBlockProps;
	var RichText          = blockEditor.RichText;
	var BlockControls     = blockEditor.BlockControls;
	var InspectorControls = blockEditor.InspectorControls;

	var picker = window.pmlPicker;

	function isPreviewable( mime ) {
		return mime === 'application/pdf';
	}

	// v0.1 attribute snapshot — see protected-image/index.js for rationale.
	var v0_1_attrs = {
		id:                 { type: 'number' },
		url:                { type: 'string' },
		filename:           { type: 'string',  default: '' },
		displayText:        { type: 'string',  default: '' },
		mime:               { type: 'string',  default: '' },
		showDownloadButton: { type: 'boolean', default: true },
		showInlinePreview:  { type: 'boolean', default: true },
		previewHeight:      { type: 'number',  default: 600 },
	};

	wp.blocks.registerBlockType( 'pml/protected-file', {
		edit: EditComponent,
		save: function () { return null; },
		deprecated: [
			{ attributes: v0_1_attrs, save: function () { return null; } },
		],
	} );

	function EditComponent( props ) {
		var attributes    = props.attributes;
		var setAttributes = props.setAttributes;
		var blockProps    = useBlockProps( { className: 'pml-protected-file' } );

		var pickerOpen = useState( false );
		var uploading  = useState( false );

		function openPicker()  { pickerOpen[ 1 ]( true ); }
		function closePicker() { pickerOpen[ 1 ]( false ); }

		function pick( att ) {
			setAttributes( {
				id:          att.id,
				url:         att.url,
				filename:    att.title || '',
				displayText: attributes.displayText || att.title || __( 'Download', 'protected-media-library' ),
				mime:        att.mime || '',
			} );
			closePicker();
		}

		function clearFile() {
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
			mime:              '',
			mode:              'list',
			title:             __( 'Protected Library', 'protected-media-library' ),
			searchPlaceholder: __( 'Search protected files…', 'protected-media-library' ),
			emptyMessage:      __( 'No protected files yet. Upload one with the Upload button on the block.', 'protected-media-library' ),
		} );

		/* ---- empty state ---- */
		if ( ! attributes.url ) {
			return el( 'div', blockProps,
				el( Placeholder, {
					icon:         'lock',
					label:        __( 'Protected File', 'protected-media-library' ),
					instructions: __( 'Upload a new protected file, or pick one from the Protected Library. Files live outside public uploads and require authentication to download.', 'protected-media-library' ),
				},
					el( FormFileUpload, {
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
						label:   __( 'Remove file', 'protected-media-library' ),
						onClick: clearFile,
					} )
				)
			),
			el( InspectorControls, null,
				el( PanelBody, { title: __( 'File settings', 'protected-media-library' ), initialOpen: true },
					el( ToggleControl, {
						label:    __( 'Show download button', 'protected-media-library' ),
						checked:  !! attributes.showDownloadButton,
						onChange: function ( v ) { setAttributes( { showDownloadButton: !! v } ); },
					} ),
					isPreviewable( attributes.mime ) && el( Fragment, null,
						el( ToggleControl, {
							label:    __( 'Show inline preview', 'protected-media-library' ),
							help:     __( 'Embed the PDF directly on the page. Visitors must still be authenticated to view it.', 'protected-media-library' ),
							checked:  !! attributes.showInlinePreview,
							onChange: function ( v ) { setAttributes( { showInlinePreview: !! v } ); },
						} ),
						attributes.showInlinePreview && el( RangeControl, {
							label:    __( 'Preview height', 'protected-media-library' ),
							value:    attributes.previewHeight || 600,
							onChange: function ( v ) { setAttributes( { previewHeight: v } ); },
							min:      200,
							max:      1200,
							step:     20,
						} )
					)
				)
			),
			el( 'div', blockProps,
				el( 'span', { className: 'pml-protected-badge', 'aria-label': __( 'Protected', 'protected-media-library' ) }, '🔒 Protected' ),
				isPreviewable( attributes.mime ) && attributes.showInlinePreview && el( 'iframe', {
					className: 'pml-file-preview',
					src:       attributes.url,
					title:     attributes.filename || __( 'Preview', 'protected-media-library' ),
					style:     { height: ( attributes.previewHeight || 600 ) + 'px' },
				} ),
				el( 'div', { className: 'pml-file-card' },
					el( 'span', { className: 'pml-file-icon', 'aria-hidden': 'true' }, picker.iconForMime( attributes.mime ) ),
					el( 'div', { className: 'pml-file-meta' },
						el( RichText, {
							tagName:        'a',
							className:      'pml-file-link',
							placeholder:    __( 'Link text…', 'protected-media-library' ),
							value:          attributes.displayText,
							onChange:       function ( v ) { setAttributes( { displayText: v } ); },
							href:           attributes.url,
							onClick:        function ( e ) { e.preventDefault(); },
							allowedFormats: [ 'core/bold', 'core/italic' ],
						} ),
						attributes.filename && el( 'span', { className: 'pml-file-filename' }, attributes.filename )
					),
					attributes.showDownloadButton && el( 'a', {
						className: 'pml-file-button',
						href:      attributes.url,
						onClick:   function ( e ) { e.preventDefault(); },
					}, __( 'Download', 'protected-media-library' ) )
				)
			),
			pickerEl
		);
	}
}( window.wp ) );
