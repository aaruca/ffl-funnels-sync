# FFL Funnels Sync

WordPress plugin: sends WooCommerce order events to the FFL Funnels ads dashboard using a signed HTTPS webhook. Delivery is event-driven through WooCommerce hooks, with Action Scheduler used when available and one-off retries scheduled when a delivery fails.

## Versioning

The plugin header and `FFL_FS_VERSION` stay at **1.0.0** until the first production release. Internal data migrations use a separate option (`ffl_fs_schema_version`, integer revision) instead of the marketing version string.

## Requirements

- WordPress 6.2+
- WooCommerce 7.0+
- PHP 7.4+

## Security

- **Direct access:** Every PHP file under `includes/` starts with `defined('ABSPATH') || exit`. Root and `includes/` also ship an empty `index.php` so misconfigured servers do not list directory contents.
- **Settings:** Saving options requires `manage_woocommerce`. Unauthorized saves keep the previous stored values.
- **Secret at rest:** The shared secret entered in wp-admin is stored encrypted in `wp_options` (AES-256-GCM) using keys derived from WordPress `AUTH_KEY` / salts (`includes/Crypto.php`). Constants `FFL_FUNNELS_SYNC_SECRET` / `FFL_WEBHOOK_SECRET` still bypass DB entirely if you define them.
- **Outbound webhooks:** HTTPS only, TLS verification on, HMAC signature on every request. Optional host allowlist via filter `ffl_fs_allowed_webhook_hosts` (see `includes/Security.php`).
- **Logs:** When debug logging is enabled, messages are passed through `Security::redact_log_message()` (strips HTML and masks email-like strings).

## Install

1. Copy the `ffl-funnels-sync` folder into `wp-content/plugins/`.
2. Activate **FFL Funnels Sync** in wp-admin.
3. Open **WooCommerce -> FFL Funnels Sync** and enter the webhook URL and shared secret for this site.

Use **Test connection now** on that screen (after saving, if needed) to POST a signed `connection_test` event; success means HTTPS, host allowlist (if used), secret, and signature headers were accepted with HTTP 2xx. Your dashboard must treat that payload like other webhooks (verify HMAC); if it returns an error status for unknown event types, the test may fail while real order events still work.

The plugin does not ship a default endpoint or secret. Orders start syncing once the admin fills those in, or once they are defined in `wp-config.php`:

```php
define('FFL_FUNNELS_SYNC_ENDPOINT', 'https://ads-dashboard.fflfunnels.com/api/webhooks/woocommerce');
define('FFL_FUNNELS_SYNC_SECRET',   'use-a-long-random-string');
```

`FFL_WEBHOOK_URL` and `FFL_WEBHOOK_SECRET` are accepted as aliases for the two constants above. When either pair is defined, the corresponding setting is managed outside the database and the admin field becomes read-only.

## Events

The plugin sends distinct events for:

- paid orders (`order_paid`)
- completed orders (`order_completed`)
- partial refunds (`order_partially_refunded`)
- full refunds (`order_fully_refunded`)

Each event is tracked independently per order so later lifecycle events are not blocked by earlier successful deliveries.

## Security (dashboard side)

Each request includes:

- `X-FFL-Timestamp` - Unix timestamp (string).
- `X-FFL-Signature` - `sha256=` + `hash_hmac('sha256', $timestamp . '.' . $rawBody, $secret)`.

Verify using the raw request body (before JSON decode). Reject stale timestamps (for example older than 5 minutes) to limit replay attacks.

## Filters

- `ffl_fs_connection_test_payload` - adjust the JSON body for **Test connection now** (default event name `connection_test`).
- `ffl_fs_payload` - adjust the outbound array before signing. Receives the payload, `WC_Order`, event name, and refund ID.
- `ffl_fs_force_resend` - return `true` to allow sending a previously delivered event again. Receives the legacy boolean, `WC_Order`, event key, event name, and refund ID.
- `ffl_fs_allowed_webhook_hosts` - return an array of allowed hostname strings (for example `['ads-dashboard.fflfunnels.com']`) to block requests to any other host. Default `null` means no extra host restriction (HTTPS + valid URL still required).
- `ffl_fs_default_endpoint` - optionally prefill the dashboard URL for new installs. Default is empty.

## Uninstall

Deactivating unschedules pending background deliveries. Uninstalling deletes `ffl_funnels_sync_options`, `ffl_fs_schema_version`, queued dispatch jobs, and plugin-specific order meta.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
