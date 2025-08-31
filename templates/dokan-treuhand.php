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
    <button type="submit" class="dokan-btn dokan-btn-theme">
      <?php esc_html_e('Speichern','weo'); ?>
    </button>
  </div>
</form>
