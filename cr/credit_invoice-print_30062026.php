<?php
require_once '../core/init.php';

$allowed_roles = ['Superadmin', 'admin', 'Accounts', 'dispatch-srg', 'dispatch-demra', 'dispatchpos-demra', 'dispatchpos-srg'];
restrict_access($allowed_roles);
global $db;

$order_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$order_id) die('Invalid order ID.');

try {
    $check_order = $db->query("SELECT id, order_number, status FROM credit_orders WHERE id = ?", [$order_id])->first();
    if (!$check_order) die("Error: Order #{$order_id} not found.");

    if (!in_array($check_order->status, ['shipped','delivered'])) {
        die("Order #{$check_order->order_number} (status: {$check_order->status}) cannot be printed yet — only shipped/delivered orders.<br><a href='javascript:history.back()'>← Back</a>");
    }

    $order = $db->query(
        "SELECT co.*,
                c.name            AS customer_name,
                c.phone_number    AS customer_phone,
                c.email           AS customer_email,
                c.business_address AS customer_address,
                b.name            AS branch_name,
                b.address         AS branch_address,
                b.phone_number    AS branch_phone,
                cos.truck_number, cos.driver_name, cos.driver_contact,
                cos.shipped_date,  cos.delivered_date,
                u.display_name    AS created_by_name
         FROM credit_orders co
         JOIN   customers c          ON co.customer_id        = c.id
         LEFT JOIN branches b        ON co.assigned_branch_id = b.id
         LEFT JOIN credit_order_shipping cos ON co.id         = cos.order_id
         LEFT JOIN users u           ON co.created_by_user_id = u.id
         WHERE co.id = ?", [$order_id]
    )->first();
    if (!$order) die("Could not load order details.");

    $items = $db->query(
        "SELECT coi.*, p.base_name AS product_name,
                pv.grade, pv.weight_variant, pv.unit_of_measure, pv.sku AS variant_sku
         FROM credit_order_items coi
         JOIN   products p         ON coi.product_id = p.id
         LEFT JOIN product_variants pv ON coi.variant_id = pv.id
         WHERE coi.order_id = ? ORDER BY coi.id ASC", [$order_id]
    )->results();
    if (empty($items)) die("No items found for this order.");

} catch (Exception $e) { die("Error: " . $e->getMessage()); }

$snap = $db->query("SELECT * FROM invoice_snapshots WHERE order_id = ? LIMIT 1", [$order_id])->first();

$previous_due = 0.0;
if ($snap) {
    $previous_due = (float)$snap->previous_due;
} else {
    try {
        $le = $db->query(
            "SELECT balance_after, debit_amount, credit_amount FROM customer_ledger
             WHERE customer_id = ? AND reference_id = ?
               AND reference_type IN ('credit_order','credit_orders')
               AND transaction_type = 'credit_sale'
             ORDER BY id DESC LIMIT 1",
            [$order->customer_id, $order_id]
        )->first();
        if ($le) {
            $previous_due = (float)$le->balance_after - (float)$le->debit_amount + (float)$le->credit_amount;
        } else {
            $cust_base = $db->query("SELECT initial_due FROM customers WHERE id = ?", [$order->customer_id])->first();
            $older = $db->query(
                "SELECT COALESCE(SUM(balance_due), 0) AS s FROM credit_orders
                 WHERE customer_id = ? AND id < ?
                   AND status NOT IN ('cancelled','rejected')
                   AND order_number NOT LIKE 'INV-INITIAL-%'",
                [$order->customer_id, $order_id]
            )->first();
            $previous_due = (float)($cust_base->initial_due ?? 0) + (float)($older->s ?? 0);
        }
    } catch (Exception $e) { /* keep 0 */ }
}

$snap_items = null;
if ($snap && !empty($snap->items_json)) {
    $snap_items = json_decode($snap->items_json, true);
}

$logo_base64 = null;
$logo_dir = dirname(__DIR__) . '/uploads/company/';
foreach (['png', 'jpg', 'jpeg', 'webp', 'gif'] as $_ext) {
    $_lf = $logo_dir . 'logo.' . $_ext;
    if (file_exists($_lf)) {
        $_mime = 'image/' . ($_ext === 'jpg' ? 'jpeg' : $_ext);
        $logo_base64 = 'data:' . $_mime . ';base64,' . base64_encode(file_get_contents($_lf));
        break;
    }
}

$company = $snap ? [
    'name'    => $snap->company_name_bn  ?? 'উজ্জল ফ্লাওয়ার মিলস',
    'en_name' => $snap->company_name_en  ?? 'Ujjal Flour Mills',
    'address' => $snap->company_address  ?? '১৭, নুরাইবাগ, ডেমরা, ঢাকা',
    'phone'   => $snap->company_phone    ?? '+880-XXX-XXXXXX',
    'email'   => $snap->company_email    ?? 'info@ujjalfm.com',
] : [
    'name'    => 'উজ্জল ফ্লাওয়ার মিলস',
    'en_name' => 'Ujjal Flour Mills',
    'address' => '১৭, নুরাইবাগ, ডেমরা, ঢাকা',
    'phone'   => '+880-XXX-XXXXXX',
    'email'   => 'info@ujjalfm.com',
];

if (!empty($order->branch_address)) $company['address'] = $order->branch_address;
if (!empty($order->branch_phone))   $company['phone']   = $order->branch_phone;

if ($snap) {
    $order->customer_name    = $snap->customer_name;
    $order->customer_phone   = $snap->customer_phone;
    $order->customer_email   = $snap->customer_email;
    $order->customer_address = $snap->customer_address;
    if (!empty($snap->truck_number))     $order->truck_number     = $snap->truck_number;
    if (!empty($snap->driver_name))      $order->driver_name      = $snap->driver_name;
    if (!empty($snap->driver_contact))   $order->driver_contact   = $snap->driver_contact;
    if (!empty($snap->shipped_date))     $order->shipped_date     = $snap->shipped_date;
    if (!empty($snap->delivered_date))   $order->delivered_date   = $snap->delivered_date;
    if (!empty($snap->shipping_address)) $order->shipping_address = $snap->shipping_address;
}

$invoice_date = $snap->invoice_date ?? ($order->shipped_date ?? $order->order_date);
$is_delivered = ($order->status === 'delivered');
$deliver_to   = trim($order->shipping_address ?? '');
$bill_address = trim($order->customer_address ?? '');

// Build render items — shared between invoice table and receipt table
$render_items = [];
if ($snap_items) {
    foreach ($snap_items as $si) {
        $obj = new stdClass();
        $obj->product_name    = $si['product_name'];
        $obj->variant_detail  = $si['variant_detail'] ?? '';
        $obj->variant_sku     = $si['sku'];
        $obj->quantity        = $si['quantity'];
        $obj->unit_price      = $si['unit_price'];
        $obj->discount_amount = $si['discount_amount'];
        $obj->line_total      = $si['line_total'];
        $render_items[]       = $obj;
    }
} else {
    foreach ($items as $it) {
        $obj = new stdClass();
        $vd  = [];
        if ($it->grade)          $vd[] = 'Grade ' . $it->grade;
        if ($it->weight_variant) $vd[] = $it->weight_variant;
        $obj->product_name    = $it->product_name;
        $obj->variant_detail  = implode(' · ', $vd);
        $obj->variant_sku     = $it->variant_sku;
        $obj->quantity        = $it->quantity;
        $obj->unit_price      = $it->unit_price;
        $obj->discount_amount = $it->discount_amount;
        $obj->line_total      = $it->line_total;
        $render_items[]       = $obj;
    }
}
$total_qty = array_sum(array_map(fn($i) => (float)$i->quantity, $render_items));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Invoice — <?php echo htmlspecialchars($order->order_number); ?></title>
<style>
/* ── Reset ─────────────────────────────────────────────────────── */
*,*::before,*::after{margin:0;padding:0;box-sizing:border-box}

/* ── Color palette — mirrors credit_payment_receipt.php ─────── */
:root{
  --grn:    #10b981;
  --grn-dk: #059669;
  --grn-lt: #d1fae5;
  --grn-bg: #f0fdf4;
  --ink:    #111827;
  --ink2:   #1f2937;
  --muted:  #6b7280;
  --line:   #e5e7eb;
  --surf:   #f9fafb;
}

/* ── Screen body ────────────────────────────────────────────────── */
body{
  font-family: Arial, sans-serif;
  font-size: 13px; line-height: 1.5;
  color: var(--ink2);
  background: #e5e7eb;
  padding: 52px 20px 60px;
}

/* ── Print toolbar ──────────────────────────────────────────────── */
.toolbar{
  position:fixed; top:10px; left:50%; transform:translateX(-50%);
  display:flex; align-items:center; gap:8px;
  background:#fff; border:1px solid #d1d5db; border-radius:10px;
  padding:6px 16px; box-shadow:0 4px 20px rgba(0,0,0,.15);
  z-index:9999; font-size:12px; font-weight:600; color:#374151; white-space:nowrap;
}
.tb-btn{
  padding:5px 16px; border:none; border-radius:6px;
  cursor:pointer; font-size:12px; font-weight:700;
}
.tb-btn:hover{filter:brightness(.88)}
.tb-print{background:var(--grn); color:#fff;}
.tb-close{background:#6b7280; color:#fff;}

/* ── A4 page container ──────────────────────────────────────────── */
.a4-page{
  width:794px; margin:0 auto; background:#fff;
  border:2px solid var(--grn);
  box-shadow:0 0 20px rgba(0,0,0,.15);
}

/* ════════════════════════════════════════════════════════════════
   INVOICE SECTION
════════════════════════════════════════════════════════════════ */

/* Header — 3-column grid: logo | centred company block | invoice info */
.inv-header{
  display:grid; grid-template-columns:auto 1fr auto;
  align-items:center; gap:18px;
  padding:16px 24px 14px;
  border-bottom:3px solid var(--grn);
}
.co-logo img{max-height:150px; max-width:150px; object-fit:contain; display:block;}
.co-block{text-align:center;}
.co-name{font-size:26px; font-weight:800; color:var(--grn); line-height:1.1;}
.co-en  {font-size:10.5px; font-weight:700; color:var(--muted); text-transform:uppercase; letter-spacing:1.2px; margin-top:3px;}
.co-meta{font-size:10.5px; color:var(--muted); margin-top:5px; line-height:1.7; text-align:center;}

.inv-right{text-align:right;}
.inv-word{
  display:inline-block; background:var(--grn); color:#fff;
  font-size:24px; font-weight:900; letter-spacing:6px; text-transform:uppercase;
  padding:6px 20px; margin-bottom:7px;
}
.inv-num {font-size:13px; font-weight:700; color:var(--ink);}
.inv-date{font-size:11px; color:var(--muted); margin-top:3px;}
.s-pill{
  display:inline-block; margin-top:6px;
  padding:3px 12px; border-radius:12px;
  font-size:9px; font-weight:700; letter-spacing:.7px; text-transform:uppercase;
  background:var(--grn-lt); color:var(--grn-dk); border:1px solid var(--grn);
}

/* Info row */
.info-row{
  display:grid; grid-template-columns:1fr 1fr 1fr;
  border-bottom:1px solid var(--line);
}
.info-col{padding:12px 20px; border-right:1px solid var(--line);}
.info-col:last-child{border-right:none;}
.info-col h4{
  font-size:10px; font-weight:800; text-transform:uppercase;
  letter-spacing:.6px; color:var(--grn);
  margin-bottom:7px; padding-bottom:5px;
  border-bottom:2px solid var(--grn-lt);
}
.info-col .nm{font-size:13px; font-weight:700; color:var(--ink); margin-bottom:3px;}
.info-col p  {font-size:11.5px; color:var(--ink2); margin:2.5px 0;}
.info-col .kv{display:flex; font-size:11.5px; margin:3px 0;}
.info-col .kv .k{color:var(--muted); min-width:76px; flex-shrink:0;}
.info-col .kv .v{color:var(--ink); font-weight:600;}

/* Items table */
.sec-lbl{
  font-size:9px; font-weight:800; text-transform:uppercase;
  letter-spacing:.7px; color:var(--muted);
  padding:6px 20px 4px;
  display:flex; align-items:center; gap:6px;
}
.sec-lbl::after{content:''; flex:1; height:1px; background:var(--line);}

.itbl{width:100%; border-collapse:collapse;}
.itbl thead tr{background:var(--grn);}
.itbl th{
  padding:7px 9px; font-size:9px; font-weight:700;
  letter-spacing:.4px; text-transform:uppercase; color:#fff;
}
.itbl th.r,.itbl td.r{text-align:right;}
.itbl th.c,.itbl td.c{text-align:center;}
.itbl tbody tr{border-bottom:1px solid var(--line);}
.itbl tbody tr:nth-child(even){background:var(--surf);}
.itbl td{padding:7px 9px; font-size:11.5px; color:var(--ink2); vertical-align:middle;}
.p-name{font-weight:700; color:var(--ink); font-size:11.5px; line-height:1.2;}
.p-sub {font-size:9px; color:var(--muted); margin-top:1px;}
.qty-v {font-weight:700; color:var(--ink);}
.tot-v {font-weight:700; color:var(--grn-dk);}
.itbl tfoot td{
  padding:5px 9px; font-size:10.5px; font-weight:700;
  color:var(--muted); background:var(--surf);
  border-top:2px solid var(--grn);
}

/* Bottom row: shipping left, totals right */
.bot-row{display:flex; border-top:1px solid var(--line);}
.bot-left {flex:1; padding:12px 20px; border-right:1px solid var(--line);}
.bot-right{width:232px; flex-shrink:0;}

.ship-ttl{
  font-size:10px; font-weight:800; text-transform:uppercase;
  letter-spacing:.6px; color:var(--grn);
  margin-bottom:6px; padding-bottom:4px;
  border-bottom:2px solid var(--grn-lt);
}
.kv2{display:flex; font-size:11.5px; margin:3px 0;}
.kv2 .k{color:var(--muted); min-width:84px; flex-shrink:0;}
.kv2 .v{color:var(--ink); font-weight:600;}

.prev-due-box{
  display:flex; justify-content:space-between; align-items:center;
  padding:6px 12px; background:var(--grn-bg); border-bottom:1px solid var(--grn-lt);
}
.prev-due-box .pl{font-size:10px; font-weight:700; color:var(--grn-dk);}
.prev-due-box .pv{font-size:13px; font-weight:800; color:var(--grn-dk);}

.tots-inner{padding:8px 12px;}
.tr2{display:flex; justify-content:space-between; padding:3.5px 0; font-size:11.5px; border-bottom:1px dashed var(--line);}
.tr2:last-child{border:none;}
.tr2 .tl{color:var(--muted);}
.tr2 .tv{font-weight:600; color:var(--ink);}

.grand-row{
  display:flex; justify-content:space-between; align-items:center;
  padding:9px 12px; background:var(--grn);
}
.grand-row .gl{font-size:13px; font-weight:800; color:#fff;}
.grand-row .gv{font-size:17px; font-weight:900; color:#fff;}

.outstanding-row{
  display:flex; justify-content:space-between; align-items:center;
  padding:6px 12px; background:var(--grn-dk);
}
.outstanding-row .ol{font-size:11px; font-weight:700; color:#fff;}
.outstanding-row .ov{font-size:15px; font-weight:900; color:#fff;}

/* Invoice signatures */
.sig-row{
  display:flex; gap:14px;
  padding:12px 20px 14px; border-top:1px solid var(--line);
}
.sig-box{flex:1;}
.sig-line{border-top:2px solid #333; margin-top:32px; padding-top:5px;}
.sig-lbl {font-size:9px; font-weight:800; text-transform:uppercase; letter-spacing:.5px; color:var(--muted);}
.sig-name{font-size:10.5px; color:var(--ink2); margin-top:2px;}

/* ════════════════════════════════════════════════════════════════
   CUT LINE
════════════════════════════════════════════════════════════════ */
.cut-line{
  display:flex; align-items:center; gap:8px;
  background:#f9fafb;
  border-top:1px dashed var(--grn); border-bottom:1px dashed var(--grn);
  padding:5px 16px;
  font-size:8px; font-weight:700; color:var(--grn);
  letter-spacing:1.5px; text-transform:uppercase; user-select:none;
}
.cut-line::before,.cut-line::after{content:''; flex:1; height:1px; border-top:1px dashed var(--grn);}

/* ════════════════════════════════════════════════════════════════
   DELIVERY RECEIPT SECTION
════════════════════════════════════════════════════════════════ */
.receipt-head{
  display:flex; justify-content:space-between; align-items:center;
  padding:10px 20px; background:var(--grn);
}
.rh-left .rh-title{font-size:16px; font-weight:900; color:#fff; letter-spacing:3px; text-transform:uppercase; line-height:1;}
.rh-left .rh-sub  {font-size:9px; color:rgba(255,255,255,.75); text-transform:uppercase; letter-spacing:.8px; margin-top:3px;}
.rh-right .rh-co  {font-size:13px; font-weight:800; color:#fff; text-align:right;}
.rh-right .rh-note{font-size:9px; color:rgba(255,255,255,.7); text-align:right; margin-top:2px;}

.r-info-bar{display:flex; border-bottom:1px solid var(--line);}
.r-info-cell{flex:1; padding:7px 14px; border-right:1px solid var(--line);}
.r-info-cell:last-child{border-right:none;}
.r-info-lbl{font-size:8.5px; font-weight:800; text-transform:uppercase; letter-spacing:.4px; color:var(--muted); margin-bottom:2px;}
.r-info-val{font-weight:700; color:var(--ink); font-size:11.5px;}

.r-notice{
  font-size:10px; font-weight:700; color:var(--grn-dk);
  text-align:center; padding:6px 20px;
  background:var(--grn-bg); border-bottom:1px solid var(--grn-lt);
}
.r-notice span{font-weight:400; font-style:italic; color:var(--muted); font-size:9.5px;}

.r-tbl{width:100%; border-collapse:collapse;}
.r-tbl thead tr{background:var(--grn-dk);}
.r-tbl th{
  padding:5px 9px; font-size:8.5px; font-weight:700;
  letter-spacing:.4px; text-transform:uppercase; color:#fff;
}
.r-tbl th.r,.r-tbl td.r{text-align:right;}
.r-tbl th.c,.r-tbl td.c{text-align:center;}
.r-tbl tbody tr{border-bottom:1px solid var(--line);}
.r-tbl tbody tr:nth-child(even){background:var(--surf);}
.r-tbl td{padding:5px 9px; font-size:11.5px; color:var(--ink2);}
.r-tbl tfoot tr{background:var(--grn);}
.r-tbl tfoot td{padding:6px 9px; font-size:12px; font-weight:800; color:#fff;}

.r-sig-row{
  display:flex; gap:12px;
  padding:10px 20px 12px; border-top:1px solid var(--line);
}
.r-sig-box{flex:1;}
.r-sig-line{border-top:2px solid #333; margin-top:28px; padding-top:5px;}
.r-sig-lbl {font-size:8.5px; font-weight:800; text-transform:uppercase; letter-spacing:.4px; color:var(--ink);}
.r-sig-sub {font-size:9.5px; color:var(--muted); margin-top:3px;}

.r-office-strip{
  background:var(--surf); border-top:1px solid var(--line);
  padding:5px 20px; font-size:9px; color:var(--muted); text-align:center;
}

/* ════════════════════════════════════════════════════════════════
   PRINT STYLES
════════════════════════════════════════════════════════════════ */
@media print{
  @page{size:A4 portrait; margin:10mm 12mm}

  html,body{
    background:#fff !important; padding:0 !important; margin:0 !important;
    font-size:10px !important;
    -webkit-print-color-adjust:exact; print-color-adjust:exact;
  }
  .toolbar{display:none !important}

  .a4-page{width:100% !important; box-shadow:none !important; border-width:1px !important;}

  /* Header */
  .inv-header{padding:11px 16px 9px !important; gap:12px !important;}
  .co-logo img{max-height:72px !important; max-width:72px !important;}
  .co-name{font-size:21px !important;}
  .co-en  {font-size:9px !important;}
  .co-meta{font-size:9px !important; margin-top:3px !important;}
  .inv-word{font-size:18px !important; letter-spacing:5px !important; padding:4px 14px !important; margin-bottom:5px !important;}
  .inv-num {font-size:11px !important;}
  .inv-date{font-size:9.5px !important;}
  .s-pill  {font-size:8px !important; margin-top:4px !important;}

  /* Info row */
  .info-col{padding:9px 14px !important;}
  .info-col h4{font-size:8.5px !important; margin-bottom:5px !important; padding-bottom:3px !important;}
  .info-col .nm{font-size:11px !important; margin-bottom:2px !important;}
  .info-col p  {font-size:10px !important; margin:1.5px 0 !important;}
  .info-col .kv{font-size:10px !important; margin:2px 0 !important;}
  .info-col .kv .k{min-width:66px !important;}

  /* Items */
  .sec-lbl{padding:5px 14px 3px !important; font-size:8px !important;}
  .itbl th {padding:5px 7px !important; font-size:8px !important;}
  .itbl td {padding:5px 7px !important; font-size:10px !important;}
  .p-name  {font-size:10px !important;}
  .p-sub   {font-size:8px !important;}
  .itbl tfoot td{padding:4px 7px !important; font-size:9.5px !important;}

  /* Bottom */
  .bot-left {padding:9px 14px !important;}
  .bot-right{width:206px !important;}
  .ship-ttl {font-size:8.5px !important; margin-bottom:5px !important; padding-bottom:3px !important;}
  .kv2      {font-size:10px !important; margin:2px 0 !important;}
  .kv2 .k   {min-width:74px !important;}
  .prev-due-box{padding:5px 10px !important;}
  .prev-due-box .pl{font-size:9px !important;}
  .prev-due-box .pv{font-size:11.5px !important;}
  .tots-inner{padding:6px 10px !important;}
  .tr2      {font-size:10px !important; padding:2.5px 0 !important;}
  .grand-row{padding:7px 10px !important;}
  .grand-row .gl{font-size:11px !important;}
  .grand-row .gv{font-size:15px !important;}
  .outstanding-row{padding:5px 10px !important;}
  .outstanding-row .ol{font-size:9.5px !important;}
  .outstanding-row .ov{font-size:13px !important;}

  /* Invoice sigs */
  .sig-row {padding:8px 14px 10px !important; gap:12px !important;}
  .sig-line{margin-top:24px !important;}
  .sig-lbl {font-size:8px !important;}
  .sig-name{font-size:9.5px !important;}

  /* Cut line */
  .cut-line{padding:4px 14px !important; font-size:8px !important;}

  /* Receipt */
  .receipt-head{padding:8px 14px !important;}
  .rh-left .rh-title{font-size:13px !important; letter-spacing:2.5px !important;}
  .rh-left .rh-sub  {font-size:7.5px !important;}
  .rh-right .rh-co  {font-size:11px !important;}
  .rh-right .rh-note{font-size:8px !important;}

  .r-info-cell{padding:5px 10px !important;}
  .r-info-lbl {font-size:7.5px !important;}
  .r-info-val {font-size:10px !important;}
  .r-notice   {padding:4px 14px !important; font-size:8.5px !important;}
  .r-notice span{font-size:8px !important;}

  .r-tbl th   {padding:4px 7px !important; font-size:7.5px !important;}
  .r-tbl td   {padding:4px 7px !important; font-size:10px !important;}
  .r-tbl tfoot td{padding:5px 7px !important; font-size:10.5px !important;}

  .r-sig-row  {padding:7px 14px 9px !important; gap:10px !important;}
  .r-sig-line {margin-top:22px !important;}
  .r-sig-lbl  {font-size:7.5px !important;}
  .r-sig-sub  {font-size:8.5px !important;}
  .r-office-strip{padding:4px 14px !important; font-size:8px !important;}
}
</style>
</head>
<body>

<div class="toolbar">
  <span style="color:#6b7280;font-size:11px">Invoice + Delivery Receipt — Single A4</span>
  <button class="tb-btn tb-print" onclick="window.print()">🖨 Print / Save PDF</button>
  <button class="tb-btn tb-close" onclick="window.close()">✕ Close</button>
</div>

<div class="a4-page">

  <!-- ════════════════════════════════════════════════════════
       INVOICE
  ════════════════════════════════════════════════════════ -->

  <div class="inv-header">

    <!-- Col 1: logo -->
    <?php if ($logo_base64): ?>
      <div class="co-logo"><img src="<?php echo $logo_base64; ?>" alt="Logo"></div>
    <?php else: ?>
      <div></div>
    <?php endif; ?>

    <!-- Col 2: company details — centred -->
    <div class="co-block">
      <div class="co-name"><?php echo htmlspecialchars($company['name']); ?></div>
      <div class="co-en"><?php echo htmlspecialchars($company['en_name']); ?></div>
      <div class="co-meta">
        <?php echo htmlspecialchars($company['address']); ?><br>
        Phone: <?php echo htmlspecialchars($company['phone']); ?> &nbsp;|&nbsp; <?php echo htmlspecialchars($company['email']); ?>
      </div>
    </div>

    <!-- Col 3: invoice info — right -->
    <div class="inv-right">
      <div class="inv-word">INVOICE</div>
      <div class="inv-num"><?php echo htmlspecialchars($order->order_number ?? ''); ?></div>
      <div class="inv-date">Date: <?php echo $invoice_date ? date('d M Y', strtotime($invoice_date)) : '—'; ?></div>
      <div><span class="s-pill"><?php echo strtoupper(str_replace('_', ' ', $order->status ?? '')); ?></span></div>
    </div>

  </div>

  <div class="info-row">

    <div class="info-col">
      <h4>Bill To — Customer</h4>
      <div class="nm"><?php echo htmlspecialchars($order->customer_name); ?></div>
      <?php if ($bill_address): ?><p><?php echo nl2br(htmlspecialchars($bill_address)); ?></p><?php endif; ?>
      <?php if ($order->customer_phone): ?><p>Phone: <?php echo htmlspecialchars($order->customer_phone); ?></p><?php endif; ?>
      <?php if ($order->customer_email): ?><p>Email: <?php echo htmlspecialchars($order->customer_email); ?></p><?php endif; ?>
    </div>

    <div class="info-col">
      <h4>Deliver To</h4>
      <?php if ($deliver_to): ?>
        <div class="nm" style="font-size:11.5px"><?php echo nl2br(htmlspecialchars($deliver_to)); ?></div>
      <?php else: ?>
        <p style="color:var(--muted);font-style:italic">Same as billing address</p>
      <?php endif; ?>
      <?php if ($order->branch_name): ?>
        <p style="margin-top:5px">
          <span style="display:inline-block;background:var(--grn-lt);color:var(--grn-dk);font-size:9px;font-weight:700;padding:2px 9px;border-radius:10px;border:1px solid var(--grn)">
            From: <?php echo htmlspecialchars($order->branch_name); ?>
          </span>
        </p>
      <?php endif; ?>
    </div>

    <div class="info-col">
      <h4>Order Details</h4>
      <div class="kv"><span class="k">Order No.:</span><span class="v"><?php echo htmlspecialchars($order->order_number ?? ''); ?></span></div>
      <div class="kv"><span class="k">Order Date:</span><span class="v"><?php echo $order->order_date ? date('d M Y', strtotime($order->order_date)) : '—'; ?></span></div>
      <div class="kv"><span class="k">Required:</span><span class="v"><?php echo $order->required_date ? date('d M Y', strtotime($order->required_date)) : '—'; ?></span></div>
      <?php if ($order->shipped_date): ?>
      <div class="kv"><span class="k">Shipped:</span><span class="v"><?php echo date('d M Y', strtotime($order->shipped_date)); ?></span></div>
      <?php endif; ?>
      <div class="kv"><span class="k">Type:</span><span class="v"><?php echo ucwords(str_replace('_', ' ', $order->order_type ?? '')); ?></span></div>
    </div>

  </div>

  <div class="sec-lbl">Order Items</div>
  <table class="itbl">
    <thead>
      <tr>
        <th class="c" style="width:3%">#</th>
        <th style="width:36%">Product Description</th>
        <th class="c" style="width:13%">SKU</th>
        <th class="r" style="width:9%">Qty</th>
        <th class="r" style="width:14%">Unit Price</th>
        <th class="r" style="width:11%">Discount</th>
        <th class="r" style="width:14%">Line Total</th>
      </tr>
    </thead>
    <tbody>
    <?php $n = 1; foreach ($render_items as $item):
      $qty_d = rtrim(rtrim(number_format($item->quantity, 2), '0'), '.'); ?>
      <tr>
        <td class="c" style="color:var(--muted)"><?php echo $n++; ?></td>
        <td>
          <div class="p-name"><?php echo htmlspecialchars($item->product_name); ?></div>
          <?php if ($item->variant_detail): ?>
            <div class="p-sub"><?php echo htmlspecialchars($item->variant_detail); ?></div>
          <?php endif; ?>
        </td>
        <td class="c" style="font-family:monospace;font-size:9px;color:var(--muted)">
          <?php echo $item->variant_sku ? htmlspecialchars($item->variant_sku) : '—'; ?>
        </td>
        <td class="r"><span class="qty-v"><?php echo $qty_d; ?></span></td>
        <td class="r">৳<?php echo number_format($item->unit_price, 2); ?></td>
        <td class="r">
          <?php if ($item->discount_amount > 0): ?>
            <span style="color:var(--muted);text-decoration:line-through">−৳<?php echo number_format($item->discount_amount, 2); ?></span>
          <?php else: ?><span style="color:var(--line)">—</span><?php endif; ?>
        </td>
        <td class="r"><span class="tot-v">৳<?php echo number_format($item->line_total, 2); ?></span></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot>
      <tr>
        <td colspan="3" style="font-style:italic;font-weight:400"><?php echo ($n - 1); ?> item<?php echo ($n > 2) ? 's' : ''; ?></td>
        <td class="r" style="color:var(--ink)"><?php echo rtrim(rtrim(number_format($total_qty, 2), '0'), '.'); ?></td>
        <td colspan="2"></td>
        <td class="r" style="color:var(--grn-dk)">৳<?php echo number_format($order->subtotal, 2); ?></td>
      </tr>
    </tfoot>
  </table>

  <div class="bot-row">
    <div class="bot-left">
      <?php if (!empty($order->truck_number) || !empty($order->driver_name)): ?>
        <div class="ship-ttl">Shipping Details</div>
        <?php if ($order->truck_number): ?><div class="kv2"><span class="k">Truck No.:</span><span class="v"><?php echo htmlspecialchars($order->truck_number); ?></span></div><?php endif; ?>
        <?php if ($order->driver_name):  ?><div class="kv2"><span class="k">Driver:</span><span class="v"><?php echo htmlspecialchars($order->driver_name); ?></span></div><?php endif; ?>
        <?php if ($order->driver_contact): ?><div class="kv2"><span class="k">Contact:</span><span class="v"><?php echo htmlspecialchars($order->driver_contact); ?></span></div><?php endif; ?>
        <?php if ($order->shipped_date): ?><div class="kv2"><span class="k">Shipped:</span><span class="v"><?php echo date('d M Y', strtotime($order->shipped_date)); ?></span></div><?php endif; ?>
        <?php if ($is_delivered && $order->delivered_date): ?><div class="kv2"><span class="k">Delivered:</span><span class="v"><?php echo date('d M Y', strtotime($order->delivered_date)); ?></span></div><?php endif; ?>
      <?php else: ?>
        <p style="font-size:9.5px;color:var(--muted);font-style:italic">No shipping details recorded.</p>
      <?php endif; ?>

      <?php if (!empty($order->special_instructions)): ?>
        <div style="margin-top:9px">
          <div class="ship-ttl">Notes</div>
          <p style="font-size:10.5px;color:var(--ink2)"><?php echo nl2br(htmlspecialchars($order->special_instructions)); ?></p>
        </div>
      <?php endif; ?>

      <div style="margin-top:10px;font-size:9.5px;color:var(--muted)">
        Prepared by: <strong style="color:var(--ink)"><?php echo htmlspecialchars($order->created_by_name ?? 'N/A'); ?></strong>
      </div>
    </div>

    <div class="bot-right">
      <?php if ($previous_due > 0): ?>
      <div class="prev-due-box">
        <span class="pl">Previous Balance Due</span>
        <span class="pv">৳<?php echo number_format($previous_due, 2); ?></span>
      </div>
      <?php endif; ?>

      <div class="tots-inner">
        <div class="tr2"><span class="tl">Subtotal</span><span class="tv">৳<?php echo number_format($order->subtotal, 2); ?></span></div>
        <?php if ($order->discount_amount > 0): ?>
        <div class="tr2"><span class="tl">Discount</span><span class="tv" style="text-decoration:line-through">−৳<?php echo number_format($order->discount_amount, 2); ?></span></div>
        <?php endif; ?>
        <?php if ($order->tax_amount > 0): ?>
        <div class="tr2"><span class="tl">Tax / VAT</span><span class="tv">৳<?php echo number_format($order->tax_amount, 2); ?></span></div>
        <?php endif; ?>
        <?php if (!empty($order->advance_paid) && $order->advance_paid > 0): ?>
        <div class="tr2"><span class="tl">Advance Paid</span><span class="tv">−৳<?php echo number_format($order->advance_paid, 2); ?></span></div>
        <?php endif; ?>
      </div>

      <div class="grand-row">
        <span class="gl">Total Amount</span>
        <span class="gv">৳<?php echo number_format($order->total_amount, 2); ?></span>
      </div>

      <?php if ($previous_due > 0):
        $total_outstanding = $previous_due + max(0, (float)$order->balance_due); ?>
      <div class="outstanding-row">
        <span class="ol">Total Outstanding</span>
        <span class="ov">৳<?php echo number_format($total_outstanding, 2); ?></span>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="sig-row">
    <div class="sig-box">
      <div class="sig-line">
        <div class="sig-lbl">Prepared By</div>
        <div class="sig-name"><?php echo htmlspecialchars($order->created_by_name ?? 'N/A'); ?></div>
      </div>
    </div>
    <div class="sig-box">
      <div class="sig-line">
        <div class="sig-lbl">Authorized By</div>
      </div>
    </div>
    <div class="sig-box">
      <div class="sig-line">
        <div class="sig-lbl">Received By (Customer)</div>
      </div>
    </div>
  </div>

  <!-- ════════════════════════════════════════════════════════
       CUT LINE
  ════════════════════════════════════════════════════════ -->
  <div class="cut-line">✂ &nbsp; CUT HERE — CUSTOMER RETURNS THIS COPY TO DISPATCHER &nbsp; ✂</div>

  <!-- ════════════════════════════════════════════════════════
       DELIVERY RECEIPT
  ════════════════════════════════════════════════════════ -->

  <div class="receipt-head">
    <div class="rh-left">
      <div class="rh-title">DELIVERY RECEIPT</div>
      <div class="rh-sub">Customer Acknowledgement — Return to Dispatcher</div>
    </div>
    <div class="rh-right">
      <div class="rh-co"><?php echo htmlspecialchars($company['en_name']); ?></div>
      <div class="rh-note">
        <?php echo htmlspecialchars($order->order_number); ?> &nbsp;|&nbsp;
        <?php echo $invoice_date ? date('d M Y', strtotime($invoice_date)) : '—'; ?>
      </div>
    </div>
  </div>

  <div class="r-info-bar">
    <div class="r-info-cell">
      <div class="r-info-lbl">Customer</div>
      <div class="r-info-val"><?php echo htmlspecialchars($order->customer_name); ?></div>
    </div>
    <?php if ($order->truck_number): ?>
    <div class="r-info-cell">
      <div class="r-info-lbl">Truck No.</div>
      <div class="r-info-val"><?php echo htmlspecialchars($order->truck_number); ?></div>
    </div>
    <?php endif; ?>
    <?php if ($order->driver_name): ?>
    <div class="r-info-cell">
      <div class="r-info-lbl">Driver</div>
      <div class="r-info-val"><?php echo htmlspecialchars($order->driver_name); ?></div>
    </div>
    <?php endif; ?>
    <?php if ($order->branch_name): ?>
    <div class="r-info-cell">
      <div class="r-info-lbl">Factory / Branch</div>
      <div class="r-info-val"><?php echo htmlspecialchars($order->branch_name); ?></div>
    </div>
    <?php endif; ?>
    <div class="r-info-cell">
      <div class="r-info-lbl">Delivery Date</div>
      <div class="r-info-val">
        <?php echo ($is_delivered && $order->delivered_date) ? date('d M Y', strtotime($order->delivered_date)) : '_______________'; ?>
      </div>
    </div>
  </div>

  <div class="r-notice">
    আমি/আমরা নিচের মালামাল সঠিক পরিমাণে ও ভালো অবস্থায় পেয়েছি।
    <span>I/We acknowledge receipt of the goods below in full and good condition.</span>
  </div>

  <table class="r-tbl">
    <thead>
      <tr>
        <th class="c" style="width:4%">#</th>
        <th style="width:44%">Product</th>
        <th class="c" style="width:16%">Pack / Grade</th>
        <th class="r" style="width:13%">Qty</th>
        <th class="r" style="width:13%">Unit Price</th>
        <th class="r" style="width:10%">Amount</th>
      </tr>
    </thead>
    <tbody>
    <?php $ri = 1; foreach ($render_items as $item):
      $qty_d = rtrim(rtrim(number_format($item->quantity, 2), '0'), '.'); ?>
      <tr>
        <td class="c" style="color:var(--muted)"><?php echo $ri++; ?></td>
        <td style="font-weight:700;color:var(--ink)"><?php echo htmlspecialchars($item->product_name); ?></td>
        <td class="c" style="font-size:9px;color:var(--muted)"><?php echo htmlspecialchars($item->variant_detail ?: '—'); ?></td>
        <td class="r" style="font-weight:700"><?php echo $qty_d; ?></td>
        <td class="r">৳<?php echo number_format($item->unit_price, 2); ?></td>
        <td class="r" style="font-weight:700;color:var(--grn-dk)">৳<?php echo number_format($item->line_total, 2); ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot>
      <tr>
        <td colspan="3" style="text-align:right;font-size:8.5px;color:rgba(255,255,255,.8);font-weight:600;letter-spacing:.5px">TOTAL AMOUNT</td>
        <td class="r"><?php echo rtrim(rtrim(number_format($total_qty, 2), '0'), '.'); ?> bags</td>
        <td></td>
        <td class="r">৳<?php echo number_format($order->total_amount, 2); ?></td>
      </tr>
    </tfoot>
  </table>

  <div class="r-sig-row">
    <div class="r-sig-box" style="flex:1.5">
      <div class="r-sig-line">
        <div class="r-sig-lbl">Customer Signature &amp; Seal</div>
        <div class="r-sig-sub">Name: ____________________________ &nbsp;&nbsp; Date: ______________</div>
      </div>
    </div>
    <div class="r-sig-box">
      <div class="r-sig-line">
        <div class="r-sig-lbl">Dispatcher / Driver</div>
        <div class="r-sig-sub">
          <?php echo $order->driver_name ? htmlspecialchars($order->driver_name) : 'Name: ____________________'; ?>
        </div>
      </div>
    </div>
    <div class="r-sig-box">
      <div class="r-sig-line">
        <div class="r-sig-lbl">Goods Verified</div>
        <div class="r-sig-sub">
          Bags: _______ / <?php echo rtrim(rtrim(number_format($total_qty, 2), '0'), '.'); ?>
          &nbsp; ☐ Good &nbsp; ☐ Damaged
        </div>
      </div>
    </div>
  </div>

  <div class="r-office-strip">
    <strong>Office use only:</strong> &nbsp;
    Received by: _________________________ &nbsp;|&nbsp;
    Recorded: _________________________ &nbsp;|&nbsp;
    Verified: _________________________
  </div>

</div><!-- /.a4-page -->

</body>
</html>