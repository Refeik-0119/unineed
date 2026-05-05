<?php

require_once '../config/database.php';

// Check if user is superadmin
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'superadmin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

header('Content-Type: application/json');

$course_map = [
    'BSIS' => 'Bachelor of Science in Information Systems (BSIS)',
    'BSOM' => 'Bachelor of Science in Office Management (BSOM)',
    'BSAIS' => 'Bachelor of Science in Accounting Information System (BSAIS)',
    'BTVTED' => 'Bachelor of Technical Vocational Teacher Education (BTVTED)',
    'BSCA' => 'Bachelor of Science in Customs Administration (BSCA)',
    'ACT' => 'Associate in Computer Technology',
    'DHRMT' => 'Diploma in Hotel and Restaurant Management Technology (DHRMT)',
    'HB' => 'Hotel and Restaurant Services (Bundled) HB',
    'SMAW' => 'Shielded Metal Arc Welding (SMAW)',
    'BOOKKEEPING' => 'Bookkeeping',
    'EIM' => 'Electrical Installations and Maintenance (EIM)'
];

function normalizeCourseName($course, $course_map) {
    $course = trim($course);
    if ($course === '') {
        return '';
    }

    $upper = strtoupper($course);
    if (isset($course_map[$upper])) {
        return $course_map[$upper];
    }

    foreach ($course_map as $mapped) {
        if (strcasecmp($course, $mapped) === 0) {
            return $mapped;
        }
    }

    return $course;
}

$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? $_POST['action'] ?? $_GET['action'] ?? '';

if ($action === 'confirm_upload' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($input['preview_data']) || !is_array($input['preview_data'])) {
        echo json_encode(['success' => false, 'message' => 'Invalid data']);
        exit();
    }
    
    $preview_data = $input['preview_data'];
    $results = [];
    
    foreach ($preview_data as $data) {
        $result = [
            'row' => $data['row'],
            'data' => $data,
            'status' => 'error',
            'message' => ''
        ];
        
        // Validate required fields
        if (empty($data['email'])) {
            $result['message'] = 'Email is required';
            $results[] = $result;
            continue;
        }
        
        if (empty($data['full_name'])) {
            $result['message'] = 'Full name is required';
            $results[] = $result;
            continue;
        }
        
        // Clean email
        $email = clean($data['email']);
        
        // Validate email format
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $result['message'] = 'Invalid email format';
            $results[] = $result;
            continue;
        }
        
        $full_name = clean($data['full_name']);
        $phone = preg_replace('/\D+/', '', clean($data['phone'] ?? ''));
        if (strlen($phone) === 10) {
            $phone = '0' . $phone;
        } elseif (strlen($phone) === 12 && substr($phone, 0, 2) === '63') {
            $phone = '0' . substr($phone, 2);
        }
        $course = normalizeCourseName(clean($data['course'] ?? ''), $course_map);
        $year_level = clean($data['year_level'] ?? '');
        $section = clean($data['section'] ?? '');
        $student_id = !empty($data['student_id']) ? clean($data['student_id']) : '';
        
        // Ensure MA- prefix for student_id
        if (!empty($student_id)) {
            if (stripos($student_id, 'MA-') === 0) {
                $student_id = 'MA-' . preg_replace('/\D+/', '', substr($student_id, 3));
            } else {
                $student_id = 'MA-' . preg_replace('/\D+/', '', $student_id);
            }
            
            // Validate student_id format
            if (!preg_match('/^MA-\d+$/', $student_id)) {
                $result['message'] = 'Invalid student ID format';
                $results[] = $result;
                continue;
            }
        }
        
        // Check for duplicates
        $check_conditions = ["email = '$email'"];
        if (!empty($student_id)) {
            $check_conditions[] = "student_id = '$student_id'";
        }
        
        $check_query = 'SELECT * FROM users WHERE ' . implode(' OR ', $check_conditions);
        $check_result = mysqli_query($conn, $check_query);
        
        if (mysqli_num_rows($check_result) > 0) {
            $existing = mysqli_fetch_assoc($check_result);
            if ($existing['email'] === $email) {
                $result['message'] = 'Email already exists (' . $existing['full_name'] . ')';
            } elseif ($existing['student_id'] === $student_id) {
                $result['message'] = 'Student ID already exists (' . $existing['full_name'] . ')';
            }
            $results[] = $result;
            continue;
        }
        
        // Generate password
        $password = password_hash('@Student01', PASSWORD_DEFAULT);
        
        // Insert student
        $insert_query = "INSERT INTO users (email, password, user_type, full_name, phone, student_id, course, year_level, section) 
                         VALUES ('$email', '$password', 'student', '$full_name', '$phone', '$student_id', '$course', '$year_level', '$section')";
        
        if (mysqli_query($conn, $insert_query)) {
            $result['status'] = 'success';
            $result['message'] = 'Student added successfully';
            $result['data']['password'] = '@Student01'; // Show default password in response
        } else {
            $result['message'] = 'Database error: ' . mysqli_error($conn);
        }
        
        $results[] = $result;
    }
    
    echo json_encode(['success' => true, 'results' => $results]);
    exit();
}

echo json_encode(['success' => false, 'message' => 'Invalid action']);
exit();
