<?php
if (!defined('ABSPATH')) exit;

class WEO_Order {
  public function __construct() {
    add_action('woocommerce_view_order',      [$this,'render_order_panel'], 20);

    // Admin-Metabox
    add_action('add_meta_boxes', [$this,'metabox']);

    // Upload signierter PSBTs
    add_action('admin_post_weo_upload_psbt_buyer',  [$this,'handle_upload']);
    add_action('admin_post_weo_upload_psbt_seller', [$this,'handle_upload']);
    add_action('admin_post_weo_open_dispute',       [$this,'open_dispute']);
  }

  /** Escrow-Adresse erzeugen (z.B. beim Wechsel in on-hold) */
  public static function maybe_create_escrow($order_id, $from, $to, $order) {
    if (!$order instanceof WC_Order) $order = wc_get_order($order_id);
    if (!$order) return;

    // Beispiel: wenn von "pending" nach "on-hold" → Adresse anlegen
    if (!($from === 'pending' && $to === 'on-hold')) return;

    $buyer_xpub  = $order->get_meta('_weo_buyer_xpub');
    $vendor_xpub = WEO_Vendor::get_vendor_xpub_by_order($order_id);
    $escrow_xpub = weo_get_option('escrow_xpub');

    if (!$buyer_xpub || !$vendor_xpub || !$escrow_xpub) return;

    // Betrag in sats (optional – reine Info für API; echtes UTXO-Tracking macht Core)
    // Der Shop muss in BTC rechnen – andernfalls hier eine passende Umrechnung ergänzen
    $total_btc  = floatval($order->get_total());
    $amount_sat = intval( round( $total_btc * 100000000 ) );
    if (!weo_validate_amount($amount_sat)) $amount_sat = 0;

    $min_conf = intval(weo_get_option('min_conf',2));

    $oid = weo_sanitize_order_id((string)$order->get_order_number());
    if (!$oid) return;
    $res = weo_api_post('/orders', [
      'order_id'   => $oid,
      'amount_sat' => $amount_sat,
      'buyer'      => ['xpub'=>$buyer_xpub],
      'seller'     => ['xpub'=>$vendor_xpub],
      'escrow'     => ['xpub'=>$escrow_xpub],
      'min_conf'   => $min_conf
    ]);

    if (!is_wp_error($res) && !empty($res['escrow_address']) && !empty($res['watch_id'])) {
      $order->update_meta_data('_weo_escrow_addr', $res['escrow_address']);
      $order->update_meta_data('_weo_watch_id',    $res['watch_id']);
      $order->save();
    } else {
      $order->add_order_note('Escrow-Service nicht erreichbar – erneuter Versuch in 5 Minuten.');
      if (!wp_next_scheduled('weo_retry_create_escrow', [$order_id])) {
        wp_schedule_single_event(time()+300, 'weo_retry_create_escrow', [$order_id]);
      }
    }
  }

  /** Bestellpanel (Thankyou & "Bestellung ansehen") */
  public function render_order_panel($order_id) {
    $order = wc_get_order($order_id); if (!$order) return;
    if ($order->get_payment_method() !== 'weo_gateway') return;

    $addr   = $order->get_meta('_weo_escrow_addr');
    $watch  = $order->get_meta('_weo_watch_id');

    echo '<section class="weo-escrow">';
    echo '<h2>Escrow</h2>';

    $cur = get_current_user_id();
    if ($cur && !weo_get_payout_address($cur)) {
      echo '<div class="notice weo"><p>'.esc_html__('Bitte hinterlege eine Payout-/Refund-Adresse in deinen Einstellungen.','weo').'</p></div>';
    }

    if (!$addr) {
      echo '<p>Escrow-Adresse wird vorbereitet …</p>';
      echo '</section>';
      return;
    }

    // Status von API holen
    $oid = weo_sanitize_order_id((string)$order->get_order_number());
    $status  = weo_api_get('/orders/'.rawurlencode($oid).'/status');
    if (is_wp_error($status)) {
      echo '<p class="weo-error">Escrow-Status derzeit nicht abrufbar. Bitte später erneut versuchen.</p>';
      $funding = null;
      $state = 'unknown';
      $deadline = 0;
    } else {
      $funding = $status['funding'] ?? null;
      $state   = $status['state'] ?? 'unknown';
      $deadline = intval($status['deadline_ts'] ?? 0);
    }

    $amount_sat = intval($status['amount_sat'] ?? 0);
    $fee_est_sat = intval($status['fee_est_sat'] ?? 0);
    $required = $amount_sat + $fee_est_sat;
    $funded_total = intval($funding['total_sat'] ?? 0);
    if ($required > 0) {
      echo '<p>Erforderliche Einzahlung: '.esc_html($required).' sats</p>';
      if ($funded_total < $required) {
        $missing = $required - $funded_total;
        echo '<div class="notice weo weo-warning"><p>Es fehlen '.esc_html($missing).' sats.</p></div>';
      }
    }

    // Stepper
    $labels = [
      'awaiting_deposit' => 'Einzahlung offen',
      'escrow_funded'    => 'Escrow gefundet',
      'signing'          => 'Signierung',
      'completed'        => 'Abgeschlossen',
      'refunded'         => 'Erstattung',
      'dispute'          => 'Disput'
    ];
    $state_disp = ($state === 'rbf_signing') ? 'signing' : $state;
    $final = in_array($state_disp, ['completed','refunded','dispute']) ? $state_disp : 'completed';
    $sequence = ['awaiting_deposit','escrow_funded','signing',$final];
    echo '<ol class="weo-stepper" data-state="'.esc_attr($state_disp).'">';
    foreach ($sequence as $s) {
      $label = $labels[$s] ?? $s;
      echo '<li data-step="'.esc_attr($s).'"><span>'.esc_html($label).'</span></li>';
    }
    echo '</ol>';

    echo '<ol class="weo-flow">';
    echo '<li>'.esc_html__('Betrag an die folgende Escrow-Adresse senden.', 'weo').'</li>';
    echo '<li>'.esc_html__('Verkäufer markiert Versand, Käufer bestätigt den Empfang.', 'weo').'</li>';
    echo '<li>'.esc_html__('Beide Parteien erstellen eine PSBT, signieren sie und laden sie hoch.', 'weo').'</li>';
    echo '</ol>';
    echo '<p>'.esc_html__('Die PSBT kann jederzeit im Dokan-Dashboard unter "Treuhand Overview" erneut abgerufen und signiert werden.', 'weo').'</p>';
    if (function_exists('dokan_get_navigation_url')) {
      $dash_url = esc_url(dokan_get_navigation_url('weo-treuhand-orders'));
      echo '<p><a href="'.$dash_url.'" class="button">'.esc_html__('Zum Treuhand-Dashboard', 'weo').'</a></p>';
    }
    $doc_url = esc_url(plugins_url('docs/woo-user-guide.md', WEO_PLUGIN_FILE));
    echo '<p><a href="'.$doc_url.'" target="_blank" rel="noopener">'.esc_html__('Zur ausführlichen Anleitung', 'weo').'</a></p>';

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
    if ($state === 'dispute') {
      echo '<div class="notice weo weo-warning"><p>'.esc_html__('Dispute offen – bitte warte auf die Entscheidung des Administrators.','weo').'</p></div>';
    }
    if ($deadline) {
      if ($deadline > time()) {
        $dl = intval($deadline);
        echo '<p>Frist: <span id="weo_deadline" data-deadline="'.$dl.'"></span></p>';
      } else {
        echo '<p class="weo-expired">Frist abgelaufen</p>';
      }
    }
    $signs = intval($order->get_meta('_weo_psbt_sign_count'));
    echo '<p>Signaturen: '. $signs . '/2</p>';

    $shipped  = intval($order->get_meta('_weo_shipped'));
    $received = intval($order->get_meta('_weo_received'));
    $ready_release = $shipped && $received;
    echo '<p>Versand: ' . ($shipped ? date_i18n(get_option('date_format'), $shipped) : 'noch nicht bestätigt') . '</p>';
    echo '<p>Empfang: ' . ($received ? date_i18n(get_option('date_format'), $received) : 'noch nicht bestätigt') . '</p>';

    echo '<p>'.esc_html__('Nach Versand und Empfang muss eine PSBT erstellt, von beiden Parteien signiert und hier hochgeladen werden.', 'weo').'</p>';

    $cur      = get_current_user_id();
    $buyer_id  = $order->get_user_id();
    $vendor_id = $order->get_meta('_weo_vendor_id');
    if (!$vendor_id) { $this->fallback_vendor_payout_address($order_id); $vendor_id = $order->get_meta('_weo_vendor_id'); }

    if ($cur && $cur == $vendor_id && !$shipped) {
      $n = wp_create_nonce('weo_ship_'.$order_id);
      echo '<form method="post" action="" style="margin-top:10px;">';
      echo '<input type="hidden" name="weo_action" value="mark_shipped">';
      echo '<input type="hidden" name="weo_nonce" value="'.$n.'">';
      echo '<p><button class="button">Als versendet markieren</button></p>';
      echo '</form>';
    }

    if ($cur && $cur == $buyer_id && $shipped && !$received) {
      $n = wp_create_nonce('weo_recv_'.$order_id);
      echo '<form method="post" action="" style="margin-top:10px;">';
      echo '<input type="hidden" name="weo_action" value="mark_received">';
      echo '<input type="hidden" name="weo_nonce" value="'.$n.'">';
      echo '<p><button class="button">Empfang bestätigen</button></p>';
      echo '</form>';
    }

    $payout_txid = $order->get_meta('_weo_payout_txid');
    if ($payout_txid) {
      echo '<p>'.esc_html__('Auszahlungs-TXID','weo').': <code>'.esc_html($payout_txid).'</code></p>';
    }
    if (in_array($state, ['signing','rbf_signing']) || $payout_txid) {
      $nonce = wp_create_nonce('weo_psbt_'.$order_id);
      echo '<form method="post" action="" style="margin-top:10px;">';
      echo '<input type="hidden" name="weo_action" value="bumpfee">';
      echo '<input type="hidden" name="weo_nonce" value="'.$nonce.'">';
      echo '<p><label>Neue Ziel-Confirmations</label> <input type="number" name="target_conf" value="1" min="1" style="width:60px;" /></p>';
      echo '<p><button class="button">Gebühr erhöhen (RBF)</button></p>';
      echo '</form>';
    }

    if (in_array($state, ['escrow_funded','signing']) && $signs < 2) {
      $upload_url = esc_url(admin_url('admin-post.php'));
      $confirm = esc_js(__('Disput wirklich eröffnen? Die Bestellung wird in den Disput-Status versetzt und nur der Admin entscheidet über die Auszahlung.', 'weo'));
      echo '<form method="post" action="'.$upload_url.'" style="margin-top:10px;" onsubmit="return confirm(\''.$confirm.'\');">';
      wp_nonce_field('weo_open_dispute_'.intval($order_id));
      echo '<input type="hidden" name="action" value="weo_open_dispute">';
      echo '<input type="hidden" name="order_id" value="'.intval($order_id).'">';
      echo '<p><label for="weo_dispute_note_'.intval($order_id).'">'.esc_html__('Problem beschreiben','weo').'</label><br/>';
      echo '<textarea name="weo_dispute_note" id="weo_dispute_note_'.intval($order_id).'" rows="4" style="width:100%"></textarea></p>';
      echo '<p><button class="button">'.esc_html__('Dispute eröffnen','weo').'</button></p>';
      echo '</form>';
    } elseif (in_array($state, ['escrow_funded','signing']) && $signs >= 2) {
      echo '<div class="notice weo weo-info"><p>'.esc_html__('Ein Dispute ist nach dem Hochladen und Bestätigen der signierten PSBT nicht mehr möglich.','weo').'</p></div>';
    }

    // PSBT-Flow wenn Escrow funded, Signing läuft oder Disput
    if (in_array($state, ['escrow_funded','signing','dispute','rbf_signing'])) {
      $nonce = wp_create_nonce('weo_psbt_'.$order_id);

      if ($state === 'rbf_signing') {
        $rbf_psbt = $order->get_meta('_weo_rbf_psbt');
        if ($rbf_psbt) {
          $psbt_b64 = esc_textarea($rbf_psbt);
          $details = '';
          $dec = weo_api_post('/psbt/decode', [ 'psbt' => $rbf_psbt ]);
          if (!is_wp_error($dec)) {
            $outs = $dec['outputs'] ?? [];
            if ($outs) {
              $details .= '<p><strong>'.esc_html__('Outputs','weo').':</strong></p><ul>';
              foreach ($outs as $addr => $sats) {
                $details .= '<li>'.esc_html($addr).' – '.esc_html(number_format_i18n($sats)).' sats</li>';
              }
              $details .= '</ul>';
            }
            if (isset($dec['fee_sat'])) {
              $details .= '<p><strong>'.esc_html__('Gebühr','weo').':</strong> '.esc_html(number_format_i18n(intval($dec['fee_sat']))).' sats</p>';
            }
          }
          echo '<div class="notice weo weo-info"><p><strong>RBF-PSBT (Base64):</strong></p><textarea rows="6" style="width:100%;">'.$psbt_b64.'</textarea>'.$details.'<p>'.esc_html__('Bitte in deiner Wallet laden, signieren und unten wieder hochladen.','weo').'</p></div>';
        }
      } else {
        if ($state !== 'dispute') {
          // PSBT (Payout) erstellen
          echo '<form method="post" action="" style="margin-top:15px;">';
          echo '<input type="hidden" name="weo_action" value="build_psbt_payout">';
          echo '<input type="hidden" name="weo_nonce" value="'.$nonce.'">';
          echo '<p><button class="button">PSBT (Auszahlung an Verkäufer) erstellen</button></p>';
          echo '</form>';
        }

        // PSBT (Refund) nur für Admins erstellen
        if (current_user_can('manage_options') && $state !== 'dispute') {
          echo '<form method="post" action="" style="margin-top:10px;">';
          echo '<input type="hidden" name="weo_action" value="build_psbt_refund">';
          echo '<input type="hidden" name="weo_nonce" value="'.$nonce.'">';
          echo '<p><button class="button">PSBT (Erstattung an Käufer) erstellen</button></p>';
          echo '</form>';
        }
      }

      // Signierte PSBT hochladen (buyer)
      $upload_url = esc_url(admin_url('admin-post.php'));
      $cur = get_current_user_id();
      $buyer_id  = $order->get_user_id();
      $vendor_id = $order->get_meta('_weo_vendor_id');
      if (!$vendor_id) { $this->fallback_vendor_payout_address($order_id); $vendor_id = $order->get_meta('_weo_vendor_id'); }

        if ($cur && $cur == $buyer_id) {
        echo '<form method="post" action="'.$upload_url.'" style="margin-top:10px;" class="weo-psbt-form">';
        wp_nonce_field('weo_upload_psbt_'.intval($order_id));
        echo '<input type="hidden" name="action" value="weo_upload_psbt_buyer">';
        echo '<input type="hidden" name="order_id" value="'.intval($order_id).'">';
        echo '<p><label>Signierte PSBT (Base64, Käufer)</label><br/>';
        echo '<textarea name="weo_signed_psbt" class="weo-psbt" rows="6" style="width:100%" placeholder="PSBT…"></textarea></p>';
        if ($ready_release) {
          echo '<p><label><input type="checkbox" name="weo_release_funds" value="1"> '.esc_html__('Freigabe der Escrow-Mittel bestätigen','weo').'</label></p>';
        } else {
          echo '<p>'.esc_html__('Versand/Empfang noch offen – Freigabe nicht möglich.','weo').'</p>';
        }
        echo '<p><button class="button button-primary">PSBT hochladen</button></p>';
        echo '</form>';
      }

        if ($cur && $cur == $vendor_id) {
        echo '<form method="post" action="'.$upload_url.'" style="margin-top:10px;" class="weo-psbt-form">';
        wp_nonce_field('weo_upload_psbt_'.intval($order_id));
        echo '<input type="hidden" name="action" value="weo_upload_psbt_seller">';
        echo '<input type="hidden" name="order_id" value="'.intval($order_id).'">';
        echo '<p><label>Signierte PSBT (Base64, Verkäufer)</label><br/>';
        echo '<textarea name="weo_signed_psbt" class="weo-psbt" rows="6" style="width:100%" placeholder="PSBT…"></textarea></p>';
        if ($ready_release) {
          echo '<p><label><input type="checkbox" name="weo_release_funds" value="1"> '.esc_html__('Freigabe der Escrow-Mittel bestätigen','weo').'</label></p>';
        } else {
          echo '<p>'.esc_html__('Versand/Empfang noch offen – Freigabe nicht möglich.','weo').'</p>';
        }
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
        var dl = document.getElementById('weo_deadline');
        if (dl) {
          var end = parseInt(dl.getAttribute('data-deadline'),10)*1000;
          function tick(){
            var diff = Math.floor((end - Date.now())/1000);
            if (diff <= 0){ dl.textContent = 'abgelaufen'; return; }
            var d = Math.floor(diff/86400); diff%=86400;
            var h = Math.floor(diff/3600); diff%=3600;
            var m = Math.floor(diff/60);
            dl.textContent = d+'d '+h+'h '+m+'m';
            setTimeout(tick,60000);
          }
          tick();
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

          document.querySelectorAll('form.weo-psbt-form').forEach(function(f){
            f.addEventListener('submit', function(ev){
              var t = f.querySelector('textarea.weo-psbt');
              if (t) {
                var val = t.value.trim();
                if (!/^[A-Za-z0-9+/]+={0,2}$/.test(val)) {
                  alert('Ungültige PSBT (Base64 erforderlich).');
                  ev.preventDefault();
                }
              }
            });
          });
        } catch(e) {}
    })();
    </script>";

    // Handle Actions direkt nach dem Panel (MVP)
    if (!empty($_POST['weo_action'])) {
      $act = sanitize_text_field($_POST['weo_action']);
      if ($act === 'mark_shipped') {
        if (wp_verify_nonce($_POST['weo_nonce'] ?? '', 'weo_ship_'.$order_id)) {
          if ($cur && $cur == $vendor_id) {
            $order->update_meta_data('_weo_shipped', time());
            $order->save();
            do_action('weo_order_shipped', $order_id);
            echo '<div class="notice weo weo-info"><p>Versand bestätigt.</p></div>';
          } else {
            echo '<div class="notice weo weo-error"><p>Keine Berechtigung.</p></div>';
          }
        } else {
          echo '<div class="notice weo weo-error"><p>Ungültiger Sicherheits-Token.</p></div>';
        }
      } elseif ($act === 'mark_received') {
        if (wp_verify_nonce($_POST['weo_nonce'] ?? '', 'weo_recv_'.$order_id)) {
          if ($cur && $cur == $buyer_id) {
            $order->update_meta_data('_weo_received', time());
            $order->save();
            do_action('weo_order_received', $order_id);
            echo '<div class="notice weo weo-info"><p>Empfang bestätigt.</p></div>';
          } else {
            echo '<div class="notice weo weo-error"><p>Keine Berechtigung.</p></div>';
          }
        } else {
          echo '<div class="notice weo weo-error"><p>Ungültiger Sicherheits-Token.</p></div>';
        }
      } elseif (wp_verify_nonce($_POST['weo_nonce'] ?? '', 'weo_psbt_'.$order_id)) {
        if ($act === 'bumpfee') {
          $target = intval($_POST['target_conf'] ?? 1);
          $resp = weo_api_post('/tx/bumpfee', [
            'order_id'    => (string)$order->get_order_number(),
            'target_conf' => $target
          ]);
          if (!is_wp_error($resp) && !empty($resp['psbt'])) {
            $order->update_meta_data('_weo_rbf_psbt', $resp['psbt']);
            $order->delete_meta_data('_weo_psbt_partials_buyer');
            $order->delete_meta_data('_weo_psbt_partials_seller');
            $order->update_meta_data('_weo_psbt_sign_count', 0);
            $order->save();
            do_action('weo_rbf_requested', $order_id);
            $order->add_order_note(__('RBF angefordert; Verkäufer benachrichtigt.', 'weo'));
            $psbt_b64 = esc_textarea($resp['psbt']);
            echo '<div class="notice weo weo-info"><p><strong>RBF-PSBT (Base64):</strong></p><textarea rows="6" style="width:100%;">'.$psbt_b64.'</textarea></div>';
          } else {
            echo '<div class="notice weo weo-error"><p>Fee-Bump fehlgeschlagen.</p></div>';
          }
        } else {
          if ($act === 'build_psbt_payout') {
            $res = WEO_Psbt::build_payout_psbt($order_id);
          } elseif ($act === 'build_psbt_refund') {
            if (!current_user_can('manage_options')) {
              $res = new WP_Error('weo_psbt', __('Keine Berechtigung.','weo'));
            } else {
              $res = WEO_Psbt::build_refund_psbt($order_id);
            }
          } else {
            $res = null;
          }

          if (is_array($res)) {
            $psbt_b64 = $res['psbt'];
            $details  = $res['details'];
            echo '<div class="notice weo weo-info"><p><strong>PSBT (Base64):</strong></p><textarea rows="6" style="width:100%;">'.$psbt_b64.'</textarea>'.$details.'<p>Bitte in deiner Wallet laden, signieren und unten wieder hochladen.</p></div>';
          } elseif ($res) {
            echo '<div class="notice weo weo-error"><p>'.esc_html($res->get_error_message()).'</p></div>';
          }
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
      if (!check_admin_referer('weo_upload_psbt_'.$order_id)) wp_die('Ungültiger Sicherheits-Token.');
      if (base64_decode($psbt, true) === false) {
        wc_add_notice(__('Ungültige PSBT – bitte gültigen Base64-String einfügen.','weo'), 'error');
        wp_safe_redirect(wp_get_referer()); exit;
      }

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
    $status = weo_api_get('/orders/'.rawurlencode($oid).'/status');
    $state = is_wp_error($status) ? '' : ($status['state'] ?? '');
    $merge = weo_api_post('/psbt/merge', [
      'order_id' => $oid,
      'partials' => $all_partials
    ]);
    if (is_wp_error($merge) || empty($merge['psbt'])) {
      wc_add_notice('PSBT konnte nicht zusammengeführt werden. Bitte später erneut versuchen.', 'error');
      wp_safe_redirect(wp_get_referer()); exit;
    }

    $dec = weo_api_post('/psbt/decode', [ 'psbt' => $merge['psbt'] ]);
    $signs = is_wp_error($dec) ? 0 : intval($dec['sign_count'] ?? 0);
    $order->update_meta_data('_weo_psbt_sign_count', $signs);
    $order->save();

    do_action('weo_psbt_uploaded', $order_id, $cur);

    // Bei offenem Dispute keine Finalisierung/Broadcast
    if ($order->get_meta('_weo_dispute')) {
      wc_add_notice(__('Dispute offen – Auszahlung erfolgt erst nach Entscheidung des Administrators.','weo'), 'notice');
      wp_safe_redirect(wp_get_referer()); exit;
    }

    // Auszahlen nur, wenn zwei Signaturen und Freigabe vorliegen
    if ($signs < 2) {
      wc_add_notice('Noch nicht genügend Signaturen – zweite Unterschrift erforderlich.', 'notice');
      wp_safe_redirect(wp_get_referer()); exit;
    }
    if (empty($_POST['weo_release_funds'])) {
      wc_add_notice(__('Bitte bestätige die Freigabe der Escrow-Mittel.','weo'), 'notice');
      wp_safe_redirect(wp_get_referer()); exit;
    }

    // Versand/Empfang müssen bestätigt sein
    $shipped  = intval($order->get_meta('_weo_shipped'));
    $received = intval($order->get_meta('_weo_received'));
    if (!$shipped || !$received) {
      wc_add_notice(__('Versand/Empfang noch offen – Freigabe nicht möglich.','weo'), 'notice');
      wp_safe_redirect(wp_get_referer()); exit;
    }

    if ($state === 'rbf_signing') {
      $final = weo_api_post('/tx/bumpfee/finalize', [
        'order_id' => $oid,
        'psbt'     => $merge['psbt']
      ]);
      if (is_wp_error($final) || empty($final['txid'])) {
        wc_add_notice('Finalisierung fehlgeschlagen. Bitte später erneut versuchen.', 'error');
        wp_safe_redirect(wp_get_referer()); exit;
      }
      $order->update_meta_data('_weo_payout_txid', $final['txid']);
      $order->delete_meta_data('_weo_rbf_psbt');
      $order->save();
      do_action('weo_tx_broadcasted', $order_id, $final['txid']);
    } else {
      // Finalize
      $final = weo_api_post('/psbt/finalize', [
        'order_id' => $oid,
        'psbt'     => $merge['psbt']
      ]);

      if (is_wp_error($final) || empty($final['hex'])) {
        wc_add_notice('Finalisierung fehlgeschlagen. Bitte später erneut versuchen.', 'error');
        wp_safe_redirect(wp_get_referer()); exit;
      }

      // Broadcast
      $tx = weo_api_post('/tx/broadcast', [
        'hex'      => $final['hex'],
        'order_id' => $oid
      ]);
      if (!is_wp_error($tx) && !empty($tx['txid'])) {
        $order->update_meta_data('_weo_payout_txid', $tx['txid']);
        $outcome = $order->get_meta('_weo_dispute_outcome');
        if ($outcome === 'refund') {
          $order->update_status('refunded', 'Escrow erstattet. TXID: '.$tx['txid']);
        } else {
          $order->update_status('completed', 'Escrow ausgezahlt. TXID: '.$tx['txid']);
        }
        $order->delete_meta_data('_weo_dispute');
        $order->delete_meta_data('_weo_dispute_outcome');
        $order->save();
        do_action('weo_tx_broadcasted', $order_id, $tx['txid']);
      } else {
        wc_add_notice('Broadcast fehlgeschlagen. Bitte Support kontaktieren.', 'error');
      }
    }

    wp_safe_redirect(wp_get_referer()); exit;
  }

  /** Dispute eröffnen → optional finalize/broadcast mit State 'dispute' */
  public function open_dispute() {
    if (!is_user_logged_in()) wp_die('Nicht erlaubt.');
    $order_id = intval($_POST['order_id'] ?? 0);
    if (!$order_id) wp_die('Fehlende Order-ID.');
    if (!check_admin_referer('weo_open_dispute_'.$order_id)) wp_die('Ungültiger Sicherheits-Token.');
    $order = wc_get_order($order_id); if (!$order) wp_die('Bestellung nicht gefunden.');
    if ($order->get_meta('_weo_rbf_psbt')) wp_die('RBF aktiv – Dispute nicht möglich.');

    $buyer_id  = $order->get_user_id();
    $vendor_id = $order->get_meta('_weo_vendor_id');
    if (!$vendor_id) { $this->fallback_vendor_payout_address($order_id); $vendor_id = $order->get_meta('_weo_vendor_id'); }
    $cur = get_current_user_id();
    if ($cur !== $buyer_id && $cur !== $vendor_id) wp_die('Nicht erlaubt.');

    $signs = intval($order->get_meta('_weo_psbt_sign_count'));
    if ($signs >= 2) {
      wc_add_notice(__('Ein Dispute kann nicht mehr eröffnet werden, nachdem beide Parteien signiert haben.','weo'), 'error');
      wp_safe_redirect(wp_get_referer()); exit;
    }

    $note = sanitize_textarea_field($_POST['weo_dispute_note'] ?? '');

    $oid = weo_sanitize_order_id((string)$order->get_order_number());
    $res = weo_api_post('/psbt/finalize', [
      'order_id' => $oid,
      'psbt'     => '',
      'state'    => 'dispute'
    ]);
    if (is_wp_error($res)) {
      $order->add_order_note('Dispute-Anmeldung beim Escrow-Service fehlgeschlagen – erneuter Versuch in 5 Minuten.');
      wc_add_notice('Escrow-Service aktuell nicht erreichbar. Der Dispute wird später automatisch gemeldet.', 'error');
      if (!wp_next_scheduled('weo_retry_finalize_dispute', [$order_id])) {
        wp_schedule_single_event(time()+300, 'weo_retry_finalize_dispute', [$order_id]);
      }
    }

    $order->update_status('on-hold', 'Dispute eröffnet');
    $order->update_meta_data('_weo_dispute', current_time('mysql'));
    if ($note) $order->update_meta_data('_weo_dispute_note', $note);
    $order->save();

    do_action('weo_dispute_opened', $order);

    wp_safe_redirect(wp_get_referer()); exit;
  }

  public static function retry_create_escrow($order_id) {
    self::maybe_create_escrow($order_id, 'pending', 'on-hold', null);
  }

  public static function retry_finalize_dispute($order_id) {
    $order = wc_get_order($order_id); if (!$order) return;
    $oid = weo_sanitize_order_id((string)$order->get_order_number());
    $res = weo_api_post('/psbt/finalize', [
      'order_id' => $oid,
      'psbt'     => '',
      'state'    => 'dispute'
    ]);
    if (is_wp_error($res) && !wp_next_scheduled('weo_retry_finalize_dispute', [$order_id])) {
      wp_schedule_single_event(time()+300, 'weo_retry_finalize_dispute', [$order_id]);
    }
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
    if ($cnt >= 2) {
      echo '<p>'.esc_html__('Ein Dispute kann nicht mehr eröffnet werden, nachdem die signierte PSBT hochgeladen und bestätigt wurde.','weo').'</p>';
    }
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
        $payout = weo_get_payout_address($vendor_id);
        if ($payout) return $payout;
      }
    }
    $fallback = get_option('weo_vendor_payout_fallback','');
    if ($fallback) return $fallback;
    wc_add_notice(__('Keine Fallback-Payout-Adresse konfiguriert.','weo'),'error');
    throw new Exception('Fallback vendor payout address missing');
  }
}

add_action('weo_retry_create_escrow', ['WEO_Order','retry_create_escrow']);
add_action('weo_retry_finalize_dispute', ['WEO_Order','retry_finalize_dispute']);
