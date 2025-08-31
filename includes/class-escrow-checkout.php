<?php
if (!defined('ABSPATH')) exit;

class WEO_Checkout {
  public function __construct() {
    add_action('woocommerce_after_order_notes', [$this,'field']);
    add_action('woocommerce_checkout_process', [$this,'validate']);
    add_action('woocommerce_checkout_update_order_meta', [$this,'save']);
  }

  public function field($checkout) {
    $required = true;
    $value = $checkout->get_value('_weo_buyer_xpub');
    if (is_user_logged_in()) {
      $vendor_xpub = get_user_meta(get_current_user_id(), 'weo_vendor_xpub', true);
      if (!empty($vendor_xpub)) {
        $value = $vendor_xpub;
        $required = false;
      }
    }
    woocommerce_form_field('_weo_buyer_xpub', [
      'type' => 'text',
      'class' => ['form-row-wide'],
      'label' => 'Dein Escrow-xpub (für Signatur der Freigabe/Refund)',
      'required' => $required,
      'placeholder' => 'xpub... / zpub...'
    ], $value);
  }

  public function validate() {
    $vendor_xpub = is_user_logged_in() ? get_user_meta(get_current_user_id(), 'weo_vendor_xpub', true) : '';
    if (empty($_POST['_weo_buyer_xpub'])) {
      if (empty($vendor_xpub)) {
        wc_add_notice('Bitte Escrow-xpub angeben.', 'error');
        return;
      }
      $_POST['_weo_buyer_xpub'] = $vendor_xpub;
    }
    $norm = weo_normalize_xpub(wp_unslash($_POST['_weo_buyer_xpub']));
    if (is_wp_error($norm)) {
      wc_add_notice('Ungültiges xpub.', 'error');
    } else {
      $_POST['_weo_buyer_xpub'] = $norm;
    }
  }

  public function save($order_id) {
    if (!empty($_POST['_weo_buyer_xpub'])) {
      update_post_meta($order_id,'_weo_buyer_xpub', weo_sanitize_xpub(wp_unslash($_POST['_weo_buyer_xpub'])));
    }
  }
}
