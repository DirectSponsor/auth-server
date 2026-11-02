<?php
/**
 * Centralized Account Deletion API
 * 
 * Handles user account deletion requests from all sites
 * Moves user data to trash, which automatically syncs to all sites via Syncthing
 * 
 * Usage: Sites redirect users here for account deletion
 * 
 * @author Sync System Team
 * @version 1.0
 * @date 2025-11-24
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Get input data
$input = json_decode(file_get_contents('php://input'), true);
if (!$input || empty($input['user_id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing required field: user_id']);
    exit;
}

// Include trash system
require_once __DIR__ . '/../includes/trash_system.php';

$userId = $input['user_id'];
$deletedFiles = [];
$messages = [];
$warnings = [];

// Auth server data directory
$dataDir = '/var/directsponsor-data';

// Function to move file to trash with logging
function moveUserDataToTrash($filePath, $fileType, $userId, $dataDir) {
    global $messages, $warnings;
    
    if (file_exists($filePath)) {
        if (moveToTrash($filePath, $dataDir)) {
            error_log("Auth Server: Moved $fileType to trash for user_id: $userId");
            return true;
        } else {
            $warnings[] = "Failed to move $fileType to trash";
            error_log("Auth Server: Failed to move $fileType to trash for user_id: $userId");
            return false;
        }
    }
    return false; // File doesn't exist
}

// Move balance file to trash
$balanceFile = "$dataDir/userdata/balances/{$userId}.txt";
if (moveUserDataToTrash($balanceFile, "balance file", $userId, $dataDir)) {
    $deletedFiles[] = "balance";
}

// Move profile file to trash
$profileFile = "$dataDir/userdata/profiles/{$userId}.txt";
if (moveUserDataToTrash($profileFile, "profile file", $userId, $dataDir)) {
    $deletedFiles[] = "profile";
}

// Move token file to trash (if exists)
$tokenFile = "$dataDir/userdata/tokens/{$userId}.txt";
if (moveUserDataToTrash($tokenFile, "token file", $userId, $dataDir)) {
    $deletedFiles[] = "token";
}

// Move avatar file to trash (if exists)
$avatarFile = "$dataDir/userdata/avatars/{$userId}.jpg";
if (moveUserDataToTrash($avatarFile, "avatar file", $userId, $dataDir)) {
    $deletedFiles[] = "avatar";
}

// Check for any other user-related files
$userDir = "$dataDir/userdata/chat";
if (is_dir($userDir)) {
    $chatFiles = glob("$userDir/{$userId}_*.txt");
    foreach ($chatFiles as $chatFile) {
        if (moveUserDataToTrash($chatFile, "chat file", $userId, $dataDir)) {
            $deletedFiles[] = "chat";
        }
    }
}

// Success message
$successMessage = "Account deletion processed. " . count($deletedFiles) . " file types moved to trash.";

// Add sync information
$syncInfo = "Files will be automatically removed from all sites via Syncthing within 15 seconds.";

// Return result
echo json_encode([
    'success' => true,
    'message' => $successMessage,
    'sync_info' => $syncInfo,
    'deleted_files' => $deletedFiles,
    'user_id' => $userId,
    'trash_location' => "$dataDir/userdata/.trash/" . date('Y-m-d'),
    'recovery_window' => '30 days',
    'warnings' => $warnings,
    'note' => 'Files are recoverable for 30 days. After 30 days, they will be permanently deleted.'
]);
?>
