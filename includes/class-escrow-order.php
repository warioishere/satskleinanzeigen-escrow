<?php
if (!defined('ABSPATH')) exit;

class WEO_Order {
  public function __construct() {
    add_action('woocommerce_thankyou',        [$this,'render_order_panel'], 20);
    add_action('woocommerce_view_order',      [$this,'render_order_panel'], 20);

    // Adresse/Watcher beim Statuswechsel anlegen (du kannst den Trigger anpassen)
    add_action('woocommerce_order_status_changed', [$this,'maybe_create_escrow'],10,4);

    // Admin-Metabox
    add_action('add_meta_boxes', [$this,'metabox']);

    // Upload signierter PSBTs
    add_action('admin_post_weo_upload_psbt_buyer',  [$this,'handle_upload']);
    add_action('admin_post_weo_upload_psbt_seller', [$this,'handle_upload']);
    add_action('admin_post_weo_open_dispute',       [$this,'open_dispute']);

    // Assets
    add_action('wp_enqueue_scripts', [$this,'enqueue_assets']);
  }

  /** Frontend-CSS/JS laden (nur auf Bestellseiten) */
  public function enqueue_assets() {
    if (is_order_received_page() || is_account_page()) {
      wp_enqueue_style('weo-css', WEO_URL.'assets/admin.css', [], '1.0');
      wp_enqueue_script('weo-qr',  WEO_URL.'assets/qr.min.js', [], '1.0', true);
    }
  }

  /** Beim Wechsel in on-hold (oder wie du willst) Escrow-Adresse erzeugen */
  public function maybe_create_escrow($order_id, $from, $to, $order) {
    if (!$order instanceof WC_Order) $order = wc_get_order($order_id);
    if (!$order) return;

    // Beispiel: wenn von "pending" nach "on-hold" → Adresse anlegen
    if (!($from === 'pending' && $to === 'on-hold')) return;

    $buyer_xpub  = $order->get_meta('_weo_buyer_xpub');
    $vendor_xpub = WEO_Vendor::get_vendor_xpub_by_order($order_id);
    $escrow_xpub = weo_get_option('escrow_xpub');

    if (!$buyer_xpub || !$vendor_xpub || !$escrow_xpub) return;

    // Betrag in sats (optional – reine Info für API; echtes UTXO-Tracking macht Core)
    // Falls du fiat Preise nutzt, ersetz die Umrechnung hier sinnvoll (oder entferne amount_sat)
    $amount_sat = 0;

    // deterministischer Index pro Order
    $index   = abs(crc32('weo-'.$order->get_order_number())) % 1000000;
    $min_conf = intval(weo_get_option('min_conf',2));

    $oid = weo_sanitize_order_id((string)$order->get_order_number());
    if (!$oid) return;
    $res = weo_api_post('/orders', [
      'order_id'   => $oid,
      'amount_sat' => $amount_sat,
      'buyer'      => ['xpub'=>$buyer_xpub],
      'seller'     => ['xpub'=>$vendor_xpub],
      'escrow'     => ['xpub'=>$escrow_xpub],
      'index'      => $index,
      'min_conf'   => $min_conf
    ]);

    if (!is_wp_error($res)) {
      if (!empty($res['escrow_address'])) $order->update_meta_data('_weo_escrow_addr', $res['escrow_address']);
      if (!empty($res['watch_id']))       $order->update_meta_data('_weo_watch_id',    $res['watch_id']);
      $order->save();
    }
  }

  /** Bestellpanel (Thankyou & "Bestellung ansehen") */
  public function render_order_panel($order_id) {
    $order = wc_get_order($order_id); if (!$order) return;

    $addr   = $order->get_meta('_weo_escrow_addr');
    $watch  = $order->get_meta('_weo_watch_id');

    echo '<section class="weo-escrow">';
    echo '<h2>Escrow</h2>';

    if (!$addr) {
      echo '<p>Escrow-Adresse wird vorbereitet …</p>';
      echo '</section>';
      return;
    }

    // Status von API holen
    $oid = weo_sanitize_order_id((string)$order->get_order_number());
    $status  = weo_api_get('/orders/'.rawurlencode($oid).'/status');
    $funding = is_wp_error($status) ? null : ($status['funding'] ?? null);
    $state   = is_wp_error($status) ? 'unknown' : ($status['state'] ?? 'unknown');

    // Stepper
    $labels = [
      'awaiting_deposit' => 'Einzahlung offen',
      'escrow_funded'    => 'Escrow gefundet',
      'signing'          => 'Signierung',
      'completed'        => 'Abgeschlossen',
      'refunded'         => 'Erstattung',
      'dispute'          => 'Disput'
    ];
    $final = in_array($state, ['completed','refunded','dispute']) ? $state : 'completed';
    $sequence = ['awaiting_deposit','escrow_funded','signing',$final];
    echo '<ol class="weo-stepper" data-state="'.esc_attr($state).'">';
    foreach ($sequence as $s) {
      $label = $labels[$s] ?? $s;
      echo '<li data-step="'.esc_attr($s).'"><span>'.esc_html($label).'</span></li>';
    }
    echo '</ol>';

    // Adresse + QR + Copy
    $addr_esc = esc_html($addr);
    $addr_js  = esc_js($addr);

    echo '<p><strong>Einzahlungsadresse:</strong> <code id="weo_addr">'.$addr_esc.'</code></p>';

    echo '<div class="weo-qr">';
    echo '  <div id="weo_qr"></div>';
    echo '  <p><button type="button" id="weo_copy" class="button">Adresse kopieren</button></p>';
    echo '</div>';

    // Funding/Status
    if ($funding) {
      $txid = esc_html($funding['txid'] ?? '');
      $confs = intval($funding['confirmations'] ?? 0);
      $val   = isset($funding['value_sat']) ? intval($funding['value_sat']).' sats' : '';
      echo "<p>Funding TX: <code>{$txid}</code> • Confs: {$confs} • Betrag: {$val}</p>";
    } else {
      echo '<p>Noch keine Einzahlung erkannt.</p>';
    }
    echo '<p>Status: <strong>'.esc_html($state).'</strong></p>';
    $signs = intval($order->get_meta('_weo_psbt_sign_count'));
    echo '<p>Signaturen: '. $signs . '/2</p>';

    $payout_txid = $order->get_meta('_weo_payout_txid');
    if ($state === 'signing' || $payout_txid) {
      $nonce = wp_create_nonce('weo_psbt_'.$order_id);
      echo '<form method="post" action="" style="margin-top:10px;">';
      echo '<input type="hidden" name="weo_action" value="bumpfee">';
      echo '<input type="hidden" name="weo_nonce" value="'.$nonce.'">';
      echo '<p><label>Neue Ziel-Confirmations</label> <input type="number" name="target_conf" value="1" min="1" style="width:60px;" /></p>';
      echo '<p><button class="button">Gebühr erhöhen (RBF)</button></p>';
      echo '</form>';
    }

    if (in_array($state, ['escrow_funded','signing'])) {
      $upload_url = esc_url(admin_url('admin-post.php'));
      echo '<form method="post" action="'.$upload_url.'" style="margin-top:10px;">';
      echo '<input type="hidden" name="action" value="weo_open_dispute">';
      echo '<input type="hidden" name="order_id" value="'.intval($order_id).'">';
      echo '<p><button class="button">Dispute eröffnen</button></p>';
      echo '</form>';
    }

    // PSBT-Flow wenn Escrow funded, Signing läuft oder Disput
    if (in_array($state, ['escrow_funded','signing','dispute'])) {
      $nonce = wp_create_nonce('weo_psbt_'.$order_id);

      if ($state !== 'dispute') {
        // PSBT (Payout) erstellen
        echo '<form method="post" action="" style="margin-top:15px;">';
        echo '<input type="hidden" name="weo_action" value="build_psbt_payout">';
        echo '<input type="hidden" name="weo_nonce" value="'.$nonce.'">';
        echo '<p><button class="button">PSBT (Auszahlung an Verkäufer) erstellen</button></p>';
        echo '</form>';
      }

      // PSBT (Refund) erstellen
      echo '<form method="post" action="" style="margin-top:10px;">';
      echo '<input type="hidden" name="weo_action" value="build_psbt_refund">';
      echo '<input type="hidden" name="weo_nonce" value="'.$nonce.'">';
      echo '<p><button class="button">PSBT (Erstattung an Käufer) erstellen</button></p>';
      echo '</form>';

      // Signierte PSBT hochladen (buyer)
      $upload_url = esc_url(admin_url('admin-post.php'));
      $cur = get_current_user_id();
      $buyer_id  = $order->get_user_id();
      $vendor_id = $order->get_meta('_weo_vendor_id');
      if (!$vendor_id) { $this->fallback_vendor_payout_address($order_id); $vendor_id = $order->get_meta('_weo_vendor_id'); }

      if ($cur && $cur == $buyer_id) {
        echo '<form method="post" action="'.$upload_url.'" style="margin-top:10px;">';
        echo '<input type="hidden" name="action" value="weo_upload_psbt_buyer">';
        echo '<input type="hidden" name="order_id" value="'.intval($order_id).'">';
        echo '<p><label>Signierte PSBT (Base64, Käufer)</label><br/>';
        echo '<textarea name="weo_signed_psbt" rows="6" style="width:100%" placeholder="PSBT…"></textarea></p>';
        echo '<p><button class="button button-primary">PSBT hochladen</button></p>';
        echo '</form>';
      }

      if ($cur && $cur == $vendor_id) {
        echo '<form method="post" action="'.$upload_url.'" style="margin-top:10px;">';
        echo '<input type="hidden" name="action" value="weo_upload_psbt_seller">';
        echo '<input type="hidden" name="order_id" value="'.intval($order_id).'">';
        echo '<p><label>Signierte PSBT (Base64, Verkäufer)</label><br/>';
        echo '<textarea name="weo_signed_psbt" rows="6" style="width:100%" placeholder="PSBT…"></textarea></p>';
        echo '<p><button class="button button-primary">PSBT hochladen</button></p>';
        echo '</form>';
      }
    }

    echo '</section>';

    // Inline-Skript für QR + Copy + optional PSBT-Build Ausgabe
    echo "<script>
    (function(){
      try {
        if (window.QRCode && document.getElementById('weo_qr')) {
          new QRCode(document.getElementById('weo_qr'), '{$addr_js}');
        }
        var btn = document.getElementById('weo_copy');
        if (btn) {
          btn.addEventListener('click', function(){
            var t = document.createElement('textarea');
            t.value = '{$addr_js}';
            document.body.appendChild(t);
            t.select(); document.execCommand('copy'); document.body.removeChild(t);
            btn.textContent = 'Kopiert!';
            setTimeout(function(){ btn.textContent = 'Adresse kopieren'; }, 1500);
          });
        }

        var stepper = document.querySelector('.weo-stepper');
        if (stepper) {
          var state = stepper.dataset.state;
          var steps = stepper.querySelectorAll('li');
          var idx = Array.prototype.findIndex.call(steps, function(li){ return li.dataset.step === state; });
          if (idx === -1) idx = 0;
          steps.forEach(function(li,i){
            if (i < idx) li.classList.add('done');
            else if (i === idx) li.classList.add('current');
          });
        }
      } catch(e) {}
    })();
    </script>";

    // Handle PSBT-Build oder Fee-Bump direkt nach dem Panel (MVP)
    if (!empty($_POST['weo_action']) && wp_verify_nonce($_POST['weo_nonce'],'weo_psbt_'.$order_id)) {
      if ($_POST['weo_action'] === 'bumpfee') {
        $target = intval($_POST['target_conf'] ?? 1);
        $resp = weo_api_post('/tx/bumpfee', [
          'order_id'    => (string)$order->get_order_number(),
          'target_conf' => $target
        ]);
        if (!is_wp_error($resp) && !empty($resp['txid'])) {
          $order->update_meta_data('_weo_payout_txid', $resp['txid']);
          $order->save();
          echo '<div class="notice weo weo-info"><p>Gebühr erhöht. Neue TXID: '.esc_html($resp['txid']).'</p></div>';
        } else {
          echo '<div class="notice weo weo-error"><p>Fee-Bump fehlgeschlagen.</p></div>';
        }
      } else {
        $oid = weo_sanitize_order_id((string)$order->get_order_number());
        if ($_POST['weo_action'] === 'build_psbt_payout') {
          // Betrag: i. d. R. voller Escrow-Input – hier Order-Total in sats
          $amount_sats = intval(round($order->get_total()*1e8));
          if (!weo_validate_amount($amount_sats)) {
            echo '<div class="notice weo weo-error"><p>Betrag ungültig.</p></div>';
            return;
          }
          $payoutAddr = get_user_meta($order->get_meta('_weo_vendor_id'), 'weo_vendor_payout_address', true);
          if (!$payoutAddr) $payoutAddr = $this->fallback_vendor_payout_address($order_id);
          if (!weo_validate_btc_address($payoutAddr)) {
            echo '<div class="notice weo weo-error"><p>Payout-Adresse ungültig.</p></div>';
            return;
          }
          $resp = weo_api_post('/psbt/build', [
            'order_id'    => $oid,
            'outputs'     => [ $payoutAddr => $amount_sats ],
            'rbf'         => true,
            'target_conf' => 3
          ]);
        } elseif ($_POST['weo_action'] === 'build_psbt_refund') {
          $refundAddr = get_user_meta($order->get_user_id(), 'weo_buyer_payout_address', true);
          if (!$refundAddr) {
            echo '<div class="notice weo weo-error"><p>Keine Käuferadresse hinterlegt.</p></div>';
            return;
          }
          if (!weo_validate_btc_address($refundAddr)) {
            echo '<div class="notice weo weo-error"><p>Adresse ungültig.</p></div>';
            return;
          }
          $resp = weo_api_post('/psbt/build_refund', [
            'order_id'    => $oid,
            'address'     => $refundAddr,
            'target_conf' => 3
          ]);
        } else {
          $resp = null;
        }

        if (!empty($resp) && !is_wp_error($resp) && !empty($resp['psbt'])) {
          $psbt_b64 = esc_textarea($resp['psbt']);
          echo '<div class="notice weo weo-info"><p><strong>PSBT (Base64):</strong></p><textarea rows="6" style="width:100%;">'.$psbt_b64.'</textarea><p>Bitte in deiner Wallet laden, signieren und unten wieder hochladen.</p></div>';
        } elseif ($resp !== null) {
          echo '<div class="notice weo weo-error"><p>PSBT konnte nicht erstellt werden.</p></div>';
        }
      }
    }
  }

  /** Upload signierter PSBT → Merge/Finalize/Broadcast via API */
  public function handle_upload() {
    if (!is_user_logged_in()) wp_die('Nicht erlaubt.');
    $order_id = intval($_POST['order_id'] ?? 0);
    $psbt     = trim(wp_unslash($_POST['weo_signed_psbt'] ?? ''));
    $action   = sanitize_text_field($_POST['action'] ?? '');
    if (!$order_id || !$psbt) wp_die('Fehlende Daten.');

    $order = wc_get_order($order_id); if (!$order) wp_die('Bestellung nicht gefunden.');

    $buyer_id  = $order->get_user_id();
    $vendor_id = $order->get_meta('_weo_vendor_id');
    if (!$vendor_id) { $this->fallback_vendor_payout_address($order_id); $vendor_id = $order->get_meta('_weo_vendor_id'); }
    $cur = get_current_user_id();

    if ($action === 'weo_upload_psbt_buyer') {
      if ($cur !== $buyer_id) wp_die('Nicht erlaubt.');
      $meta_key = '_weo_psbt_partials_buyer';
    } else {
      if ($cur !== $vendor_id) wp_die('Nicht erlaubt.');
      $meta_key = '_weo_psbt_partials_seller';
    }

    $partials = (array) $order->get_meta($meta_key);
    $partials[] = $psbt;
    $order->update_meta_data($meta_key, $partials);
    $order->save();

    $all_partials = array_merge(
      (array)$order->get_meta('_weo_psbt_partials_buyer'),
      (array)$order->get_meta('_weo_psbt_partials_seller')
    );

    // Merge
    $oid = weo_sanitize_order_id((string)$order->get_order_number());
    $merge = weo_api_post('/psbt/merge', [
      'order_id' => $oid,
      'partials' => $all_partials
    ]);
    if (is_wp_error($merge) || empty($merge['psbt'])) {
      wp_safe_redirect(wp_get_referer()); exit;
    }

    $dec = weo_api_post('/psbt/decode', [ 'psbt' => $merge['psbt'] ]);
    $signs = is_wp_error($dec) ? 0 : intval($dec['sign_count'] ?? 0);
    $order->update_meta_data('_weo_psbt_sign_count', $signs);
    $order->save();

    // Finalize
    $final = weo_api_post('/psbt/finalize', [
      'order_id' => $oid,
      'psbt'     => $merge['psbt']
    ]);

    // Wenn noch nicht genug Signaturen da sind
    if (is_wp_error($final) || empty($final['hex'])) {
      wc_add_notice('Noch nicht genügend Signaturen – zweite Unterschrift erforderlich.', 'notice');
      wp_safe_redirect(wp_get_referer()); exit;
    }

    // Broadcast
    $tx = weo_api_post('/tx/broadcast', [
      'hex'      => $final['hex'],
      'order_id' => $oid
    ]);
    if (!is_wp_error($tx) && !empty($tx['txid'])) {
      $order->update_meta_data('_weo_payout_txid', $tx['txid']);
      $order->update_status('completed', 'Escrow ausgezahlt. TXID: '.$tx['txid']);
    }

    wp_safe_redirect(wp_get_referer()); exit;
  }

  /** Dispute eröffnen → optional finalize/broadcast mit State 'dispute' */
  public function open_dispute() {
    if (!is_user_logged_in()) wp_die('Nicht erlaubt.');
    $order_id = intval($_POST['order_id'] ?? 0);
    if (!$order_id) wp_die('Fehlende Order-ID.');
    $order = wc_get_order($order_id); if (!$order) wp_die('Bestellung nicht gefunden.');

    $buyer_id  = $order->get_user_id();
    $vendor_id = $order->get_meta('_weo_vendor_id');
    if (!$vendor_id) { $this->fallback_vendor_payout_address($order_id); $vendor_id = $order->get_meta('_weo_vendor_id'); }
    $cur = get_current_user_id();
    if ($cur !== $buyer_id && $cur !== $vendor_id) wp_die('Nicht erlaubt.');

    $all_partials = array_merge(
      (array)$order->get_meta('_weo_psbt_partials_buyer'),
      (array)$order->get_meta('_weo_psbt_partials_seller')
    );
    $psbt = '';
    $oid = weo_sanitize_order_id((string)$order->get_order_number());
    if ($all_partials) {
      $merge = weo_api_post('/psbt/merge', [
        'order_id' => $oid,
        'partials' => $all_partials
      ]);
      if (!is_wp_error($merge) && !empty($merge['psbt'])) $psbt = $merge['psbt'];
    }

    if ($psbt) {
      $final = weo_api_post('/psbt/finalize', [
        'order_id' => $oid,
        'psbt'     => $psbt,
        'state'    => 'dispute'
      ]);
      if (!is_wp_error($final) && !empty($final['hex'])) {
        weo_api_post('/tx/broadcast', [
          'hex'      => $final['hex'],
          'order_id' => $oid,
          'state'    => 'dispute'
        ]);
      }
    } else {
      weo_api_post('/psbt/finalize', [
        'order_id' => $oid,
        'psbt'     => '',
        'state'    => 'dispute'
      ]);
    }

    $order->update_status('on-hold', 'Dispute eröffnet');
    wp_safe_redirect(wp_get_referer()); exit;
  }

  /** Admin-Metabox */
  public function metabox() {
    add_meta_box('weo_meta','Escrow',[$this,'meta_view'],'shop_order','side','high');
  }

  public function meta_view($post) {
    $order = wc_get_order($post->ID);
    echo '<p>Addr: <code>'.esc_html($order->get_meta('_weo_escrow_addr')).'</code></p>';
    echo '<p>Watch: <code>'.esc_html($order->get_meta('_weo_watch_id')).'</code></p>';
    $cnt = intval($order->get_meta('_weo_psbt_sign_count'));
    echo '<p>Signaturen: '. $cnt . '/2</p>';
  }

  /** Fallback – trag hier eine Vendor-Payout-Adresse ein, falls nicht separat gepflegt */
  private function fallback_vendor_payout_address($order_id) {
    $order = wc_get_order($order_id);
    if ($order) {
      $vendor_id = $order->get_meta('_weo_vendor_id');
      if (!$vendor_id) {
        foreach ($order->get_items('line_item') as $item) {
          $pid = $item->get_product_id();
          $vendor_id = get_post_field('post_author',$pid);
          if ($vendor_id) break;
        }
        if ($vendor_id) { $order->update_meta_data('_weo_vendor_id',$vendor_id); $order->save(); }
      }
      if ($vendor_id) {
        $payout = get_user_meta($vendor_id,'weo_vendor_payout_address',true);
        if ($payout) return $payout;
      }
    }
    return get_option('weo_vendor_payout_fallback','bc1qexamplefallbackaddressxxxxxxxxxxxxxxxxxx');
  }
}
