( function ( $ ) {
	'use strict';

	var debounceTimer;

	function togglePointsInput() {
		var $checkbox = $( '#ms_bonus_apply' );
		var $input = $( '#ms_bonus_points' );

		if ( ! $checkbox.length || ! $input.length ) {
			return;
		}

		var enabled = $checkbox.is( ':checked' );
		$input.prop( 'disabled', ! enabled );

		if ( ! enabled ) {
			$input.val( 0 );
		}
	}

	function clearBonusFields() {
		var $checkbox = $( '#ms_bonus_apply' );
		var $input = $( '#ms_bonus_points' );

		if ( $checkbox.length ) {
			$checkbox.prop( 'checked', false );
		}

		if ( $input.length ) {
			$input.val( 0 ).prop( 'disabled', true );
		}
	}

	function triggerCheckoutUpdate() {
		clearTimeout( debounceTimer );
		debounceTimer = setTimeout( function () {
			$( document.body ).trigger( 'update_checkout' );
		}, 400 );
	}

	$( document ).on( 'change', '#ms_bonus_apply', function () {
		togglePointsInput();
		triggerCheckoutUpdate();
	} );

	$( document ).on( 'input change', '#ms_bonus_points', function () {
		var $input = $( this );
		var max = parseInt( $input.attr( 'max' ), 10 );
		var value = parseInt( $input.val(), 10 );

		if ( ! isNaN( max ) && ! isNaN( value ) && value > max ) {
			$input.val( max );
		}

		if ( $( '#ms_bonus_apply' ).is( ':checked' ) ) {
			triggerCheckoutUpdate();
		}
	} );


	$( document.body ).on( 'applied_coupon_in_checkout removed_coupon_in_checkout', function () {
		clearBonusFields();
		triggerCheckoutUpdate();
	} );

	$( document.body ).on( 'updated_checkout', function () {

		if ( ! $( '#ms-bonus-checkout' ).length ) {
			clearBonusFields();
			return;
		}

		togglePointsInput();
	} );

	$( function () {
		togglePointsInput();
	} );
}( jQuery ) );
