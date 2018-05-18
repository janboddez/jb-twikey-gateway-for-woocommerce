<?php
/**
 * Plugin Name: Twikey Payment Gateway for WooCommerce
 * Description: Enable Twikey (to sign a recurring payment mandate) checkout for WooCommerce Subscriptions.
 * Author: Jan Boddez
 * Author URI: https://janboddez.be/
 * License: GNU General Public License v3 or later
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: jb-wc-twikey
 * Version: 0.1.2
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

if ( ! class_exists( 'JB_WC_Twikey_Payment_Gateway' ) ) :
	/**
	 * Main plugin class.
	 *
	 * @since 0.1.2
	 */
	class JB_WC_Twikey_Payment_Gateway {
		/**
		 * Register actions/hooks
		 */
		public function __construct() {
			add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );
			add_action( 'plugins_loaded', array( $this, 'init_gateway' ) );
			add_filter( 'woocommerce_payment_gateways', array( $this, 'add_gateway' ) );
			add_action( 'woocommerce_checkout_process', array( $this, 'validate_email_address' ) );
		}

		/**
		 * Logs error messages to the debug log file in the plugin folder, if WordPress
		 * debugging is enabled.
		 *
		 * @since 0.1.2
		 */
		function error_log( $message ) {
			if ( true === WP_DEBUG ) {
				error_log( $message . PHP_EOL, 3, plugin_dir_path( __FILE__ ) . 'debug.log' );
			}
		}

		/**
		 * Enables i18n of this plugin.
		 *
		 * @since 0.1.0
		 */
		function load_textdomain() {
			load_plugin_textdomain( 'jb-wc-twikey', false, basename( dirname( __FILE__ ) ) . '/languages' );
		}

		/**
		 * Loads the main payment gateway class.
		 *
		 * @since 0.1.0
		 */
		function init_gateway() {
			require_once( dirname( __FILE__ ) . '/includes/class-wc-gateway-twikey.php' );
		}

		/**
		 * Informs WooCommerce about the Twikey payment gateway.
		 *
		 * @since 0.1.0
		 * @param array $methods The list of WooCommerce payment gateways.
		 */
		function add_gateway( $methods ) {
			$methods[] = 'WC_Gateway_Twikey';
			return $methods;
		}

		/**
		 * In case of payment by Twikey mandate, disallows plus signs in
		 * customer email addresses.
		 *
		 * @since 0.1.0
		 */
		function validate_email_address() {
			if ( false !== strpos( $_POST['billing_email'], '+' ) && ( 'twikey' == $_POST['payment_method'] ) ) {
				wc_add_notice( __( 'Unfortunately, Twikey does not allow plus signs in email addresses.', 'jb-wc-twikey' ), 'error' );
			}
		}
	}
endif;

$jb_wc_twikey_payment_gateway = new JB_WC_Twikey_Payment_Gateway();
