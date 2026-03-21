=== Fliinow - Financing for WooCommerce ===
Contributors: fliinow
Tags: financing, payment, installments, travel, bnpl
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Offer installment financing at your WooCommerce checkout with Fliinow.

== Description ==

Fliinow lets your customers finance their purchases in installments directly
from the WooCommerce checkout. Compatible with both the classic checkout and
the block-based checkout (WooCommerce Blocks).

**Features:**

* Payment method integrated into the WooCommerce checkout
* Compatible with WooCommerce Blocks (block-based checkout)
* Sandbox environment for testing
* Configurable minimum and maximum amounts
* Built-in debug logging
* Compatible with HPOS (High-Performance Order Storage)
* Extensible via WordPress filters

**Customer flow:**

1. Customer adds products to the cart
2. At checkout, selects "Finance with Fliinow"
3. Places the order and is redirected to Fliinow
4. Chooses a financing plan and completes the application
5. If approved, the order is automatically confirmed
6. If rejected/cancelled, the order is marked as failed

== Installation ==

1. Upload the `fliinow-woocommerce` folder to `/wp-content/plugins/`
2. Activate the plugin from the 'Plugins' menu in WordPress
3. Go to WooCommerce → Settings → Payments → Fliinow
4. Enter your API Key provided by Fliinow
5. Enable sandbox mode to test before going to production

== Frequently Asked Questions ==

= Do I need a Fliinow account? =

Yes. Contact partners@fliinow.com to get your API credentials.

= Does it work with the block-based checkout? =

Yes. The plugin supports both the classic and block-based checkout.

= Can I customize the data sent to Fliinow? =

Yes. Use the `fliinow_wc_operation_data` filter to modify the payload:

    add_filter( 'fliinow_wc_operation_data', function( $data, $order ) {
        $data['travelersNumber'] = 2;
        $data['packageName'] = 'Custom trip';
        return $data;
    }, 10, 2 );

== Changelog ==

= 1.0.0 =
* Initial release
* Payment gateway with classic and block-based checkout support
* Sandbox/production configuration
* Success/error callbacks
* HPOS support

== Screenshots ==

1. Payment method settings in WooCommerce → Settings → Payments
2. Payment method visible at customer checkout

== Upgrade Notice ==

= 1.0.0 =
Initial release.
