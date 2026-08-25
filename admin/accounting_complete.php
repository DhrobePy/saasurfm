<?php
require_once __DIR__ . '/../core/init.php';

if (!is_logged_in()) {
    header('Location: ../auth/login.php');
    exit();
}

$accounting_handler = new Accounting($pdo);

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_entry'])) {
        // Prepare data for the handler based on the debit/credit structure
        $amount = (float)$_POST['amount'];
        $is_debit = $_POST['transaction_type'] === 'debit';
        $reference_id = $_POST['reference_id'] ?: null;
        
        // IMPORTANT: The add_entry method must now handle looking up the account_id 
        // in the 'chart_of_accounts' table using 'account_name' and 'account_type',
        // and then inserting the amount into the correct debit/credit column.
        $data = [
            'account_name' => sanitize_input($_POST['account_name']), // Used to look up ID
            'account_type' => $_POST['account_type'], // Used to look up ID
            'debit' => $is_debit ? $amount : 0.00,
            'credit' => $is_debit ? 0.00 : $amount,
            'description' => sanitize_input($_POST['description']),
            // Your table structure suggests 'payroll_id' is the only reference ID field.
            'payroll_id' => ($_POST['reference_type'] === 'payroll') ? $reference_id : null,
            'reference_id' => $reference_id, // Pass for generic use if handler needs it
            'reference_type' => $_POST['reference_type'] ?: null // Optional: For the handler logic
        ];
        
        $accounting_handler->add_entry($data);
        header('Location: accounting_complete.php?success=1');
        exit();
    }
}

// Get filter parameters
$account_filter = $_GET['account'] ?? '';
$type_filter = $_GET['type'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';

// === 1. Calculate Summary (Corrected) ===
// SUM the debit and credit columns directly
$stmt = $pdo->query("
    SELECT 
        SUM(debit) as total_debits,
        SUM(credit) as total_credits
    FROM journal_entries
    WHERE DATE(entry_date) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
");
$summary = $stmt->fetch(PDO::FETCH_ASSOC);

// === 2. Get Account Balances (Corrected with JOIN on chart_of_accounts) ===
$stmt = $pdo->query("
    SELECT c.account_name, c.account_type,
           SUM(j.debit - j.credit) as balance
    FROM journal_entries j
    JOIN chart_of_accounts c ON j.account_id = c.id
    GROUP BY c.account_name, c.account_type
    HAVING balance != 0
    ORDER BY c.account_name
");
$account_balances = $stmt->fetchAll(PDO::FETCH_ASSOC);

// === 3. Build Query for Journal Entries (Corrected with JOIN on chart_of_accounts) ===
$query = "
    SELECT j.*, c.account_name, c.account_type
    FROM journal_entries j
    JOIN chart_of_accounts c ON j.account_id = c.id
    WHERE 1=1
";
$params = [];

if ($account_filter) {
    // Filter by account name from the chart_of_accounts table
    $query .= " AND c.account_name LIKE ?";
    $params[] = "%$account_filter%";
}

if ($type_filter === 'debit') {
    $query .= " AND j.debit > 0";
} elseif ($type_filter === 'credit') {
    $query .= " AND j.credit > 0";
}

if ($date_from) {
    $query .= " AND DATE(j.entry_date) >= ?";
    $params[] = $date_from;
}

if ($date_to) {
    $query .= " AND DATE(j.entry_date) <= ?";
    $params[] = $date_to;
}

$query .= " ORDER BY j.entry_date DESC LIMIT 100";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$entries = $stmt->fetchAll(PDO::FETCH_ASSOC);

$page_title = 'Accounting';

include __DIR__ . '/../templates/header.php';
include __DIR__ . '/../templates/sidebar.php';
?>

<h1 class="mt-4">Accounting Management</h1>

<?php if (isset($_GET['success'])): ?>
    <div class="alert alert-success">Journal entry added successfully!</div>
<?php endif; ?>

<div class="row mb-4">
    <div class="col-md-3">
        <div class="card bg-success text-white">
            <div class="card-body">
                <h5>Total Credits (30 days)</h5>
                <h2>$<?php echo number_format($summary['total_credits'] ?? 0, 2); ?></h2>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card bg-danger text-white">
            <div class="card-body">
                <h5>Total Debits (30 days)</h5>
                <h2>$<?php echo number_format($summary['total_debits'] ?? 0, 2); ?></h2>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card bg-info text-white">
            <div class="card-body">
                <h5>Net Position</h5>
                <h2>$<?php echo number_format(($summary['total_credits'] ?? 0) - ($summary['total_debits'] ?? 0), 2); ?></h2>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card bg-warning text-white">
            <div class="card-body">
                <h5>Active Accounts</h5>
                <h2><?php echo count($account_balances); ?></h2>
            </div>
        </div>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header">Add Journal Entry</div>
    <div class="card-body">
        <form action="accounting_complete.php" method="POST" class="row g-3">
            <div class="col-md-3">
                <label for="account_name" class="form-label">Account Name</label>
                <input type="text" class="form-control" name="account_name" id="account_name" required 
                       placeholder="e.g., Salary Expense, Cash, Bank">
            </div>
            <div class="col-md-2">
                <label for="account_type" class="form-label">Account Type</label>
                <select class="form-select" name="account_type" id="account_type" required>
                    <option value="">Select Type</option>
                    <option value="asset">Asset</option>
                    <option value="liability">Liability</option>
                    <option value="equity">Equity</option>
                    <option value="revenue">Revenue</option>
                    <option value="expense">Expense</option>
                </select>
            </div>
            <div class="col-md-2">
                <label for="transaction_type" class="form-label">Transaction</label>
                <select class="form-select" name="transaction_type" id="transaction_type" required>
                    <option value="">Select</option>
                    <option value="debit">Debit</option>
                    <option value="credit">Credit</option>
                </select>
            </div>
            <div class="col-md-2">
                <label for="amount" class="form-label">Amount</label>
                <input type="number" step="0.01" class="form-control" name="amount" id="amount" required>
            </div>
            <div class="col-md-3">
                <label for="description" class="form-label">Description</label>
                <input type="text" class="form-control" name="description" id="description" required>
            </div>
            <div class="col-md-6">
                <label for="reference_type" class="form-label">Reference Type (Optional)</label>
                <select class="form-select" name="reference_type" id="reference_type">
                    <option value="">No Reference</option>
                    <option value="payroll">Payroll</option>
                    <option value="employee">Employee</option>
                    <option value="loan">Loan</option>
                    <option value="advance">Salary Advance</option>
                </select>
            </div>
            <div class="col-md-6">
                <label for="reference_id" class="form-label">Reference ID (Optional)</label>
                <input type="number" class="form-control" name="reference_id" id="reference_id" 
                       placeholder="Related record ID">
            </div>
            <div class="col-md-12">
                <button type="submit" name="add_entry" class="btn btn-success">Add Entry</button>
            </div>
        </form>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header">Filter Journal Entries</div>
    <div class="card-body">
        <form action="accounting_complete.php" method="GET" class="row g-3 align-items-end">
            <div class="col-md-3">
                <label for="account" class="form-label">Account Name</label>
                <input type="text" class="form-control" name="account" id="account" 
                       value="<?php echo htmlspecialchars($account_filter); ?>" placeholder="Search account">
            </div>
            <div class="col-md-2">
                <label for="type" class="form-label">Transaction Type</label>
                <select class="form-select" name="type" id="type">
                    <option value="">All Types</option>
                    <option value="debit" <?php echo $type_filter === 'debit' ? 'selected' : ''; ?>>Debit</option>
                    <option value="credit" <?php echo $type_filter === 'credit' ? 'selected' : ''; ?>>Credit</option>
                </select>
            </div>
            <div class="col-md-2">
                <label for="date_from" class="form-label">From Date</label>
                <input type="date" class="form-control" name="date_from" id="date_from" 
                       value="<?php echo htmlspecialchars($date_from); ?>">
            </div>
            <div class="col-md-2">
                <label for="date_to" class="form-label">To Date</label>
                <input type="date" class="form-control" name="date_to" id="date_to" 
                       value="<?php echo htmlspecialchars($date_to); ?>">
            </div>
            <div class="col-md-3">
                <button type="submit" class="btn btn-primary">Filter</button>
                <a href="accounting_complete.php" class="btn btn-secondary">Clear</a>
            </div>
        </form>
    </div>
</div>

<div class="row">
    <div class="col-md-8">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span>Journal Entries</span>
                <span class="badge bg-info"><?php echo count($entries); ?> entries</span>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover table-sm">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Account</th>
                                <th>Type</th>
                                <th>Debit</th>
                                <th>Credit</th>
                                <th>Description</th>
                                <th>Reference</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($entries as $entry): 
                                // Determine transaction type and amount for display
                                $is_debit_entry = $entry['debit'] > 0;
                                $transaction_type = $is_debit_entry ? 'debit' : 'credit';
                                $amount_display = $is_debit_entry ? $entry['debit'] : $entry['credit'];
                            ?>
                                <tr>
                                    <td><?php echo date('M d, Y', strtotime($entry['entry_date'])); ?></td>
                                    <td><strong><?php echo htmlspecialchars($entry['account_name']); ?></strong><br>
                                        <small class="text-muted"><?php echo ucfirst($entry['account_type']); ?></small>
                                    </td>
                                    <td>
                                        <span class="badge bg-<?php echo $transaction_type === 'debit' ? 'danger' : 'success'; ?>">
                                            <?php echo ucfirst($transaction_type); ?>
                                        </span>
                                    </td>
                                    <td><?php echo $is_debit_entry ? '$' . number_format($amount_display, 2) : '-'; ?></td>
                                    <td><?php echo !$is_debit_entry ? '$' . number_format($amount_display, 2) : '-'; ?></td>
                                    <td><?php echo htmlspecialchars($entry['description']); ?></td>
                                    <td>
                                        <?php if ($entry['payroll_id']): // Assuming payroll_id is the primary reference ID ?>
                                            <small>Payroll #<?php echo $entry['payroll_id']; ?></small>
                                        <?php else: ?>
                                            <small class="text-muted">Manual</small>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <?php if (empty($entries)): ?>
                    <div class="text-center py-4">
                        <h5 class="text-muted">No journal entries found</h5>
                        <p>Add your first journal entry using the form above.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <div class="col-md-4">
        <div class="card">
            <div class="card-header">Account Balances</div>
            <div class="card-body">
                <?php if (empty($account_balances)): ?>
                    <p class="text-muted">No account balances to display.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>Account</th>
                                    <th>Balance</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($account_balances as $account): ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo htmlspecialchars($account['account_name']); ?></strong><br>
                                            <small class="text-muted"><?php echo ucfirst($account['account_type']); ?></small>
                                        </td>
                                        <td class="<?php echo $account['balance'] >= 0 ? 'text-success' : 'text-danger'; ?>">
                                            $<?php echo number_format(abs($account['balance']), 2); ?>
                                            <?php echo $account['balance'] < 0 ? ' (CR)' : ' (DR)'; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php
include __DIR__ . '/../templates/footer.php';
?>