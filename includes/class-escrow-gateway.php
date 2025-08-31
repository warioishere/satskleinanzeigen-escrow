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
    add_action('woocommerce_thankyou_' . $this->id, [$this, 'thankyou_page']);
    add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
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

    $addr_required = true;
    $addr_value    = '';
    if (is_user_logged_in()) {
      $saved = get_user_meta(get_current_user_id(), 'weo_buyer_payout_address', true);
      if (!empty($saved)) {
        $addr_value    = $saved;
        $addr_required = false;
      }
    }
    woocommerce_form_field('_weo_buyer_payout_address', [
      'type'        => 'text',
      'class'       => ['form-row-wide'],
      'label'       => 'Deine Refund-Adresse',
      'required'    => $addr_required,
      'placeholder' => 'bc1...'
    ], $addr_value);
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

    $saved_addr = is_user_logged_in() ? get_user_meta(get_current_user_id(), 'weo_buyer_payout_address', true) : '';
    $addr = wp_unslash($_POST['_weo_buyer_payout_address'] ?? '');
    if (empty($addr)) {
      if (empty($saved_addr)) {
        wc_add_notice('Bitte Refund-Adresse angeben.', 'error');
        return false;
      }
      $addr = $saved_addr;
    }
    if (!weo_validate_btc_address($addr)) {
      wc_add_notice('Refund-Adresse ungültig.', 'error');
      return false;
    }
    $_POST['_weo_buyer_payout_address'] = $addr;

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

    $addr = weo_sanitize_btc_address(wp_unslash($_POST['_weo_buyer_payout_address'] ?? ''));
    if ($addr && is_user_logged_in()) {
      update_user_meta(get_current_user_id(), 'weo_buyer_payout_address', $addr);
    }

    if (class_exists('WEO_Order')) {
      WEO_Order::maybe_create_escrow($order_id, 'pending', 'on-hold', $order);
    }

    $order->update_status('on-hold');

    WC()->cart->empty_cart();

    return [
      'result'   => 'success',
      'redirect' => $this->get_return_url($order)
    ];
  }

  public function thankyou_page($order_id) {
    if (class_exists('WEO_Order')) {
      WEO_Order::render_order_panel($order_id);
    }
  }

  public function enqueue_assets() {
    if (is_order_received_page()) {
      $oid = absint(get_query_var('order-received'));
    } elseif (is_account_page()) {
      $oid = absint(get_query_var('view-order'));
    } else {
      return;
    }
    if (!$oid) return;
    $order = wc_get_order($oid);
    if (!$order || $order->get_payment_method() !== $this->id) return;
    wp_enqueue_style('weo-css', WEO_URL.'assets/admin.css', [], '1.0');
    wp_enqueue_script('weo-qr', WEO_URL.'assets/qr.min.js', [], '1.0', true);
  }
}
