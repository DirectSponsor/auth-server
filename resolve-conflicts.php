<?php
/**
 * Syncthing Conflict Resolver for DirectSponsor Network
 * 
 * Detects, merges, and archives 'sync-conflict' files to prevent data loss.
 * Usage: php api/resolve-conflicts.php [dry-run]
 */

// Configuration - Auth Server (Hub)
define('DATA_DIR', getenv('DIRECTSPONSOR_DATA_DIR') ?: '/var/directsponsor-data');
define('BALANCES_DIR', DATA_DIR . '/userdata/balances');
define('ARCHIVE_DIR', BALANCES_DIR . '/conflicts_archive');
define('LOG_FILE', DATA_DIR . '/logs/conflict-resolver.log');

// Ensure directories exist
if (!is_dir(ARCHIVE_DIR)) {
    mkdir(ARCHIVE_DIR, 0755, true);
}
if (!is_dir(dirname(LOG_FILE))) {
    mkdir(dirname(LOG_FILE), 0755, true);
}

// Check for dry-run mode
$isDryRun = isset($argv[1]) && $argv[1] === 'dry-run';

/**
 * Log message to file and console
 */
function logMessage($message) {
    global $isDryRun;
    $timestamp = date('Y-m-d H:i:s');
    $prefix = $isDryRun ? '[DRY-RUN] ' : '';
    $logLine = "[$timestamp] $prefix$message" . PHP_EOL;
    
    echo $logLine;
    file_put_contents(LOG_FILE, $logLine, FILE_APPEND);
}

/**
 * Resolve conflicts for a specific user
 */
function resolveConflicts($userId, $mainFile, $conflictFiles) {
    global $isDryRun;
    
    logMessage("Processing user $userId with " . count($conflictFiles) . " conflict files.");
    
    // Read main balance file
    if (file_exists($mainFile)) {
        $mainData = json_decode(file_get_contents($mainFile), true);
    } else {
        $mainData = ['balance' => 0, 'recent_transactions' => [], 'last_updated' => 0];
        logMessage("WARNING: Main balance file missing for $userId. Creating new.");
    }
    
    $originalBalance = $mainData['balance'];
    $mainTransactions = $mainData['recent_transactions'] ?? [];
    $dirty = false;
    
    foreach ($conflictFiles as $conflictFile) {
        logMessage("  Analyzing conflict file: " . basename($conflictFile));
        
        $conflictData = json_decode(file_get_contents($conflictFile), true);
        if (!$conflictData) {
            logMessage("  ERROR: Could not decode JSON from " . basename($conflictFile));
            continue;
        }
        
        $conflictTransactions = $conflictData['recent_transactions'] ?? [];
        $addedCount = 0;
        $addedAmount = 0;
        
        // Find unique transactions in conflict file
        foreach ($conflictTransactions as $cTx) {
            $isUnique = true;
            
            // Check if this specific transaction exists in main file
            // Matching on: timestamp + amount + type
            foreach ($mainTransactions as $mTx) {
                // Use null coalescing to avoid undefined index warnings
                $mTimestamp = $mTx['timestamp'] ?? 0;
                $mAmount = $mTx['amount'] ?? 0;
                $mType = $mTx['type'] ?? 'unknown';
                
                $cTimestamp = $cTx['timestamp'] ?? 0;
                $cAmount = $cTx['amount'] ?? 0;
                $cType = $cTx['type'] ?? 'unknown';

                if ($mTimestamp == $cTimestamp && 
                    $mAmount == $cAmount && 
                    $mType == $cType) {
                    $isUnique = false;
                    break;
                }
            }
            
            // If we found a unique transaction (earned on the "conflicted" device)
            if ($isUnique) {
                $mainTransactions[] = $cTx;
                $mainData['balance'] += $cTx['amount'];
                $addedAmount += $cTx['amount'];
                $addedCount++;
                $dirty = true;
                
                logMessage("    + Merging transaction: {$cTx['amount']} coins ({$cTx['type']}) from " . date('Y-m-d H:i:s', $cTx['timestamp']));
            }
        }
        
        if ($addedCount > 0) {
            logMessage("    => Recovered $addedCount transactions totaling $addedAmount coins.");
        } else {
            logMessage("    No unique transactions found.");
        }
        
        // Archive the conflict file
        if (!$isDryRun) {
            $archivePath = ARCHIVE_DIR . '/' . basename($conflictFile);
            if (rename($conflictFile, $archivePath)) {
                logMessage("    Archived conflict file to " . basename($archivePath));
            } else {
                logMessage("    ERROR: Failed to archive conflict file.");
            }
        }
    }
    
    // Save main file if changes were made
    if ($dirty) {
        $mainData['recent_transactions'] = $mainTransactions;
        
        // Sort transactions by timestamp (newest last) to keep history clean
        usort($mainData['recent_transactions'], function($a, $b) {
            return $a['timestamp'] - $b['timestamp'];
        });
        
        // Keep only last 50 transactions to prevent file bloat (increased from 10)
        if (count($mainData['recent_transactions']) > 50) {
            $mainData['recent_transactions'] = array_slice($mainData['recent_transactions'], -50);
        }
        
        $mainData['last_updated'] = time();
        
        $newBalance = $mainData['balance'];
        logMessage("  Updating main balance: $originalBalance -> $newBalance");
        
        if (!$isDryRun) {
            if (file_put_contents($mainFile, json_encode($mainData, JSON_PRETTY_PRINT))) {
                logMessage("  SUCCESS: Main balance file updated.");
            } else {
                logMessage("  ERROR: Failed to write main balance file!");
            }
        }
    } else {
        logMessage("  No changes merged for $userId.");
    }
}

// Main execution
logMessage("--- Starting Conflict Resolution Scan ---");

// Find all sync-conflict files
$files = glob(BALANCES_DIR . '/*sync-conflict*');

if (!$files) {
    logMessage("No conflict files found.");
    exit;
}

// Group conflicts by User ID
// Pattern: {userid}.txt OR {userid}_sync-conflict...
$userConflicts = [];
foreach ($files as $file) {
    $filename = basename($file);
    
    // Extract User ID: everything before the first period or underscore
    // Example: 3-lightninglova.txt -> 3-lightninglova
    // Example: 3-lightninglova-r_sync-conflict... -> 3-lightninglova-r
    if (preg_match('/^([a-zA-Z0-9-]+?)(_sync-conflict|\.)/', $filename, $matches)) {
        $userId = $matches[1];
        if (!isset($userConflicts[$userId])) {
            $userConflicts[$userId] = [];
        }
        $userConflicts[$userId][] = $file;
    }
}

logMessage("Found " . count($files) . " conflict files across " . count($userConflicts) . " users.");

foreach ($userConflicts as $userId => $conflictFiles) {
    $mainFile = BALANCES_DIR . '/' . $userId . '.txt';
    resolveConflicts($userId, $mainFile, $conflictFiles);
}

logMessage("--- Scan Completed ---");
?>
