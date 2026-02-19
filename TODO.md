# Auth Server TODOs

## Deployment
- [ ] **Fix Remote Permissions User**: The `deploy.sh` script attempts to `chown` files to `www-data:www-data`, but the remote server returns `invalid user`.
    - **Error**: `chown: invalid user: ‘www-data:www-data’`
    - **Location**: `deploy.sh` -> `fix_permissions()` function.
    - **Action**: Web server runs as `apache` on this server — update `deploy.sh` to use `apache:apache`.

## PHP Upgrade
- [x] **Upgrade PHP 7.2 → 8.2** on the `hub` server (auth + data server, `86.38.200.119`). Done 2026-02-19, now running PHP 8.2.30.
    - PHP 7.2 is end-of-life with known security vulnerabilities.
    - A full server backup now exists on the backup server (`/root/hub_backups/`) so a restore is possible if anything breaks.
    - Test approach: try on `es4` or `es6` (spare servers) first.
    - Rough steps: `dnf install -y php8.2 php8.2-fpm php8.2-mysqlnd php8.2-mbstring && systemctl restart php-fpm httpd`
    - Check all auth endpoints still work after upgrade.
