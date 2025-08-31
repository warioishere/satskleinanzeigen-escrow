# Woo Escrow On-Chain

This project links a WooCommerce plugin with a Python FastAPI service to handle Bitcoin multisig escrow.

## API key management

The API reads a comma-separated list of valid keys from the `API_KEYS` environment variable. You can revoke individual keys by listing them in `API_KEY_REVOKED`.

The WooCommerce plugin holds a single active key in its settings and sends it with every request using the `x-api-key` header.

### Key rotation
1. Deploy a new key and add it to `API_KEYS` on the API server.
2. Update the plugin setting with the new key.
3. Once clients have switched, remove the old key from `API_KEYS` or move it to `API_KEY_REVOKED`.
