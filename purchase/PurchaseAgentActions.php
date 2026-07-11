<?php
/**
 * PurchaseAgentActions.php  —  Purchase ERP Agent Action Registry
 * Place at: /purchase/PurchaseAgentActions.php
 */

class PurchaseAgentActions
{
    public static function getRegistry(): array
    {
        return [

            'create_purchase_order' => [
                'label'    => 'Create Purchase Order',
                'category' => 'Procurement',
                'fields'   => [
                    'supplier_id'            => ['label'=>'Supplier',              'type'=>'supplier','required'=>true],
                    'wheat_origin'           => ['label'=>'Wheat Origin Country',  'type'=>'string',  'required'=>true, 'hint'=>'Canada/USA/Australia/India/Argentina/Russia/Ukraine/Brazil/Other'],
                    'quantity_kg'            => ['label'=>'Quantity (kg)',          'type'=>'number',  'required'=>true],
                    'unit_price_per_kg'      => ['label'=>'Price per kg (৳)',       'type'=>'number',  'required'=>true],
                    'expected_delivery_date' => ['label'=>'Expected Delivery Date', 'type'=>'date',    'required'=>true],
                    'branch_id'              => ['label'=>'Delivery Branch',        'type'=>'branch',  'required'=>true],
                    'remarks'                => ['label'=>'Remarks',                'type'=>'string',  'required'=>false],
                ],
                'confirm'  => 'Create PO: **{supplier_name}** | {wheat_origin} | {quantity_kg}kg @ ৳{unit_price_per_kg}/kg = ৳{total_order_value} | Delivery: {expected_delivery_date}?',
                'handler'  => 'handleCreatePurchaseOrder',
            ],

            'record_payment' => [
                'label'    => 'Record Payment',
                'category' => 'Procurement',
                'fields'   => [
                    'po_number'      => ['label'=>'PO Number',      'type'=>'string', 'required'=>true,  'hint'=>'e.g. PO-498'],
                    'amount_paid'    => ['label'=>'Amount (৳)',      'type'=>'number', 'required'=>true],
                    'payment_date'   => ['label'=>'Payment Date',   'type'=>'date',   'required'=>true],
                    'payment_type'   => ['label'=>'Payment Type',   'type'=>'select', 'required'=>true,  'options'=>['advance','regular','final']],
                    'payment_method' => ['label'=>'Payment Method', 'type'=>'select', 'required'=>true,  'options'=>['bank','cash','cheque']],
                    'bank_name'      => ['label'=>'Bank Name',      'type'=>'string', 'required'=>false, 'hint'=>'required if payment_method is bank or cheque'],
                    'notes'          => ['label'=>'Notes',          'type'=>'string', 'required'=>false],
                ],
                'confirm'  => 'Record ৳**{amount_paid}** {payment_type} payment for **{po_number}** via {payment_method} on {payment_date}?',
                'handler'  => 'handleRecordPayment',
            ],

            'record_grn' => [
                'label'    => 'Record GRN (Goods Received)',
                'category' => 'Procurement',
                'fields'   => [
                    'po_number'           => ['label'=>'PO Number',            'type'=>'string', 'required'=>true,  'hint'=>'e.g. PO-498'],
                    'grn_date'            => ['label'=>'Received Date',        'type'=>'date',   'required'=>true],
                    'quantity_received_kg'=> ['label'=>'Qty Received (kg)',    'type'=>'number', 'required'=>true],
                    'expected_quantity'   => ['label'=>'Expected Qty (kg)',     'type'=>'number', 'required'=>true,  'hint'=>'from challan/supplier invoice'],
                    'unload_branch_id'    => ['label'=>'Unload Point (Branch)','type'=>'branch', 'required'=>true],
                    'notes'               => ['label'=>'Notes/Remarks',        'type'=>'string', 'required'=>false],
                ],
                'confirm'  => 'Record GRN for **{po_number}**: {quantity_received_kg}kg received (expected: {expected_quantity}kg) on {grn_date}?',
                'handler'  => 'handleRecordGRN',
            ],

            'update_po_status' => [
                'label'    => 'Update PO Status',
                'category' => 'Procurement',
                'fields'   => [
                    'po_number'  => ['label'=>'PO Number', 'type'=>'string', 'required'=>true],
                    'new_status' => ['label'=>'New Status', 'type'=>'select', 'required'=>true,
                                    'options'=>['draft','approved','partial','completed','cancelled']],
                    'remarks'    => ['label'=>'Remarks',   'type'=>'string', 'required'=>false],
                ],
                'confirm'  => 'Update **{po_number}** status → **{new_status}**?',
                'handler'  => 'handleUpdatePOStatus',
            ],

            'close_po' => [
                'label'    => 'Close Purchase Order',
                'category' => 'Procurement',
                'fields'   => [
                    'po_number' => ['label'=>'PO Number', 'type'=>'string', 'required'=>true],
                    'reason'    => ['label'=>'Reason for closing', 'type'=>'string', 'required'=>true],
                ],
                'confirm'  => 'Close PO **{po_number}**? This hides it from the active view. Reason: {reason}',
                'handler'  => 'handleClosePO',
            ],

        ];
    }


    public static function buildActionsPrompt(): string
    {
        $lines = ["AVAILABLE ACTIONS (ask for required fields, then confirm, then EXECUTE):\n"];
        foreach (self::getRegistry() as $key => $a) {
            $fields = [];
            foreach ($a['fields'] as $fk => $f) {
                $req   = $f['required'] ? '*' : '?';
                $opts  = isset($f['options']) ? '['.implode('|',$f['options']).']' : '';
                $hint  = isset($f['hint'])    ? " ({$f['hint']})" : '';
                $fields[] = "{$fk}{$req}{$opts}{$hint}";
            }
            $lines[] = "• [{$key}] {$a['label']}: " . implode(', ', $fields);
            $lines[] = "  confirm: \"{$a['confirm']}\"";
        }
        return implode("\n", $lines);
    }


    public static function execute(string $key, array $fields, $db, int $uid): array
    {
        $reg = self::getRegistry();
        if (!isset($reg[$key])) return ['success'=>false,'error'=>"Unknown action: {$key}"];
        $h = $reg[$key]['handler'];
        if (!method_exists(self::class, $h)) return ['success'=>false,'error'=>"Handler not found: {$h}"];
        return self::{$h}($fields, $db, $uid);
    }


    // ── HANDLERS ─────────────────────────────────────────────────────────────

    public static function handleCreatePurchaseOrder(array $f, $db, int $uid): array
    {
        $s = $db->query("SELECT company_name FROM suppliers WHERE id=?", [(int)$f['supplier_id']])->first();
        if (!$s) return ['success'=>false,'error'=>'Supplier not found.'];

        $qty   = (float)$f['quantity_kg'];
        $price = (float)$f['unit_price_per_kg'];
        $total = $qty * $price;
        $po_no = 'PO-'.date('Ymd').'-'.strtoupper(substr(uniqid(),-5));

        $id = $db->insert('purchase_orders_adnan', [
            'po_number'              => $po_no,
            'po_date'                => date('Y-m-d'),
            'supplier_id'            => (int)$f['supplier_id'],
            'supplier_name'          => $s->company_name,
            'branch_id'              => (int)$f['branch_id'],
            'wheat_origin'           => trim($f['wheat_origin']),
            'quantity_kg'            => $qty,
            'unit_price_per_kg'      => $price,
            'total_order_value'      => $total,
            'expected_delivery_date' => $f['expected_delivery_date'],
            'total_received_qty'     => 0,
            'total_received_value'   => 0,
            'total_paid'             => 0,
            'po_status'              => 'draft',
            'delivery_status'        => 'pending',
            'payment_status'         => 'unpaid',
            'remarks'                => trim($f['remarks'] ?? ''),
            'created_by_user_id'     => $uid,
        ]);
        if (!$id) return ['success'=>false,'error'=>'DB insert failed.'];

        return ['success'=>true,'message'=>"✅ Purchase Order **{$po_no}** created!\n**Supplier:** {$s->company_name}\n**{$f['wheat_origin']}** | {$qty}kg @ ৳{$price}/kg\n**Total:** ৳".number_format($total,2)."\n*Status: Draft — approve in Purchase module.*"];
    }

    public static function handleRecordPayment(array $f, $db, int $uid): array
    {
        // Find PO with correct expected-quantity-based balance
        $po = $db->query(
            "SELECT po.id, po.po_number, po.supplier_id, po.supplier_name,
                    po.unit_price_per_kg, po.total_paid, po.po_status,
                    GREATEST(0, COALESCE(SUM(grn.expected_quantity * po.unit_price_per_kg),0) - po.total_paid) as balance_due
             FROM purchase_orders_adnan po
             LEFT JOIN goods_received_adnan grn
               ON grn.purchase_order_id = po.id AND grn.grn_status != 'cancelled'
             WHERE po.po_number = ?
             GROUP BY po.id", [trim($f['po_number'])]
        )->first();
        if (!$po) return ['success'=>false,'error'=>"PO {$f['po_number']} not found."];
        if ($po->po_status === 'cancelled') return ['success'=>false,'error'=>'Cannot record payment for a cancelled PO.'];

        $amount  = (float)$f['amount_paid'];
        if ($amount <= 0) return ['success'=>false,'error'=>'Amount must be greater than 0.'];

        $voucher_no = 'PPV-'.date('Ymd').'-'.rand(100,9999);

        $id = $db->insert('purchase_payments_adnan', [
            'payment_voucher_number' => $voucher_no,
            'payment_date'           => $f['payment_date'],
            'purchase_order_id'      => (int)$po->id,
            'po_number'              => $po->po_number,
            'supplier_id'            => (int)$po->supplier_id,
            'supplier_name'          => $po->supplier_name,
            'amount_paid'            => $amount,
            'payment_method'         => $f['payment_method'],
            'bank_name'              => trim($f['bank_name'] ?? ''),
            'payment_type'           => $f['payment_type'],
            'notes'                  => trim($f['notes'] ?? 'Recorded via AI Agent'),
            'is_posted'              => 0, // always draft — accountant must post
            'created_by_user_id'     => $uid,
        ]);
        if (!$id) return ['success'=>false,'error'=>'DB insert failed.'];

        $remaining = max(0, $po->balance_due - $amount);
        return ['success'=>true,'message'=>"✅ Payment **{$voucher_no}** recorded!\n**PO:** {$po->po_number} | **{$po->supplier_name}**\n**Amount:** ৳".number_format($amount,2)." ({$f['payment_type']} via {$f['payment_method']})\n**Remaining balance:** ৳".number_format($remaining,2)."\n⚠️ *Status: Draft — must be posted by accounts.*"];
    }

    public static function handleRecordGRN(array $f, $db, int $uid): array
    {
        $po = $db->query(
            "SELECT id, po_number, supplier_id, supplier_name, unit_price_per_kg
             FROM purchase_orders_adnan WHERE po_number=?", [trim($f['po_number'])]
        )->first();
        if (!$po) return ['success'=>false,'error'=>"PO {$f['po_number']} not found."];

        $received  = (float)$f['quantity_received_kg'];
        $expected  = (float)$f['expected_quantity'];
        $value     = $received * $po->unit_price_per_kg;
        $variance  = $expected > 0 ? round((($received - $expected) / $expected) * 100, 2) : 0;
        $grn_no    = 'GRN-'.date('Ymd').'-'.rand(100,9999);

        $id = $db->insert('goods_received_adnan', [
            'grn_number'            => $grn_no,
            'purchase_order_id'     => (int)$po->id,
            'grn_date'              => $f['grn_date'],
            'supplier_id'           => (int)$po->supplier_id,
            'supplier_name'         => $po->supplier_name,
            'quantity_received_kg'  => $received,
            'unit_price_per_kg'     => $po->unit_price_per_kg,
            'total_value'           => $value,
            'expected_quantity'     => $expected,
            'variance_percentage'   => $variance,
            'grn_status'            => 'draft',
            'unload_point_branch_id'=> (int)($f['unload_branch_id'] ?? 0),
            'notes'                 => trim($f['notes'] ?? 'Recorded via AI Agent'),
            'created_by_user_id'    => $uid,
        ]);
        if (!$id) return ['success'=>false,'error'=>'DB insert failed.'];

        $var_text = $variance != 0 ? " (variance: {$variance}%)" : '';
        return ['success'=>true,'message'=>"✅ GRN **{$grn_no}** recorded!\n**PO:** {$po->po_number} | **{$po->supplier_name}**\n**Received:** ".number_format($received,0)."kg | Expected: ".number_format($expected,0)."kg{$var_text}\n**Value:** ৳".number_format($value,2)."\n⚠️ *Status: Draft — must be verified by warehouse.*"];
    }

    public static function handleUpdatePOStatus(array $f, $db, int $uid): array
    {
        $allowed = ['draft','approved','partial','completed','cancelled'];
        if (!in_array($f['new_status'], $allowed)) return ['success'=>false,'error'=>'Invalid status.'];

        $po = $db->query(
            "SELECT id, po_number, po_status FROM purchase_orders_adnan WHERE po_number=?",
            [trim($f['po_number'])]
        )->first();
        if (!$po) return ['success'=>false,'error'=>"PO {$f['po_number']} not found."];
        if ($po->po_status === $f['new_status']) return ['success'=>false,'error'=>"Already in '{$f['new_status']}' status."];

        $db->query(
            "UPDATE purchase_orders_adnan SET po_status=?, remarks=CONCAT(COALESCE(remarks,''), ?) WHERE id=?",
            [$f['new_status'], "\n[AI Agent ".date('Y-m-d').": ".($f['remarks']??'Status updated')."]", $po->id]
        );

        return ['success'=>true,'message'=>"✅ PO **{$po->po_number}**: status updated **{$po->po_status}** → **{$f['new_status']}**"];
    }

    public static function handleClosePO(array $f, $db, int $uid): array
    {
        $po = $db->query(
            "SELECT id, po_number, delivery_status FROM purchase_orders_adnan WHERE po_number=?",
            [trim($f['po_number'])]
        )->first();
        if (!$po) return ['success'=>false,'error'=>"PO {$f['po_number']} not found."];
        if ($po->delivery_status === 'closed') return ['success'=>false,'error'=>"PO {$f['po_number']} is already closed."];

        $db->query(
            "UPDATE purchase_orders_adnan SET delivery_status='closed', remarks=CONCAT(COALESCE(remarks,''), ?) WHERE id=?",
            ["\n[AI Agent closed ".date('Y-m-d').": ".trim($f['reason'])."]", $po->id]
        );

        return ['success'=>true,'message'=>"✅ PO **{$po->po_number}** closed and moved to closed view.\n*Reason: {$f['reason']}*"];
    }
}
?>