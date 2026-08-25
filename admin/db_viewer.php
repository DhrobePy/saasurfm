<?php
/**
 * Database Viewer — Admin / Superadmin only
 * Full CRUD: browse, insert, edit, delete (single + bulk).
 *
 * Security guarantees:
 *   • Role-restricted: Superadmin and admin only.
 *   • All POST actions require a valid CSRF token.
 *   • Table names validated against SHOW TABLES whitelist.
 *   • Column names come from DESCRIBE (server-trusted), never user input.
 *   • All values use prepared-statement placeholders.
 *   • FK checks disabled only during deletes.
 *   • Every mutating action is audit-logged.
 */

require_once '../core/init.php';
restrict_access(['Superadmin', 'admin']);

global $db;
$pdo       = $db->getPdo();
$pageTitle = 'DB Viewer';

// ── Table whitelist ───────────────────────────────────────────────────────────
$all_tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
sort($all_tables);

$table_counts = [];
foreach ($all_tables as $t) {
    try { $table_counts[$t] = (int)$pdo->query("SELECT COUNT(*) FROM `{$t}`")->fetchColumn(); }
    catch (Exception $e) { $table_counts[$t] = '?'; }
}

// ── Helpers ───────────────────────────────────────────────────────────────────
function valid_table(string $name, array $wl): ?string {
    return in_array($name, $wl, true) ? $name : null;
}

function get_pk(PDO $pdo, string $table): string {
    $row = $pdo->query("SHOW KEYS FROM `{$table}` WHERE Key_name = 'PRIMARY'")->fetch(PDO::FETCH_ASSOC);
    return $row['Column_name'] ?? 'id';
}

/** Parse enum/set option strings from MySQL type definition. */
function parse_enum_opts(string $type): array {
    preg_match_all("/'([^']*)'/", $type, $m);
    return $m[1];
}

/** Map a DESCRIBE column to a logical input type. */
function col_input_type(array $col): string {
    $t = strtolower($col['Type']);
    if (str_starts_with($t, 'enum'))    return 'enum';
    if (str_starts_with($t, 'set'))     return 'set';
    if (str_contains($t, 'text')
     || str_contains($t, 'json')
     || str_contains($t, 'blob'))       return 'textarea';
    if ($t === 'tinyint(1)')            return 'boolean';
    if (str_contains($t, 'int'))        return 'integer';
    if (str_contains($t, 'decimal')
     || str_contains($t, 'float')
     || str_contains($t, 'double'))     return 'decimal';
    if ($t === 'date')                  return 'date';
    if (str_contains($t, 'datetime')
     || str_contains($t, 'timestamp'))  return 'datetime';
    if (str_contains($t, 'time'))       return 'time';
    return 'text';
}

/** Build the value to write for one column from POST data. Returns null for NULL. */
function extract_post_value(string $field, string $input_type, bool $nullable): mixed {
    // Explicit NULL toggle
    if ($nullable && isset($_POST["_null_{$field}"])) return null;

    $val = $_POST["field_{$field}"] ?? null;
    if ($val === null || $val === '') return $nullable ? null : ($input_type === 'boolean' ? 0 : '');

    return match($input_type) {
        'integer' => (int)$val,
        'decimal' => (float)$val,
        'boolean' => (int)(bool)$val,
        default   => (string)$val,
    };
}

// ═════════════════════════════════════════════════════════════════════════════
// POST handler
// ═════════════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF
    $sess_tok = $_SESSION['csrf_token'] ?? '';
    $post_tok = $_POST['csrf_token']    ?? '';
    if (!$sess_tok || !$post_tok || !hash_equals($sess_tok, $post_tok)) {
        $_SESSION['error_flash'] = 'Invalid security token.';
        header('Location: ' . $_SERVER['REQUEST_URI']);
        exit();
    }

    $action = $_POST['action'] ?? '';
    $tbl    = valid_table($_POST['table'] ?? '', $all_tables);

    if (!$tbl) {
        $_SESSION['error_flash'] = 'Invalid table.';
        header('Location: db_viewer.php');
        exit();
    }

    $pk      = get_pk($pdo, $tbl);
    $columns = $pdo->query("DESCRIBE `{$tbl}`")->fetchAll(PDO::FETCH_ASSOC);

    // ── Delete single ─────────────────────────────────────────────────────────
    if ($action === 'delete_row') {
        $row_id = $_POST['row_id'] ?? null;
        try {
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
            $stmt = $pdo->prepare("DELETE FROM `{$tbl}` WHERE `{$pk}` = ?");
            $stmt->execute([$row_id]);
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
            auditLog('db_viewer', 'delete_row', "Deleted row {$pk}={$row_id} from {$tbl}", ['table'=>$tbl,'id'=>$row_id]);
            $_SESSION['success_flash'] = "Row deleted from `{$tbl}`.";
        } catch (Exception $e) {
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
            $_SESSION['error_flash'] = 'Delete failed: ' . $e->getMessage();
        }

    // ── Delete bulk ───────────────────────────────────────────────────────────
    } elseif ($action === 'delete_bulk') {
        $ids = array_values(array_map('strval', (array)($_POST['row_ids'] ?? [])));
        if (empty($ids)) { $_SESSION['error_flash'] = 'No rows selected.'; }
        else {
            try {
                $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
                $ph   = implode(',', array_fill(0, count($ids), '?'));
                $stmt = $pdo->prepare("DELETE FROM `{$tbl}` WHERE `{$pk}` IN ({$ph})");
                $stmt->execute($ids);
                $cnt  = $stmt->rowCount();
                $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
                auditLog('db_viewer', 'delete_bulk', "Deleted {$cnt} rows from {$tbl}", ['table'=>$tbl,'ids'=>$ids]);
                $_SESSION['success_flash'] = "Deleted {$cnt} row(s) from `{$tbl}`.";
            } catch (Exception $e) {
                $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
                $_SESSION['error_flash'] = 'Bulk delete failed: ' . $e->getMessage();
            }
        }

    // ── Insert row ────────────────────────────────────────────────────────────
    } elseif ($action === 'insert_row') {
        $fields = []; $values = [];
        foreach ($columns as $col) {
            if ($col['Extra'] === 'auto_increment') continue; // let DB assign PK
            $itype    = col_input_type($col);
            $nullable = ($col['Null'] === 'YES');
            $val      = extract_post_value($col['Field'], $itype, $nullable);
            $fields[] = "`{$col['Field']}`";
            $values[] = $val;
        }
        if (!empty($fields)) {
            try {
                $ph   = implode(',', array_fill(0, count($fields), '?'));
                $sql  = "INSERT INTO `{$tbl}` (" . implode(',', $fields) . ") VALUES ({$ph})";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($values);
                $new_id = $pdo->lastInsertId();
                auditLog('db_viewer', 'insert_row', "Inserted row into {$tbl} (new id={$new_id})", ['table'=>$tbl]);
                $_SESSION['success_flash'] = "New row inserted into `{$tbl}` (id = {$new_id}).";
            } catch (Exception $e) {
                $_SESSION['error_flash'] = 'Insert failed: ' . $e->getMessage();
            }
        }

    // ── Update row ────────────────────────────────────────────────────────────
    } elseif ($action === 'update_row') {
        $row_id  = $_POST['row_id'] ?? null;
        $sets = []; $values = [];
        foreach ($columns as $col) {
            if ($col['Field'] === $pk) continue; // never update PK
            if ($col['Extra'] === 'auto_increment') continue;
            $itype    = col_input_type($col);
            $nullable = ($col['Null'] === 'YES');
            $val      = extract_post_value($col['Field'], $itype, $nullable);
            $sets[]   = "`{$col['Field']}` = ?";
            $values[]  = $val;
        }
        $values[] = $row_id; // for WHERE clause
        if (!empty($sets)) {
            try {
                $sql  = "UPDATE `{$tbl}` SET " . implode(', ', $sets) . " WHERE `{$pk}` = ?";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($values);
                auditLog('db_viewer', 'update_row', "Updated row {$pk}={$row_id} in {$tbl}", ['table'=>$tbl,'id'=>$row_id]);
                $_SESSION['success_flash'] = "Row updated in `{$tbl}`.";
            } catch (Exception $e) {
                $_SESSION['error_flash'] = 'Update failed: ' . $e->getMessage();
            }
        }
    }

    // PRG redirect
    $pg = max(1, (int)($_POST['current_page'] ?? 1));
    $q  = urlencode($_POST['search_term'] ?? '');
    $redirect = "db_viewer.php?table=" . urlencode($tbl) . "&page={$pg}" . ($q ? "&q={$q}" : '');
    header("Location: {$redirect}");
    exit();
}

// ═════════════════════════════════════════════════════════════════════════════
// GET — render
// ═════════════════════════════════════════════════════════════════════════════
$active_table = valid_table($_GET['table'] ?? '', $all_tables);
$per_page     = 50;
$current_page = max(1, (int)($_GET['page'] ?? 1));
$search_term  = trim($_GET['q'] ?? '');

$columns    = [];
$rows       = [];
$total_rows = 0;
$total_pages = 1;
$pk_col     = 'id';
$col_meta   = []; // keyed by field name — for JS modal

if ($active_table) {
    $pk_col  = get_pk($pdo, $active_table);
    $columns = $pdo->query("DESCRIBE `{$active_table}`")->fetchAll(PDO::FETCH_ASSOC);

    // Build $col_meta for the JS form builder
    foreach ($columns as $col) {
        $itype = col_input_type($col);
        $col_meta[$col['Field']] = [
            'type'      => $itype,
            'nullable'  => $col['Null'] === 'YES',
            'default'   => $col['Default'],
            'extra'     => $col['Extra'],
            'raw_type'  => $col['Type'],
            'is_pk'     => $col['Field'] === $pk_col,
            'opts'      => in_array($itype, ['enum','set']) ? parse_enum_opts($col['Type']) : [],
        ];
    }

    // Search columns
    $text_cols = array_values(array_map(fn($c) => $c['Field'], array_filter($columns, function($c) {
        $t = strtolower($c['Type']);
        return str_contains($t,'char') || str_contains($t,'text') || str_contains($t,'enum') || str_contains($t,'set');
    })));

    $where_sql = ''; $where_params = [];
    if ($search_term !== '' && !empty($text_cols)) {
        $parts        = array_map(fn($c) => "`{$c}` LIKE ?", $text_cols);
        $where_sql    = ' WHERE ' . implode(' OR ', $parts);
        $where_params = array_fill(0, count($text_cols), '%' . $search_term . '%');
    }

    $total_rows  = (int)$pdo->prepare("SELECT COUNT(*) FROM `{$active_table}`{$where_sql}")->execute($where_params) ?
                   (int)$pdo->query("SELECT COUNT(*) FROM `{$active_table}`{$where_sql}")->fetchColumn() : 0;

    // Re-run count properly
    $cnt_stmt = $pdo->prepare("SELECT COUNT(*) FROM `{$active_table}`{$where_sql}");
    $cnt_stmt->execute($where_params);
    $total_rows  = (int)$cnt_stmt->fetchColumn();
    $total_pages = max(1, (int)ceil($total_rows / $per_page));
    $current_page = min($current_page, $total_pages);
    $offset       = ($current_page - 1) * $per_page;

    $data_stmt = $pdo->prepare("SELECT * FROM `{$active_table}`{$where_sql} ORDER BY `{$pk_col}` DESC LIMIT {$per_page} OFFSET {$offset}");
    $data_stmt->execute($where_params);
    $rows = $data_stmt->fetchAll(PDO::FETCH_ASSOC);
}

$flash_success = $_SESSION['success_flash'] ?? null;
$flash_error   = $_SESSION['error_flash']   ?? null;
unset($_SESSION['success_flash'], $_SESSION['error_flash']);

require_once '../templates/header.php';
?>

<div class="max-w-screen-2xl mx-auto px-4 sm:px-6 lg:px-8 py-6">

<!-- ══ PAGE HEADER ══════════════════════════════════════════════════════════ -->
<div class="flex items-center justify-between mb-6 gap-4">
    <div>
        <div class="flex items-center gap-3 mb-1">
            <h1 class="text-2xl font-bold text-gray-900">
                <i class="fas fa-database text-primary-600 mr-2"></i>Database Viewer
            </h1>
            <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-semibold
                         bg-red-100 text-red-700 border border-red-200">
                <i class="fas fa-lock text-[10px]"></i> Admin Only
            </span>
        </div>
        <p class="text-sm text-gray-500">
            Full CRUD on every table. Deletes bypass FK checks — use with care.
        </p>
    </div>
    <?php if ($active_table): ?>
    <a href="db_viewer.php"
       class="flex-shrink-0 flex items-center gap-2 px-4 py-2 border border-gray-300
              rounded-lg text-sm font-medium text-gray-700 hover:bg-gray-50 transition-colors">
        <i class="fas fa-th-large text-gray-400"></i> All Tables
    </a>
    <?php endif; ?>
</div>

<?php if ($flash_success): ?>
<div class="mb-4 flex items-center gap-3 px-4 py-3 bg-green-50 border border-green-300 text-green-800 text-sm rounded-lg">
    <i class="fas fa-check-circle text-green-500 flex-shrink-0"></i><?php echo htmlspecialchars($flash_success); ?>
</div>
<?php endif; ?>
<?php if ($flash_error): ?>
<div class="mb-4 flex items-center gap-3 px-4 py-3 bg-red-50 border border-red-300 text-red-800 text-sm rounded-lg">
    <i class="fas fa-exclamation-circle text-red-500 flex-shrink-0"></i><?php echo htmlspecialchars($flash_error); ?>
</div>
<?php endif; ?>

<?php if (!$active_table): ?>
<!-- ══ LANDING — all tables ══════════════════════════════════════════════════ -->
<p class="text-sm text-gray-500 mb-4">
    <span class="font-semibold text-gray-800"><?php echo count($all_tables); ?></span> tables in database
</p>
<div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 xl:grid-cols-6 gap-3">
    <?php foreach ($all_tables as $tbl): ?>
    <a href="db_viewer.php?table=<?php echo urlencode($tbl); ?>"
       class="group flex flex-col bg-white border border-gray-200 rounded-xl p-4
              hover:border-primary-400 hover:shadow-md transition-all">
        <div class="flex items-center justify-between mb-3">
            <i class="fas fa-table text-primary-400 group-hover:text-primary-600 transition-colors"></i>
            <span class="text-xs font-bold text-gray-400 bg-gray-100 px-2 py-0.5 rounded-full
                         group-hover:bg-primary-50 group-hover:text-primary-600 transition-colors">
                <?php echo number_format($table_counts[$tbl]); ?>
            </span>
        </div>
        <div class="text-sm font-semibold text-gray-700 group-hover:text-primary-700 break-all leading-snug">
            <?php echo htmlspecialchars($tbl); ?>
        </div>
        <div class="mt-1 text-[10px] text-gray-400">
            <?php echo $table_counts[$tbl] === 1 ? '1 row' : number_format($table_counts[$tbl]) . ' rows'; ?>
        </div>
    </a>
    <?php endforeach; ?>
</div>

<?php else: ?>
<!-- ══ TABLE VIEW ════════════════════════════════════════════════════════════ -->

<!-- Breadcrumb + search -->
<div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-4">
    <div class="flex items-center gap-2 text-sm text-gray-500 flex-wrap">
        <a href="db_viewer.php" class="hover:text-primary-600">All Tables</a>
        <i class="fas fa-chevron-right text-[10px] text-gray-400"></i>
        <span class="font-semibold text-gray-800 font-mono"><?php echo htmlspecialchars($active_table); ?></span>
        <span class="text-gray-400">·</span>
        <span><?php echo number_format($total_rows); ?> row<?php echo $total_rows !== 1 ? 's' : ''; ?></span>
        <span class="text-gray-400">·</span>
        <span>PK: <code class="text-xs bg-gray-100 px-1.5 py-0.5 rounded font-mono"><?php echo htmlspecialchars($pk_col); ?></code></span>
    </div>
    <form method="GET" action="db_viewer.php" class="flex items-center gap-2">
        <input type="hidden" name="table" value="<?php echo htmlspecialchars($active_table); ?>">
        <div class="relative">
            <input type="text" name="q" value="<?php echo htmlspecialchars($search_term); ?>"
                   placeholder="Search text columns…"
                   class="pl-8 pr-3 py-1.5 border border-gray-300 rounded-lg text-sm
                          focus:ring-2 focus:ring-primary-400 w-52">
            <i class="fas fa-search absolute left-2.5 top-1/2 -translate-y-1/2 text-gray-400 text-xs"></i>
        </div>
        <button type="submit" class="px-3 py-1.5 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded-lg text-sm font-medium">
            Search
        </button>
        <?php if ($search_term): ?>
        <a href="db_viewer.php?table=<?php echo urlencode($active_table); ?>"
           class="px-2 py-1.5 text-sm text-gray-400 hover:text-red-600">
            <i class="fas fa-times"></i>
        </a>
        <?php endif; ?>
    </form>
</div>

<!-- Toolbar -->
<div class="flex items-center justify-between mb-2 gap-3">
    <div class="flex items-center gap-3">
        <label class="flex items-center gap-2 text-sm text-gray-600 cursor-pointer select-none">
            <input type="checkbox" id="selectAll"
                   class="w-4 h-4 rounded border-gray-300 text-primary-600 focus:ring-primary-500 cursor-pointer">
            <span>Select all</span>
        </label>
        <span id="selCount" class="text-xs text-gray-400 hidden">
            <strong id="selNum">0</strong> selected
        </span>
        <button type="button" id="bulkDeleteBtn"
                onclick="confirmBulkDelete()"
                class="hidden items-center gap-1.5 px-3 py-1.5 bg-red-600 hover:bg-red-700
                       text-white text-xs font-semibold rounded-lg transition-colors">
            <i class="fas fa-trash-alt"></i> Delete Selected
        </button>
    </div>

    <!-- Insert button -->
    <button type="button"
            onclick="openInsertModal()"
            class="flex items-center gap-2 px-4 py-2 bg-primary-600 hover:bg-primary-700
                   text-white text-sm font-semibold rounded-lg transition-colors shadow-sm">
        <i class="fas fa-plus"></i> Insert Row
    </button>
</div>

<!-- Bulk delete form -->
<form method="POST" id="bulkForm">
    <input type="hidden" name="action"       value="delete_bulk">
    <input type="hidden" name="table"        value="<?php echo htmlspecialchars($active_table); ?>">
    <input type="hidden" name="csrf_token"   value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
    <input type="hidden" name="current_page" value="<?php echo $current_page; ?>">
    <input type="hidden" name="search_term"  value="<?php echo htmlspecialchars($search_term); ?>">

    <?php if (empty($rows)): ?>
    <div class="bg-white border border-gray-200 rounded-xl p-12 text-center text-gray-400">
        <i class="fas fa-inbox text-4xl mb-3 block"></i>
        <?php echo $search_term ? 'No rows match your search.' : 'This table is empty.'; ?>
    </div>
    <?php else: ?>
    <div class="bg-white border border-gray-200 rounded-xl overflow-hidden shadow-sm">
        <div class="overflow-x-auto">
            <table class="min-w-full text-xs font-mono" id="dataTable">
                <thead class="bg-gray-50 border-b border-gray-200">
                    <tr>
                        <th class="px-3 py-3 w-8 border-r border-gray-200 text-center">&nbsp;</th>
                        <?php foreach ($columns as $col): ?>
                        <th class="px-3 py-3 text-left text-gray-600 font-semibold whitespace-nowrap border-r border-gray-100 last:border-r-0">
                            <div class="flex items-center gap-1.5">
                                <?php if ($col['Field'] === $pk_col): ?>
                                    <i class="fas fa-key text-yellow-500 text-[9px]" title="Primary Key"></i>
                                <?php elseif ($col['Key'] === 'MUL'): ?>
                                    <i class="fas fa-link text-blue-400 text-[9px]" title="Index/FK"></i>
                                <?php elseif ($col['Key'] === 'UNI'): ?>
                                    <i class="fas fa-fingerprint text-purple-400 text-[9px]" title="Unique"></i>
                                <?php endif; ?>
                                <?php echo htmlspecialchars($col['Field']); ?>
                            </div>
                            <div class="text-[9px] text-gray-400 font-normal mt-0.5">
                                <?php echo htmlspecialchars($col['Type']); ?>
                                · <?php echo $col['Null'] === 'NO' ? 'NOT NULL' : 'NULL'; ?>
                            </div>
                        </th>
                        <?php endforeach; ?>
                        <th class="px-3 py-3 text-right border-l border-gray-200 whitespace-nowrap text-gray-500 font-semibold">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <?php foreach ($rows as $row):
                        $pk_val   = $row[$pk_col] ?? null;
                        $row_json = htmlspecialchars(json_encode($row, JSON_HEX_QUOT | JSON_HEX_APOS), ENT_QUOTES);
                    ?>
                    <tr class="hover:bg-blue-50 transition-colors group"
                        data-row="<?php echo $row_json; ?>">
                        <td class="px-3 py-2 text-center border-r border-gray-100 w-8">
                            <input type="checkbox" name="row_ids[]"
                                   value="<?php echo htmlspecialchars((string)$pk_val); ?>"
                                   class="row-checkbox w-4 h-4 rounded border-gray-300
                                          text-primary-600 focus:ring-primary-500 cursor-pointer">
                        </td>
                        <?php foreach ($columns as $col):
                            $val      = $row[$col['Field']] ?? null;
                            $is_null  = !array_key_exists($col['Field'], $row) || $row[$col['Field']] === null;
                            $display  = $is_null ? '' : (string)$val;
                            $preview  = mb_strlen($display) > 100 ? mb_substr($display, 0, 100) . '…' : $display;
                            $type     = strtolower($col['Type']);
                            $cc = 'text-gray-700';
                            if ($is_null)                             $cc = 'text-gray-300 italic';
                            elseif ($col['Field'] === $pk_col)        $cc = 'text-yellow-700 font-bold';
                            elseif (str_contains($type, 'int'))       $cc = 'text-blue-700';
                            elseif (str_contains($type, 'decimal') || str_contains($type, 'float')) $cc = 'text-emerald-700';
                            elseif (str_contains($type, 'date') || str_contains($type, 'time'))     $cc = 'text-purple-700';
                            elseif (str_contains($type, 'enum') || str_contains($type, 'set'))      $cc = 'text-orange-700';
                        ?>
                        <td class="px-3 py-2 border-r border-gray-100 last:border-r-0 max-w-[200px]"
                            <?php if (mb_strlen($display) > 100): ?>title="<?php echo htmlspecialchars($display); ?>"<?php endif; ?>>
                            <?php if ($is_null): ?>
                                <span class="text-gray-300 italic text-[10px]">NULL</span>
                            <?php else: ?>
                                <span class="<?php echo $cc; ?> break-all"><?php echo htmlspecialchars($preview); ?></span>
                            <?php endif; ?>
                        </td>
                        <?php endforeach; ?>
                        <!-- Row actions -->
                        <td class="px-3 py-2 border-l border-gray-100 whitespace-nowrap text-right">
                            <div class="flex items-center justify-end gap-1 opacity-0 group-hover:opacity-100 transition-opacity">
                                <!-- Edit -->
                                <button type="button"
                                        onclick="openEditModal(this.closest('tr').dataset.row)"
                                        class="px-2 py-1 text-gray-400 hover:text-primary-600 hover:bg-primary-50 rounded transition-colors"
                                        title="Edit row">
                                    <i class="fas fa-pencil-alt text-xs"></i>
                                </button>
                                <!-- Delete -->
                                <form method="POST" class="inline"
                                      onsubmit="return confirmSingleDelete('<?php echo htmlspecialchars(addslashes((string)$pk_val)); ?>')">
                                    <input type="hidden" name="action"       value="delete_row">
                                    <input type="hidden" name="table"        value="<?php echo htmlspecialchars($active_table); ?>">
                                    <input type="hidden" name="row_id"       value="<?php echo htmlspecialchars((string)$pk_val); ?>">
                                    <input type="hidden" name="csrf_token"   value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
                                    <input type="hidden" name="current_page" value="<?php echo $current_page; ?>">
                                    <input type="hidden" name="search_term"  value="<?php echo htmlspecialchars($search_term); ?>">
                                    <button type="submit"
                                            class="px-2 py-1 text-gray-400 hover:text-red-600 hover:bg-red-50 rounded transition-colors"
                                            title="Delete row">
                                        <i class="fas fa-trash text-xs"></i>
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
</form>

<!-- Pagination -->
<?php if ($total_pages > 1):
    $base_url = 'db_viewer.php?table=' . urlencode($active_table) . ($search_term ? '&q=' . urlencode($search_term) : '');
    $win = 2; $start = max(1, $current_page - $win); $end = min($total_pages, $current_page + $win);
?>
<div class="flex items-center justify-between mt-4">
    <p class="text-sm text-gray-500">
        Rows <?php echo number_format(($current_page-1)*$per_page+1); ?>–<?php echo number_format(min($current_page*$per_page,$total_rows)); ?>
        of <?php echo number_format($total_rows); ?>
    </p>
    <div class="flex items-center gap-1">
        <?php if ($current_page > 1): ?>
        <a href="<?php echo $base_url; ?>&page=1" class="px-2 py-1.5 text-xs text-gray-500 hover:bg-gray-100 rounded"><i class="fas fa-angle-double-left"></i></a>
        <a href="<?php echo $base_url; ?>&page=<?php echo $current_page-1; ?>" class="px-2 py-1.5 text-xs text-gray-500 hover:bg-gray-100 rounded"><i class="fas fa-angle-left"></i></a>
        <?php endif; ?>
        <?php if ($start > 1): ?><span class="px-2 text-xs text-gray-400">…</span><?php endif; ?>
        <?php for ($p = $start; $p <= $end; $p++): ?>
        <a href="<?php echo $base_url; ?>&page=<?php echo $p; ?>"
           class="px-3 py-1.5 text-xs rounded <?php echo $p === $current_page ? 'bg-primary-600 text-white font-bold' : 'text-gray-600 hover:bg-gray-100'; ?>">
            <?php echo $p; ?>
        </a>
        <?php endfor; ?>
        <?php if ($end < $total_pages): ?><span class="px-2 text-xs text-gray-400">…</span><?php endif; ?>
        <?php if ($current_page < $total_pages): ?>
        <a href="<?php echo $base_url; ?>&page=<?php echo $current_page+1; ?>" class="px-2 py-1.5 text-xs text-gray-500 hover:bg-gray-100 rounded"><i class="fas fa-angle-right"></i></a>
        <a href="<?php echo $base_url; ?>&page=<?php echo $total_pages; ?>" class="px-2 py-1.5 text-xs text-gray-500 hover:bg-gray-100 rounded"><i class="fas fa-angle-double-right"></i></a>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- ══ CRUD MODAL ════════════════════════════════════════════════════════════ -->
<div id="crudModal"
     class="hidden fixed inset-0 z-50 flex items-start justify-center bg-black/60 overflow-y-auto py-8"
     onclick="if(event.target===this) closeCrudModal()">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-2xl mx-4 flex flex-col"
         style="max-height:90vh;">

        <!-- Modal header -->
        <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between flex-shrink-0">
            <div class="flex items-center gap-3">
                <div id="modalIcon" class="w-9 h-9 rounded-lg flex items-center justify-center bg-primary-100">
                    <i class="fas fa-plus text-primary-600"></i>
                </div>
                <div>
                    <h3 id="modalTitle" class="font-bold text-gray-900 text-lg">Insert Row</h3>
                    <p class="text-xs text-gray-500 font-mono"><?php echo htmlspecialchars($active_table); ?></p>
                </div>
            </div>
            <button onclick="closeCrudModal()"
                    class="text-gray-400 hover:text-gray-600 p-1.5 rounded-lg hover:bg-gray-100 transition-colors">
                <i class="fas fa-times"></i>
            </button>
        </div>

        <!-- Form -->
        <form method="POST" id="crudForm" class="flex flex-col flex-1 overflow-hidden">
            <input type="hidden" name="table"        value="<?php echo htmlspecialchars($active_table); ?>">
            <input type="hidden" name="csrf_token"   value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
            <input type="hidden" name="current_page" value="<?php echo $current_page; ?>">
            <input type="hidden" name="search_term"  value="<?php echo htmlspecialchars($search_term); ?>">
            <input type="hidden" name="action"       id="crudAction" value="insert_row">
            <input type="hidden" name="row_id"       id="crudRowId"  value="">

            <!-- Scrollable fields -->
            <div class="overflow-y-auto flex-1 px-6 py-4">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-5 gap-y-3">
                    <?php foreach ($columns as $col):
                        $itype    = col_input_type($col);
                        $is_pk    = $col['Field'] === $pk_col;
                        $nullable = $col['Null'] === 'YES';
                        $auto_inc = $col['Extra'] === 'auto_increment';
                        $opts     = in_array($itype, ['enum','set']) ? parse_enum_opts($col['Type']) : [];
                        $fid      = 'f_' . preg_replace('/[^a-zA-Z0-9_]/', '_', $col['Field']);
                    ?>
                    <div class="flex flex-col gap-1">
                        <!-- Label row -->
                        <div class="flex items-center justify-between">
                            <label for="<?php echo $fid; ?>"
                                   class="text-xs font-semibold text-gray-700 font-mono flex items-center gap-1.5">
                                <?php if ($is_pk): ?>
                                    <i class="fas fa-key text-yellow-500 text-[9px]"></i>
                                <?php endif; ?>
                                <?php echo htmlspecialchars($col['Field']); ?>
                                <span class="font-normal text-gray-400 text-[10px] normal-case">
                                    <?php echo htmlspecialchars($col['Type']); ?>
                                </span>
                                <?php if (!$nullable && !$auto_inc): ?>
                                <span class="text-red-400 text-[10px]" title="Required">*</span>
                                <?php endif; ?>
                            </label>
                            <!-- NULL toggle for nullable fields -->
                            <?php if ($nullable): ?>
                            <label class="flex items-center gap-1 text-[10px] text-gray-400 cursor-pointer">
                                <input type="checkbox"
                                       name="_null_<?php echo htmlspecialchars($col['Field']); ?>"
                                       id="null_<?php echo $fid; ?>"
                                       onchange="toggleNull(this, '<?php echo $fid; ?>')"
                                       class="w-3 h-3 rounded border-gray-300 text-gray-500">
                                NULL
                            </label>
                            <?php endif; ?>
                        </div>

                        <!-- Input -->
                        <?php
                        $base_cls = 'w-full px-3 py-1.5 border border-gray-300 rounded-lg text-sm font-mono
                                     focus:ring-2 focus:ring-primary-400 focus:border-primary-400 transition-colors';
                        $dis_cls  = 'bg-gray-50 text-gray-400 cursor-not-allowed';
                        ?>

                        <?php if ($auto_inc): ?>
                            <!-- Auto-increment: show as disabled, never submitted -->
                            <input type="text" id="<?php echo $fid; ?>"
                                   placeholder="auto" disabled
                                   class="<?php echo $base_cls; ?> <?php echo $dis_cls; ?>">

                        <?php elseif ($itype === 'boolean'): ?>
                            <select name="field_<?php echo htmlspecialchars($col['Field']); ?>"
                                    id="<?php echo $fid; ?>"
                                    class="<?php echo $base_cls; ?>">
                                <option value="1">1 (true)</option>
                                <option value="0">0 (false)</option>
                            </select>

                        <?php elseif ($itype === 'enum'): ?>
                            <select name="field_<?php echo htmlspecialchars($col['Field']); ?>"
                                    id="<?php echo $fid; ?>"
                                    class="<?php echo $base_cls; ?>">
                                <?php if ($nullable): ?>
                                <option value="">— NULL —</option>
                                <?php endif; ?>
                                <?php foreach ($opts as $opt): ?>
                                <option value="<?php echo htmlspecialchars($opt); ?>">
                                    <?php echo htmlspecialchars($opt); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>

                        <?php elseif ($itype === 'set'): ?>
                            <select name="field_<?php echo htmlspecialchars($col['Field']); ?>"
                                    id="<?php echo $fid; ?>"
                                    class="<?php echo $base_cls; ?>">
                                <?php foreach ($opts as $opt): ?>
                                <option value="<?php echo htmlspecialchars($opt); ?>">
                                    <?php echo htmlspecialchars($opt); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>

                        <?php elseif ($itype === 'textarea'): ?>
                            <textarea name="field_<?php echo htmlspecialchars($col['Field']); ?>"
                                      id="<?php echo $fid; ?>"
                                      rows="3"
                                      class="<?php echo $base_cls; ?> resize-y"></textarea>

                        <?php elseif ($itype === 'date'): ?>
                            <input type="date"
                                   name="field_<?php echo htmlspecialchars($col['Field']); ?>"
                                   id="<?php echo $fid; ?>"
                                   class="<?php echo $base_cls; ?>">

                        <?php elseif ($itype === 'datetime'): ?>
                            <input type="datetime-local"
                                   name="field_<?php echo htmlspecialchars($col['Field']); ?>"
                                   id="<?php echo $fid; ?>"
                                   class="<?php echo $base_cls; ?>">

                        <?php elseif ($itype === 'time'): ?>
                            <input type="time"
                                   name="field_<?php echo htmlspecialchars($col['Field']); ?>"
                                   id="<?php echo $fid; ?>"
                                   class="<?php echo $base_cls; ?>">

                        <?php elseif ($itype === 'integer'): ?>
                            <input type="number" step="1"
                                   name="field_<?php echo htmlspecialchars($col['Field']); ?>"
                                   id="<?php echo $fid; ?>"
                                   class="<?php echo $base_cls; ?>">

                        <?php elseif ($itype === 'decimal'): ?>
                            <input type="number" step="any"
                                   name="field_<?php echo htmlspecialchars($col['Field']); ?>"
                                   id="<?php echo $fid; ?>"
                                   class="<?php echo $base_cls; ?>">

                        <?php else: /* text / varchar */ ?>
                            <?php
                            // Extract maxlength from varchar(255)
                            $maxlen = null;
                            if (preg_match('/\((\d+)\)/', $col['Type'], $mm)) $maxlen = (int)$mm[1];
                            ?>
                            <input type="text"
                                   name="field_<?php echo htmlspecialchars($col['Field']); ?>"
                                   id="<?php echo $fid; ?>"
                                   <?php if ($maxlen): ?>maxlength="<?php echo $maxlen; ?>"<?php endif; ?>
                                   class="<?php echo $base_cls; ?>">
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Modal footer -->
            <div class="px-6 py-4 border-t border-gray-200 flex items-center justify-end gap-3 flex-shrink-0 bg-gray-50 rounded-b-2xl">
                <button type="button" onclick="closeCrudModal()"
                        class="px-5 py-2 border border-gray-300 rounded-lg text-sm font-medium
                               text-gray-700 hover:bg-gray-100 transition-colors">
                    Cancel
                </button>
                <button type="submit" id="crudSubmitBtn"
                        class="px-6 py-2 bg-primary-600 hover:bg-primary-700 text-white rounded-lg
                               text-sm font-bold shadow transition-colors flex items-center gap-2">
                    <i class="fas fa-plus" id="crudSubmitIcon"></i>
                    <span id="crudSubmitLabel">Insert Row</span>
                </button>
            </div>
        </form>
    </div>
</div>

<?php endif; ?>
</div><!-- end page wrapper -->

<!-- ══ Column metadata for JS ════════════════════════════════════════════════ -->
<script>
const COL_META  = <?php echo json_encode($col_meta ?? []); ?>;
const PK_COL    = <?php echo json_encode($pk_col ?? 'id'); ?>;

// ── Select-all ───────────────────────────────────────────────────────────────
const selectAll = document.getElementById('selectAll');
const bulkBtn   = document.getElementById('bulkDeleteBtn');
const selCount  = document.getElementById('selCount');
const selNum    = document.getElementById('selNum');

function updateBulkUI() {
    const all     = document.querySelectorAll('.row-checkbox');
    const checked = document.querySelectorAll('.row-checkbox:checked');
    if (checked.length > 0) {
        bulkBtn.classList.replace('hidden', 'inline-flex');
        selCount.classList.remove('hidden');
        selNum.textContent = checked.length;
    } else {
        bulkBtn.classList.replace('inline-flex', 'hidden');
        selCount.classList.add('hidden');
    }
    if (selectAll) {
        selectAll.indeterminate = checked.length > 0 && checked.length < all.length;
        selectAll.checked = all.length > 0 && checked.length === all.length;
    }
}

if (selectAll) {
    selectAll.addEventListener('change', function () {
        document.querySelectorAll('.row-checkbox').forEach(cb => cb.checked = this.checked);
        updateBulkUI();
    });
}
document.querySelectorAll('.row-checkbox').forEach(cb => {
    cb.addEventListener('change', function () {
        this.closest('tr').classList.toggle('bg-red-50', this.checked);
        updateBulkUI();
    });
});

// ── Delete confirms ──────────────────────────────────────────────────────────
function confirmSingleDelete(pkVal) {
    return confirm(
        `Delete this row (${PK_COL} = ${pkVal})?\n\n` +
        `⚠ FK checks are DISABLED — child records referencing this row will be orphaned.\n` +
        `This cannot be undone.`
    );
}
function confirmBulkDelete() {
    const n = document.querySelectorAll('.row-checkbox:checked').length;
    if (!n) { alert('Select at least one row first.'); return; }
    if (confirm(`Delete ${n} selected row(s)?\n\n⚠ FK checks are DISABLED. This cannot be undone.`)) {
        document.getElementById('bulkForm').submit();
    }
}

// ── CRUD Modal ───────────────────────────────────────────────────────────────
function openInsertModal() {
    // Reset mode
    document.getElementById('crudAction').value   = 'insert_row';
    document.getElementById('crudRowId').value    = '';
    document.getElementById('modalTitle').textContent = 'Insert New Row';
    document.getElementById('modalIcon').className = 'w-9 h-9 rounded-lg flex items-center justify-center bg-primary-100';
    document.getElementById('modalIcon').innerHTML = '<i class="fas fa-plus text-primary-600"></i>';
    document.getElementById('crudSubmitLabel').textContent = 'Insert Row';
    document.getElementById('crudSubmitIcon').className    = 'fas fa-plus';

    // Clear all fields
    clearFormFields();

    // Set defaults from col_meta
    for (const [field, meta] of Object.entries(COL_META)) {
        if (meta.extra === 'auto_increment') continue;
        if (meta.default !== null) setField(field, meta.default);
    }

    showModal();
}

function openEditModal(rowJson) {
    const row = JSON.parse(rowJson);

    document.getElementById('crudAction').value   = 'update_row';
    document.getElementById('crudRowId').value    = row[PK_COL] ?? '';
    document.getElementById('modalTitle').textContent = `Edit Row — ${PK_COL} = ${row[PK_COL]}`;
    document.getElementById('modalIcon').className = 'w-9 h-9 rounded-lg flex items-center justify-center bg-amber-100';
    document.getElementById('modalIcon').innerHTML = '<i class="fas fa-pencil-alt text-amber-600"></i>';
    document.getElementById('crudSubmitLabel').textContent = 'Save Changes';
    document.getElementById('crudSubmitIcon').className    = 'fas fa-save';

    clearFormFields();

    // Fill with row data
    for (const [field, val] of Object.entries(row)) {
        const meta = COL_META[field];
        if (!meta) continue;

        // Handle NULL
        const nullCb = document.getElementById(`null_f_${field.replace(/[^a-zA-Z0-9_]/g, '_')}`);
        if (val === null) {
            if (nullCb) { nullCb.checked = true; toggleNull(nullCb, `f_${field.replace(/[^a-zA-Z0-9_]/g, '_')}`); }
        } else {
            if (nullCb) { nullCb.checked = false; toggleNull(nullCb, `f_${field.replace(/[^a-zA-Z0-9_]/g, '_')}`); }
            setField(field, val);
        }
    }

    showModal();
}

function setField(field, value) {
    const fid = `f_${field.replace(/[^a-zA-Z0-9_]/g, '_')}`;
    const el  = document.getElementById(fid);
    if (!el || el.disabled) return;

    const meta = COL_META[field];
    if (!meta) return;

    if (meta.type === 'datetime' && value) {
        // Convert "2025-01-01 12:00:00" → "2025-01-01T12:00" for datetime-local
        el.value = String(value).replace(' ', 'T').substring(0, 16);
    } else if (meta.type === 'boolean') {
        el.value = value ? '1' : '0';
    } else {
        el.value = value ?? '';
    }
}

function clearFormFields() {
    document.querySelectorAll('#crudForm input:not([type=hidden]):not([type=checkbox]), #crudForm select, #crudForm textarea').forEach(el => {
        if (el.disabled) return;
        if (el.tagName === 'SELECT') el.selectedIndex = 0;
        else el.value = '';
    });
    // Uncheck all NULL toggles
    document.querySelectorAll('#crudForm input[type=checkbox]').forEach(cb => {
        if (cb.id && cb.id.startsWith('null_')) {
            cb.checked = false;
            const target = cb.id.replace('null_', '');
            const field  = document.getElementById(target);
            if (field) { field.disabled = false; field.style.opacity = '1'; }
        }
    });
}

function toggleNull(checkbox, fieldId) {
    const field = document.getElementById(fieldId);
    if (!field) return;
    field.disabled    = checkbox.checked;
    field.style.opacity = checkbox.checked ? '0.3' : '1';
    if (checkbox.checked) field.value = '';
}

function showModal() {
    document.getElementById('crudModal').classList.remove('hidden');
    document.body.style.overflow = 'hidden';
}
function closeCrudModal() {
    document.getElementById('crudModal').classList.add('hidden');
    document.body.style.overflow = '';
}

document.addEventListener('keydown', e => { if (e.key === 'Escape') closeCrudModal(); });
</script>

<?php require_once '../templates/footer.php'; ?>
