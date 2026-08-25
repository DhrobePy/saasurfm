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

// ── Invoice Snapshot — use frozen data if available ───────────────────────────
$snap = $db->query(
    "SELECT * FROM invoice_snapshots WHERE order_id = ? LIMIT 1",
    [$order_id]
)->first();

// ── Previous due ──────────────────────────────────────────────────────────────
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

// ── Items — from snapshot or live query ───────────────────────────────────────
$snap_items = null;
if ($snap && !empty($snap->items_json)) {
    $snap_items = json_decode($snap->items_json, true);
}

// ── Company logo ──────────────────────────────────────────────────────────────
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

// Use snapshot company details if snapshot exists, otherwise use live config
$company = $snap ? [
    'name'    => $snap->company_name_bn  ?? 'উজ্জল ফ্লাওয়ার মিলস',
    'en_name' => $snap->company_name_en  ?? 'Ujjal Flour Mills',
    'address' => $snap->company_address  ?? '১৭, নুরাইবাগ, ডেমরা, ঢাকা',
    'phone'   => $snap->company_phone    ?? '+880-XXX-XXXXXX',
    'email'   => $snap->company_email    ?? 'info@ujjalfm.com',
    'website' => 'www.ujjalfm.com',
] : [
    'name'    => 'উজ্জল ফ্লাওয়ার মিলস',
    'en_name' => 'Ujjal Flour Mills',
    'address' => '১৭, নুরাইবাগ, ডেমরা, ঢাকা',
    'phone'   => '+880-XXX-XXXXXX',
    'email'   => 'info@ujjalfm.com',
    'website' => 'www.ujjalfm.com',
];

// Always override address/phone with live branch data when the branch has it set.
// Branch addresses are managed by admin in Settings and reflect the current factory location.
if (!empty($order->branch_address)) {
    $company['address'] = $order->branch_address;
}
if (!empty($order->branch_phone)) {
    $company['phone'] = $order->branch_phone;
}

// Use snapshot customer details if available (frozen at dispatch time)
if ($snap) {
    $order->customer_name    = $snap->customer_name;
    $order->customer_phone   = $snap->customer_phone;
    $order->customer_email   = $snap->customer_email;
    $order->customer_address = $snap->customer_address;
    if (!empty($snap->truck_number))  $order->truck_number  = $snap->truck_number;
    if (!empty($snap->driver_name))   $order->driver_name   = $snap->driver_name;
    if (!empty($snap->driver_contact))$order->driver_contact= $snap->driver_contact;
    if (!empty($snap->shipped_date))  $order->shipped_date  = $snap->shipped_date;
    if (!empty($snap->delivered_date))$order->delivered_date= $snap->delivered_date;
    if (!empty($snap->shipping_address)) $order->shipping_address = $snap->shipping_address;
}

$invoice_date = $snap->invoice_date ?? ($order->shipped_date ?? $order->order_date);
$has_shipping = !empty($order->truck_number);
$has_notes    = !empty($order->special_instructions);
$is_delivered = ($order->status === 'delivered');
$deliver_to   = trim($order->shipping_address ?? '');
$bill_address = trim($order->customer_address ?? '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Invoice — <?php echo htmlspecialchars($order->order_number); ?></title>
<style>
/* ── Reset ─────────────────────────────────────────────── */
*,*::before,*::after{margin:0;padding:0;box-sizing:border-box}

/* ── Tokens ─────────────────────────────────────────────── */
:root{
  --ink:  #0f172a; --ink2: #1e293b; --muted:#64748b; --line:#e2e8f0;
  --surf: #f8fafc;
  --blue: #1d4ed8; --blm:  #2563eb; --bll:  #3b82f6;
  --teal: #0891b2; --grn:  #059669; --red:  #dc2626; --amb:#d97706;
  --hd:   #0f172a;
}

/* ── Screen body ─────────────────────────────────────────── */
body{
  font-family:'Segoe UI',Arial,sans-serif;
  font-size:11.5px; line-height:1.5; color:var(--ink2);
  background:#94a3b8; padding:52px 20px 80px;
}

/* ── Toolbar ─────────────────────────────────────────────── */
.toolbar{
  position:fixed;top:10px;left:50%;transform:translateX(-50%);
  display:flex;align-items:center;gap:8px;
  background:#fff;border:1px solid #d1d5db;border-radius:10px;
  padding:6px 16px;box-shadow:0 4px 20px rgba(0,0,0,.15);
  z-index:9999;font-size:12px;font-weight:600;color:#374151;
  white-space:nowrap;
}
.tb-btn{padding:5px 16px;border:none;border-radius:6px;cursor:pointer;font-size:11.5px;font-weight:700}
.tb-btn:hover{filter:brightness(.88)}
.tb-print{background:var(--blm);color:#fff}
.tb-close{background:#475569;color:#fff}

/* ── Page shell ──────────────────────────────────────────── */
.page{
  width:794px;margin:0 auto;background:#fff;
  box-shadow:0 8px 40px rgba(0,0,0,.22);
  overflow:hidden;display:flex;flex-direction:column;
}

/* ══ HEADER ═══════════════════════════════════════════════ */
.inv-header{
  background:var(--hd);padding:20px 28px 16px;
  display:flex;justify-content:space-between;align-items:center;
  position:relative;overflow:hidden;flex-shrink:0;
}
.inv-header::before,.inv-header::after{
  content:'';position:absolute;border-radius:50%;background:#fff;opacity:.05;
}
.inv-header::before{width:200px;height:200px;top:-80px;right:120px}
.inv-header::after {width:140px;height:140px;bottom:-65px;right:20px}
.h-stripe{position:absolute;left:0;top:0;bottom:0;width:5px;
  background:linear-gradient(to bottom,var(--bll),var(--teal))}

/* Logo — leftmost, fixed width */
.co-logo-block{position:relative;z-index:1;flex-shrink:0;width:80px;display:flex;align-items:center;justify-content:flex-start}
.co-logo-block img{max-height:60px;max-width:72px;object-fit:contain;display:block}

/* Company name — center */
.co-block{position:relative;z-index:1;flex:1;text-align:center;display:flex;flex-direction:column;align-items:center}
.co-bn{font-size:22px;font-weight:800;color:#fff;letter-spacing:-.3px;line-height:1.1}
.co-en{font-size:10.5px;color:#93c5fd;font-weight:700;letter-spacing:1.2px;text-transform:uppercase;margin-top:2px}
.co-bar{width:38px;height:2.5px;margin:7px auto;background:linear-gradient(to right,var(--bll),var(--teal));border-radius:2px}
.co-meta{font-size:9.5px;color:#94a3b8;margin:2px 0;display:flex;align-items:center;justify-content:center;gap:5px}

/* Invoice details — right */
.inv-block{position:relative;z-index:1;text-align:right;flex-shrink:0}
.inv-word{font-size:34px;font-weight:900;color:#fff;letter-spacing:5px;text-transform:uppercase;line-height:1}
.inv-accent{height:3px;margin:6px 0;background:linear-gradient(to left,var(--bll),var(--teal));border-radius:2px}
.inv-num{font-size:10px;font-weight:600;color:#cbd5e1;letter-spacing:.3px}
.inv-date{font-size:9.5px;color:#94a3b8;margin-top:3px}
.s-pill{
  display:inline-block;margin-top:7px;padding:3px 12px;border-radius:20px;
  font-size:9px;font-weight:800;letter-spacing:1.2px;text-transform:uppercase;
}
.s-pill.shipped  {background:rgba(37,99,235,.3);color:#93c5fd;border:1px solid rgba(147,197,253,.25)}
.s-pill.delivered{background:rgba(5,150,105,.3); color:#6ee7b7;border:1px solid rgba(110,231,183,.25)}

/* ══ CONTENT ══════════════════════════════════════════════ */
.content{flex:1;display:flex;flex-direction:column;padding:14px 28px 0}

/* ── Info cards ──────────────────────────────────────────── */
.cards-row{display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px;margin-bottom:12px;flex-shrink:0}
.ic{border:1px solid var(--line);border-radius:6px;overflow:hidden}
.ic-h{
  display:flex;align-items:center;gap:6px;padding:5px 10px;
  background:var(--blm);font-size:8.5px;font-weight:800;letter-spacing:.7px;
  text-transform:uppercase;color:#fff;
}
/* Deliver To card gets teal header to stand out */
.ic.deliver .ic-h{background:linear-gradient(135deg,#0369a1,var(--teal))}

.ic-b{padding:8px 10px;background:#fff}
.ic-b p{font-size:10.5px;color:var(--ink2);margin:2px 0;line-height:1.4}
.ic-b .nm{font-size:12px;font-weight:700;color:var(--ink);margin-bottom:3px}

/* Deliver-to address — large & prominent */
.deliver-addr{
  font-size:13px;font-weight:800;color:var(--ink);
  line-height:1.25;margin-bottom:4px;
}
.deliver-from{
  display:inline-flex;align-items:center;gap:4px;
  margin-top:4px;padding:2px 8px;border-radius:12px;
  background:#eff6ff;border:1px solid #bfdbfe;
  font-size:9px;font-weight:700;color:var(--blm);
}

.ic-b .kv{display:flex;margin:2px 0;font-size:10px}
.ic-b .kv .k{color:var(--muted);min-width:80px;flex-shrink:0}
.ic-b .kv .v{color:var(--ink);font-weight:600}

/* ── Section label ───────────────────────────────────────── */
.sec-lbl{
  display:flex;align-items:center;gap:8px;margin-bottom:5px;
  font-size:8.5px;font-weight:800;letter-spacing:.9px;
  text-transform:uppercase;color:var(--muted);flex-shrink:0;
}
.sec-lbl::after{content:'';flex:1;height:1px;background:var(--line)}

/* ── Items table ─────────────────────────────────────────── */
.itbl{width:100%;border-collapse:collapse;margin-bottom:0;flex-shrink:0}
.itbl thead tr{background:var(--hd)}
.itbl th{
  padding:7px 7px;font-size:8.5px;font-weight:700;
  letter-spacing:.4px;text-transform:uppercase;color:#e2e8f0;
}
.itbl th:first-child{border-radius:4px 0 0 0}
.itbl th:last-child {border-radius:0 4px 0 0}
.itbl th.r,.itbl td.r{text-align:right}
.itbl th.c,.itbl td.c{text-align:center}
.itbl tbody tr{border-bottom:1px solid var(--line)}
.itbl tbody tr:nth-child(odd) {background:#fff}
.itbl tbody tr:nth-child(even){background:#f8fafc}
.itbl td{padding:7px 7px;font-size:10.5px;color:var(--ink2);vertical-align:middle}
.p-name{font-weight:700;color:var(--ink);line-height:1.2}
.p-meta{font-size:8.5px;color:var(--muted);margin-top:1px}
/* SKU — monospaced, small, no-wrap so it never wraps the row */
.sku{font-family:monospace;font-size:8.5px;color:var(--muted);
     white-space:nowrap;max-width:90px;overflow:hidden;text-overflow:ellipsis;display:block}
.disc-v{color:var(--red)}
.tot-v {font-weight:700;color:var(--ink)}
/* qty — just the number, no unit */
.qty-v {font-weight:600;color:var(--ink)}
.itbl tfoot tr{background:var(--surf);border-top:2px solid var(--hd)}
.itbl tfoot td{padding:5px 7px;font-size:9.5px;font-weight:700;color:var(--muted)}

/* ── Bottom row: shipping + totals ───────────────────────── */
.bot{display:flex;gap:12px;align-items:flex-start;margin-top:12px;margin-bottom:12px;flex-shrink:0}
.bot-l{flex:1;display:flex;flex-direction:column;gap:8px}
.bot-r{width:225px;flex-shrink:0}

/* shipping card */
.ship{border:1px solid #bae6fd;border-radius:6px;overflow:hidden}
.ship-h{
  display:flex;align-items:center;gap:6px;padding:5px 10px;
  background:linear-gradient(135deg,#0369a1,var(--teal));
  font-size:8.5px;font-weight:800;letter-spacing:.7px;text-transform:uppercase;color:#fff;
}
.ship-b{padding:8px 10px;background:#f0f9ff}
.kv2{display:flex;margin:2.5px 0;font-size:10px}
.kv2 .k{color:var(--muted);min-width:98px;flex-shrink:0}
.kv2 .v{color:var(--ink);font-weight:600}

/* notes card */
.notes{border:1px solid #fde68a;border-radius:6px;overflow:hidden}
.notes-h{
  display:flex;align-items:center;gap:6px;padding:5px 10px;
  background:linear-gradient(135deg,#b45309,var(--amb));
  font-size:8.5px;font-weight:800;letter-spacing:.7px;text-transform:uppercase;color:#fff;
}
.notes-b{padding:8px 10px;background:#fffbeb;font-size:10.5px;color:var(--ink2);line-height:1.5}

/* totals box */
.tots{border:1px solid var(--line);border-radius:6px;overflow:hidden}
.tots-h{padding:5px 10px;background:var(--hd);
  font-size:8.5px;font-weight:800;letter-spacing:.7px;text-transform:uppercase;color:#e2e8f0}
.tots-b{padding:6px 10px}
.tr2{display:flex;justify-content:space-between;padding:3px 0;font-size:10.5px;
     border-bottom:1px dashed var(--line)}
.tr2:last-child{border:none}
.tr2 .tl{color:var(--muted)}
.tr2 .tv{font-weight:600;color:var(--ink)}
.tr2.disc .tv{color:var(--red)}
.tr2.paid .tv{color:var(--grn)}
.grand{display:flex;justify-content:space-between;align-items:center;
       padding:7px 10px;background:var(--hd);font-weight:800;color:#fff}
.grand .gl{font-size:12px}
.grand .gv{font-size:14px}
.bal{display:flex;justify-content:space-between;align-items:center;
     padding:7px 10px;background:linear-gradient(135deg,#b45309,#f59e0b);font-weight:800;color:#fff}
.bal .bl{font-size:11.5px}
.bal .bv{font-size:13px}
.paid-full{
  display:flex;align-items:center;justify-content:center;gap:6px;
  padding:7px 10px;background:linear-gradient(135deg,#065f46,#059669);
  font-size:10.5px;font-weight:800;color:#fff;
}
.tot-outstanding{
  display:flex;justify-content:space-between;align-items:center;
  padding:8px 10px;background:linear-gradient(135deg,#7f1d1d,#dc2626);
  font-weight:800;color:#fff;border-top:2px solid #fff3;
}
.tot-outstanding .to-l{font-size:11px;letter-spacing:.3px}
.tot-outstanding .to-v{font-size:15px}

/* ── Signatures ───────────────────────────────────────────── */
.sig-wrap{flex-shrink:0;padding-bottom:14px}
.sig-row{display:flex;gap:12px}
.sig-box{flex:1}
.sig-line{border-top:1.5px solid #94a3b8;margin-top:30px;padding-top:4px}
.sig-lbl{font-size:8.5px;font-weight:800;text-transform:uppercase;letter-spacing:.5px;color:var(--muted)}
.sig-name{font-size:9.5px;color:var(--ink2);margin-top:1px}

/* ── Footer band ─────────────────────────────────────────── */
.inv-foot{
  background:var(--hd);padding:9px 28px;flex-shrink:0;
  display:flex;justify-content:space-between;align-items:center;
}
.ft-terms{font-size:9.5px;color:#e2e8f0;font-weight:600}
.ft-terms span{color:#93c5fd}
.ft-mid{font-size:9px;color:#94a3b8;flex:1;text-align:center}
.ft-r{font-size:8.5px;color:#64748b;text-align:right;line-height:1.6}

/* ══════════════════════════════════════════════════════════
   PRINT
══════════════════════════════════════════════════════════ */
@media print{
  @page{size:A4 portrait;margin:10mm 12mm}

  html,body{
    background:#fff!important;padding:0!important;margin:0!important;
    font-size:11px!important;
    -webkit-print-color-adjust:exact;print-color-adjust:exact;
  }
  .toolbar{display:none!important}

  /*
   * KEY FIX: NO fixed height on .page — let content flow naturally.
   * The content budget at 10mm margins:
   *   A4 height = 297mm − 20mm margins = 277mm ≈ 785px @72dpi
   * Sections (print-tightened):
   *   header ~28mm + cards ~24mm + table ~36mm + bottom ~38mm + sig ~20mm + footer ~10mm
   *   + gaps ~12mm  =  ~168mm  →  fits with 109mm to spare (no overflow)
   */
  .page{
    width:100%!important;
    box-shadow:none!important;
    overflow:visible!important;   /* must NOT clip in print */
    display:block!important;      /* plain block — no flex tricks */
  }

  /* ── Header ── */
  .inv-header{padding:14px 22px 12px!important;align-items:center!important}
  .co-logo-block{width:70px!important}
  .co-logo-block img{max-height:52px!important;max-width:64px!important}
  .co-bn    {font-size:19px!important}
  .co-en    {font-size:9.5px!important}
  .co-bar   {margin:5px auto!important}
  .co-meta  {font-size:9px!important;margin:1.5px 0!important;justify-content:center!important}
  .inv-word {font-size:28px!important}
  .inv-num  {font-size:9.5px!important}
  .inv-date {font-size:9px!important}
  .s-pill   {font-size:8.5px!important;margin-top:5px!important}

  /* ── Content ── */
  .content{display:block!important;flex:none!important;padding:12px 22px 0!important}

  /* ── Cards ── */
  .cards-row   {gap:6px!important;margin-bottom:10px!important}
  .ic-h        {padding:5px 8px!important;font-size:8px!important}
  .ic-b        {padding:7px 8px!important}
  .ic-b p      {font-size:9.5px!important;margin:1.5px 0!important}
  .ic-b .nm    {font-size:11px!important;margin-bottom:2px!important}
  .deliver-addr{font-size:12px!important}
  .deliver-from{font-size:8.5px!important}
  .ic-b .kv    {font-size:9.5px!important;margin:1.5px 0!important}
  .ic-b .kv .k {min-width:72px!important}

  /* ── Section label ── */
  .sec-lbl{font-size:8px!important;margin-bottom:4px!important}

  /* ── Table ── */
  .itbl th{padding:5px 6px!important;font-size:8px!important}
  .itbl td{padding:5.5px 6px!important;font-size:9.5px!important}
  .p-name {font-size:9.5px!important}
  .p-meta {font-size:8px!important}
  .sku    {font-size:8px!important;max-width:80px!important}
  .itbl tfoot td{padding:4px 6px!important;font-size:9px!important}

  /* ── Bottom row ── */
  .bot   {margin-top:10px!important;margin-bottom:10px!important;gap:10px!important;display:flex!important}
  .bot-r {width:210px!important}
  .ship-h,.notes-h,.tots-h{padding:5px 8px!important;font-size:8px!important}
  .ship-b{padding:7px 8px!important}
  .kv2   {font-size:9.5px!important;margin:2px 0!important}
  .kv2 .k{min-width:90px!important}
  .notes-b{padding:7px 8px!important;font-size:9.5px!important}
  .tots-b{padding:5px 8px!important}
  .tr2   {font-size:9.5px!important;padding:2.5px 0!important}
  .grand {padding:5px 8px!important}
  .grand .gl{font-size:11px!important}
  .grand .gv{font-size:12.5px!important}
  .bal   {padding:5px 8px!important}
  .bal .bl{font-size:10.5px!important}
  .bal .bv{font-size:12px!important}
  .paid-full{padding:5px 8px!important;font-size:9.5px!important}
  .tot-outstanding{padding:6px 8px!important;background:#dc2626!important}
  .tot-outstanding .to-l{font-size:10px!important}
  .tot-outstanding .to-v{font-size:13px!important}

  /* ── Signatures ── */
  .sig-wrap  {padding-bottom:10px!important}
  .sig-line  {margin-top:22px!important}
  .sig-lbl   {font-size:8px!important}
  .sig-name  {font-size:9px!important}

  /* ── Footer ── */
  .inv-foot  {padding:7px 22px!important}
  .ft-terms  {font-size:9px!important}
  .ft-mid    {font-size:8.5px!important}
  .ft-r      {font-size:8px!important}

  /* ── Force colours ── */
  .inv-header    {background:#0f172a!important}
  .h-stripe      {background:linear-gradient(to bottom,#3b82f6,#0891b2)!important}
  .ic-h          {background:#2563eb!important}
  .ic.deliver .ic-h{background:#0369a1!important}
  .itbl thead tr {background:#0f172a!important}
  .tots-h        {background:#0f172a!important}
  .grand         {background:#0f172a!important}
  .ship-h        {background:#0369a1!important}
  .ship-b        {background:#f0f9ff!important}
  .notes-h       {background:#b45309!important}
  .notes-b       {background:#fffbeb!important}
  .bal           {background:#d97706!important}
  .paid-full     {background:#059669!important}
  .inv-foot      {background:#0f172a!important}
  .deliver-from  {background:#eff6ff!important;border-color:#bfdbfe!important}
}
</style>
</head>
<body>

<div class="toolbar">
  <span style="color:#6b7280">📄 Credit Invoice</span>
  <button class="tb-btn tb-print" onclick="window.print()">🖨 Print / Save PDF</button>
  <button class="tb-btn tb-close" onclick="window.close()">✕ Close</button>
</div>

<div class="page">

  <!-- ═══ HEADER ══════════════════════════════════════════════ -->
  <div class="inv-header">
    <div class="h-stripe"></div>

    <!-- Logo — leftmost -->
    <div class="co-logo-block">
      <?php if ($logo_base64): ?>
      <img src="<?php echo $logo_base64; ?>" alt="Company Logo">
      <?php endif; ?>
    </div>

    <!-- Company name — centered -->
    <div class="co-block">
      <div class="co-bn"><?php echo htmlspecialchars($company['name']); ?></div>
      <div class="co-en"><?php echo htmlspecialchars($company['en_name']); ?></div>
      <div class="co-bar"></div>
      <div class="co-meta">
        <svg width="9" height="9" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M12 2C8 2 5 5 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-4-3-7-7-7z"/><circle cx="12" cy="9" r="2.5"/></svg>
        <?php echo htmlspecialchars($company['address']); ?>
      </div>
      <div class="co-meta">
        <svg width="9" height="9" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M22 16.92v3a2 2 0 01-2.18 2 19.79 19.79 0 01-8.63-3.07A19.5 19.5 0 013.07 9.81 19.79 19.79 0 01.92 2 2 2 0 012 .92h3a2 2 0 012 1.72 12.84 12.84 0 00.7 2.81 2 2 0 01-.45 2.11L6.09 8.91a16 16 0 006 6l1.27-1.27a2 2 0 012.11-.45 12.84 12.84 0 002.81.7A2 2 0 0122 16.92z"/></svg>
        <?php echo htmlspecialchars($company['phone']); ?>
        &nbsp;&nbsp;
        <svg width="9" height="9" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
        <?php echo htmlspecialchars($company['email']); ?>
      </div>
    </div>

    <!-- Invoice details — right -->
    <div class="inv-block">
      <div class="inv-word">INVOICE</div>
      <div class="inv-accent"></div>
      <div class="inv-num"><?php echo htmlspecialchars($order->order_number ?? ''); ?></div>
      <div class="inv-date">Date: <?php echo $invoice_date ? date('d M Y', strtotime($invoice_date)) : '—'; ?></div>
      <div><span class="s-pill <?php echo htmlspecialchars($order->status ?? ''); ?>"><?php echo strtoupper(str_replace('_',' ',$order->status ?? '')); ?></span></div>
    </div>
  </div>

  <!-- ═══ CONTENT ═════════════════════════════════════════════ -->
  <div class="content">

    <!-- INFO CARDS: Customer | Deliver To | Order Details -->
    <div class="cards-row">

      <!-- Card 1: Customer / Bill To -->
      <div class="ic">
        <div class="ic-h">
          <svg width="9" height="9" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
          Bill To — Customer
        </div>
        <div class="ic-b">
          <p class="nm"><?php echo htmlspecialchars($order->customer_name); ?></p>
          <?php if ($bill_address): ?>
          <p><?php echo nl2br(htmlspecialchars($bill_address)); ?></p>
          <?php endif; ?>
          <?php if ($order->customer_phone): ?>
          <p>
            <svg style="vertical-align:middle" width="9" height="9" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M22 16.92v3a2 2 0 01-2.18 2 19.79 19.79 0 01-8.63-3.07A19.5 19.5 0 013.07 9.81 19.79 19.79 0 01.92 2 2 2 0 012 .92h3a2 2 0 012 1.72 12.84 12.84 0 00.7 2.81 2 2 0 01-.45 2.11L6.09 8.91a16 16 0 006 6l1.27-1.27a2 2 0 012.11-.45 12.84 12.84 0 002.81.7A2 2 0 0122 16.92z"/></svg>
            <?php echo htmlspecialchars($order->customer_phone); ?>
          </p>
          <?php endif; ?>
          <?php if ($order->customer_email): ?>
          <p>
            <svg style="vertical-align:middle" width="9" height="9" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
            <?php echo htmlspecialchars($order->customer_email); ?>
          </p>
          <?php endif; ?>
        </div>
      </div>

      <!-- Card 2: Deliver To (shipping address — prominent) -->
      <div class="ic deliver">
        <div class="ic-h">
          <svg width="9" height="9" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><rect x="1" y="3" width="15" height="13"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>
          Deliver To
        </div>
        <div class="ic-b">
          <?php if ($deliver_to): ?>
            <div class="deliver-addr"><?php echo nl2br(htmlspecialchars($deliver_to)); ?></div>
          <?php else: ?>
            <div class="deliver-addr" style="color:var(--muted);font-weight:400;font-size:10px;font-style:italic;">Same as billing address</div>
          <?php endif; ?>
          <?php if ($order->branch_name): ?>
          <div class="deliver-from">
            <svg width="8" height="8" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/></svg>
            From: <?php echo htmlspecialchars($order->branch_name); ?>
          </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- Card 3: Order Details -->
      <div class="ic">
        <div class="ic-h">
          <svg width="9" height="9" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
          Order Details
        </div>
        <div class="ic-b">
          <div class="kv"><span class="k">Order No.:</span><span class="v"><?php echo htmlspecialchars($order->order_number ?? ''); ?></span></div>
          <div class="kv"><span class="k">Order Date:</span><span class="v"><?php echo $order->order_date ? date('d M Y', strtotime($order->order_date)) : '—'; ?></span></div>
          <div class="kv"><span class="k">Required:</span><span class="v"><?php echo $order->required_date ? date('d M Y', strtotime($order->required_date)) : '—'; ?></span></div>
          <?php if ($order->shipped_date): ?>
          <div class="kv"><span class="k">Shipped:</span><span class="v"><?php echo date('d M Y', strtotime($order->shipped_date)); ?></span></div>
          <?php endif; ?>
          <div class="kv"><span class="k">Type:</span><span class="v"><?php echo ucwords(str_replace('_',' ',$order->order_type ?? '')); ?></span></div>
        </div>
      </div>

    </div><!-- /.cards-row -->

    <!-- ITEMS TABLE -->
    <div class="sec-lbl">Order Items</div>
    <table class="itbl">
      <thead>
        <tr>
          <th class="c" style="width:3%">#</th>
          <th style="width:34%">Product Description</th>
          <th class="c" style="width:14%">SKU</th>
          <th class="r" style="width:9%">Qty</th>
          <th class="r" style="width:14%">Unit Price</th>
          <th class="r" style="width:11%">Discount</th>
          <th class="r" style="width:15%">Line Total</th>
        </tr>
      </thead>
      <tbody>
      <?php
      // Use snapshot items (frozen at dispatch) if available, else live DB query
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
              $vd = [];
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

      $item_num  = 1;
      $total_qty = 0;
      foreach ($render_items as $item):
        $total_qty  += (float)$item->quantity;
        $qty_display = rtrim(rtrim(number_format($item->quantity, 2), '0'), '.');
      ?>
        <tr>
          <td class="c" style="color:var(--muted)"><?php echo $item_num++; ?></td>
          <td>
            <div class="p-name"><?php echo htmlspecialchars($item->product_name); ?></div>
            <?php if ($item->variant_detail): ?><div class="p-meta"><?php echo htmlspecialchars($item->variant_detail); ?></div><?php endif; ?>
          </td>
          <td class="c">
            <?php if ($item->variant_sku): ?>
              <span class="sku" title="<?php echo htmlspecialchars($item->variant_sku); ?>"><?php echo htmlspecialchars($item->variant_sku); ?></span>
            <?php else: ?>—<?php endif; ?>
          </td>
          <td class="r"><span class="qty-v"><?php echo $qty_display; ?></span></td>
          <td class="r">৳<?php echo number_format($item->unit_price, 2); ?></td>
          <td class="r">
            <?php if ($item->discount_amount > 0): ?>
              <span class="disc-v">−৳<?php echo number_format($item->discount_amount, 2); ?></span>
            <?php else: ?><span style="color:var(--line)">—</span><?php endif; ?>
          </td>
          <td class="r"><span class="tot-v">৳<?php echo number_format($item->line_total, 2); ?></span></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr>
          <td colspan="3" style="font-style:italic;font-weight:400;color:var(--muted)">
            <?php echo ($item_num-1); ?> line item<?php echo ($item_num>2)?'s':''; ?>
          </td>
          <td class="r" style="color:var(--ink)">
            <?php echo rtrim(rtrim(number_format($total_qty,2),'0'),'.'); ?>
          </td>
          <td colspan="2"></td>
          <td class="r" style="color:var(--ink)">৳<?php echo number_format($order->subtotal, 2); ?></td>
        </tr>
      </tfoot>
    </table>

    <!-- BOTTOM ROW: shipping + totals -->
    <div class="bot">

      <div class="bot-l">
        <?php if ($has_shipping): ?>
        <div class="ship">
          <div class="ship-h">
            <svg width="9" height="9" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><rect x="1" y="3" width="15" height="13"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>
            Shipping Details
          </div>
          <div class="ship-b">
            <?php if ($order->truck_number): ?>
            <div class="kv2"><span class="k">Truck No.:</span><span class="v"><?php echo htmlspecialchars($order->truck_number); ?></span></div>
            <?php endif; ?>
            <?php if ($order->driver_name): ?>
            <div class="kv2"><span class="k">Driver:</span><span class="v"><?php echo htmlspecialchars($order->driver_name); ?></span></div>
            <?php endif; ?>
            <?php if ($order->driver_contact): ?>
            <div class="kv2"><span class="k">Driver Contact:</span><span class="v"><?php echo htmlspecialchars($order->driver_contact); ?></span></div>
            <?php endif; ?>
            <?php if ($order->shipped_date): ?>
            <div class="kv2"><span class="k">Shipped:</span><span class="v"><?php echo date('d M Y, g:i A', strtotime($order->shipped_date)); ?></span></div>
            <?php endif; ?>
            <?php if ($is_delivered && $order->delivered_date): ?>
            <div class="kv2"><span class="k">Delivered:</span><span class="v"><?php echo date('d M Y, g:i A', strtotime($order->delivered_date)); ?></span></div>
            <?php endif; ?>
          </div>
        </div>
        <?php endif; ?>

        <?php if ($has_notes): ?>
        <div class="notes">
          <div class="notes-h">
            <svg width="9" height="9" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
            Special Instructions
          </div>
          <div class="notes-b"><?php echo nl2br(htmlspecialchars($order->special_instructions)); ?></div>
        </div>
        <?php endif; ?>

        <?php if (!$has_shipping && !$has_notes): ?>
        <p style="font-size:9px;color:#94a3b8;font-style:italic;padding:4px 0">No shipping details recorded.</p>
        <?php endif; ?>
      </div>

      <div class="bot-r">
        <div class="tots">
          <div class="tots-h">Summary</div>
          <div class="tots-b">
            <?php if ($previous_due > 0): ?>
            <div style="display:flex;justify-content:space-between;align-items:center;
                        background:linear-gradient(135deg,#78350f,#d97706);
                        border-radius:4px;padding:5px 8px;margin-bottom:5px;">
              <span style="color:#fef3c7;font-weight:800;font-size:10px;letter-spacing:.3px">Opening Balance Due</span>
              <span style="color:#fff;font-weight:800;font-size:12px">৳<?php echo number_format($previous_due, 2); ?></span>
            </div>
            <div style="font-size:8px;color:var(--muted);text-align:center;margin-bottom:4px;letter-spacing:.5px;text-transform:uppercase">+ This Invoice</div>
            <?php endif; ?>
            <div class="tr2"><span class="tl">Subtotal</span><span class="tv">৳<?php echo number_format($order->subtotal, 2); ?></span></div>
            <?php if ($order->discount_amount > 0): ?>
            <div class="tr2 disc"><span class="tl">Discount</span><span class="tv">−৳<?php echo number_format($order->discount_amount, 2); ?></span></div>
            <?php endif; ?>
            <?php if ($order->tax_amount > 0): ?>
            <div class="tr2"><span class="tl">Tax / VAT</span><span class="tv">৳<?php echo number_format($order->tax_amount, 2); ?></span></div>
            <?php endif; ?>
          </div>
          <div class="grand">
            <span class="gl">Total Amount</span>
            <span class="gv">৳<?php echo number_format($order->total_amount, 2); ?></span>
          </div>
          <?php if (!empty($order->advance_paid) && $order->advance_paid > 0): ?>
          <div class="tots-b" style="padding:4px 10px">
            <div class="tr2 paid" style="border:none"><span class="tl">Advance Paid</span><span class="tv">−৳<?php echo number_format($order->advance_paid, 2); ?></span></div>
          </div>
          <?php endif; ?>
          <?php if ($order->balance_due <= 0): ?>
          <div class="paid-full">
            <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
            PAID IN FULL — This Invoice
          </div>
          <?php else: ?>
          <div class="bal">
            <span class="bl">Balance Due (This Invoice)</span>
            <span class="bv">৳<?php echo number_format($order->balance_due, 2); ?></span>
          </div>
          <?php endif; ?>
          <?php if ($previous_due > 0):
            $total_outstanding = $previous_due + max(0, (float)$order->balance_due);
          ?>
          <div class="tot-outstanding">
            <span class="to-l">Total Outstanding</span>
            <span class="to-v">৳<?php echo number_format($total_outstanding, 2); ?></span>
          </div>
          <?php endif; ?>
        </div>
      </div>

    </div><!-- /.bot -->

    <!-- SIGNATURES -->
    <div class="sig-wrap">
      <div class="sec-lbl">Acknowledgement</div>
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
    </div>

  </div><!-- /.content -->

  <!-- ═══ FOOTER ══════════════════════════════════════════════ -->
  <div class="inv-foot">
    <div class="ft-terms">Terms: <span><?php echo ($order->order_type==='credit')?'As per credit agreement':'Paid in advance'; ?></span></div>
    <div class="ft-mid">Thank you for your business!</div>
    <div class="ft-r"><?php echo htmlspecialchars($company['website']); ?><br>Printed: <?php echo date('d M Y, g:i A'); ?></div>
  </div>

</div><!-- /.page -->
</body>
</html>