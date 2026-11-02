# Account Deletion Feature — rebuilt with auth + grace period

**Status (2026-11-02): rewritten, staged here, NOT yet deployed/tested.**

`roflfaucet.com`'s profile page has a live "Delete My Account Data" button that
redirects to `auth.directsponsor.org/delete-account.html?user_id=...` — which
404s. The Nov-2025 implementation was built and tested but deployed to Apache's
default docroot (`/var/www/html/`) instead of the vhost docroot
(`/var/www/auth.directsponsor.org/public_html/`). Strays still on the server:
`/var/www/html/delete-account.html`, `/var/www/html/includes/trash_system.php`.

## Review of the original code (why it couldn't just be re-deployed)

- **No authentication at all** — POST `{user_id}` deleted *anyone's* data.
  Exactly the user_id-from-body pattern the codebase's auth rules forbid.
- **Path traversal** — `user_id` went straight into file paths; `../../`
  could relocate arbitrary files into `.trash`.
- **"30-day recovery" was fiction** — no purge mechanism existed; trash
  accumulated forever.
- **CORS `*`** instead of `setCorsHeaders()` allowlist.
- "Syncs to all sites via Syncthing in 15s" — stale, from the abandoned
  sync-system. Cross-site data removal is a separate follow-up (below).

The `moveToTrash`/`restoreFromTrash` mechanics were sound and are reused.

## New design (2026-11-02)

Request-based with a **7-day grace period** — nothing deletes on click:

1. `delete-account.php` — page, verifies `?token=<jwt>` server-side (same
   pattern as `change-password.php`). Shows current state: schedule a
   deletion, or cancel a pending one. `user_id` only ever comes from the
   verified JWT `sub` claim, sanitized `^[a-zA-Z0-9_-]+$`.
2. `api/delete-user-account.php` — POST `{token}` → writes
   `userdata/.deletion-requests/{userId}.json` `{status: pending,
   execute_at: now+7d}` and emails the account owner (the real "angry
   spouse" defense — the owner gets notified and can cancel).
3. `api/cancel-deletion.php` — POST `{token}` → cancels a pending request.
4. `api/deletion-status.php` — GET/POST `{token}` → current status.
5. `cron/process-deletions.php` — daily cron (NOT web-reachable): executes
   due requests (files → `.trash/YYYY-MM-DD/category/`) and purges trash
   older than 30 days (fixes the missing retention). The dangerous
   file-moving now has zero web attack surface.

## Deploy map (when reviewed & ready)

| Staging file | Server path (es3-auth) |
|---|---|
| `public_html/delete-account.php` | `/var/www/auth.directsponsor.org/public_html/delete-account.php` |
| `public_html/api/*.php` | `…/public_html/api/` |
| `public_html/includes/trash_system.php`, `deletion-auth.php` | `…/public_html/includes/` |
| `cron/process-deletions.php` | `/root/process-deletions.php` (absolute include paths — not under docroot) |

Cron on es3-auth (root):
```
20 5 * * * /usr/bin/php /root/process-deletions.php >> /var/log/process-deletions.log 2>&1
```

**Callers must be updated**: the roflfaucet button currently sends
`?user_id=` — must send `?token=<jwt>` instead (same pattern as the
change-password link in `profile-security-section-updated.html`, which is
already the reference). Other sites' delete buttons same.

## Before deploying

- [ ] Test JWT verify against a real token (expired/bad sig → 401)
- [ ] Create throwaway account → request → confirm email arrives → cancel → request gone
- [ ] Re-request → let cron run (or set execute_at past) → files land in `.trash/`
- [ ] Remove server strays: `/var/www/html/delete-account.html`,
      `/var/www/html/includes/trash_system.php`, `/var/www/html/api/` if present
- [ ] Old stray `delete-user-account.php` (unauthenticated) must NEVER be
      deployed — it's in git history only for reference

## Follow-up (not built)

- **Cross-site data**: this covers auth-server data only (`/var/directsponsor-data`).
  Site-local data (`/var/roflfaucet-data`, `/var/clickforcharity-data`) needs
  either a marker endpoint sites poll, or per-site delete APIs. Design when
  implementing.
- **Login blocking during grace**: currently a pending user can still sign in.
  Optional: check `.deletion-requests/` at login and suspend.
