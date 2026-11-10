<?php
/** Sell / POS screen. All behaviour lives in assets/js/sell.js. */
$scripts[] = 'js/sell.js';
$storeName = DB::val('SELECT name FROM stores WHERE id=?', [$storeId]) ?? '—';
?>
<div class="pos" id="pos" data-store="<?= (int)$storeId ?>">
  <section class="pos-catalog">
    <div class="pos-toolbar">
      <div class="search-box grow">
        <?= icon('search') ?>
        <input type="search" id="q" placeholder="Search name, SKU or scan barcode…" autocomplete="off" enterkeyhint="search">
      </div>
      <button class="btn btn-icon hide" id="scanBtn" title="Scan with camera"><?= icon('scan') ?></button>
      <div class="seg hide-sm" id="viewSeg"><button data-view="grid" class="on" title="Grid"><?= icon('grid', 'sm') ?></button><button data-view="list" title="List"><?= icon('list', 'sm') ?></button></div>
    </div>
    <div class="chips" id="cats"></div>
    <div class="pos-grid" id="grid"><div class="skeleton" style="height:120px"></div><div class="skeleton" style="height:120px"></div><div class="skeleton" style="height:120px"></div></div>
  </section>

  <aside class="pos-cart" id="cartPanel">
    <div class="cart-head">
      <button class="icon-btn cart-close" id="cartClose" aria-label="Back"><?= icon('arrow-left') ?></button>
      <div class="cust-pick grow" id="custPick">
        <?= icon('user') ?>
        <input type="search" id="custQ" placeholder="Walk-in customer" autocomplete="off">
        <button class="icon-btn hide" id="custClear" aria-label="Remove customer"><?= icon('x') ?></button>
        <div class="cust-results hide" id="custResults"></div>
      </div>
      <div class="dropdown">
        <button class="icon-btn" data-toggle-dropdown aria-label="More"><?= icon('grid') ?></button>
        <div class="dropdown-menu">
          <button type="button" id="holdBtn"><?= icon('pause') ?>Hold this sale</button>
          <button type="button" id="heldBtn"><?= icon('history') ?>Held sales <span class="badge" id="heldCount">0</span></button>
          <?php if (Auth::can('quotes.manage')): ?><button type="button" id="quoteBtn"><?= icon('file') ?>Save as quotation</button><?php endif; ?>
          <button type="button" id="reloadCat"><?= icon('refresh') ?>Refresh products</button>
          <button type="button" id="clearBtn" class="text-danger"><?= icon('trash') ?>Clear cart</button>
        </div>
      </div>
    </div>
    <div class="cart-meta">
      <div class="seg" id="typeSeg"><button data-type="retail" class="on">Retail</button><button data-type="wholesale">Wholesale</button></div>
      <span class="muted small"><?= icon('store', 'sm') ?> <?= e($storeName) ?></span>
      <span class="badge badge-gold hide" id="custDebt"></span>
    </div>
    <div class="cart-lines" id="lines"></div>
    <div class="cart-totals" id="totals"></div>
    <div class="cart-actions">
      <button class="btn btn-primary btn-lg btn-block" id="payBtn" disabled><?= icon('wallet') ?><span>Charge</span><b id="payAmt"></b></button>
    </div>
  </aside>

  <!-- Phone: floating cart bar -->
  <button class="cart-bar" id="cartBar" type="button">
    <span class="cnt" id="barCount">0</span>
    <span class="grow">View cart</span>
    <b id="barTotal"><?= e(Settings::get('currency_symbol')) ?> 0.00</b>
    <?= icon('chevron-right') ?>
  </button>
</div>

<template id="payTpl">
  <div class="pay">
    <div class="pay-due"><span>Total due</span><b data-due></b></div>
    <div class="pay-quick" data-quick></div>
    <div class="pay-rows" data-rows></div>
    <button type="button" class="btn btn-sm btn-ghost" data-addrow><?= icon('plus', 'sm') ?> Add another payment</button>
    <div class="pay-sum">
      <div><span>Paid</span><b data-paid></b></div>
      <div><span data-ballabel>Balance</span><b data-bal></b></div>
    </div>
    <div class="alert alert-gold hide" data-credit></div>
    <details class="pay-more">
      <summary>Coupon, delivery & notes</summary>
      <div class="form-grid mt">
        <div class="field"><label>Coupon code</label><div class="input-group"><input type="text" data-coupon placeholder="e.g. KARIBU10" style="text-transform:uppercase"><button type="button" class="btn" data-applycoupon>Apply</button></div></div>
        <div class="field"><label>Delivery / carriage</label><input type="number" inputmode="decimal" min="0" step="0.01" data-ship value="0"></div>
        <?php if (Auth::can('sell.backdate')): ?><div class="field"><label>Sale date/time</label><input type="datetime-local" data-soldat></div><?php endif; ?>
        <div class="field full"><label>Notes</label><input type="text" data-notes maxlength="250" placeholder="Optional note on the receipt"></div>
      </div>
    </details>
  </div>
</template>
