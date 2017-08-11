<?php
/*
Plugin Name: TossPay Payments for WooCommerce
Plugin URI: https://toss.im/tosspay
Description: 토스페이 - TossPay Payments for WooCommerce
Version: 0.1
Author: Viva Republica - TossPay
Author URI: https://toss.im/tosspay
*/

add_action( 'plugins_loaded', 'init_tossPay_gateway_class' );
function init_tossPay_gateway_class()
{
	include_once('WC_TossPay.php');
}

add_filter( 'woocommerce_payment_gateways', 'add_tossPay_gateway_class' );
function add_tossPay_gateway_class( $methods )
{
	$methods[] = 'WC_TossPay'; 
	return $methods;
}