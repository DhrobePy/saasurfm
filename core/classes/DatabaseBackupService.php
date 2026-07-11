<?php
/**
 * DatabaseBackupService — Dumps the UFM ERP database.
 *
 * Strategy (two independent layers):
 *   LAYER 1 — Local disk backup (always runs first, never depends on Drive)
 *   LAYER 2 — Google Drive upload (optional, fails gracefully)
 *
 * This means even if Google Drive is broken/revoked, every backup is still
 * saved to the server's filesystem. Data is never lost due to Drive issues.
 */
class DatabaseBackupService
{
    private ?GoogleDriveService $drive;
    private string $folderId;
    private int    $keepCount;
    private string $localDir;
    private int    $localKeepDays;

    /**
     * @param GoogleDriveService|null $drive          Pass null to disable Drive upload
     * @param string                  $folderId       Drive folder ID for uploads
     * @param int                     $keepCount      Drive: how many backups to keep
     * @param string                  $localDir       Local directory to store backups (required)
     * @param int                     $localKeepDays  Local: delete files older than N days
     */
    public function __construct(
        ?GoogleDriveService $drive,
        string $folderId   = '',
        int    $keepCount  = 500,
        string $localDir   = '',
        int    $localKeepDays = 7
    ) {
        $this->drive         = $drive;
        $this->folderId      = $folderId;
        $this->keepCount     = $keepCount;
        $this->localDir      = rtrim($localDir ?: sys_get_temp_dir() . '/ufm_db_backups', '/');
        $this->localKeepDays = max(1, $localKeepDays);
    }

    // ── Public API ────────────────────────────────────────────────────────────

    /**
     * Run a full backup cycle.
     * Returns a result array with keys:
     *   ok, filename, size_bytes, local_path, drive_id,
     *   local_ok, drive_ok, message, started_at, finished_at
     */
    public function runBackup(): array
    {
        $result = [
            'ok'          => false,
            'filename'    => '',
            'size_bytes'  => 0,
            'local_path'  => '',
            'drive_id'    => '',
            'local_ok'    => false,
            'drive_ok'    => false,
            'message'     => '',
            'warnings'    => [],
            'started_at'  => date('Y-m-d H:i:s'),
            'finished_at' => '',
        ];

        try {
            // ── Dump ─────────────────────────────────────────────────────────
            $sqlGz    = $this->dumpDatabase();
            $filename = DB_NAME . '_' . date('Y-m-d_H-i') . '.sql.gz';
            $result['filename']   = $filename;
            $result['size_bytes'] = strlen($sqlGz);

            // ── LAYER 1: Local backup (always attempted first) ────────────────
            try {
                $localPath = $this->saveLocal($sqlGz, $filename);
                $result['local_path'] = $localPath;
                $result['local_ok']   = true;
                $this->cleanupLocal();
            } catch (Throwable $le) {
                $result['warnings'][] = 'Local backup failed: ' . $le->getMessage();
                $this->notifyFailure('⚠️ LOCAL BACKUP FAILED: ' . $le->getMessage());
            }

            // ── LAYER 2: Drive upload (optional — fail gracefully) ────────────
            if ($this->drive !== null && !empty($this->folderId)) {
                try {
                    $meta = $this->drive->uploadContent(
                        $sqlGz, $filename, 'application/gzip', $this->folderId
                    );
                    $result['drive_id'] = $meta['id'] ?? '';
                    $result['drive_ok'] = !empty($meta['id']);

                    if ($result['drive_ok']) {
                        $deleted = $this->cleanupOldDriveBackups();
                        if ($deleted > 0) {
                            $result['warnings'][] = "Drive: removed $deleted old backup(s).";
                        }
                    } else {
                        $result['warnings'][] = 'Drive: upload returned no file ID.';
                    }
                } catch (Throwable $de) {
                    $result['warnings'][] = 'Drive upload failed: ' . $de->getMessage();
                    // Only alert on Drive failure if local also failed (double failure = critical)
                    if (!$result['local_ok']) {
                        $this->notifyFailure(
                            '🔴 CRITICAL — Both local AND Drive backup failed!' . "\n" .
                            'Drive error: ' . $de->getMessage()
                        );
                    } else {
                        $this->notifyFailure(
                            '⚠️ Drive backup failed (local copy IS saved): ' . $de->getMessage()
                        );
                    }
                }
            } else {
                $result['warnings'][] = 'Drive upload skipped (not configured).';
            }

            // Overall success = at least the local backup worked
            $result['ok'] = $result['local_ok'];
            $size = $this->formatBytes($result['size_bytes']);

            if ($result['local_ok'] && $result['drive_ok']) {
                $result['message'] = "Backup saved locally + uploaded to Drive: $filename ($size)";
            } elseif ($result['local_ok']) {
                $result['message'] = "Backup saved locally (Drive skipped): $filename ($size)";
            } else {
                $result['message'] = "Backup FAILED: $filename — " . implode('; ', $result['warnings']);
                $this->notifyFailure('🔴 DB Backup FAILED (no local copy): ' . $result['message']);
            }

        } catch (Throwable $e) {
            $result['ok']      = false;
            $result['message'] = 'Backup failed: ' . $e->getMessage();
            $this->notifyFailure('🔴 DB Backup exception: ' . $e->getMessage());
        }

        $result['finished_at'] = date('Y-m-d H:i:s');
        return $result;
    }

    /**
     * List all backup files on Drive (newest first).
     */
    public function listBackups(int $limit = 100): array
    {
        if (!$this->drive) return [];
        return $this->drive->listFiles($this->folderId, $limit);
    }

    /**
     * List local backup files, newest first.
     * Returns array of ['name', 'path', 'size', 'modified'] per file.
     */
    public function listLocalBackups(): array
    {
        if (!is_dir($this->localDir)) return [];

        $files = glob($this->localDir . '/*.sql.gz') ?: [];
        $list  = [];
        foreach ($files as $f) {
            $list[] = [
                'name'     => basename($f),
                'path'     => $f,
                'size'     => filesize($f),
                'modified' => date('Y-m-d H:i:s', filemtime($f)),
            ];
        }
        usort($list, fn($a, $b) => strcmp($b['modified'], $a['modified']));
        return $list;
    }

    /**
     * Delete a specific Drive backup by file ID.
     */
    public function deleteBackup(string $fileId): bool
    {
        if (!$this->drive) return false;
        return $this->drive->deleteFile($fileId);
    }

    /**
     * Delete oldest Drive backups beyond $this->keepCount.
     */
    public function cleanupOldDriveBackups(): int
    {
        if (!$this->drive) return 0;
        $files   = $this->drive->listFiles($this->folderId, 1000);
        $deleted = 0;
        if (count($files) > $this->keepCount) {
            foreach (array_slice($files, $this->keepCount) as $file) {
                if ($this->drive->deleteFile($file['id'])) $deleted++;
            }
        }
        return $deleted;
    }

    // ── Local backup helpers ──────────────────────────────────────────────────

    private function saveLocal(string $content, string $filename): string
    {
        if (!is_dir($this->localDir)) {
            if (!mkdir($this->localDir, 0750, true)) {
                throw new RuntimeException("Cannot create local backup dir: {$this->localDir}");
            }
        }

        $path = $this->localDir . '/' . $filename;
        if (file_put_contents($path, $content, LOCK_EX) === false) {
            throw new RuntimeException("Cannot write local backup: $path");
        }
        chmod($path, 0640);
        return $path;
    }

    private function cleanupLocal(): void
    {
        $cutoff = time() - ($this->localKeepDays * 86400);
        foreach (glob($this->localDir . '/*.sql.gz') ?: [] as $file) {
            if (filemtime($file) < $cutoff) {
                @unlink($file);
            }
        }
    }

    // ── Database dump ─────────────────────────────────────────────────────────

    private function dumpDatabase(): string
    {
        if ($this->mysqldumpAvailable()) {
            return $this->dumpViaMysqldump();
        }
        return $this->dumpViaPDO();
    }

    private function mysqldumpAvailable(): bool
    {
        $test = shell_exec('mysqldump --version 2>&1');
        return $test !== null && stripos($test, 'mysqldump') !== false;
    }

    private function dumpViaMysqldump(): string
    {
        $cnfFile = tempnam(sys_get_temp_dir(), 'ufm_my_') . '.cnf';
        file_put_contents($cnfFile,
            "[client]\nhost=" . DB_HOST .
            "\nuser=" . DB_USER .
            "\npassword=" . DB_PASS . "\n",
            LOCK_EX
        );
        chmod($cnfFile, 0600);

        $cmd = sprintf(
            'mysqldump --defaults-extra-file=%s --single-transaction --routines --triggers --events %s 2>&1',
            escapeshellarg($cnfFile),
            escapeshellarg(DB_NAME)
        );

        $sql = shell_exec($cmd);
        unlink($cnfFile);

        if (empty($sql) || (stripos($sql, 'error') !== false && strlen($sql) < 500)) {
            throw new RuntimeException("mysqldump error: $sql");
        }

        $compressed = gzencode($sql, 6);
        if ($compressed === false) throw new RuntimeException('gzip compression failed.');
        return $compressed;
    }

    private function dumpViaPDO(): string
    {
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER, DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );

        $sql  = "-- UFM ERP Database Backup\n";
        $sql .= "-- Generated: " . date('Y-m-d H:i:s') . " (Asia/Dhaka)\n";
        $sql .= "-- Server: " . DB_HOST . "  DB: " . DB_NAME . "\n\n";
        $sql .= "SET FOREIGN_KEY_CHECKS=0;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n\n";

        $tables = $pdo->query("SHOW FULL TABLES WHERE Table_type='BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($tables as $table) {
            $create = $pdo->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_NUM);
            $sql   .= "\n-- Table: `$table`\nDROP TABLE IF EXISTS `$table`;\n";
            $sql   .= $create[1] . ";\n\n";

            $rows = $pdo->query("SELECT * FROM `$table`")->fetchAll(PDO::FETCH_ASSOC);
            if (empty($rows)) continue;

            $cols    = '`' . implode('`, `', array_keys($rows[0])) . '`';
            foreach (array_chunk($rows, 500) as $batch) {
                $vals = [];
                foreach ($batch as $row) {
                    $escaped = array_map(fn($v) =>
                        $v === null ? 'NULL' : $pdo->quote((string)$v), $row);
                    $vals[] = '(' . implode(', ', $escaped) . ')';
                }
                $sql .= "INSERT INTO `$table` ($cols) VALUES\n" .
                        implode(",\n", $vals) . ";\n";
            }
        }

        $sql .= "\nSET FOREIGN_KEY_CHECKS=1;\n";

        $views = $pdo->query("SHOW FULL TABLES WHERE Table_type='VIEW'")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($views as $view) {
            $row = $pdo->query("SHOW CREATE VIEW `$view`")->fetch(PDO::FETCH_ASSOC);
            $createView = preg_replace('/\s+DEFINER\s*=\s*`[^`]+`@`[^`]+`/', '', $row['Create View'] ?? '');
            $sql .= "\n-- View: `$view`\nDROP VIEW IF EXISTS `$view`;\n";
            $sql .= $createView . ";\n\n";
        }

        $compressed = gzencode($sql, 6);
        if ($compressed === false) throw new RuntimeException('gzip compression failed.');
        return $compressed;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1_048_576) return round($bytes / 1_048_576, 2) . ' MB';
        if ($bytes >= 1_024)    return round($bytes / 1_024, 1) . ' KB';
        return $bytes . ' B';
    }

    private function notifyFailure(string $message): void
    {
        if (!defined('TELEGRAM_NOTIFICATIONS_ENABLED') || !TELEGRAM_NOTIFICATIONS_ENABLED) return;
        if (!defined('TELEGRAM_BOT_TOKEN') || !TELEGRAM_BOT_TOKEN) return;
        try {
            $notifier = new TelegramNotifier(TELEGRAM_BOT_TOKEN, TELEGRAM_CHAT_ID);
            $notifier->sendMessage("<b>UFM ERP — Backup Alert</b>\n" . $message);
        } catch (Throwable) { /* never block the backup */ }
    }
}