<?php
// Thanks to ehsaan <ehsaan@riseup.net> for this library.
/**
 * Add Toman currency to Easy Digital Downloads
 *
 * @author 				Hamrahpay <info@hamrahpay.com>
 * @package 			Hamrahpay
 * @subpackage 			Toman
 */

/**
 * Add Toman currency for EDD
 *
 * @param 				array $currencies Currencies list
 * @return 				array
 */
if ( ! function_exists('irg_add_toman_currency')):
	function irg_add_toman_currency( $currencies ) {
		$currencies['IRT'] = 'تومان';
		return $currencies;
	}
endif;
add_filter( 'edd_currencies', 'irg_add_toman_currency' );

/**
 * Format decimals
 */
add_filter( 'edd_sanitize_amount_decimals', function( $decimals ) {

	$currency = function_exists('edd_get_currency') ? edd_get_currency() : '';

	global $edd_options;

	if ( $edd_options['currency'] == 'IRT' || $currency == 'IRT' || $edd_options['currency'] == 'RIAL' || $currency == 'RIAL' ) {
		return $decimals = 0;
	}

	return $decimals;
} );

add_filter( 'edd_format_amount_decimals', function( $decimals ) {

	$currency = function_exists('edd_get_currency') ? edd_get_currency() : '';

	global $edd_options;

	if ( $edd_options['currency'] == 'IRT' || $currency == 'IRT' || $edd_options['currency'] == 'RIAL' || $currency == 'RIAL' ) {
		return $decimals = 0;
	}

	return $decimals;
} );

if ( function_exists('per_number') ) {
	add_filter( 'edd_irt_currency_filter_after', 'per_number', 10, 2 );
}
if ( function_exists('toman_postfix_hp') ) {
	add_filter( 'edd_irt_currency_filter_after', 'toman_postfix_hp', 10, 2 );
	function toman_postfix_hp( $price, $did ) {
		return str_replace( 'IRT', 'تومان', $price );
	}
}

if ( function_exists('rial_postfix_hp') ) {
	add_filter( 'edd_rial_currency_filter_after', 'rial_postfix_hp', 10, 2 );
	function rial_postfix_hp( $price, $did ) {
		return str_replace( 'RIAL', 'ریال', $price );
	}
}
