<?php
define('ABSPATH','/');
require_once __DIR__ . '/../../includes/class-escrow-order.php';

function add_action($hook,$cb,$prio=10){}
function do_action($hook,...$args){}
function apply_filters($hook,$value){return $value;}
function wc_get_order($id){
    global $test_order; return $test_order;
}
function weo_get_option($k,$default=false){
    if ($k==='escrow_xpub') return 'XESCROW';
    if ($k==='min_conf') return 2;
    return $default;
}
function weo_validate_amount($a){return $a>=0;}
function weo_sanitize_order_id($oid){return $oid;}
function weo_api_post($path,$body){
    global $api_calls; $api_calls[]=['path'=>$path,'body'=>$body];
    if($path==='/psbt/merge') return ['psbt'=>'merged'];
    if($path==='/psbt/decode') return ['sign_count'=>1];
    if($path==='/psbt/finalize') return ['hex'=>'deadbeef'];
    if($path==='/tx/broadcast') return ['txid'=>'txid123'];
    if($path==='/orders') return ['escrow_address'=>'addr','watch_id'=>'watch'];
    return [];
}
function is_wp_error($v){return false;}
function get_current_user_id(){return 5;}
function is_user_logged_in(){return true;}
function wp_unslash($v){return $v;}
function sanitize_text_field($v){return $v;}
function sanitize_textarea_field($v){return $v;}
function __($text,$domain=null){return $text;}
function wc_add_notice($m,$t){}
function wp_safe_redirect($u){throw new Exception('redirect');}
function wp_get_referer(){return '/prev';}
function wp_die($m){throw new Exception($m);}
function check_admin_referer($a=-1,$q='_wpnonce'){return true;}
function current_time($t){return time();}
function wp_next_scheduled($h,$a=[]){return false;}
function wp_schedule_single_event($ts,$h,$a=[]){ }

class WEO_Vendor{
  public static function get_vendor_xpub_by_order($order_id){ return 'XSELLER'; }
}

class FakeOrder{
  public $meta=['_weo_buyer_xpub'=>'XBUYER','_weo_vendor_id'=>6];
  public $status='';
  public $notes=[];
  public function get_user_id(){return 5;}
  public function get_meta($k){return $this->meta[$k]??null;}
  public function update_meta_data($k,$v){$this->meta[$k]=$v;}
  public function delete_meta_data($k){unset($this->meta[$k]);}
  public function save(){}
  public function get_total(){return 1;}
  public function get_order_number(){return 'order1';}
  public function update_status($s,$n){$this->status=$s; $this->notes[]=$n;}
  public function add_order_note($n){$this->notes[]=$n;}
}
