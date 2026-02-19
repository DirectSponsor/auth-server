# Auth Server Repository

This repository mirrors the PHP authentication stack that runs on `auth.directsponsor.org`. It intentionally keeps the **code** (`auth/website/`) and the **runtime data** (`data/`) separate so we can manage deployments safely while `syncthing` (or another sync tool) owns the live `/var/directsponsor-data` directory.

## Layout

```
auth-server/
├── auth/
│   └── website/           # PHP endpoints and helpers copied from backup
├── data/                  # Optional extracted copy of live JSON data (untracked)
├── deploy.sh              # Auth deploy script (see below)
└── README.md              # This file
```

- **`auth/website/`** should contain the files currently in `auth-server-backup-2025-12-14/website/`. Keep this folder under version control and push it to GitHub like any other site.
- **`data/`** stays out of Git. When you need a local snapshot for testing, copy it with `rsync` from the production server into this folder, but never write it back through the deploy script.

## Deploy script

`deploy.sh` automates the usual workflow:

1. Ensures you are in the repo root and the `auth/website/` folder exists.
2. Auto-commits local changes (prompting for a message) and pushes to the GitHub remote.
3. Creates a timestamped tarball of `auth/website/` inside `$HOME/backups/auth-server-deploys`.
4. Backs up the remote copy (`/var/www/auth-server/website/`) before pushing.
5. `rsync`s the code to the remote host, excluding any `data/` folder to avoid overwriting live profiles/balances.
6. Sets permissions on the remote host to keep PHP runnable (matching the patterns from the other deploy script).

Before running `deploy.sh`, edit the configuration block at the top of the file to match:

```bash
AUTH_REMOTE_HOST="your-auth-server"
AUTH_REMOTE_CODE_PATH="/var/www/auth-server/website"
AUTH_REMOTE_BACKUP_DIR="/var/backups/auth-server/code"
LOCAL_CODE_DIR="auth/website"
LOCAL_BACKUP_DIR="$HOME/backups/auth-server-deploys"
GIT_REMOTE="origin"
GIT_BRANCH="main"
```

Each value should match your environment (hostname, paths, branch). Keep `/data/` outside `LOCAL_CODE_DIR`, and the script will never `rm -rf` it.

## Rebuilding the server

If the production server is wiped:

1. Clone this repo and copy `auth/website/` onto the server (e.g., `/var/www/auth-server/website/`).
2. Restore the JWT secrets and `$allowed_redirects` list from `config.php`.
3. Sync `/var/directsponsor-data` from the latest backup or `syncthing` source.
4. Run `deploy.sh` from the repo root to push any remaining tweaks and confirm the code works.

Add any new docs or scripts under this folder so your AI or colleagues know where to look.

## Backup to remote

`backup-to-remote.sh` runs weekly (Sunday 02:00) via cron on the production server and pushes a full snapshot to the backup server (`209.209.10.41`, port 5829). It captures:

- MySQL database dump (`directsponsor_oauth`)
- Web files (`/var/www/auth.directsponsor.org/public_html/`)
- User data (`/var/directsponsor-data/`)

Backups land in `/root/hub_backups/` on the backup server. The last 4 weekly archives are kept; older ones are pruned automatically. Logs go to `/var/log/hub-backup.log` on the auth server.

To run a manual backup:
```bash
ssh hub 'bash /root/backup-to-remote.sh'
```

To restore from a backup, extract the archive and restore each component:
```bash
# On the auth server:
tar -xzf hub-full-YYYYMMDD-HHMMSS.tar.gz
tar -xzf hub-backup-*/webfiles.tar.gz -C /
mysql -u directsponsor_oauth -p directsponsor_oauth < hub-backup-*/database.sql
tar -xzf hub-backup-*/userdata.tar.gz -C /
```

## Admin dashboard

`auth/website/admin-users.php` is a password-protected admin page at `https://auth.directsponsor.org/admin-users.php`. It shows:

- Total users, new signups (7/30 day), email verification stats
- Signups broken down by which site the user first registered from
- Login activity counts per site
- Searchable, sortable, paginated user table with signup date, signup site, login count, and last login

The admin password is set via `define('ADMIN_PASSWORD', '...')` in `config.local.php` (never committed to git).
