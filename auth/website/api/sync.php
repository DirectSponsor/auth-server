<?php
/**
 * DirectSponsor Multi-Site Sync Hub API
 * 
 * Provides centralized synchronization for balance, profile, roles, and settings
 * across all DirectSponsor network sites (ROFLFaucet, ClickForCharity, etc.)
 * 
 * Endpoints:
 * - GET ?action=timestamp - Lightweight timestamp check
 * - GET ?action=get - Fetch full data
 * - POST action=push - Receive updates from sites
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *'); // TODO: Restrict to specific domains in production
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Configuration
define('DATA_DIR', '/var/directsponsor-data/userdata');
define('BALANCE_DIR', DATA_DIR . '/balances');
define('PROFILE_DIR', DATA_DIR . '/profiles');
define('FAUCET_DIR', DATA_DIR . '/faucets');
define('MAX_REQUESTS_PER_MINUTE', 100);
define('LOG_FILE', '/var/directsponsor-data/sync.log');

// Ensure data directories exist
if (!is_dir(DATA_DIR)) {
    mkdir(DATA_DIR, 0755, true);
}
if (!is_dir(FAUCET_DIR)) {
    mkdir(FAUCET_DIR, 0755, true);
}

// Rate limiting check
function checkRateLimit($userId) {
    $rateLimitFile = "/tmp/sync_rate_{$userId}";
    $now = time();
    
    if (file_exists($rateLimitFile)) {
        $data = json_decode(file_get_contents($rateLimitFile), true);
        $windowStart = $data['window_start'] ?? 0;
        $count = $data['count'] ?? 0;
        
        // Reset window if more than 1 minute passed
        if ($now - $windowStart > 60) {
            $count = 0;
            $windowStart = $now;
        }
        
        if ($count >= MAX_REQUESTS_PER_MINUTE) {
            return false;
        }
        
        $count++;
    } else {
        $count = 1;
        $windowStart = $now;
    }
    
    file_put_contents($rateLimitFile, json_encode([
        'window_start' => $windowStart,
        'count' => $count
    ]));
    
    return true;
}

// Logging function
function logSync($message) {
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[{$timestamp}] {$message}\n";
    file_put_contents(LOG_FILE, $logMessage, FILE_APPEND);
}

// Get user balance file path (existing .txt format)
function getBalanceFilePath($userId) {
    $sanitized = preg_replace('/[^a-zA-Z0-9_-]/', '_', $userId);
    return BALANCE_DIR . "/{$sanitized}.txt";
}

// Load user data (reads existing .txt balance files)
function loadUserData($userId) {
    $balancePath = getBalanceFilePath($userId);
    
    // Try to load existing balance file
    if (file_exists($balancePath)) {
        $balanceData = json_decode(file_get_contents($balancePath), true);
        if ($balanceData) {
            // Convert ROFLFaucet format to hub format
            return [
                'user_id' => $userId,
                'profile' => [
                    'display_name' => '',
                    'avatar' => '👤',
                    'bio' => '',
                    'location' => '',
                    'website' => ''
                ],
                'balance' => [
                    'coins' => (float)($balanceData['balance'] ?? 0),
                    'points' => 0
                ],
                'global_roles' => [],
                'site_roles' => [],
                'last_profile_update' => null,
                'last_balance_update' => isset($balanceData['last_updated']) ? date('Y-m-d\TH:i:s\Z', $balanceData['last_updated']) : null,
                'last_sync' => null
            ];
        }
    }
    
    // Return default structure if no file
    return [
        'user_id' => $userId,
        'profile' => [
            'display_name' => '',
            'avatar' => '👤',
            'bio' => '',
            'location' => '',
            'website' => ''
        ],
        'balance' => [
            'coins' => 0,
            'points' => 0
        ],
        'global_roles' => [],
        'site_roles' => [],
        'last_profile_update' => null,
        'last_balance_update' => null,
        'last_sync' => null
    ];
}

// Get user profile file path
function getProfileFilePath($userId) {
    $sanitized = preg_replace('/[^a-zA-Z0-9_-]/', '_', $userId);
    return PROFILE_DIR . "/{$sanitized}.txt";
}

// Save user data (writes to balance or profile .txt file in ROFLFaucet format)
function saveUserData($userId, $data) {
    // logSync("SAVE_DEBUG: Attempting to save for user {$userId}");
    
    $success = true;
    
    // Save balance if present
    if (isset($data['balance'])) {
        $balancePath = getBalanceFilePath($userId);
        
        // Load existing balance file to preserve transactions
        $existingData = [];
        if (file_exists($balancePath)) {
            $contents = file_get_contents($balancePath);
            $existingData = json_decode($contents, true) ?? [];
        }
        
        // Update balance in ROFLFaucet format
        $balanceFileData = [
            'balance' => (float)($data['balance']['coins'] ?? 0),
            'last_updated' => time(),
            'recent_transactions' => $existingData['recent_transactions'] ?? []
        ];
        
        usleep(100000); // 0.1 second
        
        $json = json_encode($balanceFileData, JSON_PRETTY_PRINT);
        $result = file_put_contents($balancePath, $json);
        
        if ($result === false) {
            logSync("ERROR: Failed to save balance for user {$userId}");
            $success = false;
        } else {
            logSync("SAVE: User {$userId} balance updated to {$balanceFileData['balance']}");
        }
    }
    
    // Save profile if present
    if (isset($data['profile'])) {
        $profilePath = getProfileFilePath($userId);
        
        // Load existing profile to preserve fields not in sync
        $existingProfile = [];
        if (file_exists($profilePath)) {
            $contents = file_get_contents($profilePath);
            $existingProfile = json_decode($contents, true) ?? [];
        }
        
        // Merge profile data
        $profileFileData = array_merge($existingProfile, [
            'user_id' => $userId,
            'display_name' => $data['profile']['display_name'] ?? '',
            'avatar' => $data['profile']['avatar'] ?? '👤',
            'bio' => $data['profile']['bio'] ?? '',
            'location' => $data['profile']['location'] ?? '',
            'website' => $data['profile']['website'] ?? '',
            'last_profile_update' => time()
        ]);
        
        usleep(100000); // 0.1 second
        
        $json = json_encode($profileFileData, JSON_PRETTY_PRINT);
        $result = file_put_contents($profilePath, $json);
        
        if ($result === false) {
            logSync("ERROR: Failed to save profile for user {$userId}");
            $success = false;
        } else {
            logSync("SAVE: User {$userId} profile updated");
        }
    }
    
    return $success;
}

// Validate data type
function isValidDataType($dataType) {
    return in_array($dataType, ['profile', 'balance', 'points', 'roles', 'settings']);
}

// Get timestamp for specific data type
function getDataTimestamp($userData, $dataType) {
    switch ($dataType) {
        case 'profile':
            return $userData['last_profile_update'] ?? $userData['last_sync'] ?? null;
        case 'balance':
        case 'points':
            return $userData['last_balance_update'] ?? $userData['last_sync'] ?? null;
        case 'roles':
            return $userData['last_sync'] ?? null;
        case 'settings':
            return $userData['last_sync'] ?? null;
        default:
            return null;
    }
}

// Handle GET requests
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = $_GET['action'] ?? '';
    $userId = $_GET['user_id'] ?? '';
    $dataType = $_GET['data_type'] ?? '';
    
    // Validate inputs
    if (empty($userId)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Missing user_id']);
        exit;
    }
    
    // Rate limiting
    if (!checkRateLimit($userId)) {
        http_response_code(429);
        echo json_encode(['success' => false, 'error' => 'Rate limit exceeded']);
        logSync("RATE_LIMIT: User {$userId} exceeded rate limit");
        exit;
    }
    
    if ($action === 'timestamp') {
        // Lightweight timestamp check
        if (empty($dataType) || !isValidDataType($dataType)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid or missing data_type']);
            exit;
        }
        
        $userData = loadUserData($userId);
        if ($userData === null) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Failed to load user data']);
            exit;
        }
        
        $timestamp = getDataTimestamp($userData, $dataType);
        
        echo json_encode([
            'success' => true,
            'last_updated' => $timestamp ?? date('Y-m-d\TH:i:s\Z'),
            'data_type' => $dataType
        ]);
        
        // logSync("TIMESTAMP: User {$userId}, type {$dataType}, timestamp {$timestamp}");
        
    } elseif ($action === 'get') {
        // Fetch full data
        if (empty($dataType) || !isValidDataType($dataType)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid or missing data_type']);
            exit;
        }
        
        $userData = loadUserData($userId);
        if ($userData === null) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Failed to load user data']);
            exit;
        }
        
        // Extract requested data type
        $responseData = null;
        switch ($dataType) {
            case 'profile':
                // For lazy loading, just load and return the complete profile file
                $profilePath = getProfileFilePath($userId);
                if (file_exists($profilePath)) {
                    $profileData = json_decode(file_get_contents($profilePath), true);
                    if ($profileData) {
                        // Return the complete profile file contents
                        $responseData = $profileData;
                        break;
                    }
                }
                // Fallback if no profile file exists
                $responseData = [];
                break;
            case 'balance':
                $responseData = ['coins' => $userData['balance']['coins'] ?? 0];
                break;
            case 'points':
                $responseData = ['points' => $userData['balance']['points'] ?? 0];
                break;
            case 'roles':
                $responseData = [
                    'global_roles' => $userData['global_roles'] ?? [],
                    'site_roles' => $userData['site_roles'] ?? []
                ];
                break;
            case 'settings':
                $responseData = $userData['settings'] ?? [];
                break;
        }
        
        echo json_encode([
            'success' => true,
            'data' => $responseData,
            'last_updated' => getDataTimestamp($userData, $dataType)
        ]);
        
        // logSync("GET: User {$userId}, type {$dataType}");
        
    } else {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid action']);
    }
    
    exit;
}

// Handle POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid JSON']);
        exit;
    }
    
    $action = $input['action'] ?? '';
    $userId = $input['user_id'] ?? '';
    $siteId = $input['site_id'] ?? '';
    $dataType = $input['data_type'] ?? '';
    $data = $input['data'] ?? null;
    
    // Validate inputs
    if (empty($userId) || empty($siteId) || empty($dataType)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Missing required fields']);
        exit;
    }
    
    if (!isValidDataType($dataType)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid data_type']);
        exit;
    }
    
    // Rate limiting
    if (!checkRateLimit($userId)) {
        http_response_code(429);
        echo json_encode(['success' => false, 'error' => 'Rate limit exceeded']);
        logSync("RATE_LIMIT: User {$userId} exceeded rate limit");
        exit;
    }
    
    if ($action === 'push') {
        // Receive update from site
        $userData = loadUserData($userId);
        if ($userData === null) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Failed to load user data']);
            exit;
        }
        
        $now = date('Y-m-d\TH:i:s\Z');
        
        // Update the appropriate data section
        switch ($dataType) {
            case 'profile':
                $userData['profile'] = array_merge($userData['profile'] ?? [], $data);
                $userData['last_profile_update'] = $now;
                break;
            case 'balance':
                if (isset($data['coins'])) {
                    $userData['balance']['coins'] = $data['coins'];
                }
                $userData['last_balance_update'] = $now;
                break;
            case 'points':
                if (isset($data['points'])) {
                    $userData['balance']['points'] = $data['points'];
                }
                $userData['last_balance_update'] = $now;
                break;
            case 'roles':
                if (isset($data['global_roles'])) {
                    $userData['global_roles'] = $data['global_roles'];
                }
                if (isset($data['site_roles'])) {
                    $userData['site_roles'] = $data['site_roles'];
                }
                break;
            case 'settings':
                $userData['settings'] = array_merge($userData['settings'] ?? [], $data);
                break;
        }
        
        // Save updated data
        if (!saveUserData($userId, $userData)) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Failed to save data']);
            exit;
        }
        
        echo json_encode([
            'success' => true,
            'timestamp' => $now
        ]);
        
        logSync("PUSH: User {$userId}, site {$siteId}, type {$dataType}");
        
    } else {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid action']);
    }
    
    exit;
}

// Method not allowed
http_response_code(405);
echo json_encode(['success' => false, 'error' => 'Method not allowed']);
