/**
 * Classic Editor glue.
 *
 * Mounts the shared React picker (window.pmlPicker.PickerModal) into a
 * detached div, listens for clicks on the two media buttons we render in
 * PML_Classic_Editor::render_buttons, and inserts the appropriate shortcode
 * into the active editor on Insert.
 *
 * Why detached mount: the picker is a Modal that portals itself to body,
 * so the parent React tree just needs to exist somewhere — it doesn't have
 * to be inside the editor's DOM.
 */
( function ( $, wp ) {
	if ( ! wp || ! wp.element || ! wp.components || ! window.pmlPicker ) {
		return;
	}

	var el          = wp.element.createElement;
	var pml         = window.pmlPicker;
	var mountEl     = null;
	var root        = null;

	function ensureMount() {
		if ( ! mountEl ) {
			mountEl = document.createElement( 'div' );
			mountEl.id = 'pml-classic-picker-mount';
			document.body.appendChild( mountEl );
		}
		if ( ! root && wp.element.createRoot ) {
			root = wp.element.createRoot( mountEl );
		}
	}

	function unmount() {
		if ( root ) {
			root.render( null );
		} else if ( wp.element.unmountComponentAtNode ) {
			wp.element.unmountComponentAtNode( mountEl );
		}
	}

	function insertIntoEditor( shortcode ) {
		// wp.media.editor.insert is the documented way to insert content at the
		// current TinyMCE / textarea caret position. Works in both Visual and
		// Text modes; falls back to the currently-active editor.
		if ( wp.media && wp.media.editor && typeof wp.media.editor.insert === 'function' ) {
			wp.media.editor.insert( shortcode );
		} else if ( window.send_to_editor ) {
			window.send_to_editor( shortcode );
		} else {
			// Last-resort: just put it in the textarea.
			var ta = document.getElementById( 'content' );
			if ( ta ) {
				ta.value += "\n" + shortcode + "\n";
			}
		}
	}

	function shortcodeFor( attachment ) {
		var id   = parseInt( attachment.id, 10 );
		var mime = attachment.mime || '';
		if ( mime.indexOf( 'image/' ) === 0 ) {
			return '[pml-image id="' + id + '"]';
		}
		if ( mime.indexOf( 'audio/' ) === 0 ) {
			return '[pml-audio id="' + id + '"]';
		}
		if ( mime.indexOf( 'video/' ) === 0 ) {
			return '[pml-video id="' + id + '"]';
		}
		// PDFs and everything else → file shortcode with display + mime so
		// the rendered card shows the right label and the inline preview can
		// kick in for PDFs.
		var display = ( attachment.title || '' ).replace( /"/g, '' );
		return '[pml-file id="' + id + '" display="' + display + '" mime="' + mime + '"]';
	}

	function galleryShortcodeFor( attachments ) {
		var ids = attachments.map( function ( a ) { return parseInt( a.id, 10 ); } );
		return '[pml-gallery ids="' + ids.join( ',' ) + '" columns="3"]';
	}

	function open( mode ) {
		ensureMount();

		function close() {
			unmount();
		}

		function onPickSingle( att ) {
			if ( att ) {
				insertIntoEditor( shortcodeFor( att ) );
			}
			close();
		}

		function onPickMulti( atts ) {
			if ( Array.isArray( atts ) && atts.length ) {
				insertIntoEditor( galleryShortcodeFor( atts ) );
			}
			close();
		}

		var props = mode === 'gallery'
			? {
				isOpen:            true,
				onClose:           close,
				onPick:            onPickMulti,
				mime:              'image',
				mode:              'grid',
				multiple:          true,
				title:             'Protected Library — Images',
				searchPlaceholder: 'Search protected images…',
				emptyMessage:      'No protected images yet.',
				insertLabel:       'Insert gallery',
			}
			: {
				isOpen:            true,
				onClose:           close,
				onPick:            onPickSingle,
				mime:              '',
				mode:              'grid',
				title:             'Protected Library',
				searchPlaceholder: 'Search protected media…',
				emptyMessage:      'No protected files yet.',
			};

		var element = el( pml.PickerModal, props );
		if ( root ) {
			root.render( element );
		} else if ( wp.element.render ) {
			wp.element.render( element, mountEl );
		}
	}

	$( document ).on( 'click', '.pml-classic-insert', function ( e ) {
		e.preventDefault();
		open( $( this ).data( 'pml-mode' ) === 'gallery' ? 'gallery' : 'single' );
	} );
}( jQuery, window.wp ) );
