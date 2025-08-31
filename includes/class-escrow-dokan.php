<?php
if (!defined('ABSPATH')) exit;

class WEO_Dokan {
  public function __construct() {
    add_filter('dokan_get_dashboard_nav', [$this,'nav']);
    add_action('dokan_render_settings_content', [$this,'page']);
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
              dokan_add_notice(__('Empfang bestätigt','weo'),'success');
            }
          } else {
            if (!wp_verify_nonce($_POST['weo_nonce'] ?? '', 'weo_psbt_'.$order_id)) {
              dokan_add_notice(__('Ungültiger Sicherheits-Token','weo'),'error');
            } elseif ($act === 'build_psbt_refund') {
              if (!current_user_can('manage_options')) {
                dokan_add_notice(__('Keine Berechtigung','weo'),'error');
              } else {
                $oid = weo_sanitize_order_id((string)$order->get_order_number());
                $refundAddr = get_user_meta($order->get_user_id(), 'weo_buyer_payout_address', true);
                if (!$refundAddr) {
                  dokan_add_notice(__('Keine Käuferadresse hinterlegt.','weo'),'error');
                } elseif (!weo_validate_btc_address($refundAddr)) {
                  dokan_add_notice(__('Adresse ungültig.','weo'),'error');
                } else {
                  $resp = weo_api_post('/psbt/build_refund', [
                    'order_id'    => $oid,
                    'address'     => $refundAddr,
                    'target_conf' => 3,
                  ]);
                  if (!is_wp_error($resp) && !empty($resp['psbt'])) {
                    $psbt_b64 = esc_textarea($resp['psbt']);
                    $details = '';
                    $dec = weo_api_post('/psbt/decode', [ 'psbt' => $resp['psbt'] ]);
                    if (!is_wp_error($dec)) {
                      $outs = $dec['outputs'] ?? [];
                      if ($outs) {
                        $details .= '<p><strong>'.esc_html__('Outputs','weo').':</strong></p><ul>';
                        foreach ($outs as $addr => $sats) {
                          $details .= '<li>'.esc_html($addr).' – '.esc_html(number_format_i18n($sats)).' sats</li>';
                        }
                        $details .= '</ul>';
                      }
                      if (isset($dec['fee_sat'])) {
                        $details .= '<p><strong>'.esc_html__('Gebühr','weo').':</strong> '.esc_html(number_format_i18n(intval($dec['fee_sat']))).' sats</p>';
                      }
                    }
                    $psbt_notice = '<div class="dokan-alert dokan-alert-success"><p><strong>'.esc_html__('PSBT (Base64)','weo').':</strong></p><textarea rows="4" style="width:100%;">'.$psbt_b64.'</textarea>'.$details.'</div>';
                  } else {
                    dokan_add_notice(__('PSBT konnte nicht erstellt werden.','weo'),'error');
                  }
                }
              }
            } else {
              if ($vendor_id !== $user_id) {
                dokan_add_notice(__('Keine Berechtigung','weo'),'error');
              } else {
                $oid = weo_sanitize_order_id((string)$order->get_order_number());
                if ($act === 'build_psbt_payout') {
                  $payoutAddr = get_user_meta($user_id,'weo_vendor_payout_address',true);
                  if (!$payoutAddr) $payoutAddr = $this->fallback_vendor_payout_address($order_id);
                  if (!weo_validate_btc_address($payoutAddr)) {
                    dokan_add_notice(__('Payout-Adresse ungültig.','weo'),'error');
                  } else {
                    $status  = weo_api_get('/orders/'.rawurlencode($oid).'/status');
                    $funded  = is_wp_error($status) ? 0 : intval($status['funding']['total_sat'] ?? 0);
                    if ($funded <= 0) {
                      dokan_add_notice(__('Keine Escrow-Einzahlung gefunden.','weo'),'error');
                    } else {
                      $quote = weo_api_post('/orders/'.rawurlencode($oid).'/payout_quote', [
                        'address'     => $payoutAddr,
                        'target_conf' => 3,
                      ]);
                      if (is_wp_error($quote) || empty($quote['payout_sat'])) {
                        dokan_add_notice(__('Fee-Kalkulation fehlgeschlagen.','weo'),'error');
                      } else {
                        $amount_sats = intval($quote['payout_sat']);
                        if ($amount_sats <= 0 || !weo_validate_amount($amount_sats)) {
                          dokan_add_notice(__('Betrag ungültig.','weo'),'error');
                        } else {
                          $resp = weo_api_post('/psbt/build', [
                            'order_id'    => $oid,
                            'outputs'     => [ $payoutAddr => $amount_sats ],
                            'rbf'         => true,
                            'target_conf' => 3,
                          ]);
                          if (!is_wp_error($resp) && !empty($resp['psbt'])) {
                            $psbt_b64 = esc_textarea($resp['psbt']);
                            $details = '';
                            $dec = weo_api_post('/psbt/decode', [ 'psbt' => $resp['psbt'] ]);
                            if (!is_wp_error($dec)) {
                              $outs = $dec['outputs'] ?? [];
                              if ($outs) {
                                $details .= '<p><strong>'.esc_html__('Outputs','weo').':</strong></p><ul>';
                                foreach ($outs as $addr => $sats) {
                                  $details .= '<li>'.esc_html($addr).' – '.esc_html(number_format_i18n($sats)).' sats</li>';
                                }
                                $details .= '</ul>';
                              }
                              if (isset($dec['fee_sat'])) {
                                $details .= '<p><strong>'.esc_html__('Gebühr','weo').':</strong> '.esc_html(number_format_i18n(intval($dec['fee_sat']))).' sats</p>';
                              }
                            }
                            $psbt_notice = '<div class="dokan-alert dokan-alert-success"><p><strong>'.esc_html__('PSBT (Base64)','weo').':</strong></p><textarea rows="4" style="width:100%;">'.$psbt_b64.'</textarea>'.$details.'</div>';
                          } else {
                            dokan_add_notice(__('PSBT konnte nicht erstellt werden.','weo'),'error');
                          }
                        }
                      }
                    }
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
        $payout = get_user_meta($vendor_id,'weo_vendor_payout_address',true);
        if ($payout) return $payout;
      }
    }
    return get_option('weo_vendor_payout_fallback','bc1qexamplefallbackaddressxxxxxxxxxxxxxxxxxx');
  }
}
