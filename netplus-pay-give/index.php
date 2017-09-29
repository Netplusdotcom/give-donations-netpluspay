<?php
ob_start();
/**
 * @package Netpluspay_Give
 * @version 1.6
 */
/*
Plugin Name: Netpluspay Give
Plugin URI: https://www.netpluspay.com
Description: Netplus Give plugin to allow donation using netplus pay.
Author: Tunde Ajibawo
Version: 0.1
Author URI: tundeajibawo.com
*/



function give_netplus_register_gateway( $gateways ) {
	// Format: ID => Name
	$gateways['netplus'] = array(
		'admin_label'    => esc_attr__( 'Netpluspay', 'netplus' ),
		'checkout_label' => esc_attr__( 'Netpluspay', 'netplus' )
	);

	return $gateways;
}


function give_process_netplus_payment( $purchase_data ) {
	
  	$payment_data = array(
		'price'           => $purchase_data['price'],
		'give_form_title' => $purchase_data['post_data']['give-form-title'],
		'give_form_id'    => intval( $purchase_data['post_data']['give-form-id'] ),
		'give_price_id'   => isset($purchase_data['post_data']['give-price-id']) ? $purchase_data['post_data']['give-price-id'] : '',
		'date'            => $purchase_data['date'],
		'user_email'      => $purchase_data['user_email'],
		'purchase_key'    => $purchase_data['purchase_key'],
		'currency'        => give_get_currency(),
		'user_info'       => $purchase_data['user_info'],
		'status'          => 'pending'
	);
	$payment_id = give_insert_payment( $payment_data );

	// Check payment.
	if ( empty( $payment_id ) ) {
		// Record the error.
		give_record_gateway_error(
			esc_html__( 'Payment Error', 'netplus' ),
			sprintf(
			/* translators: %s: payment data */
				esc_html__( 'Payment creation failed before sending donor to Netplus. Payment data: %s', 'netplus' ),
				json_encode( $purchase_data )
			),
			$payment_id
		);
		// Problems? Send back.
		give_send_back_to_checkout( '?payment-mode=' . $purchase_data['post_data']['give-gateway'] );
	}

	// Redirect to netplu.
	wp_redirect( give_build_netplus_url( $payment_id, $purchase_data ) );
	exit;
	
}



function give_get_netplus_redirect( $ssl_check = false ) {

	if ( is_ssl() || ! $ssl_check ) {
		$protocol = 'https://';
	} else {
		$protocol = 'http://';
	}

	$liveurl = 'netpluspay.com/payment/paysrc/';
	$testurl ='netpluspay.com/testpayment/paysrc/';

	// Check the current payment mode
	if ( give_is_test_mode() ) {
		// Test mode
		$netplus_uri = $protocol . $testurl;
	} else {
		// Live mode
		$netplus_uri = $protocol . $liveurl;
	}

	return apply_filters( 'give_netplus_uri', $netplus_uri );
}

function give_build_netplus_url( $payment_id, $payment_data ) {
	// Only send to netplu if the pending payment is created successfully.

	// Get the success url.
	$return_url = add_query_arg( array(
		'payment-confirmation' => 'netplus',
		'payment-id'           => $payment_id,

	), get_permalink( give_get_option( 'success_page' ) ) );

	// Get the netplus redirect uri.
	$netplus_redirect = trailingslashit( give_get_netplus_redirect() ) . '?';

	$settings = get_option( 'woocommerce_netpluspay_settings' );
	if ( give_is_test_mode() ) {
		// Test mode
		$merchant_id = $settings['test_merchant_id'];
	} else {
		// Live mode
		$merchant_id = $settings['merchant_id'];
	}


	$netplus_args = array(
		'full_name'    => $payment_data['user_info']['first_name']. " " .$payment_data['user_info']['last_name'],
		'email'         => $payment_data['user_email'],
		'narration'       => $payment_data['purchase_key'],
		'total_amount'        => $payment_data['price'],
		'currency_code' => give_get_currency(),
		'order_id'        => $payment_id,
		'return_url'        => $return_url,
		'merchant_id' => $merchant_id,
		'recurring'            => 'no',
	);


	// Build query.
	$netplus_redirect .= http_build_query( $netplus_args );

	// Fix for some sites that encode the entities.
	$netplus_redirect = str_replace( '&amp;', '&', $netplus_redirect );

	return $netplus_redirect;
}



function give_process_netplus_web_accept_and_cart( $data, $payment_id ) {


}

function give_netplus_success_page_content( $content ) {
	
	if ( ! isset( $_GET['payment-id'] ) && ! give_get_purchase_session() ) {
		return $content;
	}

	$payment_id = isset( $_GET['payment-id'] ) ? absint( $_GET['payment-id'] ) : false;

	if ( ! $payment_id ) {
		$session    = give_get_purchase_session();
		$payment_id = give_get_purchase_id_by_key( $session['purchase_key'] );
	}
	
	$payment = get_post( $payment_id );
	if ( $payment && 'pending' == $payment->post_status ) {
		$netplus_trans_id = $_POST['transaction_id'];
		$description = $_POST['description'];
		$code = $_POST['code'];

		if($code === '00'){
			give_insert_payment_note( $payment_id, sprintf( 
				__( 'Netplus Transaction ID: %s', 'give' ), $netplus_trans_id ) );
			give_set_payment_transaction_id( $payment_id, $netplus_trans_id);
			give_update_payment_status( $payment_id, 'processing' );			
		}else{
			give_record_gateway_error( __( 'Payment Error', 'give' ), sprintf(
				__( $description, 'give')), $payment_id );
			give_update_payment_status($payment_id, 'failed' );
			give_insert_payment_note( $payment_id, __( $description, 'give' ) );
			give_send_back_to_checkout( '?payment-mode=netplus');
			give_get_template_part( 'payment', 'failed' );
			$content = ob_get_clean();
		}

	}
	
	return $content;

}

add_filter( 'give_payment_confirm_netplus', 'give_netplus_success_page_content' );
add_action( 'give_netplus_cc_form', '__return_false' );
add_filter( 'give_payment_gateways', 'give_netplus_register_gateway');
add_action( 'give_gateway_netplus', 'give_process_netplus_payment' );

//NGN Currency

function add_nigerian_currency( $currencies ) {
	$currencies['NGN'] = __( 'Nigerian Naira (&#x20A6;)', 'give' );
	return $currencies;
}
add_filter( 'give_currencies', 'add_nigerian_currency' );
function add_nigerian_symbol( $symbol, $currency ) {
	switch ( $currency ) :
		case "NGN" :
			$symbol = '&#x20A6;';
			break;
	endswitch;
	return $symbol;
}
add_filter( 'give_currency_symbol', 'add_nigerian_symbol', 10, 2 );
?>