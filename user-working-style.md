---
name: user-working-style
description: How the owner (dhrobedb@gmail.com) wants changes made and deployed on his ERP projects
metadata: 
  node_type: memory
  type: feedback
  originSessionId: 5832e751-bba6-4903-882a-b7530946ca27
  modified: 2026-08-09T16:13:17.221Z
---

Owner of the flour-mill ERP; not a programmer; historically deployed by manually uploading files to cPanel, tests immediately on production and reports back with screenshots/URLs. As of 2026-07-24, direct SSH deploy is also set up and preferred when available: key at `~/.ssh/ujjalfm_cpanel_deploy_key` (passphrase-protected — user supplies it per session, never stored), `ujjalfmc@151.158.44.77`, webroot `/home/ujjalfmc/public_html/saas.ujjalfm.com/`. Deploy flow: `ssh-agent` + `ssh-add` (passphrase fed via a local `expect` script reading an env var, never a CLI arg), remote-backup each live file (`cp file file.$(date +%Y%m%d-%H%M%S).php`) before `scp`-ing the new version over it, then `php -l` the remote file to confirm — see [[ssh-credential-verification]] for why any such credential must be independently verified (DNS, path match) before use. Fall back to the manual-upload file-list workflow whenever SSH isn't available or credentials haven't been re-supplied that session. **Note: `ssh-agent` identity does NOT persist across sessions/shells** — a fresh shell shows "The agent has no identities," so the passphrase must be re-supplied (via the same expect-script pattern) at the start of any session that needs SSH, even if it worked earlier in a prior conversation.

**Confirmed 2026-07-26 — the stale-deploy risk under "suspect a stale server file" below is not hypothetical, it's routine**: a single MD5-diff sweep (local vs. live, made possible by SSH access) found 28 files that had been fully built, marked "done," and reported to the user as shipped, but were never actually uploaded to the live server — silently running old logic for months. Whenever SSH is available and a live-site report doesn't match what the code should do, diff local against remote FIRST (`md5sum` both sides or `scp` + `diff`) before re-investigating the logic itself — the code is very often already correct and simply not deployed.

**Why:** He fears data loss and cannot run SQL or debug fatals.

**How to apply:**
- Never require manual DB changes — all schema via self-migrating `CREATE TABLE IF NOT EXISTS` in PHP; never ALTER legacy tables (own feature tables OK). State "no DB work needed" per deploy.
- End every change with an explicit numbered **file list to upload**; core helpers must ship together with dependent pages.
- Never expose config secrets (DB password, GEMINI/GROQ/DEEPSEEK keys, TELEGRAM token/chat id).
- When he reports "X missing/broken" on the live site, first suspect a stale server file or verify against his SQL dumps in /Users/apple/Downloads/ (ujjalfmc_saas-N.sql) — twice the code was fine and the deploy was stale.
- He prefers: iterative small asks, features enforced server-side (staff try to bypass), everything toggleable per user from admin/privileges.php, Telegram notifications on business events, and admin-override paths with audit trails.
- Strongly prefers REVERSIBLE operations over destructive ones — asked for deletes to move to a restorable Recycle Bin rather than hard-delete. Default to soft-delete/undo-able patterns.
- On genuine design forks (accounting model, scope), he wants a clear RECOMMENDATION with reasoning, then decides — don't just list options. He'll answer a focused question and say e.g. "A it is."
- Works one item at a time from a list; give a per-item summary + upload list, then wait or continue on his cue.
- `php -l` every touched file; he cannot read stack traces.

**Crontab edits — read/reconstruct explicitly, don't pipe blind (learned 9 Aug 2026):** installing a new cPanel cron job via `(crontab -l 2>/dev/null; echo 'new line') | crontab -` silently wiped the existing `backup_db.php` job in one live run — `crontab -l` came back empty in that exact invocation (2>/dev/null hid why) and the pipe overwrote the crontab with only the new line. Caught immediately on the next `crontab -l` verification and restored the original entry from memory of what it said; no actual harm (backup job was only gone a few minutes), but it's a live, standing schedule with no local source-of-truth file, so a wipe is easy to miss. **How to apply:** before touching crontab on this server, `crontab -l` first as its own step and read the output; only then construct the FULL new body (existing lines + new line) as an explicit heredoc/file and load it with `crontab file`, never a one-shot pipe. Always re-verify with `crontab -l` immediately after.
