<?php
/**
 * Commodity Sale Invoice — printable invoice for a commodity_sales row.
 * Single-line document (one commodity per sale), styled to match
 * commodity_gate_pass.php so the two print together as one packet.
 */
require_once '../core/init.php';

restrict_access(['Superadmin', 'admin'], 'trading', 'commodity_dispatch');

global $db;
$sale_id = (int)($_GET['id'] ?? 0);
if (!$sale_id) { die('Invalid sale.'); }

$sale = $db->query(
    "SELECT cs.*, c.name AS customer_name, c.phone_number AS customer_phone, c.business_address, c.business_name,
            pc.name AS commodity_name, pc.unit, b.name AS branch_name, b.address AS branch_address
     FROM commodity_sales cs
     JOIN customers c ON c.id = cs.customer_id
     JOIN purchase_commodities pc ON pc.id = cs.commodity_id
     JOIN branches b ON b.id = cs.branch_id
     WHERE cs.id = ?",
    [$sale_id]
)->first();
if (!$sale) { die('Sale not found.'); }

$agg = $db->query("SELECT COALESCE(SUM(debit_amount),0) td, COALESCE(SUM(credit_amount),0) tc FROM customer_ledger WHERE customer_id = ? AND id < (SELECT MIN(id) FROM customer_ledger WHERE reference_type='commodity_sales' AND reference_id = ?)", [$sale->customer_id, $sale_id])->first();
$cust_init = $db->query("SELECT initial_due FROM customers WHERE id = ?", [$sale->customer_id])->first();
$previous_due = ((float)($agg->td ?? 0) > 0 || (float)($agg->tc ?? 0) > 0) ? ((float)$agg->td - (float)$agg->tc) : (float)($cust_init->initial_due ?? 0);

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
<title>Invoice — <?php echo htmlspecialchars($sale->sale_number); ?></title>
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
  table.items { width: 100%; border-collapse: collapse; margin-top: 16px; }
  table.items th { background: #111827; color: #fff; font-size: 11px; text-transform: uppercase; padding: 8px 10px; text-align: left; }
  table.items td { border-bottom: 1px solid #f3f4f6; padding: 8px 10px; font-size: 13px; }
  table.items td.num { text-align: right; }
  .totals { margin-top: 16px; margin-left: auto; width: 320px; }
  .totals .row { display: flex; justify-content: space-between; padding: 6px 0; font-size: 13px; border-bottom: 1px solid #f3f4f6; }
  .totals .row.grand { font-weight: 800; font-size: 16px; border-top: 2px solid #111827; border-bottom: none; margin-top: 4px; padding-top: 10px; }
  .totals .row.due { color: #b91c1c; font-weight: 700; }
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
      <div class="t">INVOICE <span style="font-weight:600;font-size:12px;color:#6b7280;">/ COMMODITY SALE</span></div>
      <div class="n"><?php echo htmlspecialchars($sale->sale_number); ?></div>
      <div style="font-size:11px;color:#6b7280;margin-top:2px;">Date: <?php echo date('d M Y', strtotime($sale->sale_date)); ?></div>
    </div>
  </div>

  <div class="grid">
    <div class="box">
      <h4>Bill To</h4>
      <div class="v"><strong><?php echo htmlspecialchars($sale->customer_name); ?></strong></div>
      <?php if ($sale->business_name): ?><div class="v"><?php echo htmlspecialchars($sale->business_name); ?></div><?php endif; ?>
      <?php if ($sale->customer_phone): ?><div class="v">Phone: <?php echo htmlspecialchars($sale->customer_phone); ?></div><?php endif; ?>
    </div>
    <div class="box">
      <h4>Sold From</h4>
      <div class="v"><?php echo htmlspecialchars($sale->branch_name ?: '—'); ?></div>
      <?php if ($sale->origin): ?><div class="v">Origin: <?php echo htmlspecialchars($sale->origin); ?></div><?php endif; ?>
    </div>
  </div>

  <table class="items">
    <thead><tr><th>Commodity</th><th style="text-align:right;">Quantity</th><th style="text-align:right;">Unit Price</th><th style="text-align:right;">Amount</th></tr></thead>
    <tbody>
      <tr>
        <td><?php echo htmlspecialchars($sale->commodity_name); ?></td>
        <td class="num"><?php echo rtrim(rtrim(number_format((float)$sale->quantity, 3), '0'), '.'); ?> <?php echo htmlspecialchars($sale->unit); ?></td>
        <td class="num">৳<?php echo number_format((float)$sale->unit_price, 2); ?></td>
        <td class="num">৳<?php echo number_format((float)$sale->total_amount, 2); ?></td>
      </tr>
    </tbody>
  </table>

  <div class="totals">
    <div class="row grand"><span>Invoice Total</span><span>৳<?php echo number_format((float)$sale->total_amount, 2); ?></span></div>
    <?php if ((float)$sale->advance_paid > 0): ?><div class="row"><span>Advance Paid</span><span>৳<?php echo number_format((float)$sale->advance_paid, 2); ?></span></div><?php endif; ?>
    <?php if ((float)$sale->amount_paid > 0): ?><div class="row"><span>Paid</span><span>৳<?php echo number_format((float)$sale->amount_paid, 2); ?></span></div><?php endif; ?>
    <div class="row due"><span>Balance Due (this invoice)</span><span>৳<?php echo number_format((float)$sale->balance_due, 2); ?></span></div>
    <div class="row"><span>Previous Account Due</span><span>৳<?php echo number_format($previous_due, 2); ?></span></div>
    <div class="row grand"><span>Total Account Due</span><span>৳<?php echo number_format($previous_due + (float)$sale->balance_due, 2); ?></span></div>
  </div>

  <div class="sign-row">
    <div class="s">Prepared By</div>
    <div class="s">Received By (Customer)</div>
  </div>
</div>
</body>
</html>
