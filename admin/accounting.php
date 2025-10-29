<?php
require_once __DIR__ . '/../core/init.php';

if (!is_logged_in()) {
    header('Location: ../auth/login.php');
    exit();
}

$accounting_handler = new Accounting($pdo);

$journal_entries = $accounting_handler->get_journal_entries();
$chart_of_accounts = $accounting_handler->get_chart_of_accounts();

$page_title = 'Accounting';

include __DIR__ . '/../templates/header.php';
include __DIR__ . '/../templates/sidebar.php';
?>

<h1 class="mt-4">Accounting</h1>

<div class="row">
    <div class="col-md-8">
        <div class="card mb-4">
            <div class="card-header">Journal Entries</div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-bordered">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Account</th>
                                <th>Debit</th>
                                <th>Credit</th>
                                <th>Description</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($journal_entries as $entry): ?>
                                <tr>
                                    <td><?php echo format_date($entry['entry_date'], 'M d, Y'); ?></td>
                                    <td><?php echo htmlspecialchars($entry['account_name']); ?></td>
                                    <td><?php echo htmlspecialchars($entry['debit']); ?></td>
                                    <td><?php echo htmlspecialchars($entry['credit']); ?></td>
                                    <td><?php echo htmlspecialchars($entry['description']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card mb-4">
            <div class="card-header">Chart of Accounts</div>
            <div class="card-body">
                <ul class="list-group">
                    <?php foreach ($chart_of_accounts as $account): ?>
                        <li class="list-group-item d-flex justify-content-between align-items-center">
                            <?php echo htmlspecialchars($account['account_name']); ?>
                            <span class="badge bg-info rounded-pill"><?php echo ucfirst($account['account_type']); ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    </div>
</div>

<?php
include __DIR__ . '/../templates/footer.php';
?>
