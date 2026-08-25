<?php
/**
 * Commodity Gate Pass — the document that travels with the goods, carrying a
 * signed QR that gate/delivery staff scan to release and confirm the
 * commodity sale. Adapted from cr/dispatch_slip.php for commodity_sales.
 */
require_once '../core/init.php';

restrict_access(['Superadmin', 'admin'], 'trading', 'commodity_dispatch');

global $db;
ensureCommodityDispatchConfirmTable();
$sale_id = (int)($_GET['id'] ?? 0);
if (!$sale_id) { die('Invalid sale.'); }

$sale = $db->query(
    "SELECT cs.*, c.name AS customer_name, c.phone_number AS customer_phone, c.business_address,
            pc.name AS commodity_name, pc.unit, b.name AS branch_name, b.address AS branch_address
     FROM commodity_sales cs
     JOIN customers c ON c.id = cs.customer_id
     JOIN purchase_commodities pc ON pc.id = cs.commodity_id
     JOIN branches b ON b.id = cs.branch_id
     WHERE cs.id = ?",
    [$sale_id]
)->first();
if (!$sale) { die('Sale not found.'); }

$conf = $db->query("SELECT * FROM commodity_dispatch_confirmations WHERE sale_id = ?", [$sale_id])->first();

$sig = commodityDeliveryQrSignature((string)$sale->sale_number);
$qr_url = (defined('APP_URL') ? rtrim(APP_URL, '/') : '')
        . '/trading/commodity_verify_delivery.php?inv=' . urlencode((string)$sale->sale_number) . '&sig=' . $sig;

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
<title>Gate Pass — <?php echo htmlspecialchars($sale->sale_number); ?></title>
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
  .btn { background: #e11d48; color: #fff; border: none; padding: 8px 16px; border-radius: 8px; cursor: pointer; font-size: 14px; }
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
        <div class="co-meta"><?php echo htmlspecialchars($sale->branch_address ?: '১৭, নুরাইবাগ, ডেমরা, ঢাকা'); ?></div>
      </div>
    </div>
    <div class="doc-title">
      <div class="t">GATE PASS <span style="font-weight:600;font-size:12px;color:#6b7280;">/ COMMODITY DISPATCH</span></div>
      <div class="n"><?php echo htmlspecialchars($sale->sale_number); ?></div>
      <div style="font-size:11px;color:#6b7280;margin-top:2px;">Security / Checkpost Copy — Date: <?php echo date('d M Y'); ?></div>
    </div>
  </div>

  <div class="grid">
    <div class="box">
      <h4>Deliver To</h4>
      <div class="v"><strong><?php echo htmlspecialchars($sale->customer_name); ?></strong></div>
      <?php if ($sale->customer_phone): ?><div class="v">Phone: <?php echo htmlspecialchars($sale->customer_phone); ?></div><?php endif; ?>
      <?php if (!empty($sale->business_address)): ?><div class="v" style="margin-top:4px;color:#4b5563;"><?php echo nl2br(htmlspecialchars($sale->business_address)); ?></div><?php endif; ?>
    </div>
    <div class="box">
      <h4>Dispatch From / Driver</h4>
      <div class="v"><?php echo htmlspecialchars($sale->branch_name ?: '—'); ?><?php echo $sale->origin ? ' · ' . htmlspecialchars($sale->origin) . ' origin' : ''; ?></div>
      <div class="driver" style="margin-top:8px;">
        <div><div class="lbl">Driver</div><div class="val"><?php echo htmlspecialchars($conf->driver_name ?? '—'); ?></div></div>
        <div><div class="lbl">Vehicle</div><div class="val"><?php echo htmlspecialchars($conf->vehicle_number ?? '—'); ?></div></div>
      </div>
    </div>
  </div>

  <table class="items">
    <thead><tr><th>Commodity</th><th style="text-align:right;">Quantity</th></tr></thead>
    <tbody>
      <tr>
        <td><?php echo htmlspecialchars($sale->commodity_name); ?></td>
        <td class="qty"><?php echo rtrim(rtrim(number_format((float)$sale->quantity, 3), '0'), '.'); ?> <?php echo htmlspecialchars($sale->unit); ?></td>
      </tr>
    </tbody>
  </table>

  <div class="qr-wrap">
    <canvas id="cgpQr" width="110" height="110" style="width:110px;height:110px;"></canvas>
    <div class="qr-cap">
      <strong>Scan twice: at the gate, then at delivery</strong>
      1) At the factory gate, staff scan (logged in) to <strong>release the goods</strong>.
      2) At the customer, scan again to <strong>confirm delivery</strong>, which locks the sale so it can't be delivered twice.
    </div>
  </div>

  <?php if ($conf && !empty($conf->confirmed_at)): ?>
  <div class="warn">⚠ Already delivered — confirmed by <?php echo htmlspecialchars($conf->confirmed_by_name ?? 'staff'); ?>
     on <?php echo date('d M Y, g:i A', strtotime($conf->confirmed_at)); ?>. Do not deliver again.</div>
  <?php elseif ($conf && !empty($conf->gate_out_at)): ?>
  <div class="warn" style="background:#eff6ff;border-color:#bfdbfe;color:#1e40af;">↗ Gate pass done — goods released by <?php echo htmlspecialchars($conf->gate_out_by_name ?? 'staff'); ?>
     on <?php echo date('d M Y, g:i A', strtotime($conf->gate_out_at)); ?>. Awaiting delivery confirmation.</div>
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
        var el = document.getElementById('cgpQr');
        if (!el || typeof QRious === 'undefined') return;
        try { new QRious({ element: el, value: <?php echo json_encode($qr_url); ?>, size: 110, level: 'M' }); } catch (e) {}
    }
    if (document.readyState !== 'loading') draw(); else document.addEventListener('DOMContentLoaded', draw);
})();
</script>
</body>
</html>
