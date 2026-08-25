<?php
require_once '../core/init.php';

$allowed_roles = ['Superadmin', 'admin', 'Accounts', 'accounts-srg', 'accounts-demra',
                  'dispatch-srg', 'dispatch-demra', 'dispatchpos-demra', 'dispatchpos-srg',
                  'production manager-srg', 'production manager-demra'];
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
// If the shipping address is identical to the billing address, suppress the Deliver To block
// so it falls back to the "Same as billing address" note instead of duplicating.
if ($deliver_to !== '' && $deliver_to === $bill_address) $deliver_to = '';

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
*,*::before,*::after{margin:0;padding:0;box-sizing:border-box}

/* ── Black & white tabular palette — ALL text one single black ──────────── */
:root{
  --ink:     #000000;
  --ink2:    #000000;
  --muted:   #000000;
  --line:    #000000;   /* table cell borders */
  --line-lt: #000000;   /* separators */
}

body{
  font-family: Arial, sans-serif;
  font-size:14px; line-height:1.4;
  color:var(--ink2);
  background:#e5e7eb;
  padding:52px 20px 60px;
}

.toolbar{
  position:fixed; top:10px; left:50%; transform:translateX(-50%);
  display:flex; align-items:center; gap:8px;
  background:#fff; border:1px solid #d1d5db; border-radius:10px;
  padding:6px 16px; box-shadow:0 4px 20px rgba(0,0,0,.15);
  z-index:9999; font-size:12px; font-weight:600; color:#374151; white-space:nowrap;
}
.tb-btn{padding:5px 16px;border:none;border-radius:6px;cursor:pointer;font-size:12px;font-weight:700;}
.tb-btn:hover{filter:brightness(.88)}
.tb-print{background:#111827;color:#fff;}
.tb-close{background:#6b7280;color:#fff;}

/* ── A4 container ───────────────────────────────────────────────────────── */
.a4-page{
  width:794px; margin:0 auto; background:#fff;
  border:1.5px solid var(--ink);
  box-shadow:0 0 20px rgba(0,0,0,.15);
}

/* ══════════════════════════════════════════════════════
   INVOICE
══════════════════════════════════════════════════════ */
.inv-header{
  display:grid; grid-template-columns:auto 1fr auto;
  align-items:center; gap:14px;
  padding:14px 20px 12px;
  border-bottom:2px solid var(--ink);
}
.co-logo img{max-height:82px;max-width:82px;object-fit:contain;display:block;}
.co-block{text-align:center;}
.co-name{font-size:25px;font-weight:800;color:var(--ink);line-height:1.1;}
.co-en  {font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:1.2px;margin-top:2px;}
.co-meta{font-size:11px;color:var(--muted);margin-top:4px;line-height:1.6;text-align:center;}
.inv-right{text-align:right;}
.inv-word{
  display:inline-block;background:none;color:var(--ink);
  border:2px solid var(--ink);
  font-size:21px;font-weight:900;letter-spacing:5px;text-transform:uppercase;
  padding:4px 15px;margin-bottom:5px;
}
.inv-num {font-size:13px;font-weight:700;color:var(--ink);}
.inv-date{font-size:11.5px;color:var(--muted);margin-top:2px;}
.s-pill{
  display:inline-block;margin-top:5px;
  padding:2px 10px;border-radius:12px;
  font-size:10px;font-weight:700;letter-spacing:.7px;text-transform:uppercase;
  background:none;color:var(--ink);border:1px solid var(--ink);
}

/* Compact info row */
.info-row{display:grid;grid-template-columns:1fr 1fr 1fr;border-bottom:1px solid var(--line);}
.info-col{padding:10px 16px;border-right:1px solid var(--line);}
.info-col:last-child{border-right:none;}
.info-col h4{
  font-size:10px;font-weight:800;text-transform:uppercase;
  letter-spacing:.6px;color:var(--ink);
  margin-bottom:5px;padding-bottom:4px;
  border-bottom:1.5px solid var(--ink);
}
.info-col .nm{font-size:13px;font-weight:700;color:var(--ink);margin-bottom:2px;}
.info-col p  {font-size:11.5px;color:var(--ink2);margin:2px 0;}
.info-col .kv{display:flex;font-size:11.5px;margin:2.5px 0;}
.info-col .kv .k{color:var(--muted);min-width:76px;flex-shrink:0;}
.info-col .kv .v{color:var(--ink);font-weight:600;}

/* Items table — full tabular borders, no fills */
.sec-lbl{
  font-size:10px;font-weight:800;text-transform:uppercase;
  letter-spacing:.7px;color:var(--ink);
  padding:6px 16px 4px;
  display:flex;align-items:center;gap:6px;
}
.sec-lbl::after{content:'';flex:1;height:1px;background:var(--line-lt);}

.itbl{width:100%;border-collapse:collapse;}
.itbl th,.itbl td{border:1px solid var(--line);}
.itbl thead tr{background:none;}
.itbl th{padding:7px 8px;font-size:10px;font-weight:800;letter-spacing:.4px;text-transform:uppercase;color:var(--ink);border-bottom:2px solid var(--ink);}
.itbl th.r,.itbl td.r{text-align:right;}
.itbl th.c,.itbl td.c{text-align:center;}
.itbl td{padding:7px 8px;font-size:12px;color:var(--ink2);vertical-align:middle;}
.p-name{font-weight:700;color:var(--ink);font-size:12px;line-height:1.2;}
.p-sub {font-size:10px;color:var(--muted);margin-top:1px;}
.qty-v {font-weight:700;color:var(--ink);}
.tot-v {font-weight:700;color:var(--ink);}
.itbl tfoot td{padding:6px 8px;font-size:11px;font-weight:700;color:var(--ink);background:none;border-top:2px solid var(--ink);}

/* Bottom row */
.bot-row{display:flex;border-top:1px solid var(--line);}
.bot-left {flex:1;padding:10px 16px;border-right:1px solid var(--line);}
.bot-right{width:230px;flex-shrink:0;}
.ship-ttl{font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.6px;color:var(--ink);margin-bottom:5px;padding-bottom:3px;border-bottom:1.5px solid var(--ink);}
.kv2{display:flex;font-size:11.5px;margin:2.5px 0;}
.kv2 .k{color:var(--muted);min-width:80px;flex-shrink:0;}
.kv2 .v{color:var(--ink);font-weight:600;}
.tots-inner{padding:7px 10px;}
.tr2{display:flex;justify-content:space-between;padding:3px 0;font-size:11.5px;border-bottom:1px dashed var(--line-lt);}
.tr2:last-child{border:none;}
.tr2 .tl{color:var(--muted);}
.tr2 .tv{font-weight:600;color:var(--ink);}
.grand-row{display:flex;justify-content:space-between;align-items:center;padding:8px 10px;background:none;border-top:2px solid var(--ink);border-bottom:1px solid var(--line);}
.grand-row .gl{font-size:13px;font-weight:800;color:var(--ink);}
.grand-row .gv{font-size:17px;font-weight:900;color:var(--ink);}
.outstanding-row{display:flex;justify-content:space-between;align-items:center;padding:6px 10px;background:none;border-bottom:3px double var(--ink);}
.outstanding-row .ol{font-size:11px;font-weight:800;color:var(--ink);}
.outstanding-row .ov{font-size:14px;font-weight:900;color:var(--ink);}

/* Invoice signature row */
.sig-row{display:flex;gap:12px;padding:10px 16px 13px;border-top:1px solid var(--line);}
.sig-box{flex:1;}
.sig-line{border-top:1.5px solid var(--ink);margin-top:28px;padding-top:4px;}
.sig-lbl {font-size:9.5px;font-weight:800;text-transform:uppercase;letter-spacing:.5px;color:var(--ink);}
.sig-name{font-size:11px;color:var(--ink2);margin-top:2px;}

/* ══════════════════════════════════════════════════════
   CUT LINE
══════════════════════════════════════════════════════ */
.cut-line{
  display:flex;align-items:center;gap:8px;
  background:none;
  border-top:1px dashed var(--ink);border-bottom:1px dashed var(--ink);
  padding:4px 14px;
  font-size:9px;font-weight:700;color:var(--muted);
  letter-spacing:1.5px;text-transform:uppercase;user-select:none;
}
.cut-line::before,.cut-line::after{content:'';flex:1;height:1px;border-top:1px dashed var(--muted);}

/* ══════════════════════════════════════════════════════
   SHARED: Receipt & Gate Pass strip tables (qty only)
══════════════════════════════════════════════════════ */
.strip-head{
  display:flex;justify-content:space-between;align-items:center;
  padding:8px 16px;
  border-bottom:2px solid var(--ink);
}
.strip-head.dr-head{background:none;}
.strip-head.gp-head{background:none;}

.sh-left .sh-title{font-size:15px;font-weight:900;color:var(--ink);letter-spacing:3px;text-transform:uppercase;line-height:1;}
.sh-left .sh-sub  {font-size:9.5px;color:var(--muted);text-transform:uppercase;letter-spacing:.8px;margin-top:2px;}
.sh-right .sh-inv {font-size:12.5px;font-weight:800;color:var(--ink);text-align:right;}
.sh-right .sh-date{font-size:9.5px;color:var(--muted);text-align:right;margin-top:1px;}

/* Compact info strip — single row */
.strip-info{
  display:flex;border-bottom:1px solid var(--line);
}
.si-cell{flex:1;padding:5px 12px;border-right:1px solid var(--line);}
.si-cell:last-child{border-right:none;}
.si-lbl{font-size:9px;font-weight:800;text-transform:uppercase;letter-spacing:.4px;color:var(--muted);margin-bottom:1px;}
.si-val{font-weight:700;color:var(--ink);font-size:11.5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}

/* Qty-only table — tabular borders, no fills */
.q-tbl{width:100%;border-collapse:collapse;}
.q-tbl th,.q-tbl td{border:1px solid var(--line);}
.q-tbl thead tr{background:none;}
.gp-head-tbl .q-tbl thead tr{background:none;}
.q-tbl th{padding:5px 8px;font-size:9.5px;font-weight:800;letter-spacing:.4px;text-transform:uppercase;color:var(--ink);border-bottom:2px solid var(--ink);}
.q-tbl th.r,.q-tbl td.r{text-align:right;}
.q-tbl th.c,.q-tbl td.c{text-align:center;}
.q-tbl td{padding:5px 8px;font-size:11.5px;color:var(--ink2);}
.q-tbl tfoot tr{background:none;}
.gp-head-tbl .q-tbl tfoot tr{background:none;}
.q-tbl tfoot td{padding:6px 8px;font-size:12px;font-weight:800;color:var(--ink);border-top:2px solid var(--ink);}

/* Signature rows */
.strip-sig{display:flex;gap:10px;padding:8px 16px 11px;border-top:1px solid var(--line);}
.ss-box{flex:1;}
.ss-line{border-top:1.5px solid var(--ink);margin-top:24px;padding-top:4px;}
.ss-lbl{font-size:9px;font-weight:800;text-transform:uppercase;letter-spacing:.4px;color:var(--ink);}
.ss-sub{font-size:10px;color:var(--muted);margin-top:2px;}

.gp-footer{
  background:none;border-top:1px solid var(--line);
  padding:5px 16px;font-size:9.5px;color:var(--ink2);text-align:center;
}

/* ══════════════════════════════════════════════════════
   PRINT — wider page margin, bigger type, pure B&W
══════════════════════════════════════════════════════ */
@media print{
  @page{size:A4 portrait; margin:9mm 10mm}
  /* All text pure black in print — gray tones are near-invisible on paper */
  :root{
    --ink:#000; --ink2:#000; --muted:#000; --line:#000; --line-lt:#000;
  }
  html,body{
    background:#fff !important;padding:0 !important;margin:0 !important;
    font-size:11px !important; color:#000 !important;
    -webkit-print-color-adjust:exact;print-color-adjust:exact;
  }
  .toolbar{display:none !important}
  .a4-page{width:100% !important;box-shadow:none !important;border-width:1.5px !important;}

  /* Keep each block whole; the whole document must land on one sheet */
  .inv-header,.info-row,.bot-row,.sig-row,.strip-head,.strip-info,.strip-sig,.gp-footer,
  .itbl tr,.q-tbl tr{page-break-inside:avoid;}

  /* Invoice header */
  .inv-header{padding:8px 14px 6px !important;gap:10px !important;}
  .co-logo img{max-height:58px !important;max-width:58px !important;}
  .co-name{font-size:20px !important;}
  .co-en  {font-size:9.5px !important;}
  .co-meta{font-size:9.5px !important;margin-top:2px !important;}
  .inv-word{font-size:16px !important;letter-spacing:4px !important;padding:3px 12px !important;margin-bottom:4px !important;}
  .inv-num {font-size:11px !important;}
  .inv-date{font-size:10px !important;}
  .s-pill  {font-size:8.5px !important;margin-top:3px !important;}

  /* Info row */
  .info-col{padding:7px 11px !important;}
  .info-col h4{font-size:8.5px !important;margin-bottom:4px !important;padding-bottom:2px !important;}
  .info-col .nm{font-size:11px !important;margin-bottom:1px !important;}
  .info-col p  {font-size:10px !important;margin:1.5px 0 !important;}
  .info-col .kv{font-size:10px !important;margin:1.5px 0 !important;}
  .info-col .kv .k{min-width:64px !important;}

  /* Items */
  .sec-lbl{padding:4px 11px 2px !important;font-size:8.5px !important;}
  .itbl th {padding:4px 6px !important;font-size:8.5px !important;}
  .itbl td {padding:4px 6px !important;font-size:10.5px !important;}
  .p-name  {font-size:10.5px !important;}
  .p-sub   {font-size:8.5px !important;}
  .itbl tfoot td{padding:4px 6px !important;font-size:9.5px !important;}

  /* Bottom */
  .bot-left {padding:7px 11px !important;}
  .bot-right{width:205px !important;}
  .ship-ttl {font-size:8.5px !important;margin-bottom:4px !important;padding-bottom:2px !important;}
  .kv2      {font-size:10.5px !important;margin:2px 0 !important;}
  .kv2 .k   {min-width:72px !important;}
  .tots-inner{padding:5px 8px !important;}
  .tr2      {font-size:10.5px !important;padding:2px 0 !important;}
  .grand-row{padding:6px 8px !important;}
  .grand-row .gl{font-size:11px !important;}
  .grand-row .gv{font-size:14px !important;}
  .outstanding-row{padding:4px 8px !important;}
  .outstanding-row .ol{font-size:9.5px !important;}
  .outstanding-row .ov{font-size:12px !important;}

  /* Invoice sigs */
  .sig-row {padding:5px 11px 7px !important;gap:10px !important;}
  .sig-line{margin-top:14px !important;}
  .sig-lbl {font-size:8.5px !important;}
  .sig-name{font-size:10px !important;}

  /* Cut lines */
  .cut-line{padding:3px 11px !important;font-size:8.5px !important;}

  /* Strips */
  .strip-head{padding:6px 11px !important;}
  .sh-left .sh-title{font-size:12px !important;letter-spacing:2.5px !important;}
  .sh-left .sh-sub  {font-size:8px !important;}
  .sh-right .sh-inv {font-size:11px !important;}
  .sh-right .sh-date{font-size:8.5px !important;}
  .si-cell{padding:4px 9px !important;}
  .si-lbl {font-size:8px !important;}
  .si-val {font-size:10.5px !important;}

  .q-tbl th{padding:3px 6px !important;font-size:8px !important;}
  .q-tbl td{padding:3px 6px !important;font-size:10.5px !important;}
  .q-tbl tfoot td{padding:4px 6px !important;font-size:11px !important;}

  .strip-sig{padding:4px 11px 6px !important;gap:8px !important;}
  .ss-line  {margin-top:12px !important;}
  .ss-lbl   {font-size:8px !important;}
  .ss-sub   {font-size:8.5px !important;}
  .gp-footer{padding:2px 11px !important;font-size:8.5px !important;}
}
</style>
</head>
<body>

<div class="toolbar">
  <span style="color:#6b7280;font-size:11px">Invoice + Delivery Receipt + Gate Pass — Single A4</span>
  <button class="tb-btn tb-print" onclick="window.print()">🖨 Print / Save PDF</button>
  <button class="tb-btn tb-close" onclick="window.close()">✕ Close</button>
</div>

<div class="a4-page">

<!-- ══════════════════════════════════════════════════════════
     INVOICE
══════════════════════════════════════════════════════════ -->

  <div class="inv-header">
    <?php if ($logo_base64): ?>
      <div class="co-logo"><img src="<?php echo $logo_base64; ?>" alt="Logo"></div>
    <?php else: ?><div></div><?php endif; ?>
    <div class="co-block">
      <div class="co-name"><?php echo htmlspecialchars($company['name']); ?></div>
      <div class="co-en"><?php echo htmlspecialchars($company['en_name']); ?></div>
      <div class="co-meta">
        <?php echo htmlspecialchars($company['address']); ?><br>
        Phone: <?php echo htmlspecialchars($company['phone']); ?> &nbsp;|&nbsp; <?php echo htmlspecialchars($company['email']); ?>
      </div>
    </div>
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
        <div class="nm" style="font-size:12px"><?php echo nl2br(htmlspecialchars($deliver_to)); ?></div>
      <?php else: ?>
        <p style="color:var(--muted);font-style:italic">Same as billing address</p>
      <?php endif; ?>
      <?php if ($order->branch_name): ?>
        <p style="margin-top:4px">
          <span style="display:inline-block;background:none;color:var(--ink);font-size:10px;font-weight:700;padding:2px 8px;border-radius:10px;border:1px solid var(--ink)">
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
        <td class="c" style="font-family:monospace;font-size:10px;color:var(--muted)">
          <?php echo $item->variant_sku ? htmlspecialchars($item->variant_sku) : '—'; ?>
        </td>
        <td class="r"><span class="qty-v"><?php echo $qty_d; ?></span></td>
        <td class="r">৳<?php echo number_format($item->unit_price, 2); ?></td>
        <td class="r">
          <?php if ($item->discount_amount > 0): ?>
            <span style="color:var(--muted);text-decoration:line-through">−৳<?php echo number_format($item->discount_amount, 2); ?></span>
          <?php else: ?><span style="color:var(--line-lt)">—</span><?php endif; ?>
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
        <td class="r" style="color:var(--ink)">৳<?php echo number_format($order->subtotal, 2); ?></td>
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
        <p style="font-size:10px;color:var(--muted);font-style:italic">No shipping details recorded.</p>
      <?php endif; ?>
      <?php if (!empty($order->special_instructions)): ?>
        <div style="margin-top:7px">
          <div class="ship-ttl">Notes</div>
          <p style="font-size:11px;color:var(--ink2)"><?php echo nl2br(htmlspecialchars($order->special_instructions)); ?></p>
        </div>
      <?php endif; ?>
      <div style="margin-top:8px;font-size:10px;color:var(--muted)">
        Prepared by: <strong style="color:var(--ink)"><?php echo htmlspecialchars($order->created_by_name ?? 'N/A'); ?></strong>
      </div>
    </div>
    <div class="bot-right">
      <div class="tots-inner">
        <?php if ($order->discount_amount > 0): ?>
        <div class="tr2"><span class="tl">Discount</span><span class="tv">−৳<?php echo number_format($order->discount_amount, 2); ?></span></div>
        <?php endif; ?>
        <?php if ($order->tax_amount > 0): ?>
        <div class="tr2"><span class="tl">Tax / VAT</span><span class="tv">৳<?php echo number_format($order->tax_amount, 2); ?></span></div>
        <?php endif; ?>
        <?php if (!empty($order->advance_paid) && $order->advance_paid > 0): ?>
        <div class="tr2"><span class="tl">Advance Paid</span><span class="tv">−৳<?php echo number_format($order->advance_paid, 2); ?></span></div>
        <?php endif; ?>
      </div>
      <div class="grand-row">
        <span class="gl">Invoice Amount</span>
        <span class="gv">৳<?php echo number_format($order->total_amount, 2); ?></span>
      </div>
      <div class="tr2" style="padding:5px 12px;border-bottom:1px dashed var(--line-lt);">
        <span class="tl">Previous Due</span>
        <span class="tv">৳<?php echo number_format($previous_due, 2); ?></span>
      </div>
      <?php $total_outstanding = (float)$order->total_amount + $previous_due; ?>
      <div class="outstanding-row">
        <span class="ol">Total Outstanding</span>
        <span class="ov">৳<?php echo number_format($total_outstanding, 2); ?></span>
      </div>
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
      <div class="sig-line"><div class="sig-lbl">Authorized By</div></div>
    </div>
    <div class="sig-box">
      <div class="sig-line"><div class="sig-lbl">Received By (Customer)</div></div>
    </div>
  </div>

<!-- ══════════════════════════════════════════════════════════
     CUT LINE 1
══════════════════════════════════════════════════════════ -->
  <div class="cut-line">✂ &nbsp; CUT HERE — CUSTOMER COPY — RETURN TO DISPATCHER &nbsp; ✂</div>

<!-- ══════════════════════════════════════════════════════════
     DELIVERY RECEIPT
══════════════════════════════════════════════════════════ -->

  <div class="strip-head dr-head">
    <div class="sh-left">
      <div class="sh-title">DELIVERY RECEIPT</div>
      <div class="sh-sub">Customer Acknowledgement — Return to Dispatcher</div>
    </div>
    <div class="sh-right">
      <div class="sh-inv"><?php echo htmlspecialchars($order->order_number); ?></div>
      <div class="sh-date"><?php echo $invoice_date ? date('d M Y', strtotime($invoice_date)) : '—'; ?></div>
    </div>
  </div>

  <div class="strip-info">
    <div class="si-cell" style="flex:1.4">
      <div class="si-lbl">Invoice No.</div>
      <div class="si-val"><?php echo htmlspecialchars($order->order_number); ?></div>
    </div>
    <div class="si-cell" style="flex:2">
      <div class="si-lbl">Customer Name</div>
      <div class="si-val"><?php echo htmlspecialchars($order->customer_name); ?></div>
    </div>
    <div class="si-cell" style="flex:2.5">
      <div class="si-lbl">Delivery Address</div>
      <div class="si-val"><?php echo htmlspecialchars($deliver_to ?: $bill_address ?: '—'); ?></div>
    </div>
    <div class="si-cell" style="flex:1.2">
      <div class="si-lbl">Truck No.</div>
      <div class="si-val"><?php echo htmlspecialchars($order->truck_number ?: '—'); ?></div>
    </div>
  </div>

  <table class="q-tbl">
    <thead>
      <tr>
        <th class="c" style="width:5%">#</th>
        <th style="width:55%">Product</th>
        <th class="c" style="width:22%">Pack / Grade</th>
        <th class="r" style="width:18%">Qty Received</th>
      </tr>
    </thead>
    <tbody>
    <?php $ri = 1; foreach ($render_items as $item):
      $qty_d = rtrim(rtrim(number_format($item->quantity, 2), '0'), '.'); ?>
      <tr>
        <td class="c" style="color:var(--muted)"><?php echo $ri++; ?></td>
        <td style="font-weight:700;color:var(--ink)"><?php echo htmlspecialchars($item->product_name); ?></td>
        <td class="c" style="font-size:10px;color:var(--muted)"><?php echo htmlspecialchars($item->variant_detail ?: '—'); ?></td>
        <td class="r" style="font-weight:700"><?php echo $qty_d; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot>
      <tr>
        <td colspan="3" style="text-align:right;font-size:9px;color:var(--muted);letter-spacing:.5px">TOTAL QTY</td>
        <td class="r"><?php echo rtrim(rtrim(number_format($total_qty, 2), '0'), '.'); ?> bags</td>
      </tr>
    </tfoot>
  </table>

  <div class="strip-sig">
    <div class="ss-box" style="flex:1.5">
      <div class="ss-line">
        <div class="ss-lbl">Customer Signature &amp; Seal</div>
        <div class="ss-sub">Name: ____________________________ &nbsp; Date: ______________</div>
      </div>
    </div>
    <div class="ss-box">
      <div class="ss-line">
        <div class="ss-lbl">Dispatcher / Driver</div>
        <div class="ss-sub"><?php echo $order->driver_name ? htmlspecialchars($order->driver_name) : 'Name: ____________________'; ?></div>
      </div>
    </div>
    <div class="ss-box">
      <div class="ss-line">
        <div class="ss-lbl">Goods Verified</div>
        <div class="ss-sub">Bags: _______ / <?php echo rtrim(rtrim(number_format($total_qty, 2), '0'), '.'); ?> &nbsp; ☐ OK &nbsp; ☐ Short</div>
      </div>
    </div>
  </div>

<!-- ══════════════════════════════════════════════════════════
     CUT LINE 2
══════════════════════════════════════════════════════════ -->
  <div class="cut-line">✂ &nbsp; CUT HERE — GATE PASS — FOR SECURITY / CHECKPOST &nbsp; ✂</div>

<!-- ══════════════════════════════════════════════════════════
     GATE PASS
══════════════════════════════════════════════════════════ -->

  <div class="strip-head gp-head">
    <div class="sh-left">
      <div class="sh-title">GATE PASS</div>
      <div class="sh-sub"><?php echo htmlspecialchars($company['en_name']); ?> — Security / Checkpost Copy</div>
    </div>
    <div class="sh-right">
      <div class="sh-inv"><?php echo htmlspecialchars($order->order_number); ?></div>
      <div class="sh-date"><?php echo $invoice_date ? date('d M Y', strtotime($invoice_date)) : '—'; ?></div>
    </div>
  </div>

  <div class="strip-info">
    <div class="si-cell" style="flex:1.4">
      <div class="si-lbl">Invoice No.</div>
      <div class="si-val"><?php echo htmlspecialchars($order->order_number); ?></div>
    </div>
    <div class="si-cell" style="flex:2.5">
      <div class="si-lbl">Customer Name</div>
      <div class="si-val"><?php echo htmlspecialchars($order->customer_name); ?></div>
    </div>
    <div class="si-cell" style="flex:1.2">
      <div class="si-lbl">Truck No.</div>
      <div class="si-val"><?php echo htmlspecialchars($order->truck_number ?: '—'); ?></div>
    </div>
    <div class="si-cell" style="flex:1.2">
      <div class="si-lbl">Exit Time</div>
      <div class="si-val">___________</div>
    </div>
  </div>

  <div class="gp-head-tbl">
  <table class="q-tbl">
    <thead>
      <tr>
        <th class="c" style="width:5%">#</th>
        <th style="width:55%">Product</th>
        <th class="c" style="width:22%">Pack / Grade</th>
        <th class="r" style="width:18%">Qty</th>
      </tr>
    </thead>
    <tbody>
    <?php $gi = 1; foreach ($render_items as $item):
      $qty_d = rtrim(rtrim(number_format($item->quantity, 2), '0'), '.'); ?>
      <tr>
        <td class="c" style="color:var(--muted)"><?php echo $gi++; ?></td>
        <td style="font-weight:700;color:var(--ink)"><?php echo htmlspecialchars($item->product_name); ?></td>
        <td class="c" style="font-size:10px;color:var(--muted)"><?php echo htmlspecialchars($item->variant_detail ?: '—'); ?></td>
        <td class="r" style="font-weight:700"><?php echo $qty_d; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot>
      <tr>
        <td colspan="3" style="text-align:right;font-size:9px;color:var(--muted);letter-spacing:.5px">TOTAL QTY</td>
        <td class="r"><?php echo rtrim(rtrim(number_format($total_qty, 2), '0'), '.'); ?> bags</td>
      </tr>
    </tfoot>
  </table>
  </div>

  <div class="strip-sig">
    <div class="ss-box">
      <div class="ss-line">
        <div class="ss-lbl">Gate / Security Officer</div>
        <div class="ss-sub">Name: ____________________</div>
      </div>
    </div>
    <div class="ss-box">
      <div class="ss-line">
        <div class="ss-lbl">Authorized By (Factory)</div>
        <div class="ss-sub">Name: ____________________</div>
      </div>
    </div>
    <div class="ss-box">
      <div class="ss-line">
        <div class="ss-lbl">Truck Driver</div>
        <div class="ss-sub"><?php echo $order->driver_name ? htmlspecialchars($order->driver_name) : 'Name: ____________________'; ?></div>
      </div>
    </div>
  </div>

  <div class="gp-footer">
    Bags Counted at Gate: _______ / <?php echo rtrim(rtrim(number_format($total_qty, 2), '0'), '.'); ?>
    &nbsp;|&nbsp; Seal No.: _______________ &nbsp;|&nbsp; Vehicle Reg.: _______________
    &nbsp;|&nbsp; ☐ Cleared &nbsp; ☐ Hold
  </div>

</div><!-- /.a4-page -->

<script>
// ── Fit-to-one-page guard ─────────────────────────────────────────────────
// If the document is taller than one A4 sheet (long item lists), scale it
// down just before printing so everything always lands on a single page.
(function () {
    var page = document.querySelector('.a4-page');
    if (!page) return;

    // Usable print height: 297mm − (9mm × 2 margins) = 279mm ≈ 1054px @96dpi.
    // Screen layout uses larger fonts than print (~14px vs ~11px), so the
    // screen height overestimates the print height by roughly 1/0.82 — the
    // 0.82 factor converts, and the 0.97 fudge keeps a safety strip.
    var AVAIL_PX = 1054;

    function fit() {
        page.style.zoom = 1;
        var estPrintH = page.scrollHeight * 0.82;
        if (estPrintH > AVAIL_PX) {
            page.style.zoom = Math.max(0.55, (AVAIL_PX / estPrintH) * 0.97);
        }
    }
    function reset() { page.style.zoom = 1; }

    window.addEventListener('beforeprint', fit);
    window.addEventListener('afterprint', reset);
    // Safari fallback
    if (window.matchMedia) {
        window.matchMedia('print').addListener(function (m) { m.matches ? fit() : reset(); });
    }
})();
</script>
</body>
</html>

