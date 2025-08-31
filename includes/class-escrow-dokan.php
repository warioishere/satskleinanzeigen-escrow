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
    return $urls;
  }

  public function page($query_vars) {
    if (!isset($query_vars['weo-treuhand'])) return;
    if (!current_user_can('vendor') && !current_user_can('seller')) {
      dokan_add_notice(__('Keine Berechtigung','weo'),'error');
      return;
    }
    $user_id = get_current_user_id();
    if ('POST' === $_SERVER['REQUEST_METHOD'] && isset($_POST['weo_vendor_xpub'])) {
      check_admin_referer('weo_dokan_xpub');
      $xpub   = weo_sanitize_xpub(wp_unslash($_POST['weo_vendor_xpub']));
      $payout = isset($_POST['weo_vendor_payout_address']) ? weo_sanitize_btc_address(wp_unslash($_POST['weo_vendor_payout_address'])) : '';
      update_user_meta($user_id,'weo_vendor_xpub',$xpub);
      if ($payout) update_user_meta($user_id,'weo_vendor_payout_address',$payout);
      dokan_add_notice(__('Escrow-Daten gespeichert','weo'),'success');
    }
    $xpub   = get_user_meta($user_id,'weo_vendor_xpub',true);
    $payout = get_user_meta($user_id,'weo_vendor_payout_address',true);
    $file = WEO_DIR.'templates/dokan-treuhand.php';
    if (file_exists($file)) include $file;
  }
}
