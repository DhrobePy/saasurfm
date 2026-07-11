<?php
/**
 * Dispatch / Delivery Slip (Feature #17) — the document that travels with the
 * goods. Carries the products, assigned driver/vehicle, and a signed QR that the
 * driver/dispatch staff scans at the delivery point to confirm delivery (which
 * locks the order against a second delivery).
 */
require_once '../core/init.php';

$allowed_roles = ['Superadmin', 'admin', 'Accounts', 'accounts-srg', 'accounts-demra',
                  'dispatch-srg', 'dispatch-demra', 'dispatchpos-srg', 'dispatchpos-demra',
                  'production manager-srg', 'production manager-demra', 'sales-srg', 'sales-demra', 'sales-other'];
// Utility print page reached from the order view / dispatch board — accept either grant.
if (!userHasPageGrant('credit_sales', 'credit_order_view') && !userHasPageGrant('production', 'credit_dispatch')) {
    restrict_access($allowed_roles, 'credit_sales', 'dispatch_slip');
}

global $db;
ensureDeliveryConfirmTable();
$order_id = (int)($_GET['id'] ?? 0);
if (!$order_id) { die('Invalid order.'); }

$order = $db->query(
    "SELECT co.*, c.name AS customer_name, c.phone_number AS customer_phone,
            b.name AS branch_name, b.address AS branch_address
     FROM credit_orders co
     JOIN customers c ON c.id = co.customer_id
     LEFT JOIN branches b ON b.id = co.assigned_branch_id
     WHERE co.id = ?",
    [$order_id]
)->first();
if (!$order) { die('Order not found.'); }

$items = $db->query(
    "SELECT coi.quantity, p.base_name AS product_name, pv.weight_variant, pv.grade
     FROM credit_order_items coi
     JOIN products p ON p.id = coi.product_id
     LEFT JOIN product_variants pv ON pv.id = coi.variant_id
     WHERE coi.order_id = ?",
    [$order_id]
)->results();

$ship = $db->query("SELECT truck_number, driver_name FROM credit_order_shipping WHERE order_id = ?", [$order_id])->first();
$driver  = $ship->driver_name  ?? '';
$vehicle = $ship->truck_number ?? '';

$confirmed = $db->query("SELECT confirmed_at, confirmed_by_name FROM cr_delivery_confirmations WHERE order_id = ?", [$order_id])->first();

// Signed QR → the login-gated confirm page
$qr_sig = deliveryQrSignature((string)$order->order_number);
$qr_url = (defined('APP_URL') ? rtrim(APP_URL, '/') : '')
        . '/cr/verify_delivery.php?inv=' . urlencode((string)$order->order_number) . '&sig=' . $qr_sig;

$deliver_to = trim($order->shipping_address ?? '');

// Logo
$logo_base64 = null;
$logo_dir = dirname(__DIR__) . '/uploads/company/';
foreach (['png','jpg','jpeg'] as $_ext) {
    $_lf = $logo_dir . 'logo.' . $_ext;
    if (is_file($_lf)) { $logo_base64 = 'data:image/' . ($_ext === 'jpg' ? 'jpeg' : $_ext) . ';base64,' . base64_encode(file_get_contents($_lf)); break; }
}
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dispatch Slip — <?php echo htmlspecialchars($order->order_number); ?></title>
<style>
  * { box-sizing: border-box; }
  body { font-family: -apple-system, 'Segoe UI', Roboto, sans-serif; color: #1f2937; margin: 0; padding: 24px; background: #f3f4f6; }
  .slip { max-width: 780px; margin: 0 auto; background: #fff; border: 1px solid #e5e7eb; border-radius: 10px; padding: 28px; }
  .top { display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; border-bottom: 2px solid #111827; padding-bottom: 14px; }
  .co-name { font-size: 18px; font-weight: 800; }
  .co-en { font-size: 12px; color: #6b7280; }
  .co-meta { font-size: 11px; color: #6b7280; margin-top: 4px; }
  .logo img { max-height: 64px; max-width: 64px; object-fit: contain; }
  .doc-title { text-align: right; }
  .doc-title .t { font-size: 20px; font-weight: 800; letter-spacing: 1px; }
  .doc-title .n { font-family: monospace; font-weight: 700; font-size: 14px; margin-top: 2px; }
  .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-top: 16px; }
  .box { border: 1px solid #e5e7eb; border-radius: 8px; padding: 12px; }
  .box h4 { margin: 0 0 6px; font-size: 10px; text-transform: uppercase; letter-spacing: .5px; color: #9ca3af; }
  .box .v { font-size: 13px; }
  .driver { display: flex; gap: 20px; }
  .driver .lbl { font-size: 10px; color: #9ca3af; text-transform: uppercase; }
  .driver .val { font-size: 15px; font-weight: 700; }
  table.items { width: 100%; border-collapse: collapse; margin-top: 16px; }
  table.items th { background: #111827; color: #fff; font-size: 11px; text-transform: uppercase; padding: 8px 10px; text-align: left; }
  table.items td { border-bottom: 1px solid #f3f4f6; padding: 8px 10px; font-size: 13px; }
  table.items td.qty { text-align: right; font-weight: 700; }
  .qr-wrap { display: flex; align-items: center; gap: 16px; margin-top: 20px; border-top: 1px dashed #d1d5db; padding-top: 16px; }
  .qr-cap { font-size: 12px; color: #374151; }
  .qr-cap strong { display: block; font-size: 13px; }
  .warn { margin-top: 14px; padding: 10px 12px; border-radius: 8px; background: #fffbeb; border: 1px solid #fde68a; color: #92400e; font-size: 12px; }
  .sign-row { display: flex; justify-content: space-between; margin-top: 40px; gap: 24px; }
  .sign-row .s { flex: 1; text-align: center; border-top: 1px solid #9ca3af; padding-top: 6px; font-size: 11px; color: #6b7280; }
  .toolbar { max-width: 780px; margin: 0 auto 12px; text-align: right; }
  .btn { background: #2563eb; color: #fff; border: none; padding: 8px 16px; border-radius: 8px; cursor: pointer; font-size: 14px; }
  @media print { body { background: #fff; padding: 0; } .slip { border: none; border-radius: 0; } .toolbar { display: none; } }
</style>
</head>
<body>
<div class="toolbar"><button class="btn" onclick="window.print()">🖨 Print / Save PDF</button></div>
<div class="slip">
  <div class="top">
    <div style="display:flex;gap:12px;align-items:flex-start;">
      <?php if ($logo_base64): ?><div class="logo"><img src="<?php echo $logo_base64; ?>" alt="Logo"></div><?php endif; ?>
      <div>
        <div class="co-name">Ujjal Flour Mills</div>
        <div class="co-en">উজ্জল ফ্লাওয়ার মিলস</div>
        <div class="co-meta"><?php echo htmlspecialchars($order->branch_address ?: '১৭, নুরাইবাগ, ডেমরা, ঢাকা'); ?></div>
      </div>
    </div>
    <div class="doc-title">
      <div class="t">DISPATCH SLIP</div>
      <div class="n"><?php echo htmlspecialchars($order->order_number); ?></div>
      <div style="font-size:11px;color:#6b7280;margin-top:2px;">Date: <?php echo date('d M Y'); ?></div>
    </div>
  </div>

  <div class="grid">
    <div class="box">
      <h4>Deliver To</h4>
      <div class="v"><strong><?php echo htmlspecialchars($order->customer_name); ?></strong></div>
      <?php if ($order->customer_phone): ?><div class="v">Phone: <?php echo htmlspecialchars($order->customer_phone); ?></div><?php endif; ?>
      <?php if ($deliver_to): ?><div class="v" style="margin-top:4px;color:#4b5563;"><?php echo nl2br(htmlspecialchars($deliver_to)); ?></div><?php endif; ?>
    </div>
    <div class="box">
      <h4>Dispatch From / Driver</h4>
      <div class="v"><?php echo htmlspecialchars($order->branch_name ?: '—'); ?></div>
      <div class="driver" style="margin-top:8px;">
        <div><div class="lbl">Driver</div><div class="val"><?php echo htmlspecialchars($driver ?: '—'); ?></div></div>
        <div><div class="lbl">Vehicle</div><div class="val"><?php echo htmlspecialchars($vehicle ?: '—'); ?></div></div>
      </div>
    </div>
  </div>

  <table class="items">
    <thead><tr><th>Product</th><th style="text-align:right;">Quantity</th></tr></thead>
    <tbody>
      <?php foreach ($items as $it): ?>
      <tr>
        <td><?php echo htmlspecialchars(trim($it->product_name . ' ' . ($it->weight_variant ?? '') . ($it->grade ? ' · ' . $it->grade : ''))); ?></td>
        <td class="qty"><?php echo rtrim(rtrim(number_format((float)$it->quantity, 3), '0'), '.'); ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <div class="qr-wrap">
    <canvas id="dsQr" width="110" height="110" style="width:110px;height:110px;"></canvas>
    <div class="qr-cap">
      <strong>Scan at delivery to confirm</strong>
      Driver/dispatch staff scan this code (logged in) to confirm delivery.
      It shows the items &amp; driver, and <strong>locks the order</strong> so it can't be delivered twice.
    </div>
  </div>

  <?php if ($confirmed): ?>
  <div class="warn">⚠ Already delivered — confirmed by <?php echo htmlspecialchars($confirmed->confirmed_by_name ?? 'staff'); ?>
     on <?php echo date('d M Y, g:i A', strtotime($confirmed->confirmed_at)); ?>. Do not deliver again.</div>
  <?php endif; ?>

  <div class="sign-row">
    <div class="s">Driver's Signature</div>
    <div class="s">Received By (Customer)</div>
  </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/qrious/4.0.2/qrious.min.js"></script>
<script>
(function () {
    function draw() {
        var el = document.getElementById('dsQr');
        if (!el || typeof QRious === 'undefined') return;
        try { new QRious({ element: el, value: <?php echo json_encode($qr_url); ?>, size: 110, level: 'M' }); } catch (e) {}
    }
    if (document.readyState !== 'loading') draw(); else document.addEventListener('DOMContentLoaded', draw);
})();
</script>
</body>
</html>
