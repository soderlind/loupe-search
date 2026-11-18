/**
 * Search insights report: show the custom range dates in the site's date format.
 *
 * Native date inputs always use the browser locale. jQuery UI Datepicker is
 * configured by WordPress core from Settings → General (date format, month
 * names, start of week), so the visible fields use that while hidden fields
 * keep submitting Y-m-d.
 */
/* global jQuery */
( function ( $ ) {
	$( function () {
		if ( ! $.datepicker ) {
			return;
		}

		$( '.loupe-search-insights__range input[type="date"]' ).each(
			function () {
				const $input = $( this );
				const iso = ( date ) =>
					$.datepicker.parseDate( 'yy-mm-dd', date );
				const value = $input.val();
				const max = $input.attr( 'max' );
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
						maxDate: max ? iso( max ) : null,
						changeMonth: true,
						changeYear: true,
					} );

				if ( value ) {
					$input.datepicker( 'setDate', iso( value ) );
				}
			}
		);
	} );
} )( jQuery );
