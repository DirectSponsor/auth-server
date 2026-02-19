# Auth Server TODOs

## Deployment
- [x] **Fix Remote Permissions User**: Fixed `deploy.sh` to use `chown -R apache:apache` (removed erroneous `sudo`). Done 2026-02-19.

## PHP Upgrade
- [x] **Upgrade PHP 7.2 → 8.2** on the `hub` server (auth + data server, `86.38.200.119`). Done 2026-02-19, now running PHP 8.2.30.
    - PHP 7.2 is end-of-life with known security vulnerabilities.
    - A full server backup now exists on the backup server (`/root/hub_backups/`) so a restore is possible if anything breaks.
    - Test approach: try on `es4` or `es6` (spare servers) first.
    - Rough steps: `dnf install -y php8.2 php8.2-fpm php8.2-mysqlnd php8.2-mbstring && systemctl restart php-fpm httpd`
    - Check all auth endpoints still work after upgrade.
