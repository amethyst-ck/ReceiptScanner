/**
 * Reflect the selected files next to the "Choose files…" button.
 * The real <input type="file"> is hidden via CSS, so the browser's
 * native "N files selected" text isn't visible — we render our own.
 */
( function () {
	'use strict';
	$( function () {
		var $input = $( '.rs-upload-input' );
		var $chosen = $( '.rs-upload-chosen' );
		var $count = $( '.rs-upload-count' );
		if ( !$input.length || !$chosen.length ) {
			return;
		}
		$input.on( 'change', function () {
			var files = this.files;
			var n = files ? files.length : 0;
			// Stash the selected file count for server-side truncation
			// detection — if PHP's max_file_uploads truncates the upload,
			// the server can compare $_FILES count to this value.
			$count.val( n );
			if ( n === 0 ) {
				$chosen.text( '' );
				return;
			}
			if ( n === 1 ) {
				$chosen.text( files[ 0 ].name );
				return;
			}
			$chosen.text( mw.message( 'receiptscanner-upload-files-selected', n ).text() );
		} );
	} );
}() );
