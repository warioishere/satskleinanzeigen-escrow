<?php
if (!defined('ABSPATH')) exit;

class WEO_Psbt {
  /** Build payout PSBT (vendor receives funds) */
  public static function build_payout_psbt($order_id) {
    $order = wc_get_order($order_id);
    if (!$order) return new WP_Error('weo_psbt', __('Bestellung nicht gefunden','weo'));
    $oid = weo_sanitize_order_id((string)$order->get_order_number());
    if (!$oid) return new WP_Error('weo_psbt', __('Order-ID ungültig','weo'));

    $vendor_id = $order->get_meta('_weo_vendor_id');
    if (!$vendor_id) {
      foreach ($order->get_items('line_item') as $item) {
        $pid = $item->get_product_id();
        $vendor_id = get_post_field('post_author',$pid);
        if ($vendor_id) break;
      }
      if ($vendor_id) {
        $order->update_meta_data('_weo_vendor_id',$vendor_id);
        $order->save();
      }
    }

    $payoutAddr = $vendor_id ? weo_get_payout_address($vendor_id) : '';
    if (!$payoutAddr) {
      $payoutAddr = get_option('weo_vendor_payout_fallback','');
    }
    if (!$payoutAddr) {
      return new WP_Error('weo_psbt', __('Keine Fallback-Payout-Adresse konfiguriert.','weo'));
    }
    if (!weo_validate_btc_address($payoutAddr)) {
      return new WP_Error('weo_psbt', __('Payout-Adresse ungültig.','weo'));
    }

    $status = weo_api_get('/orders/'.rawurlencode($oid).'/status');
    $funded = is_wp_error($status) ? 0 : intval($status['funding']['total_sat'] ?? 0);
    if ($funded <= 0) {
      return new WP_Error('weo_psbt', __('Keine Escrow-Einzahlung gefunden.','weo'));
    }

    $quote = weo_api_post('/orders/'.rawurlencode($oid).'/payout_quote', [
      'address'     => $payoutAddr,
      'target_conf' => 3,
    ]);
    if (is_wp_error($quote) || !isset($quote['fee_sat'])) {
      return new WP_Error('weo_psbt', __('Fee-Kalkulation fehlgeschlagen.','weo'));
    }
    $price_sat = intval( round( floatval($order->get_total()) * 100000000 ) );
    $amount_sats = $price_sat + intval($quote['fee_sat']);
    if ($amount_sats <= 0 || !weo_validate_amount($amount_sats)) {
      return new WP_Error('weo_psbt', __('Betrag ungültig.','weo'));
    }

    $resp = weo_api_post('/psbt/build', [
      'order_id'    => $oid,
      'outputs'     => [ $payoutAddr => $amount_sats ],
      'rbf'         => true,
      'target_conf' => 3,
    ]);
    return self::prepare_response($resp);
  }

  /** Build refund PSBT (buyer gets funds back) */
  public static function build_refund_psbt($order_id) {
    $order = wc_get_order($order_id);
    if (!$order) return new WP_Error('weo_psbt', __('Bestellung nicht gefunden','weo'));
    $oid = weo_sanitize_order_id((string)$order->get_order_number());
    if (!$oid) return new WP_Error('weo_psbt', __('Order-ID ungültig','weo'));

    $refundAddr = weo_get_payout_address($order->get_user_id());
    if (!$refundAddr) return new WP_Error('weo_psbt', __('Keine Käuferadresse hinterlegt.','weo'));
    if (!weo_validate_btc_address($refundAddr)) return new WP_Error('weo_psbt', __('Adresse ungültig.','weo'));

    $resp = weo_api_post('/psbt/build_refund', [
      'order_id'    => $oid,
      'address'     => $refundAddr,
      'target_conf' => 3,
    ]);
    return self::prepare_response($resp);
  }

  private static function prepare_response($resp) {
    if (is_wp_error($resp) || empty($resp['psbt'])) {
      return new WP_Error('weo_psbt', __('PSBT konnte nicht erstellt werden.','weo'));
    }
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
    return [ 'psbt' => $psbt_b64, 'details' => $details ];
  }
}
