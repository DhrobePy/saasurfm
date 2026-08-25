<?php
/**
 * User Manual — role-aware. Every module, action and Q&A.
 * Staff see their own tasks; admin/superadmin-only content is hidden from them.
 * Root-level page: any logged-in user may read it.
 */
require_once __DIR__ . '/core/init.php';
restrict_access(); // logged-in users only

$currentUser   = getCurrentUser();
$role          = $currentUser['role'] ?? ($_SESSION['user_role'] ?? '');
$is_superadmin = ($role === 'Superadmin');
$is_admin      = in_array($role, ['Superadmin', 'admin'], true);
$edition       = $is_superadmin ? 'Superadmin' : ($is_admin ? 'Admin' : 'Staff');

// Can this block be shown to the current user?
$can = function (string $who) use ($is_admin, $is_superadmin): bool {
    if ($who === 'admin')      return $is_admin;
    if ($who === 'superadmin') return $is_superadmin;
    return true; // 'all'
};

/* ── Flowchart builder (CSS boxes, no library — always renders & prints) ── */
$flow = function (array $steps): string {
    $out = '<div class="flow">';
    foreach ($steps as $s) {
        if ($s === '>') { $out .= '<span class="farr">→</span>'; continue; }
        [$cls, $label, $sub] = array_pad((array)$s, 3, '');
        $out .= '<div class="fstep ' . $cls . '">' . $label . ($sub ? '<small>' . $sub . '</small>' : '') . '</div>';
    }
    return $out . '</div>';
};

/* ═══════════════════════════════════════════════════════════════════════════
   MANUAL CONTENT  (audience: all | admin | superadmin)
   Each Q&A may carry its own 'who' to hide admin/superadmin answers from staff.
   ═══════════════════════════════════════════════════════════════════════════ */
$sections = [

/* ── GETTING STARTED ─────────────────────────────────────────────────────── */
[
  'id' => 'start', 'icon' => 'fa-circle-play', 'title' => 'Getting started', 'audience' => 'all',
  'intro' => 'This manual is tailored to your access. You are signed in as <strong>' . htmlspecialchars($currentUser['display_name'] ?? 'user')
           . '</strong> (' . htmlspecialchars($role ?: 'user') . '). Use the index on the left to jump to a task.',
  'steps' => [
    'Find your task in the index and open that section.',
    'Each section has <strong>what it does</strong>, <strong>step-by-step actions</strong>, and a <strong>Q&amp;A</strong> you can expand.',
    'Blue boxes are steps; green = start/finish; amber = a decision or hold; red = a stop/blocked state.',
  ],
  'qa' => [
    ['q' => 'A page says "Access denied" — why?', 'a' => 'Your role doesn\'t have that page enabled. Access is set per user by an administrator. Ask your admin to grant it if you need it.'],
    ['q' => 'Something looks wrong or I made a mistake — what do I do?', 'a' => 'Don\'t try to force it. Note the invoice/customer number and tell your admin. Most sensitive actions are reversible by a Superadmin.'],
    ['q' => 'What are the Telegram messages about?', 'a' => 'The system sends alerts to the company Telegram for key events (new orders, payments awaiting approval, deliveries, over-limit orders). They\'re informational — act on them from the relevant page.'],
  ],
],

/* ── ORDER LIFECYCLE ─────────────────────────────────────────────────────── */
[
  'id' => 'lifecycle', 'icon' => 'fa-route', 'title' => 'How a credit order flows', 'audience' => 'all',
  'intro' => 'Every credit order travels this road. Money posts to the customer ledger <strong>at dispatch</strong> — not at order time.',
  'flow' => $flow([
    ['start', 'Create order', 'Sales'], '>',
    ['warn', 'Credit check', 'over limit → escalates'], '>',
    ['', 'Approval', 'branch + date'], '>',
    ['', 'Production', 'make &amp; pack'], '>',
    ['warn', 'Dispatch HOLD', 'until Accounts clears'], '>',
    ['', 'Goods on Board', 'loaded · invoice posts'], '>',
    ['', 'Shipped', 'gate QR scan'], '>',
    ['end', 'Delivered', 'delivery QR scan'],
  ]),
  'qa' => [
    ['q' => 'Why is money only counted at dispatch?', 'a' => 'Because goods physically leave at dispatch. Before that, an order can still change or be cancelled, so it isn\'t on the customer\'s ledger yet. The invoice posts at the <strong>Goods on Board</strong> step (loaded on the truck).'],
    ['q' => 'What are the order statuses?', 'a' => '<em>pending approval → approved → in production → produced → ready to ship → <strong>goods on board</strong> → shipped → delivered</em>. Two extra: <em>escalated</em> (needs higher approval) and <em>cancelled/rejected</em>.'],
  ],
],

/* ── CREATING ORDERS ─────────────────────────────────────────────────────── */
[
  'id' => 'orders', 'icon' => 'fa-plus-circle', 'title' => 'Creating & amending orders', 'audience' => 'all',
  'intro' => 'Sales and Accounts create credit orders. The system checks the customer\'s credit limit as you go.',
  'steps' => [
    'Open <strong>Credit Sales → Create Order</strong>.',
    'Pick the customer. Their current balance, credit limit and available credit show on the right.',
    'Add products, quantities and delivery type; set the <strong>required delivery date</strong>.',
    'Submit. The order goes to <strong>Pending Approval</strong> (or escalates if it breaks the credit limit).',
  ],
  'qa' => [
    ['q' => 'My order became "Escalated" instead of pending — why?', 'a' => 'The order pushes the customer over their credit limit. It\'s blocked from the normal queue and needs <strong>Superadmin</strong> approval. The Superadmin is alerted automatically.'],
    ['q' => 'The customer isn\'t in the list.', 'a' => 'They may be inactive, or not added yet. Add them first in <strong>Customers → Add Customer</strong> (name, phone, type, credit limit).'],
    ['q' => 'Can I change an order after submitting?', 'a' => 'Before dispatch, use <strong>Order Amendment</strong> to edit it. After dispatch, changes are made through debit/credit notes, not by editing the order.'],
    ['q' => 'My order is stuck in "Pending Approval".', 'a' => 'It\'s waiting for an approver. Accounts/Admin approve orders within their limit; higher-value ones escalate. Follow up with them if it\'s urgent.'],
    ['q' => 'What is the "required date"?', 'a' => 'Your target delivery date. The approver confirms it and assigns the production branch.'],
  ],
],

/* ── APPROVALS ───────────────────────────────────────────────────────────── */
[
  'id' => 'approvals', 'icon' => 'fa-gavel', 'title' => 'Approving orders', 'audience' => 'all',
  'intro' => 'Approvers assign the production branch and delivery date. Each approver has a personal ৳ limit; bigger orders escalate.',
  'flow' => $flow([
    ['start', 'Order submitted'], '>',
    ['', 'Within your ৳ limit', 'approve'], '>',
    ['warn', 'Above your limit', 'escalates to admin'], '>',
    ['stop', 'Over credit limit', 'Superadmin only'],
  ]),
  'steps' => [
    'Open <strong>Credit Sales → Approve Orders</strong>.',
    'Review the customer\'s credit usage shown on each order.',
    'Choose the <strong>production branch</strong> and confirm the <strong>required date</strong>, then Approve (or Reject with a reason).',
  ],
  'qa' => [
    ['q' => 'What\'s my approval limit?', 'a' => 'A ৳ ceiling set for you by an administrator. Orders above it don\'t get rejected — they auto-escalate to someone with more authority.'],
    ['q' => 'Can I attach a payment condition when approving?', 'a' => 'Yes — you can add a <strong>dispatch hold with a condition</strong> (e.g. "outstanding must drop below ৳X") and/or a <strong>production hold</strong>. The order won\'t ship until the condition is cleared.'],
    ['q' => 'Approving an escalated (high-value) order', 'a' => 'Escalated orders appear in the same queue for admins. Approve the same way once you\'re satisfied.', 'who' => 'admin'],
    ['q' => 'Approving an order that is OVER the customer\'s credit limit', 'a' => 'Only <strong>Superadmin</strong> can. Open the escalated order — you\'ll see the approve form (hidden for everyone else). Pick the branch and approve. This is the deliberate control so over-limit credit is never extended without you.', 'who' => 'superadmin'],
  ],
],

/* ── PAYMENT WATCH ───────────────────────────────────────────────────────── */
[
  'id' => 'watch', 'icon' => 'fa-eye', 'title' => 'Payment Watch & the dispatch hold', 'audience' => 'all',
  'intro' => 'Every order is <strong>held from dispatch</strong> until Accounts/Admin clears it. Being "ready to ship" never releases goods by itself.',
  'flow' => $flow([
    ['warn', 'Order HELD'], '>',
    ['', 'Customer pays', 'condition met'], '>',
    ['', 'or Accounts clears', 'manual / override'], '>',
    ['end', 'CLEARED', 'truck can leave'],
  ]),
  'steps' => [
    'Open <strong>Credit Sales → Payment Watch</strong>. Each held order shows the customer\'s outstanding, what this invoice adds, and any shortfall.',
    'To take money now: click <strong>Collect Payment</strong> (opens the payment page preloaded).',
    'To release the truck: click <strong>Grant Dispatch Clearance</strong> (add a note, e.g. payment reference).',
  ],
  'qa' => [
    ['q' => 'Why can\'t the truck leave even though goods are ready?', 'a' => 'There\'s a dispatch hold. Someone must either collect enough payment (auto-releases when a condition is met) or grant clearance in Payment Watch.'],
    ['q' => 'Difference between "Collect Payment" and "Grant Clearance"?', 'a' => '<strong>Collect Payment</strong> records money received. <strong>Grant Clearance</strong> releases the hold so the order can ship (an override). They sit side by side on each order.'],
    ['q' => 'It says "Condition met — ready to clear".', 'a' => 'The customer has paid enough to satisfy the condition set at approval. Clear it (or it auto-releases if auto-release was enabled).'],
    ['q' => 'I cleared the wrong order.', 'a' => 'Use <strong>Revoke Clearance</strong> (with a reason) — available until the order actually ships.'],
    ['q' => 'The hold is "locked" and I can\'t clear it early.', 'a' => 'The order has a payment condition that isn\'t met yet. Early release needs an admin, or an officer whose delegated ৳ limit covers the order value.'],
    ['q' => 'Turning the whole hold policy on/off', 'a' => 'Admin → Settings → <strong>Dispatch Hold Policy</strong>. ON (default) holds every order; OFF reverts to per-order holds only.', 'who' => 'admin'],
    ['q' => 'Can I let a non-Accounts user clear dispatch holds?', 'a' => 'Yes — clearance is <strong>delegable to any user</strong>. In <strong>User Privileges</strong>, open the user, enable the <strong>Credit Sales</strong> module, tick the <strong>Payment Watch</strong> page, then tick the <strong>"Grant Dispatch Clearance"</strong> action (and "Revoke Clearance" if they should undo). They can then clear holds; un-tick anytime to withdraw it. Accounts staff keep it by default. (The "Collect Payment" button on that screen stays Accounts/Admin-only.)', 'who' => 'admin'],
  ],
],

/* ── DISPATCH & QR ───────────────────────────────────────────────────────── */
[
  'id' => 'delivery', 'icon' => 'fa-qrcode', 'title' => 'Goods on Board, gate pass & QR delivery', 'audience' => 'all',
  'intro' => 'Dispatch is now three physical steps: <strong>Goods on Board</strong> (loaded on the truck), <strong>Shipped</strong> (scanned out at the gate), and <strong>Delivered</strong> (scanned at the customer). The dispatch slip is the <strong>gate pass</strong> and carries the QR.',
  'flow' => $flow([
    ['start', 'Goods on Board', 'dispatcher · invoice posts'], '>',
    ['', 'Print gate pass', 'QR slip'], '>',
    ['warn', 'Scan at gate', 'enter driver+vehicle'], '>',
    ['', 'Marks SHIPPED', 'goods left premises'], '>',
    ['end', 'Scan at customer', 'marks DELIVERED · locked'], '>',
    ['stop', 'Re-scan = alert'],
  ]),
  'steps' => [
    'On the <strong>Dispatch board</strong>, when an order is ready and cleared, the dispatcher clicks <strong>Goods on Board</strong> (goods loaded on the truck). The invoice/ledger posts at this step.',
    'Print the <strong>Gate Pass</strong> (the dispatch slip, with QR) and the invoice/receipt if needed. <em>The gate pass does not show the invoice amount.</em>',
    '<strong>At the factory gate</strong>, staff scan the QR (logged in), confirm/enter the <strong>driver &amp; vehicle</strong>, and release — this marks the order <strong>Shipped</strong>. A held order shows "⛔ DO NOT RELEASE".',
    '<strong>At the customer</strong>, scan the QR again and tap <strong>Confirm Delivery</strong> — this marks it <strong>Delivered</strong> and locks it. (The dispatcher can also mark delivered manually from the board.)',
  ],
  'qa' => [
    ['q' => 'What does "Goods on Board" mean?', 'a' => 'The order is loaded on the truck at the factory. It replaces the old "Ship" button. From here you print the gate pass; the gate scan is what actually marks it Shipped.'],
    ['q' => 'When is the driver &amp; vehicle recorded?', 'a' => 'At the <strong>gate scan</strong> — the person releasing the goods confirms or types the actual driver and vehicle. It\'s required, so every gate pass records the real truck.'],
    ['q' => 'How does the QR stop double delivery?', 'a' => 'The delivery scan locks the order. Any later scan shows <strong>"ALREADY DELIVERED"</strong>, is logged as a re-scan, and <strong>alerts admins on Telegram</strong> — so a duplicate-delivery attempt is caught.'],
    ['q' => 'The scan says "Not a genuine dispatch slip".', 'a' => 'The QR is fake or altered — it\'s signed, so a forged slip fails. Do not release/deliver against it; report it.'],
    ['q' => 'Why does the gate pass hide the amount?', 'a' => 'It\'s a security/checkpost document — the invoice amount isn\'t needed at the gate. The full invoice (with amounts) is a separate print.'],
    ['q' => 'It asks me to log in when I scan.', 'a' => 'Correct — every scan records who did it. Sign in and you land straight on the confirm screen.'],
  ],
],

/* ── PAYMENTS ────────────────────────────────────────────────────────────── */
[
  'id' => 'payments', 'icon' => 'fa-money-bill-wave', 'title' => 'Collecting payments', 'audience' => 'all',
  'intro' => 'Three pages collect money: <strong>Collect Payment</strong> (office), <strong>Collect (Field)</strong>, and <strong>Advance Collection</strong>. All follow the same approval policy.',
  'flow' => $flow([
    ['start', 'Record receipt'], '>',
    ['warn', 'Policy ON / over limit', 'queued + alert'], '>',
    ['', 'Senior officer reviews', 'prefilled'], '>',
    ['end', 'Posted to ledger'],
  ]),
  'steps' => [
    'Open the relevant payment page and pick the customer (or open it via <strong>Collect</strong> from Outstanding Invoices / Payment Watch, preloaded).',
    'Enter the amount, method (cash/bank), reference, and allocate to invoices. Use the <strong>×</strong> on an invoice row to drop it from the allocation list.',
    'Submit. It either posts immediately or goes to the approval queue (see below).',
  ],
  'qa' => [
    ['q' => 'Why did my payment go "for approval" instead of posting?', 'a' => 'Either the <strong>Payment Approval Policy</strong> is on (every receipt is reviewed) or your amount is above your personal <strong>collect limit</strong>. It\'s parked in Approval Requests and a senior officer posts it.'],
    ['q' => 'Where do queued receipts go?', 'a' => '<strong>Credit Sales → Approval Requests</strong>. The approver clicks "Review & Post"; the form opens prefilled and posts under their authority. A Telegram alert fires when something is queued.'],
    ['q' => 'How do I remove an invoice I don\'t want to pay against?', 'a' => 'In the invoices list on the Collect Payment page, click the <strong>×</strong> at the end of that row — it drops out of the allocation and the total re-calculates.'],
    ['q' => 'How do I take an advance payment?', 'a' => 'Use <strong>Advance Collection</strong> — record the amount and (optionally) allocate it to specific pending orders. It follows the same approval rules.'],
    ['q' => 'I entered a payment wrong.', 'a' => 'Tell whoever holds the <strong>Edit Payment</strong> privilege (Admins by default) the receipt number — they can correct or reverse it, and it\'s always restorable from the Recycle Bin.'],
    ['q' => 'Editing or deleting a posted payment', 'a' => 'On <strong>Payment History</strong>, users with the <strong>Edit / Delete Payment</strong> privilege (Admins get it automatically) see a per-row <strong>Edit</strong> and <strong>Delete</strong>. <strong>Edit</strong> opens the payment form <strong>fully prefilled</strong> — change any field including the amount and which invoices it pays, then <strong>Save</strong>: the original is reversed into the Recycle Bin and the corrected receipt is reposted in one step (never a silent overwrite). <strong>Delete</strong> simply reverses the receipt into the Recycle Bin. Both are fully restorable and audit-logged. Grant these under <strong>Privileges → Credit Sales → Payment History</strong>.', 'who' => 'admin'],
  ],
],

/* ── RETURNS ─────────────────────────────────────────────────────────────── */
[
  'id' => 'returns', 'icon' => 'fa-undo', 'title' => 'Returns, Adjustments & Over-delivery', 'audience' => 'all',
  'intro' => 'One tabbed page with three sub-tabs: <strong>Goods Returns</strong>, <strong>Stock Adjustments</strong>, and <strong>Over-Delivery</strong>. <strong>All three require approval by a different person</strong> — you can never approve your own.',
  'flow' => $flow([
    ['start', 'User A records it', 'return / adjustment / over-delivery'], '>',
    ['warn', 'Pending', 'A can\'t approve own'], '>',
    ['', 'User B approves'], '>',
    ['end', 'Invoice + ledger adjusted'],
  ]),
  'steps' => [
    'Open <strong>Credit Sales → Returns &amp; Adjustments</strong> and pick the tab: <strong>Goods Returns</strong>, <strong>Stock Adjustments</strong> or <strong>Over-Delivery</strong>.',
    'Fill in the details (items/quantities, reason) and submit — it becomes <strong>Pending</strong>.',
    'A <strong>different authorised user</strong> approves it; the invoice, receivables and ledger update automatically.',
  ],
  'qa' => [
    ['q' => 'Why can\'t I approve the one I just created?', 'a' => 'Separation of duties applies to <strong>all three</strong> (returns, adjustments, over-deliveries) — the creator can never approve their own. Someone else with approval rights must review it.'],
    ['q' => 'What happens when a return is approved?', 'a' => 'The invoice total drops, the customer\'s receivable reduces, and a credit note posts to their ledger — all in one step.'],
    ['q' => 'What is Over-Delivery for?', 'a' => 'When the customer received <em>more</em> than ordered. Record it and choose to bill the extra, retrieve the goods, or write it off. Approval by a different user applies the financial effect (e.g. billing the extra).'],
    ['q' => 'What is the "Stock Adjustments" tab?', 'a' => 'Correct inventory with a reason and value; a different person approves it (posts inventory + a journal entry). If you don\'t track stock counts, you can ignore it.'],
  ],
],

/* ── CUSTOMERS ───────────────────────────────────────────────────────────── */
[
  'id' => 'customers', 'icon' => 'fa-user-friends', 'title' => 'Customers & ledger', 'audience' => 'all',
  'intro' => 'Clicking <strong>Customers</strong> opens the <strong>Directory</strong> (contact list: name, business, phone, address). A <strong>Balances</strong> sub-tab shows everyone grouped into Due / In Advance / Zero-Due with true ledger balances.',
  'steps' => [
    'Open <strong>Customers → Directory</strong>. Search by name, phone, business or address; filter by type.',
    'Switch to the <strong>Balances</strong> sub-tab for who owes / who\'s in advance / who\'s settled.',
    'Add via <strong>Add Customer</strong>; Edit (pencil) / View (eye) per row.',
    'For history, open <strong>Customer Ledger</strong> — the customer picker is searchable (name, business, phone).',
  ],
  'qa' => [
    ['q' => 'Where\'s the contact list vs the balances?', 'a' => 'The <strong>Directory</strong> tab is the contact list (name/business/phone/address). The <strong>Balances</strong> tab has the Due / In Advance / Zero-Due tables.'],
    ['q' => 'How do I see a customer\'s complete history?', 'a' => 'Open <strong>Customer Ledger</strong> — pick the customer (the dropdown searches by name, business or phone), or open it with nobody selected to see everyone\'s balances at a glance.'],
    ['q' => 'Why can\'t I delete a customer?', 'a' => 'A customer with any balance (due or advance) can\'t be deleted — settle it to zero first. Only fully-settled customers show an active delete button.'],
    ['q' => 'How do I fix a balance that\'s slightly off (reconciliation)?', 'a' => 'Open the customer\'s ledger and click <strong>Post Adjustment</strong> (Admin/Superadmin): choose Debit (increase due) or Credit (decrease due), amount and reason. It posts a memo entry and updates the balance — and it\'s reversible.', 'who' => 'admin'],
    ['q' => 'Setting a customer\'s opening balance', 'a' => 'The opening-balance field on a new customer is <strong>Superadmin-only</strong>. Others always start a customer at ৳0.', 'who' => 'superadmin'],
    ['q' => 'Deleting a customer (and their records)', 'a' => 'Superadmin/Admin can delete a settled customer — the customer <strong>and all their records</strong> (orders, ledger, payments) move to the Recycle Bin in one batch and can be restored. It\'s audit-logged.', 'who' => 'admin'],
  ],
],

/* ── OUTSTANDING INVOICES ────────────────────────────────────────────────── */
[
  'id' => 'invoices', 'icon' => 'fa-file-invoice-dollar', 'title' => 'Outstanding invoices', 'audience' => 'all',
  'intro' => 'One list of every invoice with a balance due — regular credit orders and opening balances together.',
  'steps' => [
    'Open <strong>Credit Sales → Outstanding Invoices</strong>.',
    'Sort by clicking a column header (Due, Date, Customer, Branch).',
    'Filter by branch, invoice type, <strong>due type</strong> (Due / Advance / No credit limit), minimum amount, or search.',
    'Click <strong>Collect</strong> on a row to jump to the payment page with that invoice preloaded.',
  ],
  'qa' => [
    ['q' => 'What\'s an "Opening" invoice?', 'a' => 'A carried-forward opening balance, shown as an invoice so you can collect against it. It\'s tagged purple; normal orders are tagged blue.'],
    ['q' => 'What does the "Due Type" filter do?', 'a' => 'Classifies each invoice\'s customer: <strong>Due</strong> (owes), <strong>Advance</strong> (has credit with us), or <strong>No credit limit</strong> (customers who shouldn\'t carry a due — useful to spot anomalies).'],
    ['q' => 'What do the amounts on a printed invoice mean?', 'a' => 'The printed invoice shows, in order: <strong>Invoice Amount</strong> → <strong>Paid (this invoice)</strong> → <strong>Invoice Due</strong> (amount − paid) → <strong>Previous Due</strong> → <strong>Total Due</strong> (invoice due + previous due). So the customer sees exactly what this invoice adds and their full outstanding.'],
  ],
],

/* ── PRODUCTS & PRICING ──────────────────────────────────────────────────── */
[
  'id' => 'products', 'icon' => 'fa-box-open', 'title' => 'Products & pricing', 'audience' => 'all',
  'intro' => 'The Overview shows each product with its variants, grades and current prices per factory at a glance.',
  'steps' => [
    'Open <strong>Products → Overview</strong> to see products, variants and prices.',
    'Use <strong>Price Matrix</strong> for the detailed per-branch grid.',
  ],
  'qa' => [
    ['q' => 'Where do I see current prices?', 'a' => 'Products → Overview (card per product) or Price Matrix (full grid). Everyone can view prices.'],
    ['q' => 'Changing a price', 'a' => 'Only Superadmin/Admin can change prices (Products → Pricing). Every change is archived in price history. Others see prices but the edit form is hidden.', 'who' => 'admin'],
    ['q' => 'Zone / branch pricing and surcharges', 'a' => 'Set per-branch (zone) surcharges and mini-truck surcharges in <strong>Products → Smart Pricing</strong>. <strong>Any active branch</strong> can have zone pricing now — it no longer has to be flagged as a factory.', 'who' => 'admin'],
  ],
],

/* ── EXPENSES ────────────────────────────────────────────────────────────── */
[
  'id' => 'expense', 'icon' => 'fa-receipt', 'title' => 'Expenses', 'audience' => 'all',
  'intro' => 'Staff raise expense vouchers; they wait as pending until approved. Approving posts the accounting.',
  'steps' => [
    'Open <strong>Expenses → Create Expense</strong>, choose category/branch, enter amount and details, submit.',
    'The voucher is <strong>pending</strong> until an approver reviews it.',
    'Track yours in Expense History (filter by branch, date, category).',
  ],
  'qa' => [
    ['q' => 'Why is my expense "pending"?', 'a' => 'It\'s waiting for approval. Only after approval does it post to the accounts (journal + cash/bank).'],
    ['q' => 'Can I delete a pending voucher?', 'a' => 'Yes — pending vouchers can be deleted (they move to the Recycle Bin). Approved ones can only be removed by a Superadmin.'],
    ['q' => 'Approving expenses (single & bulk)', 'a' => 'Open <strong>Approve Expense</strong>. Approve one at a time, or tick several (or Select all) and click <strong>Approve Selected</strong> to clear them in one action. Failures are reported individually.', 'who' => 'admin'],
    ['q' => 'Deleting an approved expense', 'a' => 'Superadmin only — it archives the original posting and restores the cash/bank, and it\'s fully restorable from the Recycle Bin.', 'who' => 'superadmin'],
  ],
],

/* ── PURCHASE ────────────────────────────────────────────────────────────── */
[
  'id' => 'purchase', 'icon' => 'fa-shopping-cart', 'title' => 'Purchase (wheat)', 'audience' => 'all',
  'intro' => 'The wheat-purchase flow: create a PO, record goods received (GRN), record payment, and track the supplier balance.',
  'flow' => $flow([
    ['start', 'Create PO'], '>',
    ['', 'GRN — goods received', 'weight variance'], '>',
    ['', 'Record payment'], '>',
    ['end', 'Supplier ledger settled'],
  ]),
  'steps' => [
    'Create a <strong>Purchase Order</strong> for the supplier and quantity.',
    'When wheat arrives, record a <strong>GRN</strong> (goods received) — the system tracks weight variance.',
    'Record supplier <strong>payments</strong>; the supplier balance summary keeps the running position.',
  ],
  'qa' => [
    ['q' => 'How do I add a new supplier?', 'a' => 'From <strong>All Suppliers → Add Supplier</strong> (you need the "Add Supplier" privilege).'],
    ['q' => 'How are PO / GRN deletions handled?', 'a' => 'Deleting a PO or GRN marks it <em>cancelled</em> (the record is kept). Deleting an unposted payment or an adjustment note moves it to the Recycle Bin; posted payments are reversed by journal.'],
  ],
],

/* ── COMMODITY TRADING ───────────────────────────────────────────────────── */
[
  'id' => 'trading', 'icon' => 'fa-right-left', 'title' => 'Commodity Trading (buying & reselling wheat)', 'audience' => 'all',
  'intro' => 'Besides milling, we also buy extra wheat and resell it directly to the market — sometimes to the same customers who buy flour on credit. This is a separate module from Credit Sales so the two businesses don\'t get mixed up, but it uses the same customer list and the same ledger.',
  'flow' => $flow([
    ['start', 'Record Sale', 'pick customer + commodity'], '>',
    ['', 'Stock & ledger update', 'automatic'], '>',
    ['', 'Collect Payment', 'when the customer pays'], '>',
    ['end', 'Settled'],
  ]),
  'steps' => [
    'Open <strong>Trading → Trading Dashboard</strong> for an overview — filterable by date range, customer, commodity and origin — plus profit, stock value, and anything needing attention.',
    'To sell wheat (or any other approved commodity): <strong>Trading → Commodity Sale</strong>. Pick the customer, the commodity, the branch/warehouse/dock it\'s shipping from, the <strong>origin</strong> (if this commodity has more than one, e.g. Canadian vs Australian wheat — each origin keeps its own stock and cost), quantity and price (prices are volatile, so you type the price by hand every time). You can optionally link the sale to the original Purchase Order for traceability.',
    'The system checks how much stock is on hand for that exact branch + origin. If you try to sell more than what\'s recorded as received, it warns you — you can still proceed by ticking <strong>"sell anyway"</strong> (e.g. if a truck is on the way but not yet logged in).',
    'Submitting either posts the sale immediately, or sends it for approval first — same rule as payments: big amounts or no personal limit set always go to a senior officer via <strong>Approval Requests</strong>.',
    'Once posted, go to <strong>Trading → Commodity Dispatch</strong> to print the <strong>Invoice</strong> and <strong>Gate Pass</strong>. The gate pass carries a QR — scan it once at the factory gate to release the goods (records driver & vehicle), and again at the customer to confirm delivery. This locks the sale so it can\'t be "delivered" twice.',
    'When the customer pays (in full or in parts), click <strong>Collect</strong> next to their sale in the Recent Sales list, or open <strong>Collect Commodity Payment</strong> directly.',
    'If a customer is <em>also</em> someone you buy wheat from, link them once in <strong>Business Partners</strong> — after that you can net what you owe each other in one click via <strong>Partner Settlement</strong>, instead of tracking it on paper.',
  ],
  'qa' => [
    ['q' => 'Why is this separate from Credit Sales / Create Order?', 'a' => 'Credit Sales is for flour we produce. Commodity Trading is for raw wheat (or other commodities) we buy and resell as-is, with no production step — different profit math (it tracks the exact buying cost of what was sold, so you see the real margin, not an estimate).'],
    ['q' => 'How is the "cost" of a sale calculated?', 'a' => 'The system keeps a running <strong>weighted-average cost</strong> per commodity, per branch, <strong>and per origin</strong> (Canadian wheat and Australian wheat, say, are tracked and costed separately even at the same branch) — updated every time a purchase (GRN) is received. When you sell, it uses that origin\'s average automatically.'],
    ['q' => 'How does the gate pass / delivery scan work?', 'a' => 'Print the Gate Pass from <strong>Commodity Dispatch</strong> — it has a QR code. First scan (at the gate): enter the driver and vehicle, which releases the goods. Second scan (at the customer): confirm delivery, which locks the sale. Both scans require you to be logged in, so every step is attributed to a real person, and a re-scan of an already-delivered sale alerts admins.'],
    ['q' => 'I made a mistake on a commodity sale — can it be undone?', 'a' => 'Yes. Click the sale number in Recent Sales to open its <strong>View</strong> page — full details, the accounting entry, payment history, and a <strong>Timeline</strong> of everything that\'s happened to it, all in one place. If <em>no payment</em> has been collected yet, you\'ll see <strong>Edit</strong> and <strong>Delete</strong> buttons there (and in the Recent Sales list).'],
    ['q' => 'How does Edit actually work?', 'a' => 'You open a pre-filled form, change whatever\'s wrong (customer, commodity, quantity, price…), and give a reason. Saving doesn\'t silently overwrite the old entry — it reverses it (stock and ledger put back, old entry archived to the Recycle Bin) and posts a brand-new, correct entry with its own sale number. If you\'re an admin, this happens immediately. If you\'re staff with delegated Edit access, your correction is sent for approval first — a senior officer sees exactly what you changed (old value → new value) and approves or rejects it before anything is applied. Either way, the correction — who asked, what changed, who approved it — shows up permanently on the sale\'s <strong>Timeline</strong> in its View page.'],
    ['q' => 'A payment was collected by mistake — can that be undone too?', 'a' => 'Yes. Open <strong>Collect Commodity Payment</strong> for that sale — the Payment History list at the bottom has a <strong>Reverse</strong> button per payment. This puts the balance due back and moves the payment to the Recycle Bin, same as a sale reversal.'],
    ['q' => 'Why can\'t I delete a sale that has payments on it?', 'a' => 'To keep the numbers honest, reverse the payment(s) first (see above), then delete the sale. This avoids a partly-paid sale silently vanishing with money already collected against it.'],
    ['q' => 'What does "sell anyway" (stock override) do?', 'a' => 'It lets you record a sale even though it exceeds what\'s on hand — useful when stock is in transit. It\'s flagged with a warning icon everywhere it appears, and shows up red on the Commodity Inventory page so it\'s never hidden.'],
    ['q' => 'Where do I see overall profit, not just one sale?', 'a' => 'The <strong>Trading Dashboard</strong> shows this month\'s totals; the <strong>Margin Report</strong> lets you pick any date range and see it broken down per commodity, with a CSV export.'],
  ],
],

/* ── LOANS ────────────────────────────────────────────────────────────────── */
[
  'id' => 'loans', 'icon' => 'fa-hand-holding-dollar', 'title' => 'Loans (cash advances to customers/suppliers)', 'audience' => 'all',
  'intro' => 'Sometimes a customer, supplier, or related party (e.g. a sister concern funding a tender) needs to borrow cash from the company and pay it back later — no goods involved. This is completely separate from what they owe for buying/selling goods: a party\'s <strong>trading balance</strong> and their <strong>loan balance</strong> are always shown as two different numbers, never mixed together.',
  'flow' => $flow([
    ['start', 'Disburse Loan', 'pick borrower + amount'], '>',
    ['', 'Loan outstanding', 'tracked separately'], '>',
    ['', 'Collect Repayment', 'when they pay back'], '>',
    ['end', 'Loan Closed'],
  ]),
  'steps' => [
    'Open <strong>Loans → Loans Dashboard</strong> for an overview: total outstanding, overdue loans, and a filterable history by date/party.',
    'To lend money: <strong>Loans → New Loan</strong>. Search for the borrower (a customer or a supplier — the search box covers both, tagged so you can tell them apart), enter the amount, how it\'s being paid out (cash/bank), and optionally the purpose (e.g. "Tender participation — XYZ project") and an expected return date.',
    'Submitting either posts the loan immediately, or sends it for approval first — the same rule as every other cash-out action: a big amount, or no personal limit configured for you, always goes to a senior officer via <strong>Approval Requests</strong>.',
    'When the borrower pays back (in full or in parts), open the loan\'s page and click <strong>Collect Repayment</strong>. The loan closes itself automatically once fully repaid.',
    'If a loan passes its expected return date without being fully repaid, it shows up as <strong>Overdue</strong> on the Loans Dashboard and the main admin dashboard.',
  ],
  'qa' => [
    ['q' => 'Why doesn\'t this show up on the customer\'s regular statement?', 'a' => 'A loan is cash we lent out — it\'s not an invoice for goods, so mixing it into the same ledger as sales/payments would make "how much do they owe for flour" and "how much cash did we lend them" impossible to tell apart. The Loans module tracks it completely separately; you can see both numbers side by side on request.'],
    ['q' => 'Can I lend money to a supplier, not just a customer?', 'a' => 'Yes — the borrower search covers both customers and suppliers (and if that party is also a linked Business Partner, you\'ll see a badge noting it).'],
    ['q' => 'Does this charge interest?', 'a' => 'No — loans in this system are interest-free cash advances, matching how related-party/sister-concern funding usually works.'],
    ['q' => 'I made a mistake on a loan — can it be undone?', 'a' => 'Yes, the same Recycle-Bin pattern as everywhere else. If <em>no repayment</em> has been collected yet, open the loan\'s View page and click <strong>Delete</strong> — this reverses the accounting entry and archives it. If a repayment was collected by mistake, use the <strong>Reverse</strong> button next to that repayment in the loan\'s Repayment History instead — reverse repayments first, then you can delete the loan if needed.'],
  ],
],

/* ── POINT OF SALE ────────────────────────────────────────────────────────── */
[
  'id' => 'pos', 'icon' => 'fa-cash-register', 'title' => 'Point of Sale (counter sales)', 'audience' => 'all',
  'intro' => 'The walk-in counter, rebuilt Jul 2026: real inventory locking, a split cash+credit payment, a QR exit check so nothing walks out unrecorded, and its own credit ledger for POS-type customers — separate from the Credit Sales ledger, since a POS customer\'s counter tab isn\'t the same kind of thing as a Credit Sales invoice.',
  'flow' => $flow([
    ['start', 'Ring up sale', 'cash / split / credit'], '>',
    ['', 'QR exit check', 'security scans on the way out'], '>',
    ['', 'End of Day', 'reconcile the drawer'], '>',
    ['end', 'Bank Deposit', 'next day'],
  ]),
  'steps' => [
    'Open <strong>POS → POS Terminal</strong>. Add products to the cart by tapping them — price comes live from the same catalog the rest of the system uses. POS does not check or track stock quantities, so any active priced product can always be added.',
    'Pick a customer if this sale is (fully or partly) on credit — a <strong>Walk-in Customer</strong> can only pay cash/card/bank in full, since there\'s nowhere to post a credit balance without a customer record.',
    'To split the payment, tick <strong>"Charge part of this sale to customer credit"</strong> and enter how much goes on credit — the rest is paid now by whatever method you pick (Cash / Bank Deposit / Bank Transfer / Card / bKash / Nagad).',
    'If charging to credit would push the customer over their POS credit limit, the sale doesn\'t complete immediately — it\'s sent to an admin for approval instead, and you\'ll see a clear "do not release the goods yet" message. An admin approves it from the same request queue used everywhere else (<strong>Approval Requests</strong>).',
    'On success, the receipt shows a <strong>QR code</strong> — have security/gate staff scan it before the customer leaves with the goods. This is a single check (unlike Credit Sales\' two-stage gate-then-delivery flow, since a POS sale is a straight walk-out, not a truck journey) and a re-scan of an already-cleared receipt alerts admins.',
    'To collect on a customer\'s running POS credit balance later, use <strong>POS → Collect Payment</strong> — Cash, Bank, bKash, or Nagad. Their full history and current balance are on <strong>POS → Customer Ledger</strong>.',
    'At the end of the day, <strong>POS → End of Day</strong> reconciles the drawer: expected cash is calculated automatically from the day\'s actual cash sales (not a rough estimate), you can enter what you actually counted plus a note if they don\'t match, and choose whether today\'s cash stays in the branch\'s petty cash or is being taken to the bank. If deposited, confirm it the next day at <strong>POS → Confirm Bank Deposit</strong> — this is what actually posts the Petty Cash → Bank accounting entry.',
  ],
  'qa' => [
    ['q' => 'Why is the POS customer ledger separate from the regular Customer Ledger?', 'a' => 'The same reasoning as Loans: a POS counter tab and a Credit Sales invoice are different kinds of balances, and forcing them into one ledger has caused real bugs in this system before. A POS customer\'s outstanding balance is tracked on its own page — you can always see both numbers if a customer happens to buy through both channels.'],
    ['q' => 'Does POS track stock quantities?', 'a' => 'No — stock tracking was deliberately removed from POS. You can sell any active priced product regardless of what any stock count says; nothing is checked or decremented.'],
    ['q' => 'What does the small badge on a product card mean (e.g. "Demra")?', 'a' => 'That\'s the Origin / Factory — which mill actually produces that variant, separate from which branch is selling it. Set per product variant in <strong>Products → Base Products → (a product) → Manage Variants</strong>. It\'s optional and purely informational; leave it blank if not needed.'],
    ['q' => 'I rang up the wrong item or quantity — can it be fixed without deleting the whole sale?', 'a' => 'Yes — an admin can open <strong>Edit</strong> on the sale from Today\'s Sales, correct the quantity, price, or payment split, and give a reason. This reverses the old ledger/accounting effect and posts a corrected one, same Recycle-Bin-backed reversal pattern as everywhere else. Admin edits apply immediately (admin submission is the approval, same as everywhere in this system).'],
    ['q' => 'Can I delete a POS sale entirely?', 'a' => 'Yes, admin-only, from Today\'s Sales — it reverses any credit-ledger effect and moves it to the Recycle Bin, fully restorable if needed.'],
    ['q' => 'What if a branch has no petty cash account set up yet?', 'a' => 'Cash sales at that branch will be rejected with a clear error rather than silently posting to the wrong place — set up the branch\'s petty cash account in Chart of Accounts / Branch Petty Cash first.'],
  ],
],

/* ── ADMIN TOOLS (admin only) ────────────────────────────────────────────── */
[
  'id' => 'admin', 'icon' => 'fa-shield-halved', 'title' => 'Admin tools & policies', 'audience' => 'admin',
  'intro' => 'Controls available to administrators. Changes take effect within a few minutes or at next login.',
  'steps' => [
    '<strong>Settings → Dispatch Hold Policy</strong>: hold every order until cleared (default ON).',
    '<strong>Settings → Payment Approval Policy</strong>: require approval for every receipt (default ON).',
    '<strong>Role Access Matrix</strong>: read-only overview of which modules each role can reach.',
    '<strong>Approvals</strong>: approve escalated orders (within limit) and expense vouchers (single/bulk).',
  ],
  'qa' => [
    ['q' => 'How do I see who can access what?', 'a' => 'Admin → <strong>Role Access Matrix</strong> — a Role × Module grid. Manage the actual per-user access in User Privileges.'],
    ['q' => 'Two big safety switches', 'a' => '<strong>Dispatch Hold Policy</strong> (nothing ships without clearance) and <strong>Payment Approval Policy</strong> (every receipt reviewed). Both in Settings, both instant, no code changes.'],
    ['q' => 'Setting per-user ৳ limits and page access', 'a' => 'That\'s in <strong>User Privileges</strong> — see the Superadmin section.', 'who' => 'superadmin'],
  ],
],

/* ── SUPERADMIN (superadmin only) ────────────────────────────────────────── */
[
  'id' => 'superadmin', 'icon' => 'fa-user-shield', 'title' => 'Superadmin controls', 'audience' => 'superadmin',
  'intro' => 'The most sensitive controls — reserved for you. Nothing here is destructive without a way back.',
  'flow' => $flow([
    ['stop', 'Something deleted'], '>',
    ['warn', 'Recycle Bin batch', 'rows + snapshots'], '>',
    ['end', 'Restore — all back'], '>',
    ['stop', 'or Purge — gone'],
  ]),
  'steps' => [
    '<strong>User Privileges</strong>: per-user tree (modules → pages → actions) with inline ৳ limits (approve / amend / early-release / collect / delivery).',
    '<strong>Recycle Bin</strong>: restore or permanently purge any deleted business record.',
    '<strong>Credit Limits</strong> & over-limit order approval; opening balances on new customers.',
    '<strong>Reversals</strong>: reverse posted payments; delete orders/customers (→ Recycle Bin).',
  ],
  'qa' => [
    ['q' => 'How does the Recycle Bin work?', 'a' => 'Every deleted business record — orders, payments, customers, ledger entries, expenses, returns, products, bank entries, commodity sales &amp; commodity payments, loans &amp; loan repayments — lands here in a <strong>batch</strong>. Restore brings the whole thing back (including the balances it changed); Purge removes it permanently.'],
    ['q' => 'Restoring a deleted customer', 'a' => 'Open Admin → Recycle Bin, find the customer batch, and Restore — the customer and all their related records (orders, ledger, payments) come back together, in the correct order.'],
    ['q' => 'Setting a user\'s ৳ limits and access', 'a' => 'User Privileges → open the user → tick modules/pages/actions and type the ৳ limits next to the actions they control (e.g. approve-order limit, collect-payment limit). Save; effective within ~5 min or next login.'],
    ['q' => 'Why can only I approve over-credit-limit orders?', 'a' => 'By design — extending credit beyond a customer\'s limit is a decision reserved for you. Such orders auto-escalate and alert you; the approve form is hidden for everyone else.'],
    ['q' => 'Backups', 'a' => 'The database is backed up nightly (stored locally and uploaded to Google Drive). If a Drive upload fails you get a Telegram alert.'],
  ],
],

/* ── HELP / FAQ ──────────────────────────────────────────────────────────── */
[
  'id' => 'help', 'icon' => 'fa-circle-question', 'title' => 'Troubleshooting & FAQ', 'audience' => 'all',
  'intro' => 'Quick answers to the most common questions.',
  'qa' => [
    ['q' => 'A button or page is missing for me.', 'a' => 'Access is set per user. If you need a page or action, ask an administrator to enable it in your privileges.'],
    ['q' => 'My action was "queued" or "escalated" instead of done.', 'a' => 'That\'s the approval system: your amount/order is above your limit or a policy requires a second person. It will complete once a senior officer approves it.'],
    ['q' => 'I deleted something by mistake.', 'a' => 'Most deletions go to the <strong>Recycle Bin</strong> and can be restored by a Superadmin — tell your admin what and when.'],
    ['q' => 'The QR / dispatch slip won\'t scan.', 'a' => 'Make sure you have internet and are logged in. If it says "not genuine", the slip is fake/altered — report it.'],
    ['q' => 'Numbers look off on a customer.', 'a' => 'Balances come from the ledger, not a cached figure. Open the customer\'s ledger to see the running statement; if it still looks wrong, tell your admin.'],
  ],
],

];

require_once __DIR__ . '/templates/header.php';
// visible sections for this user
$visible = array_values(array_filter($sections, fn($s) => $can($s['audience'])));
?>

<style>
.flow { display:flex; flex-wrap:wrap; align-items:center; gap:6px; margin:14px 0; }
.fstep { background:#eff6ff; border:1.5px solid #93c5fd; color:#1e3a8a; font-size:12px; font-weight:600;
         padding:8px 12px; border-radius:10px; text-align:center; line-height:1.35; max-width:200px; }
.fstep small { display:block; font-weight:400; color:#3b82f6; font-size:10px; margin-top:2px; }
.fstep.start { background:#f0fdf4; border-color:#86efac; color:#14532d; }
.fstep.end   { background:#f0fdf4; border-color:#4ade80; color:#14532d; }
.fstep.warn  { background:#fffbeb; border-color:#fcd34d; color:#92400e; }
.fstep.stop  { background:#fef2f2; border-color:#fca5a5; color:#991b1b; }
.farr  { color:#9ca3af; font-size:16px; font-weight:700; flex-shrink:0; }
.man-section { scroll-margin-top: 90px; }
.qa details { border:1px solid #e5e7eb; border-radius:10px; margin-top:8px; overflow:hidden; }
.qa details[open] { border-color:#bfdbfe; }
.qa summary { cursor:pointer; list-style:none; padding:10px 14px; font-weight:600; font-size:.9rem; color:#1f2937;
              background:#f9fafb; display:flex; align-items:center; gap:8px; }
.qa summary::-webkit-details-marker { display:none; }
.qa summary::before { content:'\f105'; font-family:'Font Awesome 6 Free'; font-weight:900; color:#3b82f6; transition:transform .15s; }
.qa details[open] summary::before { transform:rotate(90deg); }
.qa .ans { padding:10px 14px 14px 34px; font-size:.875rem; color:#4b5563; line-height:1.6; }
.badge-adm { font-size:9px; font-weight:700; padding:1px 6px; border-radius:9px; background:#ede9fe; color:#6d28d9; margin-left:auto; }
@media print { .man-toc, nav, footer, .no-print { display:none !important; } .man-card { break-inside: avoid; } .qa details { break-inside: avoid; } .qa details:not([open]) > *:not(summary){display:block;} }
</style>

<div class="max-w-screen-xl mx-auto px-4 sm:px-6 py-6">

<div class="mb-6 flex flex-wrap items-start justify-between gap-3">
    <div>
        <h1 class="text-3xl font-bold text-gray-900"><i class="fas fa-book-open text-blue-600 mr-2"></i>User Manual</h1>
        <p class="text-gray-500 mt-1">
            <span class="inline-block px-2 py-0.5 rounded-full text-[11px] font-bold bg-blue-100 text-blue-700"><?php echo $edition; ?> edition</span>
            Tailored to your access — this shows what <strong><?php echo htmlspecialchars($currentUser['display_name'] ?? 'you'); ?></strong> can do.
            <button onclick="window.print()" class="no-print ml-2 text-xs text-blue-600 hover:underline"><i class="fas fa-print"></i> Print</button>
        </p>
    </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-4 gap-6">

<!-- TOC -->
<div class="lg:col-span-1 man-toc no-print">
    <div class="bg-white rounded-xl shadow-md p-4 sticky top-20">
        <p class="text-xs font-bold text-gray-400 uppercase mb-2">Index</p>
        <nav class="space-y-1 text-sm">
            <?php foreach ($visible as $s): ?>
            <a href="#<?php echo $s['id']; ?>" class="flex items-center gap-2 px-2 py-1.5 rounded-lg text-gray-600 hover:bg-blue-50 hover:text-blue-700">
                <i class="fas <?php echo $s['icon']; ?> w-4 text-center text-blue-400"></i><?php echo htmlspecialchars($s['title']); ?>
                <?php if ($s['audience'] !== 'all'): ?><span class="badge-adm"><?php echo $s['audience'] === 'superadmin' ? 'SA' : 'ADM'; ?></span><?php endif; ?>
            </a>
            <?php endforeach; ?>
        </nav>
    </div>
</div>

<!-- CONTENT -->
<div class="lg:col-span-3 space-y-6">
    <?php foreach ($visible as $s): ?>
    <div id="<?php echo $s['id']; ?>" class="man-section man-card bg-white rounded-xl shadow-md p-6">
        <h2 class="text-lg font-bold text-gray-800 mb-1 flex items-center gap-2">
            <i class="fas <?php echo $s['icon']; ?> text-blue-500"></i><?php echo htmlspecialchars($s['title']); ?>
            <?php if ($s['audience'] === 'admin'): ?><span class="badge-adm">Admin</span><?php endif; ?>
            <?php if ($s['audience'] === 'superadmin'): ?><span class="badge-adm">Superadmin</span><?php endif; ?>
        </h2>
        <?php if (!empty($s['intro'])): ?><p class="text-sm text-gray-600 mb-2"><?php echo $s['intro']; ?></p><?php endif; ?>
        <?php if (!empty($s['flow'])) echo $s['flow']; ?>

        <?php if (!empty($s['steps'])): ?>
        <ol class="text-sm text-gray-600 list-decimal ml-5 space-y-1 mt-2">
            <?php foreach ($s['steps'] as $st): ?><li><?php echo $st; ?></li><?php endforeach; ?>
        </ol>
        <?php endif; ?>

        <?php
        // Q&A filtered by the current user's access
        $qas = array_filter($s['qa'] ?? [], fn($x) => $can($x['who'] ?? 'all'));
        if ($qas): ?>
        <div class="qa mt-4">
            <p class="text-xs font-bold text-gray-400 uppercase mb-1">Questions &amp; answers</p>
            <?php foreach ($qas as $x): ?>
            <details>
                <summary><?php echo htmlspecialchars($x['q']); ?>
                    <?php if (($x['who'] ?? 'all') === 'superadmin'): ?><span class="badge-adm">SA</span>
                    <?php elseif (($x['who'] ?? 'all') === 'admin'): ?><span class="badge-adm">ADM</span><?php endif; ?>
                </summary>
                <div class="ans"><?php echo $x['a']; ?></div>
            </details>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>

    <p class="text-center text-xs text-gray-400 pb-4">Ujjal Flour Mills ERP — <?php echo $edition; ?> edition · generated <?php echo date('F Y'); ?>. Questions beyond this? Ask your administrator.</p>
</div>
</div>
</div>

<?php require_once __DIR__ . '/templates/footer.php'; ?>
