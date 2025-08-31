<?php
if (!defined('ABSPATH')) exit;

class WEO_Checkout {
  public function __construct() {
    add_action('woocommerce_after_order_notes', [$this,'field']);
    add_action('woocommerce_checkout_process', [$this,'validate']);
    add_action('woocommerce_checkout_update_order_meta', [$this,'save']);
  }

  public function field($checkout) {
    woocommerce_form_field('_weo_buyer_xpub', [
      'type'=>'text','class'=>['form-row-wide'],
      'label'=>'Dein Escrow-xpub (für Signatur der Freigabe/Refund)',
      'required'=>true,
      'placeholder'=>'xpub... / zpub...'
    ], $checkout->get_value('_weo_buyer_xpub'));
  }

  public function validate() {
    if (empty($_POST['_weo_buyer_xpub'])) wc_add_notice('Bitte Escrow-xpub angeben.', 'error');
  }

  public function save($order_id) {
    if (!empty($_POST['_weo_buyer_xpub'])) {
      update_post_meta($order_id,'_weo_buyer_xpub', weo_sanitize_xpub(wp_unslash($_POST['_weo_buyer_xpub'])));
    }
  }
}
