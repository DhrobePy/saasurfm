<?php
/**
 * Chairman's Report Center
 *
 * Built 4 Sep 2026 per explicit request: one page to produce comprehensive,
 * date-ranged, professionally-tabulated PDF/CSV reports for the chairman —
 * completed sales, receivables, customer-wise activity, collections, products
 * sold, user-wise actions, expenses, modifications & amendments, transports,
 * exceptions & corrections, and a day-wise reconciliation summary.
 *
 * Each report type is ONE query-building case in buildReport() below, shared
 * by the on-screen preview, the PDF (core/functions/chairman_report_pdf.php —
 * same proven table/header/footer pattern as the existing Sales Report and
 * Daily Activity Report PDFs), and the CSV export — so all three can never
 * disagree. Receivables reuses the exact bucket definition already
 * established in cr/ageing_report.php; Customer-wise and Receivables both use
 * the live ledger-truth balance formula (never the stored current_balance
 * column) established/fixed earlier this session, so this hub can't disagree
 * with the Customer Ledger page or the balance reconciliation tool.
 */
require_once '../core/init.php';
require_once '../core/functions/chairman_report_pdf.php';

$allowed_roles = ['Superadmin', 'admin', 'Accounts'];
restrict_access($allowed_roles, 'admin', 'chairman_reports');

global $db;
$currentUser = getCurrentUser();
$pageTitle   = "Chairman's Report Center";

$REPORT_TYPES = [
    'completed_sales'      => 'Completed Sales',
    'receivables'           => 'Receivables',
    'customer_wise'         => 'Customer-wise Summary',
    'collections'            => 'Collections',
    'products_sold'          => 'Products Sold',
    'user_actions'            => 'User-wise Actions',
    'expenses'                 => 'Expenses',
    'amendments'                => 'Modifications & Amendments',
    'transports'                 => 'Transports',
    'exceptions'                  => 'Exceptions & Corrections',
    'daily_reconciliation'         => 'Day-wise Reconciliation',
    // Purchase-side reports, added 4 Sep 2026 per explicit request — the
    // purchase module's real live tables all carry an "_adnan" suffix
    // (purchase_orders_adnan, goods_received_adnan, purchase_payments_adnan
    // — confirmed by grepping the live purchase/*.php pages' own queries,
    // not guessed from table names), a completely separate schema from the
    // sales side (wheat/commodity procurement, not flour SKUs).
    'purchase_orders'               => 'Purchase Orders',
    'goods_received'                 => 'Goods Received (GRN)',
    'supplier_payments'               => 'Supplier Payments',
];

// ── Date range with quick presets ────────────────────────────────────────
$preset = $_GET['preset'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to   = $_GET['date_to']   ?? '';
if ($preset || !$date_from || !$date_to) {
    switch ($preset ?: 'this_month') {
        case 'today':
            $date_from = $date_to = date('Y-m-d');
            break;
        case 'this_week':
            $date_from = date('Y-m-d', strtotime('monday this week'));
            $date_to   = date('Y-m-d');
            break;
        case 'this_quarter':
            $q_start_month = (intval(date('n') - 1) - (intval(date('n') - 1) % 3)) + 1;
            $date_from = date('Y-' . str_pad((string)$q_start_month, 2, '0', STR_PAD_LEFT) . '-01');
            $date_to   = date('Y-m-d');
            break;
        case 'this_month':
        default:
            $date_from = date('Y-m-01');
            $date_to   = date('Y-m-d');
    }
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) $date_from = date('Y-m-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to))   $date_to   = date('Y-m-d');
if ($date_from > $date_to) { [$date_from, $date_to] = [$date_to, $date_from]; }

$branch_id   = (int)($_GET['branch_id'] ?? 0);
$report_type = array_key_exists($_GET['report_type'] ?? '', $REPORT_TYPES) ? $_GET['report_type'] : 'completed_sales';
$period_label = date('d M Y', strtotime($date_from)) . ' — ' . date('d M Y', strtotime($date_to));
$branches = $db->query("SELECT id, name FROM branches WHERE status='active' ORDER BY name")->results();
$branch_label = $branch_id ? (($branches ? array_values(array_filter($branches, fn($b) => $b->id == $branch_id))[0]->name ?? 'All Branches' : 'All Branches')) : 'All Branches';

// ══════════════════════════════════════════════════════════════════════════
// buildReport() — the single source of truth every export format reads from
// ══════════════════════════════════════════════════════════════════════════
function buildReport($db, string $type, string $date_from, string $date_to, int $branch_id): array {
    $branch_sql_co = $branch_id ? " AND co.assigned_branch_id = " . (int)$branch_id : '';
    $branch_sql_ev = $branch_id ? " AND ev.branch_id = " . (int)$branch_id : '';

    switch ($type) {

    // ── 1. Completed Sales — full order story, tabulated & merged ───────
    // History: first built as free-form per-order "dossier" blocks, then
    // flattened to a fully-repeated one-row-per-item table per instruction
    // ("tabulated, column wise"), then — 4 Sep 2026 — asked to add payment
    // method/account/date and to visually MERGE the order-level columns
    // across an order's item rows (like a spreadsheet rowspan) instead of
    // repeating them on every line, with landscape/centered/filled/wrapped
    // cell styling. Returns BOTH shapes from one query pass: a flat
    // 'headers'/'rows' (unaffected, used by CSV — merging a spreadsheet
    // export doesn't help anyone, repeated values are the normal/expected
    // CSV convention) and a grouped 'groups' shape (used by the PDF/HTML
    // renderers to actually draw the merge).
    case 'completed_sales': {
        $orders = $db->query(
            "SELECT co.id, co.order_number, co.order_date, co.total_amount, co.amount_paid, co.balance_due,
                    c.name AS customer_name, cos.truck_number, cos.driver_name, cos.shipped_date, cos.delivered_date
             FROM credit_orders co
             JOIN customers c ON c.id = co.customer_id
             LEFT JOIN branches b ON b.id = co.assigned_branch_id
             LEFT JOIN credit_order_shipping cos ON cos.order_id = co.id
             WHERE co.status = 'delivered'
               AND COALESCE(DATE(cos.delivered_date), co.order_date) BETWEEN ? AND ?
               {$branch_sql_co}
             ORDER BY COALESCE(cos.delivered_date, co.order_date), co.order_number",
            [$date_from, $date_to]
        )->results();

        $rows = []; $groups = []; $t = ['total'=>0,'paid'=>0,'due'=>0];
        $shortDate = fn($at) => $at ? date('d M', strtotime($at)) : '-';
        foreach ($orders as $o) {
            $items = $db->query(
                "SELECT p.base_name, pv.grade, pv.weight_variant, coi.quantity, coi.unit_price, coi.line_total
                 FROM credit_order_items coi
                 JOIN products p ON p.id = coi.product_id
                 LEFT JOIN product_variants pv ON pv.id = coi.variant_id
                 WHERE coi.order_id = ?", [$o->id]
            )->results();

            $wf = $db->query(
                "SELECT w.action, w.performed_at, u.display_name AS by_whom
                 FROM credit_order_workflow w LEFT JOIN users u ON u.id = w.performed_by_user_id
                 WHERE w.order_id = ? ORDER BY w.id", [$o->id]
            )->results();
            $milestone = fn($actions) => array_values(array_filter($wf, fn($w) => in_array($w->action, (array)$actions)))[0] ?? null;
            $approved  = $milestone(['approved']);
            $prod_done = $milestone(['complete_production']);

            // Payments allocated to this order — an order can have more than
            // one (partial payments); join distinct methods/accounts, use the
            // latest payment date as the representative "Payment Date".
            // Sourced from payment_allocations (order_id, allocated_amount),
            // not the cp.allocated_to_invoices JSON column — that column is a
            // denormalized snapshot that can carry zero-allocation entries
            // (an order considered but not actually paid by that receipt);
            // payment_allocations is only ever written for amount > 0, so it's
            // the reliable source (see cr/customer_payment.php's insert).
            $payments = $db->query(
                "SELECT cp.payment_method, cp.payment_date, ba.account_name, ba.bank_name
                 FROM payment_allocations pa
                 JOIN customer_payments cp ON cp.id = pa.payment_id
                 LEFT JOIN bank_accounts ba ON ba.id = cp.bank_account_id
                 WHERE pa.order_id = ?
                 ORDER BY cp.payment_date", [(int)$o->id]
            )->results();
            $methods = array_values(array_unique(array_map(fn($p) => $p->payment_method ?: '-', $payments)));
            $accounts = array_values(array_unique(array_map(fn($p) => $p->payment_method === 'Cash' ? 'Cash' : ($p->account_name ? $p->account_name . ($p->bank_name ? ' (' . $p->bank_name . ')' : '') : '-'), $payments)));
            $payment_method_col = $payments ? implode(', ', $methods) : '-';
            $paid_to_col        = $payments ? implode(', ', $accounts) : '-';
            $payment_date_col   = $payments ? $shortDate(end($payments)->payment_date) : '-';

            $production_col = $milestone(['start_production'])
                ? ($shortDate($milestone(['start_production'])->performed_at) . ' -> ' . $shortDate($prod_done->performed_at ?? null))
                : $shortDate($prod_done->performed_at ?? null);
            $shipment_col = trim(($o->truck_number ?? '') . ($o->driver_name ? ' / ' . $o->driver_name : '')) ?: '-';

            $orderCells = [
                $o->order_number, date('d M', strtotime($o->order_date)), $o->customer_name,
                $approved->by_whom ?? '-', $production_col, $shipment_col,
                $shortDate($o->shipped_date), $shortDate($o->delivered_date),
                $payment_method_col, $paid_to_col, $payment_date_col,
                ChairmanReportPdf::money((float)$o->total_amount), ChairmanReportPdf::money((float)$o->amount_paid), ChairmanReportPdf::money((float)$o->balance_due),
            ];

            $itemSource = $items ?: [(object)['base_name'=>'-','grade'=>'','weight_variant'=>'','quantity'=>null,'unit_price'=>null,'line_total'=>null]];
            $itemCells = [];
            foreach ($itemSource as $it) {
                $itemCells[] = [
                    $it->base_name,
                    trim(($it->grade ?? '') . ' ' . ($it->weight_variant ?? '')) ?: '-',
                    $it->quantity !== null ? number_format((float)$it->quantity, 2) : '-',
                    $it->unit_price !== null ? ChairmanReportPdf::money((float)$it->unit_price) : '-',
                    $it->line_total !== null ? ChairmanReportPdf::money((float)$it->line_total) : '-',
                ];
            }
            $groups[] = ['order' => $orderCells, 'items' => $itemCells];

            foreach ($itemCells as $idx => $ic) {
                $flat = array_slice($orderCells, 0, 3); // Order#, Date, Customer
                $flat = array_merge($flat, $ic, array_slice($orderCells, 3)); // items, then the rest of order cells
                // Order total/paid/due (last 3 of $orderCells) only on the last item row.
                if ($idx !== count($itemCells) - 1) {
                    $n = count($flat);
                    $flat[$n-1] = ''; $flat[$n-2] = ''; $flat[$n-3] = '';
                }
                $rows[] = $flat;
            }
            $t['total'] += (float)$o->total_amount; $t['paid'] += (float)$o->amount_paid; $t['due'] += (float)$o->balance_due;
        }

        $noteText = 'One row per item; order-level fields (approval, production, shipment, payment, delivery, totals) repeat per item in the CSV, and are merged into one spanning cell per order in the PDF/preview.'
            . (empty($rows) ? ' No orders were completed (delivered) in this period.' : (' Totals for the period: ' . count($orders) . ' orders, '
            . ChairmanReportPdf::money($t['total']) . ' total, ' . ChairmanReportPdf::money($t['paid']) . ' paid, ' . ChairmanReportPdf::money($t['due']) . ' due.'));

        return [
            'headers' => ['Order #', 'Date', 'Customer', 'Product', 'Variant', 'Qty', 'Price', 'Line Total',
                'Approved By', 'Production (Start->Done)', 'Truck / Driver', 'Shipped', 'Delivered',
                'Payment Method', 'Paid To Account', 'Payment Date', 'Order Total', 'Paid', 'Due'],
            'widths'  => [14, 10, 20, 26, 14, 12, 16, 18, 14, 16, 16, 10, 10, 13, 14, 11, 13, 12, 12],
            'aligns'  => ['C','C','L','L','C','C','R','R','C','C','L','C','C','C','L','C','R','R','R'],
            'rows' => $rows, 'totalRow' => null, 'fontSize' => 6.5,
            'note' => $noteText,
            'merged' => [
                'order_headers' => ['Order #', 'Date', 'Customer', 'Approved By', 'Production', 'Truck / Driver', 'Shipped', 'Delivered', 'Payment Method', 'Paid To Account', 'Payment Date', 'Order Total', 'Paid', 'Due'],
                'order_widths'  => [19, 10, 19, 13, 15, 15, 8, 8, 13, 14, 11, 13, 12, 12],
                'order_aligns'  => ['C','C','L','C','C','L','C','C','C','L','C','R','R','R'],
                'item_headers'  => ['Product', 'Variant', 'Qty', 'Price', 'Line Total'],
                'item_widths'   => [26, 14, 12, 16, 18],
                'item_aligns'   => ['L','C','R','R','R'],
                'groups' => $groups,
            ],
        ];
    }

    // ── 2. Receivables (as-of the end of the range — a snapshot, not a period sum) ──
    case 'receivables': {
        $as_of = $date_to;
        $branch_sql = $branch_id ? " AND co.assigned_branch_id = " . (int)$branch_id : '';
        $rows_raw = $db->query(
            "SELECT c.name AS customer_name, c.credit_limit,
                    SUM(CASE WHEN DATEDIFF(?, co.order_date) BETWEEN 0  AND 30  THEN co.balance_due ELSE 0 END) AS b0_30,
                    SUM(CASE WHEN DATEDIFF(?, co.order_date) BETWEEN 31 AND 60  THEN co.balance_due ELSE 0 END) AS b31_60,
                    SUM(CASE WHEN DATEDIFF(?, co.order_date) BETWEEN 61 AND 90  THEN co.balance_due ELSE 0 END) AS b61_90,
                    SUM(CASE WHEN DATEDIFF(?, co.order_date) > 90                THEN co.balance_due ELSE 0 END) AS b90_plus,
                    SUM(co.balance_due) AS total_due
             FROM customers c
             JOIN credit_orders co ON co.customer_id = c.id
             WHERE co.balance_due > 0.01 AND co.status NOT IN ('cancelled','rejected','draft')
               AND c.customer_type = 'Credit' AND c.status = 'active' {$branch_sql}
             GROUP BY c.id
             HAVING total_due > 0.01
             ORDER BY total_due DESC",
            [$as_of, $as_of, $as_of, $as_of]
        )->results();
        $rows = []; $t = ['limit'=>0,'b0'=>0,'b31'=>0,'b61'=>0,'b90'=>0,'total'=>0];
        foreach ($rows_raw as $r) {
            $rows[] = [
                $r->customer_name, ChairmanReportPdf::money((float)$r->credit_limit),
                ChairmanReportPdf::money((float)$r->b0_30), ChairmanReportPdf::money((float)$r->b31_60),
                ChairmanReportPdf::money((float)$r->b61_90), ChairmanReportPdf::money((float)$r->b90_plus),
                ChairmanReportPdf::money((float)$r->total_due),
            ];
            $t['limit']+=(float)$r->credit_limit; $t['b0']+=(float)$r->b0_30; $t['b31']+=(float)$r->b31_60;
            $t['b61']+=(float)$r->b61_90; $t['b90']+=(float)$r->b90_plus; $t['total']+=(float)$r->total_due;
        }
        return [
            'headers' => ['Customer', 'Credit Limit', '0-30d', '31-60d', '61-90d', '90d+', 'Total Due'],
            'widths'  => [55, 32, 32, 32, 32, 32, 35], 'aligns' => ['L','R','R','R','R','R','R'],
            'rows' => $rows, 'totalRow' => ['Total', ChairmanReportPdf::money($t['limit']), ChairmanReportPdf::money($t['b0']),
                ChairmanReportPdf::money($t['b31']), ChairmanReportPdf::money($t['b61']), ChairmanReportPdf::money($t['b90']), ChairmanReportPdf::money($t['total'])],
            'note' => 'Snapshot as of ' . date('d M Y', strtotime($as_of)) . ' (end of the selected period), same aging buckets as the Customer Credit Ageing Report.' . (empty($rows) ? ' No outstanding receivables.' : ''),
        ];
    }

    // ── 3. Customer-wise Summary ────────────────────────────────────────
    case 'customer_wise': {
        $rows_raw = $db->query(
            "SELECT c.id, c.name, c.initial_due, c.current_balance,
                    (SELECT COUNT(*) FROM credit_orders co WHERE co.customer_id=c.id AND co.order_date BETWEEN ? AND ? AND co.status NOT IN ('draft','cancelled','rejected')) AS order_count,
                    (SELECT COALESCE(SUM(co.total_amount),0) FROM credit_orders co WHERE co.customer_id=c.id AND co.order_date BETWEEN ? AND ? AND co.status NOT IN ('draft','cancelled','rejected')) AS sales_amount,
                    (SELECT COALESCE(SUM(cp.amount),0) FROM customer_payments cp WHERE cp.customer_id=c.id AND cp.payment_date BETWEEN ? AND ?) AS collected_amount,
                    (SELECT COALESCE(SUM(cl.debit_amount),0) - COALESCE(SUM(cl.credit_amount),0) FROM customer_ledger cl WHERE cl.customer_id=c.id AND cl.reference_type != 'initial_due') AS ledger_net
             FROM customers c
             WHERE c.customer_type='Credit' AND c.status='active'
             HAVING order_count > 0 OR collected_amount > 0
             ORDER BY sales_amount DESC",
            [$date_from, $date_to, $date_from, $date_to, $date_from, $date_to]
        )->results();
        $rows = []; $t = ['orders'=>0,'sales'=>0,'collected'=>0,'balance'=>0];
        foreach ($rows_raw as $r) {
            $true_balance = (float)$r->initial_due + (float)$r->ledger_net;
            $rows[] = [
                $r->name, (string)$r->order_count, ChairmanReportPdf::money((float)$r->sales_amount),
                ChairmanReportPdf::money((float)$r->collected_amount), ChairmanReportPdf::money($true_balance),
            ];
            $t['orders']+=(int)$r->order_count; $t['sales']+=(float)$r->sales_amount;
            $t['collected']+=(float)$r->collected_amount; $t['balance']+=$true_balance;
        }
        return [
            'headers' => ['Customer', 'Orders', 'Sales (period)', 'Collected (period)', 'Current Balance'],
            'widths'  => [70, 25, 45, 45, 45], 'aligns' => ['L','C','R','R','R'],
            'rows' => $rows, 'totalRow' => ['Total', (string)$t['orders'], ChairmanReportPdf::money($t['sales']), ChairmanReportPdf::money($t['collected']), ChairmanReportPdf::money($t['balance'])],
            'note' => 'Current Balance is the live ledger-true figure (same formula as Customer Ledger), not the cached column — includes activity outside this period too.' . (empty($rows) ? ' No customer activity in this period.' : ''),
        ];
    }

    // ── 4. Collections — every recorded field, added 4 Sep 2026 per explicit
    // "add every possible fields" request. customer_payments has several
    // columns that are always empty in this database — receipt_number,
    // collected_by_employee_id, cash_account_id, branch_id, and the stale
    // allocation_status/allocated_amount pair (payment_allocations is the
    // real, live-maintained source, confirmed earlier today) — those are
    // deliberately left OUT rather than shown as a permanently-blank column,
    // consistent with this session's "don't show what doesn't actually work"
    // principle (the same one behind the cosmetic-toggle badges). Everything
    // else customer_payments actually carries is included. 15 columns won't
    // fit unwrapped on a landscape page, so this uses the new wrappedTable()
    // (wrap/fill/center, no merge needed — collection rows aren't
    // hierarchical like orders/items).
    case 'collections': {
        $rows_raw = $db->query(
            "SELECT cp.id, cp.payment_number, cp.payment_date, cp.payment_type, cp.payment_method,
                    cp.cheque_number, cp.cheque_date, cp.bank_transaction_type, cp.reference_number,
                    cp.amount, cp.notes, c.name AS customer_name, u.display_name AS recorded_by,
                    ba.account_name, ba.bank_name,
                    (SELECT GROUP_CONCAT(co.order_number SEPARATOR ', ')
                       FROM payment_allocations pa JOIN credit_orders co ON co.id = pa.order_id
                       WHERE pa.payment_id = cp.id) AS allocated_orders,
                    (SELECT COALESCE(SUM(pa.allocated_amount), 0)
                       FROM payment_allocations pa WHERE pa.payment_id = cp.id) AS allocated_amount
             FROM customer_payments cp
             JOIN customers c ON c.id = cp.customer_id
             LEFT JOIN users u ON u.id = cp.created_by_user_id
             LEFT JOIN bank_accounts ba ON ba.id = cp.bank_account_id
             WHERE cp.payment_date BETWEEN ? AND ?
             ORDER BY cp.payment_date, cp.payment_number",
            [$date_from, $date_to]
        )->results();
        $rows = []; $total = 0; $t_alloc = 0; $t_unapplied = 0;
        $by_method = []; $by_type = [];
        foreach ($rows_raw as $r) {
            $amount = (float)$r->amount;
            $allocated = (float)$r->allocated_amount;
            $unapplied = max(0, $amount - $allocated);
            $paid_to = $r->payment_method === 'Cash' ? 'Cash'
                : ($r->account_name ? $r->account_name . ($r->bank_name ? ' (' . $r->bank_name . ')' : '') : '-');
            $rows[] = [
                $r->payment_number, date('d M Y', strtotime($r->payment_date)), $r->customer_name,
                $r->payment_type === 'advance' ? 'Advance' : 'Invoice Payment',
                $r->payment_method ?? '-', $paid_to,
                $r->cheque_number ?: '-', $r->cheque_date ? date('d M Y', strtotime($r->cheque_date)) : '-',
                $r->bank_transaction_type ?: '-', $r->reference_number ?: '-',
                ChairmanReportPdf::money($amount),
                $r->allocated_orders ?: '-', ChairmanReportPdf::money($allocated), ChairmanReportPdf::money($unapplied),
                $r->recorded_by ?? 'System', $r->notes ?: '-',
            ];
            $total += $amount; $t_alloc += $allocated; $t_unapplied += $unapplied;
            $by_method[$r->payment_method ?? 'Other'] = ($by_method[$r->payment_method ?? 'Other'] ?? 0) + $amount;
            $tk = $r->payment_type === 'advance' ? 'Advance' : 'Invoice Payment';
            $by_type[$tk] = ($by_type[$tk] ?? 0) + $amount;
        }
        $note = empty($rows) ? 'No collections recorded in this period.' : (
            'By method: ' . implode(', ', array_map(fn($k,$v) => "{$k} " . ChairmanReportPdf::money($v), array_keys($by_method), $by_method))
            . '  |  By type: ' . implode(', ', array_map(fn($k,$v) => "{$k} " . ChairmanReportPdf::money($v), array_keys($by_type), $by_type))
            . '  |  Total unapplied/advance balance sitting on these receipts: ' . ChairmanReportPdf::money($t_unapplied)
        );
        return [
            'headers' => ['Receipt #', 'Date', 'Customer', 'Type', 'Method', 'Paid To Account', 'Cheque #', 'Cheque Date',
                'Bank Txn Type', 'Reference #', 'Amount', 'Allocated To Orders', 'Allocated Amt', 'Unapplied', 'Recorded By', 'Notes'],
            'widths'  => [22, 14, 22, 14, 16, 22, 10, 12, 14, 16, 16, 22, 16, 18, 18, 19],
            'aligns'  => ['L','C','L','C','C','L','C','C','C','C','R','L','R','R','L','L'],
            'rows' => $rows,
            'totalRow' => ['', '', '', '', '', '', '', '', '', 'Total', ChairmanReportPdf::money($total), '', ChairmanReportPdf::money($t_alloc), ChairmanReportPdf::money($t_unapplied), '', ''],
            'note' => $note, 'fontSize' => 7, 'wrapped' => true,
        ];
    }

    // ── 5. Products Sold ─────────────────────────────────────────────────
    case 'products_sold': {
        $branch_sql = $branch_id ? " AND co.assigned_branch_id = " . (int)$branch_id : '';
        $rows_raw = $db->query(
            "SELECT p.base_name, pv.grade, pv.weight_variant, SUM(coi.quantity) AS qty, SUM(coi.line_total) AS revenue
             FROM credit_order_items coi
             JOIN credit_orders co ON co.id = coi.order_id
             JOIN products p ON p.id = coi.product_id
             LEFT JOIN product_variants pv ON pv.id = coi.variant_id
             WHERE co.status = 'delivered' AND co.order_date BETWEEN ? AND ? {$branch_sql}
             GROUP BY p.id, pv.id
             ORDER BY revenue DESC",
            [$date_from, $date_to]
        )->results();
        $rows = []; $t_qty = 0; $t_rev = 0;
        foreach ($rows_raw as $r) {
            $variant = trim(($r->grade ?? '') . ' ' . ($r->weight_variant ?? ''));
            $rows[] = [$r->base_name, $variant ?: '—', number_format((float)$r->qty, 2), ChairmanReportPdf::money((float)$r->revenue)];
            $t_qty += (float)$r->qty; $t_rev += (float)$r->revenue;
        }
        return [
            'headers' => ['Product', 'Variant', 'Quantity', 'Revenue'],
            'widths'  => [90, 60, 40, 47], 'aligns' => ['L','L','R','R'],
            'rows' => $rows, 'totalRow' => ['', 'Total', number_format($t_qty, 2), ChairmanReportPdf::money($t_rev)],
            'note' => empty($rows) ? 'No products delivered in this period.' : null,
        ];
    }

    // ── 6. User-wise Actions ─────────────────────────────────────────────
    case 'user_actions': {
        $rows_raw = $db->query(
            "SELECT u.display_name, u.role, sal.module, COUNT(*) AS action_count, MAX(sal.created_at) AS last_activity
             FROM system_audit_log sal
             LEFT JOIN users u ON u.id = sal.user_id
             WHERE DATE(sal.created_at) BETWEEN ? AND ?
             GROUP BY sal.user_id, sal.module
             ORDER BY u.display_name, action_count DESC",
            [$date_from, $date_to]
        )->results();
        $rows = []; $total = 0;
        foreach ($rows_raw as $r) {
            $rows[] = [$r->display_name ?? 'System', $r->role ?? '—', $r->module ?? '—', (string)$r->action_count, date('d M Y, g:i A', strtotime($r->last_activity))];
            $total += (int)$r->action_count;
        }
        return [
            'headers' => ['User', 'Role', 'Module', 'Actions', 'Last Activity'],
            'widths'  => [55, 45, 45, 30, 62], 'aligns' => ['L','L','L','C','L'],
            'rows' => $rows, 'totalRow' => ['', '', '', (string)$total, 'Total actions'],
            'note' => 'Source: system-wide audit log — the general activity trail, not the order-specific workflow log used by the Modifications & Amendments report.' . (empty($rows) ? ' No audit-logged activity in this period.' : ''),
        ];
    }

    // ── 7. Expenses ───────────────────────────────────────────────────────
    case 'expenses': {
        $rows_raw = $db->query(
            "SELECT ev.voucher_number, ev.expense_date, ec.category_name, b.name AS branch_name, ev.total_amount, ev.status, approver.display_name AS approved_by
             FROM expense_vouchers ev
             LEFT JOIN expense_categories ec ON ec.id = ev.category_id
             LEFT JOIN branches b ON b.id = ev.branch_id
             LEFT JOIN users approver ON approver.id = ev.approved_by_user_id
             WHERE ev.expense_date BETWEEN ? AND ? AND ev.status != 'cancelled' {$branch_sql_ev}
             ORDER BY ev.expense_date, ev.voucher_number",
            [$date_from, $date_to]
        )->results();
        $rows = []; $total = 0;
        foreach ($rows_raw as $r) {
            $rows[] = [$r->voucher_number, date('d M Y', strtotime($r->expense_date)), $r->category_name ?? '—', $r->branch_name ?? '—', ucfirst($r->status), ChairmanReportPdf::money((float)$r->total_amount)];
            if ($r->status === 'approved') $total += (float)$r->total_amount;
        }
        return [
            'headers' => ['Voucher #', 'Date', 'Category', 'Branch', 'Status', 'Amount'],
            'widths'  => [40, 28, 50, 40, 32, 47], 'aligns' => ['L','L','L','L','L','R'],
            'rows' => $rows, 'totalRow' => ['', '', '', '', 'Total (approved)', ChairmanReportPdf::money($total)],
            'note' => empty($rows) ? 'No expense vouchers in this period.' : null,
        ];
    }

    // ── 8. Modifications & Amendments ───────────────────────────────────
    case 'amendments': {
        $rows = []; $total_delta = 0;
        $amds = $db->query(
            "SELECT oa.amd_number, oa.created_at, co.order_number, oa.amendment_type, oa.delta_amount, oa.reason, u.display_name AS by_whom
             FROM order_amendments oa
             JOIN credit_orders co ON co.id = oa.order_id
             LEFT JOIN users u ON u.id = oa.requested_by_user_id
             WHERE DATE(oa.created_at) BETWEEN ? AND ? AND oa.status = 'approved'
             ORDER BY oa.created_at", [$date_from, $date_to]
        )->results();
        foreach ($amds as $a) {
            $rows[] = [date('d M Y', strtotime($a->created_at)), $a->order_number, ucwords(str_replace('_',' ',$a->amendment_type)),
                ChairmanReportPdf::money((float)$a->delta_amount), substr($a->reason ?? '', 0, 40), $a->by_whom ?? '—'];
            $total_delta += (float)$a->delta_amount;
        }
        $revs = $db->query(
            "SELECT pr.created_at, co.order_number, p.base_name, pr.old_unit_price, pr.new_unit_price, u.display_name AS by_whom
             FROM credit_order_price_revisions pr
             JOIN credit_orders co ON co.id = pr.order_id
             LEFT JOIN products p ON p.id = pr.product_id
             LEFT JOIN users u ON u.id = pr.changed_by_user_id
             WHERE DATE(pr.created_at) BETWEEN ? AND ?
             ORDER BY pr.created_at", [$date_from, $date_to]
        )->results();
        foreach ($revs as $r) {
            $delta = (float)$r->new_unit_price - (float)$r->old_unit_price;
            $rows[] = [date('d M Y', strtotime($r->created_at)), $r->order_number, 'Price revision: ' . $r->base_name,
                ChairmanReportPdf::money($delta) . '/unit', 'Tk ' . number_format($r->old_unit_price,0) . ' -> Tk ' . number_format($r->new_unit_price,0), $r->by_whom ?? '—'];
        }
        usort($rows, fn($a,$b) => strtotime($a[0]) <=> strtotime($b[0]));
        return [
            'headers' => ['Date', 'Order #', 'Type', 'Amount / Delta', 'Detail / Reason', 'By Whom'],
            'widths'  => [28, 35, 55, 40, 60, 42], 'aligns' => ['L','L','L','R','L','L'],
            'rows' => $rows, 'totalRow' => null,
            'note' => 'Combines approved order amendments and item price revisions.' . (empty($rows) ? ' No modifications or amendments in this period.' : ''),
        ];
    }

    // ── 9. Transports ────────────────────────────────────────────────────
    case 'transports': {
        $branch_sql = $branch_id ? " AND co.assigned_branch_id = " . (int)$branch_id : '';
        $rows_raw = $db->query(
            "SELECT co.order_number, cos.truck_number, cos.driver_name, cos.shipped_date, cos.delivered_date,
                    c.name AS customer_name, co.total_amount
             FROM credit_order_shipping cos
             JOIN credit_orders co ON co.id = cos.order_id
             JOIN customers c ON c.id = co.customer_id
             WHERE cos.shipped_date IS NOT NULL AND DATE(cos.shipped_date) BETWEEN ? AND ? {$branch_sql}
             ORDER BY cos.shipped_date",
            [$date_from, $date_to]
        )->results();
        $rows = []; $total = 0;
        foreach ($rows_raw as $r) {
            $rows[] = [$r->order_number, $r->truck_number ?? '—', $r->driver_name ?? '—', $r->customer_name,
                date('d M Y', strtotime($r->shipped_date)), $r->delivered_date ? date('d M Y', strtotime($r->delivered_date)) : 'Pending',
                ChairmanReportPdf::money((float)$r->total_amount)];
            $total += (float)$r->total_amount;
        }
        return [
            'headers' => ['Order #', 'Truck', 'Driver', 'Customer', 'Shipped', 'Delivered', 'Value'],
            'widths'  => [38, 32, 40, 55, 30, 30, 37], 'aligns' => ['L','L','L','L','L','L','R'],
            'rows' => $rows, 'totalRow' => ['', '', '', '', '', 'Total', ChairmanReportPdf::money($total)],
            'note' => empty($rows) ? 'No dispatches in this period.' : null,
        ];
    }

    // ── 12. Purchase Orders — every real field, added 4 Sep 2026 per
    // "need to add purchase related reports". The purchase module's live
    // tables carry an "_adnan" suffix (purchase_orders_adnan etc. —
    // confirmed by grepping the live purchase/*.php pages' own SQL, not
    // guessed) and are FLAT — one commodity per PO, no line-items table —
    // so this needs wrappedTable() (wrap/fill/center) but no merge.
    // branch_id and approved_by-style columns exist on this table but are
    // always empty live (checked before including them, same principle as
    // the Collections report's excluded columns) so they're left out.
    case 'purchase_orders': {
        $rows_raw = $db->query(
            "SELECT po.po_number, po.po_date, po.supplier_name, pc.name AS commodity_name, po.wheat_origin,
                    po.quantity_kg, po.unit_price_per_kg, po.total_order_value,
                    po.total_received_qty, po.qty_yet_to_receive, po.total_received_value,
                    po.total_paid, po.balance_payable, po.po_status, po.delivery_status, po.payment_status,
                    po.expected_delivery_date, u.display_name AS created_by
             FROM purchase_orders_adnan po
             LEFT JOIN purchase_commodities pc ON pc.id = po.commodity_id
             LEFT JOIN users u ON u.id = po.created_by_user_id
             WHERE po.po_date BETWEEN ? AND ?
             ORDER BY po.po_date, po.po_number",
            [$date_from, $date_to]
        )->results();
        $rows = []; $t = ['total'=>0,'recv_val'=>0,'paid'=>0,'due'=>0];
        foreach ($rows_raw as $r) {
            $rows[] = [
                $r->po_number, date('d M Y', strtotime($r->po_date)), $r->supplier_name, $r->commodity_name ?: '-', $r->wheat_origin ?: '-',
                number_format((float)$r->quantity_kg, 0) . ' kg', ChairmanReportPdf::money2((float)$r->unit_price_per_kg), ChairmanReportPdf::money((float)$r->total_order_value),
                number_format((float)$r->total_received_qty, 0) . ' kg', number_format((float)$r->qty_yet_to_receive, 0) . ' kg', ChairmanReportPdf::money((float)$r->total_received_value),
                ChairmanReportPdf::money((float)$r->total_paid), ChairmanReportPdf::money((float)$r->balance_payable),
                ucfirst($r->po_status), ucfirst($r->delivery_status), ucfirst($r->payment_status),
                $r->expected_delivery_date ? date('d M Y', strtotime($r->expected_delivery_date)) : '-', $r->created_by ?? 'System',
            ];
            $t['total'] += (float)$r->total_order_value; $t['recv_val'] += (float)$r->total_received_value;
            $t['paid'] += (float)$r->total_paid; $t['due'] += (float)$r->balance_payable;
        }
        return [
            'headers' => ['PO #', 'Date', 'Supplier', 'Commodity', 'Origin', 'Qty Ordered', 'Unit Price', 'Total Value',
                'Qty Received', 'Qty Pending', 'Received Value', 'Paid', 'Balance Payable', 'PO Status', 'Delivery Status', 'Payment Status', 'Expected Delivery', 'Created By'],
            'widths'  => [12, 14, 22, 16, 14, 16, 14, 16, 16, 16, 16, 14, 14, 12, 14, 14, 14, 17],
            'aligns'  => ['L','C','L','L','L','R','R','R','R','R','R','R','R','C','C','C','C','L'],
            'rows' => $rows,
            'totalRow' => ['', '', '', '', '', '', '', ChairmanReportPdf::money($t['total']), '', '', ChairmanReportPdf::money($t['recv_val']), ChairmanReportPdf::money($t['paid']), ChairmanReportPdf::money($t['due']), '', '', '', '', ''],
            'note' => empty($rows) ? 'No purchase orders raised in this period.' : (count($rows) . ' POs, ' . ChairmanReportPdf::money($t['total']) . ' total value, ' . ChairmanReportPdf::money($t['paid']) . ' paid, ' . ChairmanReportPdf::money($t['due']) . ' balance payable.'),
            'fontSize' => 7, 'wrapped' => true,
        ];
    }

    // ── 13. Goods Received (GRN) ─────────────────────────────────────────
    case 'goods_received': {
        $rows_raw = $db->query(
            "SELECT g.grn_number, g.grn_date, g.po_number, g.supplier_name, g.truck_number,
                    g.quantity_received_kg, g.expected_quantity, g.weight_variance, g.variance_percentage,
                    g.unit_price_per_kg, g.total_value, g.unload_point_name, g.grn_status, g.remarks,
                    u.display_name AS received_by
             FROM goods_received_adnan g
             LEFT JOIN users u ON u.id = g.receiver_user_id
             WHERE g.grn_date BETWEEN ? AND ?
             ORDER BY g.grn_date, g.grn_number",
            [$date_from, $date_to]
        )->results();
        $rows = []; $t = ['qty'=>0,'value'=>0,'variance'=>0];
        foreach ($rows_raw as $r) {
            $rows[] = [
                $r->grn_number, date('d M Y', strtotime($r->grn_date)), $r->po_number ?: '-', $r->supplier_name, $r->truck_number ?: '-',
                number_format((float)$r->quantity_received_kg, 0) . ' kg', number_format((float)$r->expected_quantity, 0) . ' kg',
                number_format((float)$r->weight_variance, 1) . ' kg', number_format((float)$r->variance_percentage, 2) . '%',
                ChairmanReportPdf::money2((float)$r->unit_price_per_kg), ChairmanReportPdf::money((float)$r->total_value),
                $r->unload_point_name ?: '-', ucfirst($r->grn_status), $r->received_by ?? 'System', $r->remarks ?: '-',
            ];
            $t['qty'] += (float)$r->quantity_received_kg; $t['value'] += (float)$r->total_value; $t['variance'] += (float)$r->weight_variance;
        }
        return [
            'headers' => ['GRN #', 'Date', 'PO #', 'Supplier', 'Truck #', 'Qty Received', 'Expected Qty', 'Variance', 'Variance %',
                'Unit Price', 'Total Value', 'Unload Point', 'Status', 'Received By', 'Remarks'],
            'widths'  => [22, 14, 12, 28, 16, 16, 16, 14, 12, 14, 22, 22, 12, 18, 33],
            'aligns'  => ['L','C','C','L','C','R','R','R','R','R','R','L','C','L','L'],
            'rows' => $rows,
            'totalRow' => ['', '', '', '', 'Total', number_format($t['qty'],0).' kg', '', number_format($t['variance'],1).' kg', '', '', ChairmanReportPdf::money($t['value']), '', '', '', ''],
            'note' => empty($rows) ? 'No goods received in this period.' : (count($rows) . ' GRNs, ' . number_format($t['qty'],0) . ' kg received, ' . ChairmanReportPdf::money($t['value']) . ' total value, net weight variance ' . number_format($t['variance'],1) . ' kg.'),
            'fontSize' => 7, 'wrapped' => true,
        ];
    }

    // ── 14. Supplier Payments ────────────────────────────────────────────
    case 'supplier_payments': {
        $rows_raw = $db->query(
            "SELECT pp.payment_voucher_number, pp.payment_date, pp.po_number, pp.supplier_name,
                    pp.amount_paid, pp.payment_method, pp.bank_name, pp.reference_number, pp.payment_type,
                    pp.is_posted, pp.handled_by_employee, pp.remarks, u.display_name AS recorded_by
             FROM purchase_payments_adnan pp
             LEFT JOIN users u ON u.id = pp.created_by_user_id
             WHERE pp.payment_date BETWEEN ? AND ?
             ORDER BY pp.payment_date, pp.payment_voucher_number",
            [$date_from, $date_to]
        )->results();
        $rows = []; $total = 0; $by_method = []; $by_type = [];
        foreach ($rows_raw as $r) {
            $amount = (float)$r->amount_paid;
            $paid_to = $r->payment_method === 'cash' ? 'Cash' : ($r->bank_name ?: '-');
            $rows[] = [
                $r->payment_voucher_number, date('d M Y', strtotime($r->payment_date)), $r->po_number ?: '-', $r->supplier_name,
                ChairmanReportPdf::money($amount), ucfirst($r->payment_method ?? '-'), $paid_to, $r->reference_number ?: '-',
                ucfirst($r->payment_type ?? '-'), $r->is_posted ? 'Yes' : 'No', $r->handled_by_employee ?: '-', $r->recorded_by ?? 'System', $r->remarks ?: '-',
            ];
            $total += $amount;
            $by_method[ucfirst($r->payment_method ?? 'Other')] = ($by_method[ucfirst($r->payment_method ?? 'Other')] ?? 0) + $amount;
            $by_type[ucfirst($r->payment_type ?? 'Other')] = ($by_type[ucfirst($r->payment_type ?? 'Other')] ?? 0) + $amount;
        }
        $note = empty($rows) ? 'No supplier payments recorded in this period.' : (
            'By method: ' . implode(', ', array_map(fn($k,$v) => "{$k} " . ChairmanReportPdf::money($v), array_keys($by_method), $by_method))
            . '  |  By type: ' . implode(', ', array_map(fn($k,$v) => "{$k} " . ChairmanReportPdf::money($v), array_keys($by_type), $by_type))
        );
        return [
            'headers' => ['Voucher #', 'Date', 'PO #', 'Supplier', 'Amount', 'Method', 'Paid To Account', 'Reference #', 'Type', 'Posted', 'Handled By', 'Recorded By', 'Remarks'],
            'widths'  => [22, 14, 12, 32, 18, 14, 34, 22, 14, 12, 20, 20, 37],
            'aligns'  => ['L','C','C','L','R','C','L','C','C','C','L','L','L'],
            'rows' => $rows, 'totalRow' => ['', '', '', 'Total', ChairmanReportPdf::money($total), '', '', '', '', '', '', '', ''],
            'note' => $note, 'fontSize' => 7, 'wrapped' => true,
        ];
    }

    // ── 10. Exceptions & Corrections ────────────────────────────────────
    case 'exceptions': {
        $rows = [];
        $recon = $db->query(
            "SELECT r.reconciled_at AS at, c.name, r.old_balance, r.new_balance, r.status, u.display_name AS by_whom
             FROM customer_balance_reconciliations r
             JOIN customers c ON c.id = r.customer_id
             LEFT JOIN users u ON u.id = r.reconciled_by_user_id
             WHERE DATE(r.reconciled_at) BETWEEN ? AND ?
             ORDER BY r.reconciled_at", [$date_from, $date_to]
        )->results();
        foreach ($recon as $r) {
            $rows[] = [date('d M Y', strtotime($r->at)), 'Balance reconciliation', $r->name,
                'Tk ' . number_format($r->old_balance,0) . ' -> Tk ' . number_format($r->new_balance,0),
                ucfirst($r->status), $r->by_whom ?? 'System'];
        }
        $wf = $db->query(
            "SELECT w.performed_at AS at, co.order_number, w.comments, u.display_name AS by_whom
             FROM credit_order_workflow w
             JOIN credit_orders co ON co.id = w.order_id
             LEFT JOIN users u ON u.id = w.performed_by_user_id
             WHERE w.action = 'admin_correction' AND DATE(w.performed_at) BETWEEN ? AND ?
             ORDER BY w.performed_at", [$date_from, $date_to]
        )->results();
        foreach ($wf as $w) {
            $rows[] = [date('d M Y', strtotime($w->at)), 'Order correction', $w->order_number, substr($w->comments ?? '', 0, 60), '—', $w->by_whom ?? 'System'];
        }
        usort($rows, fn($a,$b) => strtotime($a[0]) <=> strtotime($b[0]));
        return [
            'headers' => ['Date', 'Type', 'Subject', 'What Changed', 'Status', 'By Whom'],
            'widths'  => [28, 45, 50, 80, 30, 42], 'aligns' => ['L','L','L','L','L','L'],
            'rows' => $rows, 'totalRow' => null,
            'note' => 'Every data correction made through the Balance Reconciliation tool or a direct order-status correction, in this period.' . (empty($rows) ? ' No exceptions or corrections in this period — clean.' : ''),
        ];
    }

    // ── 11. Day-wise Reconciliation ──────────────────────────────────────
    case 'daily_reconciliation':
    default: {
        $days = [];
        $cursor = strtotime($date_from);
        $end = strtotime($date_to);
        while ($cursor <= $end) { $days[date('Y-m-d', $cursor)] = true; $cursor = strtotime('+1 day', $cursor); }

        $mk = function($sql, $params) use ($db) {
            $out = [];
            foreach ($db->query($sql, $params)->results() as $r) $out[$r->d] = (float)($r->v ?? $r->c ?? 0);
            return $out;
        };
        $created  = $mk("SELECT DATE(order_date) d, COUNT(*) c FROM credit_orders WHERE order_date BETWEEN ? AND ? GROUP BY d", [$date_from,$date_to]);
        $approved = $mk("SELECT DATE(performed_at) d, COUNT(*) c FROM credit_order_workflow WHERE action='approved' AND DATE(performed_at) BETWEEN ? AND ? GROUP BY d", [$date_from,$date_to]);
        $produced = $mk("SELECT DATE(performed_at) d, COUNT(*) c FROM credit_order_workflow WHERE action='complete_production' AND DATE(performed_at) BETWEEN ? AND ? GROUP BY d", [$date_from,$date_to]);
        $shipped  = $mk("SELECT DATE(performed_at) d, COUNT(*) c FROM credit_order_workflow WHERE action IN ('goods_on_board','ship') AND DATE(performed_at) BETWEEN ? AND ? GROUP BY d", [$date_from,$date_to]);
        $delivered= $mk("SELECT DATE(performed_at) d, COUNT(*) c FROM credit_order_workflow WHERE to_status='delivered' AND (from_status IS NULL OR from_status != to_status) AND DATE(performed_at) BETWEEN ? AND ? GROUP BY d", [$date_from,$date_to]);
        $sales    = $mk("SELECT DATE(o.order_date) d, SUM(o.total_amount) v FROM credit_orders o WHERE o.status NOT IN ('draft','cancelled','rejected') AND o.order_date BETWEEN ? AND ? GROUP BY d", [$date_from,$date_to]);
        $collected= $mk("SELECT DATE(payment_date) d, SUM(amount) v FROM customer_payments WHERE payment_date BETWEEN ? AND ? GROUP BY d", [$date_from,$date_to]);
        $expensed = $mk("SELECT DATE(expense_date) d, SUM(total_amount) v FROM expense_vouchers WHERE status='approved' AND expense_date BETWEEN ? AND ? GROUP BY d", [$date_from,$date_to]);

        $rows = []; $t = ['created'=>0,'approved'=>0,'produced'=>0,'shipped'=>0,'delivered'=>0,'sales'=>0,'collected'=>0,'expensed'=>0];
        foreach (array_keys($days) as $d) {
            $rows[] = [
                date('d M Y (D)', strtotime($d)),
                (string)($created[$d] ?? 0), (string)($approved[$d] ?? 0), (string)($produced[$d] ?? 0),
                (string)($shipped[$d] ?? 0), (string)($delivered[$d] ?? 0),
                ChairmanReportPdf::money($sales[$d] ?? 0), ChairmanReportPdf::money($collected[$d] ?? 0), ChairmanReportPdf::money($expensed[$d] ?? 0),
            ];
            $t['created']+=$created[$d]??0; $t['approved']+=$approved[$d]??0; $t['produced']+=$produced[$d]??0;
            $t['shipped']+=$shipped[$d]??0; $t['delivered']+=$delivered[$d]??0;
            $t['sales']+=$sales[$d]??0; $t['collected']+=$collected[$d]??0; $t['expensed']+=$expensed[$d]??0;
        }
        return [
            'headers' => ['Date', 'Created', 'Approved', 'Produced', 'Shipped', 'Delivered', 'Sales', 'Collected', 'Expenses'],
            'widths'  => [40, 24, 24, 24, 24, 24, 32, 32, 32], 'aligns' => ['L','C','C','C','C','C','R','R','R'],
            'rows' => $rows,
            'totalRow' => ['Total', (string)$t['created'], (string)$t['approved'], (string)$t['produced'], (string)$t['shipped'], (string)$t['delivered'],
                ChairmanReportPdf::money($t['sales']), ChairmanReportPdf::money($t['collected']), ChairmanReportPdf::money($t['expensed'])],
            'note' => 'One row per calendar day in the selected range — order milestone counts plus sales/collections/expenses totals for daily reconciliation.',
        ];
    }
    }
}

$report = buildReport($db, $report_type, $date_from, $date_to, $branch_id);
$report_title = $REPORT_TYPES[$report_type];

// ── CSV export ────────────────────────────────────────────────────────────
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . preg_replace('/[^a-z0-9]+/i','_', strtolower($report_title)) . '_' . $date_from . '_to_' . $date_to . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ["Ujjal Flour Mills — {$report_title}"]);
    fputcsv($out, ["Period: {$period_label}", "Scope: {$branch_label}", "Generated: " . date('Y-m-d H:i')]);
    fputcsv($out, []);
    fputcsv($out, $report['headers']);
    foreach ($report['rows'] as $row) fputcsv($out, $row);
    if ($report['totalRow']) fputcsv($out, $report['totalRow']);
    fclose($out);
    exit();
}

// ── PDF export ────────────────────────────────────────────────────────────
if (isset($_GET['export']) && $_GET['export'] === 'pdf') {
    $pdf = new ChairmanReportPdf('L', 'mm', 'A4');
    $pdf->AliasNbPages();
    $pdf->reportTitle = $report_title;
    $pdf->periodLabel = $period_label;
    $pdf->branchLabel = $branch_label;
    $pdf->generatedBy = $currentUser['display_name'] ?? '';
    $pdf->AddPage();
    if (!empty($report['note'])) $pdf->note($report['note']);
    if (!empty($report['rows'])) {
        if (!empty($report['merged'])) {
            $m = $report['merged'];
            $pdf->mergedTable($m['order_headers'], $m['order_widths'], $m['order_aligns'],
                $m['item_headers'], $m['item_widths'], $m['item_aligns'], $m['groups'], $report['fontSize'] ?? 6.5);
        } elseif (!empty($report['wrapped'])) {
            $pdf->wrappedTable($report['headers'], $report['widths'], $report['aligns'], $report['rows'], $report['totalRow'], $report['fontSize'] ?? 7);
        } else {
            $pdf->table($report['headers'], $report['widths'], $report['aligns'], $report['rows'], $report['totalRow'], $report['fontSize'] ?? 8.5);
        }
    }
    $pdf->Output('D', preg_replace('/[^a-z0-9]+/i','_', strtolower($report_title)) . '_' . $date_from . '_to_' . $date_to . '.pdf');
    exit();
}

require_once '../templates/header.php';
?>
<div class="max-w-7xl mx-auto px-4 py-6">

    <div class="mb-2">
        <h1 class="text-2xl font-bold text-gray-900 flex items-center gap-2">
            <i class="fas fa-file-invoice text-indigo-600"></i> Chairman's Report Center
        </h1>
        <p class="text-sm text-gray-500 mt-1">Comprehensive, date-ranged, tabulated reports — preview on screen, or download as PDF/CSV.</p>
    </div>

    <form method="GET" class="bg-white rounded-xl border border-gray-200 shadow-sm p-4 mb-6 flex flex-wrap items-end gap-3">
        <div class="flex flex-col gap-0.5">
            <label class="text-xs text-gray-500 font-medium">Report</label>
            <select name="report_type" class="text-sm px-3 py-2 border border-gray-300 rounded-lg focus:ring-1 focus:ring-indigo-500 min-w-[220px]">
                <?php foreach ($REPORT_TYPES as $key => $label): ?>
                <option value="<?php echo $key; ?>" <?php echo $report_type === $key ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="flex flex-col gap-0.5">
            <label class="text-xs text-gray-500 font-medium">From</label>
            <input type="date" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>" class="text-sm px-3 py-2 border border-gray-300 rounded-lg focus:ring-1 focus:ring-indigo-500">
        </div>
        <div class="flex flex-col gap-0.5">
            <label class="text-xs text-gray-500 font-medium">To</label>
            <input type="date" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>" class="text-sm px-3 py-2 border border-gray-300 rounded-lg focus:ring-1 focus:ring-indigo-500">
        </div>
        <div class="flex flex-col gap-0.5">
            <label class="text-xs text-gray-500 font-medium">Branch</label>
            <select name="branch_id" class="text-sm px-3 py-2 border border-gray-300 rounded-lg focus:ring-1 focus:ring-indigo-500">
                <option value="0">All Branches</option>
                <?php foreach ($branches as $b): ?>
                <option value="<?php echo $b->id; ?>" <?php echo $branch_id === (int)$b->id ? 'selected' : ''; ?>><?php echo htmlspecialchars($b->name); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="flex items-center gap-1 pb-0.5">
            <?php foreach (['today'=>'Today','this_week'=>'This Week','this_month'=>'This Month','this_quarter'=>'This Quarter'] as $pk => $pl): ?>
            <a href="?report_type=<?php echo $report_type; ?>&branch_id=<?php echo $branch_id; ?>&preset=<?php echo $pk; ?>"
               class="px-2.5 py-1.5 text-xs rounded-lg border <?php echo $preset===$pk ? 'bg-indigo-600 text-white border-indigo-600' : 'bg-gray-50 text-gray-600 border-gray-200 hover:bg-gray-100'; ?>"><?php echo $pl; ?></a>
            <?php endforeach; ?>
        </div>
        <button type="submit" class="px-4 py-2 bg-gray-800 text-white text-sm font-semibold rounded-lg hover:bg-gray-900 cursor-pointer">
            <i class="fas fa-eye mr-1"></i>Preview
        </button>
        <a href="?<?php echo http_build_query(['report_type'=>$report_type,'date_from'=>$date_from,'date_to'=>$date_to,'branch_id'=>$branch_id,'export'=>'pdf']); ?>"
           class="px-4 py-2 bg-red-600 text-white text-sm font-semibold rounded-lg hover:bg-red-700 cursor-pointer">
            <i class="fas fa-file-pdf mr-1"></i>Download PDF
        </a>
        <a href="?<?php echo http_build_query(['report_type'=>$report_type,'date_from'=>$date_from,'date_to'=>$date_to,'branch_id'=>$branch_id,'export'=>'csv']); ?>"
           class="px-4 py-2 bg-green-600 text-white text-sm font-semibold rounded-lg hover:bg-green-700 cursor-pointer">
            <i class="fas fa-file-csv mr-1"></i>Download CSV
        </a>
    </form>

    <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-5">
        <div class="flex items-start justify-between mb-3">
            <div>
                <h2 class="text-base font-bold text-gray-800"><?php echo htmlspecialchars($report_title); ?></h2>
                <p class="text-xs text-gray-500">Period: <?php echo $period_label; ?> &middot; Scope: <?php echo htmlspecialchars($branch_label); ?> &middot; <?php echo count($report['rows']); ?> row(s)</p>
            </div>
        </div>
        <?php if (!empty($report['note'])): ?>
        <p class="text-xs text-indigo-700 bg-indigo-50 border border-indigo-100 rounded-lg px-3 py-2 mb-3"><i class="fas fa-info-circle mr-1"></i><?php echo htmlspecialchars($report['note']); ?></p>
        <?php endif; ?>
        <?php
        $alignClass = fn($a) => $a === 'R' ? 'text-right' : ($a === 'C' ? 'text-center' : 'text-left');
        ?>
        <?php if (empty($report['rows'])): ?>
        <div class="text-center py-10 text-gray-400"><i class="fas fa-inbox text-3xl mb-2"></i><p class="text-sm">Nothing to show for this selection.</p></div>
        <?php elseif (!empty($report['merged'])): ?>
        <?php $m = $report['merged']; ?>
        <div class="overflow-x-auto">
        <table class="w-full text-xs border-collapse">
            <thead>
                <tr class="bg-slate-700 text-white">
                    <?php foreach (array_merge($m['order_headers'], $m['item_headers']) as $h): ?>
                    <th class="px-2 py-2 text-center font-semibold uppercase tracking-wide border border-slate-300"><?php echo htmlspecialchars($h); ?></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($m['groups'] as $gi => $g): $nItems = max(1, count($g['items'])); $items = $g['items'] ?: [array_fill(0, count($m['item_headers']), '-')]; ?>
                <?php foreach ($items as $ri => $irow): ?>
                <tr class="<?php echo $gi % 2 === 0 ? 'bg-white' : 'bg-slate-50'; ?>">
                    <?php if ($ri === 0): foreach ($g['order'] as $ci => $val): ?>
                    <td rowspan="<?php echo count($items); ?>" class="px-2 py-2 border border-gray-200 align-middle whitespace-normal break-words <?php echo $alignClass($m['order_aligns'][$ci] ?? 'C'); ?>"><?php echo htmlspecialchars((string)$val); ?></td>
                    <?php endforeach; endif; ?>
                    <?php foreach ($irow as $ci => $val): ?>
                    <td class="px-2 py-2 border border-gray-200 whitespace-normal break-words <?php echo $alignClass($m['item_aligns'][$ci] ?? 'C'); ?>"><?php echo htmlspecialchars((string)$val); ?></td>
                    <?php endforeach; ?>
                </tr>
                <?php endforeach; ?>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php else: ?>
        <?php $wrapCls = !empty($report['wrapped']) ? 'whitespace-normal break-words' : 'whitespace-nowrap'; ?>
        <div class="overflow-x-auto">
        <table class="w-full text-xs">
            <thead>
                <tr class="bg-gray-50 border-b border-gray-200">
                    <?php foreach ($report['headers'] as $i => $h): ?>
                    <th class="px-3 py-2 <?php echo $alignClass($report['aligns'][$i] ?? 'L'); ?> font-semibold text-gray-500 uppercase tracking-wide <?php echo $wrapCls; ?>"><?php echo htmlspecialchars($h); ?></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                <?php foreach ($report['rows'] as $row): ?>
                <tr class="hover:bg-gray-50">
                    <?php foreach ($row as $i => $val): ?>
                    <td class="px-3 py-2 <?php echo $alignClass($report['aligns'][$i] ?? 'L'); ?> <?php echo $wrapCls; ?>"><?php echo htmlspecialchars((string)$val); ?></td>
                    <?php endforeach; ?>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <?php if (!empty($report['totalRow'])): ?>
            <tfoot>
                <tr class="border-t-2 border-gray-300 bg-gray-50 font-bold">
                    <?php foreach ($report['totalRow'] as $i => $val): ?>
                    <td class="px-3 py-2 <?php echo $alignClass($report['aligns'][$i] ?? 'L'); ?> <?php echo $wrapCls; ?>"><?php echo htmlspecialchars((string)$val); ?></td>
                    <?php endforeach; ?>
                </tr>
            </tfoot>
            <?php endif; ?>
        </table>
        </div>
        <?php endif; ?>
    </div>

</div>
<?php require_once '../templates/footer.php'; ?>
