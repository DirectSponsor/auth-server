#!/bin/bash
# Daily incremental DB backup for hub (auth.directsponsor.org)
# Runs Mon-Sat at 02:00; Sunday is covered by the full backup (backup-to-remote.sh)
# Keeps 7 most recent daily DB dumps on each backup server.
set -uo pipefail

BACKUP_SERVER="209.209.10.41"
BACKUP_PORT="5829"
BACKUP_SERVER2="198.23.194.19"
BACKUP_PORT2="22"
BACKUP_DIR="/root/hub_backups/daily-db"
DB_NAME="directsponsor_oauth"
DB_USER="directsponsor_oauth"
DB_PASS="ds9Hj#k2P9*mN"

TIMESTAMP=$(date +%Y%m%d-%H%M%S)
ARCHIVE="/tmp/hub-db-${TIMESTAMP}.sql.gz"

mysqldump -u"${DB_USER}" -p"${DB_PASS}" "${DB_NAME}" | gzip > "${ARCHIVE}"
echo "[OK] DB dumped and compressed: ${ARCHIVE}"

ssh -p "${BACKUP_PORT}"  root@"${BACKUP_SERVER}"  "mkdir -p ${BACKUP_DIR}"
scp -P "${BACKUP_PORT}"  "${ARCHIVE}" root@"${BACKUP_SERVER}":"${BACKUP_DIR}/"
echo "[OK] Transferred to servarica1"

ssh -p "${BACKUP_PORT2}" root@"${BACKUP_SERVER2}" "mkdir -p ${BACKUP_DIR}"
scp -P "${BACKUP_PORT2}" "${ARCHIVE}" root@"${BACKUP_SERVER2}":"${BACKUP_DIR}/"
echo "[OK] Transferred to dr4"

rm -f "${ARCHIVE}"

ssh -p "${BACKUP_PORT}"  root@"${BACKUP_SERVER}"  "ls -t ${BACKUP_DIR}/hub-db-*.sql.gz | tail -n +8 | xargs -r rm -f && echo 'Pruned servarica1'"
ssh -p "${BACKUP_PORT2}" root@"${BACKUP_SERVER2}" "ls -t ${BACKUP_DIR}/hub-db-*.sql.gz | tail -n +8 | xargs -r rm -f && echo 'Pruned dr4'"

echo "[DONE] Daily DB backup complete: hub-db-${TIMESTAMP}.sql.gz"
