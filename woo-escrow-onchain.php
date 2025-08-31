<?php
/**
 * Plugin Name: Woo Escrow On-Chain (2-of-3 Multisig, PSBT)
 * Description: Non-custodial Escrow für WooCommerce: Käufer/Verkäufer/Escrow (2-von-3), PSBT-Flow, Anbindung an Escrow-API.
 * Version: 0.1.0
 * Author: yourdevice.ch
 */

if (!defined('ABSPATH')) exit;

define('WEO_PLUGIN_FILE', __FILE__);
define('WEO_DIR', plugin_dir_path(__FILE__));
define('WEO_URL', plugin_dir_url(__FILE__));
define('WEO_OPT', 'weo_options'); // Optionen-Array

require_once WEO_DIR.'includes/helpers.php';
require_once WEO_DIR.'includes/class-escrow-settings.php';
require_once WEO_DIR.'includes/class-escrow-vendor.php';
require_once WEO_DIR.'includes/class-escrow-checkout.php';
require_once WEO_DIR.'includes/class-escrow-order.php';
require_once WEO_DIR.'includes/class-escrow-rest.php';

add_action('plugins_loaded', function() {
  if (!class_exists('WooCommerce')) return;
  new WEO_Settings();
  new WEO_Vendor();
  new WEO_Checkout();
  new WEO_Order();
  new WEO_REST();
});
