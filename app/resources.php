<?php
/**
 * Declarative CRUD resources. One definition gives you: list + search +
 * paging API, create/edit modal, validation, soft delete and audit logging.
 *
 * Field types: text, textarea, number, money, email, tel, date, color, switch, select (options), ref (table)
 */
$methods = ['cash' => 'Cash', 'mpesa' => 'M-Pesa', 'bank' => 'Bank', 'card' => 'Card', 'other' => 'Other'];

return [
    'categories' => [
        'table' => 'categories', 'singular' => 'Category', 'icon' => 'layers',
        'perm' => 'products.manage', 'view_perm' => 'products.view',
        'fields' => [
            'name'        => ['label' => 'Name', 'type' => 'text', 'required' => true],
            'code'        => ['label' => 'Code', 'type' => 'text'],
            'color'       => ['label' => 'Colour on sell screen', 'type' => 'color', 'default' => '#C4502A'],
            'description' => ['label' => 'Description', 'type' => 'textarea', 'full' => true],
            'is_active'   => ['label' => 'Active', 'type' => 'switch', 'default' => 1],
        ],
        'columns' => ['name', 'code', 'products', 'is_active'],
        'computed' => ['products' => ['label' => 'Products', 'sql' => '(SELECT COUNT(*) FROM products p WHERE p.category_id=t.id)', 'num' => true]],
        'search' => ['name', 'code'], 'order' => 't.name', 'delete' => 'soft',
    ],

    'stores' => [
        'table' => 'stores', 'singular' => 'Store / branch', 'icon' => 'store', 'perm' => 'settings.manage',
        'fields' => [
            'name'      => ['label' => 'Name', 'type' => 'text', 'required' => true],
            'code'      => ['label' => 'Code', 'type' => 'text'],
            'phone'     => ['label' => 'Phone', 'type' => 'tel'],
            'address'   => ['label' => 'Address / location', 'type' => 'text'],
            'is_active' => ['label' => 'Active', 'type' => 'switch', 'default' => 1],
        ],
        'columns' => ['name', 'code', 'phone', 'address', 'is_active'],
        'search' => ['name', 'code', 'address'], 'order' => 't.name', 'delete' => 'soft',
    ],

    'payment_accounts' => [
        'table' => 'payment_accounts', 'singular' => 'Payment option', 'icon' => 'card', 'perm' => 'settings.manage', 'view_perm' => 'sell.create',
        'intro' => 'Every way you receive money: cash drawer, M-Pesa tills/paybills, bank accounts. Toggle “Show on documents” to print it in the “Pay via” box on invoices, statements and reminders.',
        'fields' => [
            'method'             => ['label' => 'Type', 'type' => 'select', 'options' => $methods, 'required' => true],
            'name'               => ['label' => 'Display name', 'type' => 'text', 'required' => true, 'placeholder' => 'e.g. Till 123456 / Equity Bank'],
            'provider'           => ['label' => 'Provider / bank', 'type' => 'text', 'placeholder' => 'Safaricom, Equity, KCB…'],
            'account_type'       => ['label' => 'Account type', 'type' => 'select', 'options' => ['' => '—', 'till' => 'Buy Goods Till', 'paybill' => 'Paybill', 'pochi' => 'Pochi la Biashara', 'send_money' => 'Send money (phone)', 'current' => 'Current account', 'savings' => 'Savings account']],
            'account_number'     => ['label' => 'Till / paybill / account no.', 'type' => 'text'],
            'account_name'       => ['label' => 'Account name / paybill account', 'type' => 'text'],
            'branch'             => ['label' => 'Bank branch', 'type' => 'text'],
            'sort_order'         => ['label' => 'Order', 'type' => 'number', 'default' => 0],
            'requires_reference' => ['label' => 'Require reference code at checkout', 'type' => 'switch', 'default' => 0],
            'show_on_documents'  => ['label' => 'Show on documents (Pay via)', 'type' => 'switch', 'default' => 1],
            'stk_enabled'        => ['label' => 'Allow M-Pesa STK push', 'type' => 'switch', 'default' => 0],
            'is_active'          => ['label' => 'Active', 'type' => 'switch', 'default' => 1],
        ],
        'columns' => ['method', 'name', 'account_number', 'show_on_documents', 'is_active'],
        'search' => ['name', 'account_number', 'provider'], 'order' => 't.sort_order, t.id', 'delete' => 'soft',
    ],

    'customers' => [
        'table' => 'customers', 'singular' => 'Customer', 'icon' => 'users', 'perm' => 'customers.manage', 'view_perm' => 'customers.view',
        'fields' => [
            'name'             => ['label' => 'Name', 'type' => 'text', 'required' => true],
            'phone'            => ['label' => 'Phone', 'type' => 'tel'],
            'email'            => ['label' => 'Email', 'type' => 'email'],
            'kra_pin'          => ['label' => 'KRA PIN', 'type' => 'text'],
            'contact_person'   => ['label' => 'Contact person', 'type' => 'text'],
            'address'          => ['label' => 'Address', 'type' => 'text'],
            'price_tier'       => ['label' => 'Price tier', 'type' => 'select', 'options' => ['retail' => 'Retail', 'wholesale' => 'Wholesale']],
            'discount'         => ['label' => 'Automatic discount %', 'type' => 'number', 'default' => 0],
            'credit_limit'     => ['label' => 'Credit limit (0 = no limit)', 'type' => 'money', 'default' => 0],
            'credit_days'      => ['label' => 'Credit days', 'type' => 'number', 'default' => 30],
            'marketing_opt_in' => ['label' => 'Receives offers & campaigns', 'type' => 'switch', 'default' => 1],
            'notes'            => ['label' => 'Notes', 'type' => 'textarea', 'full' => true],
            'is_active'        => ['label' => 'Active', 'type' => 'switch', 'default' => 1],
        ],
        'columns' => ['name', 'phone', 'email', 'debt', 'last_visit', 'is_active'],
        'computed' => [
            'debt' => ['label' => 'Owes', 'sql' => '(SELECT COALESCE(SUM(s.balance),0) FROM sales s WHERE s.customer_id=t.id AND s.status<>"voided")', 'money' => true],
            'last_visit' => ['label' => 'Last visit', 'sql' => '(SELECT MAX(s.sold_at) FROM sales s WHERE s.customer_id=t.id)', 'date' => true],
        ],
        'search' => ['name', 'phone', 'email', 'kra_pin'], 'order' => 't.name', 'delete' => 'soft', 'link' => 'customer',
    ],

    'suppliers' => [
        'table' => 'suppliers', 'singular' => 'Supplier', 'icon' => 'store', 'perm' => 'suppliers.manage', 'view_perm' => 'purchases.manage',
        'fields' => [
            'name'           => ['label' => 'Name', 'type' => 'text', 'required' => true],
            'code'           => ['label' => 'Code', 'type' => 'text'],
            'contact_person' => ['label' => 'Contact person', 'type' => 'text'],
            'phone'          => ['label' => 'Phone', 'type' => 'tel'],
            'email'          => ['label' => 'Email', 'type' => 'email'],
            'kra_pin'        => ['label' => 'KRA PIN', 'type' => 'text'],
            'payment_terms'  => ['label' => 'Payment terms', 'type' => 'text', 'placeholder' => 'e.g. 30 days'],
            'address'        => ['label' => 'Address', 'type' => 'text'],
            'is_active'      => ['label' => 'Active', 'type' => 'switch', 'default' => 1],
        ],
        'columns' => ['name', 'contact_person', 'phone', 'owed', 'is_active'],
        'computed' => ['owed' => ['label' => 'We owe', 'sql' => '(SELECT COALESCE(SUM(p.balance),0) FROM purchases p WHERE p.supplier_id=t.id)', 'money' => true]],
        'search' => ['name', 'code', 'phone', 'contact_person'], 'order' => 't.name', 'delete' => 'soft',
    ],

    'expenses' => [
        'table' => 'expenses', 'singular' => 'Expense', 'icon' => 'cash', 'perm' => 'expenses.manage',
        'fields' => [
            'spent_on'           => ['label' => 'Date', 'type' => 'date', 'required' => true, 'default' => '@today'],
            'category'           => ['label' => 'Category', 'type' => 'text', 'required' => true, 'list' => ['Rent', 'Salaries', 'Electricity', 'Water', 'Transport', 'Airtime & data', 'Repairs', 'Licences', 'Marketing', 'Supplies', 'Bank charges', 'Other']],
            'amount'             => ['label' => 'Amount', 'type' => 'money', 'required' => true],
            'method'             => ['label' => 'Paid with', 'type' => 'select', 'options' => $methods],
            'payment_account_id' => ['label' => 'From account', 'type' => 'ref', 'ref' => 'payment_accounts', 'optional' => true],
            'store_id'           => ['label' => 'Store', 'type' => 'ref', 'ref' => 'stores', 'optional' => true, 'default' => '@store'],
            'reference'          => ['label' => 'Reference', 'type' => 'text'],
            'description'        => ['label' => 'Description', 'type' => 'textarea', 'full' => true],
        ],
        'columns' => ['spent_on', 'category', 'description', 'method', 'amount'],
        'search' => ['category', 'description', 'reference'], 'order' => 't.spent_on DESC, t.id DESC', 'delete' => 'hard',
        'stamp_user' => 'user_id', 'sum' => 'amount',
    ],

    'coupons' => [
        'table' => 'coupons', 'singular' => 'Coupon', 'icon' => 'ticket', 'perm' => 'marketing.manage',
        'intro' => 'Codes customers redeem at checkout. Attach a coupon to a campaign to email it to your customers.',
        'fields' => [
            'code'         => ['label' => 'Code', 'type' => 'text', 'required' => true, 'upper' => true, 'placeholder' => 'KARIBU10'],
            'description'  => ['label' => 'Description', 'type' => 'text'],
            'type'         => ['label' => 'Type', 'type' => 'select', 'options' => ['percent' => 'Percent off', 'fixed' => 'Fixed amount off']],
            'value'        => ['label' => 'Value', 'type' => 'number', 'required' => true],
            'min_spend'    => ['label' => 'Minimum spend', 'type' => 'money', 'default' => 0],
            'max_discount' => ['label' => 'Maximum discount (blank = none)', 'type' => 'money', 'nullable' => true],
            'max_uses'     => ['label' => 'Max uses (blank = unlimited)', 'type' => 'number', 'nullable' => true],
            'customer_id'  => ['label' => 'Only for customer', 'type' => 'ref', 'ref' => 'customers', 'optional' => true],
            'starts_at'    => ['label' => 'Starts', 'type' => 'date', 'nullable' => true],
            'ends_at'      => ['label' => 'Ends', 'type' => 'date', 'nullable' => true],
            'is_active'    => ['label' => 'Active', 'type' => 'switch', 'default' => 1],
        ],
        'columns' => ['code', 'description', 'type', 'value', 'used_count', 'ends_at', 'is_active'],
        'search' => ['code', 'description'], 'order' => 't.id DESC', 'delete' => 'hard',
    ],

    'promotions' => [
        'table' => 'promotions', 'singular' => 'Promotion', 'icon' => 'tag', 'perm' => 'marketing.manage',
        'intro' => 'Automatic discounts applied on the sell screen — e.g. 10% off a category this weekend.',
        'fields' => [
            'name'      => ['label' => 'Name', 'type' => 'text', 'required' => true, 'placeholder' => 'Back to school week'],
            'type'      => ['label' => 'Type', 'type' => 'select', 'options' => ['percent' => 'Percent off', 'fixed' => 'Amount off per unit']],
            'value'     => ['label' => 'Value', 'type' => 'number', 'required' => true],
            'scope'     => ['label' => 'Applies to', 'type' => 'select', 'options' => ['all' => 'All products', 'category' => 'A category', 'product' => 'A product']],
            'scope_id'  => ['label' => 'Category / product id', 'type' => 'number', 'nullable' => true, 'hint' => 'Category or product ID when not “All products”'],
            'sale_type' => ['label' => 'Sale type', 'type' => 'select', 'options' => ['any' => 'Retail & wholesale', 'retail' => 'Retail only', 'wholesale' => 'Wholesale only']],
            'starts_at' => ['label' => 'Starts', 'type' => 'date', 'nullable' => true],
            'ends_at'   => ['label' => 'Ends', 'type' => 'date', 'nullable' => true],
            'is_active' => ['label' => 'Active', 'type' => 'switch', 'default' => 1],
        ],
        'columns' => ['name', 'type', 'value', 'scope', 'starts_at', 'ends_at', 'is_active'],
        'search' => ['name'], 'order' => 't.id DESC', 'delete' => 'hard',
    ],
];
