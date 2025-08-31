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
  // Basic allowlist: Base58 or xpub/ypub/zpub/vpub etc., also xpub-like descriptors.
  if (!preg_match('#^[xyzv]pub[a-km-zA-HJ-NP-Z1-9]+$#', $x) && !preg_match('#^(tpub|upub|vpub|Zpub|Vpub|Ypub)[a-km-zA-HJ-NP-Z1-9]+$#', $x)) {
    // allow slip132 variants; keep loose for MVP
    return $x; // we keep it permissive, validate server-side
  }
  return $x;
}
