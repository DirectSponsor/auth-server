#!/bin/bash
set -uo pipefail

BACKUP_SERVER="209.209.10.41"
BACKUP_PORT="5829"
BACKUP_DIR="/root/hub_backups"
DB_NAME="directsponsor_oauth"
DB_USER="directsponsor_oauth"
DB_PASS="ds9Hj#k2P9*mN"

TIMESTAMP=$(date +%Y%m%d-%H%M%S)
TMP_DIR="/tmp/hub-backup-${TIMESTAMP}"

mkdir -p "$TMP_DIR"
echo "[${TIMESTAMP}] Starting backup..."

mysqldump -u"${DB_USER}" -p"${DB_PASS}" "${DB_NAME}" > "${TMP_DIR}/database.sql"
echo "[OK] Database dumped"

tar -czf "${TMP_DIR}/webfiles.tar.gz" /var/www/auth.directsponsor.org/public_html/
echo "[OK] Web files archived"

tar -czf "${TMP_DIR}/userdata.tar.gz" --warning=no-file-changed /var/directsponsor-data/ || true
echo "[OK] User data archived"

ARCHIVE="/tmp/hub-full-${TIMESTAMP}.tar.gz"
tar -czf "${ARCHIVE}" -C /tmp "hub-backup-${TIMESTAMP}"
rm -rf "${TMP_DIR}"
echo "[OK] Bundle created: ${ARCHIVE}"

ssh -p "${BACKUP_PORT}" root@"${BACKUP_SERVER}" "mkdir -p ${BACKUP_DIR}"
scp -P "${BACKUP_PORT}" "${ARCHIVE}" root@"${BACKUP_SERVER}":"${BACKUP_DIR}/"
rm -f "${ARCHIVE}"
echo "[OK] Transferred to backup server"

ssh -p "${BACKUP_PORT}" root@"${BACKUP_SERVER}" "ls -t ${BACKUP_DIR}/hub-full-*.tar.gz | tail -n +5 | xargs -r rm -f && echo 'Pruned old backups'"

echo "[DONE] Backup complete: hub-full-${TIMESTAMP}.tar.gz"
