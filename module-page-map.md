---
name: module-page-map
description: "Full module-wise, page-wise structural map of the Ujjal ERP — every module's folder/pages/roles, one line each. The sitemap/reference index, not the deep-dive (see [[ujjal-erp-architecture]] for that)."
metadata: 
  node_type: memory
  type: project
  originSessionId: 5832e751-bba6-4903-882a-b7530946ca27
  modified: 2026-08-25T00:00:00.000Z
---

Built 20 Aug 2026 directly from `getModuleRegistry()` in `core/functions/helpers.php` (the app's own authoritative module/page routing table — 15 modules, ~140 nav-registered pages) cross-checked by reading each page file's top docblock/role-gate. This is the STRUCTURE reference — "what page does what, who can reach it." For the cross-cutting concerns (accounting invariants, gate/limit system, bug classes, audit findings), see [[ujjal-erp-architecture]]. For the "what got built/fixed recently" changelog, see [[implementation-backlog]].

Convention below: `page_file — description (roles: ...)`. Roles are the page's own `$allowed_roles` / `restrict_access()` call, not the delegable privilege layer (`userCanPageAction()`) that can extend access beyond the hardcoded list — Superadmin/admin always bypass restrictions.

## credit_sales (folder: `cr`) — main credit-order sales pipeline
- `sales_hub` — landing hub: live pipeline counts (pending/escalated/approved/shipped/returns/over-delivery) + per-tool privilege toggles.
- `sales_dashboard` — date-range KPI dashboard (orders, payments, outstanding, customer counts).
- `index` — role-branching dashboard (pending approvals / production queue / dispatch queue depending on role).
- `all_sales` — full order list; multi-status filter (checkbox dropdown, `status[]=`), branch/date/customer filters, partial-delivery badge (🚚 "Partial: ৳X") linking to delivery history, invoice/gate-pass print icons.
- `product_sales_report` — Superadmin/admin only. 3 tabs: **Sales by Product** (delivered orders grouped by product/variant/unit_price, showing qty/discount/net per price-point — surfaces mid-period price changes), **Price History** (flat `price_change_log`, paginated, no-op-run rows filtered out), **Price History by Product** (same data grouped/paginated by product). Built 19–20 Aug 2026.
- `create_order` — new credit order creation, incl. "Other Sales" direct commodity-resale shortcut.
- `backdated_order` — Superadmin/admin only. Records an already-delivered order directly as `delivered` with manual pricing, bypassing the normal approval pipeline (reconciliation tool).
- `credit_order_approval` — approve/escalate pending orders, delegated ৳ approval-limit logic.
- `customer_payment` — record a payment against outstanding orders, posts AR journal entry.
- `outstanding_invoices` — filterable unpaid-invoice list, due/advance classification.
- `payment_history` — payment ledger, gated edit/delete of posted payments.
- `advance_payment_collection` — collect advance payment not yet tied to a specific invoice.
- `returns_center` — hub that server-side-includes returns/stock_adjustment/over_delivery as tabs.
- `returns` — record/approve goods returns against a delivered order.
- `stock_adjustment` — manual stock +/- with offset accounting entry; approver must not be the creator.
- `payment_watch` — dispatch-clearance board for HELD orders (payment/amount gate conditions) — this is what `orderDispatchAllowed()` / the gate-pass "DO NOT RELEASE" check reads.
- `over_delivery` — record/approve over-delivery resolution (bill/retrieve/writeoff).
- `approval_requests` — shared maker/checker inbox for payment/delivery/commodity-sale/loan requests over delegated caps.
- `qr_scan_log` — read-only viewer of delivery-QR scan history, flags reused scans (`recordQrScan()`/`notifyQrReuse()`).
- `credit_order_view` *(not in nav — reached via order links)* — single order detail: Delivery History (Print Invoice + Print Gate Pass per delivery, `#deliveries` anchor), **Price Revision History** (added 19 Aug — old→new price, reason, changed-by), Returns History, Over-Delivery History.
- `admin_edit` *(hidden utility, Superadmin/admin only)* — comprehensive order edit (items/qty/price/status/branch). Mandatory "Reason for Price Change" whenever a line price is revised (validates against pre-fetched old prices, blocks save if blank) → logs to `credit_order_price_revisions`.
- `credit_invoice_print` *(hidden, print-only)* — invoice printing. Accepts optional `delivery_id` to print ONE partial delivery's actual items/qty/amount instead of the full order (fixed 19 Aug 2026 — used to always show the full order regardless).
- `dispatch_slip` *(hidden, print-only)* — the physical **GATE PASS** document with a signed QR. Accepts optional `delivery_id` for a partial-delivery-scoped gate pass (fixed 19 Aug 2026 — previously order-only, no partial-delivery security check existed at all).
- `verify_delivery` *(hidden, QR-scan endpoint)* — two-stage gate-out → delivery-confirm flow behind the dispatch-slip QR. Delivery-scoped as of 19 Aug 2026 (one `cr_delivery_confirmations` row per trip, not per order); admin-only "Void Gate Pass" via the shared recycle bin, before delivery is confirmed.
- `partial_delivery` — record a delivery trip against an order (`credit_order_deliveries`/`credit_order_delivery_items`), Delivery History + page-wide Recent Deliveries list, print-invoice/print-gate-pass icons. Order's fully-delivered decision now lives in the shared `updateOrderDeliveryStatus()` helper (also used by verify_delivery.php).

## production (folder: `cr`) — production-floor pipeline (shares the `cr` folder with credit_sales)
- `credit_production` — main production queue (approved→in_production→produced→ready_to_ship), branch-scoped for production managers. Tabs: Production Queue, **Delivery Schedule** (due-today/overdue tables, sorted by `required_date, delivery_priority`), All Orders, Production Totals, Payment History.
- `production_requirement` — per-product-variant "what still needs producing" planning view, independent of order workflow status. **Date range as of 20 Aug 2026** — preset buttons (Previous/Today/Tomorrow/This Week/Next Week) + manual From/To; in-hand/produced quantity EDITING stays single-day-only (point-in-time snapshot, doesn't apply across a range) — multi-day view auto-falls-back to the existing read-only rendering path.
- `delivery_schedule` — standalone page (built 18 Aug 2026), separate from credit_production's tab of the same concept: day-colored drag-drop priority board (Schedule / Overdue tabs), filters (branch/order#/dates/truck/customer/product — deliberately no $ amount column), admin/superadmin drag-to-reorder-and-reschedule + logistics-edit modal (required date/truck/branch only, not items/pricing), Telegram + `auditLogOrder()` on every change. New `credit_orders.delivery_priority` column.
- `credit_dispatch` — dispatch board, order handoff to trucks/drivers (goods_on_board→shipped).
- `partial_delivery` — *(also listed under credit_sales above — this module's nav entry points to the same file)*.
- `order_status` — bulk order status console, AJAX updates. Dual-claimed by `credit_sales` AND `production` modules. **Fixed 23 Aug 2026** (was bare `restrict_access()`, no role fallback, silently denied real `production`-module grants — see the recurring bug-class note in [[ujjal-erp-architecture]]) — now `userHasPageGrant()` dual-module check + explicit role list.
- `sales_report` — date-range/branch/product/customer sales report + real csv/xlsx/pdf export, click-through product drill-down to underlying orders. Owned by `production` module (not `credit_sales`, despite living in `cr/`). **Fixed 23 Aug 2026** (same bug class — bare `restrict_access([])` was silently auto-resolving to `credit_sales`) — now explicitly passes `'production'`. Its date-attribution logic was also fixed 25 Aug 2026 (was falling back to `updated_at`, an unstable proxy — see [[implementation-backlog]]).
- `dispatch_report` *(new, built 25 Aug 2026)* — goods-shipped-from-factory report: date range + branch/factory filter, Product Breakdown with click-through drill-down to the underlying dispatched orders, full order-level table (truck/driver/items/status), order numbers link to the invoice view, CSV export. Uses `credit_order_shipping.shipped_date` as the "dispatched" date basis (not delivered/order date). Registered under `production`.

## customers (folder: `customers`, three pages live in `cr`)
- `directory` — customer contact list, CRUD + soft-delete-to-recycle-bin.
- `index` — legacy customer list/balances page, same delete-to-recycle-bin logic.
- `manage` — add/edit customer; opening balance settable by Superadmin only.
- `customer_credit_management` *(folder `cr`)* — update credit limit/status.
- `ageing_report` *(folder `cr`)* — AR ageing buckets (0-30/31-60/61-90/91-180/181+) as of a date.
- `customer_ledger` *(folder `cr`)* — per-customer running ledger, admin manual reconciliation/adjustment.
- `view` — single customer detail/ledger, date-filterable.

## products (folder: `product`)
- `products_overview` — read-only matrix of products/variants × branch prices.
- `products` — export/pricing data + product listing. **No visible role gate in first 60 lines** — flagged by the sweep as worth a targeted check (every sibling page gates near the top; this one opens straight into pricing-config helpers).
- `base_products` — CRUD base products, delete → recycle bin.
- `pricing` — set/update per-variant price, one variant×branch at a time. Self-migrates `price_change_log` (old_price/new_price/change_type/changed_by/changed_at/note) and `product_prices` (current active price). Price changes admin-only; Accounts read-only.
- `pricing_engine` — "Smart Pricing Engine" (the one users call "the Pricing Engine") — bulk-computes e.g. 74kg price from 50kg base + branch/mini-truck surcharges via a formula config, writes to the SAME `price_change_log`. Admin-only. **Known quirk**: logs one row per branch on every bulk run even when that branch's price didn't move — `product_sales_report.php`'s history tabs filter these no-ops out (`old_price != new_price`), confirmed live: ~4,500 rows/month of noise vs ~490 real changes.
- `inventory` — per-branch stock level for a single variant.
- `manage_variants` — CRUD variants (grade/weight/SKU) under a base product.
- `price_manager` — admin-only bulk price-editing tool (1125 lines). Existed on disk since before this session but had zero Privileges registry entry until **25 Aug 2026** — was completely undelegable; now registered.

## expenses (folder: `expense`)
- `index` — module dashboard (monthly/pending/own stats).
- `expense_history` — filterable history, Superadmin-only delete-to-recycle-bin.
- `create_expense` — single-page voucher creation with audit logging.
- `approve_expense` — approve/reject/bulk-approve pending vouchers (roles: Superadmin, admin, Expense Approver).
- `edit_expense` — edit a still-pending voucher; gated via helper functions rather than a role array.
- `expense_voucher_list` — list all vouchers, delete-pending-to-recycle-bin; gated via `canAccessExpense()`/`canDeleteExpense()`.
- `expense_categories` — manage categories/subcategories via AJAX CRUD.
- `expense_voucher_create` — create voucher + Telegram notification on success.
- `view_expense_voucher` — printable single-voucher detail.

## bank (folder: `bank`) — module-owned bank transaction tracking (distinct from `accounts`' bank accounts)
- `index` — dashboard; admin sees KPIs, others see own history.
- `create_transaction` — create/edit; "Approver" role can edit but not create new.
- `transfer` — internal bank-to-bank transfer request/approval.
- `statement` — per-account statement + CSV export.
- `central_statement` — unified statement across cr_payment/expense/purchase_payment/bank_tx sources. Admin-only.
- `manage_accounts` — CRUD module-owned `bank_tx_accounts`. Admin-only.
- `manage_types` — manage income/expense transaction categories.
- `bulk_manage` — bulk unpost/hard-delete; hard delete Superadmin-only.
- `view_transaction` — single transaction + audit log; self-owned-or-admin ownership check in-page.

## accounts (folder: `accounts`) — general ledger / chart of accounts
- `index` — dashboard: pending approvals, credit-limit risk, pending orders.
- `chart_of_accounts` — CRUD chart-of-accounts entries.
- `new_transaction` — manual accounting transaction against categorized accounts.
- `all_accounts` — summary balances of all non-bank accounts + CSV export.
- `bank_accounts` — list bank accounts with computed balances.
- `manage_bank_account` — CRUD bank accounts; auto-provisions "Opening Balance Equity" system account.
- `internal_transfer` — internal transfer between cash/petty-cash/bank accounts.
- `receive_payment` — receive payment against a vehicle-rental invoice (logistics sub-module).
- `debit_voucher` — create debit voucher (expense payment), sequence-based numbering.
- `daily_log` — day/branch view of all journal entries + credit orders that day.
- `account_statement` — per-bank-account statement by UUID.
- `chart_account_statement` — per-chart-of-accounts-account statement with opening balance.
- `reconcile` — bank/cash reconciliation, ERP vs actual balance per date; self-migrates its table.
- `tax_statement` — NBR corporate income-tax DRAFT (P&L + balance sheet) from the ledger — explicitly not a final filing.

## purchase (folder: `purchase`) — wheat/commodity procurement
- `purchase_adnan_index` — main procurement dashboard, filters/stats/supplier summary by origin.
- `purchase_adnan_supplier_summary` — supplier-wise summary, posted/verified only.
- `supplier_form` — add/edit supplier.
- `purchase_adnan_supplier_ledger` — per-supplier ledger + adjustment-entry posting.
- `procurement_catalog` — admin-only management of commodities/origins/supplier mappings.
- `all_po` — all purchase orders, filterable.
- `purchase_adnan_create_po` — create a wheat/commodity PO.
- `goods_received` — paginated GRN list, filter/print/edit/delete.
- `purchase_adnan_record_grn` — record goods received against a PO (branch/unload-point selection).
- `payments` — paginated purchase-payment list.
- `purchase_adnan_record_payment` — record payment against a PO + "Supplier Sale" barter/settlement sub-flow. **No visible `restrict_access()` in first 60 lines** — flagged by the sweep, worth a targeted check (every sibling page gates near the top).
- `purchase_adnan_adjustments` — manage Debit/Credit Adjustment Notes (DAN/CAN).
- `purchase_adnan_balance_summary` — per-supplier due/advance from GRN+adjustments-payments formula.
- `purchase_adnan_variance_report` — weight variance (loss/gain) analysis across GRNs.
- `reports` — purchase analytics/insights dashboard, period selection.
- `purchase_adnan_reconciliation` — admin-only data-quality checklist (stale balance_payable, origin confusion, etc.).
- `bank_statement` — bank-account picker + purchase-payment statement per account.
- Note: this module has an `excluded_pages` list of legacy pre-refactor pages (`create_po`, old `index`, `purchase_orders`, `suppliers`, etc.) deliberately hidden from the Privileges UI.

## trading (folder: `trading`) — commodity resale, separate from the milled-flour Credit Sales pipeline (built Jul 2026)
- `dashboard` — admin-only overview: revenue/COGS/margin, inventory value, pending approvals, activity feed.
- `commodity_sale` — record a direct commodity resale, real-time inventory/COGS posting, maker/checker above delegated limits.
- `collect_commodity_payment` — collect payment against a commodity sale balance.
- `view_commodity_sale` — read-only detail incl. journal entry, payment history.
- `edit_commodity_sale` — correct a posted sale via atomic reverse-old + recreate-new.
- `commodity_dispatch` — admin-only board of posted-but-undelivered sales, invoice/gate-pass print links.
- `commodity_invoice` — printable invoice for a commodity sale.
- `commodity_gate_pass` — printable gate pass with its own signed QR (parallel system to credit_sales' dispatch_slip/verify_delivery — NOT the same gate-pass fix applied 19 Aug; this is a separate implementation for commodity sales specifically, not yet checked for the same partial-delivery gap).
- `commodity_verify_delivery` — two-stage QR scan (gate-out → delivery-confirm) for commodity sales.
- `commodity_inventory` — admin-only read-only on-hand stock + weighted-avg cost per commodity×branch.
- `margin_report` — admin-only per-commodity revenue/COGS/margin + CSV export.
- `business_partners` — admin-only: link a customer record and supplier record as the same real-world party.
- `partner_settlement` — nets AR vs AP for a linked partner via one atomic contra journal entry.

## loans (folder: `loans`)
- `dashboard` — admin-only: outstanding loans, overdue alerts, pending approvals, filterable history.
- `loan` — disburse a new loan (own table, not customer/supplier ledger), maker/checker above delegated limits.
- `view_loan` — read-only loan detail incl. disbursement journal entry + repayment history.
- `repay_loan` — collect a loan repayment.

## sales (folder: `sales`) — thin, appears to duplicate parts of credit_sales
- `index` — role-branching dashboard, near-duplicate of `cr/index.php`.
- `order_history` — historical credit-order list for sales officers, own-vs-all toggle, CSV export.

## dispatch (folder: `dispatch`) — thin
- `index` — role-branching dashboard, near-duplicate of `sales/index.php`/`cr/index.php`.

## pos (folder: `pos`) — full standalone POS (rebuilt/hardened earlier this session, see [[ujjal-erp-architecture]])
- `index` — main POS terminal: item/cart discounts, multi-payment, thermal receipt.
- `dashboard` — KPI summary (today/period sales, cash vs credit, outstanding ledger, pending verifications/deposits).
- `todays_sales` — today's order list; admin sees all branches, others own branch only.
- `customer_ledger` — dedicated POS-customer ledger, a SEPARATE table from the Credit Sales customer ledger.
- `collect_payment` — collect payment against a POS customer's ledger balance.
- `reports` — sales reports with date presets, branch-scoped for non-admins.
- `cash_verification` — verify actual vs expected cash-in-box per branch, logs variance.
- `eod` — End-of-Day summary run per branch.
- `confirm_deposit` — confirm next-day bank deposit of EOD cash, posts Petty Cash→Bank journal entry.
- `eod_reopen` — admin-only JSON endpoint to reopen a closed EOD day, mandatory reason + audit trail.
- `edit_order` — admin-only: correct quantity/price/payment-split on an existing POS sale's line items. Existed on disk since the 29 Jul POS rebuild but had zero Privileges registry entry until **25 Aug 2026** — now registered.
- `pending_approvals` — maker/checker queue for POS sales requiring approval (over credit limit, etc.). Same as above — existed, undelegable, now registered.
- `verify_exit` — the QR exit-scan gate check (`posExitQrSignature()`). Same as above — existed, undelegable, now registered.

## logistics (folder: `logistics`) — vehicles, drivers, fuel, maintenance, rentals, trips *(new module entry, 25 Aug 2026)*
- `index` — Logistics Dashboard. Registered in the Privileges system for the first time on 25 Aug 2026 — the whole `logistics/` folder had zero module-registry coverage before, despite real `restrict_access()` gating throughout its ~20 pages.
- **Known limitation**: only `index.php` is exposed. The subdirectory pages (`logistics/drivers/`, `logistics/fuel/`, `logistics/maintenance/`, `logistics/rentals/`, `logistics/trips/`, `logistics/vehicles/`) remain invisible to Privileges — `scanModulePages()` doesn't recurse into subfolders, and naively adding them would collide on `page_key` (every subfolder's `index.php` would resolve to the same bare basename `'index'`). Needs a coordinated fix to both `scanModulePages()` and the `page_key` convention; not attempted.

## collector (folder: `collector`)
- **`index` is registered in the nav but the file/folder does not exist on disk at all** — only these top-level module folders actually exist: `admin, bank, cr, customers, dispatch, expense, loans, pos, product, purchase, sales, trading, accounts`. The `collector` role itself IS used as an allowed role on other pages (`customer_payment.php`, `payment_history.php`, etc.), so the role is real/in use — just its own dedicated landing page was apparently never built or was removed. Worth flagging to the user rather than assuming it's fine.

## admin (folder: `admin`) — system administration
- `daily_summary` — Superadmin/admin: daily financial summary (position, collections, deliveries), date range.
- `cashflow_dashboard` — cash inflow/outflow/raw-material/product KPIs, period-over-period comparison.
- `operations_center` — income/expense KPI dashboard with drill-down modals.
- `users` — user management; Superadmin sees a plain-text-password column (existing design, not something introduced this session).
- `employees` — employee list, position/department/branch joins.
- `user_activity` — Superadmin-only system audit-log viewer incl. login/logout events.
- `drivers_trucks` — CRUD drivers + fleet vehicles.
- `settings` — dashboard widget layout preferences (drag-drop) per user.
- `privileges` — Superadmin-only Roles & Privileges editor — the UI that drives `userCanPageAction()` everywhere else in the app.
- `role_matrix` — read-only overview of which modules each role's active users can reach.
- `db_viewer` — full raw-table CRUD browser/editor, whitelist + audit logging.
- `drive_manager` — Google Drive DB-backup management (see [[drive-backup-auth]]).
- `recycle_bin` — Superadmin-only restore/purge UI for everything archived via `recycleBegin()`/`recycleArchiveDelete()` app-wide.
- `telegram_ai_users` — manage the allow-list for the Telegram `/ask` AI query bot (see [[implementation-backlog]] for the bot's own build history).

## Security fixes from the 25 Aug 2026 full sweep (not page-map structure, but relevant to anyone browsing this file)
Two background agents did a complete page-by-page Privileges sweep on 25 Aug 2026 (full detail in [[implementation-backlog]]). Headline finding: **4 files with zero authentication were live on production**, found and disabled (renamed `.disabled-20260825`, not deleted):
- `cr/test.php` — accepted unauthenticated POST and wrote real rows into `customer_payments`. Most severe — a live fake-payment-injection vector.
- `cr/test5.php`, `cr/payment_debug.php` — read-only, dumped real customer payment data to anyone.
- `modules/wheat_shipment_dashboard.php` — full CRUD, no auth at all; was accidentally non-functional (missing dependency → HTTP 500) rather than actually safe.
Also fixed: a `page_key` mismatch on `purchase/procurement_catalog.php` that had silently broken a real user's (id=26) already-granted Privileges access; the same bug class on `trading/edit_commodity_sale.php`; three one-time DB-migration scripts in `bank/` that were wrongly showing as ordinary togglable Privileges checkboxes instead of being hidden.

## Loose ends this sweep surfaced (not yet investigated/fixed)
- `collector/index.php` — nav entry with no backing file. Re-confirmed still true as of the 25 Aug 2026 sweep. `collector` role is genuinely used elsewhere (`customer_payment.php`, `payment_history.php`, etc.), so likely an intentionally-unbuilt landing page, not an orphaned reference — needs a product decision (build it, or remove the dead nav entry).
- `purchase/purchase_adnan_record_payment.php` and `product/products.php` — no role gate visible in the first 60 lines, unlike every sibling page in their module. Could be gated further down, or could be relying only on `core/init.php`'s bare login check — worth a direct read before assuming either way.
- `expense/edit_expense.php`, `expense/expense_voucher_list.php` — role gating done via helper functions (`canAccessExpense()` etc.) rather than an explicit role array, so effective access couldn't be confirmed from a docblock skim alone.
- `trading/commodity_gate_pass.php` + `commodity_verify_delivery.php` — a SEPARATE QR gate-pass implementation from credit_sales' `dispatch_slip.php`/`verify_delivery.php`. The 19 Aug 2026 partial-delivery security fix was only applied to the credit_sales one — this trading-side gate pass has NOT been checked for the same one-confirmation-per-order limitation. Worth auditing if commodity sales ever go through partial/multi-trip delivery.
- `purchase/purchase_adnan_reconcile.php` — appears to be a byte-for-byte unreferenced duplicate of `purchase_adnan_reconciliation.php` (found 25 Aug 2026). Recommend confirming and deleting rather than registering as its own page.
- `production/index.php` (a legacy top-level `production/` folder, NOT the `cr`-based "Production" module) — only reachable from a dated legacy nav copy, not live navigation. Self-gated so not a security issue, but looks like dead code.
