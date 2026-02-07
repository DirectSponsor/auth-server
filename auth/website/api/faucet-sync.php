<?php
/**
 * Faucet List Sync Endpoint
 * Simple JSON file storage for faucetlist.org user data
 * 
 * GET ?user_id=xxx - Load user's faucet data
 * POST - Save user's faucet data
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$faucetDataDir = '/var/directsponsor-data/faucetlist';

if (!is_dir($faucetDataDir)) {
    mkdir($faucetDataDir, 0755, true);
}

$userId = $_GET['user_id'] ?? '';
$userId = preg_replace('/[^a-zA-Z0-9_-]/', '_', $userId);

if (empty($userId)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing user_id']);
    exit;
}

$filePath = $faucetDataDir . '/' . $userId . '.json';

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (file_exists($filePath)) {
        $data = json_decode(file_get_contents($filePath), true);
        echo json_encode(['success' => true, 'faucets' => $data]);
    } else {
        echo json_encode(['success' => true, 'faucets' => []]);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $faucets = $input['faucets'] ?? [];

    $json = json_encode($faucets, JSON_PRETTY_PRINT);
    $result = file_put_contents($filePath, $json);

    if ($result === false) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Failed to save data']);
    } else {
        echo json_encode(['success' => true]);
    }
    exit;
}

http_response_code(405);
echo json_encode(['success' => false, 'error' => 'Method not allowed']);
