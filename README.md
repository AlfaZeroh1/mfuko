# Mfuko POS v2

Point of sale built for African SMEs. It runs on a phone, tablet or desktop: M-Pesa, sales on credit, split payments, multi-store stock, one print engine, campaigns, and AI insights.
It is built with vanilla PHP 8.1+, MySQL 8 / MariaDB 10.5+, and plain CSS/JS. The only CDN libraries are Google Fonts and Chart.js on the dashboards.

## Install (new client)
1. Copy the folder to the web root (Apache or nginx; see `nginx.conf.example`).
2. Make `config/`, `uploads/` and `storage/` writable by the web server.
3. Open `https://your-host/install.php`. Enter the database details, business name, timezone (default **Africa/Nairobi**) and the owner account.
4. Add the cron worker. It sends email/SMS and automatic debt reminders:
   ```
   * * * * * php /var/www/mfuko-pos/tools/cron.php >> /var/www/mfuko-pos/storage/logs/cron.log 2>&1
   ```
5. In **Admin → Payment options**, add your tills, paybills and bank accounts. Then upload the logo in **Settings → Business**.

## Migrating an existing v1 ("pos2") client
```
# 1. create an empty DB for v2 and point config/.env at it (or run install.php)
# 2. rehearse without writing anything:
php tools/migrate_legacy.php --legacy-db=pos --legacy-user=root --legacy-pass=*** --legacy-path=/var/www/pos2 --dry-run
# 3. run it (re-runnable during demos with --fresh --yes):
php tools/migrate_legacy.php --legacy-db=pos --legacy-user=root --legacy-pass=*** --legacy-path=/var/www/pos2 --fresh --yes --copy-logo
```
- The legacy database is **only read**. v1 keeps working, so you can demo v2 side by side.
- IDs are kept, and invoice numbers continue from the last v1 invoice.
- Passwords carry over (both versions use bcrypt). Users set a new quick PIN under *My account*.
- Levels and rules become roles with mapped permissions. Departments become categories. `stock_track` becomes the stock ledger.
- Credit, cash and M-Pesa sales are rebuilt as payment lines, so debts and balances come out right.
- Company details and the "pay via" text from `app_config.json` become settings and a payment option.
- At the end, a verification table compares counts, sales value and stock units between the two systems.

## Architecture
```
index.php      front controller → pages/*.php inside app/views/layout.php
api.php        JSON API → app/api/<group>.php  (permission + CSRF checked centrally)
print.php      ONE print engine → app/print/Documents.php (data) + a4.php / thermal.php (layout)
mpesa_callback.php   Daraja callbacks (token protected)
app/core       DB, Auth, Perm, Audit, Settings, Sequence, Upload, helpers (+ inline SVG icons)
app/services   SaleService, Stock, Mpesa, Mailer (SMTP), Sms, Comms, Insights, Ai, Reports, …
app/resources.php   declarative CRUD (categories, stores, payment options, expenses, coupons, promotions…)
sql/schema.sql      DECIMAL money, stock ledger, audit log, outbox, M-Pesa transactions
```
- **Speed on the sell screen:** the product catalogue is cached on the device and refreshed with deltas. Search, pricing and promotions run locally. Icons are inline (no icon font). Checkout is one request. If the network drops, the sale is queued offline and synced later, with a duplicate guard (`client_ref`).
- **Payments:** a sale can have several payment lines (cash + M-Pesa + bank, with references). Whatever is left unpaid becomes credit for the selected customer, checked against their credit limit. Debt repayments are applied to the oldest invoices first.
- **Stock:** every change goes through `Stock::move()`. The `stock_movements` ledger always explains the current balance.
- **Timezone:** set in Settings. On every connection PHP and MySQL are aligned (`SET time_zone`), so receipts, reports and the navbar clock agree.
- **Security:** CSRF on every write, bcrypt, login throttling, sessions are httponly and SameSite, upload re-encoding, no script execution in `uploads/`, and an audit trail on every action.

## Roles & dashboards
| Role | Lands on | Dashboard |
|---|---|---|
| Owner / Manager | Dashboard | KPIs, revenue + 7-day forecast, payment mix, AI insights, who's online, busy-hours heatmap, activity |
| Cashier | **Sell** | My sales vs target, team rank, register shift, my payment mix, sales by hour |
| Storekeeper | Dashboard | Reorder list, fast movers, in-transit transfers, expiring stock |
| Accountant | Dashboard | P&L month to date, receivables ageing, payables, expenses, till variances |

Roles and permissions are editable under **Admin → Roles**.

## M-Pesa
The integration is ready to switch on: **Settings → M-Pesa** (consumer key/secret, shortcode, passkey, public URL), then **Test connection** and **Register C2B URLs**.
- STK push from the till (the cashier types the phone number, the customer enters their PIN, the sale gets the receipt automatically).
- C2B confirmations (customer pays the paybill directly) arrive as *unmatched* and can be applied to a debt in one click.
- Codes typed in by hand still work with no integration at all.

## Printing
Every document has both an **A4** and a **thermal (58/80 mm)** version: receipt/invoice, quotation, credit note, debt payment receipt, customer statement, debtors list, GRN, payment voucher, supplier return, delivery note, stock take, X/Z shift report, M-Pesa confirmation, price list and all reports.
The business logo appears on A4 and email versions only. Each document can also be emailed.
