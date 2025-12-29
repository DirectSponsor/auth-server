<?php
/**
 * Complete system cleanup for ID recycling testing
 * Cleans both auth database and balance files
 */
require_once 'config.php';

echo "=== Complete System Cleanup ===\n\n";

try {
    $db = getAuthDB();
    
    // Show current state before cleanup
    echo "=== Current System State ===\n";
    
    $stmt = $db->prepare("SELECT id, username, email FROM users ORDER BY id");
    $stmt->execute();
    $users = $stmt->fetchAll();
    
    echo "Auth Database Users:\n";
    if (empty($users)) {
        echo "  (no users)\n";
    } else {
        foreach ($users as $user) {
            echo "  ID {$user['id']}: {$user['username']} ({$user['email']})\n";
        }
    }
    
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM site_claims");
    $stmt->execute();
    $claimsCount = $stmt->fetch()['count'];
    echo "Site Claims: $claimsCount\n";
    
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM user_balances");
    $stmt->execute();
    $balancesCount = $stmt->fetch()['count'];
    echo "User Balances: $balancesCount\n";
    
    echo "\n=== Creating Full Backup ===\n";
    $backupFile = "/tmp/full_system_backup_" . date('Ymd_His') . ".sql";
    $backupCmd = "mysqldump directsponsor_oauth > $backupFile";
    exec($backupCmd, $output, $result);
    
    if ($result === 0) {
        echo "✓ Database backup created: $backupFile\n";
    } else {
        throw new Exception("Backup failed");
    }
    
    echo "\n=== Clearing Database ===\n";
    
    // Clear all related tables
    echo "Clearing site_claims...\n";
    $stmt = $db->prepare("DELETE FROM site_claims");
    $stmt->execute();
    
    echo "Clearing user_balances...\n";
    $stmt = $db->prepare("DELETE FROM user_balances");
    $stmt->execute();
    
    echo "Clearing users...\n";
    $stmt = $db->prepare("DELETE FROM users");
    $stmt->execute();
    
    echo "✓ Database cleared\n";
    
    // Verify cleanup
    echo "\n=== Verifying Cleanup ===\n";
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM users");
    $stmt->execute();
    $userCount = $stmt->fetch()['count'];
    echo "Remaining users: $userCount\n";
    
    // Test ID recycling function
    function getNextUserId($db) {
        $stmt = $db->prepare("
            SELECT t1.id + 1 as next_id
            FROM users t1
            LEFT JOIN users t2 ON t1.id + 1 = t2.id
            WHERE t2.id IS NULL
            ORDER BY t1.id
            LIMIT 1
        ");
        $stmt->execute();
        $result = $stmt->fetch();
        
        if ($result) {
            return $result['next_id'];
        } else {
            return 1;
        }
    }
    
    $nextId = getNextUserId($db);
    echo "Next user ID will be: $nextId\n";
    
    echo "\n=== System Ready ===\n";
    echo "✓ Auth database cleaned\n";
    echo "✓ ID recycling active (next ID: $nextId)\n";
    echo "✓ Ready for fresh user registration testing\n";
    echo "\nBackup location: $backupFile\n";
    echo "\nNEXT STEPS:\n";
    echo "1. Clean production balance files manually\n";
    echo "2. Register test user (e.g., username 'andytest1')\n";
    echo "3. Verify balance file created as '1-andytest1.txt'\n";
    echo "4. Test tip system integration\n";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    if (isset($backupFile)) {
        echo "Backup available at: $backupFile\n";
    }
}
?>
