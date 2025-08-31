<?php
if (!defined('ABSPATH')) exit;
?>
<?php if (!empty($psbt_notice)) echo $psbt_notice; ?>
<?php if (!empty($orders)) : ?>
  <?php foreach ($orders as $o) : ?>
    <div class="weo-escrow-order">
      <h3><?php echo esc_html(sprintf(__('Bestellung #%s','weo'), $o['number'])); ?></h3>
      <p><?php esc_html_e('Status','weo'); ?>: <strong><?php echo esc_html($o['state']); ?></strong></p>
      <?php if ($o['state'] === 'dispute') : ?>
        <div class="notice weo weo-warning"><p><?php esc_html_e('Dispute offen – bitte warte auf die Entscheidung des Administrators.','weo'); ?></p></div>
      <?php endif; ?>
      <?php if (!empty($o['funding'])) : ?>
        <?php $f = $o['funding']; $txid = esc_html($f['txid'] ?? ''); $confs = intval($f['confirmations'] ?? 0); $val = isset($f['value_sat']) ? intval($f['value_sat']).' sats' : ''; ?>
        <p><?php esc_html_e('Funding TX','weo'); ?>: <code><?php echo $txid; ?></code> • <?php esc_html_e('Confs','weo'); ?>: <?php echo $confs; ?> • <?php esc_html_e('Betrag','weo'); ?>: <?php echo esc_html($val); ?></p>
      <?php else : ?>
        <p><?php esc_html_e('Noch keine Einzahlung erkannt.','weo'); ?></p>
      <?php endif; ?>
      <?php if (!empty($o['addr'])) : ?>
        <p><strong><?php esc_html_e('Escrow-Adresse','weo'); ?>:</strong> <code id="weo_addr_<?php echo intval($o['id']); ?>"><?php echo esc_html($o['addr']); ?></code></p>
        <div class="weo-qr" id="weo_qr_<?php echo intval($o['id']); ?>" data-addr="<?php echo esc_attr($o['addr']); ?>"></div>
      <?php endif; ?>

      <p><?php esc_html_e('Versand','weo'); ?>: <?php echo $o['shipped'] ? esc_html(date_i18n(get_option('date_format'), $o['shipped'])) : esc_html__('noch nicht bestätigt','weo'); ?></p>
      <p><?php esc_html_e('Empfang','weo'); ?>: <?php echo $o['received'] ? esc_html(date_i18n(get_option('date_format'), $o['received'])) : esc_html__('noch nicht bestätigt','weo'); ?></p>

      <?php $ship_nonce = wp_create_nonce('weo_ship_'.$o['id']); ?>
      <?php if ($o['role'] === 'vendor' && empty($o['shipped'])) : ?>
        <form method="post" class="dokan-form" style="margin-top:10px;">
          <input type="hidden" name="order_id" value="<?php echo intval($o['id']); ?>">
          <input type="hidden" name="weo_nonce" value="<?php echo esc_attr($ship_nonce); ?>">
          <input type="hidden" name="weo_action" value="mark_shipped">
          <button type="submit" class="dokan-btn"><?php esc_html_e('Als versendet markieren','weo'); ?></button>
        </form>
      <?php endif; ?>
      <?php $recv_nonce = wp_create_nonce('weo_recv_'.$o['id']); ?>
      <?php if ($o['role'] === 'buyer' && $o['shipped'] && empty($o['received'])) : ?>
        <form method="post" class="dokan-form" style="margin-top:10px;">
          <input type="hidden" name="order_id" value="<?php echo intval($o['id']); ?>">
          <input type="hidden" name="weo_nonce" value="<?php echo esc_attr($recv_nonce); ?>">
          <input type="hidden" name="weo_action" value="mark_received">
          <button type="submit" class="dokan-btn"><?php esc_html_e('Empfang bestätigen','weo'); ?></button>
        </form>
      <?php endif; ?>

      <?php if ($o['role'] === 'vendor' && in_array($o['state'], ['escrow_funded','signing','dispute'])) : ?>
        <?php $nonce = wp_create_nonce('weo_psbt_'.$o['id']); ?>
        <?php if ($o['state'] !== 'dispute') : ?>
        <form method="post" class="dokan-form" style="margin-top:10px;">
          <input type="hidden" name="order_id" value="<?php echo intval($o['id']); ?>">
          <input type="hidden" name="weo_nonce" value="<?php echo esc_attr($nonce); ?>">
          <input type="hidden" name="weo_action" value="build_psbt_payout">
          <button type="submit" class="dokan-btn"><?php esc_html_e('PSBT (Auszahlung an Verkäufer) erstellen','weo'); ?></button>
        </form>
        <?php endif; ?>
      <?php endif; ?>

      <?php if ($o['role'] === 'vendor') : ?>
      <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="dokan-form" style="margin-top:10px;">
        <input type="hidden" name="action" value="weo_upload_psbt_seller">
        <input type="hidden" name="order_id" value="<?php echo intval($o['id']); ?>">
        <div class="dokan-form-group">
          <label class="dokan-form-label" for="weo_signed_psbt_<?php echo intval($o['id']); ?>"><?php esc_html_e('Signierte PSBT (Base64)','weo'); ?></label>
          <textarea name="weo_signed_psbt" id="weo_signed_psbt_<?php echo intval($o['id']); ?>" rows="6" style="width:100%" placeholder="PSBT..."></textarea>
        </div>
        <div class="dokan-form-group">
          <button type="submit" class="dokan-btn dokan-btn-theme"><?php esc_html_e('PSBT hochladen','weo'); ?></button>
        </div>
      </form>
      <?php endif; ?>

      <?php if ($o['role'] === 'buyer') : ?>
      <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="dokan-form" style="margin-top:10px;">
        <input type="hidden" name="action" value="weo_upload_psbt_buyer">
        <input type="hidden" name="order_id" value="<?php echo intval($o['id']); ?>">
        <div class="dokan-form-group">
          <label class="dokan-form-label" for="weo_signed_psbt_<?php echo intval($o['id']); ?>"><?php esc_html_e('Signierte PSBT (Base64)','weo'); ?></label>
          <textarea name="weo_signed_psbt" id="weo_signed_psbt_<?php echo intval($o['id']); ?>" rows="6" style="width:100%" placeholder="PSBT..."></textarea>
        </div>
        <div class="dokan-form-group">
          <button type="submit" class="dokan-btn dokan-btn-theme"><?php esc_html_e('PSBT hochladen','weo'); ?></button>
        </div>
      </form>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
  <script>
  (function(){
    if (!window.QRCode) return;
    document.querySelectorAll('.weo-qr[data-addr]').forEach(function(el){
      new QRCode(el, el.getAttribute('data-addr'));
    });
  })();
  </script>
<?php else : ?>
  <p><?php esc_html_e('Keine Escrow-Bestellungen gefunden.','weo'); ?></p>
<?php endif; ?>
