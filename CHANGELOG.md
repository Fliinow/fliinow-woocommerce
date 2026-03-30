# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [1.3.2] - 2026-03-30

### Fixed
- Text domain changed to match WP.org assigned slug (`fliinow-checkout-financing`)
- "Tested up to" updated to WordPress 6.9
- Excluded `.phpcs.xml.dist` from submission ZIP (hidden files not permitted)
- Main file renamed to `fliinow-checkout-financing.php`

## [1.3.1] - 2026-03-30

### Fixed
- WordPress Plugin Check (WPCS) compliance — fixed all coding standards issues
- Added `.phpcs.xml.dist` for reproducible linting
- Renamed `class-fliinow-blocks.php` to `class-fliinow-blocks-payment-method.php` (WPCS filename convention)

## [1.3.0] - 2025-07-24

### Changed
- Renamed plugin from "Fliinow - Financiación para WooCommerce" to "Fliinow – Checkout Financing" (WP.org compliance)
- New slug/text domain: `fliinow-checkout-financing` (was `fliinow-woocommerce`)
- Main file renamed to `fliinow-checkout-financing.php`
- Plugin description now in English
- Removed empty Screenshots section from readme.txt

## [1.2.1] - 2026-03-22

### Fixed
- Malformed lines in cron initialization closure (collapsed `if` blocks)
- `.pot` translation file regenerated with all 51 strings and correct version
- `package.json` version aligned to release

### Added
- `Requires Plugins: woocommerce` header (WP 6.5+ standard) in plugin file and readme.txt

## [1.2.0] - 2026-03-22

### Added
- LICENSE file (GPL-2.0-or-later) — required for WP.org submission
- Cron observability: summary log after each polling run (checked/completed/cancelled/errors/unchanged)
- Proactive health monitoring: twice-daily cron checks API availability, admin notice on failure
- `.editorconfig` for contributor consistency
- This CHANGELOG.md

### Changed
- `process_payment()` now retries once on transient failure (16s worst case vs lost sale)
- Phone prefix and nationality simplified to Spanish-only (no over-engineering)

### Fixed
- `uninstall.php` now cleans order meta (`_fliinow_operation_id`, `_fliinow_status`, `_fliinow_financing_url`) from both `wp_postmeta` and HPOS tables
- Deactivation hook now also clears `fliinow_health_monitor` cron

## [1.1.0] - 2025-12-15

### Added
- Callback handler verifies real operation status via Fliinow API before acting
- Orders go to on-hold (not cancelled) when status cannot be verified — cron picks them up
- Background cron uses dedicated profile with 30s timeout / 2 retries
- Retry-After header capped to 5s to prevent worker blocking
- Partial refunds rejected — Fliinow only supports full cancellation
- 126 PHP unit + 17 JS = 143 total tests

### Changed
- API client defaults to 8s timeout / 0 retries for user-facing paths
- Nonce removed from callback URLs — `order_key` is the sole auth factor

## [1.0.0] - 2025-11-01

### Added
- Payment gateway with classic and block-based checkout support
- Sandbox/production configuration
- Success/error callbacks with redirect handling
- HPOS (High-Performance Order Storage) compatibility
- WooCommerce Blocks checkout support
- Configurable min/max amount thresholds
- Debug logging via WooCommerce logger
- Extensible via `fliinow_wc_operation_data` filter
