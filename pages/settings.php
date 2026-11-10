<?php
$tab = $_GET['tab'] ?? 'business';
$yn = ['1' => 'Yes', '0' => 'No'];
$tabs = [
    'business' => ['Business', 'store', [
        'business_name' => ['Business name', 'text'], 'business_tagline' => ['Tagline / slogan', 'text'],
        'business_phone' => ['Phone', 'text'], 'business_email' => ['Email', 'text'],
        'business_address' => ['Address / P.O. Box', 'text'], 'business_town' => ['Town', 'text'], 'business_kra_pin' => ['KRA PIN', 'text'],
    ]],
    'regional' => ['Time & currency', 'clock', [
        'timezone' => ['Timezone (applies to the app AND the database)', 'tz'], 'currency' => ['Currency code', 'text'], 'currency_symbol' => ['Currency symbol', 'text'],
        'date_format' => ['Date format', 'select', ['d M Y' => '10 Nov 2026', 'd/m/Y' => '10/11/2026', 'Y-m-d' => '2026-11-10', 'M j, Y' => 'Nov 10, 2026']],
        'default_theme' => ['Default theme', 'select', ['auto' => 'Follow device', 'light' => 'Solar Garden (light)', 'dark' => 'Wakanda Night (dark)']],
    ]],
    'sales' => ['Selling', 'cart', [
        'default_sale_type' => ['Default sale type', 'select', ['retail' => 'Retail', 'wholesale' => 'Wholesale']],
        'tax_mode' => ['Prices are', 'select', ['inclusive' => 'VAT inclusive', 'exclusive' => 'VAT exclusive (added at till)']], 'default_tax_rate' => ['Default VAT % for new products', 'number'],
        'max_discount_pct' => ['Max line discount % (non-owners)', 'number'], 'allow_negative_stock' => ['Allow selling with zero stock', 'select', $yn],
        'enforce_credit_limit' => ['Block sales above credit limit', 'select', $yn], 'require_shift' => ['Require an open register shift to sell', 'select', $yn],
        'quote_valid_days' => ['Quotation validity (days)', 'number'], 'sell_show_images' => ['Show product photos on sell screen', 'select', $yn],
    ]],
    'printing' => ['Printing', 'printer', [
        'print_receipt_size' => ['Default size for sale receipts', 'select', ['thermal' => 'Thermal roll', 'a4' => 'A4']],
        'print_default_size' => ['Default size for other documents', 'select', ['a4' => 'A4', 'thermal' => 'Thermal roll']],
        'thermal_width' => ['Thermal paper width', 'select', ['80' => '80 mm', '58' => '58 mm']], 'auto_print' => ['Auto-print receipt after sale (desktop)', 'select', $yn],
        'print_show_pay_accounts' => ['Show "Pay via" accounts on invoices/statements', 'select', $yn], 'print_show_served_by' => ['Show cashier name', 'select', $yn],
        'print_footer' => ['Footer message', 'text'], 'print_terms' => ['Terms on invoices', 'textarea'],
    ]],
    'mpesa' => ['M-Pesa', 'phone', [
        'mpesa_enabled' => ['Enable Daraja integration', 'select', $yn], 'mpesa_env' => ['Environment', 'select', ['sandbox' => 'Sandbox (testing)', 'production' => 'Production (live)']],
        'mpesa_type' => ['Shortcode type', 'select', ['paybill' => 'Paybill', 'till' => 'Buy Goods Till']], 'mpesa_shortcode' => ['Business shortcode (paybill / store number)', 'text'],
        'mpesa_till' => ['Till number (if Buy Goods)', 'text'], 'mpesa_passkey' => ['Lipa na M-Pesa passkey', 'secret'],
        'mpesa_consumer_key' => ['Consumer key', 'text'], 'mpesa_consumer_secret' => ['Consumer secret', 'secret'],
        'mpesa_callback_base' => ['Public base URL of this system (https://…)', 'text'],
    ]],
    'email' => ['Email', 'mail', [
        'mail_driver' => ['Send with', 'select', ['smtp' => 'SMTP server', 'mail' => 'PHP mail()']], 'smtp_host' => ['SMTP host', 'text'], 'smtp_port' => ['Port', 'number'],
        'smtp_secure' => ['Encryption', 'select', ['tls' => 'STARTTLS (587)', 'ssl' => 'SSL (465)', 'none' => 'None']], 'smtp_user' => ['Username', 'text'], 'smtp_pass' => ['Password', 'secret'],
        'mail_from' => ['From email', 'text'], 'mail_from_name' => ['From name', 'text'],
    ]],
    'sms' => ['SMS', 'send', [
        'sms_enabled' => ['Enable SMS (Africa\'s Talking)', 'select', $yn], 'sms_username' => ['Username ("sandbox" for testing)', 'text'],
        'sms_api_key' => ['API key', 'secret'], 'sms_sender_id' => ['Sender ID (optional)', 'text'],
    ]],
    'reminders' => ['Debt reminders', 'bell', [
        'reminder_auto' => ['Send automatic reminders (via cron)', 'select', $yn], 'reminder_every_days' => ['Remind every N days', 'number'],
        'reminder_subject' => ['Email subject', 'text'], 'reminder_body' => ['Message ({name} {balance} {days_overdue} {pay_accounts} {business})', 'textarea'],
    ]],
    'ai' => ['AI', 'sparkles', [
        'ai_provider' => ['Provider', 'select', ['none' => 'Off (built-in insights only)', 'anthropic' => 'Anthropic Claude', 'openai' => 'OpenAI']],
        'ai_api_key' => ['API key', 'secret'], 'ai_model' => ['Model (blank = default)', 'text'],
    ]],
];
if (!isset($tabs[$tab])) $tab = 'business';
$logo = Settings::get('business_logo');
?>
<div class="page-head"><h2 class="grow">Settings</h2><a class="btn" href="<?= url('payment-accounts') ?>"><?= icon('card') ?> Payment options</a><a class="btn" href="<?= url('stores') ?>"><?= icon('store') ?> Stores</a></div>
<div class="tabs"><?php foreach ($tabs as $k => [$label, $ic]): ?><a href="<?= url('settings', ['tab' => $k]) ?>" class="<?= $k === $tab ? 'on' : '' ?>"><?= icon($ic, 'sm') ?> <?= e($label) ?></a><?php endforeach; ?></div>
<div class="grid g-2-1">
  <div class="card card-pad">
    <form id="sf" class="form-grid" onsubmit="return false">
      <?php foreach ($tabs[$tab][2] as $key => $f): $type = $f[1]; ?>
        <div class="field <?= $type === 'textarea' ? 'full' : '' ?>"><label><?= e($f[0]) ?></label>
          <?php if ($type === 'select'): ?><select name="<?= e($key) ?>"><?php foreach ($f[2] as $v => $l): ?><option value="<?= e($v) ?>" <?= Settings::get($key) === (string)$v ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
          <?php elseif ($type === 'tz'): ?><select name="<?= e($key) ?>"><?php foreach (timezone_identifiers_list() as $z): ?><option <?= Settings::get($key) === $z ? 'selected' : '' ?>><?= e($z) ?></option><?php endforeach; ?></select>
          <?php elseif ($type === 'textarea'): ?><textarea name="<?= e($key) ?>" rows="6"><?= e(Settings::get($key)) ?></textarea>
          <?php elseif ($type === 'secret'): ?><input type="password" name="<?= e($key) ?>" value="<?= Settings::get($key) !== '' ? '••••••••' : '' ?>" autocomplete="new-password">
          <?php else: ?><input type="<?= $type === 'number' ? 'number' : 'text' ?>" step="any" name="<?= e($key) ?>" value="<?= e(Settings::get($key)) ?>"><?php endif; ?>
        </div>
      <?php endforeach; ?>
      <div class="full row"><button class="btn btn-primary" id="save"><?= icon('check') ?> Save <?= e(strtolower($tabs[$tab][0])) ?> settings</button></div>
    </form>
  </div>
  <div class="stack">
    <?php if ($tab === 'business'): ?>
      <div class="card card-pad"><h3>Business logo</h3><p class="small muted">Shown on A4 invoices, statements, quotations, emails and the sign-in screen. Thermal receipts stay text-only for speed and clarity.</p>
        <div style="height:120px;border:2px dashed var(--border-2);border-radius:14px;display:grid;place-items:center;background:#fff" id="logoBox"><?= $logo ? '<img src="' . e($logo) . '" style="max-height:100px;max-width:90%">' : '<span class="muted">No logo yet</span>' ?></div>
        <div class="row mt"><label class="btn btn-soft"><?= icon('upload', 'sm') ?> Upload logo<input type="file" accept="image/*" id="logoIn" hidden></label><?php if ($logo): ?><button class="btn btn-ghost" id="logoRm">Remove</button><?php endif; ?></div></div>
    <?php elseif ($tab === 'regional'): ?>
      <div class="card card-pad"><h3>Clock check</h3><p class="small">PHP time: <b><?= e(date('Y-m-d H:i:s')) ?></b><br>Database time: <b><?= e((string)DB::val('SELECT NOW()')) ?></b><br>Timezone: <b><?= e(date_default_timezone_get()) ?></b> (UTC<?= e(date('P')) ?>)</p>
        <p class="small muted">Both are aligned on every connection, so receipts, reports and the navbar clock always agree.</p></div>
    <?php elseif ($tab === 'mpesa'): ?>
      <div class="card card-pad"><h3>Daraja setup</h3><ol class="small" style="padding-left:18px">
        <li>Create an app at developer.safaricom.co.ke and copy the consumer key/secret.</li><li>Fill in your shortcode and passkey, choose Sandbox to test.</li>
        <li>Set the public HTTPS URL of this system so Safaricom can reach <code>mpesa_callback.php</code>.</li><li>Save, then test the connection and register C2B URLs.</li></ol>
        <?php if (Settings::get('mpesa_callback_base')): ?><p class="small">STK callback: <code style="word-break:break-all"><?= e(Mpesa::callbackUrl('stk')) ?></code></p><?php endif; ?>
        <div class="row"><button class="btn" id="mpTest"><?= icon('zap', 'sm') ?> Test connection</button><button class="btn" id="mpReg"><?= icon('refresh', 'sm') ?> Register C2B URLs</button></div>
        <p class="small muted mt">Tip: list your tills/paybills under <a href="<?= url('payment-accounts') ?>">Payment options</a> so they print on invoices and show at checkout.</p></div>
    <?php elseif ($tab === 'email'): ?>
      <div class="card card-pad"><h3>Send a test</h3><div class="row nowrap"><input type="email" id="testTo" placeholder="you@example.com" value="<?= e($user['email'] ?? '') ?>"><button class="btn" id="testMail"><?= icon('send', 'sm') ?></button></div>
        <p class="small muted mt">Gmail: host smtp.gmail.com, port 587, STARTTLS and an App Password. Queued mail is delivered by <code>tools/cron.php</code> every minute.</p></div>
    <?php elseif ($tab === 'ai'): ?>
      <div class="card card-pad"><h3>About AI</h3><p class="small muted">Built-in insights (forecasts, stock-out alerts, churn, margin leaks, bundles) always run on your server. Adding a provider unlocks “Ask Mfuko AI”, which sends a summarized snapshot of your numbers — never customer names or phone numbers — to the provider you choose.</p></div>
    <?php else: ?>
      <div class="card card-pad small muted">Changes apply immediately and are recorded in the audit trail.</div>
    <?php endif; ?>
  </div>
</div>
<script>
MF.ready(() => {
  const { $ } = MF;
  $('#save').onclick = async e => {
    e.target.classList.add('loading');
    try { const r = await MF.api('admin.settings_save', { settings: MF.formData($('#sf')) }); MF.toast(r.changed.length ? 'Saved ' + r.changed.length + ' setting(s)' : 'No changes', 'ok'); if (r.changed.includes('timezone') || r.changed.includes('currency_symbol')) setTimeout(() => location.reload(), 600); }
    finally { e.target.classList.remove('loading'); }
  };
  $('#logoIn') && ($('#logoIn').onchange = async e => { const f = e.target.files[0]; if (!f) return; const fd = new FormData(); fd.append('logo', f); await MF.api('admin.logo', fd); location.reload(); });
  $('#logoRm') && ($('#logoRm').onclick = async () => { await MF.api('admin.logo', { remove: 1 }); location.reload(); });
  $('#mpTest') && ($('#mpTest').onclick = async () => { const r = await MF.api('mpesa.test', {}); MF.toast('Connected to Daraja ✓', 'ok'); });
  $('#mpReg') && ($('#mpReg').onclick = async () => { await MF.api('mpesa.register_c2b', {}); MF.toast('C2B URLs registered', 'ok'); });
  $('#testMail') && ($('#testMail').onclick = async e => { e.target.closest('button').classList.add('loading'); try { await MF.api('comms.test_email', { to: $('#testTo').value }); MF.toast('Test email sent', 'ok'); } finally { e.target.closest('button').classList.remove('loading'); } });
});
</script>
