<?php
/**
 * Setup script to enable ID recycling
 * This removes AUTO_INCREMENT from the users table and tests the system
 */
require_once 'config.php';

echo "=== ID Recycling Setup ===\n\n";

try {
    $db = getAuthDB();
    
    // Show current table structure
    echo "Current table structure:\n";
    $stmt = $db->prepare("DESCRIBE users");
    $stmt->execute();
    $columns = $stmt->fetchAll();
    
    foreach ($columns as $col) {
        echo "  {$col['Field']}: {$col['Type']} {$col['Extra']}\n";
    }
    
    // Check if AUTO_INCREMENT needs to be removed
    $hasAutoIncrement = false;
    foreach ($columns as $col) {
        if ($col['Field'] == 'id' && strpos($col['Extra'], 'auto_increment') !== false) {
            $hasAutoIncrement = true;
            break;
        }
    }
    
    if ($hasAutoIncrement) {
        echo "\n=== Removing AUTO_INCREMENT ===\n";
        
        // Create backup of the current state
        echo "Creating backup...\n";
        $backupFile = "/tmp/users_backup_" . date('Ymd_His') . ".sql";
        $backupCmd = "mysqldump directsponsor_oauth users > $backupFile";
        exec($backupCmd, $output, $result);
        
        if ($result === 0) {
            echo "Backup created: $backupFile\n";
        } else {
            throw new Exception("Backup failed");
        }
        
        // Remove AUTO_INCREMENT from id column
        echo "Removing AUTO_INCREMENT from id column...\n";
        $stmt = $db->prepare("ALTER TABLE users MODIFY id INT(11) NOT NULL PRIMARY KEY");
        $stmt->execute();
        
        echo "AUTO_INCREMENT removed successfully!\n";
        
        // Verify the change
        echo "\nUpdated table structure:\n";
        $stmt = $db->prepare("DESCRIBE users");
        $stmt->execute();
        $columns = $stmt->fetchAll();
        
        foreach ($columns as $col) {
            echo "  {$col['Field']}: {$col['Type']} {$col['Extra']}\n";
        }
        
    } else {
        echo "\nAUTO_INCREMENT already removed.\n";
    }
    
    // Test the next ID function
    echo "\n=== Testing ID Recycling ===\n";
    
    // Show current users again
    $stmt = $db->prepare("SELECT id, username FROM users ORDER BY id");
    $stmt->execute();
    $users = $stmt->fetchAll();
    
    echo "Current users:\n";
    foreach ($users as $user) {
        echo "  ID {$user['id']}: {$user['username']}\n";
    }
    
    // Test next ID
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
    echo "\nNext available ID: $nextId\n";
    
    echo "\n=== Setup Complete ===\n";
    echo "ID recycling is now active!\n";
    echo "Next user registration will use ID: $nextId\n";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    
    if (isset($backupFile)) {
        echo "Backup available at: $backupFile\n";
        echo "To restore: mysql directsponsor_oauth < $backupFile\n";
    }
}
?>
