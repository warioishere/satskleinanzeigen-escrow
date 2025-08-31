<?php
use PHPUnit\Framework\TestCase;
require_once __DIR__.'/bootstrap.php';

class GatewayFlowTest extends TestCase {
    protected function setUp(): void {
        global $api_calls, $api_failures, $api_returns, $api_get_returns, $api_get_calls, $test_order, $decode_signs, $notices, $scheduled;
        $api_calls = [];
        $api_failures = [];
        $api_returns = [];
        $api_get_returns = [];
        $api_get_calls = [];
        $notices = [];
        $scheduled = [];
        $test_order = new FakeOrder();
        $decode_signs = 1;
    }

    public function test_checkout_creates_escrow() {
        global $api_calls, $test_order;
        WEO_Order::maybe_create_escrow(1, 'pending', 'on-hold', $test_order);
        $this->assertSame('/orders', $api_calls[0]['path']);
        $this->assertSame(100000000, $api_calls[0]['body']['amount_sat']);
        $this->assertSame('addr', $test_order->get_meta('_weo_escrow_addr'));
    }

    public function test_handle_upload_workflow() {
        global $api_calls, $test_order, $decode_signs;
        $decode_signs = 2;
        $_POST = ['order_id'=>1, 'weo_signed_psbt'=>'part1', 'action'=>'weo_upload_psbt_buyer', '_wpnonce'=>'nonce', 'weo_release_funds'=>1];
        try { (new WEO_Order())->handle_upload(); } catch (Exception $e) {}
        $paths = array_column($api_calls, 'path');
        $this->assertSame(['/psbt/merge','/psbt/decode','/psbt/finalize','/tx/broadcast'], $paths);
        $this->assertSame('txid123', $test_order->get_meta('_weo_payout_txid'));
        $this->assertSame('completed', $test_order->status);
    }

    public function test_handle_upload_with_dispute_skips_broadcast() {
        global $api_calls, $test_order;
        $test_order->update_meta_data('_weo_dispute', '2024-01-01 00:00:00');
        $_POST = ['order_id'=>1, 'weo_signed_psbt'=>'part1', 'action'=>'weo_upload_psbt_buyer', '_wpnonce'=>'nonce'];
        try { (new WEO_Order())->handle_upload(); } catch (Exception $e) {}
        $paths = array_column($api_calls, 'path');
        $this->assertSame(['/psbt/merge','/psbt/decode'], $paths);
        $this->assertArrayNotHasKey('_weo_payout_txid', $test_order->meta);
    }

    public function test_open_dispute_calls_api() {
        global $api_calls, $test_order;
        $_POST = ['order_id'=>1, 'weo_dispute_note'=>'problem', '_wpnonce'=>'nonce'];
        try { (new WEO_Order())->open_dispute(); } catch (Exception $e) {}
        $this->assertSame('/psbt/finalize', $api_calls[0]['path']);
        $this->assertSame('on-hold', $test_order->status);
        $this->assertArrayHasKey('_weo_dispute', $test_order->meta);
    }

    public function test_handle_upload_refund_sets_refunded_status() {
        global $api_calls, $test_order, $decode_signs;
        $decode_signs = 2;
        $test_order->update_meta_data('_weo_dispute_outcome', 'refund');
        $_POST = ['order_id'=>1,'weo_signed_psbt'=>'part1','action'=>'weo_upload_psbt_buyer','_wpnonce'=>'nonce','weo_release_funds'=>1];
        try { (new WEO_Order())->handle_upload(); } catch (Exception $e) {}
        $this->assertSame('refunded', $test_order->status);
        $this->assertArrayNotHasKey('_weo_dispute_outcome', $test_order->meta);
    }

    public function test_render_order_panel_shows_hint_and_txid() {
        global $api_get_returns, $test_order;
        $test_order->update_meta_data('_weo_escrow_addr','addr');
        $test_order->update_meta_data('_weo_watch_id','watch');
        $test_order->update_meta_data('_weo_payout_txid','txid123');
        $api_get_returns['/orders/order1/status'] = ['state'=>'completed'];
        ob_start();
        (new WEO_Order())->render_order_panel(1);
        $html = ob_get_clean();
        $this->assertStringContainsString('PSBT kann jederzeit im Dokan-Dashboard', $html);
        $this->assertStringContainsString('txid123', $html);
    }

    public function test_handle_upload_finalize_error_shows_notice() {
        global $api_calls, $api_failures, $test_order, $decode_signs, $notices;
        $decode_signs = 2;
        $api_failures['/psbt/finalize'] = new WP_Error('fail','nope');
        $_POST = ['order_id'=>1,'weo_signed_psbt'=>'part1','action'=>'weo_upload_psbt_buyer','_wpnonce'=>'nonce','weo_release_funds'=>1];
        try { (new WEO_Order())->handle_upload(); } catch (Exception $e) {}
        $paths = array_column($api_calls,'path');
        $this->assertNotContains('/tx/broadcast', $paths);
        $this->assertArrayNotHasKey('_weo_payout_txid', $test_order->meta);
        $this->assertSame('error', $notices[0][0]);
    }

    public function test_open_dispute_api_failure_schedules_retry() {
        global $api_failures, $test_order, $scheduled;
        $api_failures['/psbt/finalize'] = new WP_Error('fail','down');
        $_POST = ['order_id'=>1,'weo_dispute_note'=>'problem','_wpnonce'=>'nonce'];
        try { (new WEO_Order())->open_dispute(); } catch (Exception $e) {}
        $this->assertArrayHasKey('_weo_dispute', $test_order->meta);
        $this->assertSame('weo_retry_finalize_dispute', $scheduled[0]['hook']);
    }
}
