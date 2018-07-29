# Twikey Payment Gateway for WooCommerce
Contributors: janboddez  
Tags: woocommerce, payment gateways, twikey, recurring payments, woocommerce subscriptions, automatic renewal payments  
Requires at least: 4.7  
Tested up to: 4.9  
Requires PHP: 5.2.4  
Stable tag: 1.0  
License: GPLv2 or later  
License URI: http://www.gnu.org/licenses/gpl-2.0.html  
WC requires at least: 3.0  
WC tested up to: 3.4  

Enable Twikey checkout for WooCommerce (and WooCommerce Subscriptions) and allow
customers to easily sign a recurring SEPA (Single Euro Payments Area) mandate
using their debit card or eID, or by SMS.

## Installation
Install and activate the plugin. Head over to WooCommerce > Settings and then
the Payments tab. Choose Twikey and make sure it's enabled.

Fill out your Twikey API token, private key, and the Twikey contract template to
be used. If applicable, customize any of the other (title and message) fields.

In Twikey's own admin environment, make sure the value(s) for all exit URLs are
set to
`http(s)://mysite.com/wc-api/wc_gateway_jb_twikey/?mandateNumber={0}&state={1}&sig={3}`,
where (only) `http(s)://mysite.com` should obviously be adapted to your
situation. **This step is absolutely necessary for payments to function
correctly.**

## Notes
* Supports WooCommerce Subscriptions and automatic subscription renewal
payments, but works just as well without it.
* Even after the Twikey API tells WooCommerce a mandate is signed OK, payments
are marked _'on hold'_ until the _actual payment_ is received. Payment
processing generally does not take longer than a few days.
* Supports only one Twikey contract template per WooCommerce installation. That
should (almost) never be a problem, though.
* ~~To do: replace cURL functions with `wp_remote_get()` or `wp_remote_post()`
calls.~~
