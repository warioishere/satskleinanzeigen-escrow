# Operator Guide

## Bitcoin Core setup

1. Install Bitcoin Core >= 25 with wallet support.
2. Create a wallet dedicated to escrow operations:
   ```bash
   bitcoin-cli createwallet "escrowwatch" true true "" true true
   ```
3. Enable RPC access for the API user in `bitcoin.conf`:
   ```ini
   rpcuser=escrow
   rpcpassword=change-me
   ```
4. Start `bitcoind` and ensure the API host can reach it via RPC.

## Environment variables

The API reads configuration from environment variables:

- `BITCOIN_RPCUSER` / `BITCOIN_RPCPASSWORD`
- `BITCOIN_RPCHOST` (default `127.0.0.1`)
- `BITCOIN_RPCPORT` (default `8332`)
- `API_KEYS` – comma-separated list of active keys
- `API_KEY_REVOKED` – optional comma-separated list of revoked keys
- `ALLOW_ORIGINS` – comma-separated list of permitted CORS origins
- `WEBHOOK_RETRIES` – retry attempts for Woo callbacks (default 3)
- `WEBHOOK_BACKOFF` – multiplier for exponential backoff (default 2)
- `ORDERS_DB` – path to SQLite file (default `orders.sqlite`)
- `SIGNING_DEADLINE_DAYS` – days before unsigned orders auto-escalate (default 7)
- `STUCK_ORDER_HOURS` – hours before orders are reported as stuck

Load these variables via systemd `Environment=` directives or a secret manager in production.

## Starting the API

```bash
pip install -r requirements.txt
uvicorn api:app --host 0.0.0.0 --port 8000
```

Monitor logs and Prometheus metrics under `/metrics` to ensure the service operates correctly.
