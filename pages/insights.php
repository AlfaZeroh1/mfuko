<?php $scripts[] = 'js/dashboard.js'; ?>
<div class="page-head">
  <div class="grow"><h2><?= icon('sparkles') ?> AI insights</h2><p class="muted small" style="margin:0">Mfuko studies your sales, stock, customers and staff every time you open this page.<?= Ai::enabled() ? '' : ' Connect an AI provider in Settings → AI to ask questions in plain language.' ?></p></div>
  <button class="btn" onclick="location.reload()"><?= icon('refresh') ?> Refresh</button>
</div>
<div class="grid g-2-1">
  <div class="card" id="ins"><div class="card-body"><div class="skeleton" style="height:200px"></div></div></div>
  <div class="stack" id="side"></div>
</div>
<script>
MF.ready(async () => {
  const side = document.getElementById('side');
  side.innerHTML = MF.dash.askCard.replace('hide', '<?= Ai::enabled() ? '' : 'hide' ?>') + `<div class="card card-pad small"><b>How this works</b><p class="muted mt">Forecasts use your weekday pattern and the last 4 weeks trend. Stock-out predictions use 30-day sales velocity. Churn looks for customers with 3+ visits who stopped coming. All computed on your own server — your data stays yours.</p></div>`;
  MF.dash.wireAsk(side);
  const d = await MF.get('dashboard.insights');
  document.getElementById('ins').innerHTML = MF.dash.insightsHtml(d.cards);
});
</script>
