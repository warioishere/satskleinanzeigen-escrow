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

- `BTC_CORE_URL` – RPC URL (default `http://127.0.0.1:8332/`)
- `BTC_CORE_USER` / `BTC_CORE_PASS` – RPC credentials
- `BTC_CORE_WALLET` – watch-only wallet name (default `escrowwatch`)
- `API_KEYS` – comma-separated list of active keys
- `API_KEY_REVOKED` – optional comma-separated list of revoked keys
- `ALLOW_ORIGINS` – comma-separated list of permitted CORS origins
- `WEBHOOK_RETRIES` – retry attempts for Woo callbacks (default 3)
- `WEBHOOK_BACKOFF` – multiplier for exponential backoff (default 2)
- `ORDERS_DB` – path to SQLite file (default `orders.sqlite`)
- `SIGNING_DEADLINE_DAYS` – days before unsigned orders auto-escalate (default 7)
- `STUCK_ORDER_HOURS` – hours before orders are reported as stuck

Load these variables via an environment file or a secret manager in production.

## Systemd service

1. **Install dependencies**
   ```bash
   sudo apt-get install python3 python3-venv
   git clone https://example.com/satskleinanzeigen-escrow.git
   cd satskleinanzeigen-escrow/python-api
   python3 -m venv venv
   source venv/bin/activate
   pip install fastapi slowapi structlog prometheus_client requests python-dotenv uvicorn
   ```
2. **Create watch-only wallet**
   ```bash
   bitcoin-cli createwallet "escrowwatch" true true "" true true
   ```
3. **Environment file** (`/etc/default/escrow-api`)
   ```
   BTC_CORE_URL=http://127.0.0.1:8332/
   BTC_CORE_USER=escrow
   BTC_CORE_PASS=change-me
   BTC_CORE_WALLET=escrowwatch
   ALLOW_ORIGINS=https://your-wordpress-site
   API_KEYS=your_api_key
   ```
4. **Systemd unit** (`/etc/systemd/system/escrow-api.service`)
   ```
   [Unit]
   Description=Escrow API
   After=network.target

   [Service]
   Type=simple
   WorkingDirectory=/path/to/satskleinanzeigen-escrow/python-api
   EnvironmentFile=/etc/default/escrow-api
   ExecStart=/path/to/satskleinanzeigen-escrow/python-api/venv/bin/uvicorn python_api.main:app --host 0.0.0.0 --port 8080
   Restart=on-failure

   [Install]
   WantedBy=multi-user.target
   ```
5. **Enable and start**
   ```bash
   sudo systemctl daemon-reload
   sudo systemctl enable --now escrow-api
   ```

Monitor logs and Prometheus metrics under `/metrics` to ensure the service operates correctly.

## Fee handling

Buyers are expected to deposit the product price **plus** an estimated payout fee.  When an
order is created, the API calls Bitcoin Core's `estimatesmartfee` and multiplies the returned
feerate by a typical payout transaction size to derive `fee_est_sat`.  The WordPress plugin
displays the total escrow target (`amount_sat + fee_est_sat`) so the seller receives the
full price.  Because feerates fluctuate, the final miner fee may differ slightly from the
estimate; any difference is reconciled when the payout transaction is broadcast.
