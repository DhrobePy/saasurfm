<?php
require_once dirname(__DIR__) . '/core/init.php';
restrict_access(['Superadmin', 'admin']);

$pageTitle = 'Google Drive Manager';

// ── Shared: resolve Drive service (service account preferred) ─────────────────
function getDriveService(): ?GoogleDriveService
{
    // OAuth2 is required for personal Google Drive uploads (service accounts
    // have no storage quota on personal Drive — only Shared/Team Drives).
    $cid = defined('GOOGLE_OAUTH_CLIENT_ID')     ? GOOGLE_OAUTH_CLIENT_ID     : '';
    $sec = defined('GOOGLE_OAUTH_CLIENT_SECRET') ? GOOGLE_OAUTH_CLIENT_SECRET : '';
    $ref = defined('GOOGLE_OAUTH_REFRESH_TOKEN') ? GOOGLE_OAUTH_REFRESH_TOKEN : '';
    if ($cid && $sec && $ref) {
        return new GoogleDriveService($cid, $sec, $ref);
    }
    return null;
}

function getLocalBackupDir(): string
{
    if (defined('DB_LOCAL_BACKUP_DIR') && DB_LOCAL_BACKUP_DIR) return DB_LOCAL_BACKUP_DIR;
    return dirname(dirname(__DIR__)) . '/db_backups';
}

// ── AJAX action handler ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');

    if (($_POST['csrf'] ?? '') !== ($_SESSION['csrf_token'] ?? '')) {
        echo json_encode(['ok' => false, 'msg' => 'Invalid CSRF token.']);
        exit;
    }

    $action = $_POST['action'];

    try {
        // ── Backup now (works with or without Drive) ────────────────────────
        if ($action === 'backup_now') {
            $drive  = getDriveService();
            $svc    = new DatabaseBackupService(
                $drive, GOOGLE_DRIVE_BACKUP_FOLDER_ID, DB_BACKUP_KEEP_COUNT,
                getLocalBackupDir(), DB_LOCAL_BACKUP_KEEP_DAYS
            );
            $result = $svc->runBackup();
            echo json_encode([
                'ok'         => $result['ok'],
                'msg'        => $result['message'],
                'file'       => $result['filename'],
                'size'       => $result['size_bytes'],
                'local_ok'   => $result['local_ok'],
                'drive_ok'   => $result['drive_ok'],
                'local_path' => $result['local_path'],
                'warnings'   => $result['warnings'],
            ]);
            exit;
        }

        // ── Drive-dependent actions ─────────────────────────────────────────
        $drive = getDriveService();
        if (!$drive) {
            echo json_encode(['ok' => false, 'msg' => 'Google Drive is not configured. Set up service account or OAuth2 credentials in config.php.']);
            exit;
        }

        if ($action === 'delete_backup') {
            $fileId = trim($_POST['file_id'] ?? '');
            if (!$fileId) { echo json_encode(['ok' => false, 'msg' => 'No file ID.']); exit; }
            $ok = $drive->deleteFile($fileId);
            echo json_encode(['ok' => $ok, 'msg' => $ok ? 'Deleted.' : 'Delete failed.']);
            exit;
        }

        if ($action === 'upload_file') {
            $category  = $_POST['category'] ?? 'receipts';
            $folderMap = [
                'receipts' => GOOGLE_DRIVE_RECEIPTS_FOLDER_ID,
                'images'   => GOOGLE_DRIVE_IMAGES_FOLDER_ID,
                'docs'     => GOOGLE_DRIVE_DOCS_FOLDER_ID,
            ];
            $folderId = $folderMap[$category] ?? '';
            if (empty($folderId)) {
                echo json_encode(['ok' => false, 'msg' => "Drive folder for '$category' not configured."]); exit;
            }
            if (empty($_FILES['file']['tmp_name'])) {
                echo json_encode(['ok' => false, 'msg' => 'No file uploaded.']); exit;
            }
            $file = $_FILES['file'];
            if ($file['size'] > 50 * 1024 * 1024) {
                echo json_encode(['ok' => false, 'msg' => 'File too large (max 50 MB).']); exit;
            }
            $mime     = mime_content_type($file['tmp_name']) ?: 'application/octet-stream';
            $safeName = preg_replace('/[^a-zA-Z0-9._\-]/', '_', $file['name']);
            $meta     = $drive->uploadFile($file['tmp_name'], date('Ymd_His_') . $safeName, $mime, $folderId);
            echo json_encode([
                'ok'      => !empty($meta['id']),
                'msg'     => !empty($meta['id']) ? 'Uploaded: ' . $safeName : 'Upload failed.',
                'file_id' => $meta['id'] ?? '',
                'view'    => $meta['webViewLink'] ?? '',
            ]);
            exit;
        }

        if ($action === 'delete_file') {
            $fileId = trim($_POST['file_id'] ?? '');
            $ok     = $fileId ? $drive->deleteFile($fileId) : false;
            echo json_encode(['ok' => $ok, 'msg' => $ok ? 'Deleted.' : 'Delete failed.']);
            exit;
        }

    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
        exit;
    }

    echo json_encode(['ok' => false, 'msg' => 'Unknown action.']);
    exit;
}

// ── Page data ──────────────────────────────────────────────────────────────────
$driveEnabled    = defined('GOOGLE_DRIVE_ENABLED') && GOOGLE_DRIVE_ENABLED;
$driveAuthMode   = 'none';
$backups         = [];
$localBackups    = [];
$filesByCategory = ['receipts' => [], 'images' => [], 'docs' => []];
$storageInfo     = [];
$setupError      = '';
$localDir        = getLocalBackupDir();

// Local backups (always available)
try {
    $tmpSvc      = new DatabaseBackupService(null, '', 0, $localDir, DB_LOCAL_BACKUP_KEEP_DAYS);
    $localBackups = $tmpSvc->listLocalBackups();
} catch (Throwable) {}

// Drive (optional)
if ($driveEnabled) {
    try {
        $drive = getDriveService();
        if ($drive === null) throw new RuntimeException('No Drive credentials configured (set up service account or OAuth2).');

        // Detect which auth mode is active
        $saJson = defined('GOOGLE_SERVICE_ACCOUNT_JSON') ? GOOGLE_SERVICE_ACCOUNT_JSON : '';
        if ($saJson && file_exists($saJson)) {
            $saData = json_decode(file_get_contents($saJson), true);
            $driveAuthMode = ($saData['client_email'] ?? '') !== 'REPLACE_ME@REPLACE_ME.iam.gserviceaccount.com'
                ? 'service_account' : 'oauth2';
        } else {
            $driveAuthMode = 'oauth2';
        }

        if (GOOGLE_DRIVE_BACKUP_FOLDER_ID) {
            $svc     = new DatabaseBackupService($drive, GOOGLE_DRIVE_BACKUP_FOLDER_ID, DB_BACKUP_KEEP_COUNT, $localDir, DB_LOCAL_BACKUP_KEEP_DAYS);
            $backups = $svc->listBackups(500);
        }
        foreach (['receipts' => GOOGLE_DRIVE_RECEIPTS_FOLDER_ID, 'images' => GOOGLE_DRIVE_IMAGES_FOLDER_ID, 'docs' => GOOGLE_DRIVE_DOCS_FOLDER_ID] as $cat => $fid) {
            if ($fid) $filesByCategory[$cat] = $drive->listFiles($fid, 50);
        }
        $storageInfo = $drive->getStorageInfo();

    } catch (Throwable $e) {
        $setupError = $e->getMessage();
    }
}

// ── Helpers ────────────────────────────────────────────────────────────────────
function fmtBytes(int $bytes): string {
    if ($bytes >= 1_073_741_824) return round($bytes / 1_073_741_824, 2) . ' GB';
    if ($bytes >= 1_048_576)     return round($bytes / 1_048_576, 2)     . ' MB';
    if ($bytes >= 1_024)         return round($bytes / 1_024, 1)          . ' KB';
    return $bytes . ' B';
}

$totalBackupSize = array_sum(array_column($backups, 'size'));
$lastBackup      = $backups[0] ?? null;

include dirname(__DIR__) . '/templates/header.php';
$csrf = $_SESSION['csrf_token'] ?? '';
?>

<div class="max-w-7xl mx-auto space-y-6" x-data="driveManager()" x-init="init()">

    <!-- ═══════════════════════════════════════════════════════════
         PAGE HEADER
    ════════════════════════════════════════════════════════════ -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-2xl font-extrabold text-gray-900 flex items-center gap-2.5">
                <span class="w-9 h-9 rounded-xl bg-emerald-600 flex items-center justify-center shadow">
                    <i class="fab fa-google-drive text-white text-base"></i>
                </span>
                Google Drive Manager
            </h1>
            <p class="text-sm text-gray-500 mt-1">
                Automated DB backups every 30 min &mdash; secure cloud storage for receipts, images &amp; documents
            </p>
        </div>
        <div class="flex items-center gap-3 flex-wrap">
            <!-- Auth mode pill -->
            <?php if ($driveEnabled && !$setupError): ?>
            <span class="inline-flex items-center gap-1.5 text-xs font-semibold px-3 py-1.5 rounded-full border
                <?php echo $driveAuthMode === 'service_account'
                    ? 'bg-emerald-50 border-emerald-200 text-emerald-700'
                    : 'bg-amber-50 border-amber-200 text-amber-700'; ?>">
                <i class="fas <?php echo $driveAuthMode === 'service_account' ? 'fa-shield-halved' : 'fa-key'; ?>"></i>
                <?php echo $driveAuthMode === 'service_account' ? 'Service Account' : 'OAuth2'; ?>
            </span>
            <?php elseif ($setupError): ?>
            <span class="inline-flex items-center gap-1.5 text-xs font-semibold px-3 py-1.5 rounded-full bg-red-50 border border-red-200 text-red-700">
                <i class="fas fa-triangle-exclamation"></i> Drive Error
            </span>
            <?php endif; ?>
            <!-- Backup button (always shown — local backup works without Drive) -->
            <button @click="backupNow()"
                    :disabled="backing"
                    class="inline-flex items-center gap-2 bg-emerald-600 hover:bg-emerald-700 disabled:opacity-60
                           text-white font-semibold px-5 py-2.5 rounded-xl shadow transition-all">
                <i class="fas fa-database text-sm" :class="backing ? 'animate-pulse' : ''"></i>
                <span x-text="backing ? 'Backing up…' : 'Backup Now'"></span>
            </button>
        </div>
    </div>

    <!-- ═══════════════════════════════════════════════════════════
         SETUP GUIDE (shown when not configured)
    ════════════════════════════════════════════════════════════ -->
    <?php if (!$driveEnabled || $setupError): ?>
    <div class="bg-amber-50 border border-amber-200 rounded-2xl p-6">
        <div class="flex items-start gap-4">
            <div class="w-10 h-10 rounded-xl bg-amber-100 flex items-center justify-center flex-shrink-0">
                <i class="fas fa-circle-exclamation text-amber-600 text-lg"></i>
            </div>
            <div class="flex-1 min-w-0">
                <h3 class="font-bold text-amber-800 text-base mb-3">
                    <?php echo $setupError ? 'Configuration Error' : 'Google Drive Not Yet Configured'; ?>
                </h3>
                <?php if ($setupError): ?>
                <div class="bg-red-50 border border-red-200 rounded-lg p-3 mb-4 font-mono text-xs text-red-700 break-all">
                    <?php echo htmlspecialchars($setupError); ?>
                </div>
                <?php endif; ?>
                <ol class="space-y-2 text-sm text-amber-900">
                    <li class="flex gap-2">
                        <span class="flex-shrink-0 w-6 h-6 rounded-full bg-amber-200 text-amber-800 text-xs font-bold flex items-center justify-center">1</span>
                        <span>Go to <a href="https://console.cloud.google.com" target="_blank" class="underline font-semibold">console.cloud.google.com</a> → New Project: <strong>UFM ERP</strong></span>
                    </li>
                    <li class="flex gap-2">
                        <span class="flex-shrink-0 w-6 h-6 rounded-full bg-amber-200 text-amber-800 text-xs font-bold flex items-center justify-center">2</span>
                        <span>APIs &amp; Services → Library → Enable <strong>Google Drive API</strong></span>
                    </li>
                    <li class="flex gap-2">
                        <span class="flex-shrink-0 w-6 h-6 rounded-full bg-amber-200 text-amber-800 text-xs font-bold flex items-center justify-center">3</span>
                        <span>Credentials → Create Credentials → <strong>Service Account</strong> → name it <em>ufm-erp-backup</em> → Done</span>
                    </li>
                    <li class="flex gap-2">
                        <span class="flex-shrink-0 w-6 h-6 rounded-full bg-amber-200 text-amber-800 text-xs font-bold flex items-center justify-center">4</span>
                        <span>Click the service account → <strong>Keys</strong> → Add Key → JSON → Download</span>
                    </li>
                    <li class="flex gap-2">
                        <span class="flex-shrink-0 w-6 h-6 rounded-full bg-amber-200 text-amber-800 text-xs font-bold flex items-center justify-center">5</span>
                        <span>Place the downloaded file at: <code class="bg-amber-100 px-1.5 py-0.5 rounded font-mono text-xs">/secure/google-service-account.json</code></span>
                    </li>
                    <li class="flex gap-2">
                        <span class="flex-shrink-0 w-6 h-6 rounded-full bg-amber-200 text-amber-800 text-xs font-bold flex items-center justify-center">6</span>
                        <span>In Google Drive, create a folder <strong>UFM ERP Backups</strong> → Share it with the service account email (Editor) → Copy the folder ID from the URL</span>
                    </li>
                    <li class="flex gap-2">
                        <span class="flex-shrink-0 w-6 h-6 rounded-full bg-amber-200 text-amber-800 text-xs font-bold flex items-center justify-center">7</span>
                        <span>Paste the folder IDs into <code class="bg-amber-100 px-1.5 py-0.5 rounded font-mono text-xs">core/config/config.php</code> and set <code class="bg-amber-100 px-1.5 py-0.5 rounded font-mono text-xs">GOOGLE_DRIVE_ENABLED = true</code></span>
                    </li>
                    <li class="flex gap-2">
                        <span class="flex-shrink-0 w-6 h-6 rounded-full bg-amber-200 text-amber-800 text-xs font-bold flex items-center justify-center">8</span>
                        <span>Add cron job — see the <strong>Cron Setup</strong> section below</span>
                    </li>
                </ol>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($driveEnabled && !$setupError): ?>

    <!-- ═══════════════════════════════════════════════════════════
         KPI CARDS
    ════════════════════════════════════════════════════════════ -->
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <!-- Last Backup -->
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
            <div class="flex items-center gap-3 mb-3">
                <div class="w-9 h-9 rounded-lg bg-emerald-100 flex items-center justify-center">
                    <i class="fas fa-clock-rotate-left text-emerald-600 text-sm"></i>
                </div>
                <span class="text-xs font-semibold text-gray-500 uppercase tracking-wider">Last Backup</span>
            </div>
            <?php if ($lastBackup): ?>
            <p class="text-lg font-extrabold text-gray-900 truncate">
                <?php echo date('d M, H:i', strtotime($lastBackup['createdTime'])); ?>
            </p>
            <p class="text-xs text-gray-400 mt-0.5 truncate">
                <?php echo htmlspecialchars($lastBackup['name']); ?>
            </p>
            <?php else: ?>
            <p class="text-sm text-gray-400">No backups yet</p>
            <?php endif; ?>
        </div>

        <!-- Total Backups -->
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
            <div class="flex items-center gap-3 mb-3">
                <div class="w-9 h-9 rounded-lg bg-sky-100 flex items-center justify-center">
                    <i class="fas fa-layer-group text-sky-600 text-sm"></i>
                </div>
                <span class="text-xs font-semibold text-gray-500 uppercase tracking-wider">Snapshots</span>
            </div>
            <p class="text-2xl font-extrabold text-gray-900"><?php echo count($backups); ?></p>
            <p class="text-xs text-gray-400 mt-0.5">
                <?php echo fmtBytes($totalBackupSize); ?> total &bull; keep <?php echo DB_BACKUP_KEEP_COUNT; ?>
            </p>
        </div>

        <!-- Local backups -->
        <?php
        $lastLocal    = $localBackups[0] ?? null;
        $totalLocalSz = array_sum(array_column($localBackups, 'size'));
        ?>
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
            <div class="flex items-center gap-3 mb-3">
                <div class="w-9 h-9 rounded-lg bg-violet-100 flex items-center justify-center">
                    <i class="fas fa-hard-drive text-violet-600 text-sm"></i>
                </div>
                <span class="text-xs font-semibold text-gray-500 uppercase tracking-wider">Local Backups</span>
            </div>
            <?php if ($lastLocal): ?>
            <p class="text-lg font-extrabold text-gray-900">
                <?php echo date('d M, H:i', strtotime($lastLocal['modified'])); ?>
            </p>
            <p class="text-xs text-gray-400 mt-0.5">
                <?php echo count($localBackups); ?> files &bull; <?php echo fmtBytes($totalLocalSz); ?> &bull; keep <?php echo DB_LOCAL_BACKUP_KEEP_DAYS; ?>d
            </p>
            <?php else: ?>
            <p class="text-sm text-gray-400">No local backups yet</p>
            <p class="text-xs text-gray-400 mt-0.5">Run Backup Now to create one</p>
            <?php endif; ?>
        </div>

        <!-- Drive storage -->
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
            <div class="flex items-center gap-3 mb-3">
                <div class="w-9 h-9 rounded-lg bg-amber-100 flex items-center justify-center">
                    <i class="fab fa-google-drive text-amber-600 text-sm"></i>
                </div>
                <span class="text-xs font-semibold text-gray-500 uppercase tracking-wider">Drive Storage</span>
            </div>
            <?php
            $used  = (int)($storageInfo['usage'] ?? 0);
            $limit = (int)($storageInfo['limit']  ?? 0);
            $pct   = $limit > 0 ? round($used / $limit * 100, 1) : 0;
            ?>
            <p class="text-lg font-extrabold text-gray-900"><?php echo fmtBytes($used); ?></p>
            <p class="text-xs text-gray-400 mt-0.5">
                <?php echo $limit > 0 ? "of " . fmtBytes($limit) . " ($pct%)" : 'Unlimited'; ?>
            </p>
        </div>
    </div>

    <!-- ═══════════════════════════════════════════════════════════
         RECENT BACKUPS TABLE
    ════════════════════════════════════════════════════════════ -->
    <div class="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
            <h2 class="font-bold text-gray-800 flex items-center gap-2">
                <i class="fas fa-database text-emerald-500"></i>
                Database Backups
                <span class="text-xs font-normal text-gray-400 ml-1">(newest first &bull; last 500)</span>
            </h2>
            <span class="text-xs text-gray-400">Retention: <?php echo DB_BACKUP_KEEP_COUNT; ?> snapshots ≈ 10 days</span>
        </div>

        <!-- Toast -->
        <div x-show="toast.show" x-transition
             class="mx-6 mt-4 px-4 py-3 rounded-xl text-sm font-medium flex items-center gap-2"
             :class="toast.ok ? 'bg-emerald-50 border border-emerald-200 text-emerald-800'
                              : 'bg-red-50 border border-red-200 text-red-800'">
            <i :class="toast.ok ? 'fas fa-check-circle text-emerald-500' : 'fas fa-circle-exclamation text-red-500'"></i>
            <span x-text="toast.msg"></span>
        </div>

        <?php if (empty($backups)): ?>
        <div class="px-6 py-12 text-center text-gray-400">
            <i class="fas fa-cloud-arrow-up text-4xl mb-3 opacity-30"></i>
            <p class="font-medium">No backups yet.</p>
            <p class="text-sm mt-1">Click <strong>Backup Now</strong> to create the first snapshot.</p>
        </div>
        <?php else: ?>
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-gray-50 border-b border-gray-100">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">File</th>
                        <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Created</th>
                        <th class="px-6 py-3 text-right text-xs font-semibold text-gray-500 uppercase tracking-wider">Size</th>
                        <th class="px-6 py-3 text-center text-xs font-semibold text-gray-500 uppercase tracking-wider">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-50">
                    <?php foreach ($backups as $i => $f): ?>
                    <tr class="hover:bg-gray-50 transition-colors" id="backup-<?php echo $f['id']; ?>">
                        <td class="px-6 py-3">
                            <div class="flex items-center gap-2.5">
                                <i class="fas fa-file-zipper text-emerald-400"></i>
                                <span class="font-mono text-xs text-gray-700 truncate max-w-xs">
                                    <?php echo htmlspecialchars($f['name']); ?>
                                </span>
                                <?php if ($i === 0): ?>
                                <span class="text-[10px] font-bold bg-emerald-100 text-emerald-700 px-1.5 py-0.5 rounded-full">latest</span>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td class="px-6 py-3 text-gray-500 whitespace-nowrap">
                            <?php echo date('d M Y, H:i', strtotime($f['createdTime'])); ?>
                        </td>
                        <td class="px-6 py-3 text-right text-gray-600 font-medium whitespace-nowrap">
                            <?php echo isset($f['size']) ? fmtBytes((int)$f['size']) : '—'; ?>
                        </td>
                        <td class="px-6 py-3 text-center">
                            <div class="flex items-center justify-center gap-2">
                                <?php if (!empty($f['webViewLink'])): ?>
                                <a href="<?php echo htmlspecialchars($f['webViewLink']); ?>"
                                   target="_blank"
                                   class="inline-flex items-center gap-1 text-xs font-semibold text-sky-600
                                          hover:text-sky-800 transition-colors px-2 py-1 rounded hover:bg-sky-50">
                                    <i class="fas fa-eye"></i> View
                                </a>
                                <?php endif; ?>
                                <?php if (!empty($f['webContentLink'])): ?>
                                <a href="<?php echo htmlspecialchars($f['webContentLink']); ?>"
                                   class="inline-flex items-center gap-1 text-xs font-semibold text-emerald-600
                                          hover:text-emerald-800 transition-colors px-2 py-1 rounded hover:bg-emerald-50">
                                    <i class="fas fa-download"></i> DL
                                </a>
                                <?php endif; ?>
                                <button onclick="deleteBackup('<?php echo $f['id']; ?>')"
                                        class="inline-flex items-center gap-1 text-xs font-semibold text-red-400
                                               hover:text-red-600 transition-colors px-2 py-1 rounded hover:bg-red-50">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <!-- ═══════════════════════════════════════════════════════════
         FILE STORAGE (Receipts / Images / Documents)
    ════════════════════════════════════════════════════════════ -->
    <div class="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-100 flex flex-col sm:flex-row sm:items-center gap-3">
            <h2 class="font-bold text-gray-800 flex items-center gap-2 flex-1">
                <i class="fas fa-folder-open text-sky-500"></i>
                File Storage
            </h2>
            <!-- Upload form -->
            <form id="uploadForm" onsubmit="uploadFile(event)"
                  enctype="multipart/form-data"
                  class="flex items-center gap-2 flex-wrap">
                <select id="uploadCategory" name="category"
                        class="text-xs border border-gray-200 rounded-lg px-2.5 py-2 text-gray-700
                               focus:outline-none focus:ring-2 focus:ring-sky-400">
                    <option value="receipts">Receipts</option>
                    <option value="images">Images</option>
                    <option value="docs">Documents / Tokens / Certs</option>
                </select>
                <label class="inline-flex items-center gap-1.5 cursor-pointer bg-sky-50 hover:bg-sky-100
                              text-sky-700 border border-sky-200 text-xs font-semibold px-3 py-2 rounded-lg transition-colors">
                    <i class="fas fa-paperclip"></i>
                    <span id="fileLabel">Choose file</span>
                    <input type="file" id="fileInput" name="file" class="hidden"
                           onchange="document.getElementById('fileLabel').textContent = this.files[0]?.name || 'Choose file'">
                </label>
                <button type="submit"
                        class="inline-flex items-center gap-1.5 bg-sky-600 hover:bg-sky-700 text-white
                               text-xs font-semibold px-3 py-2 rounded-lg transition-colors">
                    <i class="fas fa-cloud-arrow-up"></i> Upload
                </button>
            </form>
        </div>

        <!-- Category tabs -->
        <?php
        $tabs = [
            'receipts' => ['label' => 'Receipts',     'icon' => 'fa-receipt',    'color' => 'violet'],
            'images'   => ['label' => 'Images',        'icon' => 'fa-images',     'color' => 'pink'],
            'docs'     => ['label' => 'Docs / Tokens', 'icon' => 'fa-file-shield','color' => 'amber'],
        ];
        ?>
        <div class="border-b border-gray-100 px-6">
            <div class="flex gap-0.5 -mb-px" id="fileTabs">
                <?php foreach ($tabs as $tabKey => $tabDef):
                    $count  = count($filesByCategory[$tabKey]);
                    $active = $tabKey === 'receipts';
                ?>
                <button onclick="switchTab('<?php echo $tabKey; ?>')"
                        id="tab-btn-<?php echo $tabKey; ?>"
                        data-tab="<?php echo $tabKey; ?>"
                        data-active-class="border-b-2 border-<?php echo $tabDef['color']; ?>-500 text-<?php echo $tabDef['color']; ?>-700 font-semibold"
                        data-inactive-class="text-gray-500 hover:text-gray-700"
                        class="flex items-center gap-1.5 px-4 py-3 text-sm transition-colors
                               <?php echo $active
                                   ? 'border-b-2 border-' . $tabDef['color'] . '-500 text-' . $tabDef['color'] . '-700 font-semibold'
                                   : 'text-gray-500 hover:text-gray-700'; ?>">
                    <i class="fas <?php echo $tabDef['icon']; ?>"></i>
                    <?php echo $tabDef['label']; ?>
                    <span class="ml-1 text-xs bg-gray-100 text-gray-600 rounded-full px-1.5 py-0.5">
                        <?php echo $count; ?>
                    </span>
                </button>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- File lists per category -->
        <?php foreach ($tabs as $tabKey => $tabDef):
            $files = $filesByCategory[$tabKey];
        ?>
        <div id="tab-<?php echo $tabKey; ?>" class="tab-panel"
             style="display: <?php echo $tabKey === 'receipts' ? 'block' : 'none'; ?>">
            <?php if (empty($files)): ?>
            <div class="px-6 py-10 text-center text-gray-400">
                <i class="fas <?php echo $tabDef['icon']; ?> text-3xl mb-3 opacity-30"></i>
                <p class="text-sm">No <?php echo strtolower($tabDef['label']); ?> uploaded yet.</p>
            </div>
            <?php else: ?>
            <div class="divide-y divide-gray-50">
                <?php foreach ($files as $f): ?>
                <div class="flex items-center gap-3 px-6 py-3 hover:bg-gray-50 transition-colors"
                     id="file-<?php echo $f['id']; ?>">
                    <div class="w-8 h-8 rounded-lg bg-<?php echo $tabDef['color']; ?>-100 flex items-center justify-center flex-shrink-0">
                        <i class="fas <?php echo str_contains($f['mimeType'] ?? '', 'image') ? 'fa-image' : $tabDef['icon']; ?>
                                   text-<?php echo $tabDef['color']; ?>-500 text-sm"></i>
                    </div>
                    <div class="flex-1 min-w-0">
                        <p class="text-sm font-medium text-gray-800 truncate">
                            <?php echo htmlspecialchars($f['name']); ?>
                        </p>
                        <p class="text-xs text-gray-400">
                            <?php echo isset($f['size']) ? fmtBytes((int)$f['size']) : ''; ?>
                            &bull; <?php echo date('d M Y', strtotime($f['createdTime'])); ?>
                        </p>
                    </div>
                    <div class="flex items-center gap-1.5 flex-shrink-0">
                        <?php if (!empty($f['webViewLink'])): ?>
                        <a href="<?php echo htmlspecialchars($f['webViewLink']); ?>"
                           target="_blank"
                           class="w-7 h-7 rounded-lg bg-sky-50 hover:bg-sky-100 flex items-center justify-center
                                  text-sky-600 text-xs transition-colors" title="View in Drive">
                            <i class="fas fa-eye"></i>
                        </a>
                        <?php endif; ?>
                        <?php if (!empty($f['webContentLink'])): ?>
                        <a href="<?php echo htmlspecialchars($f['webContentLink']); ?>"
                           class="w-7 h-7 rounded-lg bg-emerald-50 hover:bg-emerald-100 flex items-center justify-center
                                  text-emerald-600 text-xs transition-colors" title="Download">
                            <i class="fas fa-download"></i>
                        </a>
                        <?php endif; ?>
                        <button onclick="deleteFile('<?php echo $f['id']; ?>')"
                                class="w-7 h-7 rounded-lg bg-red-50 hover:bg-red-100 flex items-center justify-center
                                       text-red-400 hover:text-red-600 text-xs transition-colors" title="Delete">
                            <i class="fas fa-trash"></i>
                        </button>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>

    <?php endif; // driveEnabled && !setupError ?>

    <!-- ═══════════════════════════════════════════════════════════
         LOCAL BACKUPS TABLE (always shown — Layer 1 safety net)
    ════════════════════════════════════════════════════════════ -->
    <div class="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between flex-wrap gap-2">
            <h2 class="font-bold text-gray-800 flex items-center gap-2">
                <i class="fas fa-hard-drive text-violet-500"></i>
                Local Disk Backups
                <span class="text-xs font-normal text-gray-400 ml-1">(Layer 1 — always saved even when Drive is broken)</span>
            </h2>
            <span class="text-xs text-gray-400 font-mono truncate max-w-xs" title="<?php echo htmlspecialchars($localDir); ?>">
                <?php echo htmlspecialchars(basename(dirname($localDir)) . '/' . basename($localDir)); ?>
                &bull; keep <?php echo DB_LOCAL_BACKUP_KEEP_DAYS; ?> days
            </span>
        </div>

        <?php if (empty($localBackups)): ?>
        <div class="px-6 py-12 text-center text-gray-400">
            <i class="fas fa-hard-drive text-4xl mb-3 opacity-30"></i>
            <p class="font-medium">No local backups yet.</p>
            <p class="text-sm mt-1">Click <strong>Backup Now</strong> to create the first local snapshot.</p>
        </div>
        <?php else: ?>
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-gray-50 border-b border-gray-100">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">File</th>
                        <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Saved At</th>
                        <th class="px-6 py-3 text-right text-xs font-semibold text-gray-500 uppercase tracking-wider">Size</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-50">
                    <?php foreach (array_slice($localBackups, 0, 20) as $i => $lf): ?>
                    <tr class="hover:bg-gray-50 transition-colors">
                        <td class="px-6 py-3">
                            <div class="flex items-center gap-2.5">
                                <i class="fas fa-file-zipper text-violet-400"></i>
                                <span class="font-mono text-xs text-gray-700 truncate max-w-xs">
                                    <?php echo htmlspecialchars($lf['name']); ?>
                                </span>
                                <?php if ($i === 0): ?>
                                <span class="text-[10px] font-bold bg-violet-100 text-violet-700 px-1.5 py-0.5 rounded-full">latest</span>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td class="px-6 py-3 text-gray-500 whitespace-nowrap">
                            <?php echo date('d M Y, H:i', strtotime($lf['modified'])); ?>
                        </td>
                        <td class="px-6 py-3 text-right text-gray-600 font-medium whitespace-nowrap">
                            <?php echo fmtBytes((int)$lf['size']); ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (count($localBackups) > 20): ?>
                    <tr>
                        <td colspan="3" class="px-6 py-3 text-center text-xs text-gray-400">
                            … and <?php echo count($localBackups) - 20; ?> more files on disk
                        </td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <!-- ═══════════════════════════════════════════════════════════
         CRON SETUP
    ════════════════════════════════════════════════════════════ -->
    <div class="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-100">
            <h2 class="font-bold text-gray-800 flex items-center gap-2">
                <i class="fas fa-terminal text-gray-500"></i>
                Cron Setup — Automated Backups Every 30 Minutes
            </h2>
        </div>
        <div class="p-6 space-y-5">
            <div>
                <p class="text-sm font-semibold text-gray-700 mb-2">Option A — Linux VPS (crontab -e)</p>
                <div class="bg-gray-900 text-green-400 font-mono text-sm rounded-xl p-4 overflow-x-auto">
                    <span class="text-gray-500"># Run backup every 30 minutes, log output</span><br>
                    */30 * * * * /usr/bin/php <?php echo rtrim($_SERVER['DOCUMENT_ROOT'] ?? '/var/www/html', '/'); ?>/scripts/backup_db.php >> /var/log/ufm_backup.log 2>&amp;1
                </div>
            </div>
            <div>
                <p class="text-sm font-semibold text-gray-700 mb-2">Option B — cPanel Cron Jobs</p>
                <div class="bg-gray-50 border border-gray-200 rounded-xl p-4 text-sm space-y-2">
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <span class="text-xs font-semibold text-gray-500 uppercase">Minute</span>
                            <code class="block bg-white border rounded px-2 py-1 mt-1 font-mono">*/30</code>
                        </div>
                        <div>
                            <span class="text-xs font-semibold text-gray-500 uppercase">Hour / Day / Month / Weekday</span>
                            <code class="block bg-white border rounded px-2 py-1 mt-1 font-mono">* * * * *</code>
                        </div>
                    </div>
                    <div>
                        <span class="text-xs font-semibold text-gray-500 uppercase">Command</span>
                        <code class="block bg-white border rounded px-2 py-1 mt-1 font-mono text-xs break-all">
                            /usr/local/bin/php <?php echo rtrim($_SERVER['DOCUMENT_ROOT'] ?? '/home/user/public_html', '/'); ?>/scripts/backup_db.php
                        </code>
                    </div>
                </div>
            </div>
            <div class="flex items-start gap-3 bg-sky-50 border border-sky-100 rounded-xl p-4">
                <i class="fas fa-lightbulb text-sky-500 mt-0.5 flex-shrink-0"></i>
                <p class="text-sm text-sky-800">
                    Test manually first: <code class="bg-sky-100 px-1.5 rounded font-mono text-xs">php scripts/backup_db.php</code>
                    from the project root. You should see <em>SUCCESS</em> in the output before setting up the cron.
                </p>
            </div>
        </div>
    </div>

</div><!-- end max-w container -->

<script>
const CSRF = '<?php echo $csrf; ?>';

// ── Alpine component ─────────────────────────────────────────────────────────
function driveManager() {
    return {
        backing: false,
        toast:   { show: false, ok: true, msg: '' },

        init() {},

        showToast(ok, msg, duration = 5000) {
            this.toast = { show: true, ok, msg };
            setTimeout(() => this.toast.show = false, duration);
        },

        async backupNow() {
            this.backing = true;
            try {
                const fd = new FormData();
                fd.append('action', 'backup_now');
                fd.append('csrf', CSRF);
                const r  = await fetch('', { method: 'POST', body: fd });
                const j  = await r.json();
                this.showToast(j.ok, j.msg, j.ok ? 6000 : 10000);
                if (j.ok) setTimeout(() => location.reload(), 3000);
            } catch(e) {
                this.showToast(false, 'Request failed: ' + e.message);
            } finally {
                this.backing = false;
            }
        }
    };
}

// ── Tab switching ────────────────────────────────────────────────────────────
function switchTab(key) {
    // Hide all panels
    document.querySelectorAll('.tab-panel').forEach(p => p.style.display = 'none');
    // Show the target panel
    const panel = document.getElementById('tab-' + key);
    if (panel) panel.style.display = 'block';

    // Update button styles
    document.querySelectorAll('#fileTabs button[data-tab]').forEach(btn => {
        const isActive = btn.dataset.tab === key;
        // Remove all dynamic classes first
        btn.className = btn.className
            .replace(/border-b-2\s+border-\S+/g, '')
            .replace(/text-\w+-700\s+font-semibold/g, '')
            .replace(/text-gray-500\s+hover:text-gray-700/g, '')
            .trim();
        // Apply correct state classes
        const addClasses = isActive
            ? btn.dataset.activeClass
            : btn.dataset.inactiveClass;
        addClasses.split(' ').forEach(c => { if (c) btn.classList.add(c); });
    });
}

// ── Delete backup ────────────────────────────────────────────────────────────
async function deleteBackup(fileId) {
    if (!confirm('Delete this backup from Google Drive?')) return;
    const fd = new FormData();
    fd.append('action', 'delete_backup');
    fd.append('file_id', fileId);
    fd.append('csrf', CSRF);
    const r  = await fetch('', { method: 'POST', body: fd });
    const j  = await r.json();
    if (j.ok) {
        const row = document.getElementById('backup-' + fileId);
        if (row) row.remove();
    } else {
        alert('Failed: ' + j.msg);
    }
}

// ── Delete file ───────────────────────────────────────────────────────────────
async function deleteFile(fileId) {
    if (!confirm('Delete this file from Google Drive?')) return;
    const fd = new FormData();
    fd.append('action', 'delete_file');
    fd.append('file_id', fileId);
    fd.append('csrf', CSRF);
    const r  = await fetch('', { method: 'POST', body: fd });
    const j  = await r.json();
    if (j.ok) {
        const el = document.getElementById('file-' + fileId);
        if (el) el.remove();
    } else {
        alert('Failed: ' + j.msg);
    }
}

// ── Upload file ───────────────────────────────────────────────────────────────
async function uploadFile(e) {
    e.preventDefault();
    const form = e.target;
    const fileInput = document.getElementById('fileInput');
    if (!fileInput.files.length) { alert('Please choose a file.'); return; }

    const btn = form.querySelector('button[type=submit]');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Uploading…';

    const fd = new FormData(form);
    fd.append('action', 'upload_file');
    fd.append('csrf', CSRF);

    try {
        const r = await fetch('', { method: 'POST', body: fd });
        const j = await r.json();
        if (j.ok) {
            alert('✅ ' + j.msg);
            location.reload();
        } else {
            alert('❌ ' + j.msg);
        }
    } catch(err) {
        alert('Upload error: ' + err.message);
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-cloud-arrow-up"></i> Upload';
    }
}

// ── Initialise first tab on load ─────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => switchTab('receipts'));
</script>

<?php include dirname(__DIR__) . '/templates/footer.php'; ?>