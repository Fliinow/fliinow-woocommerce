=== Fliinow – Checkout Financing ===
Contributors: fliinow
Tags: financing, payment, installments, travel, bnpl
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 7.4
Requires Plugins: woocommerce
Stable tag: 1.3.2
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
5. Plugin verifies the real status via the Fliinow API:
   - Approved → order automatically confirmed
   - Pending or unverifiable → order on-hold (background cron retries)
   - Rejected/cancelled → order cancelled, cart restored

== Installation ==

1. Upload the `fliinow-checkout-financing` folder to `/wp-content/plugins/`
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

= 1.3.2 =
* Fixed: Text domain changed to match WP.org assigned slug (fliinow-checkout-financing)
* Fixed: "Tested up to" updated to WordPress 6.9
* Fixed: Excluded .phpcs.xml.dist from submission ZIP (hidden files not permitted)
* Changed: Main file renamed to fliinow-checkout-financing.php

= 1.3.1 =
* Fixed: WordPress Plugin Check (WPCS) compliance — all coding standards issues resolved
* Added: .phpcs.xml.dist for reproducible linting
* Changed: Blocks class filename convention (WPCS)

= 1.3.0 =
* Changed: Renamed plugin to "Fliinow – Checkout Financing" (WP.org naming compliance)
* Changed: New slug and text domain: fliinow-checkout-financing
* Changed: Plugin description in English

= 1.2.1 =
* Fixed: Malformed lines in cron initialization closure
* Fixed: .pot translation file regenerated with all strings and correct version
* Fixed: package.json version aligned
* Added: Requires Plugins: woocommerce header (WP 6.5+ standard)

= 1.2.0 =
* Added: LICENSE file (GPL-2.0-or-later)
* Added: Cron observability — summary log after each polling run
* Added: Proactive health monitoring — twice-daily API checks with admin notice on failure
* Added: .editorconfig for contributor consistency
* Changed: process_payment() now retries once on transient failure (avoids lost sales)
* Changed: Phone prefix and nationality simplified to Spanish-only
* Fixed: uninstall.php now cleans order meta from wp_postmeta and HPOS tables
* Fixed: Deactivation hook clears health monitor cron
* Tests: 128 PHP unit + 17 JS = 145 total

= 1.1.0 =
* Security: callback handler now verifies real operation status via Fliinow API before acting
* Security: orders go to on-hold (not cancelled) when status cannot be verified — cron picks them up
* Security: API client defaults to 8s timeout / 0 retries for user-facing paths (checkout, callback)
* Security: background cron uses dedicated profile with 30s timeout / 2 retries
* Security: Retry-After header capped to 5s to prevent worker blocking
* Security: partial refunds rejected — Fliinow only supports full cancellation
* Security: nonce removed from callback URLs — order_key is the sole auth factor
* Tests: 126 PHP unit + 17 JS = 143 total

= 1.0.0 =
* Initial release
* Payment gateway with classic and block-based checkout support
* Sandbox/production configuration
* Success/error callbacks
* HPOS support

== Upgrade Notice ==

= 1.2.0 =
Observability, retry resilience, proactive health monitoring. Recommended update.

= 1.1.0 =
Security hardening: callback verification, retry policy, partial refund protection. Recommended update.

= 1.0.0 =
Initial release.
