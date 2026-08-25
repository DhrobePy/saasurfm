<?php
/**
 * Floating team chat — AJAX JSON endpoint.
 * Team broadcast channel (peer_id 0) + 1:1 DMs. Polling-based (no websocket
 * needed on shared hosting). Available to any logged-in user — chat is a
 * cross-cutting utility, not gated by module privileges.
 */
require_once '../core/init.php';
header('Content-Type: application/json');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not logged in']);
    exit();
}

global $db;
$user_id = (int)$_SESSION['user_id'];

/* ─── Self-migrating schema (CREATE TABLE IF NOT EXISTS only) ─────────────── */
$pdo = $db->getPdo();
$pdo->exec("
    CREATE TABLE IF NOT EXISTS `chat_messages` (
      `id`            bigint UNSIGNED NOT NULL AUTO_INCREMENT,
      `sender_id`     bigint UNSIGNED NOT NULL,
      `recipient_id`  bigint UNSIGNED DEFAULT NULL COMMENT 'NULL = team broadcast channel',
      `body`          text NOT NULL,
      `created_at`    timestamp NOT NULL DEFAULT current_timestamp(),
      PRIMARY KEY (`id`),
      KEY `idx_dm` (`sender_id`, `recipient_id`, `id`),
      KEY `idx_recipient` (`recipient_id`, `id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
$pdo->exec("
    CREATE TABLE IF NOT EXISTS `chat_reads` (
      `user_id`               bigint UNSIGNED NOT NULL,
      `peer_id`                bigint UNSIGNED NOT NULL DEFAULT 0 COMMENT '0 = team channel, else the DM peer user id',
      `last_read_message_id`  bigint UNSIGNED NOT NULL DEFAULT 0,
      `updated_at`             timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
      PRIMARY KEY (`user_id`, `peer_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
$pdo->exec("
    CREATE TABLE IF NOT EXISTS `chat_presence` (
      `user_id`         bigint UNSIGNED NOT NULL,
      `last_active_at`  datetime NOT NULL,
      PRIMARY KEY (`user_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

/* ─── CSRF (all POST-style actions that write data) ────────────────────────── */
function chat_verify_csrf(): void {
    $sess_tok = $_SESSION['csrf_token'] ?? '';
    $recv_tok = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? '';
    if (!$sess_tok || !$recv_tok || !hash_equals($sess_tok, $recv_tok)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Invalid security token.']);
        exit();
    }
}

$action = $_REQUEST['action'] ?? '';

try {
    switch ($action) {

        /* ── Heartbeat: mark this user online ─────────────────────────────── */
        case 'heartbeat': {
            $db->query(
                "INSERT INTO chat_presence (user_id, last_active_at) VALUES (?, NOW())
                 ON DUPLICATE KEY UPDATE last_active_at = NOW()",
                [$user_id]
            );
            echo json_encode(['success' => true]);
            break;
        }

        /* ── Combined poll: heartbeat + unread badge in ONE request ────────── */
        case 'poll': {
            $db->query(
                "INSERT INTO chat_presence (user_id, last_active_at) VALUES (?, NOW())
                 ON DUPLICATE KEY UPDATE last_active_at = NOW()",
                [$user_id]
            );
            $team = $db->query(
                "SELECT COUNT(*) c FROM chat_messages cm
                 LEFT JOIN chat_reads cr ON cr.user_id = ? AND cr.peer_id = 0
                 WHERE cm.recipient_id IS NULL AND cm.sender_id != ?
                   AND cm.id > COALESCE(cr.last_read_message_id, 0)",
                [$user_id, $user_id]
            )->first()->c ?? 0;
            $dm = $db->query(
                "SELECT COUNT(*) c FROM chat_messages cm
                 LEFT JOIN chat_reads cr ON cr.user_id = ? AND cr.peer_id = cm.sender_id
                 WHERE cm.recipient_id = ?
                   AND cm.id > COALESCE(cr.last_read_message_id, 0)",
                [$user_id, $user_id]
            )->first()->c ?? 0;
            echo json_encode(['success' => true, 'unread' => (int)$team + (int)$dm]);
            break;
        }

        /* ── Lightweight unread total for the closed bubble badge ─────────── */
        case 'unread_total': {
            $team = $db->query(
                "SELECT COUNT(*) c FROM chat_messages cm
                 LEFT JOIN chat_reads cr ON cr.user_id = ? AND cr.peer_id = 0
                 WHERE cm.recipient_id IS NULL AND cm.sender_id != ?
                   AND cm.id > COALESCE(cr.last_read_message_id, 0)",
                [$user_id, $user_id]
            )->first()->c ?? 0;

            $dm = $db->query(
                "SELECT COUNT(*) c FROM chat_messages cm
                 LEFT JOIN chat_reads cr ON cr.user_id = ? AND cr.peer_id = cm.sender_id
                 WHERE cm.recipient_id = ?
                   AND cm.id > COALESCE(cr.last_read_message_id, 0)",
                [$user_id, $user_id]
            )->first()->c ?? 0;

            echo json_encode(['success' => true, 'unread' => (int)$team + (int)$dm]);
            break;
        }

        /* ── Conversation list: team channel + every active user with
               unread count, online status, and last message preview ───────── */
        case 'list_conversations': {
            $users = $db->query(
                "SELECT id, display_name, role FROM users
                 WHERE status = 'active' AND id != ?
                 ORDER BY display_name ASC",
                [$user_id]
            )->results();

            // DM unread counts, one query for all peers
            $dm_unread = [];
            $rows = $db->query(
                "SELECT cm.sender_id AS peer_id, COUNT(*) AS unread
                 FROM chat_messages cm
                 LEFT JOIN chat_reads cr ON cr.user_id = ? AND cr.peer_id = cm.sender_id
                 WHERE cm.recipient_id = ?
                   AND cm.id > COALESCE(cr.last_read_message_id, 0)
                 GROUP BY cm.sender_id",
                [$user_id, $user_id]
            )->results();
            foreach ($rows as $r) $dm_unread[(int)$r->peer_id] = (int)$r->unread;

            // Online set — ONE query for everyone (not per user)
            $online_ids = [];
            foreach ($db->query(
                "SELECT user_id FROM chat_presence WHERE last_active_at >= (NOW() - INTERVAL 90 SECOND)"
            )->results() as $p) {
                $online_ids[(int)$p->user_id] = true;
            }

            // Latest DM per peer — ONE query using max-id per conversation pair
            $last_by_peer = [];
            foreach ($db->query(
                "SELECT cm.sender_id, cm.recipient_id, cm.body, cm.created_at
                 FROM chat_messages cm
                 JOIN (
                     SELECT MAX(id) AS mid
                     FROM chat_messages
                     WHERE recipient_id IS NOT NULL AND (sender_id = ? OR recipient_id = ?)
                     GROUP BY LEAST(sender_id, recipient_id), GREATEST(sender_id, recipient_id)
                 ) t ON t.mid = cm.id",
                [$user_id, $user_id]
            )->results() as $lm) {
                $peer_key = (int)$lm->sender_id === $user_id ? (int)$lm->recipient_id : (int)$lm->sender_id;
                $last_by_peer[$peer_key] = $lm;
            }

            $conversations = [];
            foreach ($users as $u) {
                $last = $last_by_peer[(int)$u->id] ?? null;
                $conversations[] = [
                    'peer_id'      => (int)$u->id,
                    'name'         => $u->display_name,
                    'role'         => $u->role,
                    'online'       => isset($online_ids[(int)$u->id]),
                    'unread'       => $dm_unread[(int)$u->id] ?? 0,
                    'last_body'    => $last->body ?? null,
                    'last_at'      => $last->created_at ?? null,
                    'last_mine'    => $last ? ((int)$last->sender_id === $user_id) : false,
                ];
            }

            // Team channel unread + last message
            $team_unread = $db->query(
                "SELECT COUNT(*) c FROM chat_messages cm
                 LEFT JOIN chat_reads cr ON cr.user_id = ? AND cr.peer_id = 0
                 WHERE cm.recipient_id IS NULL AND cm.sender_id != ?
                   AND cm.id > COALESCE(cr.last_read_message_id, 0)",
                [$user_id, $user_id]
            )->first()->c ?? 0;
            $team_last = $db->query(
                "SELECT cm.body, cm.created_at, u.display_name AS sender_name
                 FROM chat_messages cm LEFT JOIN users u ON u.id = cm.sender_id
                 WHERE cm.recipient_id IS NULL ORDER BY cm.id DESC LIMIT 1"
            )->first();

            echo json_encode([
                'success' => true,
                'team' => [
                    'unread'      => (int)$team_unread,
                    'last_body'   => $team_last->body ?? null,
                    'last_at'     => $team_last->created_at ?? null,
                    'last_sender' => $team_last->sender_name ?? null,
                ],
                'conversations' => $conversations,
            ]);
            break;
        }

        /* ── Fetch messages for a conversation, optionally only newer ones ─── */
        case 'fetch_messages': {
            $type    = $_GET['type'] ?? 'team';
            $peer    = (int)($_GET['peer_id'] ?? 0);
            $after   = (int)($_GET['after_id'] ?? 0);

            // Initial load must return the LATEST 50 (DESC + reverse), not the
            // oldest 50 — incremental polls then walk forward from last seen id.
            $order = $after > 0 ? 'ASC' : 'DESC';
            $limit = $after > 0 ? 200 : 50;

            if ($type === 'team') {
                $rows = $db->query(
                    "SELECT cm.id, cm.sender_id, cm.body, cm.created_at, u.display_name AS sender_name
                     FROM chat_messages cm LEFT JOIN users u ON u.id = cm.sender_id
                     WHERE cm.recipient_id IS NULL AND cm.id > ?
                     ORDER BY cm.id $order LIMIT $limit",
                    [$after]
                )->results();
            } else {
                if ($peer <= 0) throw new Exception('Invalid conversation.');
                $rows = $db->query(
                    "SELECT cm.id, cm.sender_id, cm.body, cm.created_at, u.display_name AS sender_name
                     FROM chat_messages cm LEFT JOIN users u ON u.id = cm.sender_id
                     WHERE ((cm.sender_id = ? AND cm.recipient_id = ?) OR (cm.sender_id = ? AND cm.recipient_id = ?))
                       AND cm.id > ?
                     ORDER BY cm.id $order LIMIT $limit",
                    [$user_id, $peer, $peer, $user_id, $after]
                )->results();
            }
            if ($order === 'DESC') $rows = array_reverse($rows);

            $messages = array_map(fn($r) => [
                'id'          => (int)$r->id,
                'sender_id'   => (int)$r->sender_id,
                'sender_name' => $r->sender_name ?? 'User',
                'body'        => $r->body,
                'mine'        => (int)$r->sender_id === $user_id,
                // Formatted server-side so browser/server timezone mismatch
                // can't shift the displayed time
                'at_fmt'      => date('d M, H:i', strtotime($r->created_at)),
            ], $rows);

            echo json_encode(['success' => true, 'messages' => $messages]);
            break;
        }

        /* ── Send a message ────────────────────────────────────────────────── */
        case 'send_message': {
            chat_verify_csrf();
            $type = $_POST['type'] ?? 'team';
            $peer = (int)($_POST['peer_id'] ?? 0);
            $body = trim($_POST['body'] ?? '');

            if ($body === '') throw new Exception('Message cannot be empty.');
            if (mb_strlen($body) > 4000) throw new Exception('Message is too long.');

            $recipient_id = null;
            if ($type === 'dm') {
                if ($peer <= 0) throw new Exception('Invalid recipient.');
                $exists = $db->query("SELECT id FROM users WHERE id = ? AND status = 'active'", [$peer])->first();
                if (!$exists) throw new Exception('Recipient not found.');
                $recipient_id = $peer;
            }

            $msg_id = $db->insert('chat_messages', [
                'sender_id'    => $user_id,
                'recipient_id' => $recipient_id,
                'body'         => $body,
            ]);
            if (!$msg_id) throw new Exception('Failed to save message — please try again.');

            // Sender's own message is immediately "read" on their side
            $db->query(
                "INSERT INTO chat_reads (user_id, peer_id, last_read_message_id) VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE last_read_message_id = GREATEST(last_read_message_id, ?)",
                [$user_id, $recipient_id ?? 0, $msg_id, $msg_id]
            );

            echo json_encode(['success' => true, 'id' => (int)$msg_id]);
            break;
        }

        /* ── Mark a conversation as read up to the latest message ──────────── */
        case 'mark_read': {
            chat_verify_csrf();
            $type = $_POST['type'] ?? 'team';
            $peer = (int)($_POST['peer_id'] ?? 0);

            if ($type === 'team') {
                $max = $db->query("SELECT COALESCE(MAX(id),0) m FROM chat_messages WHERE recipient_id IS NULL")->first()->m;
                $db->query(
                    "INSERT INTO chat_reads (user_id, peer_id, last_read_message_id) VALUES (?, 0, ?)
                     ON DUPLICATE KEY UPDATE last_read_message_id = GREATEST(last_read_message_id, ?)",
                    [$user_id, $max, $max]
                );
            } else {
                if ($peer <= 0) throw new Exception('Invalid conversation.');
                $max = $db->query(
                    "SELECT COALESCE(MAX(id),0) m FROM chat_messages WHERE sender_id = ? AND recipient_id = ?",
                    [$peer, $user_id]
                )->first()->m;
                $db->query(
                    "INSERT INTO chat_reads (user_id, peer_id, last_read_message_id) VALUES (?, ?, ?)
                     ON DUPLICATE KEY UPDATE last_read_message_id = GREATEST(last_read_message_id, ?)",
                    [$user_id, $peer, $max, $max]
                );
            }
            echo json_encode(['success' => true]);
            break;
        }

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Unknown action.']);
    }
} catch (Exception $e) {
    error_log('chat_api error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}