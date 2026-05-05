<?php
header('Content-Type: application/json');
require_once '../config/database.php';
requireAdmin();

// Only superadmins can access
if ($_SESSION['user_type'] !== 'superadmin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

$action = isset($_POST['action']) ? clean($_POST['action']) : '';

if ($action === 'update') {
    $user_ids = isset($_POST['user_ids']) ? $_POST['user_ids'] : '';
    $year_level = isset($_POST['year_level']) ? intval($_POST['year_level']) : 0;

    if (empty($user_ids) || !$year_level) {
        echo json_encode(['success' => false, 'message' => 'Invalid input: Missing user IDs or year level']);
        exit;
    }

    // Sanitize IDs
    $id_array = array_map('intval', explode(',', $user_ids));
    $id_array = array_filter($id_array); // Remove zeros
    
    if (empty($id_array)) {
        echo json_encode(['success' => false, 'message' => 'Invalid: No valid student IDs provided']);
        exit;
    }
    
    $id_list = implode(',', $id_array);

    // Validate year level
    if (!in_array($year_level, [1, 2, 3, 4])) {
        echo json_encode(['success' => false, 'message' => 'Invalid year level: Must be 1, 2, 3, or 4']);
        exit;
    }

    try {
        // Update the year level (no transaction needed for single UPDATE)
        $query = "UPDATE users SET year_level = $year_level WHERE user_id IN ($id_list) AND user_type = 'student'";
        
        if (!mysqli_query($conn, $query)) {
            throw new Exception('Database error: ' . mysqli_error($conn));
        }

        $affected = mysqli_affected_rows($conn);

        echo json_encode([
            'success' => true,
            'message' => ($affected > 0 ? $affected . ' student(s) year level updated successfully' : 'No students updated')
        ]);

    } catch (Exception $e) {
        error_log("Bulk update error: " . $e->getMessage());
        echo json_encode([
            'success' => false,
            'message' => 'Error: ' . $e->getMessage()
        ]);
    }

} else {
    echo json_encode(['success' => false, 'message' => 'Invalid action']);
}
?>
