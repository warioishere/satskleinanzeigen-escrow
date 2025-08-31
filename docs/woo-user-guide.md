# WooCommerce User Guide

## Exporting an xpub

1. In your Bitcoin wallet, locate the account you wish to use for escrow.
2. Find the extended public key (xpub, zpub, etc.).
3. Copy the key and paste it into the plugin’s xpub field. The plugin normalizes Slip132
   prefixes automatically.

## Funding the escrow

When the payment panel appears, it shows the product price **plus** an estimated payout fee.
Send this total amount so the seller receives the full price.  The fee is estimated using
Bitcoin Core's `estimatesmartfee` and a typical payout transaction size, so the actual
fee paid at broadcast time may differ slightly.

## Signing a PSBT

1. Download the partially signed transaction (PSBT) from the order page after clicking
   the payout or refund button.
2. Import the PSBT into your wallet:
   - Electrum: `Tools -> Load transaction -> From file`
   - Sparrow: `File -> Open Transaction`
3. Sign the transaction and export the updated PSBT file.
4. Upload the signed PSBT back on the order page using your role-specific upload form.

Once both required signatures are present, the plugin will finalize and broadcast the transaction.
