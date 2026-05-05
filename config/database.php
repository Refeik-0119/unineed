<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set('Asia/Manila');

define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'unineeds');

// IONOS credentials 
// define('DB_HOST', 'db5018930086.hosting-data.io');
// define('DB_USER', 'dbu1862993');
// define('DB_PASS', 'bpcunineedspass.');
// define('DB_NAME', 'dbs14922247');

$conn = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if (!$conn) {
    die("Connection failed: " . mysqli_connect_error());
}

mysqli_set_charset($conn, "utf8");

@mysqli_query($conn, "SET time_zone = '+08:00'");

define('DOWN_PAYMENT_PERCENTAGE', 0.20);

function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function isSuperAdmin() {
    return isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'superadmin';
}

function isAdmin() {
    return isset($_SESSION['user_type']) && in_array($_SESSION['user_type'], ['admin', 'superadmin']);
}

function isStudent() {
    return isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'student';
}

function requireLogin() {
    if (!isLoggedIn()) {
        header('Location: /unineed/index.php');
        exit();
    }
}

function requireAdmin() {
    requireLogin();
    if (!isAdmin()) {
        header('Location: /unineed/student/products.php');
        exit();
    }
}

function requireSuperAdmin() {
    requireLogin();
    if (!isSuperAdmin()) {
        header('Location: /unineed/admin/dashboard.php');
        exit();
    }
}

function requireStudent() {
    requireLogin();
    if (!isStudent()) {
        $isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
                  || (strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false);
        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Authentication required']);
            exit();
        }
        header('Location: /unineed/admin/dashboard.php');
        exit();
    }

    global $conn;
    if (isset($_SESSION['user_id']) && !isset($_SESSION['require_password_change']) && basename($_SERVER['PHP_SELF']) !== 'settings.php') {
        $user_id = intval($_SESSION['user_id']);
        $res = mysqli_query($conn, "SELECT password FROM users WHERE user_id = $user_id LIMIT 1");
        if ($res && $row = mysqli_fetch_assoc($res)) {
            if (password_verify('@Student01', $row['password'])) {
                $_SESSION['require_password_change'] = true;
                header('Location: ' . dirname($_SERVER['REQUEST_URI']) . '/settings.php?force_change=1');
                exit();
            }
        }
    }
}

function clean($string) {
    global $conn;
    return mysqli_real_escape_string($conn, trim($string));
}

function formatCurrency($amount) {
    return '₱' . number_format($amount, 2);
}

function checkAndRunAutoArchive() {
    global $conn;
    
    try {
        $today = date('Y-m-d');
        
        $check_query = "SELECT setting_value FROM settings WHERE setting_key = 'last_archive_run_date'";
        $check_result = mysqli_query($conn, $check_query);
        $last_run_date = null;
        
        if ($check_result && mysqli_num_rows($check_result) > 0) {
            $row = mysqli_fetch_assoc($check_result);
            $last_run_date = $row['setting_value'];
        }
        
        if ($last_run_date !== $today) {
            $criteria_query = "SELECT setting_value FROM settings WHERE setting_key = 'archive_student_criteria'";
            $criteria_result = mysqli_query($conn, $criteria_query);
            
            if ($criteria_result && mysqli_num_rows($criteria_result) > 0) {
                $row = mysqli_fetch_assoc($criteria_result);
                $criteria_json = $row['setting_value'];
                $criteria_list = json_decode($criteria_json, true);
                
                if ($criteria_list && is_array($criteria_list) && count($criteria_list) > 0) {
                    $today_ts = strtotime($today);
                    $archived_count = 0;
                    $criteria_triggered = false;
                    
                    foreach ($criteria_list as $criteria) {
                        $criteria_date = isset($criteria['date']) ? trim($criteria['date']) : '';
                        $criteria_ts = $criteria_date ? strtotime($criteria_date) : false;
                        
                        if ($criteria_ts && $criteria_ts <= $today_ts) {
                            $criteria_triggered = true;
                            $course = isset($criteria['course']) ? trim($criteria['course']) : '';
                            $year_level = isset($criteria['year_level']) ? trim($criteria['year_level']) : '';
                            
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
                            
                            $archive_query = "UPDATE users SET status = 'archived' WHERE $where_clause";
                            if (mysqli_query($conn, $archive_query)) {
                                $affected = mysqli_affected_rows($conn);
                                $archived_count += $affected;
                            } else {
                                error_log("Archive query error: " . mysqli_error($conn));
                            }
                        }
                    }
                    
                    if ($criteria_triggered) {
                        $update_query = "UPDATE settings SET setting_value = '$today' WHERE setting_key = 'last_archive_run_date'";
                        if (!mysqli_query($conn, $update_query)) {
                            $insert_query = "INSERT INTO settings (setting_key, setting_value) VALUES ('last_archive_run_date', '$today')";
                            mysqli_query($conn, $insert_query);
                        }
                    }
                }
            }
        }
    } catch (Exception $e) {
        error_log("Auto archive error: " . $e->getMessage());
    }
}

checkAndRunAutoArchive();

?>