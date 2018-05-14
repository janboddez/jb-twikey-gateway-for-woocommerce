<?php
/**
 * Plugin Name: WooCommerce Twikey Payment Gateway
 * Description: Enable Twikey (to sign a recurring payment mandate) checkout for WooCommerce Subscriptions.
 * Author: Jan Boddez
 * Author URI: https://janboddez.be/
 * License: GNU General Public License v3 or later
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: jb-wc-twikey
 * Version: 0.1.1
 *
 * @package WooCommerce_Gateway_Twikey
 * @author Jan Boddez [jan@janboddez.be]
 * @license http://www.gnu.org/licenses/gpl-3.0.html GNU General Public License v3.0
 */

// Prevents this script from being loaded directly.
defined( 'ABSPATH' ) or exit;

// Ensures WooCommerce is active.
if ( ! is_woocommerce_active() ) {
	return;
}

/**
 * Enables i18n of this plugin.
 *
 * @since 0.1.0
 */
function jb_wc_twikey_load_plugin_textdomain() {
	$result = load_plugin_textdomain( 'jb-wc-twikey', false, basename( dirname( __FILE__ ) ) . '/languages' );
}
add_action( 'plugins_loaded', 'jb_wc_twikey_load_plugin_textdomain' );

/**
 * Loads the main payment gateway class.
 *
 * @since 0.1.0
 */
function jb_wc_twikey_init_WC_Gateway_Twikey() {
	require_once( dirname( __FILE__ ) . '/includes/class-wc-gateway-twikey.php' );
}
add_action( 'plugins_loaded', 'jb_wc_twikey_init_WC_Gateway_Twikey' );

/**
 * Informs WooCommerce about the Twikey payment gateway.
 *
 * @since 0.1.0
 *
 * @param array $methods The list of WooCommerce payment gateways.
 */
function jb_wc_twikey_add_WC_Gateway_Twikey( $methods ) {
	$methods[] = 'WC_Gateway_Twikey';
	return $methods;
}
add_filter( 'woocommerce_payment_gateways', 'jb_wc_twikey_add_WC_Gateway_Twikey' );

/**
 * In case of payment by Twikey mandate, disallows plus signs in customer email
 * addresses.
 *
 * @since 0.1.0
 */
function jb_wc_twikey_validate_email_address() {
	if ( false !== strpos( $_POST['billing_email'], '+' ) && ( 'twikey' == $_POST['payment_method'] ) ) {
		wc_add_notice( __( 'Unfortunately, Twikey does not allow plus signs in email addresses.', 'jb-wc-twikey' ), 'error' );
	}
}
add_action( 'woocommerce_checkout_process', 'jb_wc_twikey_validate_email_address' );
