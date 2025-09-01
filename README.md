# Woo Escrow On-Chain

This project links a WooCommerce plugin with a Python FastAPI service to handle Bitcoin multisig escrow.

## Vendor setup

Dokan sellers can create a page (e.g., `/escrow-settings/`) containing the `[weo_treuhand]` shortcode. The Dokan dashboard adds a **Treuhand Service** menu item linking to that page for vendors to manage their escrow settings and orders.

## API key management

The API reads a comma-separated list of valid keys from the `API_KEYS` environment variable. You can revoke individual keys by listing them in `API_KEY_REVOKED`.

The WooCommerce plugin holds a single active key in its settings and sends it with every request using the `x-api-key` header.

### Key rotation
1. Deploy a new key and add it to `API_KEYS` on the API server.
2. Update the plugin setting with the new key.
3. Once clients have switched, remove the old key from `API_KEYS` or move it to `API_KEY_REVOKED`.

## Webhook security

When the API calls back into WooCommerce it signs the JSON body together with a Unix timestamp. The plugin stores a shared HMAC secret and verifies each request:

- `x-weo-ts` contains the timestamp (±5 minutes allowed)
- `x-weo-sign` is `HMAC_SHA256(secret, ts + body)`
- Requests with missing, stale, or mismatched signatures are rejected with `401`

Callbacks are delivered asynchronously with retry and exponential backoff. Configure `WEBHOOK_RETRIES` for the number of attempts
and `WEBHOOK_BACKOFF` as the multiplier between retries (defaults: 3 retries, backoff 2). Successful final notifications record a
timestamp in the `last_webhook_ts` column to prevent duplicate sends.

## CORS configuration

The API enforces a strict origin whitelist. Set the `ALLOW_ORIGINS` environment
variable to a comma-separated list of permitted shop domains.

Example:

```bash
# production
ALLOW_ORIGINS=https://shop.example.com

# staging
ALLOW_ORIGINS=https://shop-staging.example.com
```

After updating the value, redeploy the API so the new origins take effect.

## Prometheus metrics

The API exposes metrics under `/metrics` in the Prometheus exposition format. The following metrics are available:

- `rpc_duration_seconds` – histogram of Bitcoin Core RPC latency, labelled by `method`
- `webhook_total` – counter for webhook deliveries with label `status` (`success` or `error`)
- `pending_signatures` – gauge for the number of missing PSBT signatures across orders
- `broadcast_fail_total` – counter for failed transaction broadcasts
- `stuck_orders_total` – counter labelled by `state` for orders that exceed `STUCK_ORDER_HOURS`

Example scrape configuration:

```yaml
scrape_configs:
  - job_name: escrow_api
    static_configs:
      - targets: ['api.example.com:8080']
```

### Alerting

The API periodically checks orders in `awaiting_deposit` and `signing`. When an order remains
in one of these states longer than `STUCK_ORDER_HOURS` (default 24 h), the `stuck_orders_total{state}`
counter is incremented and a warning is logged. Configure `STUCK_CHECK_INTERVAL` (seconds) to tune the
scan frequency. Optionally set `SENTRY_DSN` to forward warnings to Sentry.

Alertmanager should fire when either `broadcast_fail_total` or `stuck_orders_total` increases. A simple rule:

```yaml
alerts:
  - alert: EscrowBroadcastFailures
    expr: increase(broadcast_fail_total[5m]) > 0
  - alert: EscrowStuckOrders
    expr: increase(stuck_orders_total[1h]) > 0
``` 

These alerts signal that transactions failed to broadcast or orders have stalled and require manual intervention.

## Signing deadlines

Orders that reach `escrow_funded` or `signing` receive a signing deadline `SIGNING_DEADLINE_DAYS` (default 7 days).
The Python API stores this as `deadline_ts` in the database and the WooCommerce plugin displays the remaining
time in the order panel. When the deadline expires and only one party has signed, the API will automatically
co‑sign with the escrow key and broadcast the payout or refund, depending on the stored destination.

Configure the timeout period in two places:

- Set the `SIGNING_DEADLINE_DAYS` environment variable for the API service.
- In WordPress, adjust the **Signatur‑Timeout (Tage)** option under the plugin settings to match the server value.

### Default escalation policy

- If the buyer fails to sign within 7 days after the vendor marks the order as delivered, the escrow operator
  may co‑sign with the vendor to complete the payout.
- If the vendor does not deliver and remains inactive for 7 days, the escrow operator may co‑sign with the
  buyer to refund the order.

Admins can override deadlines or trigger payouts/refunds manually from the order panel if exceptional
circumstances require intervention. Increase or decrease `SIGNING_DEADLINE_DAYS` on the server and in the
plugin settings to customize the window for your shop.

## FAQ

### When does an escalation happen?
The background worker checks `signing` orders against their `deadline_ts`. Once the timestamp has passed and
only one signature is present, the API co‑signs and broadcasts the payout or refund automatically.

### Can admins override the process?
Yes. WordPress administrators can initiate payouts, refunds, or disputes directly from the order panel,
which bypasses the automatic deadline handling.

### Where can I find more documentation?
- [API reference](docs/api.md)
- [Operator guide](docs/operator-guide.md)
- [WooCommerce user guide](docs/woo-user-guide.md)
- [Sample Terms of Service clause](docs/terms-template.md)
