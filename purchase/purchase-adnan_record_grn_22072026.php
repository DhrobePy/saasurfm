<?php
require_once __DIR__ . '/../core/init.php';
restrict_access(['Superadmin', 'admin', 'Accounts', 'accounts-demra', 'accounts-srg']);

$pageTitle = "Record Goods Received";

// Get PO ID
$po_id = $_GET['po_id'] ?? null;

// Initialize managers
$po_manager = new Purchaseadnanmanager();
$grn_manager = new Goodsreceivedadnanmanager();

// Get all GRN-eligible POs:
// - Not admin-locked (is_delivery_locked = 0)
// - Not closed or cancelled
// - Any delivery_status (pending/partial/completed/over_received) — admin may re-open completed POs
$open_pos = $po_manager->listPurchaseOrders(['grn_eligible' => true]);

// If PO ID provided, get PO details
$selected_po = null;
if ($po_id) {
    $selected_po = $po_manager->getPurchaseOrder($po_id);
}

// Branches, for the Unload Point (Branch) field — this is what commodity-trading
// costing (postCommodityGRNCost) keys stock to. unload_point_name below is a
// free-text/Bangla location label, not a branch_id, so it can't be used for that.
$branches = $db->query("SELECT id, name FROM branches WHERE status = 'active' ORDER BY name")->results();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Get selected PO details for additional fields
        $selected_po = $po_manager->getPurchaseOrder($_POST['purchase_order_id']);
        
        $data = [
            'purchase_order_id' => $_POST['purchase_order_id'],
            'grn_date' => $_POST['grn_date'],
            'truck_number' => $_POST['truck_number'] ?? null,
            'quantity_received_kg' => $_POST['quantity_received_kg'],
            'expected_quantity' => $_POST['expected_quantity'] ?? null,
            'unload_point_branch_id' => !empty($_POST['unload_point_branch_id']) ? (int)$_POST['unload_point_branch_id'] : null,
            'unload_point_name' => $_POST['unload_point_name'],
            'remarks' => $_POST['remarks'] ?? null,
            // Add missing required fields
            'po_number' => $selected_po->po_number,
            'supplier_name' => $selected_po->supplier_name,
            'unit_price_per_kg' => $selected_po->unit_price_per_kg,
            'total_value' => floatval($_POST['quantity_received_kg']) * floatval($selected_po->unit_price_per_kg)
        ];

        $result = $grn_manager->recordGoodsReceived($data);
        
        // Check if result is array or just ID
        $grn_id = is_array($result) ? ($result['grn_id'] ?? $result['id'] ?? null) : $result;
        
        if ($grn_id) {

            // ============================================
            // COMMODITY TRADING: weighted-average cost update
            // ============================================
            // Best-effort follow-up AFTER the GRN itself is already recorded — a
            // costing hiccup should never roll back a receipt that already
            // happened. Silently skipped for POs with no commodity_id (legacy /
            // non-commodity-tagged POs) — see COMMODITY_TRADING_PLAN.md §4.
            if (!empty($selected_po->commodity_id)) {
                try {
                    postCommodityGRNCost(
                        (int)$selected_po->commodity_id,
                        isset($data['unload_point_branch_id']) ? (int)$data['unload_point_branch_id'] : 0,
                        floatval($_POST['quantity_received_kg']),
                        floatval($selected_po->unit_price_per_kg)
                    );
                } catch (\Throwable $cost_e) {
                    error_log("Commodity GRN cost update error: " . $cost_e->getMessage());
                }
            }

            // ============================================
            // OVER-DELIVERY: auto-create draft DAN
            // ============================================
            $over_delivery_action = $_POST['over_delivery_action'] ?? 'as_is';
            $excess_qty           = floatval($_POST['excess_qty'] ?? 0);

            if ($over_delivery_action === 'accept_with_dan' && $excess_qty > 0) {
                try {
                    $grn_num_for_dan = is_array($result) && isset($result['grn_number'])
                        ? $result['grn_number'] : 'GRN#' . $grn_id;

                    $dan_result = $po_manager->createAdjustmentNote([
                        'note_type'         => 'debit',
                        'reason_type'       => 'over_delivery',
                        'purchase_order_id' => $data['purchase_order_id'],
                        'quantity_kg'       => $excess_qty,
                        'unit_price_per_kg' => $selected_po->unit_price_per_kg,
                        'amount'            => $excess_qty * floatval($selected_po->unit_price_per_kg),
                        'description'       => "Auto-generated: Over-delivery on {$grn_num_for_dan}. "
                                              . "Excess qty: " . number_format($excess_qty, 2) . " KG.",
                    ]);

                    if ($dan_result['success']) {
                        $_SESSION['info'] = "Draft Debit Adjustment Note {$dan_result['note_number']} created for "
                                           . number_format($excess_qty, 2) . " KG excess delivery. "
                                           . "<a href='purchase_adnan_view_adjustment.php?id={$dan_result['note_id']}' "
                                           . "class='underline font-semibold'>Review & Approve →</a>";
                    }
                } catch (Exception $dan_e) {
                    error_log("Auto-DAN creation error: " . $dan_e->getMessage());
                }
            }

            // ============================================
            // AUDIT TRAIL - GRN CREATED
            // ============================================
            try {
                if (function_exists('auditLog')) {
                    $currentUser = getCurrentUser();
                    $user_name = $currentUser['display_name'] ?? 'System User';
                    
                    // Get GRN number from result
                    $grn_number = is_array($result) && isset($result['grn_number']) 
                        ? $result['grn_number'] 
                        : ($grn_manager->getGRNNumber($grn_id) ?? 'N/A');
                    
                    $variance = floatval($_POST['quantity_received_kg']) - floatval($_POST['expected_quantity'] ?? 0);
                    $variance_pct = floatval($_POST['expected_quantity'] ?? 0) > 0 
                        ? ($variance / floatval($_POST['expected_quantity'])) * 100 
                        : 0;
                    
                    auditLog(
                        'purchase',
                        'created',
                        "GRN {$grn_number} created for PO #{$selected_po->po_number} - Expected: " . number_format($_POST['expected_quantity'] ?? 0, 2) . " KG, Received: " . number_format($_POST['quantity_received_kg'], 2) . " KG" . ($variance != 0 ? ", Variance: " . number_format($variance, 2) . " KG (" . number_format($variance_pct, 2) . "%)" : ""),
                        [
                            'record_type' => 'purchase_grn',
                            'record_id' => $grn_id,
                            'reference_number' => $grn_number,
                            'po_id' => $selected_po->id,
                            'po_number' => $selected_po->po_number,
                            'supplier_name' => $selected_po->supplier_name,
                            'grn_date' => $_POST['grn_date'],
                            'truck_number' => $_POST['truck_number'] ?? null,
                            'expected_quantity' => $_POST['expected_quantity'] ?? 0,
                            'quantity_received_kg' => $_POST['quantity_received_kg'],
                            'variance' => $variance,
                            'variance_percentage' => $variance_pct,
                            'unit_price' => $selected_po->unit_price_per_kg,
                            'expected_value' => floatval($_POST['expected_quantity'] ?? 0) * floatval($selected_po->unit_price_per_kg),
                            'received_value' => floatval($_POST['quantity_received_kg']) * floatval($selected_po->unit_price_per_kg),
                            'created_by' => $user_name,
                            'severity' => abs($variance) > 100 ? 'warning' : 'info'
                        ]
                    );
                }
            } catch (Exception $e) {
                error_log("✗ Audit log error: " . $e->getMessage());
            }
            
            // ============================================
            // TELEGRAM NOTIFICATION - GRN RECORDED
            // ============================================
            try {
                if (defined('TELEGRAM_NOTIFICATIONS_ENABLED') && TELEGRAM_NOTIFICATIONS_ENABLED) {
                    require_once '../core/classes/TelegramNotifier.php';
                    $telegram = new TelegramNotifier(TELEGRAM_BOT_TOKEN, TELEGRAM_CHAT_ID);
                    
                    $db = Database::getInstance();
                    
                    // Get complete GRN details
                    $grn = $db->query(
                        "SELECT grn.*, 
                                po.wheat_origin,
                                b.name as branch_name
                         FROM goods_received_adnan grn
                         LEFT JOIN purchase_orders_adnan po ON grn.purchase_order_id = po.id
                         LEFT JOIN branches b ON grn.unload_point_branch_id = b.id
                         WHERE grn.id = ?",
                        [$grn_id]
                    )->first();
                    
                    if ($grn) {
                        // Get current user info
                        $currentUser = getCurrentUser();
                        $user_name = $currentUser['display_name'] ?? 'System User';
                        
                        // Get PO details for progress calculation
                        $po = $db->query(
                            "SELECT quantity_kg, total_received_qty 
                             FROM purchase_orders_adnan 
                             WHERE id = ?",
                            [$grn->purchase_order_id]
                        )->first();
                        
                        $po_quantity = $po ? floatval($po->quantity_kg) : 0;
                        $total_received = $po ? floatval($po->total_received_qty) : 0;
                        $completion_percentage = $po_quantity > 0 ? ($total_received / $po_quantity) * 100 : 0;
                        
                        // Prepare GRN data
                        $grnData = [
                            'grn_number' => $grn->grn_number,
                            'grn_date' => date('d M Y', strtotime($grn->grn_date)),
                            'po_number' => $grn->po_number,
                            'supplier_name' => $grn->supplier_name,
                            'wheat_origin' => $grn->wheat_origin,
                            'truck_number' => $grn->truck_number ?: 'N/A',
                            'quantity_received_kg' => floatval($grn->quantity_received_kg),
                            'unit_price_per_kg' => floatval($grn->unit_price_per_kg),
                            'received_value' => floatval($grn->total_value),
                            'unload_point' => $grn->branch_name ?: $grn->unload_point_name,
                            'po_quantity' => $po_quantity,
                            'total_received' => $total_received,
                            'pending' => $po_quantity - $total_received,
                            'completion_percentage' => $completion_percentage,
                            'remarks' => $grn->remarks ?: '',
                            'recorded_by' => $user_name
                        ];
                        
                        // Send notification
                        $notif_result = $telegram->sendGRNNotification($grnData);
                        
                        if ($notif_result['success']) {
                            error_log("✓ Telegram GRN notification sent: " . $grn->grn_number);
                        } else {
                            error_log("✗ Telegram GRN notification failed: " . json_encode($notif_result['response']));
                        }
                    } else {
                        error_log("✗ Telegram GRN notification: GRN not found with ID: " . $grn_id);
                    }
                }
            } catch (Exception $e) {
                error_log("✗ Telegram GRN notification error: " . $e->getMessage());
            }
            // END TELEGRAM NOTIFICATION
            
            $_SESSION['success'] = "Goods received recorded successfully! GRN ID: " . $grn_id;
            redirect('purchase/purchase_adnan_view_po.php?id=' . $data['purchase_order_id']);
        } else {
            throw new Exception("Failed to record goods received");
        }
        
    } catch (Exception $e) {
        $_SESSION['error'] = $e->getMessage();
    }
}

require_once '../templates/header.php';
?>

<!-- Rest of your HTML remains exactly the same -->


<div class="w-full px-4 py-6">
    <!-- Header -->
    <div class="flex justify-between items-center mb-6">
        <div>
            <h2 class="text-2xl font-bold text-gray-900 flex items-center gap-2">
                <i class="fas fa-truck-loading text-green-600"></i> Record Goods Received
            </h2>
            <nav class="text-sm text-gray-600 mt-1">
                <a href="<?php echo url('purchase/purchase_adnan_index.php'); ?>" class="hover:text-primary-600">Purchase (Adnan)</a>
                <span class="mx-2">›</span>
                <span>Record GRN</span>
            </nav>
        </div>
        <a href="<?php echo url('purchase/purchase_adnan_index.php'); ?>" class="border border-gray-300 text-gray-700 px-4 py-2 rounded-lg hover:bg-gray-50 flex items-center gap-2">
            <i class="fas fa-arrow-left"></i> Back
        </a>
    </div>

    <?php if (isset($_SESSION['error'])): ?>
        <div class="bg-red-50 border border-red-200 text-red-800 px-4 py-3 rounded-lg mb-6 flex items-center justify-between">
            <span><?php echo $_SESSION['error']; unset($_SESSION['error']); ?></span>
            <button onclick="this.parentElement.remove()" class="text-red-600 hover:text-red-800">
                <i class="fas fa-times"></i>
            </button>
        </div>
    <?php endif; ?>
    <?php if (isset($_SESSION['info'])): ?>
        <div class="bg-blue-50 border border-blue-200 text-blue-800 px-4 py-3 rounded-lg mb-6 flex items-center justify-between">
            <span><?php echo $_SESSION['info']; unset($_SESSION['info']); ?></span>
            <button onclick="this.parentElement.remove()" class="text-blue-600 hover:text-blue-800">
                <i class="fas fa-times"></i>
            </button>
        </div>
    <?php endif; ?>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="lg:col-span-2">
            <div class="bg-white rounded-lg shadow">
                <div class="bg-green-600 text-white px-6 py-4 rounded-t-lg">
                    <h5 class="font-semibold">GRN Details</h5>
                </div>
                <div class="p-6">
                    <form method="POST" id="grnForm">
                        <!-- Purchase Order Selection -->
                        <div class="mb-4">
                            <label class="block text-sm font-medium text-gray-700 mb-2">
                                Purchase Order <span class="text-red-500">*</span>
                            </label>
                            <select name="purchase_order_id" id="purchase_order_id" 
                                    class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-primary-500" 
                                    required>
                                <option value="">-- Select Purchase Order --</option>
                                <?php foreach ($open_pos as $po): ?>
                                <option value="<?php echo $po->id; ?>" 
                                        data-supplier="<?php echo htmlspecialchars($po->supplier_name); ?>"
                                        data-origin="<?php echo htmlspecialchars($po->wheat_origin); ?>"
                                        data-ordered="<?php echo $po->quantity_kg; ?>"
                                        data-received="<?php echo $po->total_received_qty; ?>"
                                        data-pending="<?php echo $po->qty_yet_to_receive; ?>"
                                        data-unit-price="<?php echo $po->unit_price_per_kg; ?>"
                                        data-commodity-id="<?php echo (int)($po->commodity_id ?? 0); ?>"
                                        <?php echo $selected_po && $selected_po->id == $po->id ? 'selected' : ''; ?>>
                                    PO #<?php echo $po->po_number; ?> - <?php echo htmlspecialchars($po->supplier_name); ?> - 
                                    <?php echo htmlspecialchars($po->wheat_origin); ?> 
                                    (Pending: <?php echo number_format($po->qty_yet_to_receive, 2); ?> KG)
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- PO Summary (shown when PO selected) -->
                        <div id="poSummary" class="mb-4 hidden">
                            <div class="bg-blue-50 border border-blue-200 rounded-lg p-4">
                                <div class="grid grid-cols-2 gap-4">
                                    <div class="space-y-1">
                                        <div><strong>Supplier:</strong> <span id="poSupplier"></span></div>
                                        <div><strong>Origin:</strong> <span id="poOrigin"></span></div>
                                        <div><strong>Unit Price:</strong> ৳<span id="poUnitPrice"></span>/KG</div>
                                    </div>
                                    <div class="space-y-1">
                                        <div><strong>Ordered:</strong> <span id="poOrdered"></span> KG</div>
                                        <div><strong>Already Received:</strong> <span id="poReceived"></span> KG</div>
                                        <div><strong>Yet to Receive:</strong> <span id="poPending"></span> KG</div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                            <!-- GRN Date -->
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">
                                    GRN Date <span class="text-red-500">*</span>
                                </label>
                                <input type="date" name="grn_date" 
                                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-primary-500"
                                       value="<?php echo date('Y-m-d'); ?>" required max="<?php echo date('Y-m-d'); ?>">
                            </div>

                            <!-- Truck Number -->
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">
                                    Truck Number
                                </label>
                                <input type="text" name="truck_number" 
                                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-primary-500"
                                       placeholder="e.g., 1234" maxlength="20">
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                            <!-- Quantity Received -->
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">
                                    Quantity Received (KG) <span class="text-red-500">*</span>
                                </label>
                                <input type="number" name="quantity_received_kg" id="quantity_received_kg" 
                                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-primary-500"
                                       step="0.01" min="0.01" required>
                            </div>

                            <!-- Expected Quantity -->
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">
                                    Expected Quantity (KG)
                                </label>
                                <input type="number" name="expected_quantity" id="expected_quantity" 
                                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-primary-500"
                                       step="0.01" min="0">
                                <small class="text-gray-500">Optional: For weight variance tracking</small>
                            </div>
                        </div>

                        <!-- Variance Display -->
                        <div id="varianceDisplay" class="mb-4 hidden">
                            <div class="bg-yellow-50 border border-yellow-200 text-yellow-800 px-4 py-3 rounded-lg">
                                <strong>Weight Variance:</strong> 
                                <span id="varianceAmount"></span> KG 
                                (<span id="variancePercent"></span>%)
                            </div>
                        </div>

                        <!-- Unload Location -->
                        <div class="mb-4">
                            <label class="block text-sm font-medium text-gray-700 mb-2">
                                Unload Location <span class="text-red-500">*</span>
                            </label>
                            <select name="unload_point_name" 
                                    class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-primary-500" 
                                    required>
                                <option value="">-- Select Location --</option>
                                <option value="সিরাজগঞ্জ">সিরাজগঞ্জ (Sirajganj)</option>
                                <option value="ডেমরা">ডেমরা (Demra)</option>
                                <option value="রামপুরা">রামপুরা (Rampura)</option>
                                <option value="Head Office">Head Office</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>

                        <!-- Unload Point (Branch) — feeds commodity-trading costing -->
                        <div class="mb-4" id="commodityBranchField">
                            <label class="block text-sm font-medium text-gray-700 mb-2">
                                Unload Point (Branch)
                                <span id="commodityBranchRequired" class="hidden text-red-500">*</span>
                            </label>
                            <select name="unload_point_branch_id" id="unload_point_branch_id"
                                    class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-primary-500">
                                <option value="">-- Select Branch --</option>
                                <?php foreach ($branches as $branch): ?>
                                <option value="<?php echo (int)$branch->id; ?>"><?php echo htmlspecialchars($branch->name); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div id="commodityBranchWarning" class="hidden mt-2 bg-amber-50 border border-amber-200 text-amber-800 px-3 py-2 rounded-lg text-sm">
                                <i class="fas fa-triangle-exclamation mr-1"></i>This PO is tagged for commodity trading. Select the branch that received the stock so its weighted-average cost updates — otherwise the inventory/costing update is silently skipped.
                            </div>
                        </div>

                        <!-- Remarks -->
                        <div class="mb-4">
                            <label class="block text-sm font-medium text-gray-700 mb-2">
                                Remarks
                            </label>
                            <textarea name="remarks" rows="3"
                                      class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-primary-500"
                                      placeholder="Any notes about this delivery..."></textarea>
                        </div>

                        <!-- Calculated Value Display -->
                        <div class="mb-6">
                            <div class="bg-gray-50 border border-gray-200 rounded-lg p-4">
                                <h5 class="text-sm font-medium text-gray-700 mb-1">Calculated Value:</h5>
                                <h3 class="text-3xl font-bold text-primary-600">৳<span id="calculatedValue">0.00</span></h3>
                                <small class="text-gray-500">Quantity × Unit Price</small>
                            </div>
                        </div>

                        <!-- Over-delivery hidden fields (set by JS modal) -->
                        <input type="hidden" name="over_delivery_action" id="over_delivery_action" value="as_is">
                        <input type="hidden" name="excess_qty" id="excess_qty" value="0">

                        <!-- Over-delivery Warning (shown by JS, not a modal) -->
                        <div id="overDeliveryWarning" class="hidden mb-4 p-4 bg-orange-50 border-2 border-orange-300 rounded-lg">
                            <div class="flex items-start gap-3">
                                <i class="fas fa-exclamation-triangle text-orange-500 text-xl mt-0.5"></i>
                                <div class="flex-1">
                                    <p class="font-semibold text-orange-800">Over-Delivery Detected</p>
                                    <p class="text-sm text-orange-700 mt-1">
                                        Remaining on PO: <strong id="warnPending">0</strong> KG |
                                        You're recording: <strong id="warnReceiving">0</strong> KG |
                                        Excess: <strong id="warnExcess" class="text-red-700">0</strong> KG
                                    </p>
                                    <p class="text-sm text-orange-700 mt-1">How would you like to handle the excess?</p>
                                    <div class="mt-3 flex flex-wrap gap-2">
                                        <button type="button" id="btnAcceptWithDAN"
                                                class="bg-orange-600 text-white text-sm px-4 py-2 rounded hover:bg-orange-700 flex items-center gap-1">
                                            <i class="fas fa-file-invoice-dollar"></i>
                                            Accept All + Auto-Draft Debit Note (DAN)
                                        </button>
                                        <button type="button" id="btnAcceptAsIs"
                                                class="bg-gray-600 text-white text-sm px-4 py-2 rounded hover:bg-gray-700 flex items-center gap-1">
                                            <i class="fas fa-check"></i>
                                            Record As-Is (handle manually later)
                                        </button>
                                    </div>
                                    <p id="overDeliveryChoice" class="text-xs text-green-700 mt-2 font-semibold hidden"></p>
                                </div>
                            </div>
                        </div>

                        <!-- Submit Buttons -->
                        <div class="flex justify-between">
                            <a href="<?php echo url('purchase/purchase_adnan_index.php'); ?>"
                               class="border border-gray-300 text-gray-700 px-6 py-2 rounded-lg hover:bg-gray-50">
                                Cancel
                            </a>
                            <button type="submit"
                                    class="bg-green-600 text-white px-6 py-2 rounded-lg hover:bg-green-700 flex items-center gap-2">
                                <i class="fas fa-save"></i> Record GRN
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div>
            <!-- Help Card -->
            <div class="bg-white rounded-lg shadow">
                <div class="bg-blue-600 text-white px-6 py-4 rounded-t-lg">
                    <h5 class="font-semibold flex items-center gap-2">
                        <i class="fas fa-info-circle"></i> Instructions
                    </h5>
                </div>
                <div class="p-6">
                    <h6 class="font-semibold mb-2">Recording Goods Receipt:</h6>
                    <ol class="list-decimal list-inside space-y-1 text-sm text-gray-700">
                        <li>Select the purchase order</li>
                        <li>Enter the date goods were received</li>
                        <li>Enter truck number (optional)</li>
                        <li>Enter actual quantity received</li>
                        <li>Enter expected quantity (for variance tracking)</li>
                        <li>Select unload location</li>
                        <li>Add any remarks if needed</li>
                        <li>Review calculated value</li>
                        <li>Click "Record GRN"</li>
                    </ol>

                    <hr class="my-4">

                    <h6 class="font-semibold mb-2">Weight Variance:</h6>
                    <p class="text-sm text-gray-700">
                        If actual weight differs from expected by more than 0.5%, 
                        a variance record will be automatically created for analysis.
                    </p>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const poSelect             = document.getElementById('purchase_order_id');
    const qtyReceived          = document.getElementById('quantity_received_kg');
    const qtyExpected          = document.getElementById('expected_quantity');
    const poSummary            = document.getElementById('poSummary');
    const varianceDisplay      = document.getElementById('varianceDisplay');
    const calculatedValue      = document.getElementById('calculatedValue');
    const overDeliveryWarning  = document.getElementById('overDeliveryWarning');
    const overDeliveryChoice   = document.getElementById('overDeliveryChoice');
    const overDeliveryAction   = document.getElementById('over_delivery_action');
    const excessQtyInput       = document.getElementById('excess_qty');
    const branchSelect         = document.getElementById('unload_point_branch_id');
    const commodityBranchWarning  = document.getElementById('commodityBranchWarning');
    const commodityBranchRequired = document.getElementById('commodityBranchRequired');

    // ─── Commodity-tagged PO check: nudge (not block) for the branch field ──
    function checkCommodityBranch() {
        const option = poSelect.options[poSelect.selectedIndex];
        const isCommodity = !!(option && parseInt(option.dataset.commodityId || '0') > 0);
        const showWarning = isCommodity && !branchSelect.value;
        commodityBranchWarning.classList.toggle('hidden', !showWarning);
        commodityBranchRequired.classList.toggle('hidden', !isCommodity);
    }
    branchSelect.addEventListener('change', checkCommodityBranch);

    // ─── PO change: populate summary ─────────────────────────────
    poSelect.addEventListener('change', function() {
        const option = this.options[this.selectedIndex];
        if (this.value) {
            document.getElementById('poSupplier').textContent  = option.dataset.supplier;
            document.getElementById('poOrigin').textContent    = option.dataset.origin;
            document.getElementById('poOrdered').textContent   = parseFloat(option.dataset.ordered  || 0).toFixed(2);
            document.getElementById('poReceived').textContent  = parseFloat(option.dataset.received || 0).toFixed(2);
            // pending = ordered − already received (fallback if data-pending is missing)
            const pending = parseFloat(option.dataset.pending) ||
                            (parseFloat(option.dataset.ordered || 0) - parseFloat(option.dataset.received || 0));
            document.getElementById('poPending').textContent   = pending.toFixed(2);
            document.getElementById('poUnitPrice').textContent = parseFloat(option.dataset.unitPrice || 0).toFixed(2);
            poSummary.classList.remove('hidden');
        } else {
            poSummary.classList.add('hidden');
        }
        resetOverDelivery();
        calculateValue();
        checkCommodityBranch();
    });
    checkCommodityBranch();

    // ─── Reset over-delivery state ────────────────────────────────
    function resetOverDelivery() {
        overDeliveryWarning.classList.add('hidden');
        overDeliveryChoice.classList.add('hidden');
        overDeliveryAction.value = 'as_is';
        excessQtyInput.value     = '0';
    }

    // ─── Check for over-delivery when qty changes ─────────────────
    function checkOverDelivery() {
        if (!poSelect.value) { resetOverDelivery(); return; }

        const option   = poSelect.options[poSelect.selectedIndex];
        const ordered  = parseFloat(option.dataset.ordered  || 0);
        const received = parseFloat(option.dataset.received || 0);
        const pending  = parseFloat(option.dataset.pending) || (ordered - received);
        const nowQty   = parseFloat(qtyReceived.value) || 0;

        if (nowQty > 0 && pending >= 0 && nowQty > pending + 0.01) {
            const excess = nowQty - pending;
            document.getElementById('warnPending').textContent   = pending.toFixed(2);
            document.getElementById('warnReceiving').textContent = nowQty.toFixed(2);
            document.getElementById('warnExcess').textContent    = excess.toFixed(2);
            excessQtyInput.value = excess.toFixed(4);
            overDeliveryWarning.classList.remove('hidden');
        } else {
            resetOverDelivery();
        }
    }

    // ─── Over-delivery choice buttons ────────────────────────────
    document.getElementById('btnAcceptWithDAN').addEventListener('click', function() {
        overDeliveryAction.value = 'accept_with_dan';
        overDeliveryChoice.textContent = '✔ Choice: Accept all goods + Auto-draft Debit Note (DAN) for excess. Click "Record GRN" to confirm.';
        overDeliveryChoice.classList.remove('hidden');
    });

    document.getElementById('btnAcceptAsIs').addEventListener('click', function() {
        overDeliveryAction.value = 'as_is';
        overDeliveryChoice.textContent = '✔ Choice: Record as-is. You can create an adjustment note manually later.';
        overDeliveryChoice.classList.remove('hidden');
    });

    // ─── Variance display ────────────────────────────────────────
    function calculateVariance() {
        const rcvd     = parseFloat(qtyReceived.value) || 0;
        const expected = parseFloat(qtyExpected.value) || 0;
        if (expected > 0 && rcvd > 0) {
            const variance        = rcvd - expected;
            const variancePercent = (variance / expected * 100).toFixed(2);
            document.getElementById('varianceAmount').textContent  = variance.toFixed(2);
            document.getElementById('variancePercent').textContent = variancePercent;
            varianceDisplay.classList.remove('hidden');
            const alertDiv = varianceDisplay.querySelector('div');
            if (Math.abs(variancePercent) > 1) {
                alertDiv.className = 'bg-red-50 border border-red-200 text-red-800 px-4 py-3 rounded-lg';
            } else if (Math.abs(variancePercent) > 0.5) {
                alertDiv.className = 'bg-yellow-50 border border-yellow-200 text-yellow-800 px-4 py-3 rounded-lg';
            } else {
                alertDiv.className = 'bg-green-50 border border-green-200 text-green-800 px-4 py-3 rounded-lg';
            }
        } else {
            varianceDisplay.classList.add('hidden');
        }
    }

    // ─── Calculated value display (based on expected qty) ────────
    function calculateValue() {
        const option    = poSelect.options[poSelect.selectedIndex];
        const unitPrice = parseFloat(option.dataset.unitPrice || 0);
        const expected  = parseFloat(qtyExpected.value) || 0;
        calculatedValue.textContent = (unitPrice * expected).toFixed(2);
    }

    qtyReceived.addEventListener('input', function() {
        checkOverDelivery();
        calculateVariance();
        calculateValue();
    });

    qtyExpected.addEventListener('input', function() {
        calculateVariance();
        calculateValue();
    });

    // Trigger on page load if PO already selected
    if (poSelect.value) {
        poSelect.dispatchEvent(new Event('change'));
    }

    // ─── Non-blocking nudge at submit time (warn, don't block) ──────────
    document.getElementById('grnForm').addEventListener('submit', function(e) {
        const option = poSelect.options[poSelect.selectedIndex];
        const isCommodity = !!(option && parseInt(option.dataset.commodityId || '0') > 0);
        if (isCommodity && !branchSelect.value) {
            const proceed = confirm('This PO is tagged for commodity trading but no Unload Point (Branch) is selected — the weighted-average cost/inventory update will be silently skipped for this receipt. Continue anyway?');
            if (!proceed) { e.preventDefault(); branchSelect.focus(); }
        }
    });
});
</script>

<?php require_once '../templates/footer.php'; ?>