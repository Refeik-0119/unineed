<?php
header('Content-Type: application/json');
require_once '../config/database.php';
requireAdmin();

// Only superadmins can access this
if ($_SESSION['user_type'] !== 'superadmin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied.']);
    exit;
}

$action = isset($_GET['action']) ? $_GET['action'] : (isset($_POST['action']) ? $_POST['action'] : '');

// Get current archive criteria
function get_archive_criteria() {
    global $conn;
    $query = "SELECT setting_value FROM settings WHERE setting_key = 'archive_student_criteria'";
    $result = mysqli_query($conn, $query);
    if ($result && mysqli_num_rows($result) > 0) {
        $row = mysqli_fetch_assoc($result);
        return json_decode($row['setting_value'], true) ?: [];
    }
    return [];
}

// Save archive criteria
function save_archive_criteria($criteria) {
    global $conn;
    $json = json_encode($criteria);
    $escaped_json = mysqli_real_escape_string($conn, $json);
    
    $check_query = "SELECT id FROM settings WHERE setting_key = 'archive_student_criteria'";
    $check_result = mysqli_query($conn, $check_query);
    
    if ($check_result && mysqli_num_rows($check_result) > 0) {
        $query = "UPDATE settings SET setting_value = '$escaped_json' WHERE setting_key = 'archive_student_criteria'";
    } else {
        $query = "INSERT INTO settings (setting_key, setting_value) VALUES ('archive_student_criteria', '$escaped_json')";
    }
    
    return mysqli_query($conn, $query);
}

// Handle Preview
if ($action === 'preview' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $archive_date = isset($_POST['archive_date']) ? $_POST['archive_date'] : '';
    $year_level = isset($_POST['year_level']) ? $_POST['year_level'] : '';
    $course = isset($_POST['course']) ? $_POST['course'] : '';
    
    $where_parts = [];
    $where_parts[] = "user_type = 'student'";
    $where_parts[] = "status = 'active'";
    
    if (!empty($year_level)) {
        $where_parts[] = "year_level = '" . intval($year_level) . "'";
    }
    
    if (!empty($course)) {
        $where_parts[] = "course = '" . mysqli_real_escape_string($conn, $course) . "'";
    }
    
    $where_clause = implode(" AND ", $where_parts);
    $query = "SELECT COUNT(*) as count FROM users WHERE $where_clause";
    $result = mysqli_query($conn, $query);
    $row = mysqli_fetch_assoc($result);
    
    echo json_encode(['success' => true, 'count' => (int)$row['count']]);
    exit;
}

// Handle Add
if ($action === 'add' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $archive_date = isset($_POST['archive_date']) ? trim($_POST['archive_date']) : '';
    $year_level = isset($_POST['year_level']) ? trim($_POST['year_level']) : '';
    $course = isset($_POST['course']) ? trim($_POST['course']) : '';
    
    if (empty($archive_date)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Archive date is required.']);
        exit;
    }
    
    // Validate date format
    $date_obj = DateTime::createFromFormat('Y-m-d', $archive_date);
    if (!$date_obj || $date_obj->format('Y-m-d') !== $archive_date) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid date format.']);
        exit;
    }
    
    $new_criteria = [
        'date' => $archive_date,
        'year_level' => $year_level,
        'course' => $course
    ];
    
    $criteria = get_archive_criteria();
    $criteria[] = $new_criteria;
    
    if (save_archive_criteria($criteria)) {
        $_SESSION['success'] = 'Archive criteria added successfully!';
        http_response_code(303);
        header('Location: ../admin/bulk-operations.php');
        exit;
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to save criteria.']);
        exit;
    }
}

// Handle Delete
if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $criteria_index = isset($_POST['criteria_index']) ? intval($_POST['criteria_index']) : -1;
    
    if ($criteria_index < 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid criteria index.']);
        exit;
    }
    
    $criteria = get_archive_criteria();
    
    if ($criteria_index >= count($criteria)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Criteria not found.']);
        exit;
    }
    
    array_splice($criteria, $criteria_index, 1);
    
    if (save_archive_criteria($criteria)) {
        $_SESSION['success'] = 'Archive criteria deleted successfully!';
        http_response_code(303);
        header('Location: ../admin/bulk-operations.php');
        exit;
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to delete criteria.']);
        exit;
    }
}

// If no valid action
http_response_code(400);
echo json_encode(['success' => false, 'message' => 'No valid action specified.']);
?>
