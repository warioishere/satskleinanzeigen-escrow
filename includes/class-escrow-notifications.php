<?php
if (!defined('ABSPATH')) exit;

class WEO_Notifications {
  public static function init() {
    add_action('weo_order_shipped', [__CLASS__, 'notify_shipped']);
    add_action('weo_order_received', [__CLASS__, 'notify_received']);
    add_action('weo_psbt_uploaded', [__CLASS__, 'notify_psbt_uploaded'], 10, 2);
  }

  protected static function send_mail($to, $subject, $message) {
    if ($to) wc_mail($to, $subject, $message);
  }

  public static function notify_shipped($order_id) {
    $order = wc_get_order($order_id); if (!$order) return;
    $to = $order->get_billing_email();
    $subject = __('Bestellung versendet', 'weo');
    $message = sprintf(__('Der Verkäufer hat Bestellung %s als versendet markiert. Bitte bestätige den Empfang im Dokan-Dashboard.', 'weo'), $order->get_order_number());
    self::send_mail($to, $subject, $message);
    $order->add_order_note(__('Verkäufer hat Versand markiert; Käufer benachrichtigt.', 'weo'), true);
  }

  public static function notify_received($order_id) {
    $order = wc_get_order($order_id); if (!$order) return;
    $vendor_id = $order->get_meta('_weo_vendor_id');
    $vendor = get_userdata($vendor_id); if (!$vendor) return;
    $to = $vendor->user_email;
    $subject = __('Empfang bestätigt', 'weo');
    $message = sprintf(__('Der Käufer hat Bestellung %s als erhalten markiert. Bitte signiere die PSBT im Dokan-Dashboard.', 'weo'), $order->get_order_number());
    self::send_mail($to, $subject, $message);
    $order->add_order_note(__('Käufer hat Empfang bestätigt; Verkäufer benachrichtigt.', 'weo'), true);
  }

  public static function notify_psbt_uploaded($order_id, $uploader_id) {
    $order = wc_get_order($order_id); if (!$order) return;
    $buyer_id  = $order->get_user_id();
    $vendor_id = $order->get_meta('_weo_vendor_id');
    if ($uploader_id == $buyer_id) {
      $user = get_userdata($vendor_id); if (!$user) return;
      $to = $user->user_email;
      $subject = __('Käufer hat eine PSBT hochgeladen', 'weo');
      $message = sprintf(__('Der Käufer hat eine signierte PSBT für Bestellung %s hochgeladen. Bitte prüfe und signiere im Dokan-Dashboard.', 'weo'), $order->get_order_number());
      $note = __('Käufer hat eine PSBT hochgeladen; Verkäufer benachrichtigt.', 'weo');
    } else {
      $to = $order->get_billing_email();
      $subject = __('Verkäufer hat eine PSBT hochgeladen', 'weo');
      $message = sprintf(__('Der Verkäufer hat eine signierte PSBT für Bestellung %s hochgeladen. Bitte prüfe und signiere im Dokan-Dashboard.', 'weo'), $order->get_order_number());
      $note = __('Verkäufer hat eine PSBT hochgeladen; Käufer benachrichtigt.', 'weo');
    }
    self::send_mail($to, $subject, $message);
    $order->add_order_note($note, true);
  }
}
