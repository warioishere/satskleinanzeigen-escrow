<?php
if (!defined('ABSPATH')) exit;

class WEO_REST {
  public function __construct() {
    add_action('rest_api_init', [$this,'routes']);
  }

  public function routes() {
    register_rest_route('weo/v1', '/webhook', [
      'methods'  => 'POST',
      'callback' => [$this,'handle'],
      'permission_callback' => [$this,'verify'],
    ]);
  }

  public function verify($req) {
    $secret = weo_get_option('hmac_secret','');
    $ts  = intval($req->get_header('x-weo-ts'));
    $sig = $req->get_header('x-weo-sign');
    if (!$secret || !$ts || !$sig) return new WP_Error('forbidden','missing signature',['status'=>401]);
    if (abs(time()-$ts) > 300) return new WP_Error('forbidden','stale timestamp',['status'=>401]);
    $body = $req->get_body();
    $calc = hash_hmac('sha256', $ts.$body, $secret);
    if (!hash_equals($calc, $sig)) return new WP_Error('forbidden','bad signature',['status'=>401]);
    return true;
  }

  public function handle($req) {
    $data = $req->get_json_params();
    $order_id_str = $data['order_id'] ?? '';
    if (!$order_id_str) return new WP_REST_Response(['ok'=>false],400);

    $order_id = wc_get_order_id_by_order_key($order_id_str);
    $order = $order_id ? wc_get_order($order_id) : wc_get_order($order_id_str);
    if (!$order) return new WP_REST_Response(['ok'=>false],404);

    $event = $data['event'] ?? '';
    switch ($event) {
      case 'escrow_funded':
        $order->update_status('processing','Escrow funded');
        break;
      case 'settled':
        $order->update_status('completed','Escrow ausgezahlt');
        break;
      case 'refunded':
        $order->update_status('refunded','Escrow refund');
        break;
      case 'dispute_opened':
        $order->update_status('on-hold','Dispute geöffnet');
        break;
    }
    return new WP_REST_Response(['ok'=>true],200);
  }
}
