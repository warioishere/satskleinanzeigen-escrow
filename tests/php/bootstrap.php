<?php
define('ABSPATH','/');
define('WEO_PLUGIN_FILE', __FILE__);
require_once __DIR__ . '/../../includes/class-escrow-order.php';
require_once __DIR__ . '/../../includes/class-psbt.php';

// Basic hooks
function add_action($hook,$cb,$prio=10){}
function do_action($hook,...$args){ global $actions; $actions[]=['hook'=>$hook,'args'=>$args]; }
function apply_filters($hook,$value){return $value;}

// WordPress sanitizers / escaping
function esc_html__($t,$d=null){return $t;}
function esc_html($t){return $t;}
function esc_attr($t){return $t;}
function esc_js($t){return $t;}
function esc_url($t){return $t;}
function __($t,$d=null){return $t;}
function esc_textarea($t){return $t;}
function number_format_i18n($n){return $n;}

// Misc WP helpers
function admin_url($p=''){return $p;}
function plugins_url($p,$f=null){return $p;}
function wp_create_nonce($a){return 'nonce';}
function wp_nonce_field($a){echo '<input type="hidden" name="_wpnonce" value="nonce" />';}
function current_user_can($c){return true;}
function date_i18n($f,$ts){return 'DATE';}
function get_option($k){
    if ($k === 'weo_vendor_payout_fallback') return 'bc1qtestfallbackaddress000000000000000000000';
    return 'Y-m-d';
}

// Global state
function wc_get_order($id){ global $test_order; return $test_order; }
function weo_get_option($k,$default=false){ if ($k==='escrow_xpub') return 'XESCROW'; if ($k==='min_conf') return 2; return $default; }
function weo_validate_amount($a){return $a>=0;}
function weo_sanitize_order_id($oid){return $oid;}
function weo_validate_btc_address($addr){return true;}

class WP_Error{ private $msg; public function __construct($c,$m){$this->msg=$m;} public function get_error_message(){return $this->msg;} }
function is_wp_error($v){ return $v instanceof WP_Error; }

// API stubs with configurable responses
function weo_api_post($path,$body){
    global $api_calls,$decode_signs,$api_failures,$api_returns; $api_calls[]=['path'=>$path,'body'=>$body];
    if(isset($api_failures[$path])) return $api_failures[$path];
    if($path==='/psbt/merge')   return $api_returns['/psbt/merge']   ?? ['psbt'=>'merged'];
    if($path==='/psbt/decode')  return $api_returns['/psbt/decode']  ?? ['sign_count'=>$decode_signs ?? 1];
    if($path==='/psbt/finalize')return $api_returns['/psbt/finalize']?? ['hex'=>'deadbeef'];
    if($path==='/tx/broadcast') return $api_returns['/tx/broadcast'] ?? ['txid'=>'txid123'];
    if($path==='/orders')       return $api_returns['/orders']       ?? ['escrow_address'=>'addr','watch_id'=>'watch'];
    return $api_returns[$path] ?? [];
}
function weo_api_get($path){ global $api_get_calls,$api_get_returns; $api_get_calls[]=$path; return $api_get_returns[$path] ?? []; }

// Environment
function get_current_user_id(){return 5;}
function is_user_logged_in(){return true;}
function wp_unslash($v){return $v;}
function sanitize_text_field($v){return $v;}
function sanitize_textarea_field($v){return $v;}
function wc_add_notice($m,$t){ global $notices; $notices[]=[$t,$m]; }
function wp_safe_redirect($u){throw new Exception('redirect');}
function wp_get_referer(){return '/prev';}
function wp_die($m){throw new Exception($m);}
function check_admin_referer($a=-1,$q='_wpnonce'){return true;}
function current_time($t){return time();}
function wp_next_scheduled($h,$a=[]){return false;}
function wp_schedule_single_event($ts,$h,$a=[]){ global $scheduled; $scheduled[]=['ts'=>$ts,'hook'=>$h,'args'=>$a]; }
function dokan_get_navigation_url($p){ return '/'.$p; }
function wc_mail($to,$subject,$message){ global $mails; $mails[]=['to'=>$to,'subject'=>$subject,'message'=>$message]; }
function get_userdata($id){ return (object)['user_email'=>'vendor'.$id.'@example.com']; }
function get_user_meta($id,$key,$single=true){ return ''; }

// Minimal WooCommerce replacements
class WEO_Vendor{ public static function get_vendor_xpub_by_order($order_id){ return 'XSELLER'; } }

class FakeOrder{
  public $meta=['_weo_buyer_xpub'=>'XBUYER','_weo_vendor_id'=>6];
  public $status='';
  public $notes=[];
  public $total=1;
  public function get_user_id(){return 5;}
  public function get_payment_method(){return 'weo_gateway';}
  public function get_meta($k){return $this->meta[$k]??null;}
  public function update_meta_data($k,$v){$this->meta[$k]=$v;}
  public function delete_meta_data($k){unset($this->meta[$k]);}
  public function save(){}
  public function get_total(){return $this->total;}
  public function get_order_number(){return 'order1';}
  public function get_billing_email(){return 'buyer@example.com';}
  public function update_status($s,$n){$this->status=$s; $this->notes[]=$n;}
  public function add_order_note($n){$this->notes[]=$n;}
}
