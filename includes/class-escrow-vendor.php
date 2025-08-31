<?php
if (!defined('ABSPATH')) exit;

class WEO_Vendor {
  public function __construct() {
    // Fallback: einfach im User-Profil
    add_action('show_user_profile', [$this,'field']);
    add_action('edit_user_profile', [$this,'field']);
    add_action('personal_options_update', [$this,'save']);
    add_action('edit_user_profile_update', [$this,'save']);
  }

  public function field($user) {
    if (!user_can($user,'vendor') && !user_can($user,'seller')) return;
    $xpub = get_user_meta($user->ID,'weo_vendor_xpub',true);
    ?>
    <h3>Escrow xpub (Verkäufer)</h3>
    <table class="form-table"><tr>
      <th><label for="weo_vendor_xpub">Vendor xpub</label></th>
      <td><input type="text" class="regular-text code" name="weo_vendor_xpub" id="weo_vendor_xpub" value="<?php echo esc_attr($xpub); ?>"></td>
    </tr></table>
    <?php
  }

  public function save($user_id) {
    if (!current_user_can('edit_user',$user_id)) return;
    if (isset($_POST['weo_vendor_xpub'])) {
      update_user_meta($user_id,'weo_vendor_xpub', weo_sanitize_xpub(wp_unslash($_POST['weo_vendor_xpub'])));
    }
  }

  public static function get_vendor_xpub_by_order($order_id) {
    $order = wc_get_order($order_id);
    if (!$order) return '';
    $vendor_id = $order->get_meta('_weo_vendor_id');
    if (!$vendor_id) {
      // Fallback: first product author
      foreach ($order->get_items('line_item') as $item) {
        $pid = $item->get_product_id();
        $vendor_id = get_post_field('post_author',$pid);
        if ($vendor_id) break;
      }
      if ($vendor_id) $order->update_meta_data('_weo_vendor_id',$vendor_id); $order->save();
    }
    return $vendor_id ? get_user_meta($vendor_id,'weo_vendor_xpub',true) : '';
  }
}
