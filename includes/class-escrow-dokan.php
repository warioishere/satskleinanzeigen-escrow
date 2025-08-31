<?php
if (!defined('ABSPATH')) exit;

class WEO_Dokan {
  public function __construct() {
    add_filter('dokan_get_dashboard_nav', [$this,'nav']);
    add_action('dokan_render_settings_content', [$this,'page']);
  }

  public function nav($urls) {
    if (!current_user_can('vendor') && !current_user_can('seller')) return $urls;
    $urls['weo-treuhand'] = [
      'title' => __('Treuhand Service','weo'),
      'icon'  => '<i class="dashicons-lock"></i>',
      'url'   => dokan_get_navigation_url('weo-treuhand'),
      'pos'   => 51,
    ];
    $urls['weo-treuhand-orders'] = [
      'title' => __('Treuhand-Bestellungen','weo'),
      'icon'  => '<i class="dashicons-cart"></i>',
      'url'   => dokan_get_navigation_url('weo-treuhand-orders'),
      'pos'   => 52,
    ];
    return $urls;
  }

  public function page($query_vars) {
    if (!current_user_can('vendor') && !current_user_can('seller')) {
      dokan_add_notice(__('Keine Berechtigung','weo'),'error');
      return;
    }

    $user_id = get_current_user_id();

    if (isset($query_vars['weo-treuhand-orders'])) {
      wp_enqueue_style('weo-css', WEO_URL.'assets/admin.css', [], '1.0');
      wp_enqueue_script('weo-qr', WEO_URL.'assets/qr.min.js', [], '1.0', true);

      $orders = wc_get_orders([
        'limit'         => -1,
        'customer'      => 0,
        'meta_key'      => '_weo_vendor_id',
        'meta_value'    => $user_id,
        'payment_method'=> 'weo_gateway',
        'return'        => 'objects',
      ]);

      $list = [];
      foreach ($orders as $order) {
        $addr = $order->get_meta('_weo_escrow_addr');
        $oid  = weo_sanitize_order_id((string)$order->get_order_number());
        $state = 'unknown';
        $funding = null;
        if ($addr && $oid) {
          $status = weo_api_get('/orders/'.rawurlencode($oid).'/status');
          if (!is_wp_error($status)) {
            $state = $status['state'] ?? 'unknown';
            $funding = $status['funding'] ?? null;
          }
        }
        $list[] = [
          'id'      => $order->get_id(),
          'number'  => $order->get_order_number(),
          'addr'    => $addr,
          'state'   => $state,
          'funding' => $funding,
        ];
      }

      $file = WEO_DIR.'templates/dokan-treuhand-orders.php';
      if (file_exists($file)) { $orders = $list; include $file; }
      return;
    }

    if (!isset($query_vars['weo-treuhand'])) return;

    if ('POST' === $_SERVER['REQUEST_METHOD'] && isset($_POST['weo_vendor_xpub'])) {
      check_admin_referer('weo_dokan_xpub');
      $xpub   = weo_normalize_xpub(wp_unslash($_POST['weo_vendor_xpub']));
      $payout = isset($_POST['weo_vendor_payout_address']) ? wp_unslash($_POST['weo_vendor_payout_address']) : '';
      $ok = true;
      if (is_wp_error($xpub)) { dokan_add_notice(__('Ungültiges xpub','weo'),'error'); $ok = false; }
      if ($payout && !weo_validate_btc_address($payout)) { dokan_add_notice(__('Ungültige Adresse','weo'),'error'); $ok = false; }
      if ($ok) {
        update_user_meta($user_id,'weo_vendor_xpub',$xpub);
        if ($payout) update_user_meta($user_id,'weo_vendor_payout_address', weo_sanitize_btc_address($payout));
        dokan_add_notice(__('Escrow-Daten gespeichert','weo'),'success');
      }
    }

    $xpub   = get_user_meta($user_id,'weo_vendor_xpub',true);
    $payout = get_user_meta($user_id,'weo_vendor_payout_address',true);
    $file = WEO_DIR.'templates/dokan-treuhand.php';
    if (file_exists($file)) include $file;
  }
}
