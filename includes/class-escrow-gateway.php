<?php
if (!defined('ABSPATH')) exit;

class WEO_Gateway extends WC_Payment_Gateway {
  public function __construct() {
    $this->id                 = 'weo_gateway';
    $this->method_title       = 'Escrow (On-Chain)';
    $this->method_description = '2-of-3 Multisig Escrow';
    $this->has_fields         = true;

    $this->init_form_fields();
    $this->init_settings();

    $this->title       = $this->get_option('title');
    $this->description = $this->get_option('description');

    add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
  }

  public function init_form_fields() {
    $this->form_fields = [
      'enabled' => [
        'title'   => 'Enable/Disable',
        'type'    => 'checkbox',
        'label'   => 'Escrow-Zahlung aktivieren',
        'default' => 'yes'
      ],
      'title' => [
        'title'   => 'Title',
        'type'    => 'text',
        'default' => 'Escrow'
      ],
      'description' => [
        'title'   => 'Description',
        'type'    => 'textarea',
        'default' => 'Escrow-Zahlung (2-von-3 Multisig)'
      ]
    ];
  }

  public function payment_fields() {
    if ($this->description) {
      echo wpautop(wp_kses_post($this->description));
    }
    $required = true;
    $value    = '';
    if (is_user_logged_in()) {
      $vendor_xpub = get_user_meta(get_current_user_id(), 'weo_vendor_xpub', true);
      if (!empty($vendor_xpub)) {
        $value    = $vendor_xpub;
        $required = false;
      }
    }
    woocommerce_form_field('_weo_buyer_xpub', [
      'type'        => 'text',
      'class'       => ['form-row-wide'],
      'label'       => 'Dein Escrow-xpub (für Signatur der Freigabe/Refund)',
      'required'    => $required,
      'placeholder' => 'xpub... / zpub...'
    ], $value);
  }

  public function validate_fields() {
    $vendor_xpub = is_user_logged_in() ? get_user_meta(get_current_user_id(), 'weo_vendor_xpub', true) : '';
    if (empty($_POST['_weo_buyer_xpub'])) {
      if (empty($vendor_xpub)) {
        wc_add_notice('Bitte Escrow-xpub angeben.', 'error');
        return false;
      }
      $_POST['_weo_buyer_xpub'] = $vendor_xpub;
    }
    $norm = weo_normalize_xpub(wp_unslash($_POST['_weo_buyer_xpub']));
    if (is_wp_error($norm)) {
      wc_add_notice('Ungültiges xpub.', 'error');
      return false;
    }
    $_POST['_weo_buyer_xpub'] = $norm;
    return true;
  }

  public function process_payment($order_id) {
    $order = wc_get_order($order_id);
    if (!$order) return ['result' => 'failure'];

    $xpub = weo_sanitize_xpub(wp_unslash($_POST['_weo_buyer_xpub'] ?? ''));
    if ($xpub) {
      $order->update_meta_data('_weo_buyer_xpub', $xpub);
      $order->save();
    }

    $order->update_status('on-hold');

    if (class_exists('WEO_Order')) {
      WEO_Order::maybe_create_escrow($order_id, 'pending', 'on-hold', $order);
    }

    WC()->cart->empty_cart();

    return [
      'result'   => 'success',
      'redirect' => $this->get_return_url($order)
    ];
  }
}
