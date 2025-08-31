# Woo Escrow On-Chain

This project links a WooCommerce plugin with a Python FastAPI service to handle Bitcoin multisig escrow.

## Vendor setup

Dokan sellers can configure their escrow xpub under **Treuhand Service** in the vendor dashboard.

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

Example scrape configuration:

```yaml
scrape_configs:
  - job_name: escrow_api
    static_configs:
      - targets: ['api.example.com:8080']
```
