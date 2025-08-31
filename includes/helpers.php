<?php
if (!defined('ABSPATH')) exit;

function weo_get_option($key, $default = '') {
  $opts = get_option(WEO_OPT, []);
  return isset($opts[$key]) ? $opts[$key] : $default;
}

function weo_api_post($endpoint, $body = []) {
  $base = rtrim(weo_get_option('api_base'), '/');
  $key  = weo_get_option('api_key','');
  $headers = ['Content-Type'=>'application/json'];
  if ($key) $headers['x-api-key'] = $key;
  $resp = wp_remote_post("$base$endpoint", [
    'headers' => $headers,
    'timeout' => 20,
    'body'    => wp_json_encode($body),
  ]);
  if (is_wp_error($resp)) return $resp;
  $code = wp_remote_retrieve_response_code($resp);
  $json = json_decode(wp_remote_retrieve_body($resp), true);
  return ($code >=200 && $code <300) ? $json : new WP_Error('weo_api', 'API error', ['code'=>$code,'body'=>$json]);
}

function weo_api_get($endpoint) {
  $base = rtrim(weo_get_option('api_base'), '/');
  $key  = weo_get_option('api_key','');
  $headers = $key ? ['x-api-key'=>$key] : [];
  $resp = wp_remote_get("$base$endpoint", ['timeout'=>20,'headers'=>$headers]);
  if (is_wp_error($resp)) return $resp;
  $code = wp_remote_retrieve_response_code($resp);
  $json = json_decode(wp_remote_retrieve_body($resp), true);
  return ($code >=200 && $code <300) ? $json : new WP_Error('weo_api', 'API error', ['code'=>$code,'body'=>$json]);
}

function weo_sanitize_xpub($x) {
  $x = trim($x);
  return preg_replace('/[^A-Za-z0-9]/','',$x);
}

function weo_sanitize_btc_address($addr) {
  $addr = trim($addr);
  return sanitize_text_field($addr);
}

// ---- Validation helpers ----

function weo_normalize_xpub($xpub, $network = 'main') {
  $xpub = weo_sanitize_xpub($xpub);
  if (!$xpub) return new WP_Error('weo_xpub','xpub missing');

  $vers = [
    '0488b21e'=>['net'=>'main','dest'=>'0488b21e'], // xpub
    '049d7cb2'=>['net'=>'main','dest'=>'0488b21e'], // ypub
    '04b24746'=>['net'=>'main','dest'=>'0488b21e'], // zpub
    '0295b43f'=>['net'=>'main','dest'=>'0488b21e'], // Ypub
    '02aa7ed3'=>['net'=>'main','dest'=>'0488b21e'], // Zpub
    '043587cf'=>['net'=>'test','dest'=>'043587cf'], // tpub
    '044a5262'=>['net'=>'test','dest'=>'043587cf'], // upub
    '045f1cf6'=>['net'=>'test','dest'=>'043587cf'], // vpub
    '024289ef'=>['net'=>'test','dest'=>'043587cf'], // Upub
    '02575483'=>['net'=>'test','dest'=>'043587cf'], // Vpub
  ];

  $hex = weo_base58check_decode($xpub);
  if ($hex === false) return new WP_Error('weo_xpub','invalid base58');
  $prefix = substr($hex,0,8);
  $payload = substr($hex,8);
  if (!isset($vers[$prefix])) return new WP_Error('weo_xpub','unknown prefix');
  $info = $vers[$prefix];
  if ($info['net'] !== $network) return new WP_Error('weo_xpub','wrong network');
  $norm_hex = $info['dest'] . $payload;
  return weo_base58check_encode($norm_hex);
}

function weo_validate_btc_address($addr, $network = 'main') {
  $addr = weo_sanitize_btc_address($addr);
  $hrp = $network === 'main' ? 'bc1' : 'tb1';
  if (!preg_match('#^'.preg_quote($hrp,'#').'[0-9ac-hj-np-z]{8,87}$#i', $addr)) return false;
  return true;
}

function weo_validate_amount($sats, $min = 1, $max = 2100000000000000) {
  if (!is_numeric($sats)) return false;
  $sats = intval($sats);
  return ($sats >= $min && $sats <= $max);
}

function weo_sanitize_order_id($id) {
  $id = sanitize_text_field($id);
  return preg_match('/^[A-Za-z0-9_-]{1,32}$/',$id) ? $id : '';
}

// ---- Base58Check helpers ----

function weo_base58check_decode($b58) {
  $alphabet = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';
  $num = '0';
  for ($i=0; $i<strlen($b58); $i++) {
    $p = strpos($alphabet, $b58[$i]);
    if ($p === false) return false;
    $num = bcadd(bcmul($num,'58'), (string)$p);
  }
  $hex = '';
  while (bccomp($num,'0') > 0) {
    $rem = bcmod($num,'16');
    $num = bcdiv($num,'16',0);
    $hex = dechex($rem) . $hex;
  }
  if (strlen($hex)%2) $hex = '0'.$hex;
  $bin = hex2bin($hex);
  $pad = 0;
  for ($i=0; $i<strlen($b58) && $b58[$i]=='1'; $i++) $pad++;
  $bin = str_repeat("\x00", $pad) . $bin;
  $data = substr($bin,0,-4);
  $checksum = substr($bin,-4);
  $hash = substr(hash('sha256', hex2bin(hash('sha256',$data)), true),0,4);
  if ($checksum !== $hash) return false;
  return bin2hex($data);
}

function weo_base58check_encode($hex) {
  $data = hex2bin($hex);
  $checksum = substr(hash('sha256', hex2bin(hash('sha256',$data)), true),0,4);
  $bin = $data . $checksum;
  $alphabet = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';
  $num = '0';
  $bytes = unpack('C*', $bin);
  foreach ($bytes as $b) {
    $num = bcadd(bcmul($num,'256'), (string)$b);
  }
  $res = '';
  while (bccomp($num,'0') > 0) {
    $rem = bcmod($num,'58');
    $num = bcdiv($num,'58',0);
    $res = $alphabet[(int)$rem] . $res;
  }
  foreach ($bytes as $b) {
    if ($b === 0) $res = '1'.$res; else break;
  }
  return $res;
}
