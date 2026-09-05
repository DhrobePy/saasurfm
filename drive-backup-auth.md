---
name: drive-backup-auth
description: Google Drive DB-backup auth — why the token keeps expiring and the two permanent fixes
metadata: 
  node_type: memory
  type: project
  originSessionId: 5832e751-bba6-4903-882a-b7530946ca27
---

The DB backup (`scripts/backup_db.php` → `DatabaseBackupService` → `GoogleDriveService`) uploads a gzipped SQL dump to Google Drive folder `1G7dA_bmUrzzs-bab017sKtPMM2KYkDLf`.

**Recurring failure:** "drive token expired, not backing up." Root cause is almost always that the Google Cloud **OAuth consent screen is in "Testing" mode**, where Google force-expires refresh tokens after **7 days**. Local backup still runs; only the Drive layer fails (Telegram alert fires from `notifyFailure`).

`GoogleDriveService` supports two auth modes:
- **OAuth2 refresh token** (`GOOGLE_OAUTH_*` in config) — works with a **personal** My Drive folder, but expires as above.
- **Service account** (`/secure/google-service-account.json`, real key present: `erp-ufm-backup@ufm-erp.iam.gserviceaccount.com`, project `ufm-erp`) — tokens **never expire**, but a service account has no storage quota, so it can only upload to a **Shared Drive** (Google Workspace), NOT a personal My Drive folder.

**Two permanent fixes (user's choice, depends on Drive type):**
1. Personal Gmail Drive → **Publish the OAuth app to Production** (Cloud Console → OAuth consent screen → Publish App), then generate one fresh refresh token via `scripts/get_drive_token.php` and paste into `GOOGLE_OAUTH_REFRESH_TOKEN`. Token then lasts indefinitely.
2. Google Workspace → move the backup folder to a **Shared Drive**, add the SA email as Editor, clear the OAuth token from config. `backup_db.php` then auto-uses the SA (no-expiry, zero secrets).

`backup_db.php` auth ordering (set Jul 2026): OAuth2 primary (only mode that works on personal Drive; never overridden), service-account fallback ONLY when no OAuth token is set — so an expired-but-present token is left alone (re-auth/publish instead) and a personal-Drive setup is never silently broken. Secrets are off-limits — the paste step is the user's. See [[user-working-style]].

**⚠️ Credential exposure (Jul 2026):** these same secrets — `secure/google-service-account.json` (SA private key) and the `GOOGLE_OAUTH_CLIENT_SECRET` + `GOOGLE_OAUTH_REFRESH_TOKEN` in `core/config/config.php` — were committed to git and sent to GitHub during blocked push attempts (push-protection caught them). Treat as compromised: **rotate** the SA key (delete old JSON key, create new) and reset the OAuth client secret + regenerate the refresh token. Fix + git-scrub details in [[feature-roadmap]] (POST-LIST WORK).
