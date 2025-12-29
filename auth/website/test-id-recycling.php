<?php
/**
 * Test script for ID recycling system
 */
require_once 'config.php';

/**
 * Get the next available user ID, reusing deleted IDs
 */
function getNextUserId($db) {
    // Find the lowest available user ID by looking for gaps
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

echo "=== ID Recycling Test ===\n";

try {
    $db = getAuthDB();
    
    // Show current users
    $stmt = $db->prepare("SELECT id, username FROM users ORDER BY id");
    $stmt->execute();
    $users = $stmt->fetchAll();
    
    echo "Current users:\n";
    if (empty($users)) {
        echo "  (no users)\n";
    } else {
        foreach ($users as $user) {
            echo "  ID {$user['id']}: {$user['username']}\n";
        }
    }
    
    // Test next ID
    $nextId = getNextUserId($db);
    echo "\nNext available ID: $nextId\n";
    
    echo "\n=== ID Recycling Logic ===\n";
    
    // Test scenarios
    $testUsers = [1, 2, 3, 5, 6, 8, 10]; // Missing: 4, 7, 9
    
    echo "Test scenario - existing IDs: " . implode(', ', $testUsers) . "\n";
    echo "Expected next ID: 4 (first gap)\n";
    
    // Simulate the query
    echo "\nSQL Query Test:\n";
    $stmt = $db->prepare("
        SELECT COALESCE(MIN(t1.id + 1), 1) as next_id
        FROM (SELECT 0 as id UNION ALL SELECT id FROM users) t1
        LEFT JOIN users t2 ON t1.id + 1 = t2.id
        WHERE t2.id IS NULL AND t1.id + 1 > 0
    ");
    $stmt->execute();
    $result = $stmt->fetch();
    echo "Query result: " . ($result['next_id'] ?? 'null') . "\n";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

echo "\n=== Test Complete ===\n";
?>
