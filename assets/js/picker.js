/**
 * Shared picker module for Protected Media blocks.
 *
 * Exposes `window.pmlPicker`:
 *   - PickerModal(props)  React component, renders the modal when isOpen
 *   - upload(file)         returns a Promise resolving to the serialized
 *                          attachment from POST /pml/v1/upload
 *   - iconForMime(mime)    emoji icon used in list mode
 *
 * Props for PickerModal:
 *   isOpen              bool
 *   onClose             ()
 *   onPick              single-mode: (attachment) => void
 *                       multiple-mode: (attachment[]) => void
 *   mime                'image' | 'audio' | 'video' | '' (empty = all)
 *   mode                'grid' | 'list' (default 'list')
 *   multiple            bool — if true, click to toggle selection;
 *                       commit with Insert button (sends an array).
 *   initialSelection    optional array of attachments to pre-select in
 *                       multiple mode (e.g. when reopening to edit a
 *                       gallery).
 *   title               modal title
 *   searchPlaceholder   text for search input
 *   emptyMessage        shown when zero results
 *   insertLabel         text on the Insert button (multiple mode);
 *                       default "Insert N item(s)"
 *
 * Each block's index.js declares `pml-picker` as a script dependency
 * (via index.asset.php) so this loads first and window.pmlPicker is
 * available when their registerBlockType runs.
 */
( function ( wp ) {
	if ( ! wp || ! wp.element || ! wp.components || ! wp.apiFetch ) {
		return;
	}

	var el       = wp.element.createElement;
	var useState = wp.element.useState;
	var useEffect = wp.element.useEffect;
	var __       = ( wp.i18n && wp.i18n.__ ) || function ( s ) { return s; };
	var apiFetch = wp.apiFetch;

	var Modal          = wp.components.Modal;
	var Button         = wp.components.Button;
	var Spinner        = wp.components.Spinner;
	var TextControl    = wp.components.TextControl;
	var FormFileUpload = wp.components.FormFileUpload;

	function iconForMime( mime ) {
		if ( ! mime ) { return '📄'; }
		if ( mime === 'application/pdf' )       { return '📕'; }
		if ( mime.indexOf( 'image/' ) === 0 )   { return '🖼️'; }
		if ( mime.indexOf( 'video/' ) === 0 )   { return '🎞️'; }
		if ( mime.indexOf( 'audio/' ) === 0 )   { return '🎵'; }
		if ( mime.indexOf( 'text/' ) === 0 )    { return '📃'; }
		if ( mime.indexOf( 'application/zip' ) === 0 ) { return '🗜️'; }
		if ( mime.indexOf( 'word' ) >= 0 || mime.indexOf( 'document' ) >= 0 ) { return '📘'; }
		if ( mime.indexOf( 'sheet' ) >= 0 || mime.indexOf( 'excel' ) >= 0 )   { return '📊'; }
		return '📄';
	}

	function upload( file ) {
		var fd = new FormData();
		fd.append( 'file', file );
		// Auto-attach to the post being edited, so the file isn't orphaned
		// as "(Unattached)" in the Protected Library. Only works inside a
		// block-editor context; falls back to unattached (post_id=0) outside.
		var postId = currentEditingPostId();
		if ( postId ) {
			fd.append( 'post_id', String( postId ) );
		}
		return apiFetch( { path: '/pml/v1/upload', method: 'POST', body: fd } )
			.catch( function ( err ) {
				// apiFetch hits this when the webserver (nginx/apache) rejects
				// the request before PHP runs — typically nginx client_max_body_size
				// or apache LimitRequestBody. The body is HTML so JSON parsing
				// fails. Translate to an actionable message based on file size.
				if ( err && err.code === 'invalid_json' ) {
					var sizeMb = ( file && file.size ? ( file.size / ( 1024 * 1024 ) ).toFixed( 1 ) : '?' );
					throw {
						code:    'pml_server_rejected',
						message: __( 'Server rejected the upload', 'protected-media-library' )
							+ ' (' + sizeMb + ' MB). '
							+ __( 'It usually means the file exceeds the webserver\'s body-size limit (nginx client_max_body_size / apache LimitRequestBody). Ask your host to raise it, or compress the file.', 'protected-media-library' ),
					};
				}
				throw err;
			} );
	}

	// Reparent already-uploaded attachments to the current post when the user
	// picks them — without this, an attachment uploaded earlier (or from the
	// admin Add page) stays "(Unattached)" even after being inserted into a
	// post. Mirrors WP's media-frame attach-on-insert behavior. Fire-and-forget.
	function attachToCurrentPost( ids ) {
		var postId = currentEditingPostId();
		if ( ! postId || ! ids || ! ids.length ) { return; }
		apiFetch( {
			path:   '/pml/v1/attach',
			method: 'POST',
			data:   { ids: ids.join( ',' ), post_id: postId },
		} ).catch( function () { /* non-fatal */ } );
	}

	function currentEditingPostId() {
		// Block editor.
		try {
			if ( wp.data && typeof wp.data.select === 'function' ) {
				var editor = wp.data.select( 'core/editor' );
				if ( editor && typeof editor.getCurrentPostId === 'function' ) {
					var id = editor.getCurrentPostId();
					if ( id ) { return id; }
				}
			}
		} catch ( e ) {}
		// Classic Editor fallback: WP renders <input type="hidden" id="post_ID">
		// on post.php / post-new.php.
		var hidden = document.getElementById( 'post_ID' );
		if ( hidden && hidden.value ) {
			var parsed = parseInt( hidden.value, 10 );
			if ( parsed > 0 ) { return parsed; }
		}
		return 0;
	}

	function PickerModal( props ) {
		if ( ! props.isOpen ) {
			return null;
		}

		var mode     = props.mode === 'grid' ? 'grid' : 'list';
		var mime     = typeof props.mime === 'string' ? props.mime : '';
		var multiple = !! props.multiple;

		// `selected` is keyed by attachment ID and holds the full attachment
		// object, so selections survive pagination/search (we don't need to
		// refetch them on Insert).
		var initialSelected = {};
		if ( multiple && Array.isArray( props.initialSelection ) ) {
			props.initialSelection.forEach( function ( a ) {
				if ( a && a.id ) { initialSelected[ a.id ] = a; }
			} );
		}

		var state = useState( {
			items:      [],
			loading:    false,
			loaded:     false, // true once first fetch resolves
			page:       1,
			totalPages: 1,
			search:     '',
			error:      null,
			selected:   initialSelected,
			uploading:  false,
		} );

		function fetchPage( page, search ) {
			state[ 1 ]( Object.assign( {}, state[ 0 ], { loading: true, error: null } ) );
			var path = '/pml/v1/library?mime=' + encodeURIComponent( mime )
				+ '&page=' + encodeURIComponent( page );
			if ( search ) {
				path += '&search=' + encodeURIComponent( search );
			}
			apiFetch( { path: path } )
				.then( function ( res ) {
					state[ 1 ]( Object.assign( {}, state[ 0 ], {
						items:      res.items || [],
						loading:    false,
						loaded:     true,
						page:       res.page || page,
						totalPages: res.totalPages || 1,
						search:     search,
						error:      null,
					} ) );
				} )
				.catch( function ( err ) {
					state[ 1 ]( Object.assign( {}, state[ 0 ], {
						loading: false,
						loaded:  true,
						error:   ( err && err.message ) || __( 'Failed to load library.', 'protected-media-library' ),
					} ) );
				} );
		}

		function toggleSelect( item ) {
			var sel = Object.assign( {}, state[ 0 ].selected );
			if ( sel[ item.id ] ) {
				delete sel[ item.id ];
			} else {
				sel[ item.id ] = item;
			}
			state[ 1 ]( Object.assign( {}, state[ 0 ], { selected: sel } ) );
		}

		function commitMultiple() {
			var items = Object.keys( state[ 0 ].selected ).map( function ( k ) {
				return state[ 0 ].selected[ k ];
			} );
			attachToCurrentPost( items.map( function ( a ) { return a.id; } ) );
			if ( props.onPick ) { props.onPick( items ); }
		}

		function isSelected( id ) {
			return !! state[ 0 ].selected[ id ];
		}

		// Auto-fetch on first open.
		useEffect( function () {
			if ( props.isOpen && ! state[ 0 ].loaded && ! state[ 0 ].loading ) {
				fetchPage( 1, '' );
			}
		}, [ props.isOpen ] );

		function setSearch( v ) {
			state[ 1 ]( Object.assign( {}, state[ 0 ], { search: v } ) );
		}

		function close() {
			if ( props.onClose ) { props.onClose(); }
		}

		function pick( item ) {
			if ( multiple ) {
				toggleSelect( item );
				return;
			}
			attachToCurrentPost( [ item.id ] );
			if ( props.onPick ) { props.onPick( item ); }
		}

		function handleUpload( files ) {
			if ( ! files || ! files.length ) { return; }
			state[ 1 ]( Object.assign( {}, state[ 0 ], { uploading: true, error: null } ) );
			upload( files[ 0 ] )
				.then( function ( att ) {
					if ( multiple ) {
						// Prepend to items, auto-select, stay in modal so the
						// user can keep picking.
						var newItems = [ att ].concat( state[ 0 ].items.filter( function ( x ) { return x.id !== att.id; } ) );
						var sel = Object.assign( {}, state[ 0 ].selected );
						sel[ att.id ] = att;
						state[ 1 ]( Object.assign( {}, state[ 0 ], {
							items:     newItems,
							selected:  sel,
							uploading: false,
						} ) );
					} else {
						state[ 1 ]( Object.assign( {}, state[ 0 ], { uploading: false } ) );
						// upload() already attached via post_id form field;
						// no need to call attachToCurrentPost here.
						if ( props.onPick ) { props.onPick( att ); }
					}
				} )
				.catch( function ( err ) {
					state[ 1 ]( Object.assign( {}, state[ 0 ], {
						uploading: false,
						error:     ( err && err.message ) || __( 'Upload failed.', 'protected-media-library' ),
					} ) );
				} );
		}

		var selectedCount = Object.keys( state[ 0 ].selected ).length;

		return el( Modal, {
			title:          props.title || __( 'Protected Library', 'protected-media-library' ),
			onRequestClose: close,
			className:      'pml-picker-modal',
			size:           'large',
		},
			el( 'div', { className: 'pml-picker-toolbar' },
				el( TextControl, {
					label:               __( 'Search', 'protected-media-library' ),
					hideLabelFromVision: true,
					placeholder:         props.searchPlaceholder || __( 'Search…', 'protected-media-library' ),
					value:               state[ 0 ].search,
					onChange:            setSearch,
				} ),
				el( Button, {
					variant: 'secondary',
					onClick: function () { fetchPage( 1, state[ 0 ].search ); },
				}, __( 'Search', 'protected-media-library' ) ),
				el( FormFileUpload, {
					accept:   mime ? mime + '/*' : '',
					onChange: function ( e ) { handleUpload( e.target.files ); },
					variant:  'primary',
					disabled: state[ 0 ].uploading,
				}, state[ 0 ].uploading
					? __( 'Uploading…', 'protected-media-library' )
					: __( 'Upload', 'protected-media-library' )
				)
			),
			state[ 0 ].error
				? el( 'p', { className: 'pml-picker-error' }, state[ 0 ].error )
				: state[ 0 ].loading
					? el( 'div', { className: 'pml-picker-loading' }, el( Spinner ) )
					: state[ 0 ].items.length === 0
						? el( 'p', { className: 'pml-picker-empty' },
							props.emptyMessage || __( 'Nothing here yet.', 'protected-media-library' ) )
						: mode === 'grid'
							? renderGrid( state[ 0 ].items, pick, isSelected )
							: renderList( state[ 0 ].items, pick, isSelected ),
			multiple && el( 'div', { className: 'pml-picker-multi-footer' },
				el( 'span', { className: 'pml-picker-multi-count' },
					selectedCount === 0
						? __( 'Nothing selected', 'protected-media-library' )
						: ( selectedCount === 1
							? __( '1 item selected', 'protected-media-library' )
							: selectedCount + ' ' + __( 'items selected', 'protected-media-library' ) )
				),
				el( Button, {
					variant: 'tertiary',
					onClick: close,
				}, __( 'Cancel', 'protected-media-library' ) ),
				el( Button, {
					variant:  'primary',
					disabled: selectedCount === 0,
					onClick:  commitMultiple,
				}, props.insertLabel || ( selectedCount === 0
					? __( 'Insert', 'protected-media-library' )
					: __( 'Insert', 'protected-media-library' ) + ' ' + selectedCount )
				)
			),
			state[ 0 ].totalPages > 1 && el( 'div', { className: 'pml-picker-pager' },
				el( Button, {
					variant:  'secondary',
					disabled: state[ 0 ].page <= 1,
					onClick:  function () { fetchPage( state[ 0 ].page - 1, state[ 0 ].search ); },
				}, __( '← Previous', 'protected-media-library' ) ),
				el( 'span', null, ' ' + __( 'Page', 'protected-media-library' )
					+ ' ' + state[ 0 ].page + ' / ' + state[ 0 ].totalPages + ' ' ),
				el( Button, {
					variant:  'secondary',
					disabled: state[ 0 ].page >= state[ 0 ].totalPages,
					onClick:  function () { fetchPage( state[ 0 ].page + 1, state[ 0 ].search ); },
				}, __( 'Next →', 'protected-media-library' ) )
			)
		);
	}

	function renderGrid( items, pick, isSelected ) {
		return el( 'div', { className: 'pml-picker-grid' },
			items.map( function ( it ) {
				var isImage = it.mime && it.mime.indexOf( 'image/' ) === 0;
				var thumb   = it.sizes && it.sizes.thumbnail ? it.sizes.thumbnail.url : null;
				var selected = isSelected && isSelected( it.id );
				var visual = ( isImage && thumb )
					? el( 'img', { src: thumb, alt: it.alt || '' } )
					: el( 'span', { className: 'pml-picker-item-icon', 'aria-hidden': 'true' }, iconForMime( it.mime ) );
				return el( 'button', {
					key:       it.id,
					type:      'button',
					className: 'pml-picker-item' + ( selected ? ' is-selected' : '' ),
					onClick:   function () { pick( it ); },
				},
					el( 'span', { className: 'pml-picker-item-image' }, visual ),
					el( 'span', { className: 'pml-picker-item-title' }, it.title ),
					selected && el( 'span', { className: 'pml-picker-item-check', 'aria-hidden': 'true' }, '✓' )
				);
			} )
		);
	}

	function renderList( items, pick, isSelected ) {
		return el( 'div', { className: 'pml-picker-list' },
			items.map( function ( it ) {
				var selected = isSelected && isSelected( it.id );
				var thumbUrl = it.sizes && it.sizes.thumbnail ? it.sizes.thumbnail.url : null;
				var isImage  = it.mime && it.mime.indexOf( 'image/' ) === 0;
				var iconEl   = ( isImage && thumbUrl )
					? el( 'span', { className: 'pml-picker-row-thumb' },
						el( 'img', { src: thumbUrl, alt: it.alt || '' } ) )
					: el( 'span', { className: 'pml-picker-row-icon', 'aria-hidden': 'true' }, iconForMime( it.mime ) );
				return el( 'button', {
					key:       it.id,
					type:      'button',
					className: 'pml-picker-row' + ( selected ? ' is-selected' : '' ),
					onClick:   function () { pick( it ); },
				},
					iconEl,
					el( 'span', { className: 'pml-picker-row-meta' },
						el( 'span', { className: 'pml-picker-row-title' }, it.title ),
						el( 'span', { className: 'pml-picker-row-mime' }, it.mime || '' )
					),
					selected && el( 'span', { className: 'pml-picker-row-check', 'aria-hidden': 'true' }, '✓' )
				);
			} )
		);
	}

	window.pmlPicker = {
		PickerModal:           PickerModal,
		upload:                upload,
		iconForMime:           iconForMime,
		currentEditingPostId:  currentEditingPostId,
	};
}( window.wp ) );
