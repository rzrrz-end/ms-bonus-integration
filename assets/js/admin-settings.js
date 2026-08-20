( function ( $ ) {
	'use strict';

	function formatValue( value, fallback ) {
		if ( value === null || value === undefined || value === '' ) {
			return fallback;
		}

		return value;
	}

	function renderProgramInfo( program ) {
		var i18n = msBonusAdmin.i18n;
		var html = '<table class="widefat striped ms-bonus-program-table"><tbody>';

		html += '<tr><th scope="row">' + i18n.earnRate + '</th><td>' +
			formatValue( program.earnRateRoublesToPoint, i18n.notAvailable ) + '</td></tr>';
		html += '<tr><th scope="row">' + i18n.spendRate + '</th><td>' +
			formatValue( program.spendRatePointsToRouble, i18n.notAvailable ) + '</td></tr>';
		html += '<tr><th scope="row">' + i18n.maxPaidRate + '</th><td>' +
			formatValue( program.maxPaidRatePercents, i18n.notAvailable ) + '</td></tr>';
		html += '<tr><th scope="row">' + i18n.earnWhileRedeeming + '</th><td>' +
			( program.earnWhileRedeeming ? i18n.yes : i18n.no ) + '</td></tr>';
		html += '</tbody></table>';

		$( '#ms-bonus-program-info' ).html( html );
	}

	$( document ).on( 'click', '#ms-bonus-test-connection', function ( event ) {
		event.preventDefault();

		var $button = $( this );
		var $result = $( '#ms-bonus-test-result' );
		var bonusProgramId = $( '#ms_bonus_bonus_program_id' ).val();

		$button.prop( 'disabled', true );
		$result.removeClass( 'is-success is-error' ).text( msBonusAdmin.i18n.testing );

		$.post(
			msBonusAdmin.ajaxUrl,
			{
				action: 'ms_bonus_test_connection',
				nonce: msBonusAdmin.nonce,
				bonus_program_id: bonusProgramId
			}
		)
			.done( function ( response ) {
				if ( ! response || ! response.success ) {
					var errorMessage = response && response.data && response.data.message
						? response.data.message
						: msBonusAdmin.i18n.error;

					$result.addClass( 'is-error' ).text( errorMessage );
					return;
				}

				$result.addClass( 'is-success' ).text( response.data.message || msBonusAdmin.i18n.success );

				if ( response.data.program ) {
					renderProgramInfo( response.data.program );
				}
			} )
			.fail( function ( xhr ) {
				var message = msBonusAdmin.i18n.error;

				if ( xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message ) {
					message = xhr.responseJSON.data.message;
				}

				$result.addClass( 'is-error' ).text( message );
			} )
			.always( function () {
				$button.prop( 'disabled', false );
			} );
	} );
}( jQuery ) );
