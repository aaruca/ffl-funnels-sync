# FFL Funnels Sync 1.0.0

First public release of the WooCommerce → FFL dashboard sync plugin.

## What it does

- Sends order lifecycle events to your dashboard over **HTTPS** with **HMAC-signed** JSON payloads (`order_paid`, `order_completed`, partial/full refunds).
- Stores the shared secret **encrypted** in the database (AES-256-GCM) when configured in wp-admin, or use `wp-config.php` constants to keep URL/secret out of the DB.
- **Test connection** sends a `connection_test` event so you can verify URL, TLS, and signature without placing a real order (your API must accept that event; see [README](https://github.com/aaruca/ffl-funnels-sync#readme)).

## Install

Use the attached **`ffl-funnels-sync-1.0.0.zip`** from the [v1.0.0 release](https://github.com/aaruca/ffl-funnels-sync/releases/tag/v1.0.0) — not the generic “Source code” zip. Unzip or upload in **Plugins → Add New** so the folder is `wp-content/plugins/ffl-funnels-sync/`.

## Requirements

- WordPress 6.2+
- WooCommerce 7.0+
- PHP 7.4+

## Documentation

- [README](https://github.com/aaruca/ffl-funnels-sync#readme) — install, security, filters, `wp-config` aliases.
- [CHANGELOG](https://github.com/aaruca/ffl-funnels-sync/blob/main/CHANGELOG.md)

## Nota (ES)

Primera versión pública. Usa el **.zip** de la release (no “Source code”). *Test connection* envía `connection_test`; si el API aún no lo acepta, el test puede fallar; los eventos reales de pedido son la prueba fiable.

