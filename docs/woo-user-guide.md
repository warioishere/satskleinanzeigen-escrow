# WooCommerce User Guide

## Exporting an xpub

1. In your Bitcoin wallet, locate the account you wish to use for escrow.
2. Find the extended public key (xpub, zpub, etc.).
3. Copy the key and paste it into the plugin’s xpub field. The plugin normalizes Slip132
   prefixes automatically.

## Signing a PSBT

1. Download the partially signed transaction (PSBT) from the order page after clicking
   the payout or refund button.
2. Import the PSBT into your wallet:
   - Electrum: `Tools -> Load transaction -> From file`
   - Sparrow: `File -> Open Transaction`
3. Sign the transaction and export the updated PSBT file.
4. Upload the signed PSBT back on the order page using your role-specific upload form.

Once both required signatures are present, the plugin will finalize and broadcast the transaction.
