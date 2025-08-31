<?php
if (!defined('ABSPATH')) exit;

class WEO_Admin {
  public function __construct() {
    add_action('admin_menu', [$this, 'menu']);
    add_action('weo_dispute_opened', [$this, 'notify_dispute']);
    add_action('admin_notices', [$this, 'maybe_notice']);
  }

  public function menu() {
    add_submenu_page(
      'woocommerce',
      'Escrows',
      'Escrows',
      'manage_woocommerce',
      'weo-escrows',
      [$this, 'page']
    );

    add_submenu_page(
      'woocommerce',
      'Disputes',
      'Disputes',
      'manage_woocommerce',
      'weo-disputes',
      [$this, 'disputes_page']
    );
  }

  public function page() {
    if (!current_user_can('manage_woocommerce')) wp_die('Nicht erlaubt.');

    if (!empty($_POST['weo_action']) && !empty($_POST['order_id'])) {
      $order_id = intval($_POST['order_id']);
      if (wp_verify_nonce($_POST['weo_nonce'] ?? '', 'weo_psbt_' . $order_id)) {
        $order = wc_get_order($order_id);
        if ($order) {
          $this->handle_action($order);
        } else {
          echo '<div class="notice notice-error"><p>Bestellung nicht gefunden.</p></div>';
        }
      } else {
        echo '<div class="notice notice-error"><p>Ungültiger Sicherheits-Token.</p></div>';
      }
    }

    echo '<div class="wrap"><h1>Offene Escrows</h1>';

    $statuses = array_keys(wc_get_order_statuses());
    $exclude  = ['wc-completed','wc-refunded','wc-dispute'];
    $statuses = array_values(array_diff($statuses, $exclude));
    $statuses = array_map(function($s){ return substr($s,3); }, $statuses);

    $orders = wc_get_orders([
      'limit' => -1,
      'status' => $statuses,
      'meta_key' => '_weo_escrow_addr',
      'meta_compare' => 'EXISTS'
    ]);

    echo '<table class="widefat fixed"><thead><tr>';
    echo '<th>Bestellung</th><th>Adresse</th><th>Funding</th><th>Signaturen</th><th>Aktionen</th>';
    echo '</tr></thead><tbody>';

    foreach ($orders as $order) {
      $addr = $order->get_meta('_weo_escrow_addr');
      if (!$addr) continue;
      $oid  = weo_sanitize_order_id((string)$order->get_order_number());
      $status = weo_api_get('/orders/'.rawurlencode($oid).'/status');
      $fund = is_wp_error($status) ? '-' : intval($status['funding']['total_sat'] ?? 0);
      $conf = is_wp_error($status) ? 0 : intval($status['funding']['confirmed_sat'] ?? 0);
      $signs = intval($order->get_meta('_weo_psbt_sign_count'));
      $nonce = wp_create_nonce('weo_psbt_'.$order->get_id());
      $admin_post = esc_url(admin_url('admin-post.php'));

      echo '<tr>';
      echo '<td><a href="'.esc_url(get_edit_post_link($order->get_id())).'">#'.esc_html($order->get_order_number()).'</a></td>';
      echo '<td><code>'.esc_html($addr).'</code></td>';
      echo '<td>'.esc_html($conf.'/'.$fund).'</td>';
      echo '<td>'.esc_html($signs).'/2</td>';
      echo '<td>';
      echo '<form method="post" style="display:inline;margin-right:4px;">';
      echo '<input type="hidden" name="order_id" value="'.intval($order->get_id()).'">';
      echo '<input type="hidden" name="weo_nonce" value="'.$nonce.'">';
      echo '<input type="hidden" name="weo_action" value="build_psbt_payout">';
      echo '<button class="button">Payout</button>';
      echo '</form>';
      echo '<form method="post" style="display:inline;margin-right:4px;">';
      echo '<input type="hidden" name="order_id" value="'.intval($order->get_id()).'">';
      echo '<input type="hidden" name="weo_nonce" value="'.$nonce.'">';
      echo '<input type="hidden" name="weo_action" value="build_psbt_refund">';
      echo '<button class="button">Refund</button>';
      echo '</form>';
      echo '<form method="post" style="display:inline;margin-right:4px;">';
      echo '<input type="hidden" name="order_id" value="'.intval($order->get_id()).'">';
      echo '<input type="hidden" name="weo_nonce" value="'.$nonce.'">';
      echo '<input type="hidden" name="weo_action" value="bumpfee">';
      echo '<input type="number" name="target_conf" value="1" min="1" style="width:60px;" />';
      echo '<button class="button">RBF</button>';
      echo '</form>';
      echo '<form method="post" action="'.$admin_post.'" style="display:inline;margin-right:4px;">';
      echo '<input type="hidden" name="action" value="weo_open_dispute">';
      echo '<input type="hidden" name="order_id" value="'.intval($order->get_id()).'">';
      echo '<button class="button">Dispute</button>';
      echo '</form>';
      echo '</td>';
      echo '</tr>';
    }

    echo '</tbody></table></div>';
  }

  public function disputes_page() {
    if (!current_user_can('manage_woocommerce')) wp_die('Nicht erlaubt.');

    if (!empty($_POST['weo_action']) && !empty($_POST['order_id'])) {
      $order_id = intval($_POST['order_id']);
      if (wp_verify_nonce($_POST['weo_nonce'] ?? '', 'weo_psbt_' . $order_id)) {
        $order = wc_get_order($order_id);
        if ($order) {
          $this->handle_action($order);
        } else {
          echo '<div class="notice notice-error"><p>Bestellung nicht gefunden.</p></div>';
        }
      } else {
        echo '<div class="notice notice-error"><p>Ungültiger Sicherheits-Token.</p></div>';
      }
    }

    echo '<div class="wrap"><h1>Disputes</h1>';

    $orders = wc_get_orders([
      'limit' => -1,
      'meta_key' => '_weo_escrow_addr',
      'meta_compare' => 'EXISTS'
    ]);

    echo '<table class="widefat fixed"><thead><tr>';
    echo '<th>Bestellung</th><th>Käufer</th><th>Verkäufer</th><th>Funding</th><th>Aktionen</th>';
    echo '</tr></thead><tbody>';

    foreach ($orders as $order) {
      $oid  = weo_sanitize_order_id((string)$order->get_order_number());
      $meta_dispute = $order->get_meta('_weo_dispute');
      $status = weo_api_get('/orders/'.rawurlencode($oid).'/status');
      $state  = is_wp_error($status) ? '' : ($status['state'] ?? '');
      if (!$meta_dispute && $state !== 'dispute') continue;

      $fund = is_wp_error($status) ? '-' : intval($status['funding']['total_sat'] ?? 0);
      $conf = is_wp_error($status) ? 0 : intval($status['funding']['confirmed_sat'] ?? 0);
      $nonce = wp_create_nonce('weo_psbt_'.$order->get_id());

      $buyer_id  = intval($order->get_user_id());
      $vendor_id = intval($order->get_meta('_weo_vendor_id'));

      echo '<tr>';
      echo '<td><a href="'.esc_url(get_edit_post_link($order->get_id())).'">#'.esc_html($order->get_order_number()).'</a></td>';
      echo '<td>'.esc_html($buyer_id).'</td>';
      echo '<td>'.esc_html($vendor_id).'</td>';
      echo '<td>'.esc_html($conf.'/'.$fund).'</td>';
      echo '<td>';
      echo '<form method="post" style="display:inline;margin-right:4px;">';
      echo '<input type="hidden" name="order_id" value="'.intval($order->get_id()).'">';
      echo '<input type="hidden" name="weo_nonce" value="'.$nonce.'">';
      echo '<input type="hidden" name="weo_action" value="build_psbt_payout">';
      echo '<button class="button">Payout</button>';
      echo '</form>';
      echo '<form method="post" style="display:inline;margin-right:4px;">';
      echo '<input type="hidden" name="order_id" value="'.intval($order->get_id()).'">';
      echo '<input type="hidden" name="weo_nonce" value="'.$nonce.'">';
      echo '<input type="hidden" name="weo_action" value="build_psbt_refund">';
      echo '<button class="button">Refund</button>';
      echo '</form>';
      echo '<form method="post" style="display:inline;margin-right:4px;">';
      echo '<input type="hidden" name="order_id" value="'.intval($order->get_id()).'">';
      echo '<input type="hidden" name="weo_nonce" value="'.$nonce.'">';
      echo '<input type="hidden" name="weo_action" value="close_dispute">';
      echo '<button class="button">Close</button>';
      echo '</form>';
      echo '</td>';
      echo '</tr>';
    }

    echo '</tbody></table></div>';
  }

  private function handle_action($order) {
    $order_id = $order->get_id();
    $action = $_POST['weo_action'];
    if ($action === 'bumpfee') {
      $target = intval($_POST['target_conf'] ?? 1);
      $resp = weo_api_post('/tx/bumpfee', [
        'order_id'    => (string)$order->get_order_number(),
        'target_conf' => $target
      ]);
      if (!is_wp_error($resp) && !empty($resp['txid'])) {
        $order->update_meta_data('_weo_payout_txid', $resp['txid']);
        $order->save();
        echo '<div class="notice notice-success"><p>Gebühr erhöht. Neue TXID: '.esc_html($resp['txid']).'</p></div>';
      } else {
        echo '<div class="notice notice-error"><p>Fee-Bump fehlgeschlagen.</p></div>';
      }
      return;
    }

    if ($action === 'close_dispute') {
      $order->delete_meta_data('_weo_dispute');
      $order->update_status('on-hold', 'Dispute geschlossen');
      $order->save();
      echo '<div class="notice notice-success"><p>Dispute geschlossen.</p></div>';
      return;
    }

    $oid = weo_sanitize_order_id((string)$order->get_order_number());

    if (in_array($action, ['build_psbt_payout','build_psbt_refund'], true)) {
      $state = '';
      if ($order->get_meta('_weo_dispute')) {
        $state = 'dispute';
      } else {
        $status = weo_api_get('/orders/'.rawurlencode($oid).'/status');
        $state  = is_wp_error($status) ? '' : ($status['state'] ?? '');
      }
      if ($state !== 'dispute') {
        echo '<div class="notice notice-error"><p>Bestellung nicht im Dispute.</p></div>';
        return;
      }
    }

    if ($action === 'build_psbt_payout') {
      $payoutAddr = get_user_meta($order->get_meta('_weo_vendor_id'), 'weo_vendor_payout_address', true);
      if (!$payoutAddr) $payoutAddr = $this->fallback_vendor_payout_address($order_id);
      if (!weo_validate_btc_address($payoutAddr)) {
        echo '<div class="notice notice-error"><p>Payout-Adresse ungültig.</p></div>';
        return;
      }
      $status = weo_api_get('/orders/'.rawurlencode($oid).'/status');
      $funded = is_wp_error($status) ? 0 : intval($status['funding']['total_sat'] ?? 0);
      if ($funded <= 0) {
        echo '<div class="notice notice-error"><p>Keine Escrow-Einzahlung gefunden.</p></div>';
        return;
      }
      $quote = weo_api_post('/orders/'.rawurlencode($oid).'/payout_quote', [
        'address'     => $payoutAddr,
        'target_conf' => 3,
      ]);
      if (is_wp_error($quote) || empty($quote['payout_sat'])) {
        echo '<div class="notice notice-error"><p>Fee-Kalkulation fehlgeschlagen.</p></div>';
        return;
      }
      $amount_sats = intval($quote['payout_sat']);
      if ($amount_sats <= 0 || !weo_validate_amount($amount_sats)) {
        echo '<div class="notice notice-error"><p>Betrag ungültig.</p></div>';
        return;
      }
      $resp = weo_api_post('/psbt/build', [
        'order_id'    => $oid,
        'outputs'     => [ $payoutAddr => $amount_sats ],
        'rbf'         => true,
        'target_conf' => 3,
      ]);
    } elseif ($action === 'build_psbt_refund') {
      $refundAddr = get_user_meta($order->get_user_id(), 'weo_buyer_payout_address', true);
      if (!$refundAddr) {
        echo '<div class="notice notice-error"><p>Keine Käuferadresse hinterlegt.</p></div>';
        return;
      }
      if (!weo_validate_btc_address($refundAddr)) {
        echo '<div class="notice notice-error"><p>Adresse ungültig.</p></div>';
        return;
      }
      $resp = weo_api_post('/psbt/build_refund', [
        'order_id'    => $oid,
        'address'     => $refundAddr,
        'target_conf' => 3
      ]);
    } else {
      $resp = null;
    }

    if (!empty($resp) && !is_wp_error($resp) && !empty($resp['psbt'])) {
      if ($action === 'build_psbt_payout') {
        $order->update_meta_data('_weo_dispute_outcome', 'payout');
      } elseif ($action === 'build_psbt_refund') {
        $order->update_meta_data('_weo_dispute_outcome', 'refund');
      }
      $order->save();
      $psbt_b64 = esc_textarea($resp['psbt']);
      echo '<div class="notice notice-info"><p><strong>PSBT (Base64):</strong></p><textarea rows="4" style="width:100%;">'.$psbt_b64.'</textarea></div>';
    } elseif ($resp !== null) {
      echo '<div class="notice notice-error"><p>PSBT konnte nicht erstellt werden.</p></div>';
    }
  }

  public function notify_dispute($order) {
    if (!$order instanceof WC_Order) $order = wc_get_order($order);
    if (!$order) return;
    $admin_email = get_option('admin_email');
    $subject = 'Escrow Dispute für Bestellung #'.$order->get_order_number();
    $link = admin_url('admin.php?page=weo-disputes');
    $message = 'Für Bestellung #'.$order->get_order_number().' wurde ein Dispute eröffnet. '.$link;
    if ($admin_email) wp_mail($admin_email, $subject, $message);
    set_transient('weo_dispute_notice', $order->get_order_number(), 60);
  }

  public function maybe_notice() {
    $ord = get_transient('weo_dispute_notice');
    if ($ord) {
      echo '<div class="notice notice-warning"><p>Dispute eröffnet für Bestellung #'.esc_html($ord).'</p></div>';
      delete_transient('weo_dispute_notice');
    }
  }

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
