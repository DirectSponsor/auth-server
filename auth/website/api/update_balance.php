<?php
/**
 * Centralized Balance Update API
 * 
 * Receives balance change requests from all sites
 * Maintains single source of truth for user balances
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'POST method required']);
    exit;
}

// Shared secret — only our own servers may call this
$_secret_file = '/etc/ds-balance-secret';
if (!file_exists($_secret_file)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Balance secret not configured on server']);
    exit;
}
define('BALANCE_SECRET', trim(file_get_contents($_secret_file)));

$input = json_decode(file_get_contents('php://input'), true);

if (($input['secret'] ?? '') !== BALANCE_SECRET) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Forbidden']);
    exit;
}

if (!isset($input['user_id']) || !isset($input['amount'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing user_id or amount']);
    exit;
}

$userId = $input['user_id'];
$amount = floatval($input['amount']);
$source = $input['source'] ?? 'unknown';
$serverId = $input['server_id'] ?? 'unknown';

// Validate user_id format
if (!preg_match('/^\d+-[a-zA-Z0-9_-]+$/', $userId)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid user_id format']);
    exit;
}

// Balance file path
$dataDir = '/var/directsponsor-data/userdata/balances';
$balanceFile = $dataDir . '/' . $userId . '.txt';

// Ensure directory exists
if (!is_dir($dataDir)) {
    mkdir($dataDir, 0755, true);
}

// Use file locking to prevent race conditions
$lockFile = $balanceFile . '.lock';
$lock = fopen($lockFile, 'c');
if (!flock($lock, LOCK_EX)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Could not acquire lock']);
    exit;
}

try {
    // Read current balance
    $currentBalance = 0;
    if (file_exists($balanceFile)) {
        $data = json_decode(file_get_contents($balanceFile), true);
        if ($data && isset($data['balance'])) {
            $currentBalance = floatval($data['balance']);
        }
    }
    
    // Calculate new balance
    $newBalance = $currentBalance + $amount;
    
    // Prevent negative balance
    if ($newBalance < 0) {
        $newBalance = 0;
    }
    
    // Prepare balance data
    $balanceData = [
        'user_id' => $userId,
        'balance' => $newBalance,
        'last_updated' => time(),
        'last_source' => $source,
        'last_server' => $serverId
    ];
    
    // Write to file
    $writeSuccess = file_put_contents($balanceFile, json_encode($balanceData, JSON_PRETTY_PRINT));
    
    if ($writeSuccess === false) {
        throw new Exception('Failed to write balance file');
    }
    
    // Return success
    echo json_encode([
        'success' => true,
        'balance' => $newBalance,
        'previous_balance' => $currentBalance,
        'amount' => $amount,
        'timestamp' => time()
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} finally {
    // Release lock
    flock($lock, LOCK_UN);
    fclose($lock);
}
?>
