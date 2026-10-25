# Lighter Woo Subscriptions

A lightweight subscriptions plugin for WooCommerce. It is modelled on the official WooCommerce Subscriptions plugin but is about 4,500 lines (comments included) instead of ~150,000. It handles the core job: selling products on a recurring schedule and charging saved cards automatically through **Stripe** and **Square**.

## Features

- **Any simple or variable product can be a subscription.** Tick **Subscription** next to *Virtual* / *Downloadable* on the product, then set *Bill every N day/week/month/year* and an optional number of payments. Each variation can override the billing schedule (e.g. monthly vs. every 2 weeks).
- **Classic and block checkout.** Prices show the schedule (`$10.00 / month`). Cart and checkout show a **Recurring total** and the first renewal date.
- **Automatic renewals** run through WooCommerce's Action Scheduler and create a normal WooCommerce renewal order each period:
  - **Stripe** (official *WooCommerce Stripe Payment Gateway*): charges the saved payment method off-session.
  - **Square** (official *WooCommerce Square*): charges the saved card on file.
  - **Any other gateway** (bank transfer, cash on delivery, …), or a subscription without a saved card: the renewal order is emailed to the customer as an invoice with a **Pay now** link.
- **Failed payment retries.** After a decline the subscription goes on hold and the charge is retried after 1, 3 and 5 days. The customer is emailed a pay link after the first failure and again when retries run out. Paying (by retry, by the link, or by switching cards) reactivates the subscription.
- **Customer self-service** in *My Account → Subscriptions*:
  - pause and resume
  - cancel; the subscription runs to the end of the paid period, and the customer can undo the cancellation before then
  - switch to another saved card
  - see related orders
- **Admin.** *WooCommerce → Subscriptions* uses WooCommerce's own order screens, so items, addresses, notes and status all work as usual. The plugin adds:
  - a schedule box to edit the interval, period, length and next payment/end dates
  - a manual-renewal switch
  - a **Process renewal now** action
- **Emails** (WooCommerce → Settings → Emails): *Subscription renewal invoice*, *Subscription payment failed*, *Subscription cancelled* (to the store). Renewal orders also get WooCommerce's normal receipts and admin emails.
- Works with **HPOS** (High-Performance Order Storage) and with classic post storage.

## Requirements

- WordPress 6.4+, PHP 7.4+, WooCommerce 8.5+
- For automatic card renewals, one of:
  - **WooCommerce Stripe Payment Gateway**, with **Saved payment methods** enabled in its settings
  - **WooCommerce Square**, connected to your Square account
- The official WooCommerce Subscriptions plugin must **not** be active (the plugin refuses to run alongside it).

## Install

1. Copy this folder to `wp-content/plugins/lighter-woo-subscriptions` (or upload a zip of it under *Plugins → Add New → Upload*).
2. Activate **Lighter Woo Subscriptions**.
3. Edit a product, tick **Subscription**, set the price and billing schedule, and save.
4. Make sure WP-Cron (or a real cron calling `wp-cron.php`) runs, because renewals are processed by Action Scheduler. You can watch them under *WooCommerce → Status → Scheduled Actions* (group `lighter-woo-subscriptions`).

## How it works

| Piece | Where |
| --- | --- |
| Subscription record: a custom WooCommerce order type `lws_subscription` with statuses *pending, active, on-hold, pending-cancel, cancelled, expired* | `includes/class-lws-order-type.php`, `includes/class-lws-subscription.php` |
| Product settings and price strings | `includes/class-lws-product.php` |
| Cart rules, recurring totals, required account | `includes/class-lws-cart.php` |
| Creating subscriptions from the checkout order and activating them when it is paid | `includes/class-lws-checkout.php` |
| Scheduling (Action Scheduler hooks `lws_process_renewal`, `lws_retry_renewal`, `lws_end_subscription`) | `includes/class-lws-scheduler.php` |
| Renewal orders, charging, retries, double-charge lock | `includes/class-lws-renewal.php` |
| Gateway adapters | `includes/gateways/` |
| My Account | `includes/class-lws-my-account.php`, `templates/myaccount/` |
| Admin | `includes/class-lws-admin.php` |
| Emails | `includes/class-lws-emails.php`, `includes/emails/`, `templates/emails/` |
| Block cart/checkout | `includes/class-lws-blocks.php`, `assets/js/blocks.js` |

**Why gateway adapters?** Stripe and Square only charge saved cards automatically when they detect the official WooCommerce Subscriptions plugin. This plugin therefore:

- tells them to save the card whenever a subscription is bought (`wc_stripe_force_save_payment_method`, `wc_payment_gateway_square_credit_card_tokenization_forced`)
- copies the saved-card reference onto the subscription:
  - Stripe: `_stripe_customer_id` / `_stripe_source_id`
  - Square: `_wc_square_credit_card_payment_token` / `_wc_square_credit_card_customer_id`
- charges that card through each plugin's own API client:
  - Stripe: an off-session PaymentIntent
  - Square: the plugin's `process_payment()` with the card injected, which is how Square's built-in subscription integration does it

Other gateways can be added with the `lws_gateway_adapters` filter by implementing `LWS_Gateway_Adapter`.

## Deliberate simplifications (compared with WooCommerce Subscriptions)

- **One billing schedule per checkout, with no non-subscription items in the same cart.** Every renewal is then an exact copy of the first order, including shipping and tax. Customers who try to mix are told to check out separately.
- **Coupons apply to the first payment only.** Renewals bill the regular (pre-discount) line prices.
- **Prices are locked at sign-up.** Changing a product's price later doesn't change existing subscriptions. Edit the subscription's items in the admin to change one.
- **Taxes are copied from the original order**, not recalculated at each renewal.
- **Not included:** free trials, sign-up fees, plan switching/upgrades, gifting, early renewal, renewal synchronisation, the PayPal integration, REST API endpoints and reports. The data model leaves room to add trials and sign-up fees later.
- **Uninstalling** removes the plugin's options and scheduled actions but **keeps** subscription records, which are orders with payment history.

## Hooks

| Hook | Type | Purpose |
| --- | --- | --- |
| `lws_retry_intervals` | filter | Retry delays in days (default `[1, 3, 5]`) |
| `lws_gateway_adapters` | filter | Register gateway adapters |
| `lws_customer_can_pause` / `lws_customer_can_cancel` / `lws_customer_actions` | filter | Control My Account actions |
| `lws_cart_recurring_total`, `lws_schedule_string`, `lws_mixed_cart_message` | filter | Display tweaks |
| `lws_subscription_include_shipping` | filter | Whether renewals repeat the initial shipping lines |
| `lws_stripe_renewal_request` | filter | Modify the Stripe PaymentIntent request |
| `lws_renewal_order_before_save` | filter | Modify a renewal order before it is saved |
| `lws_subscription_created`, `lws_renewal_order_created`, `lws_renewal_paid`, `lws_renewal_payment_failed` | action | Lifecycle events |
| `lws_subscription_status_{status}`, `lws_subscription_status_{from}_to_{to}`, `lws_subscription_status_changed` | action | Status changes |

Templates in `templates/` can be overridden from your theme under `yourtheme/woocommerce/` (e.g. `woocommerce/myaccount/lws-view-subscription.php`).

## Tests

These scripts run against a **disposable** WordPress site (they create products, customers and orders). Run them with WP-CLI from the WordPress root:

```sh
wp eval-file wp-content/plugins/lighter-woo-subscriptions/tests/smoke-test.php          # core lifecycle, 64 checks
wp eval-file wp-content/plugins/lighter-woo-subscriptions/tests/gateway-stripe-test.php # needs the Stripe plugin; Stripe API mocked
wp eval-file wp-content/plugins/lighter-woo-subscriptions/tests/gateway-square-test.php # needs the Square plugin; Square API mocked
```

Coding standards: `phpcs` with WPCS 3 (`phpcs.xml.dist`).

### Before going live

The automated tests mock the Stripe and Square APIs. Before taking real payments, run one purchase and one renewal in each gateway's **test/sandbox** mode:

1. Buy a subscription with Stripe test card `4242 4242 4242 4242`.
2. Under *WooCommerce → Subscriptions*, open it and run **Process renewal now**. The renewal order should be paid in Stripe.
3. Use card `4000 0000 0000 0341` (it saves fine, but declines on later charges) to see the failure → on-hold → retry → pay-link flow.
4. Repeat steps 1–3 in Square's sandbox.
