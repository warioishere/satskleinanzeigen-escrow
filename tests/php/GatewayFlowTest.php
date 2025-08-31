<?php
use PHPUnit\Framework\TestCase;
require_once __DIR__.'/bootstrap.php';

class GatewayFlowTest extends TestCase {
    protected function setUp(): void {
        global $api_calls, $test_order;
        $api_calls = [];
        $test_order = new FakeOrder();
    }

    public function test_checkout_creates_escrow() {
        global $api_calls, $test_order;
        WEO_Order::maybe_create_escrow(1, 'pending', 'on-hold', $test_order);
        $this->assertSame('/orders', $api_calls[0]['path']);
        $this->assertSame(100000000, $api_calls[0]['body']['amount_sat']);
        $this->assertSame('addr', $test_order->get_meta('_weo_escrow_addr'));
    }

    public function test_handle_upload_workflow() {
        global $api_calls, $test_order;
        $_POST = ['order_id'=>1, 'weo_signed_psbt'=>'part1', 'action'=>'weo_upload_psbt_buyer', '_wpnonce'=>'nonce'];
        try { (new WEO_Order())->handle_upload(); } catch (Exception $e) {}
        $paths = array_column($api_calls, 'path');
        $this->assertSame(['/psbt/merge','/psbt/decode','/psbt/finalize','/tx/broadcast'], $paths);
        $this->assertSame('txid123', $test_order->get_meta('_weo_payout_txid'));
        $this->assertSame('completed', $test_order->status);
    }

    public function test_open_dispute_calls_api() {
        global $api_calls, $test_order;
        $_POST = ['order_id'=>1, 'weo_dispute_note'=>'problem', '_wpnonce'=>'nonce'];
        try { (new WEO_Order())->open_dispute(); } catch (Exception $e) {}
        $this->assertSame('/psbt/finalize', $api_calls[0]['path']);
        $this->assertSame('on-hold', $test_order->status);
        $this->assertArrayHasKey('_weo_dispute', $test_order->meta);
    }
}
