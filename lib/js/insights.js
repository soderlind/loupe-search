/**
 * Search insights report: show the custom range dates in the site's date format.
 *
 * Native date inputs always use the browser locale. jQuery UI Datepicker is
 * configured by WordPress core from Settings → General (date format, month
 * names, start of week), so the visible fields use that while hidden fields
 * keep submitting Y-m-d.
 */
/* global jQuery */
( function ( $, __ ) {
	$( function () {
		if ( ! $.datepicker ) {
			return;
		}

		const iso = ( date ) => $.datepicker.parseDate( 'yy-mm-dd', date );
		const fields = [];

		$( '.loupe-search-insights__range input[type="date"]' ).each(
			function () {
				const $input = $( this );
				const value = $input.val();
				const max = $input.attr( 'max' )
					? iso( $input.attr( 'max' ) )
					: null;
				const $hidden = $( '<input type="hidden" />' )
					.attr( 'name', $input.attr( 'name' ) )
					.val( value )
					.insertAfter( $input );

				$input
					.removeAttr( 'name' )
					.attr( { type: 'text', autocomplete: 'off' } )
					.val( '' )
					.datepicker( {
						altField: $hidden,
						altFormat: 'yy-mm-dd',
						maxDate: max,
						changeMonth: true,
						changeYear: true,
					} )
					.on( 'input change', function () {
						this.setCustomValidity( '' );
					} );

				if ( value ) {
					$input.datepicker( 'setDate', iso( value ) );
				}

				fields.push( { $input, $hidden, max } );
			}
		);

		// Typed text does not update the hidden field when it cannot be parsed,
		// so validate the visible value and only then sync the hidden Y-m-d.
		$( '.loupe-search-insights__range' ).on( 'submit', function ( event ) {
			let firstInvalid = null;

			fields.forEach( ( { $input, $hidden, max } ) => {
				const input = $input.get( 0 );
				let date = null;
				try {
					date = $.datepicker.parseDate(
						$input.datepicker( 'option', 'dateFormat' ),
						$input.val()
					);
				} catch {
					date = null;
				}

				if ( ! date || ( max && date > max ) ) {
					input.setCustomValidity(
						__(
							'Enter a valid date that is not in the future.',
							'loupe-search'
						)
					);
					firstInvalid = firstInvalid || input;
					return;
				}

				input.setCustomValidity( '' );
				$hidden.val( $.datepicker.formatDate( 'yy-mm-dd', date ) );
			} );

			if ( firstInvalid ) {
				event.preventDefault();
				firstInvalid.reportValidity();
			}
		} );
	} );
} )( jQuery, window.wp && wp.i18n && wp.i18n.__ ? wp.i18n.__ : ( s ) => s );
