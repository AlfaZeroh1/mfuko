-- =====================================================================
--  Mfuko POS — database schema (MySQL 8 / MariaDB 10.5+)
--  Money: DECIMAL(14,2)  Quantities: DECIMAL(14,3)  Engine: InnoDB utf8mb4
--  All timestamps are stored in the business timezone (session time_zone is
--  set on every connection from settings.timezone, default Africa/Nairobi).
-- =====================================================================
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------- System ---------------------------------------------------
CREATE TABLE IF NOT EXISTS settings (
  `key`        VARCHAR(64) PRIMARY KEY,
  `value`      MEDIUMTEXT NULL,
  updated_at   DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sequences (
  `key`        VARCHAR(32) PRIMARY KEY,
  prefix       VARCHAR(16) NOT NULL DEFAULT '',
  next_no      INT UNSIGNED NOT NULL DEFAULT 1,
  pad          TINYINT UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stores (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name         VARCHAR(80) NOT NULL,
  code         VARCHAR(20) NULL,
  address      VARCHAR(255) NULL,
  phone        VARCHAR(32) NULL,
  is_active    TINYINT(1) NOT NULL DEFAULT 1,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- Access control ------------------------------------------
CREATE TABLE IF NOT EXISTS roles (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name         VARCHAR(60) NOT NULL UNIQUE,
  description  VARCHAR(255) NULL,
  dashboard    ENUM('admin','cashier','storekeeper','accountant') NOT NULL DEFAULT 'cashier',
  home_page    VARCHAR(40) NOT NULL DEFAULT 'dashboard',
  is_system    TINYINT(1) NOT NULL DEFAULT 0,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS role_permissions (
  role_id      INT UNSIGNED NOT NULL,
  permission   VARCHAR(48) NOT NULL,
  PRIMARY KEY (role_id, permission),
  CONSTRAINT fk_rp_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS users (
  id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  role_id          INT UNSIGNED NOT NULL,
  first_name       VARCHAR(60) NOT NULL,
  last_name        VARCHAR(60) NULL,
  username         VARCHAR(40) NOT NULL UNIQUE,
  email            VARCHAR(120) NULL,
  phone            VARCHAR(32) NULL,
  id_no            VARCHAR(32) NULL,
  password_hash    VARCHAR(255) NOT NULL,
  pin_hash         VARCHAR(255) NULL,
  avatar           VARCHAR(255) NULL,
  default_store_id INT UNSIGNED NULL,
  daily_target     DECIMAL(14,2) NOT NULL DEFAULT 0,
  theme            ENUM('auto','light','dark') NOT NULL DEFAULT 'auto',
  status           ENUM('active','suspended') NOT NULL DEFAULT 'active',
  failed_logins    TINYINT UNSIGNED NOT NULL DEFAULT 0,
  locked_until     DATETIME NULL,
  last_login_at    DATETIME NULL,
  last_seen_at     DATETIME NULL,
  last_page        VARCHAR(60) NULL,
  last_ip          VARCHAR(45) NULL,
  created_by       INT UNSIGNED NULL,
  created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_users_seen (last_seen_at),
  CONSTRAINT fk_users_role FOREIGN KEY (role_id) REFERENCES roles(id),
  CONSTRAINT fk_users_store FOREIGN KEY (default_store_id) REFERENCES stores(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_stores (
  user_id      INT UNSIGNED NOT NULL,
  store_id     INT UNSIGNED NOT NULL,
  PRIMARY KEY (user_id, store_id),
  CONSTRAINT fk_us_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_us_store FOREIGN KEY (store_id) REFERENCES stores(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_logs (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id      INT UNSIGNED NULL,
  action       VARCHAR(48) NOT NULL,
  entity       VARCHAR(40) NULL,
  entity_id    VARCHAR(40) NULL,
  summary      VARCHAR(255) NULL,
  old_values   JSON NULL,
  new_values   JSON NULL,
  ip           VARCHAR(45) NULL,
  user_agent   VARCHAR(255) NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_audit_created (created_at),
  KEY idx_audit_user (user_id, created_at),
  KEY idx_audit_entity (entity, entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- Catalogue -----------------------------------------------
CREATE TABLE IF NOT EXISTS categories (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name         VARCHAR(80) NOT NULL,
  code         VARCHAR(20) NULL,
  color        VARCHAR(9) NULL,
  description  TEXT NULL,
  is_active    TINYINT(1) NOT NULL DEFAULT 1,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS products (
  id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  sku              VARCHAR(40) NULL,
  barcode          VARCHAR(64) NULL,
  name             VARCHAR(255) NOT NULL,
  category_id      INT UNSIGNED NULL,
  brand            VARCHAR(108) NULL,
  unit             VARCHAR(32) NOT NULL DEFAULT 'pc',
  image            VARCHAR(255) NULL,
  cost_price       DECIMAL(14,2) NOT NULL DEFAULT 0,
  retail_price     DECIMAL(14,2) NOT NULL DEFAULT 0,
  wholesale_price  DECIMAL(14,2) NOT NULL DEFAULT 0,
  min_price        DECIMAL(14,2) NOT NULL DEFAULT 0,
  tax_rate         DECIMAL(5,2) NOT NULL DEFAULT 0,
  reorder_level    DECIMAL(14,3) NOT NULL DEFAULT 0,
  max_level        DECIMAL(14,3) NULL,
  parent_id        INT UNSIGNED NULL COMMENT 'Bulk pack this item breaks down from',
  units_per_parent DECIMAL(14,3) NULL,
  track_stock      TINYINT(1) NOT NULL DEFAULT 1,
  is_favorite      TINYINT(1) NOT NULL DEFAULT 0,
  is_active        TINYINT(1) NOT NULL DEFAULT 1,
  expiry_date      DATE NULL,
  description      TEXT NULL,
  created_by       INT UNSIGNED NULL,
  created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_products_sku (sku),
  KEY idx_products_barcode (barcode),
  KEY idx_products_name (name),
  KEY idx_products_updated (updated_at),
  CONSTRAINT fk_products_cat FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL,
  CONSTRAINT fk_products_parent FOREIGN KEY (parent_id) REFERENCES products(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stock (
  store_id     INT UNSIGNED NOT NULL,
  product_id   INT UNSIGNED NOT NULL,
  qty          DECIMAL(14,3) NOT NULL DEFAULT 0,
  updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (store_id, product_id),
  KEY idx_stock_product (product_id),
  CONSTRAINT fk_stock_store FOREIGN KEY (store_id) REFERENCES stores(id) ON DELETE CASCADE,
  CONSTRAINT fk_stock_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stock_movements (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  store_id     INT UNSIGNED NOT NULL,
  product_id   INT UNSIGNED NOT NULL,
  type         ENUM('sale','return','purchase','supplier_return','transfer_out','transfer_in','adjustment','stock_take','breakdown_out','breakdown_in','void','opening') NOT NULL,
  qty          DECIMAL(14,3) NOT NULL,
  balance      DECIMAL(14,3) NOT NULL,
  unit_cost    DECIMAL(14,2) NULL,
  ref_type     VARCHAR(24) NULL,
  ref_id       INT UNSIGNED NULL,
  note         VARCHAR(255) NULL,
  user_id      INT UNSIGNED NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_mv_product (product_id, created_at),
  KEY idx_mv_store (store_id, created_at),
  KEY idx_mv_ref (ref_type, ref_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- Customers & payments setup ------------------------------
CREATE TABLE IF NOT EXISTS customers (
  id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name             VARCHAR(120) NOT NULL,
  phone            VARCHAR(32) NULL,
  email            VARCHAR(160) NULL,
  address          VARCHAR(255) NULL,
  kra_pin          VARCHAR(20) NULL,
  contact_person   VARCHAR(120) NULL,
  credit_limit     DECIMAL(14,2) NOT NULL DEFAULT 0,
  credit_days      SMALLINT UNSIGNED NOT NULL DEFAULT 30,
  discount         DECIMAL(5,2) NOT NULL DEFAULT 0,
  price_tier       ENUM('retail','wholesale') NOT NULL DEFAULT 'retail',
  marketing_opt_in TINYINT(1) NOT NULL DEFAULT 1,
  notes            TEXT NULL,
  last_reminder_at DATETIME NULL,
  is_active        TINYINT(1) NOT NULL DEFAULT 1,
  created_by       INT UNSIGNED NULL,
  created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_customers_name (name),
  KEY idx_customers_phone (phone)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The ways a business can receive money (cash drawer, M-Pesa tills/paybills,
-- bank accounts...). show_on_documents controls the "Pay via" block on prints.
CREATE TABLE IF NOT EXISTS payment_accounts (
  id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  method             ENUM('cash','mpesa','bank','card','other') NOT NULL,
  name               VARCHAR(80) NOT NULL,
  provider           VARCHAR(80) NULL COMMENT 'Bank name / Safaricom',
  account_type       VARCHAR(30) NULL COMMENT 'till | paybill | pochi | current | savings',
  account_number     VARCHAR(60) NULL,
  account_name       VARCHAR(120) NULL,
  branch             VARCHAR(80) NULL,
  requires_reference TINYINT(1) NOT NULL DEFAULT 0,
  show_on_documents  TINYINT(1) NOT NULL DEFAULT 1,
  stk_enabled        TINYINT(1) NOT NULL DEFAULT 0,
  sort_order         SMALLINT NOT NULL DEFAULT 0,
  is_active          TINYINT(1) NOT NULL DEFAULT 1,
  created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         DATETIME NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- Marketing ----------------------------------------------
CREATE TABLE IF NOT EXISTS coupons (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code         VARCHAR(32) NOT NULL UNIQUE,
  description  VARCHAR(160) NULL,
  type         ENUM('percent','fixed') NOT NULL DEFAULT 'percent',
  value        DECIMAL(12,2) NOT NULL,
  min_spend    DECIMAL(14,2) NOT NULL DEFAULT 0,
  max_discount DECIMAL(14,2) NULL,
  max_uses     INT UNSIGNED NULL,
  used_count   INT UNSIGNED NOT NULL DEFAULT 0,
  customer_id  INT UNSIGNED NULL,
  starts_at    DATE NULL,
  ends_at      DATE NULL,
  is_active    TINYINT(1) NOT NULL DEFAULT 1,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS promotions (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name         VARCHAR(120) NOT NULL,
  type         ENUM('percent','fixed') NOT NULL DEFAULT 'percent',
  value        DECIMAL(12,2) NOT NULL,
  scope        ENUM('all','category','product') NOT NULL DEFAULT 'all',
  scope_id     INT UNSIGNED NULL,
  sale_type    ENUM('any','retail','wholesale') NOT NULL DEFAULT 'any',
  starts_at    DATE NULL,
  ends_at      DATE NULL,
  is_active    TINYINT(1) NOT NULL DEFAULT 1,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS campaigns (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name         VARCHAR(120) NOT NULL,
  channel      ENUM('email','sms') NOT NULL DEFAULT 'email',
  subject      VARCHAR(200) NULL,
  body         MEDIUMTEXT NOT NULL,
  audience     VARCHAR(30) NOT NULL DEFAULT 'all',
  audience_ids TEXT NULL,
  coupon_id    INT UNSIGNED NULL,
  status       ENUM('draft','queued','sent') NOT NULL DEFAULT 'draft',
  recipients   INT UNSIGNED NOT NULL DEFAULT 0,
  created_by   INT UNSIGNED NULL,
  queued_at    DATETIME NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS outbox (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  channel      ENUM('email','sms') NOT NULL DEFAULT 'email',
  kind         ENUM('campaign','debt_reminder','manual','document','system') NOT NULL DEFAULT 'manual',
  customer_id  INT UNSIGNED NULL,
  campaign_id  INT UNSIGNED NULL,
  recipient    VARCHAR(160) NOT NULL,
  recipient_name VARCHAR(120) NULL,
  subject      VARCHAR(200) NULL,
  body         MEDIUMTEXT NOT NULL,
  status       ENUM('queued','sent','failed') NOT NULL DEFAULT 'queued',
  attempts     TINYINT UNSIGNED NOT NULL DEFAULT 0,
  error        VARCHAR(255) NULL,
  created_by   INT UNSIGNED NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  sent_at      DATETIME NULL,
  KEY idx_outbox_status (status, id),
  KEY idx_outbox_customer (customer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- Register shifts -----------------------------------------
CREATE TABLE IF NOT EXISTS shifts (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id        INT UNSIGNED NOT NULL,
  store_id       INT UNSIGNED NOT NULL,
  opening_float  DECIMAL(14,2) NOT NULL DEFAULT 0,
  expected_cash  DECIMAL(14,2) NULL,
  counted_cash   DECIMAL(14,2) NULL,
  variance       DECIMAL(14,2) NULL,
  notes          VARCHAR(255) NULL,
  opened_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  closed_at      DATETIME NULL,
  KEY idx_shift_user (user_id, closed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- Sales ----------------------------------------------------
CREATE TABLE IF NOT EXISTS sales (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  invoice_no      VARCHAR(30) NOT NULL UNIQUE,
  client_ref      VARCHAR(40) NULL UNIQUE COMMENT 'Idempotency key from device (offline queue)',
  store_id        INT UNSIGNED NOT NULL,
  customer_id     INT UNSIGNED NULL,
  user_id         INT UNSIGNED NULL,
  shift_id        INT UNSIGNED NULL,
  quotation_id    INT UNSIGNED NULL,
  sale_type       ENUM('retail','wholesale') NOT NULL DEFAULT 'retail',
  subtotal        DECIMAL(14,2) NOT NULL DEFAULT 0,
  discount_total  DECIMAL(14,2) NOT NULL DEFAULT 0,
  coupon_id       INT UNSIGNED NULL,
  coupon_discount DECIMAL(14,2) NOT NULL DEFAULT 0,
  tax_total       DECIMAL(14,2) NOT NULL DEFAULT 0,
  shipping        DECIMAL(14,2) NOT NULL DEFAULT 0,
  total           DECIMAL(14,2) NOT NULL DEFAULT 0,
  cost_total      DECIMAL(14,2) NOT NULL DEFAULT 0,
  paid            DECIMAL(14,2) NOT NULL DEFAULT 0,
  returned_total  DECIMAL(14,2) NOT NULL DEFAULT 0,
  balance         DECIMAL(14,2) NOT NULL DEFAULT 0,
  change_given    DECIMAL(14,2) NOT NULL DEFAULT 0,
  payment_status  ENUM('paid','partial','credit') NOT NULL DEFAULT 'paid',
  status          ENUM('completed','returned','partially_returned','voided') NOT NULL DEFAULT 'completed',
  due_date        DATE NULL,
  notes           VARCHAR(255) NULL,
  void_reason     VARCHAR(255) NULL,
  voided_by       INT UNSIGNED NULL,
  sold_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_sales_sold (sold_at),
  KEY idx_sales_store (store_id, sold_at),
  KEY idx_sales_user (user_id, sold_at),
  KEY idx_sales_customer (customer_id, balance),
  KEY idx_sales_balance (balance)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sale_items (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  sale_id      INT UNSIGNED NOT NULL,
  product_id   INT UNSIGNED NOT NULL,
  name         VARCHAR(255) NOT NULL,
  qty          DECIMAL(14,3) NOT NULL,
  unit_cost    DECIMAL(14,2) NOT NULL DEFAULT 0,
  unit_price   DECIMAL(14,2) NOT NULL,
  discount     DECIMAL(14,2) NOT NULL DEFAULT 0,
  tax_rate     DECIMAL(5,2) NOT NULL DEFAULT 0,
  tax          DECIMAL(14,2) NOT NULL DEFAULT 0,
  total        DECIMAL(14,2) NOT NULL,
  returned_qty DECIMAL(14,3) NOT NULL DEFAULT 0,
  KEY idx_si_sale (sale_id),
  KEY idx_si_product (product_id),
  CONSTRAINT fk_si_sale FOREIGN KEY (sale_id) REFERENCES sales(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A sale can be settled by several payment lines (cash + mpesa + bank...).
-- Debt repayments are payment lines too (kind='debt'), grouped by receipt_no.
CREATE TABLE IF NOT EXISTS payments (
  id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  receipt_no         VARCHAR(30) NULL,
  kind               ENUM('sale','debt','refund') NOT NULL DEFAULT 'sale',
  sale_id            INT UNSIGNED NULL,
  customer_id        INT UNSIGNED NULL,
  payment_account_id INT UNSIGNED NULL,
  method             ENUM('cash','mpesa','bank','card','other') NOT NULL,
  amount             DECIMAL(14,2) NOT NULL,
  reference          VARCHAR(100) NULL,
  mpesa_tx_id        INT UNSIGNED NULL,
  store_id           INT UNSIGNED NULL,
  shift_id           INT UNSIGNED NULL,
  received_by        INT UNSIGNED NULL,
  is_void            TINYINT(1) NOT NULL DEFAULT 0,
  notes              VARCHAR(255) NULL,
  paid_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_pay_sale (sale_id),
  KEY idx_pay_customer (customer_id),
  KEY idx_pay_paid (paid_at),
  KEY idx_pay_receipt (receipt_no),
  KEY idx_pay_method (method, paid_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mpesa_transactions (
  id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  type                ENUM('stk','c2b') NOT NULL,
  merchant_request_id VARCHAR(64) NULL,
  checkout_request_id VARCHAR(64) NULL UNIQUE,
  phone               VARCHAR(20) NULL,
  amount              DECIMAL(14,2) NOT NULL DEFAULT 0,
  account_ref         VARCHAR(40) NULL,
  mpesa_receipt       VARCHAR(30) NULL UNIQUE,
  payer_name          VARCHAR(120) NULL,
  status              ENUM('pending','success','failed','cancelled') NOT NULL DEFAULT 'pending',
  result_code         VARCHAR(10) NULL,
  result_desc         VARCHAR(255) NULL,
  sale_id             INT UNSIGNED NULL,
  payment_id          INT UNSIGNED NULL,
  initiated_by        INT UNSIGNED NULL,
  raw                 JSON NULL,
  created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_mpesa_status (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS credit_notes (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  cn_no          VARCHAR(30) NOT NULL UNIQUE,
  sale_id        INT UNSIGNED NOT NULL,
  store_id       INT UNSIGNED NOT NULL,
  customer_id    INT UNSIGNED NULL,
  total          DECIMAL(14,2) NOT NULL DEFAULT 0,
  applied_to_balance DECIMAL(14,2) NOT NULL DEFAULT 0,
  refund_amount  DECIMAL(14,2) NOT NULL DEFAULT 0,
  refund_method  ENUM('cash','mpesa','bank','card','other','none') NOT NULL DEFAULT 'none',
  restock        TINYINT(1) NOT NULL DEFAULT 1,
  reason         VARCHAR(255) NULL,
  user_id        INT UNSIGNED NULL,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_cn_sale (sale_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS credit_note_items (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  credit_note_id INT UNSIGNED NOT NULL,
  sale_item_id   INT UNSIGNED NOT NULL,
  product_id     INT UNSIGNED NOT NULL,
  name           VARCHAR(255) NOT NULL,
  qty            DECIMAL(14,3) NOT NULL,
  unit_price     DECIMAL(14,2) NOT NULL,
  total          DECIMAL(14,2) NOT NULL,
  CONSTRAINT fk_cni_cn FOREIGN KEY (credit_note_id) REFERENCES credit_notes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS quotations (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  quote_no      VARCHAR(30) NOT NULL UNIQUE,
  store_id      INT UNSIGNED NOT NULL,
  customer_id   INT UNSIGNED NULL,
  user_id       INT UNSIGNED NULL,
  sale_type     ENUM('retail','wholesale') NOT NULL DEFAULT 'retail',
  subtotal      DECIMAL(14,2) NOT NULL DEFAULT 0,
  discount_total DECIMAL(14,2) NOT NULL DEFAULT 0,
  tax_total     DECIMAL(14,2) NOT NULL DEFAULT 0,
  shipping      DECIMAL(14,2) NOT NULL DEFAULT 0,
  total         DECIMAL(14,2) NOT NULL DEFAULT 0,
  status        ENUM('pending','converted','cancelled') NOT NULL DEFAULT 'pending',
  valid_until   DATE NULL,
  sale_id       INT UNSIGNED NULL,
  notes         VARCHAR(255) NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_q_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS quotation_items (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  quotation_id  INT UNSIGNED NOT NULL,
  product_id    INT UNSIGNED NOT NULL,
  name          VARCHAR(255) NOT NULL,
  qty           DECIMAL(14,3) NOT NULL,
  unit_price    DECIMAL(14,2) NOT NULL,
  discount      DECIMAL(14,2) NOT NULL DEFAULT 0,
  tax_rate      DECIMAL(5,2) NOT NULL DEFAULT 0,
  tax           DECIMAL(14,2) NOT NULL DEFAULT 0,
  total         DECIMAL(14,2) NOT NULL,
  CONSTRAINT fk_qi_q FOREIGN KEY (quotation_id) REFERENCES quotations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- Supply chain --------------------------------------------
CREATE TABLE IF NOT EXISTS suppliers (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code          VARCHAR(32) NULL,
  name          VARCHAR(120) NOT NULL,
  contact_person VARCHAR(120) NULL,
  phone         VARCHAR(32) NULL,
  email         VARCHAR(160) NULL,
  address       VARCHAR(255) NULL,
  kra_pin       VARCHAR(20) NULL,
  payment_terms VARCHAR(60) NULL,
  is_active     TINYINT(1) NOT NULL DEFAULT 1,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS purchases (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  grn_no        VARCHAR(30) NOT NULL UNIQUE,
  supplier_id   INT UNSIGNED NOT NULL,
  store_id      INT UNSIGNED NOT NULL,
  supplier_invoice VARCHAR(60) NULL,
  purchase_date DATE NOT NULL,
  subtotal      DECIMAL(14,2) NOT NULL DEFAULT 0,
  tax_total     DECIMAL(14,2) NOT NULL DEFAULT 0,
  shipping      DECIMAL(14,2) NOT NULL DEFAULT 0,
  total         DECIMAL(14,2) NOT NULL DEFAULT 0,
  paid          DECIMAL(14,2) NOT NULL DEFAULT 0,
  returned_total DECIMAL(14,2) NOT NULL DEFAULT 0,
  balance       DECIMAL(14,2) NOT NULL DEFAULT 0,
  notes         VARCHAR(255) NULL,
  user_id       INT UNSIGNED NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_pur_supplier (supplier_id, balance),
  KEY idx_pur_date (purchase_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS purchase_items (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  purchase_id   INT UNSIGNED NOT NULL,
  product_id    INT UNSIGNED NOT NULL,
  name          VARCHAR(255) NOT NULL,
  qty           DECIMAL(14,3) NOT NULL,
  bonus_qty     DECIMAL(14,3) NOT NULL DEFAULT 0,
  unit_cost     DECIMAL(14,2) NOT NULL,
  discount      DECIMAL(14,2) NOT NULL DEFAULT 0,
  tax           DECIMAL(14,2) NOT NULL DEFAULT 0,
  total         DECIMAL(14,2) NOT NULL,
  expiry_date   DATE NULL,
  returned_qty  DECIMAL(14,3) NOT NULL DEFAULT 0,
  CONSTRAINT fk_pi_p FOREIGN KEY (purchase_id) REFERENCES purchases(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS supplier_payments (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  voucher_no    VARCHAR(30) NULL,
  supplier_id   INT UNSIGNED NOT NULL,
  purchase_id   INT UNSIGNED NULL,
  payment_account_id INT UNSIGNED NULL,
  method        ENUM('cash','mpesa','bank','card','other') NOT NULL,
  amount        DECIMAL(14,2) NOT NULL,
  reference     VARCHAR(100) NULL,
  user_id       INT UNSIGNED NULL,
  paid_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_sp_supplier (supplier_id),
  KEY idx_sp_voucher (voucher_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS supplier_returns (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  return_no     VARCHAR(30) NOT NULL UNIQUE,
  purchase_id   INT UNSIGNED NOT NULL,
  supplier_id   INT UNSIGNED NOT NULL,
  store_id      INT UNSIGNED NOT NULL,
  total         DECIMAL(14,2) NOT NULL DEFAULT 0,
  reason        VARCHAR(255) NULL,
  user_id       INT UNSIGNED NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS supplier_return_items (
  id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_return_id INT UNSIGNED NOT NULL,
  product_id         INT UNSIGNED NOT NULL,
  name               VARCHAR(255) NOT NULL,
  qty                DECIMAL(14,3) NOT NULL,
  unit_cost          DECIMAL(14,2) NOT NULL,
  total              DECIMAL(14,2) NOT NULL,
  CONSTRAINT fk_sri FOREIGN KEY (supplier_return_id) REFERENCES supplier_returns(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- Inventory documents ------------------------------------
CREATE TABLE IF NOT EXISTS transfers (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  transfer_no   VARCHAR(30) NOT NULL UNIQUE,
  from_store_id INT UNSIGNED NOT NULL,
  to_store_id   INT UNSIGNED NOT NULL,
  status        ENUM('in_transit','received','cancelled') NOT NULL DEFAULT 'in_transit',
  notes         VARCHAR(255) NULL,
  created_by    INT UNSIGNED NULL,
  received_by   INT UNSIGNED NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  received_at   DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS transfer_items (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  transfer_id   INT UNSIGNED NOT NULL,
  product_id    INT UNSIGNED NOT NULL,
  name          VARCHAR(255) NOT NULL,
  qty           DECIMAL(14,3) NOT NULL,
  unit_cost     DECIMAL(14,2) NOT NULL DEFAULT 0,
  CONSTRAINT fk_ti FOREIGN KEY (transfer_id) REFERENCES transfers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stock_takes (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  take_no       VARCHAR(30) NOT NULL UNIQUE,
  store_id      INT UNSIGNED NOT NULL,
  notes         VARCHAR(255) NULL,
  variance_value DECIMAL(14,2) NOT NULL DEFAULT 0,
  user_id       INT UNSIGNED NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stock_take_items (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  stock_take_id INT UNSIGNED NOT NULL,
  product_id    INT UNSIGNED NOT NULL,
  name          VARCHAR(255) NOT NULL,
  system_qty    DECIMAL(14,3) NOT NULL,
  counted_qty   DECIMAL(14,3) NOT NULL,
  variance      DECIMAL(14,3) NOT NULL,
  unit_cost     DECIMAL(14,2) NOT NULL DEFAULT 0,
  CONSTRAINT fk_sti FOREIGN KEY (stock_take_id) REFERENCES stock_takes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS expenses (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  store_id      INT UNSIGNED NULL,
  category      VARCHAR(60) NOT NULL,
  description   VARCHAR(255) NULL,
  amount        DECIMAL(14,2) NOT NULL,
  payment_account_id INT UNSIGNED NULL,
  method        ENUM('cash','mpesa','bank','card','other') NOT NULL DEFAULT 'cash',
  reference     VARCHAR(100) NULL,
  spent_on      DATE NOT NULL,
  shift_id      INT UNSIGNED NULL,
  user_id       INT UNSIGNED NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_exp_date (spent_on)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ai_cache (
  cache_key     VARCHAR(80) PRIMARY KEY,
  payload       MEDIUMTEXT NOT NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
