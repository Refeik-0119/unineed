<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

$is_cli = php_sapi_name() === 'cli';
if (!$is_cli) {
    require_once '../config/database.php';
    requireAdmin();
    
n') {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Access denied.']);
        exit;
    }
    
    header('Content-Type: application/json');
} else {
    // CLI mode - just load the database config
    require_once __DIR__ . '/../config/database.php';
}
// Run the auto archive check
try {
try {
    $response = [
        'success' => true,
        'message' => 'Archive scheduler executed successfully.',
        'timestamp' => date('Y-m-d H:i:s')
    ];
    
    if ($is_cli) {
        echo "[" . date('Y-m-d H:i:s') . "] Archive scheduler executed successfully.\n";
    } else {
        echo json_encode($response);
    }
} catch (Exception $e) {
    $response = [
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ];
    
    if ($is_cli) {
        echo "[" . date('Y-m-d H:i:s') . "] Error: " . $e->getMessage() . "\n";
        exit(1);
    } else {
        http_response_code(500);
        echo json_encode($response);
    }
}
?>
