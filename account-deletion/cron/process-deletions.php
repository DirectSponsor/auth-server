#!/usr/bin/env php
<?php
/**
 * Daily cron: execute due deletion requests + purge expired trash.
 *
 * - Pending requests whose execute_at has passed get their user data moved
 *   to .trash (recoverable for DELETION_TRASH_RETENTION_DAYS days).
 * - Trash entries older than the retention period are permanently removed.
 *
 * Deploy to /root/process-deletions.php on es3-auth. Crontab (root):
 *   20 5 * * * /usr/bin/php /root/process-deletions.php >> /var/log/process-deletions.log 2>&1
 */

define('DELETION_DATA_DIR', '/var/directsponsor-data');
define('DELETION_REQUESTS_DIR', DELETION_DATA_DIR . '/userdata/.deletion-requests');
define('DELETION_TRASH_RETENTION_DAYS', 30);

require_once '/var/www/auth.directsponsor.org/public_html/includes/trash_system.php';

$log = function ($msg) {
    echo '[' . date('Y-m-d H:i:s') . "] $msg\n";
};

// --- 1. Execute due requests ---

foreach (glob(DELETION_REQUESTS_DIR . '/*.json') ?: [] as $reqFile) {
    $req = json_decode(file_get_contents($reqFile), true);
    if (!$req || ($req['status'] ?? '') !== 'pending' || ($req['execute_at'] ?? 0) > time()) {
        continue;
    }

    $userId = $req['user_id'];
    if (!preg_match('/^[a-zA-Z0-9_-]+$/', $userId)) {
        $log("SKIP malformed user_id in $reqFile");
        continue;
    }

    $moved = [];
    $targets = [
        'balance' => DELETION_DATA_DIR . "/userdata/balances/{$userId}.txt",
        'profile' => DELETION_DATA_DIR . "/userdata/profiles/{$userId}.txt",
        'token'   => DELETION_DATA_DIR . "/userdata/tokens/{$userId}.txt",
        'avatar'  => DELETION_DATA_DIR . "/userdata/avatars/{$userId}.jpg",
    ];
    foreach (glob(DELETION_DATA_DIR . "/userdata/chat/{$userId}_*.txt") ?: [] as $f) {
        $targets['chat-' . basename($f)] = $f;
    }

    foreach ($targets as $type => $path) {
        if (file_exists($path) && moveToTrash($path, DELETION_DATA_DIR)) {
            $moved[] = $type;
        }
    }

    $req['status'] = 'completed';
    $req['completed_at'] = time();
    $req['moved_files'] = $moved;
    file_put_contents($reqFile, json_encode($req, JSON_PRETTY_PRINT));
    $log("DELETED {$userId}: " . count($moved) . ' items to trash (' . implode(',', $moved) . ')');
}

// --- 2. Purge trash older than retention period ---

$cutoff = time() - (DELETION_TRASH_RETENTION_DAYS * 86400);
$trashBase = DELETION_DATA_DIR . '/userdata/.trash';

foreach (glob($trashBase . '/*', GLOB_ONLYDIR) ?: [] as $dateDir) {
    if (strtotime(basename($dateDir)) >= $cutoff) continue;
    $purged = 0;
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dateDir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) {
        $f->isDir() ? rmdir($f->getPathname()) : (unlink($f->getPathname()) && $purged++);
    }
    rmdir($dateDir);
    $log("PURGED trash dir " . basename($dateDir) . " ($purged files permanently deleted)");
}
