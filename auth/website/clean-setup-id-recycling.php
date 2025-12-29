<?php
/**
 * Clean setup script for ID recycling
 * This backs up and clears all user data, then sets up ID recycling from scratch
 */
require_once 'config.php';

echo "=== Clean ID Recycling Setup ===\n\n";

try {
    $db = getAuthDB();
    
    // Create comprehensive backup
    echo "=== Creating Backup ===\n";
    $backupFile = "/tmp/oauth_full_backup_" . date('Ymd_His') . ".sql";
    $backupCmd = "mysqldump directsponsor_oauth > $backupFile";
    exec($backupCmd, $output, $result);
    
    if ($result === 0) {
        echo "Full backup created: $backupFile\n";
    } else {
        throw new Exception("Backup failed");
    }
    
    // Show current data counts
    echo "\n=== Current Data ===\n";
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM users");
    $stmt->execute();
    $userCount = $stmt->fetch()['count'];
    echo "Users: $userCount\n";
    
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM site_claims");
    $stmt->execute();
    $claimsCount = $stmt->fetch()['count'];
    echo "Site claims: $claimsCount\n";
    
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM user_balances");
    $stmt->execute();
    $balancesCount = $stmt->fetch()['count'];
    echo "User balances: $balancesCount\n";
    
    // Clear all data (respecting foreign key constraints)
    echo "\n=== Clearing All Data ===\n";
    
    echo "Clearing site_claims...\n";
    $stmt = $db->prepare("DELETE FROM site_claims");
    $stmt->execute();
    
    echo "Clearing user_balances...\n";
    $stmt = $db->prepare("DELETE FROM user_balances");
    $stmt->execute();
    
    echo "Clearing users...\n";
    $stmt = $db->prepare("DELETE FROM users");
    $stmt->execute();
    
    // Remove AUTO_INCREMENT from users table
    echo "\n=== Removing AUTO_INCREMENT ===\n";
    $stmt = $db->prepare("ALTER TABLE users MODIFY id INT(11) NOT NULL");
    $stmt->execute();
    echo "AUTO_INCREMENT removed from users table\n";
    
    // Reset AUTO_INCREMENT value to 1 (just in case)
    $stmt = $db->prepare("ALTER TABLE users AUTO_INCREMENT = 1");
    $stmt->execute();
    
    // Verify table structure
    echo "\n=== Updated Table Structure ===\n";
    $stmt = $db->prepare("DESCRIBE users");
    $stmt->execute();
    $columns = $stmt->fetchAll();
    
    foreach ($columns as $col) {
        $extra = $col['Extra'] ? " ({$col['Extra']})" : "";
        echo "  {$col['Field']}: {$col['Type']}{$extra}\n";
    }
    
    // Test the ID recycling function
    echo "\n=== Testing ID Recycling Function ===\n";
    
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
            // No users exist, start with ID 1
            return 1;
        }
    }
    
    $nextId = getNextUserId($db);
    echo "First user ID will be: $nextId\n";
    
    // Verify empty tables
    echo "\n=== Verification ===\n";
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM users");
    $stmt->execute();
    $userCount = $stmt->fetch()['count'];
    echo "Users remaining: $userCount\n";
    
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM site_claims");
    $stmt->execute();
    $claimsCount = $stmt->fetch()['count'];
    echo "Site claims remaining: $claimsCount\n";
    
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM user_balances");
    $stmt->execute();
    $balancesCount = $stmt->fetch()['count'];
    echo "User balances remaining: $balancesCount\n";
    
    echo "\n=== Setup Complete ===\n";
    echo "✓ Database cleaned successfully\n";
    echo "✓ AUTO_INCREMENT removed\n";
    echo "✓ ID recycling system ready\n";
    echo "✓ Next registration will use ID: $nextId\n";
    echo "\nBackup available at: $backupFile\n";
    echo "To restore if needed: mysql directsponsor_oauth < $backupFile\n";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    
    if (isset($backupFile) && file_exists($backupFile)) {
        echo "Backup available at: $backupFile\n";
        echo "To restore: mysql directsponsor_oauth < $backupFile\n";
    }
}
?>
