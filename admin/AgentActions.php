<?php
/**
 * AgentActions.php  —  ERP Agent Action Registry
 * Place at: /admin/AgentActions.php
 *
 * To add a new action: add a block to getRegistry() + write a handle* method.
 * The AI learns about it automatically from buildActionsPrompt().
 */

class AgentActions
{
    public static function getRegistry(): array
    {
        return [

            'add_user_role' => [
                'label'    => 'Add User Role',
                'category' => 'User Management',
                'fields'   => ['role_name' => ['label'=>'Role Name','type'=>'string','required'=>true,'hint'=>'e.g. "Bank Transaction Approver"']],
                'confirm'  => 'Add role **{role_name}** to users table?',
                'handler'  => 'handleAddUserRole',
            ],

            'create_user' => [
                'label'    => 'Create User',
                'category' => 'User Management',
                'fields'   => [
                    'display_name' => ['label'=>'Full Name',       'type'=>'string', 'required'=>true],
                    'email'        => ['label'=>'Email',           'type'=>'email',  'required'=>true],
                    'role'         => ['label'=>'Role',            'type'=>'string', 'required'=>true,'hint'=>'Must be existing role'],
                    'password'     => ['label'=>'Initial Password','type'=>'string', 'required'=>true,'hint'=>'Min 8 chars'],
                ],
                'confirm'  => 'Create user **{display_name}** ({email}) role **{role}**?',
                'handler'  => 'handleCreateUser',
            ],

            'create_purchase_order' => [
                'label'    => 'Create Purchase Order',
                'category' => 'Procurement',
                'fields'   => [
                    'supplier_id'            => ['label'=>'Supplier',             'type'=>'supplier','required'=>true],
                    'wheat_origin'           => ['label'=>'Wheat Origin Country', 'type'=>'string',  'required'=>true,'hint'=>'Canada/USA/Australia/India/Argentina/Russia/Ukraine'],
                    'quantity_kg'            => ['label'=>'Quantity (kg)',         'type'=>'number',  'required'=>true],
                    'unit_price_per_kg'      => ['label'=>'Price per kg (৳)',      'type'=>'number',  'required'=>true],
                    'expected_delivery_date' => ['label'=>'Expected Delivery Date','type'=>'date',    'required'=>true],
                    'branch_id'              => ['label'=>'Delivery Branch',       'type'=>'branch',  'required'=>true],
                    'remarks'                => ['label'=>'Remarks',               'type'=>'string',  'required'=>false],
                ],
                'confirm'  => 'Create PO: **{supplier_name}** | {wheat_origin} | {quantity_kg}kg @ ৳{unit_price_per_kg}/kg = ৳{total_order_value} | Delivery: {expected_delivery_date}?',
                'handler'  => 'handleCreatePurchaseOrder',
            ],

            'create_credit_order' => [
                'label'    => 'Create Credit Order',
                'category' => 'Sales',
                'fields'   => [
                    'customer_id'          => ['label'=>'Customer',              'type'=>'customer','required'=>true],
                    'required_date'        => ['label'=>'Required Delivery Date','type'=>'date',    'required'=>true],
                    'branch_id'            => ['label'=>'Assigned Branch',       'type'=>'branch',  'required'=>true],
                    'priority'             => ['label'=>'Priority',              'type'=>'select',  'required'=>false,'options'=>['low','normal','high','urgent'],'default'=>'normal'],
                    'items'                => ['label'=>'Items (product, qty, price)','type'=>'items','required'=>true,
                                               'hint'=>'Each item: product name, qty, unit price'],
                    'shipping_address'     => ['label'=>'Delivery Address',      'type'=>'string',  'required'=>false],
                    'special_instructions' => ['label'=>'Special Instructions',  'type'=>'string',  'required'=>false],
                    'advance_paid'         => ['label'=>'Advance Payment (৳)',   'type'=>'number',  'required'=>false,'default'=>'0'],
                ],
                'confirm'  => 'Create credit order: **{customer_name}** | {item_count} item(s) | ৳{total_amount} | Due: {required_date}?',
                'handler'  => 'handleCreateCreditOrder',
            ],

            'update_order_status' => [
                'label'    => 'Update Order Status',
                'category' => 'Sales',
                'fields'   => [
                    'order_number' => ['label'=>'Order Number','type'=>'string','required'=>true],
                    'new_status'   => ['label'=>'New Status',  'type'=>'select','required'=>true,
                                       'options'=>['pending_approval','approved','in_production','produced','ready_to_ship','shipped','delivered','cancelled']],
                    'comments'     => ['label'=>'Comments',    'type'=>'string','required'=>false],
                ],
                'confirm'  => 'Update order **{order_number}** → status **{new_status}**?',
                'handler'  => 'handleUpdateOrderStatus',
            ],

            'create_customer' => [
                'label'    => 'Create Customer',
                'category' => 'Customers',
                'fields'   => [
                    'name'             => ['label'=>'Contact Name',   'type'=>'string', 'required'=>true],
                    'business_name'    => ['label'=>'Business Name',  'type'=>'string', 'required'=>false],
                    'phone_number'     => ['label'=>'Phone',          'type'=>'string', 'required'=>true],
                    'customer_type'    => ['label'=>'Type',           'type'=>'select', 'required'=>true,'options'=>['Credit','POS']],
                    'credit_limit'     => ['label'=>'Credit Limit (৳)','type'=>'number','required'=>false,'default'=>'0'],
                    'business_address' => ['label'=>'Address',        'type'=>'string', 'required'=>false],
                ],
                'confirm'  => 'Create {customer_type} customer **{name}** ({business_name}) | Phone: {phone_number} | Limit: ৳{credit_limit}?',
                'handler'  => 'handleCreateCustomer',
            ],

            'create_expense' => [
                'label'    => 'Record Expense',
                'category' => 'Finance',
                'fields'   => [
                    'expense_date'      => ['label'=>'Date',          'type'=>'date',   'required'=>true],
                    'category_id'       => ['label'=>'Category',      'type'=>'expcat', 'required'=>true],
                    'handled_by_person' => ['label'=>'Handled By',    'type'=>'string', 'required'=>true],
                    'total_amount'      => ['label'=>'Amount (৳)',     'type'=>'number', 'required'=>true],
                    'payment_method'    => ['label'=>'Payment Method', 'type'=>'select','required'=>true,'options'=>['cash','bank']],
                    'branch_id'         => ['label'=>'Branch',         'type'=>'branch','required'=>true],
                    'remarks'           => ['label'=>'Remarks',        'type'=>'string','required'=>false],
                ],
                'confirm'  => 'Record expense ৳**{total_amount}** on {expense_date} | {payment_method} | Branch: {branch_name}?',
                'handler'  => 'handleCreateExpense',
            ],

        ];
    }


    // ─────────────────────────────────────────────────────────────────────────
    // COMPACT PROMPT  (~300 tokens vs previous ~2000 tokens)
    // ─────────────────────────────────────────────────────────────────────────
    public static function buildActionsPrompt(): string
    {
        $lines = ["AVAILABLE ACTIONS (ask for required fields, then confirm, then execute):\n"];
        foreach (self::getRegistry() as $key => $a) {
            $fields = [];
            foreach ($a['fields'] as $fk => $f) {
                $req  = $f['required'] ? '*' : '?';
                $opts = isset($f['options']) ? '['.implode('|',$f['options']).']' : '';
                $hint = isset($f['hint'])    ? " ({$f['hint']})" : '';
                $fields[] = "{$fk}{$req}{$opts}{$hint}";
            }
            $lines[] = "• [{$key}] {$a['label']}: " . implode(', ', $fields);
            $lines[] = "  confirm: \"{$a['confirm']}\"";
        }
        return implode("\n", $lines);
    }


    // ═════════════════════════════════════════════════════════════════════════
    // EXECUTE DISPATCHER
    // ═════════════════════════════════════════════════════════════════════════
    public static function execute(string $key, array $fields, $db, int $uid): array
    {
        $reg = self::getRegistry();
        if (!isset($reg[$key])) return ['success'=>false,'error'=>"Unknown action: {$key}"];
        $h = $reg[$key]['handler'];
        if (!method_exists(self::class, $h)) return ['success'=>false,'error'=>"Handler not implemented: {$h}"];
        return self::{$h}($fields, $db, $uid);
    }


    // ═════════════════════════════════════════════════════════════════════════
    // HANDLERS
    // ═════════════════════════════════════════════════════════════════════════

    public static function handleAddUserRole(array $f, $db, int $uid): array
    {
        $role = trim($f['role_name'] ?? '');
        if (!$role) return ['success'=>false,'error'=>'Role name cannot be empty.'];
        if (!preg_match('/^[a-zA-Z0-9 \-_]+$/', $role)) return ['success'=>false,'error'=>'Invalid characters in role name.'];

        $col = $db->query("SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='role'")->first();
        if (!$col) return ['success'=>false,'error'=>'Could not read users.role enum.'];

        preg_match_all("/'([^']+)'/", $col->COLUMN_TYPE, $m);
        $roles = $m[1] ?? [];
        if (in_array($role, $roles)) return ['success'=>false,'error'=>"Role '{$role}' already exists."];

        $roles[] = $role;
        $enum = "'".implode("','", array_map('addslashes', $roles))."'";
        $db->getPdo()->exec("ALTER TABLE `users` MODIFY COLUMN `role` ENUM({$enum}) NOT NULL DEFAULT 'sales-other'");

        return ['success'=>true,'message'=>"✅ Role **{$role}** added to users table. Total roles: ".count($roles)."."];
    }

    public static function handleCreateUser(array $f, $db, int $uid): array
    {
        if (strlen($f['password']??'') < 8) return ['success'=>false,'error'=>'Password min 8 chars.'];
        if ($db->query("SELECT id FROM users WHERE email=?", [trim($f['email'])])->first())
            return ['success'=>false,'error'=>'Email already in use.'];

        $id = $db->insert('users', [
            'display_name'  => trim($f['display_name']),
            'email'         => strtolower(trim($f['email'])),
            'password_hash' => password_hash($f['password'], PASSWORD_BCRYPT),
            'role'          => $f['role'],
            'status'        => 'active',
        ]);
        return $id
            ? ['success'=>true,'message'=>"✅ User **{$f['display_name']}** created (ID:{$id}) with role **{$f['role']}**."]
            : ['success'=>false,'error'=>'DB insert failed.'];
    }

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

    public static function handleCreateCreditOrder(array $f, $db, int $uid): array
    {
        $c = $db->query("SELECT id,name,business_name,current_balance,credit_limit FROM customers WHERE id=?", [(int)$f['customer_id']])->first();
        if (!$c) return ['success'=>false,'error'=>'Customer not found.'];

        $items = is_array($f['items']) ? $f['items'] : json_decode($f['items']??'[]', true);
        if (empty($items)) return ['success'=>false,'error'=>'At least one order item required.'];

        $subtotal = array_sum(array_column($items,'line_total'));
        $advance  = (float)($f['advance_paid'] ?? 0);
        $total    = $subtotal;
        $balance  = $total - $advance;
        $order_no = 'CR-'.date('Ymd').'-'.rand(1000,9999);
        $wt_kg    = array_sum(array_column($items,'weight_kg'));

        $db->getPdo()->beginTransaction();
        try {
            $oid = $db->insert('credit_orders', [
                'order_number'         => $order_no,
                'customer_id'          => (int)$f['customer_id'],
                'order_date'           => date('Y-m-d'),
                'required_date'        => $f['required_date'],
                'order_type'           => 'credit',
                'subtotal'             => $subtotal,
                'discount_amount'      => 0,
                'tax_amount'           => 0,
                'total_amount'         => $total,
                'advance_paid'         => $advance,
                'balance_due'          => $balance,
                'amount_paid'          => $advance,
                'status'               => 'draft',
                'assigned_branch_id'   => (int)$f['branch_id'],
                'priority'             => $f['priority'] ?? 'normal',
                'sort_order'           => 0,
                'created_by_user_id'   => $uid,
                'shipping_address'     => trim($f['shipping_address'] ?? ''),
                'special_instructions' => trim($f['special_instructions'] ?? ''),
                'internal_notes'       => 'Created via AI Agent',
                'total_weight_kg'      => $wt_kg,
            ]);
            if (!$oid) throw new Exception('Order insert failed.');
            foreach ($items as $item) {
                $db->insert('credit_order_items', [
                    'order_id'        => $oid,
                    'product_id'      => (int)($item['product_id'] ?? 0),
                    'variant_id'      => (int)($item['variant_id'] ?? 0) ?: null,
                    'quantity'        => (float)$item['quantity'],
                    'unit_price'      => (float)$item['unit_price'],
                    'discount_amount' => 0,
                    'tax_amount'      => 0,
                    'line_total'      => (float)$item['line_total'],
                    'total_weight_kg' => (float)($item['weight_kg'] ?? 0),
                ]);
            }
            $db->getPdo()->commit();
            $cname = $c->business_name ?: $c->name;
            return ['success'=>true,'message'=>"✅ Credit Order **{$order_no}** created!\n**{$cname}** | ".count($items)." items | ৳".number_format($total,2)."\nAdvance: ৳".number_format($advance,2)." | Balance: ৳".number_format($balance,2)."\n*Status: Draft — submit for approval.*"];
        } catch (Exception $e) {
            $db->getPdo()->rollBack();
            return ['success'=>false,'error'=>'Transaction failed: '.$e->getMessage()];
        }
    }

    public static function handleUpdateOrderStatus(array $f, $db, int $uid): array
    {
        $allowed = ['pending_approval','approved','in_production','produced','ready_to_ship','shipped','delivered','cancelled'];
        if (!in_array($f['new_status'], $allowed)) return ['success'=>false,'error'=>'Invalid status.'];

        $o = $db->query("SELECT id,status,order_number FROM credit_orders WHERE order_number=?", [trim($f['order_number'])])->first();
        if (!$o) return ['success'=>false,'error'=>"Order {$f['order_number']} not found."];
        if ($o->status === $f['new_status']) return ['success'=>false,'error'=>"Already in '{$f['new_status']}' status."];

        $db->query("UPDATE credit_orders SET status=?, updated_at=NOW() WHERE id=?", [$f['new_status'], $o->id]);
        $db->insert('credit_order_workflow', [
            'order_id'             => $o->id,
            'from_status'          => $o->status,
            'to_status'            => $f['new_status'],
            'action'               => 'ai_agent_update',
            'performed_by_user_id' => $uid,
            'comments'             => trim($f['comments'] ?? 'Updated via AI Agent'),
            'performed_at'         => date('Y-m-d H:i:s'),
        ]);
        return ['success'=>true,'message'=>"✅ Order **{$f['order_number']}**: **{$o->status}** → **{$f['new_status']}**"];
    }

    public static function handleCreateCustomer(array $f, $db, int $uid): array
    {
        if ($db->query("SELECT id FROM customers WHERE phone_number=?", [trim($f['phone_number'])])->first())
            return ['success'=>false,'error'=>'Phone number already in use.'];

        $id = $db->insert('customers', [
            'customer_type'   => $f['customer_type'],
            'name'            => trim($f['name']),
            'business_name'   => trim($f['business_name'] ?? ''),
            'phone_number'    => trim($f['phone_number']),
            'email'           => strtolower(trim($f['email'] ?? '')),
            'business_address'=> trim($f['business_address'] ?? ''),
            'credit_limit'    => (float)($f['credit_limit'] ?? 0),
            'initial_due'     => 0,
            'current_balance' => 0,
            'status'          => 'active',
        ]);
        if (!$id) return ['success'=>false,'error'=>'DB insert failed.'];
        $cname = $f['business_name'] ? "{$f['name']} ({$f['business_name']})" : $f['name'];
        return ['success'=>true,'message'=>"✅ Customer **{$cname}** created (ID:{$id}).\nType: {$f['customer_type']}" . ($f['customer_type']==='Credit' ? " | Limit: ৳".number_format((float)($f['credit_limit']??0)) : '')];
    }

    public static function handleCreateExpense(array $f, $db, int $uid): array
    {
        $vno = 'EXP-'.date('Ymd').'-'.rand(100,999);
        $id  = $db->insert('expense_vouchers', [
            'voucher_number'    => $vno,
            'expense_date'      => $f['expense_date'],
            'category_id'       => (int)$f['category_id'],
            'handled_by_person' => trim($f['handled_by_person']),
            'unit_quantity'     => 1,
            'per_unit_cost'     => (float)$f['total_amount'],
            'total_amount'      => (float)$f['total_amount'],
            'remarks'           => trim($f['remarks'] ?? ''),
            'payment_method'    => $f['payment_method'],
            'branch_id'         => (int)$f['branch_id'],
            'status'            => 'draft',
            'created_by_user_id'=> $uid,
        ]);
        return $id
            ? ['success'=>true,'message'=>"✅ Expense **{$vno}** created: ৳{$f['total_amount']}. Status: Draft (approve in Expenses module)."]
            : ['success'=>false,'error'=>'DB insert failed.'];
    }
}
?>