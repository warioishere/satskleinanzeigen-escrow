<?php
if (!defined('ABSPATH')) exit;
?>
<form method="post" class="dokan-form">
  <?php wp_nonce_field('weo_dokan_xpub'); ?>
  <div class="dokan-form-group">
    <label for="weo_vendor_xpub" class="dokan-form-label"><?php esc_html_e('Vendor xpub','weo'); ?></label>
    <input type="text" class="dokan-form-control" name="weo_vendor_xpub" id="weo_vendor_xpub" value="<?php echo esc_attr($xpub); ?>">
  </div>
  <div class="dokan-form-group">
    <label for="weo_payout_address" class="dokan-form-label"><?php esc_html_e('Payout-/Refund-Adresse','weo'); ?></label>
    <input type="text" class="dokan-form-control" name="weo_payout_address" id="weo_payout_address" value="<?php echo esc_attr($payout); ?>">
    <p class="help-block"><?php esc_html_e('Diese Adresse wird für Auszahlungen und Rückerstattungen verwendet.','weo'); ?></p>
  </div>
  <div class="dokan-form-group">
    <label for="weo_vendor_escrow_enabled" class="dokan-form-label">
      <input type="checkbox" name="weo_vendor_escrow_enabled" id="weo_vendor_escrow_enabled" value="1" <?php checked($escrow_enabled,'1'); ?>>
      <?php esc_html_e('Escrow-Service aktiv','weo'); ?>
    </label>
  </div>
  <div class="dokan-form-group">
    <button type="submit" class="dokan-btn dokan-btn-theme">
      <?php esc_html_e('Speichern','weo'); ?>
    </button>
  </div>
</form>
