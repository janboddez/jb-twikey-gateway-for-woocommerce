<?php
/**
 * Plugin Name: Twikey Gateway for WooCommerce
 * Plugin URI: https://github.com/janboddez/jb-twikey-gateway-for-woocommerce
 * GitHub Plugin URI: https://github.com/janboddez/jb-twikey-gateway-for-woocommerce
 * Description: Enable Twikey checkout for WooCommerce and allow customers to easily sign a recurring payment mandate. Supports (but does not require) WooCommerce Subscriptions and automatic subscription renewal payments.
 * Author: Jan Boddez
 * Author URI: https://janboddez.be/
 * License: GNU General Public License v2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: jb-wc-twikey
 * Version: 0.2.2
 * GitHub Plugin URI: https://github.com/janboddez/jb-twikey-gateway-for-woocommerce/
 *
 * @author Jan Boddez [jan@janboddez.be]
 * @license http://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0
 * @package JB_Twikey_Gateway_WooCommerce
 */

/* Prevents this script from being loaded directly. */
defined( 'ABSPATH' ) or exit;

if ( ! class_exists( 'JB_Twikey_Gateway_WooCommerce' ) ) :
/**
 * Main plugin class.
 *
 * @since 0.1.0
 */
class JB_Twikey_Gateway_WooCommerce {
	/**
	 * Register hooks/actions.
	 */
	public function __construct() {
		register_activation_hook( __FILE__, array( $this, 'activate' ) );
		register_deactivation_hook( __FILE__, array( $this, 'deactivate' ) );
		add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );
		add_action( 'plugins_loaded', array( $this, 'init_gateway' ) );
		add_filter( 'woocommerce_payment_gateways', array( $this, 'add_gateway' ) );
		add_action( 'daily_check_transactions', array( $this, 'check_transactions' ) );
	}

	/**
	 * Enables i18n of this plugin.
	 *
	 * @since 0.1.0
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'jb-wc-twikey', false, basename( dirname( __FILE__ ) ) . '/languages' );
	}

	/**
	 * Loads the main payment gateway class.
	 *
	 * @since 0.1.0
	 */
	public function init_gateway() {
		if ( $this->woocommerce_is_active() ) {
			require_once dirname( __FILE__ ) . '/includes/class-wc-gateway-jb-twikey.php';
		}
	}

	/**
	 * On plugin activation, sets up the WP cron job for checking transaction
	 * changes and updating order statuses.
	 *
	 * @since 0.2.0
	 */
	public function activate() {
		if ( false === wp_next_scheduled( 'daily_check_transactions' ) ) {
			wp_schedule_event( strtotime( 'tomorrow' ), 'daily', 'daily_check_transactions' );
		}
	}

	/**
	 * On plugin deactivation, clears all WP cron jobs.
	 *
	 * @since 0.2.0
	 */
	public function deactivate() {
		wp_clear_scheduled_hook( 'daily_check_transactions' );
	}

	/**
	 * Informs WooCommerce about our Twikey payment gateway.
	 *
	 * @since 0.1.0
	 *
	 * @param array $methods The list of WooCommerce payment gateways.
	 * @return array The list of WooCommerce payment gateways.
	 */
	public function add_gateway( $methods ) {
		// Will only ever get called if WooCommerce is active.
		$methods[] = 'WC_Gateway_JB_Twikey'; // Important: the _gateway_ class name!
		return $methods;
	}

	/**
	 * Checks if submitted transactions have been updated.
	 *
	 * @since 0.2.0
	 */
	public function check_transactions() {
		if ( $this->woocommerce_is_active() ) {
			$available_gateways = WC()->payment_gateways->payment_gateways();

			if ( isset( $available_gateways['jb_twikey'] ) && $available_gateways['jb_twikey'] instanceof WC_Gateway_JB_Twikey ) {
				$available_gateways['jb_twikey']->check_transactions();
			}
		}
	}

	/**
	 * Verifies WooCommerce is active.
	 *
	 * @since 0.2.2
	 */
	private function woocommerce_is_active() {
		if ( in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) {
			return true;
		}

		return false;
	}
}
endif;

new JB_Twikey_Gateway_WooCommerce();
