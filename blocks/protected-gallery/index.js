/**
 * Protected Gallery block.
 *
 * Stores only an array of attachment IDs. The frontend render.php fetches
 * the current URL/dimensions for each, gates on viewer access, and renders
 * a grid (or a single locked card for anon).
 *
 * Editor behavior:
 *   - Empty: Placeholder with one "Browse Protected Library" button that
 *     opens the picker in multi-select mode.
 *   - Filled: a thumbnail grid. Each tile has a hover-X to remove. A
 *     toolbar button reopens the picker pre-checked with the current
 *     selection, so the editor can add/remove without starting over.
 *
 * Dynamic block — save() returns null.
 */
( function ( wp ) {
	if ( ! wp || ! wp.blocks || ! wp.element || ! wp.components || ! window.pmlPicker ) {
		return;
	}

	var el       = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var useState = wp.element.useState;
	var useEffect = wp.element.useEffect;
	var __       = ( wp.i18n && wp.i18n.__ ) || function ( s ) { return s; };
	var apiFetch = wp.apiFetch;

	var Placeholder   = wp.components.Placeholder;
	var Button        = wp.components.Button;
	var RangeControl  = wp.components.RangeControl;
	var ToggleControl = wp.components.ToggleControl;
	var PanelBody     = wp.components.PanelBody;
	var ToolbarGroup  = wp.components.ToolbarGroup;
	var ToolbarButton = wp.components.ToolbarButton;

	var blockEditor       = wp.blockEditor || wp.editor;
	var useBlockProps     = blockEditor.useBlockProps;
	var BlockControls     = blockEditor.BlockControls;
	var InspectorControls = blockEditor.InspectorControls;

	var picker = window.pmlPicker;

	// v0.1 attribute snapshot — see protected-image/index.js for rationale.
	var v0_1_attrs = {
		ids:       { type: 'array',   default: [], items: { type: 'number' } },
		columns:   { type: 'number',  default: 3, minimum: 1, maximum: 8 },
		imageCrop: { type: 'boolean', default: true },
	};

	wp.blocks.registerBlockType( 'pml/protected-gallery', {
		edit: EditComponent,
		save: function () { return null; },
		deprecated: [
			{ attributes: v0_1_attrs, save: function () { return null; } },
		],
	} );

	function EditComponent( props ) {
		var attributes    = props.attributes;
		var setAttributes = props.setAttributes;
		var blockProps    = useBlockProps( { className: 'pml-protected-gallery' } );

		var pickerOpen = useState( false );
		// In-editor cache of attachment data keyed by id, so we can render
		// thumbnails without refetching on every render. Hydrated from REST
		// on mount whenever attributes.ids contains an unknown id.
		var cache      = useState( {} );

		var ids = Array.isArray( attributes.ids ) ? attributes.ids : [];

		// Hydrate cache for any ids we don't have yet. One REST call returns
		// up to 100; for galleries larger than that we'd need batching — out
		// of scope for v1.
		useEffect( function () {
			var missing = ids.filter( function ( id ) { return ! cache[ 0 ][ id ]; } );
			if ( missing.length === 0 ) { return; }
			apiFetch( {
				path: '/pml/v1/library?per_page=100&ids=' + encodeURIComponent( missing.join( ',' ) ),
			} ).then( function ( res ) {
				var next = Object.assign( {}, cache[ 0 ] );
				( res.items || [] ).forEach( function ( a ) { next[ a.id ] = a; } );
				cache[ 1 ]( next );
			} ).catch( function () { /* leave cache as-is; UI will show placeholders */ } );
		}, [ ids.join( ',' ) ] );

		function openPicker()  { pickerOpen[ 1 ]( true ); }
		function closePicker() { pickerOpen[ 1 ]( false ); }

		function commitSelection( items ) {
			var nextCache = Object.assign( {}, cache[ 0 ] );
			items.forEach( function ( a ) { nextCache[ a.id ] = a; } );
			cache[ 1 ]( nextCache );
			setAttributes( { ids: items.map( function ( a ) { return a.id; } ) } );
			closePicker();
		}

		function removeId( id ) {
			setAttributes( { ids: ids.filter( function ( i ) { return i !== id; } ) } );
		}

		// Provide initial selection by mapping current ids through cache;
		// for ids not yet in cache, pass a stub so the picker still pre-checks.
		var initialSelection = ids.map( function ( id ) {
			return cache[ 0 ][ id ] || { id: id, title: '', url: '', sizes: {}, mime: '', alt: '' };
		} );

		var pickerEl = el( picker.PickerModal, {
			isOpen:             pickerOpen[ 0 ],
			onClose:            closePicker,
			onPick:             commitSelection,
			mime:               'image',
			mode:               'grid',
			multiple:           true,
			initialSelection:   initialSelection,
			title:              __( 'Protected Library — Images', 'protected-media-library' ),
			searchPlaceholder:  __( 'Search protected images…', 'protected-media-library' ),
			emptyMessage:       __( 'No protected images yet. Upload some via the Protected Image block, then come back to build a gallery.', 'protected-media-library' ),
			insertLabel:        __( 'Insert into gallery', 'protected-media-library' ),
		} );

		/* ---- empty state ---- */
		if ( ids.length === 0 ) {
			return el( 'div', blockProps,
				el( Placeholder, {
					icon:         'lock',
					label:        __( 'Protected Gallery', 'protected-media-library' ),
					instructions: __( 'Pick protected images to display in a gallery. Visitors must be authenticated to view it.', 'protected-media-library' ),
				},
					el( Button, {
						variant: 'primary',
						onClick: openPicker,
					}, __( 'Browse Protected Library', 'protected-media-library' ) )
				),
				pickerEl
			);
		}

		var columns = attributes.columns || 3;
		var gridStyle = { gridTemplateColumns: 'repeat(' + columns + ', minmax(0, 1fr))' };

		/* ---- filled state ---- */
		return el( Fragment, null,
			el( BlockControls, null,
				el( ToolbarGroup, null,
					el( ToolbarButton, {
						icon:    'edit',
						label:   __( 'Edit gallery selection', 'protected-media-library' ),
						onClick: openPicker,
					} )
				)
			),
			el( InspectorControls, null,
				el( PanelBody, { title: __( 'Gallery settings', 'protected-media-library' ), initialOpen: true },
					el( RangeControl, {
						label:    __( 'Columns', 'protected-media-library' ),
						value:    columns,
						onChange: function ( v ) { setAttributes( { columns: v } ); },
						min:      1,
						max:      8,
					} ),
					el( ToggleControl, {
						label:    __( 'Crop images to fill cells', 'protected-media-library' ),
						help:     __( 'When off, images retain their aspect ratio (rows may be uneven).', 'protected-media-library' ),
						checked:  !! attributes.imageCrop,
						onChange: function ( v ) { setAttributes( { imageCrop: !! v } ); },
					} )
				)
			),
			el( 'div', blockProps,
				el( 'span', { className: 'pml-protected-badge', 'aria-label': __( 'Protected', 'protected-media-library' ) }, '🔒 Protected' ),
				el( 'div', {
					className: 'pml-gallery-grid' + ( attributes.imageCrop ? ' is-cropped' : '' ),
					style:     gridStyle,
				},
					ids.map( function ( id ) {
						var att = cache[ 0 ][ id ];
						var thumb = att && att.sizes && att.sizes.medium
							? att.sizes.medium.url
							: ( att && att.sizes && att.sizes.thumbnail ? att.sizes.thumbnail.url : ( att && att.url ) );
						return el( 'figure', { key: id, className: 'pml-gallery-item' },
							thumb
								? el( 'img', { src: thumb, alt: att && att.alt ? att.alt : '' } )
								: el( 'span', { className: 'pml-gallery-loading' }, '…' ),
							el( 'button', {
								type:      'button',
								className: 'pml-gallery-remove',
								onClick:   function () { removeId( id ); },
								'aria-label': __( 'Remove from gallery', 'protected-media-library' ),
							}, '×' )
						);
					} )
				)
			),
			pickerEl
		);
	}
}( window.wp ) );
