<?php
if (!defined('ABSPATH')) exit;

class WEO_Settings {
  public function __construct() {
    add_action('admin_init', [$this, 'register']);
    add_action('admin_menu', [$this, 'menu']);
  }

  public function register() {
    register_setting('weo_settings', WEO_OPT, ['sanitize_callback'=>[$this,'sanitize']]);
    add_settings_section('weo_main', 'Escrow Einstellungen', '__return_false', 'weo');
    add_settings_field('api_base', 'Escrow-API Base URL', [$this,'field_api'], 'weo', 'weo_main');
    add_settings_field('escrow_xpub', 'Escrow xpub (dein Key)', [$this,'field_xpub'], 'weo', 'weo_main');
    add_settings_field('min_conf', 'Min. Bestätigungen', [$this,'field_conf'], 'weo', 'weo_main');
    add_settings_field('api_key', 'API Key', [$this,'field_api_key'], 'weo', 'weo_main');
    add_settings_field('hmac_secret', 'Webhook HMAC Secret', [$this,'field_hmac_secret'], 'weo', 'weo_main');
    add_settings_field('timeout_days', 'Signatur-Timeout (Tage)', [$this,'field_timeout'], 'weo', 'weo_main');
  }

  public function sanitize($opts) {
    $clean = [];
    $clean['api_base']   = esc_url_raw($opts['api_base'] ?? '');
    $clean['escrow_xpub']= weo_sanitize_xpub($opts['escrow_xpub'] ?? '');
    $clean['min_conf']   = max(0, intval($opts['min_conf'] ?? 1));
    $clean['api_key']    = sanitize_text_field($opts['api_key'] ?? '');
    $clean['hmac_secret']= sanitize_text_field($opts['hmac_secret'] ?? '');
    $clean['timeout_days']= max(1, intval($opts['timeout_days'] ?? 7));
    return $clean;
  }

  public function menu() {
    add_submenu_page('woocommerce', 'Escrow On-Chain', 'Escrow On-Chain', 'manage_woocommerce', 'weo', [$this,'render']);
  }

  public function field_api() {
    $v = esc_attr(weo_get_option('api_base','https://escrow.example.com'));
    echo "<input type='url' name='".WEO_OPT."[api_base]' value='$v' class='regular-text' placeholder='https://escrow.yourdevice.ch/api' />";
  }
  public function field_xpub() {
    $v = esc_attr(weo_get_option('escrow_xpub',''));
    echo "<input type='text' name='".WEO_OPT."[escrow_xpub]' value='$v' class='regular-text code' />";
  }
  public function field_conf() {
    $v = intval(weo_get_option('min_conf',2));
    echo "<input type='number' name='".WEO_OPT."[min_conf]' value='$v' min='0' max='6' />";
  }

  public function field_api_key() {
    $v = esc_attr(weo_get_option('api_key',''));
    echo "<input type='text' name='".WEO_OPT."[api_key]' value='$v' class='regular-text' />";
  }

  public function field_hmac_secret() {
    $v = esc_attr(weo_get_option('hmac_secret',''));
    echo "<input type='text' name='".WEO_OPT."[hmac_secret]' value='$v' class='regular-text' />"; 
  }

  public function field_timeout() {
    $v = intval(weo_get_option('timeout_days',7));
    echo "<input type='number' name='".WEO_OPT."[timeout_days]' value='$v' min='1' />"; 
  }

  public function render() { ?>
    <div class="wrap">
      <h1>Escrow On-Chain</h1>
      <form method="post" action="options.php">
        <?php settings_fields('weo_settings'); do_settings_sections('weo'); submit_button(); ?>
      </form>
    </div>
  <?php }
}
