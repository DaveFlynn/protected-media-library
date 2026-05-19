/**
 * Add Protected Media File page — vanilla drag-drop uploader.
 *
 * Talks directly to the REST endpoint /pml/v1/upload (the same one the block
 * editor uploads go through). No plupload, no wp.Uploader, no WP media UI.
 * One file per XHR; queue is processed one at a time so a single failure
 * doesn't block the rest.
 */
( function () {
	if ( typeof window.PMLAddPage === 'undefined' || ! window.wp || ! wp.apiFetch ) {
		return;
	}

	var cfg = window.PMLAddPage;

	document.addEventListener( 'DOMContentLoaded', function () {
		var zone   = document.getElementById( 'pml-dropzone' );
		var input  = document.getElementById( 'pml-file-input' );
		var list   = document.getElementById( 'pml-upload-list' );
		if ( ! zone || ! input || ! list ) { return; }

		zone.addEventListener( 'click',   function ()  { input.click(); } );
		zone.addEventListener( 'keydown', function ( e ) {
			if ( e.key === 'Enter' || e.key === ' ' ) {
				e.preventDefault();
				input.click();
			}
		} );

		zone.addEventListener( 'dragover', function ( e ) {
			e.preventDefault();
			zone.classList.add( 'is-dragover' );
		} );
		zone.addEventListener( 'dragleave', function () {
			zone.classList.remove( 'is-dragover' );
		} );
		zone.addEventListener( 'drop', function ( e ) {
			e.preventDefault();
			zone.classList.remove( 'is-dragover' );
			queueFiles( e.dataTransfer.files );
		} );

		input.addEventListener( 'change', function () {
			queueFiles( input.files );
			input.value = ''; // allow re-selecting the same file
		} );

		// --- queue ---
		var queue   = [];
		var busy    = false;

		function queueFiles( fileList ) {
			if ( ! fileList || ! fileList.length ) { return; }
			Array.prototype.forEach.call( fileList, function ( f ) {
				var item = renderQueuedItem( f );
				queue.push( { file: f, row: item } );
			} );
			processQueue();
		}

		function processQueue() {
			if ( busy || queue.length === 0 ) { return; }
			busy = true;
			var entry = queue.shift();
			upload( entry.file, entry.row ).finally( function () {
				busy = false;
				processQueue();
			} );
		}

		function upload( file, row ) {
			setRowState( row, 'uploading', cfg.i18n.uploading );

			var fd = new FormData();
			fd.append( 'file', file );

			return wp.apiFetch( {
				path: '/pml/v1/upload',
				method: 'POST',
				body: fd,
			} )
				.then( function ( att ) {
					setRowState( row, 'done', att );
				} )
				.catch( function ( err ) {
					var msg = ( err && err.message ) || cfg.i18n.failed;
					setRowState( row, 'error', msg );
				} );
		}

		// --- row rendering ---
		function renderQueuedItem( file ) {
			var li = document.createElement( 'li' );
			li.className = 'pml-upload-item is-queued';
			li.innerHTML =
				'<span class="pml-upload-icon" aria-hidden="true">📄</span>' +
				'<div class="pml-upload-meta">' +
					'<span class="pml-upload-name"></span>' +
					'<span class="pml-upload-status">' + escapeHtml( cfg.i18n.uploading ) + '</span>' +
				'</div>' +
				'<button type="button" class="button-link pml-upload-remove" aria-label="' + escapeHtml( cfg.i18n.remove ) + '">×</button>';
			li.querySelector( '.pml-upload-name' ).textContent = file.name;
			li.querySelector( '.pml-upload-remove' ).addEventListener( 'click', function () {
				li.remove();
			} );
			list.prepend( li );
			return li;
		}

		function setRowState( row, state, payload ) {
			row.classList.remove( 'is-queued', 'is-uploading', 'is-done', 'is-error' );
			row.classList.add( 'is-' + state );
			var statusEl = row.querySelector( '.pml-upload-status' );
			var iconEl   = row.querySelector( '.pml-upload-icon' );
			if ( state === 'uploading' ) {
				statusEl.textContent = payload;
				iconEl.textContent   = '⏳';
			} else if ( state === 'done' ) {
				iconEl.textContent = '🔒';
				statusEl.innerHTML =
					'<a class="pml-upload-link" href="' + escapeAttr( cfg.libraryUrl ) + '">' +
						escapeHtml( cfg.i18n.viewInLib ) +
					'</a>';
				// Replace name with the (filename-derived) title from server.
				if ( payload && payload.title ) {
					row.querySelector( '.pml-upload-name' ).textContent = payload.title;
				}
			} else if ( state === 'error' ) {
				iconEl.textContent   = '⚠️';
				statusEl.textContent = payload;
			}
		}

		function escapeHtml( s ) {
			return String( s ).replace( /[&<>"']/g, function ( c ) {
				return ( { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ c ] );
			} );
		}
		function escapeAttr( s ) { return escapeHtml( s ); }
	} );
}() );
