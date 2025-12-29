#!/bin/bash

# Auth Server Deployment Script
# Mirrors the deployment pattern used by the other sites while keeping runtime data untouched.

set -euo pipefail

# === Configuration (edit these for your environment) ===
REMOTE_HOST="hub"
REMOTE_CODE_PATH="/var/www/auth.directsponsor.org/public_html"
REMOTE_BACKUP_DIR="/var/backups/auth-server/code"
LOCAL_CODE_DIR="auth/website"
LOCAL_BACKUP_DIR="$HOME/backups/auth-server-deploys"
GIT_REMOTE="origin"
GIT_BRANCH="main"

# === Coloring ===
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

log() { echo -e "${BLUE}[$(date +'%Y-%m-%d %H:%M:%S')]${NC} $1"; }
success() { echo -e "${GREEN}[$(date +'%Y-%m-%d %H:%M:%S')] ✅ $1${NC}"; }
warning() { echo -e "${YELLOW}[$(date +'%Y-%m-%d %H:%M:%S')] ⚠️  $1${NC}"; }
error() { echo -e "${RED}[$(date +'%Y-%m-%d %H:%M:%S')] ❌ $1${NC}"; exit 1; }

auto_commit_changes() {
    log "Scanning for pending git changes..."
    if [[ -n $(git status --porcelain) ]]; then
        local change_count
        change_count=$(git status --porcelain | wc -l)
        log "Found $change_count change(s)."
        read -p "Commit message (leave empty for default): " -r msg
        git add -A "$LOCAL_CODE_DIR"
        local commit_msg=${msg:-"auth-server deploy prep - $(date +'%Y-%m-%d %H:%M:%S')"}
        git commit -m "$commit_msg"
        success "Committed work: $commit_msg"
    else
        log "Working tree clean."
    fi
}

pre_deploy_checks() {
    log "Running pre-deploy sanity checks..."
    if [[ ! -d "$LOCAL_CODE_DIR" ]]; then
        error "Missing $LOCAL_CODE_DIR. Run this script from the repo root."
    fi
    if ! ssh "$REMOTE_HOST" "echo connection ok" >/dev/null 2>&1; then
        error "Cannot reach $REMOTE_HOST."
    fi
    success "Pre-deploy checks passed."
}

create_local_backup() {
    log "Backing up local code..."
    mkdir -p "$LOCAL_BACKUP_DIR"
    local ts
    ts=$(date +'%Y%m%d-%H%M%S')
    local archive="$LOCAL_BACKUP_DIR/auth-server-$ts.tar.gz"
    tar -czf "$archive" -C "$(dirname "$LOCAL_CODE_DIR")" "$(basename "$LOCAL_CODE_DIR")"
    success "Local backup saved to $archive."
    # prune older archives, keep last 6
    ls -t "$LOCAL_BACKUP_DIR"/auth-server-*.tar.gz | tail -n +7 | xargs -r rm -f
}

backup_remote_code() {
    log "Backing up remote code on $REMOTE_HOST..."
    ssh "$REMOTE_HOST" "
        mkdir -p '$REMOTE_BACKUP_DIR'
        ts=\"\$(date +'%Y%m%d-%H%M%S')\"
        tar -czf '$REMOTE_BACKUP_DIR/auth-server-\$ts.tar.gz' -C '$REMOTE_CODE_PATH/..' '$(basename "$REMOTE_CODE_PATH")'
    "
    success "Remote code snapshot stored."
}

deploy_files() {
    log "Deploying to $REMOTE_HOST..."
    rsync -avz --delete \
        --exclude='data/' \
        --exclude='.git/' \
        --exclude='*.log' \
        --exclude='*.tmp' \
        --exclude='deploy.sh' \
        "$LOCAL_CODE_DIR/" "$REMOTE_HOST:$REMOTE_CODE_PATH/"
    success "Code deployed."
}

fix_permissions() {
    log "Fixing remote permissions..."
    ssh "$REMOTE_HOST" "
        sudo chown -R www-data:www-data '$REMOTE_CODE_PATH'
        find '$REMOTE_CODE_PATH' -type d -exec chmod 755 {} \;
        find '$REMOTE_CODE_PATH' -type f -exec chmod 644 {} \;
    "
    success "Permissions adjusted."
}

push_git() {
    log "Pushing to $GIT_REMOTE/$GIT_BRANCH..."
    git push "$GIT_REMOTE" "$GIT_BRANCH"
    success "Git push complete."
}

main() {
    log "Starting auth-server deployment..."
    echo "Deploying from $(pwd)/$LOCAL_CODE_DIR to $REMOTE_HOST:$REMOTE_CODE_PATH"
    echo "Remote backup path: $REMOTE_BACKUP_DIR"
    echo ""
    read -rp "Continue? (y/N): " -n 1
    echo
    if [[ ! $REPLY =~ ^[Yy]$ ]]; then
        error "Deployment cancelled."
    fi

    auto_commit_changes
    push_git
    pre_deploy_checks
    create_local_backup
    backup_remote_code
    deploy_files
    fix_permissions
    success "Auth server deployment finished!"
}

main "$@"
