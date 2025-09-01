<?php
if (!defined('ABSPATH')) exit;

class WEO_Dokan {
  public function __construct() {
    add_filter('dokan_get_dashboard_nav', [$this,'nav']);
    add_action('dokan_load_custom_template', [$this,'page']);
    add_action('dokan_product_edit_after_pricing', [$this,'product_field'], 10, 2);
    add_action('dokan_process_product_meta', [$this,'save_product_meta'], 10, 2);
    add_filter('woocommerce_is_purchasable', [$this,'is_purchasable'], 10, 2);
    add_filter('woocommerce_loop_add_to_cart_link', [$this,'maybe_hide_add_to_cart'], 10, 3);
    add_filter('dokan_query_vars', [$this,'query_vars']);
    add_action('init', [$this,'add_endpoints']);
  }

  public function nav($urls) {
    if (!current_user_can('vendor') && !current_user_can('seller')) return $urls;
    $urls['weo-treuhand-orders'] = [
      'title' => __('Treuhand Service','weo'),
      'icon'  => '<i class="dashicons-lock"></i>',
      'url'   => dokan_get_navigation_url('weo-treuhand-orders'),
      'pos'   => 51,
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

      $psbt_notice = '';

      if ('POST' === $_SERVER['REQUEST_METHOD'] && !empty($_POST['weo_action']) && !empty($_POST['order_id'])) {
        $order_id = intval($_POST['order_id']);
        $order = wc_get_order($order_id);
        if (!$order) {
          dokan_add_notice(__('Bestellung nicht gefunden','weo'),'error');
        } else {
          $act = sanitize_text_field($_POST['weo_action']);
          $vendor_id = intval($order->get_meta('_weo_vendor_id'));
          $buyer_id  = $order->get_user_id();

          if ($act === 'mark_shipped') {
            if (!wp_verify_nonce($_POST['weo_nonce'] ?? '', 'weo_ship_'.$order_id)) {
              dokan_add_notice(__('Ungültiger Sicherheits-Token','weo'),'error');
            } elseif ($user_id !== $vendor_id) {
              dokan_add_notice(__('Keine Berechtigung','weo'),'error');
            } else {
              $order->update_meta_data('_weo_shipped', time());
              $order->save();
              do_action('weo_order_shipped', $order_id);
              dokan_add_notice(__('Versand markiert','weo'),'success');
            }
          } elseif ($act === 'mark_received') {
            if (!wp_verify_nonce($_POST['weo_nonce'] ?? '', 'weo_recv_'.$order_id)) {
              dokan_add_notice(__('Ungültiger Sicherheits-Token','weo'),'error');
            } elseif ($user_id !== $buyer_id) {
              dokan_add_notice(__('Keine Berechtigung','weo'),'error');
            } else {
              $order->update_meta_data('_weo_received', time());
              $order->save();
              do_action('weo_order_received', $order_id);
              dokan_add_notice(__('Empfang bestätigt','weo'),'success');
            }
          } else {
            if (!wp_verify_nonce($_POST['weo_nonce'] ?? '', 'weo_psbt_'.$order_id)) {
              dokan_add_notice(__('Ungültiger Sicherheits-Token','weo'),'error');
            } elseif ($act === 'build_psbt_refund') {
              if (!current_user_can('manage_options')) {
                dokan_add_notice(__('Keine Berechtigung','weo'),'error');
              } else {
                $res = WEO_Psbt::build_refund_psbt($order_id);
                if (is_array($res)) {
                  $psbt_notice = '<div class="dokan-alert dokan-alert-success"><p><strong>'.esc_html__('PSBT (Base64)','weo').':</strong></p><textarea rows="4" style="width:100%;">'.$res['psbt'].'</textarea>'.$res['details'].'</div>';
                } else {
                  dokan_add_notice($res->get_error_message(),'error');
                }
              }
            } else {
              if ($vendor_id !== $user_id) {
                dokan_add_notice(__('Keine Berechtigung','weo'),'error');
              } else {
                if ($act === 'build_psbt_payout') {
                  $res = WEO_Psbt::build_payout_psbt($order_id);
                  if (is_array($res)) {
                    $psbt_notice = '<div class="dokan-alert dokan-alert-success"><p><strong>'.esc_html__('PSBT (Base64)','weo').':</strong></p><textarea rows="4" style="width:100%;">'.$res['psbt'].'</textarea>'.$res['details'].'</div>';
                  } else {
                    dokan_add_notice($res->get_error_message(),'error');
                  }
                }
              }
            }
          }
        }
      }

      $vendor_orders = wc_get_orders([
        'limit'         => -1,
        'customer'      => 0,
        'meta_key'      => '_weo_vendor_id',
        'meta_value'    => $user_id,
        'payment_method'=> 'weo_gateway',
        'return'        => 'objects',
      ]);
      $buyer_orders = wc_get_orders([
        'limit'         => -1,
        'customer'      => $user_id,
        'payment_method'=> 'weo_gateway',
        'return'        => 'objects',
      ]);

      $list = [];
      $seen = [];
      foreach ($vendor_orders as $order) {
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
          'id'        => $order->get_id(),
          'number'    => $order->get_order_number(),
          'addr'      => $addr,
          'state'     => $state,
          'funding'   => $funding,
          'shipped'   => intval($order->get_meta('_weo_shipped')),
          'received'  => intval($order->get_meta('_weo_received')),
          'buyer_id'  => $order->get_user_id(),
          'vendor_id' => intval($order->get_meta('_weo_vendor_id')),
          'payout_txid'=> $order->get_meta('_weo_payout_txid'),
          'role'      => 'vendor',
        ];
        $seen[$order->get_id()] = true;
      }

      foreach ($buyer_orders as $order) {
        if (isset($seen[$order->get_id()])) continue;
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
          'id'        => $order->get_id(),
          'number'    => $order->get_order_number(),
          'addr'      => $addr,
          'state'     => $state,
          'funding'   => $funding,
          'shipped'   => intval($order->get_meta('_weo_shipped')),
          'received'  => intval($order->get_meta('_weo_received')),
          'buyer_id'  => $order->get_user_id(),
          'vendor_id' => intval($order->get_meta('_weo_vendor_id')),
          'payout_txid'=> $order->get_meta('_weo_payout_txid'),
          'role'      => 'buyer',
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
      $payout = isset($_POST['weo_payout_address']) ? wp_unslash($_POST['weo_payout_address']) : '';
      $escrow = isset($_POST['weo_vendor_escrow_enabled']) ? '1' : '';
      $ok = true;
      if (is_wp_error($xpub)) { dokan_add_notice(__('Ungültiges xpub','weo'),'error'); $ok = false; }
      if ($payout && !weo_validate_btc_address($payout)) { dokan_add_notice(__('Ungültige Adresse','weo'),'error'); $ok = false; }
      if ($ok) {
        update_user_meta($user_id,'weo_vendor_xpub',$xpub);
        if ($payout) update_user_meta($user_id,'weo_payout_address', weo_sanitize_btc_address($payout));
        if ($escrow) update_user_meta($user_id,'weo_vendor_escrow_enabled','1');
        else delete_user_meta($user_id,'weo_vendor_escrow_enabled');
        dokan_add_notice(__('Escrow-Daten gespeichert','weo'),'success');
      }
    }

    $xpub   = get_user_meta($user_id,'weo_vendor_xpub',true);
    $payout = weo_get_payout_address($user_id);
    $escrow_enabled = get_user_meta($user_id,'weo_vendor_escrow_enabled',true);
    $file = WEO_DIR.'templates/dokan-treuhand.php';
    if (file_exists($file)) include $file;
  }

  public function product_field($post, $post_id) {
    $val = get_post_meta($post_id,'_weo_escrow_product',true);
    ?>
    <div class="dokan-form-group">
      <label for="_weo_escrow_product">
        <input type="checkbox" name="_weo_escrow_product" id="_weo_escrow_product" value="yes" <?php checked($val,'yes'); ?>>
        <?php esc_html_e('Escrow-Service aktiv','weo'); ?>
      </label>
    </div>
    <?php
  }

  public function save_product_meta($post_id, $post) {
    $enabled = isset($_POST['_weo_escrow_product']) ? 'yes' : '';
    if ($enabled) update_post_meta($post_id,'_weo_escrow_product','yes');
    else delete_post_meta($post_id,'_weo_escrow_product');
  }

  public function is_purchasable($purchasable, $product) {
    if (!$purchasable) return $purchasable;
    $vendor_id = get_post_field('post_author',$product->get_id());
    $vendor_on = get_user_meta($vendor_id,'weo_vendor_escrow_enabled',true);
    $product_on = get_post_meta($product->get_id(),'_weo_escrow_product',true);
    if (!$vendor_on || !$product_on) return false;
    return $purchasable;
  }

  public function maybe_hide_add_to_cart($html, $product, $args) {
    return $product->is_purchasable() ? $html : '';
  }

  public function query_vars($vars) {
    $vars[] = 'weo-treuhand-orders';
    $vars[] = 'weo-treuhand';
    return $vars;
  }

  public function add_endpoints() {
    add_rewrite_endpoint('weo-treuhand-orders', EP_ROOT | EP_PAGES);
    add_rewrite_endpoint('weo-treuhand', EP_ROOT | EP_PAGES);
  }

  /** Fallback – trag hier eine Vendor-Payout-Adresse ein, falls nicht separat gepflegt */
  private function fallback_vendor_payout_address($order_id) {
    $order = wc_get_order($order_id);
    if ($order) {
      $vendor_id = $order->get_meta('_weo_vendor_id');
      if (!$vendor_id) {
        foreach ($order->get_items('line_item') as $item) {
          $pid = $item->get_product_id();
          $vendor_id = get_post_field('post_author',$pid);
          if ($vendor_id) break;
        }
        if ($vendor_id) { $order->update_meta_data('_weo_vendor_id',$vendor_id); $order->save(); }
      }
      if ($vendor_id) {
        $payout = weo_get_payout_address($vendor_id);
        if ($payout) return $payout;
      }
    }
    $fallback = get_option('weo_vendor_payout_fallback','');
    if ($fallback) return $fallback;
    wc_add_notice(__('Keine Fallback-Payout-Adresse konfiguriert.','weo'),'error');
    throw new Exception('Fallback vendor payout address missing');
  }
}
