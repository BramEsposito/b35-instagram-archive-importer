/* global wp */
( function () {
	'use strict';

	/**
	 * Wires up a "Select file" button to the WP media modal.
	 *
	 * @param {string} buttonId  ID of the trigger button element.
	 * @param {string} inputId   ID of the hidden attachment-ID input.
	 * @param {string} displayId ID of the element that shows the filename.
	 */
	function attachMediaUploader( buttonId, inputId, displayId ) {
		var button = document.getElementById( buttonId );
		if ( ! button ) {
			return;
		}

		var frame;

		button.addEventListener( 'click', function ( e ) {
			e.preventDefault();

			if ( frame ) {
				frame.open();
				return;
			}

			frame = wp.media( {
				title:    button.dataset.title || 'Select File',
				button:   { text: 'Use this file' },
				multiple: false,
			} );

			frame.on( 'select', function () {
				var attachment = frame.state().get( 'selection' ).first().toJSON();
				document.getElementById( inputId ).value            = attachment.id;
				document.getElementById( displayId ).textContent    = attachment.filename;
			} );

			frame.open();
		} );
	}

	attachMediaUploader( 'b35-ig-select-json', 'b35-ig-json-id', 'b35-ig-json-name' );
	attachMediaUploader( 'b35-ig-select-csv',  'b35-ig-csv-id',  'b35-ig-csv-name' );
}() );
