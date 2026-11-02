# Account Deletion Feature — currently broken in production

**Status: live button, 404 destination. Needs a real fix session.**

`roflfaucet.com`'s profile page has a live "Delete My Account Data" button
(`site/profile.html`, ~line 303) that redirects to:

```
https://auth.directsponsor.org/delete-account.html?user_id=...&return_url=...
```

That URL currently 404s. These files were found sitting in the **wrong
directory** on the hub server (`/var/www/html/`, Apache's default docroot),
not in the actual vhost docroot for `auth.directsponsor.org`
(`/var/www/auth.directsponsor.org/public_html/`, per
`/etc/httpd/conf.d/auth.directsponsor.org.conf` on `hub`). They were likely
never actually deployed to the live path, or got left behind after a docroot
change.

Found and extracted from `projects/sync-system/` (2026-10-15) — this feature
is unrelated to that folder's actual subject (the old, now-abandoned
Syncthing/inotify userdata-sync system); it was just sitting in the same
folder.

## Files here

- `delete-account.html` — the page the button redirects to
- `delete-user-account.php` — deletion API endpoint
- `trash_system.php` — soft-delete/trash handling
- `profile-security-section-updated.html` — related profile page section
- `CENTRALIZED_DELETION_SUCCESS.md` — notes from when this was apparently built/tested
- `IMPLEMENTATION_GUIDE.md` — original implementation notes

## Before fixing

Read `CENTRALIZED_DELETION_SUCCESS.md` and `IMPLEMENTATION_GUIDE.md` first —
this was evidently built and tested at some point, so check whether the
deletion logic itself is still sound before re-deploying, and confirm the
correct live path and permissions on `hub` (`/var/www/auth.directsponsor.org/public_html/`).
