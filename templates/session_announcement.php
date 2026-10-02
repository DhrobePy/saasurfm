<?php
/**
 * One-time "what's new" popup for specific users (Adnan, Dhrobe) summarizing
 * a whole session's worth of changes, in Banglish. Included from footer.php
 * for every logged-in user (cross-cutting utility, like chat_widget.php) —
 * this file itself decides whether to actually render anything.
 *
 * Shown on every page load until the user closes it once; dismissal is
 * recorded server-side per user (user_dismissed_announcements), so it never
 * reappears after being closed — on any device/browser, not just this one.
 * To reuse this pattern for a future update, add a new $announcement_key
 * and rewrite the content below; old dismissals for the old key are
 * harmless and stay in the table as history.
 */
if (!isLoggedIn()) return;

global $db;
try {
    $db->getPdo()->exec("
        CREATE TABLE IF NOT EXISTS `user_dismissed_announcements` (
          `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          `user_id` BIGINT UNSIGNED NOT NULL,
          `announcement_key` VARCHAR(100) NOT NULL,
          `dismissed_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          UNIQUE KEY `uk_user_announcement` (`user_id`, `announcement_key`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
} catch (\Throwable $e) { return; }

$announcement_key   = 'session_update_2026_09_08';
$announcement_users = [2, 17]; // Adnan Ilias Siddique, Dhrobe

$sa_user  = getCurrentUser();
$sa_my_id = (int)($sa_user['id'] ?? 0);
if (!in_array($sa_my_id, $announcement_users, true)) return;

try {
    $sa_dismissed = $db->query(
        "SELECT id FROM user_dismissed_announcements WHERE user_id = ? AND announcement_key = ?",
        [$sa_my_id, $announcement_key]
    )->first();
} catch (\Throwable $e) { return; }
if ($sa_dismissed) return;
?>
<style>
#saOverlay {
    position: fixed; inset: 0; z-index: 9998;
    background: rgba(15, 23, 42, .55);
    display: flex; align-items: center; justify-content: center;
    padding: 16px;
}
#saOverlay.hidden { display: none !important; }
#saBox {
    background: #fff; border-radius: 16px; max-width: 640px; width: 100%;
    max-height: 88vh; display: flex; flex-direction: column; overflow: hidden;
    box-shadow: 0 20px 60px rgba(0,0,0,.35);
}
#saBox .sa-head {
    background: linear-gradient(135deg,#0284c7,#0369a1); color: #fff;
    padding: 16px 20px; display: flex; align-items: center; gap: 10px; flex-shrink: 0;
}
#saBox .sa-head .sa-title { font-weight: 700; font-size: 16px; flex: 1; }
#saBox .sa-head button {
    background: rgba(255,255,255,.15); border: none; color: #fff; cursor: pointer;
    width: 30px; height: 30px; border-radius: 8px; font-size: 15px;
}
#saBox .sa-head button:hover { background: rgba(255,255,255,.28); }
#saBody { padding: 18px 22px; overflow-y: auto; font-size: 13.5px; line-height: 1.65; color: #1f2937; }
#saBody h3 {
    font-size: 13px; font-weight: 700; color: #0369a1; margin: 16px 0 6px;
    text-transform: uppercase; letter-spacing: .02em;
}
#saBody h3:first-child { margin-top: 0; }
#saBody ul { margin: 0 0 4px 18px; padding: 0; }
#saBody li { margin-bottom: 6px; }
#saFoot {
    padding: 12px 22px; border-top: 1px solid #e5e7eb; flex-shrink: 0;
    display: flex; justify-content: flex-end;
}
#saFoot button {
    background: #0284c7; color: #fff; border: none; padding: 9px 20px;
    border-radius: 8px; font-weight: 600; font-size: 13.5px; cursor: pointer;
}
#saFoot button:hover { background: #0369a1; }
</style>

<div id="saOverlay">
  <div id="saBox">
    <div class="sa-head">
      <i class="fas fa-bullhorn"></i>
      <span class="sa-title">Ei session-e ja ja change hoise (summary)</span>
      <button type="button" id="saCloseBtnTop" aria-label="Close">✕</button>
    </div>
    <div id="saBody">
      <p>Assalamu Alaikum / Hi — ei session-e ERP-te onek gulo kaj kora hoise. Ekhane shob change-er ekta summary deya holo, jate apnara janen ki ki update hoise:</p>

      <h3>1. Access &amp; Branch-wise Data</h3>
      <ul>
        <li>Different dashboard/report page-e branch-wise data scoping thik kora hoise — user shudhu nijer branch-er data dekhbe (Access Manager-e je scope set kora ache shei onujayi).</li>
        <li>Access Manager, Privileges, r Role Access Matrix — puro system-ta full test kore confirm kora hoise sob thik moto kaj korche (save, clear, inline limits, enforcement — shob check kora hoise).</li>
        <li>Production Manager (Demra)-ke shob 16 ta module-e full access deya hoise Access Manager die.</li>
        <li>Delivery Schedule page-r "Edit" option age Admin/Superadmin-only chilo — akhon je karo ke customize kore dite parben (delegable kora hoise).</li>
      </ul>

      <h3>2. Bank Account Dropdown — Standard Kora Hoise</h3>
      <ul>
        <li>App-er shob jaigay (Bank, Expense, Purchase, Credit Sales, Loans, Trading — shob module-e) bank account dropdown/list-e akhon Bank Name + Account Name + Account Number sobai dekhte parbe.</li>
        <li>Balance (taka-r poriman) shudhu Superadmin/Admin, ba je take specifically permission deya hoyeche, shey dekhbe — normal user dekhbe na.</li>
        <li>Erokom kaj korte giye 2 ta real bug pawa gese ar thik kora hoise — kichu jaigay bank account-er bodole bhul table theke data ashtechilo (receipt-e bank details show hocchilo na thik moto).</li>
      </ul>

      <h3>3. Pricing Engine — Notun Option</h3>
      <ul>
        <li>Age product-er price change korle, already-placed order-e kono effect porto na (bhul na, eta e-i design chilo — customer-er bill hঠাৎ change hoye jaoa risky bole).</li>
        <li>Akhon Admin Settings-e ekta notun Toggle add kora hoise: <strong>ON korle</strong>, Pricing Engine-e price change korle — ja ja order ekhono deliver hoyni, shegulo-r price/total automatic update hoye jabe, r invoice-e history hishebe thakbe. <strong>OFF thakle</strong> age jemon chilo temon-i thakbe (manual review lagbe).</li>
        <li>Ei setting ekhon <strong>ON</strong> kora ache (live test kore confirm kora hoise thik moto kaj korche).</li>
      </ul>

      <h3>4. Audit Trail Page</h3>
      <ul>
        <li>Description column age onek chotto chilo, puro text dekha jeto na — akhon full text dekha jabe (wrap hoye jabe, cut hobe na).</li>
        <li>Statistics Cards (Total Actions, Active Users, etc.) remove kora hoise, jehetu dorkar nai bole bola hoyechilo.</li>
      </ul>

      <h3>5. Delivered Order Amendment — Boro Feature</h3>
      <ul>
        <li>Age delivered order-e sudhu ekta flat taka-r amount (add/subtract) change kora jeto — kono specific item-er quantity/price shorashori change kora jeto na.</li>
        <li>Akhon delivered order-eo specific item-er quantity/price directly correct kora jabe (pre-dispatch order-er moto), r shathe shathe customer ledger-e shothik entry hoye jabe.</li>
        <li>Customer Consent mandatory kora hoise — customer raji ache kina, kivabe/kokhon consent neya hoyeche, shei note likhte hobe change korar age.</li>
        <li>Shob change invoice-e dekhabe — kon product-er quantity/price ki chilo, ki hoyeche (old → new), r customer consent note-o dekhabe.</li>
        <li><strong>Original order/invoice date kokhono change hobe na</strong> — shudhu amendment-er nijer date (kobe change kora hoyeche) alada kore track hoy.</li>
        <li>Order View page-e notun "Amendment History" card add kora hoise — shob change ekhane dekha jabe.</li>
      </ul>

      <p style="margin-top:14px;color:#6b7280;font-size:12px;">Eita ekbar-i pop-up hobe. Close korle r kokhono ashbe na.</p>
    </div>
    <div id="saFoot">
      <button type="button" id="saCloseBtnBottom">Bujhechi, Close Koro</button>
    </div>
  </div>
</div>

<script>
(function () {
    const overlay = document.getElementById('saOverlay');
    const CSRF    = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const API     = '<?php echo url("api/dismiss_announcement.php"); ?>';
    const KEY     = '<?php echo $announcement_key; ?>';

    function dismiss() {
        overlay.classList.add('hidden');
        const fd = new FormData();
        fd.append('announcement_key', KEY);
        fetch(API, { method: 'POST', headers: { 'X-CSRF-Token': CSRF }, body: fd }).catch(() => {});
    }

    document.getElementById('saCloseBtnTop')?.addEventListener('click', dismiss);
    document.getElementById('saCloseBtnBottom')?.addEventListener('click', dismiss);
})();
</script>
