/**
 * ACF "Protected Image" field UI.
 *
 * Wires the three buttons rendered by PML_ACF_Field_Image::render_field to the
 * shared picker (window.pmlPicker):
 *   - Upload  → hidden file input → pmlPicker.upload(file) → set value
 *   - Select  → pmlPicker.PickerModal (browse protected library) → set value
 *   - Remove  → clear value
 *
 * The picker Modal portals itself to <body>, so we mount its React root in a
 * single detached div (same approach as the Classic Editor glue) and re-render
 * it per open. Event handlers are delegated on document so fields added
 * dynamically (repeaters/flexible content) work without re-binding.
 */
( function ( $, wp ) {
	if ( ! wp || ! wp.element || ! window.pmlPicker ) {
		return;
	}

	var el      = wp.element.createElement;
	var pml     = window.pmlPicker;
	var __      = ( wp.i18n && wp.i18n.__ ) || function ( s ) { return s; };
	var mountEl = null;
	var root    = null;

	function ensureMount() {
		if ( ! mountEl ) {
			mountEl = document.createElement( 'div' );
			mountEl.id = 'pml-acf-image-mount';
			document.body.appendChild( mountEl );
		}
		if ( ! root && wp.element.createRoot ) {
			root = wp.element.createRoot( mountEl );
		}
	}

	function closePicker() {
		if ( root ) {
			root.render( null );
		} else if ( wp.element.unmountComponentAtNode ) {
			wp.element.unmountComponentAtNode( mountEl );
		}
	}

	function openPicker( onPick ) {
		ensureMount();
		var element = el( pml.PickerModal, {
			isOpen:            true,
			onClose:           closePicker,
			onPick:            function ( att ) { if ( att ) { onPick( att ); } closePicker(); },
			mime:              'image',
			mode:              'grid',
			title:             __( 'Protected Library — Images', 'protected-media-library' ),
			searchPlaceholder: __( 'Search protected images…', 'protected-media-library' ),
			emptyMessage:      __( 'No protected images yet. Use Upload to add one.', 'protected-media-library' ),
		} );
		if ( root ) {
			root.render( element );
		} else if ( wp.element.render ) {
			wp.element.render( element, mountEl );
		}
	}

	function previewUrlFor( att, size ) {
		if ( att.sizes && att.sizes[ size ] && att.sizes[ size ].url ) {
			return att.sizes[ size ].url;
		}
		if ( att.sizes && att.sizes.medium && att.sizes.medium.url ) {
			return att.sizes.medium.url;
		}
		return att.url;
	}

	function setValue( $wrap, att ) {
		var $input = $wrap.find( '.pml-acf-image-input' );
		var size   = $input.data( 'preview-size' ) || 'medium';
		$input.val( att.id ).trigger( 'change' ); // trigger so ACF marks the form dirty
		$wrap.find( '.pml-acf-image-preview' )
			.html( $( '<img>' ).attr( 'src', previewUrlFor( att, size ) ).attr( 'alt', '' ) );
		$wrap.addClass( 'has-value' );
		$wrap.find( '.pml-acf-image-remove' ).show();
	}

	function clearValue( $wrap ) {
		$wrap.find( '.pml-acf-image-input' ).val( '' ).trigger( 'change' );
		$wrap.find( '.pml-acf-image-preview' ).empty();
		$wrap.removeClass( 'has-value' );
		$wrap.find( '.pml-acf-image-remove' ).hide();
	}

	/* ---- Select from library ---- */
	$( document ).on( 'click', '.pml-acf-image-select', function ( e ) {
		e.preventDefault();
		var $wrap = $( this ).closest( '.pml-acf-image' );
		openPicker( function ( att ) { setValue( $wrap, att ); } );
	} );

	/* ---- Upload ---- */
	$( document ).on( 'click', '.pml-acf-image-upload', function ( e ) {
		e.preventDefault();
		var $wrap  = $( this ).closest( '.pml-acf-image' );
		var $btn   = $( this );
		var picker = document.createElement( 'input' );
		picker.type   = 'file';
		picker.accept = 'image/*';
		picker.addEventListener( 'change', function () {
			if ( ! picker.files || ! picker.files.length ) { return; }
			var original = $btn.text();
			$btn.prop( 'disabled', true ).text( __( 'Uploading…', 'protected-media-library' ) );
			pml.upload( picker.files[ 0 ] )
				.then( function ( att ) {
					$btn.prop( 'disabled', false ).text( original );
					setValue( $wrap, att );
				} )
				.catch( function ( err ) {
					$btn.prop( 'disabled', false ).text( original );
					window.alert( ( err && err.message ) || __( 'Upload failed.', 'protected-media-library' ) );
				} );
		} );
		picker.click();
	} );

	/* ---- Remove ---- */
	$( document ).on( 'click', '.pml-acf-image-remove', function ( e ) {
		e.preventDefault();
		clearValue( $( this ).closest( '.pml-acf-image' ) );
	} );
}( jQuery, window.wp ) );
