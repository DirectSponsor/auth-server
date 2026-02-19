#!/bin/bash
set -uo pipefail

BACKUP_SERVER="209.209.10.41"
BACKUP_PORT="5829"
BACKUP_SERVER2="198.23.194.19"
BACKUP_PORT2="22"
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
echo "[OK] Transferred to backup-server (servarica1)"

ssh -p "${BACKUP_PORT2}" root@"${BACKUP_SERVER2}" "mkdir -p ${BACKUP_DIR}"
scp -P "${BACKUP_PORT2}" "${ARCHIVE}" root@"${BACKUP_SERVER2}":"${BACKUP_DIR}/"
echo "[OK] Transferred to backup-server-2 (dr4)"

rm -f "${ARCHIVE}"

ssh -p "${BACKUP_PORT}" root@"${BACKUP_SERVER}" "ls -t ${BACKUP_DIR}/hub-full-*.tar.gz | tail -n +5 | xargs -r rm -f && echo 'Pruned servarica1'"
ssh -p "${BACKUP_PORT2}" root@"${BACKUP_SERVER2}" "ls -t ${BACKUP_DIR}/hub-full-*.tar.gz | tail -n +5 | xargs -r rm -f && echo 'Pruned dr4'"

echo "[DONE] Backup complete: hub-full-${TIMESTAMP}.tar.gz"
