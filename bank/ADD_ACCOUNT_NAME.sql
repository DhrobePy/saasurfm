-- Adds account_name to v_bank_statement (bank/central_statement.php), so the
-- "Bank" column can show "Bank — Account Name" instead of just "Bank".
-- Verified 11 Oct 2026: row count against the live view matched exactly
-- (10752 = 10752) before this was written — same rows, one extra column.
-- Blocked from running automatically (DDL on a shared view) — run manually:
--   mysql -u<user> -p <database> < bank/ADD_ACCOUNT_NAME.sql
-- or paste the CREATE OR REPLACE VIEW statement into phpMyAdmin / Drive & Backups' SQL tool.

CREATE OR REPLACE VIEW `v_bank_statement` AS

SELECT 'cr_payment' AS source, cp.id AS source_id, cp.payment_date AS txn_date, cp.bank_account_id AS bank_account_id,
       CONVERT(ba.bank_name USING utf8mb4) COLLATE utf8mb4_unicode_ci AS bank_name, CONVERT(ba.account_name USING utf8mb4) COLLATE utf8mb4_unicode_ci AS account_name, CONVERT(ba.account_number USING utf8mb4) COLLATE utf8mb4_unicode_ci AS account_number,
       cp.amount AS credit_amount, 0.00 AS debit_amount,
       CONVERT(CONCAT('Received — ', c.name, ' (', cp.payment_number, ')') USING utf8mb4) COLLATE utf8mb4_unicode_ci AS description,
       CONVERT(cp.payment_number USING utf8mb4) COLLATE utf8mb4_unicode_ci AS reference, CONVERT(cp.payment_method USING utf8mb4) COLLATE utf8mb4_unicode_ci AS payment_method, cp.created_at AS created_at
FROM customer_payments cp
JOIN bank_accounts ba ON ba.id = cp.bank_account_id
JOIN customers c ON c.id = cp.customer_id
WHERE cp.payment_method <> 'Cash'
UNION ALL
SELECT 'expense' AS source, ev.id AS source_id, ev.expense_date AS txn_date, ev.bank_account_id AS bank_account_id,
       CONVERT(ba.bank_name USING utf8mb4) COLLATE utf8mb4_unicode_ci AS bank_name, CONVERT(ba.account_name USING utf8mb4) COLLATE utf8mb4_unicode_ci AS account_name, CONVERT(ba.account_number USING utf8mb4) COLLATE utf8mb4_unicode_ci AS account_number,
       0.00 AS credit_amount, ev.total_amount AS debit_amount,
       CONVERT(CONCAT('Expense — ', ec.category_name, COALESCE(CONCAT(': ', ev.remarks), '')) USING utf8mb4) COLLATE utf8mb4_unicode_ci AS description,
       CONVERT(ev.voucher_number USING utf8mb4) COLLATE utf8mb4_unicode_ci AS reference, CONVERT('Bank' USING utf8mb4) COLLATE utf8mb4_unicode_ci AS payment_method, ev.created_at AS created_at
FROM expense_vouchers ev
JOIN bank_accounts ba ON ba.id = ev.bank_account_id
JOIN expense_categories ec ON ec.id = ev.category_id
WHERE ev.payment_method = 'bank' AND ev.status = 'approved'
UNION ALL
SELECT 'purchase_payment' AS source, pmt.id AS source_id, pmt.payment_date AS txn_date, pmt.bank_account_id AS bank_account_id,
       CONVERT(ba.bank_name USING utf8mb4) COLLATE utf8mb4_unicode_ci AS bank_name, CONVERT(ba.account_name USING utf8mb4) COLLATE utf8mb4_unicode_ci AS account_name, CONVERT(ba.account_number USING utf8mb4) COLLATE utf8mb4_unicode_ci AS account_number,
       0.00 AS credit_amount, pmt.amount_paid AS debit_amount,
       CONVERT(CONCAT('Purchase — ', pmt.supplier_name, ' (', pmt.payment_voucher_number, ')') USING utf8mb4) COLLATE utf8mb4_unicode_ci AS description,
       CONVERT(pmt.reference_number USING utf8mb4) COLLATE utf8mb4_unicode_ci AS reference, CONVERT(pmt.payment_method USING utf8mb4) COLLATE utf8mb4_unicode_ci AS payment_method, pmt.created_at AS created_at
FROM purchase_payments_adnan pmt
JOIN bank_accounts ba ON ba.id = pmt.bank_account_id
WHERE pmt.payment_method IN ('bank','cheque')
UNION ALL
SELECT 'bank_tx' AS source, bt.id AS source_id, bt.transaction_date AS txn_date, bam.bank_account_id AS bank_account_id,
       CONVERT(COALESCE(ba.bank_name, bta.bank_name, '') USING utf8mb4) COLLATE utf8mb4_unicode_ci AS bank_name,
       CONVERT(COALESCE(ba.account_name, bta.account_name, '') USING utf8mb4) COLLATE utf8mb4_unicode_ci AS account_name,
       CONVERT(COALESCE(ba.account_number, bta.account_number, '') USING utf8mb4) COLLATE utf8mb4_unicode_ci AS account_number,
       CASE WHEN bt.entry_type = 'credit' THEN bt.amount ELSE 0.00 END AS credit_amount,
       CASE WHEN bt.entry_type = 'debit' THEN bt.amount ELSE 0.00 END AS debit_amount,
       CONVERT(bt.description USING utf8mb4) COLLATE utf8mb4_unicode_ci AS description, CONVERT(bt.transaction_number USING utf8mb4) COLLATE utf8mb4_unicode_ci AS reference, CONVERT('Bank' USING utf8mb4) COLLATE utf8mb4_unicode_ci AS payment_method, bt.created_at AS created_at
FROM bank_transactions bt
JOIN bank_tx_accounts bta ON bta.id = bt.bank_tx_account_id
LEFT JOIN bank_account_map bam ON bam.bank_tx_account_id = bt.bank_tx_account_id AND bam.confirmed = 1
LEFT JOIN bank_accounts ba ON ba.id = bam.bank_account_id
WHERE bt.status = 'approved'
;
