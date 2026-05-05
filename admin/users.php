<?php

require_once '../config/database.php';
requireAdmin();
$is_superadmin = isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'superadmin';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

if (isset($_GET['action']) && $_GET['action'] === 'download_template') {
    $filename = 'students_bulk_upload_template.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=' . $filename);
    
    echo "student_id,full_name,email,phone,course,year_level,section\n";
    echo "20240001,Justine Martin,justine.martin@example.com,09123456789,BSIS,1,A\n";
    echo "20240002,Juan Santos,juan.santos@example.com,09234567890,BSOM,1,B\n";
    echo "20240003,Maria Cruz,maria.cruz@example.com,09345678901,BSAIS,2,A\n";
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_user'])) {
    $email = clean($_POST['email']);
    $full_name = clean($_POST['full_name']);
    $phone = clean($_POST['phone']);
    $student_id = isset($_POST['student_id']) ? clean($_POST['student_id']) : '';
    $user_type = $is_superadmin && isset($_POST['user_type']) ? clean($_POST['user_type']) : 'student';
    if (!in_array($user_type, ['student', 'admin'])) {
        $user_type = 'student';
    }

    if (!empty($student_id)) {
        if (stripos($student_id, 'MA-') === 0) {
            $student_id = 'MA-' . preg_replace('/\D+/', '', substr($student_id, 3));
        } else {
            $student_id = 'MA-' . preg_replace('/\D+/', '', $student_id);
        }
    }

    $course = isset($_POST['course']) ? clean($_POST['course']) : '';
    $year_level = isset($_POST['year_level']) ? clean($_POST['year_level']) : '';
    $section = isset($_POST['section']) ? clean($_POST['section']) : '';

    if ($user_type === 'admin') {
        $course = '';
        $year_level = '';
        $section = '';
    }

    $password = password_hash(
        $user_type === 'admin' ? '@Admin01' : '@Student01',
        PASSWORD_DEFAULT
    );
    
    $check_conditions = ["email = '$email'"];
    if (!empty($student_id)) {
        $check_conditions[] = "student_id = '$student_id'";
    }
    $check_query = 'SELECT * FROM users WHERE ' . implode(' OR ', $check_conditions);
    $check_result = mysqli_query($conn, $check_query);
    
    if (mysqli_num_rows($check_result) > 0) {
        $duplicate_emails = [];
        $duplicate_student_ids = [];
        mysqli_data_seek($check_result, 0);
        while ($row = mysqli_fetch_assoc($check_result)) {
            if ($row['email'] === $email) {
                $duplicate_emails[] = [
                    'name' => $row['full_name'],
                    'student_id' => $row['student_id'],
                    'course' => $row['course'],
                    'year_level' => $row['year_level'],
                    'section' => $row['section']
                ];
            }
            if ($row['student_id'] === $student_id) {
                $duplicate_student_ids[] = [
                    'name' => $row['full_name'],
                    'email' => $row['email'],
                    'course' => $row['course'],
                    'year_level' => $row['year_level'],
                    'section' => $row['section']
                ];
            }
        }
        if (!empty($duplicate_emails)) {
            $error = "<strong>Email already exists!</strong><br><table class='table table-sm table-bordered mt-2'><thead><tr><th>Name</th><th>Student ID</th><th>Course</th><th>Year</th><th>Section</th></tr></thead><tbody>";
            foreach ($duplicate_emails as $dup) {
                $error .= "<tr><td>{$dup['name']}</td><td>{$dup['student_id']}</td><td>{$dup['course']}</td><td>{$dup['year_level']}</td><td>{$dup['section']}</td></tr>";
            }
            $error .= "</tbody></table>";
        } elseif (!empty($duplicate_student_ids)) {
            $error = "<strong>Student ID already exists!</strong><br><table class='table table-sm table-bordered mt-2'><thead><tr><th>Name</th><th>Email</th><th>Course</th><th>Year</th><th>Section</th></tr></thead><tbody>";
            foreach ($duplicate_student_ids as $dup) {
                $error .= "<tr><td>{$dup['name']}</td><td>{$dup['email']}</td><td>{$dup['course']}</td><td>{$dup['year_level']}</td><td>{$dup['section']}</td></tr>";
            }
            $error .= "</tbody></table>";
        }
    } else {
        $query = "INSERT INTO users (email, password, user_type, full_name, phone, student_id, course, year_level, section) 
                 VALUES ('$email', '$password', '$user_type', '$full_name', '$phone', '$student_id', '$course', '$year_level', '$section')";
        if (mysqli_query($conn, $query)) {
            if ($user_type === 'admin') {
                $success = "Admin added successfully! Default password: @Admin01";
                // Redirect to admin accounts list
                header('Location: users.php?user_type=admin');
                exit();
            } else {
                $success = "Student added successfully! Default password: @Student01";
                // Redirect to student accounts list
                header('Location: users.php?user_type=student');
                exit();
            }
        } else {
            $error = "Failed to add user.";
        }
    }
}

// Handle Edit User
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_user'])) {
    $user_id = clean($_POST['user_id']);
    $email = clean($_POST['email']);
    $full_name = clean($_POST['full_name']);
    $phone = clean($_POST['phone']);
    $student_id = isset($_POST['student_id']) ? clean($_POST['student_id']) : '';
    $user_type = $is_superadmin && isset($_POST['user_type']) ? clean($_POST['user_type']) : 'student';
    if (!in_array($user_type, ['student', 'admin'])) {
        $user_type = 'student';
    }
    $course = isset($_POST['course']) ? clean($_POST['course']) : '';
    $year_level = isset($_POST['year_level']) ? clean($_POST['year_level']) : '';
    $section = isset($_POST['section']) ? clean($_POST['section']) : ''; 
    
    if ($user_type === 'admin' || $user_type === 'superadmin') {
        $course = '';
        $year_level = '';
        $section = '';
    }

    // Check for duplicate email (excluding current user)
    $check_email = "SELECT * FROM users WHERE email = '$email' AND user_id != $user_id";
    $check_email_result = mysqli_query($conn, $check_email);
    
    // Check for duplicate student_id (excluding current user)
    $check_student_id_result = false;
    if (!empty($student_id)) {
        $check_student_id = "SELECT * FROM users WHERE student_id = '$student_id' AND user_id != $user_id";
        $check_student_id_result = mysqli_query($conn, $check_student_id);
    }
    
    if (mysqli_num_rows($check_email_result) > 0) {
        $error = "Email already exists!";
    } elseif ($check_student_id_result && mysqli_num_rows($check_student_id_result) > 0) {
        $error = "Student ID already exists!";
    } else {
        $query = "UPDATE users SET 
                 email = '$email',
                 full_name = '$full_name',
                 phone = '$phone',
                 student_id = '$student_id',
                 course = '$course',
                 year_level = '$year_level',
                 section = '$section',
                 user_type = '$user_type'
                 WHERE user_id = $user_id";
        if (mysqli_query($conn, $query)) {
            $success = "User updated successfully!";
        } else {
            $error = "Failed to update user.";
        }
    }
}

// Handle Archive/Delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['archive_user'])) {
    $user_id = clean($_POST['user_id']);
    $query = "UPDATE users SET status = 'archived' WHERE user_id = $user_id";
    if (mysqli_query($conn, $query)) {
        $success = "Student archived successfully!";
    }
}

// Handle Activate (un-archive)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['activate_user'])) {
    $user_id = clean($_POST['user_id']);
    $query = "UPDATE users SET status = 'active' WHERE user_id = $user_id";
    if (mysqli_query($conn, $query)) {
        $success = "Student activated successfully!";
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_user'])) {
    $user_id = clean($_POST['user_id']);
    
    // Start transaction with foreign key constraint disabled
    mysqli_query($conn, "SET FOREIGN_KEY_CHECKS=0");
    mysqli_begin_transaction($conn);
    try {
        // Delete all related records - order doesn't matter with FK checks disabled
        $tables_to_delete = [
            "DELETE FROM inventory_movements WHERE created_by = $user_id",
            "DELETE FROM notifications WHERE user_id = $user_id",
            "DELETE FROM invoices WHERE order_id IN (SELECT order_id FROM orders WHERE user_id = $user_id)",
            "DELETE FROM order_items WHERE order_id IN (SELECT order_id FROM orders WHERE user_id = $user_id)",
            "DELETE FROM orders WHERE user_id = $user_id",
            "DELETE FROM users WHERE user_id = $user_id"
        ];
        
        foreach ($tables_to_delete as $query) {
            if (!mysqli_query($conn, $query)) {
                throw new Exception("Query failed: " . mysqli_error($conn));
            }
        }
        
        mysqli_commit($conn);
        mysqli_query($conn, "SET FOREIGN_KEY_CHECKS=1");
        $success = "Student and all related records deleted successfully!";
    } catch (Exception $e) {
        mysqli_rollback($conn);
        mysqli_query($conn, "SET FOREIGN_KEY_CHECKS=1");
        $error = "Failed to delete student: " . $e->getMessage();
    }
}

// Handle Bulk Archive/Unarchive Selected
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_archive_selected'])) {
    $selected = isset($_POST['selected_ids']) ? trim($_POST['selected_ids']) : '';
    $action = isset($_POST['archive_action']) ? $_POST['archive_action'] : 'archive';
    $ids = array_filter(array_map('intval', explode(',', $selected)));
    if (count($ids) > 0) {
        $id_list = implode(',', $ids);
        $target_status = ($action === 'unarchive') ? 'active' : 'archived';
        $query = "UPDATE users SET status = '$target_status' WHERE user_id IN ($id_list)";
        if (mysqli_query($conn, $query)) {
            $success = ($action === 'unarchive') ? "Selected students unarchived successfully!" : "Selected students archived successfully!";
        } else {
            $error = ($action === 'unarchive') ? "Failed to unarchive selected students." : "Failed to archive selected students.";
        }
    } else {
        $error = 'No students selected.';
    }
}

// Handle Bulk Delete Selected
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_delete_selected'])) {
    $selected = isset($_POST['selected_ids']) ? trim($_POST['selected_ids']) : '';
    $ids = array_filter(array_map('intval', explode(',', $selected)));
    if (count($ids) > 0) {
        $id_list = implode(',', $ids);
        // Start transaction with foreign key constraint disabled
        mysqli_query($conn, "SET FOREIGN_KEY_CHECKS=0");
        mysqli_begin_transaction($conn);
        try {
            $tables_to_delete = [
                "DELETE FROM inventory_movements WHERE created_by IN ($id_list)",
                "DELETE FROM notifications WHERE user_id IN ($id_list)",
                "DELETE FROM invoices WHERE order_id IN (SELECT order_id FROM orders WHERE user_id IN ($id_list))",
                "DELETE FROM order_items WHERE order_id IN (SELECT order_id FROM orders WHERE user_id IN ($id_list))",
                "DELETE FROM orders WHERE user_id IN ($id_list)",
                "DELETE FROM users WHERE user_id IN ($id_list)"
            ];
            foreach ($tables_to_delete as $query) {
                if (!mysqli_query($conn, $query)) {
                    throw new Exception("Query failed: " . mysqli_error($conn));
                }
            }
            mysqli_commit($conn);
            mysqli_query($conn, "SET FOREIGN_KEY_CHECKS=1");
            $success = "Selected students and related records deleted successfully!";
        } catch (Exception $e) {
            mysqli_rollback($conn);
            mysqli_query($conn, "SET FOREIGN_KEY_CHECKS=1");
            $error = "Failed to delete selected students: " . $e->getMessage();
        }
    } else {
        $error = 'No students selected.';
    }
}

// Get users
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_GET['user_type']) && isset($_POST['user_type'])) {
        $_GET['user_type'] = clean($_POST['user_type']);
    }
    if (empty($_GET['status']) && isset($_POST['status'])) {
        $_GET['status'] = clean($_POST['status']);
    }
}
$status_filter = isset($_GET['status']) ? clean($_GET['status']) : 'active';

$user_type_filter = isset($_GET['user_type']) ? clean($_GET['user_type']) : '';
$search = isset($_GET['search']) ? clean($_GET['search']) : '';
$course_filter = isset($_GET['course_filter']) ? clean($_GET['course_filter']) : ''; 
$year_level_filter = isset($_GET['year_level_filter']) ? clean($_GET['year_level_filter']) : '';
$section_filter = isset($_GET['section_filter']) ? clean($_GET['section_filter']) : '';

$default_add_type = '';
if ($is_superadmin && isset($_GET['add_type']) && in_array($_GET['add_type'], ['student', 'admin'])) {
    $default_add_type = clean($_GET['add_type']);
    $user_type_filter = $default_add_type; // Set filter immediately
}
// Fallback: if no filter but we have a default, set it
if ($is_superadmin && empty($user_type_filter) && !empty($default_add_type)) {
    $user_type_filter = $default_add_type;
}

// Default to student accounts for superadmin when no explicit type is set
if ($is_superadmin && empty($user_type_filter)) {
    $user_type_filter = 'student';
}

// Define available courses (used in Modals and Filters)
$all_courses = [
    'Bachelor of Science in Information Systems (BSIS)',
    'Bachelor of Science in Office Management (BSOM)',
    'Bachelor of Science in Accounting Information System (BSAIS)',
    'Bachelor of Technical Vocational Teacher Education (BTVTED)',
    'Bachelor of Science in Customs Administration (BSCA)',
    'Associate in Computer Technology',
    'Diploma in Hotel and Restaurant Management Technology (DHRMT)',
    'Hotel and Restaurant Services (Bundled) HB',
    'Shielded Metal Arc Welding (SMAW)',
    'Bookkeeping',
    'Electrical Installations and Maintenance (EIM)'
];

// --- FIXED DROPDOWN VALUES AS REQUESTED (1-4 and A-J) ---
$fixed_year_levels = ['1', '2', '3', '4'];
$fixed_sections = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J'];
// ---

$where_clauses = [];
if ($is_superadmin) {
    if ($user_type_filter) {
        $where_clauses[] = "user_type = '$user_type_filter'";
    }
} else {
    $where_clauses[] = "user_type = 'student'";
}
if ($status_filter) {
    $where_clauses[] = "status = '$status_filter'";
}
if ($search) {
    $where_clauses[] = "(full_name LIKE '%$search%' OR email LIKE '%$search%' OR student_id LIKE '%$search%')";
}
if ($course_filter) {
    $where_clauses[] = "course = '$course_filter'";
}
if ($year_level_filter) {
    $where_clauses[] = "year_level = '$year_level_filter'";
}
if ($section_filter) {
    $where_clauses[] = "section = '$section_filter'";
}

$where_sql = 'WHERE ' . implode(' AND ', $where_clauses);


$query = "SELECT * FROM users $where_sql ORDER BY created_at DESC";
$users = mysqli_query($conn, $query);

$page_title = $is_superadmin ? 'Manage User Accounts' : 'Student Users';
if ($is_superadmin) {
    if ($user_type_filter === 'admin') {
        $page_title = 'Admin Accounts';
    } elseif ($user_type_filter === 'student') {
        $page_title = 'Student Accounts';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Users - UniNeeds Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/mobile.css">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function showAddUserModal() {
            document.getElementById('addUserModal').style.display = 'block';
        }
        
        function closeAddUserModal() {
            document.getElementById('addUserModal').style.display = 'none';
        }
        
        function showBulkUploadModal() {
            document.getElementById('bulkUploadModal').style.display = 'block';
        }
        
        function closeBulkUploadModal() {
            document.getElementById('bulkUploadModal').style.display = 'none';
        }
    </script>
</head>
<body>
    <?php include 'includes/sidebar.php'; ?>
    
    <div class="main-content">
            <div class="top-bar">
            <button class="btn btn-link d-md-none" id="sidebarToggle">
                <i class="bi bi-list fs-3"></i>
            </button>
            <h2><?php echo $page_title; ?></h2>
            <div class="ms-auto">
                <?php if ($is_superadmin && empty($default_add_type)): ?>
                <button class="btn btn-primary" onclick="showAddUserModal()">
                    <i class="bi bi-person-plus me-2"></i>Add Account
                </button>
                <?php elseif ($is_superadmin): ?>
                <button class="btn btn-primary" onclick="showAddUserModal()">
                    <i class="bi bi-person-plus me-2"></i>Add <?php echo ucfirst($default_add_type); ?>
                </button>
                <?php if ($default_add_type === 'student'): ?>
                <button class="btn btn-success ms-2" onclick="showBulkUploadModal()">
                    <i class="bi bi-cloud-upload me-2"></i>Bulk Upload
                </button>
                <?php endif; ?>
                <?php endif; ?>
                <!-- Bulk action buttons moved to table header -->
                <a href="users.php?user_type=<?php echo htmlspecialchars($user_type_filter); ?>&status=archived" class="btn btn-outline-secondary ms-2">
                    <i class="bi bi-archive me-2"></i>View Archived
                </a>
            </div>
        </div>
        
        <div class="content-area">
            <?php if ($is_superadmin && !empty($default_add_type)): ?>
                <div class="alert alert-secondary">
                    Showing only <strong><?php echo ucfirst($default_add_type); ?></strong> accounts.
                </div>
            <?php endif; ?>
            <?php if (isset($success)): ?>
                <div class="alert alert-success alert-dismissible fade show">
                    <i class="bi bi-check-circle me-2"></i><?php echo $success; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            
            <?php if (isset($error)): ?>
                <div class="alert alert-danger alert-dismissible fade show">
                    <i class="bi bi-exclamation-triangle me-2"></i><?php echo $error; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            
            <div class="filter-bar">
                <form method="GET" class="row g-3 align-items-end" id="filterForm">
                    <div class="<?php echo ($is_superadmin && $user_type_filter === 'admin') ? 'col-md-4' : 'col-md-2'; ?>">
                        <label class="form-label d-none d-md-block">Search</label>
                        <input type="text" class="form-control filter-input" name="search" placeholder="Name/Email/ID" value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                    <?php if ($user_type_filter !== 'admin'): ?>
                        <input type="hidden" name="user_type" value="student">
                    <?php endif; ?>
                    <?php if ($user_type_filter !== 'admin' || !$is_superadmin): ?>
                    <div class="col-md-2">
                        <label class="form-label d-none d-md-block">Course</label>
                        <select class="form-select filter-input" name="course_filter">
                            <option value="">All Courses</option>
                            <?php foreach ($all_courses as $course_option): ?>
                                <option value="<?php echo htmlspecialchars($course_option); ?>" <?php echo $course_filter === $course_option ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($course_option); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label d-none d-md-block">Year</label>
                        <select class="form-select filter-input" name="year_level_filter">
                            <option value="">All Years</option>
                            <?php foreach ($fixed_year_levels as $year_option): ?>
                                <option value="<?php echo htmlspecialchars($year_option); ?>" <?php echo $year_level_filter === $year_option ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($year_option); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label d-none d-md-block">Section</label>
                        <select class="form-select filter-input" name="section_filter">
                            <option value="">All Sections</option>
                            <?php foreach ($fixed_sections as $section_option): ?>
                                <option value="<?php echo htmlspecialchars($section_option); ?>" <?php echo $section_filter === $section_option ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($section_option); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>
                    <div class="<?php echo ($is_superadmin && $user_type_filter === 'admin') ? 'col-md-3' : 'col-md-2'; ?>">
                        <label class="form-label d-none d-md-block">Status</label>
                        <select class="form-select filter-input" name="status">
                            <option value="active" <?php echo $status_filter === 'active' ? 'selected' : ''; ?>>Active</option>
                            <option value="archived" <?php echo $status_filter === 'archived' ? 'selected' : ''; ?>>Archived</option>
                        </select>
                    </div>
                </form>
            </div>
            
            <div class="card">
                <div class="card-body p-0">
                    <!-- Bulk Action Toolbar -->
                    <?php if ($is_superadmin): ?>
<div id="bulkActionToolbar" style="display: none; padding: 15px; background-color: #f8f9fa; border-bottom: 1px solid #dee2e6; justify-content: space-between; align-items: center;">
                        <div>
                            <span id="selectedCount" style="font-weight: 500; color: #495057;"></span>
                        </div>
                        <div style="display: flex; gap: 0.5rem;">
                            <button type="button" id="bulkArchiveBtn" class="btn btn-sm btn-warning">
                                <i class="bi bi-archive me-1"></i><span id="archiveBtnText">Archive Selected</span>
                            </button>
                            <button type="button" id="bulkDeleteBtn" class="btn btn-sm btn-danger">
                                <i class="bi bi-trash me-1"></i>Delete Selected
                            </button>
                        </div>
                    </div>
                    <?php endif; ?>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <?php if ($is_superadmin): ?>
                                    <th style="width:50px; text-align:center;">
                                        <input type="checkbox" id="selectAll">
                                    </th>
                                    <?php endif; ?>
                                    <th>Student ID</th>
                                    <th>Name</th>
                                    <th>Type</th>
                                    <th>Email</th>
                                    <th>Phone</th>
                                    <?php if (!($is_superadmin && $user_type_filter === 'admin')): ?>
                                    <th>Course</th>
                                    <th>Year Level</th>
                                    <th>Section</th>
                                    <?php endif; ?>
                                    <th style="display: none;">Status</th>
                                    <?php if ($is_superadmin): ?>
                                    <th>Actions</th>
                                    <?php endif; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $edit_modals = ''; ?>
                                <?php if (mysqli_num_rows($users) > 0): ?>
                                    <?php while ($user = mysqli_fetch_assoc($users)):
                                        // normalize year level to numeric only (strip ordinal text)
                                        $user['year_level'] = preg_replace('/\D/', '', $user['year_level']); ?>
                                        <tr>
                                            <?php if ($is_superadmin): ?>
                                            <td style="vertical-align: middle; text-align:center;"><input type="checkbox" class="select-row" data-user-type="<?php echo $user['user_type']; ?>" value="<?php echo $user['user_id']; ?>"></td>
                                            <?php endif; ?>
                                            <td><strong><?php echo htmlspecialchars($user['student_id']); ?></strong></td>
                                            <td><?php echo htmlspecialchars($user['full_name']); ?></td>
                                            <td><span class="badge bg-<?php echo $user['user_type'] === 'superadmin' ? 'danger' : ($user['user_type'] === 'admin' ? 'secondary' : 'info'); ?> text-capitalize"><?php echo htmlspecialchars($user['user_type']); ?></span></td>
                                            <td><?php echo htmlspecialchars($user['email']); ?></td>
                                            <td><?php echo htmlspecialchars($user['phone']); ?></td>
                                            <?php if (!($is_superadmin && $user_type_filter === 'admin')): ?>
                                            <td><?php echo htmlspecialchars($user['course']); ?></td>
                                            <td><?php echo htmlspecialchars($user['year_level']); ?></td>
                                            <td><?php echo htmlspecialchars($user['section']); ?></td>
                                            <?php endif; ?>
                                            <td style="display: none;">
                                                <?php if ($user['status'] === 'active'): ?>
                                                    <span class="badge bg-success">Active</span>
                                                <?php else: ?>
                                                    <span class="badge bg-secondary">Archived</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($is_superadmin): ?>
                                                <div class="action-buttons" style="display: flex; justify-content: flex-end; gap: 0.5rem;">
                                                    <button class="btn btn-sm btn-primary btn-action" data-bs-toggle="modal" data-bs-target="#editModal<?php echo $user['user_id']; ?>" title="Edit">
                                                        <i class="bi bi-pencil"></i>
                                                    </button>
                                                    <?php if ($user['status'] === 'active'): ?>
                                                        <form method="POST" style="display: inline;" onsubmit="return confirm('Archive this account?');">
                                                            <input type="hidden" name="user_id" value="<?php echo $user['user_id']; ?>">
                                                            <button type="submit" name="archive_user" class="btn btn-sm btn-warning btn-action" title="Archive">
                                                                <i class="bi bi-archive"></i>
                                                            </button>
                                                        </form>
                                                    <?php else: ?>
                                                        <form method="POST" style="display: inline;" onsubmit="return confirm('Activate this student account?');">
                                                            <input type="hidden" name="user_id" value="<?php echo $user['user_id']; ?>">
                                                            <button type="submit" name="activate_user" class="btn btn-sm btn-success btn-action" title="Activate">
                                                                <i class="bi bi-unlock"></i>
                                                            </button>
                                                        </form>
                                                    <?php endif; ?>
                                                    <form method="POST" style="display: inline;" onsubmit="return confirm('Delete this account?');">
                                                        <input type="hidden" name="user_id" value="<?php echo $user['user_id']; ?>">
                                                        <button type="submit" name="delete_user" class="btn btn-sm btn-danger btn-action" title="Delete">
                                                            <i class="bi bi-trash"></i>
                                                        </button>
                                                    </form>
                                                </div>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        
                                        <?php if ($is_superadmin): ?>
                                            <?php ob_start(); ?>
                                            <div class="modal fade" id="editModal<?php echo $user['user_id']; ?>" tabindex="-1" data-bs-backdrop="false">
                                                <div class="modal-dialog">
                                                    <div class="modal-content">
                                                        <form method="POST">
                                                            <div class="modal-header">
                                                                <h5 class="modal-title">Edit Account</h5>
                                                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                            </div>
                                                            <div class="modal-body">
                                                                <input type="hidden" name="user_id" value="<?php echo $user['user_id']; ?>">
                                                                <?php if ($is_superadmin): ?>
                                                                    <?php if ($user['user_type'] === 'superadmin'): ?>
                                                                        <input type="hidden" name="user_type" value="superadmin">
                                                                        <div class="mb-3">
                                                                            <label class="form-label">Account Type</label>
                                                                            <input type="text" class="form-control" value="Superadmin" disabled>
                                                                        </div>
                                                                    <?php else: ?>
                                                                        <div class="mb-3">
                                                                            <label class="form-label">Account Type</label>
                                                                            <select class="form-select" name="user_type">
                                                                                <option value="student" <?php echo $user['user_type'] === 'student' ? 'selected' : ''; ?>>Student</option>
                                                                                <option value="admin" <?php echo $user['user_type'] === 'admin' ? 'selected' : ''; ?>>Admin</option>
                                                                            </select>
                                                                        </div>
                                                                    <?php endif; ?>
                                                                <?php else: ?>
                                                                    <input type="hidden" name="user_type" value="<?php echo htmlspecialchars($user['user_type']); ?>">
                                                                <?php endif; ?>
                                                                <div class="mb-3">
                                                                    <label class="form-label">Full Name *</label>
                                                                    <input type="text" class="form-control" name="full_name" value="<?php echo htmlspecialchars($user['full_name']); ?>" required>
                                                                </div>
                                                                <div class="mb-3">
                                                                    <label class="form-label">Email *</label>
                                                                    <input type="email" class="form-control" name="email" value="<?php echo htmlspecialchars($user['email']); ?>" required>
                                                                </div>
                                                                <div class="mb-3">
                                                                    <label class="form-label">Phone</label>
                                                                    <input type="text" class="form-control" name="phone" value="<?php echo htmlspecialchars($user['phone']); ?>" maxlength="11" inputmode="numeric" pattern="\d*" oninput="this.value=this.value.replace(/\D/g,'').slice(0,11)">
                                                                </div>
                                                                <div class="mb-3">
                                                                    <label class="form-label">Student ID</label>
                                                                    <input type="text" class="form-control" name="student_id" value="<?php echo htmlspecialchars($user['student_id']); ?>">
                                                                </div>
                                                                <?php if ($user['user_type'] !== 'admin' && $user['user_type'] !== 'superadmin'): ?>
                                                                <div class="mb-3">
                                                                    <label class="form-label">Course</label>
                                                                    <select class="form-select course-select" name="course" required>
                                                                        <option value="">Select Course</option>
                                                                        <optgroup label="4-YEAR">
                                                                            <option <?php echo $user['course'] === 'Bachelor of Science in Information Systems (BSIS)' ? 'selected' : ''; ?>>Bachelor of Science in Information Systems (BSIS)</option>
                                                                            <option <?php echo $user['course'] === 'Bachelor of Science in Office Management (BSOM)' ? 'selected' : ''; ?>>Bachelor of Science in Office Management (BSOM)</option>
                                                                            <option <?php echo $user['course'] === 'Bachelor of Science in Accounting Information System (BSAIS)' ? 'selected' : ''; ?>>Bachelor of Science in Accounting Information System (BSAIS)</option>
                                                                            <option <?php echo $user['course'] === 'Bachelor of Technical Vocational Teacher Education (BTVTED)' ? 'selected' : ''; ?>>Bachelor of Technical Vocational Teacher Education (BTVTED)</option>
                                                                            <option <?php echo $user['course'] === 'Bachelor of Science in Customs Administration (BSCA)' ? 'selected' : ''; ?>>Bachelor of Science in Customs Administration (BSCA)</option>
                                                                        </optgroup>
                                                                        <optgroup label="2-YEAR">
                                                                            <option <?php echo $user['course'] === 'Associate in Computer Technology' ? 'selected' : ''; ?>>Associate in Computer Technology</option>
                                                                        </optgroup>
                                                                        <optgroup label="3-YEAR">
                                                                            <option <?php echo $user['course'] === 'Diploma in Hotel and Restaurant Management Technology (DHRMT)' ? 'selected' : ''; ?>>Diploma in Hotel and Restaurant Management Technology (DHRMT)</option>
                                                                        </optgroup>
                                                                        <optgroup label="1-YEAR">
                                                                            <option <?php echo $user['course'] === 'Hotel and Restaurant Services (Bundled) HB' ? 'selected' : ''; ?>>Hotel and Restaurant Services (Bundled) HB</option>
                                                                            <option <?php echo $user['course'] === 'Shielded Metal Arc Welding (SMAW)' ? 'selected' : ''; ?>>Shielded Metal Arc Welding (SMAW)</option>
                                                                            <option <?php echo $user['course'] === 'Bookkeeping' ? 'selected' : ''; ?>>Bookkeeping</option>
                                                                            <option <?php echo $user['course'] === 'Electrical Installations and Maintenance (EIM)' ? 'selected' : ''; ?>>Electrical Installations and Maintenance (EIM)</option>
                                                                        </optgroup>
                                                                    </select>
                                                                </div>
                                                                <div class="mb-3">
                                                                    <label class="form-label">Year Level</label>
                                                                    <select class="form-select year-select" name="year_level" required>
                                                                        <option value="">Select Year Level</option>
                                                                        <?php foreach ($fixed_year_levels as $year_option): ?>
                                                                            <option value="<?php echo htmlspecialchars($year_option); ?>" <?php echo $user['year_level'] === $year_option ? 'selected' : ''; ?>>
                                                                                <?php echo htmlspecialchars($year_option); ?>
                                                                            </option>
                                                                        <?php endforeach; ?>
                                                                    </select>
                                                                </div>
                                                                <div class="mb-3">
                                                                    <label class="form-label">Section</label>
                                                                    <select class="form-select" name="section">
                                                                        <option value="">Select Section</option>
                                                                        <?php foreach ($fixed_sections as $section_option): ?>
                                                                            <option value="<?php echo htmlspecialchars($section_option); ?>" <?php echo $user['section'] === $section_option ? 'selected' : ''; ?>>
                                                                                <?php echo htmlspecialchars($section_option); ?>
                                                                            </option>
                                                                        <?php endforeach; ?>
                                                                    </select>
                                                                </div>
                                                                <?php endif; ?>
                                                            </div>
                                                            <div class="modal-footer">
                                                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                                <button type="submit" name="edit_user" class="btn btn-primary">Update Student</button>
                                                            </div>
                                                        </form>
                                                    </div>
                                                </div>
                                            </div>
                                            <?php $edit_modals .= ob_get_clean(); ?>
                                        <?php endif; ?>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                        <tr>
                                        <td colspan="11">
                                            <div class="empty-state">
                                                <i class="bi bi-people"></i>
                                                <h5>No Students Found</h5>
                                                <p>Start by adding students individually.</p>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                        <?php echo $edit_modals; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <div id="addUserModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 9999;">
        <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; padding: 20px; border-radius: 5px; max-width: 90%; max-height: 90%; overflow: auto; width: 600px;">
            <form method="POST">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                    <h5><?php echo $is_superadmin ? (($user_type_filter === 'admin' || $default_add_type === 'admin') ? 'Add Admin Account' : 'Add Student Account') : 'Add New Account'; ?></h5>
                    <button type="button" onclick="closeAddUserModal()" style="background: none; border: none; font-size: 20px; cursor: pointer;">&times;</button>
                </div>
                <div>
                        <?php if ($is_superadmin): ?>
                            <?php $modal_add_type = $default_add_type ? $default_add_type : ($user_type_filter ?: 'student'); ?>
                            <input type="hidden" name="user_type" id="addUserTypeInput" value="<?php echo $modal_add_type; ?>">
                            <div class="alert alert-info mb-3">
                                <i class="bi bi-info-circle me-2"></i>
                                Creating <?php echo ucfirst($modal_add_type); ?> account. Default password will be: <strong><?php echo $modal_add_type === 'admin' ? '@Admin01' : '@Student01'; ?></strong>
                            </div>
                        <?php else: ?>
                            <input type="hidden" name="user_type" value="student">
                        <?php endif; ?>
                        <div class="mb-3">
                            <label class="form-label">Full Name *</label>
                            <input type="text" class="form-control" name="full_name" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Email *</label>
                            <input type="email" class="form-control" name="email" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Student ID</label>
                            <input type="text" class="form-control" name="student_id" placeholder="2024-001">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Phone</label>
                            <input type="text" class="form-control" name="phone" placeholder="09XXXXXXXXX" maxlength="11" inputmode="numeric" pattern="\d*" oninput="this.value=this.value.replace(/\D/g,'').slice(0,11)">
                        </div>
                        <div id="studentFields" style="<?php echo ($is_superadmin && $user_type_filter === 'admin') ? 'display:none;' : ''; ?>">
                            <div class="mb-3">
                                <label class="form-label">Course</label>
                                <select class="form-select course-select" name="course" required <?php echo ($is_superadmin && $user_type_filter === 'admin') ? 'disabled' : ''; ?>>
                                    <option value="">Select Course</option>
                                    <optgroup label="4-YEAR">
                                    <option>Bachelor of Science in Information Systems (BSIS)</option>
                                    <option>Bachelor of Science in Office Management (BSOM)</option>
                                    <option>Bachelor of Science in Accounting Information System (BSAIS)</option>
                                    <option>Bachelor of Technical Vocational Teacher Education (BTVTED)</option>
                                    <option>Bachelor of Science in Customs Administration (BSCA)</option>
                                </optgroup>
                                <optgroup label="2-YEAR">
                                    <option>Associate in Computer Technology</option>
                                </optgroup>
                                <optgroup label="3-YEAR">
                                    <option>Diploma in Hotel and Restaurant Management Technology (DHRMT)</option>
                                </optgroup>
                                <optgroup label="1-YEAR">
                                    <option>Hotel and Restaurant Services (Bundled) HB</option>
                                    <option>Shielded Metal Arc Welding (SMAW)</option>
                                    <option>Bookkeeping</option>
                                    <option>Electrical Installations and Maintenance (EIM)</option>
                                </optgroup>
                            </select>
                        </div>
                            <div class="mb-3">
                                <label class="form-label">Year Level</label>
                                <select class="form-select year-select" name="year_level" required <?php echo ($is_superadmin && $user_type_filter === 'admin') ? 'disabled' : ''; ?>>
                                    <option value="">Select Year Level</option>
                                <?php foreach ($fixed_year_levels as $year_option): ?>
                                    <option value="<?php echo htmlspecialchars($year_option); ?>">
                                        <?php echo htmlspecialchars($year_option); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Section</label>
                            <select class="form-select" name="section" <?php echo ($is_superadmin && $user_type_filter === 'admin') ? 'disabled' : ''; ?>>
                                <option value="">Select Section</option>
                                <?php foreach ($fixed_sections as $section_option): ?>
                                    <option value="<?php echo htmlspecialchars($section_option); ?>">
                                        <?php echo htmlspecialchars($section_option); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div style="text-align: right; margin-top: 20px;">
                        <button type="button" onclick="closeAddUserModal()" style="background: #6c757d; color: white; border: none; padding: 8px 16px; border-radius: 4px; cursor: pointer;">Cancel</button>
                        <button type="submit" name="add_user" style="background: #007bff; color: white; border: none; padding: 8px 16px; border-radius: 4px; cursor: pointer;"><?php echo $is_superadmin ? (($user_type_filter === 'admin' || $default_add_type === 'admin') ? 'Add Admin' : 'Add Student') : 'Add Student'; ?></button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Bulk Upload Modal - Simple Custom -->
    <div id="bulkUploadModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 9999;">
        <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; padding: 20px; border-radius: 5px; max-width: 90%; max-height: 90%; overflow: auto; width: 800px;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <h5><i class="bi bi-cloud-upload me-2"></i>Bulk Upload Students</h5>
                <button type="button" onclick="closeBulkUploadModal()" style="background: none; border: none; font-size: 20px; cursor: pointer;">&times;</button>
            </div>
            <div>
                <div id="bulkUploadStep1">
                    <div style="background: #d1ecf1; border: 1px solid #bee5eb; color: #0c5460; padding: 15px; border-radius: 5px; margin-bottom: 20px;">
                        <i class="bi bi-info-circle me-2"></i>
                        <strong>Instructions:</strong>
                        <ol style="margin-bottom: 0; margin-top: 10px;">
                            <li>Download the CSV template using the button below</li>
                            <li>Fill in your student information in the template</li>
                            <li>Upload the completed CSV file</li>
                            <li>Review the preview and confirm</li>
                        </ol>
                    </div>

                    <div style="margin-bottom: 20px;">
                        <a href="<?php echo $_SERVER['PHP_SELF']; ?>?action=download_template" class="btn btn-outline-primary btn-sm" download>
                            <i class="bi bi-file-csv me-2"></i>Download CSV Template
                        </a>
                    </div>

                    <form id="csvUploadForm" enctype="multipart/form-data">
                        <div style="margin-bottom: 15px;">
                            <label style="display: block; margin-bottom: 5px;">Upload CSV File</label>
                            <div style="border: 2px dashed #007bff; border-radius: 5px; padding: 20px; text-align: center; cursor: pointer;" id="uploadZone">
                                <i class="bi bi-cloud-upload" style="font-size: 2rem; color: #007bff;"></i>
                                <p style="margin: 10px 0;"><strong>Drag and drop your CSV file here</strong></p>
                                <p style="color: #6c757d; font-size: 14px;">or click to select file</p>
                                <input type="file" id="csvFile" name="csv_file" accept=".csv" style="display: none;">
                            </div>
                            <small style="color: #6c757d; display: block; margin-top: 10px;">Maximum file size: 10MB. Format: CSV only</small>
                        </div>
                    </form>
                </div>

                <div id="bulkUploadStep2" style="display: none;">
                    <div style="background: #fff3cd; border: 1px solid #ffeaa7; color: #856404; padding: 15px; border-radius: 5px; margin-bottom: 20px;">
                        <i class="bi bi-eye me-2"></i>
                        <strong>Preview</strong> - Review the data below before confirming
                    </div>
                    <div style="max-height: 400px; overflow-y: auto;">
                        <table style="width: 100%; border-collapse: collapse;" id="previewTable">
                            <thead style="background: #f8f9fa;">
                                <tr>
                                    <th style="border: 1px solid #dee2e6; padding: 8px;">#</th>
                                    <th style="border: 1px solid #dee2e6; padding: 8px;">Student ID</th>
                                    <th style="border: 1px solid #dee2e6; padding: 8px;">Full Name</th>
                                    <th style="border: 1px solid #dee2e6; padding: 8px;">Email</th>
                                    <th style="border: 1px solid #dee2e6; padding: 8px;">Phone</th>
                                    <th style="border: 1px solid #dee2e6; padding: 8px;">Course</th>
                                    <th style="border: 1px solid #dee2e6; padding: 8px;">Year</th>
                                    <th style="border: 1px solid #dee2e6; padding: 8px;">Section</th>
                                </tr>
                            </thead>
                            <tbody id="previewTableBody">
                            </tbody>
                        </table>
                    </div>
                </div>

                <div id="bulkUploadStep3" style="display: none;">
                    <div id="uploadResultMessage"></div>
                    <div style="max-height: 400px; overflow-y: auto;">
                        <table style="width: 100%; border-collapse: collapse;" id="resultTable">
                            <thead style="background: #f8f9fa;">
                                <tr>
                                    <th style="border: 1px solid #dee2e6; padding: 8px;">Status</th>
                                    <th style="border: 1px solid #dee2e6; padding: 8px;">Name</th>
                                    <th style="border: 1px solid #dee2e6; padding: 8px;">Email</th>
                                    <th style="border: 1px solid #dee2e6; padding: 8px;">Details</th>
                                </tr>
                            </thead>
                            <tbody id="resultTableBody">
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <div style="text-align: right; margin-top: 20px;">
                <button type="button" onclick="closeBulkUploadModal()" style="background: #6c757d; color: white; border: none; padding: 8px 16px; border-radius: 4px; cursor: pointer;">Close</button>
                <button type="button" id="uploadBtn" style="display: none; background: #007bff; color: white; border: none; padding: 8px 16px; border-radius: 4px; cursor: pointer;" onclick="document.getElementById('csvUploadForm').submit();">
                    <i class="bi bi-upload me-2"></i>Process Upload
                </button>
                <button type="button" id="confirmUploadBtn" style="display: none; background: #28a745; color: white; border: none; padding: 8px 16px; border-radius: 4px; cursor: pointer;">
                    <i class="bi bi-check me-2"></i>Confirm & Upload All
                </button>
                <button type="button" onclick="location.reload()" id="refreshBtn" style="display: none; background: #007bff; color: white; border: none; padding: 8px 16px; border-radius: 4px; cursor: pointer;">
                    <i class="bi bi-arrow-clockwise me-2"></i>Refresh
                </button>
            </div>
        </div>
    </div>
    
            <!-- Bulk action confirmation modals -->
            <div class="modal fade" id="bulkDeleteModal" tabindex="-1">
                <div class="modal-dialog">
                    <div class="modal-content">
                        <form method="POST" id="bulkDeleteForm">
                            <input type="hidden" name="selected_ids" id="bulk_selected_ids_delete">
                            <input type="hidden" name="bulk_delete_selected" value="1">
                            <div class="modal-header">
                                <h5 class="modal-title">Confirm Delete</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                            </div>
                            <div class="modal-body">
                                <p>Are you sure you want to permanently delete <span id="deleteModalCount">0</span> selected account(s)? This action cannot be undone.</p>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                <button type="submit" name="bulk_delete_selected" class="btn btn-danger">Yes, Delete</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <div class="modal fade" id="bulkArchiveModal" tabindex="-1">
                <div class="modal-dialog">
                    <div class="modal-content">
                        <form method="POST" id="bulkArchiveForm">
                            <input type="hidden" name="selected_ids" id="bulk_selected_ids_archive">
                            <input type="hidden" name="archive_action" id="bulk_archive_action" value="archive">
                            <input type="hidden" name="bulk_archive_selected" value="1">
                            <div class="modal-header">
                                <h5 class="modal-title">Confirm Archive</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                            </div>
                            <div class="modal-body">
                                <p id="bulkArchiveModalBody">Are you sure you want to archive <span id="archiveModalCount"></span> selected student(s)? They will be moved to archived list.</p>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                <button type="submit" name="bulk_archive_selected" id="bulkArchiveConfirmBtn" class="btn btn-warning">Yes, Archive</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <script>
                // Auto-submit filter form on any change
                document.addEventListener('DOMContentLoaded', function() {
                    const filterInputs = document.querySelectorAll('.filter-input');
                    filterInputs.forEach(input => {
                        input.addEventListener('change', function() {
                            document.getElementById('filterForm').submit();
                        });
                        // Only submit the form when Enter is pressed in the text search box
                        if (input.type === 'text') {
                            input.addEventListener('keypress', function(e) {
                                if (e.key === 'Enter' || e.keyCode === 13) {
                                    e.preventDefault(); // prevent form from double-submitting
                                    document.getElementById('filterForm').submit();
                                }
                            });
                        }
                    });
                });

                // Manage selection and bulk action buttons
                function getSelectedIds() {
                    const checked = Array.from(document.querySelectorAll('.select-row:checked'));
                    return checked.map(cb => cb.value);
                }

                function getSelectedTypeLabel(selectedCount) {
                    const checked = Array.from(document.querySelectorAll('.select-row:checked'));
                    const selectedTypes = [...new Set(checked.map(cb => cb.dataset.userType))];
                    if (selectedTypes.length === 1 && selectedCount > 0) {
                        const type = selectedTypes[0];
                        const typeLabel = type === 'admin' || type === 'superadmin' ? type : 'student';
                        return `${selectedCount} ${typeLabel}${selectedCount !== 1 ? 's' : ''} selected`;
                    }
                    return `${selectedCount} account${selectedCount !== 1 ? 's' : ''} selected`;
                }

                function updateBulkButtons() {
                    const selectedCount = document.querySelectorAll('.select-row:checked').length;
                    console.log('updateBulkButtons called, selectedCount:', selectedCount);
                    const toolbar = document.getElementById('bulkActionToolbar');
                    const selectedCountSpan = document.getElementById('selectedCount');
                    const isArchivedView = window.location.search.indexOf('status=archived') !== -1;
                    const archiveBtnText = document.getElementById('archiveBtnText');
                    const bulkArchiveBtn = document.getElementById('bulkArchiveBtn');

                    if (selectedCount > 0) {
                        toolbar.style.display = 'flex';
                        selectedCountSpan.textContent = getSelectedTypeLabel(selectedCount);

                        if (isArchivedView) {
                            archiveBtnText.textContent = 'Unarchive Selected';
                            bulkArchiveBtn.classList.remove('btn-warning');
                            bulkArchiveBtn.classList.add('btn-success');
                        } else {
                            archiveBtnText.textContent = 'Archive Selected';
                            bulkArchiveBtn.classList.remove('btn-success');
                            bulkArchiveBtn.classList.add('btn-warning');
                        }
                    } else {
                        toolbar.style.display = 'none';
                    }
                }

                document.addEventListener('DOMContentLoaded', function() {
                    const selectAll = document.getElementById('selectAll');
                    if (selectAll) {
                        selectAll.addEventListener('change', function() {
                            const rows = document.querySelectorAll('.select-row');
                            rows.forEach(r => r.checked = selectAll.checked);
                            updateBulkButtons();
                        });
                    }

                    document.querySelectorAll('.select-row').forEach(cb => {
                        cb.addEventListener('change', function() {
                            const total = document.querySelectorAll('.select-row').length;
                            const checked = document.querySelectorAll('.select-row:checked').length;
                            if (selectAll) selectAll.checked = (checked === total && total > 0);
                            updateBulkButtons();
                        });
                    });

                    var bulkDeleteBtn = document.getElementById('bulkDeleteBtn');
                    var bulkArchiveBtn = document.getElementById('bulkArchiveBtn');

                    var updateBulkModalFields = function(isArchive) {
                        const ids = getSelectedIds();
                        console.log('updateBulkModalFields called, isArchive:', isArchive, 'ids:', ids);
                        if (isArchive) {
                            document.getElementById('bulk_selected_ids_archive').value = ids.join(',');
                            document.getElementById('archiveModalCount').textContent = ids.length;
                            const isArchivedView = window.location.search.indexOf('status=archived') !== -1;
                            const actionInput = document.getElementById('bulk_archive_action');
                            const modalBody = document.getElementById('bulkArchiveModalBody');
                            const confirmBtn = document.getElementById('bulkArchiveConfirmBtn');
                            actionInput.value = isArchivedView ? 'unarchive' : 'archive';
                            if (modalBody) {
                                modalBody.textContent = isArchivedView
                                    ? 'Are you sure you want to unarchive ' + ids.length + ' selected account(s)? They will be moved back to active list.'
                                    : 'Are you sure you want to archive ' + ids.length + ' selected account(s)? They will be moved to archived list.';
                            }
                            if (confirmBtn) {
                                confirmBtn.textContent = isArchivedView ? 'Yes, Unarchive' : 'Yes, Archive';
                                confirmBtn.classList.toggle('btn-success', isArchivedView);
                                confirmBtn.classList.toggle('btn-warning', !isArchivedView);
                            }
                        } else {
                            document.getElementById('bulk_selected_ids_delete').value = ids.join(',');
                            var deleteCountElem = document.getElementById('deleteModalCount');
                            if (deleteCountElem) {
                                deleteCountElem.textContent = ids.length;
                            }
                        }
                        return ids.length;
                    };

                    if (bulkDeleteBtn) {
                        bulkDeleteBtn.addEventListener('click', function(event) {
                            event.preventDefault();
                            console.log('Bulk delete button clicked');
                            const selectedCount = updateBulkModalFields(false);
                            console.log('Selected IDs:', getSelectedIds());
                            if (selectedCount === 0) {
                                console.log('No accounts selected');
                                alert('Please select at least one account to delete.');
                                return;
                            }
                            if (confirm('Delete ' + selectedCount + ' selected account(s)? This cannot be undone.')) {
                                const deleteForm = document.getElementById('bulkDeleteForm');
                                if (deleteForm) {
                                    deleteForm.submit();
                                } else {
                                    console.error('Bulk delete form not found.');
                                }
                            }
                        });
                    }

                    if (bulkArchiveBtn) {
                        bulkArchiveBtn.addEventListener('click', function(event) {
                            event.preventDefault();
                            console.log('Bulk archive button clicked');
                            const selectedCount = updateBulkModalFields(true);
                            console.log('Selected IDs:', getSelectedIds());
                            if (selectedCount === 0) {
                                console.log('No accounts selected');
                                alert('Please select at least one account to archive/unarchive.');
                                return;
                            }
                            const isArchivedView = window.location.search.indexOf('status=archived') !== -1;
                            const actionText = isArchivedView ? 'unarchive' : 'archive';
                            if (confirm('Are you sure you want to ' + actionText + ' ' + selectedCount + ' selected account(s)?')) {
                                const archiveForm = document.getElementById('bulkArchiveForm');
                                if (archiveForm) {
                                    archiveForm.submit();
                                } else {
                                    console.error('Bulk archive form not found.');
                                }
                            }
                        });
                    }
                });
            </script>
            
            <!-- Bulk Upload Functionality -->
            <script>
                function showAddUserModal() {
                    document.getElementById('addUserModal').style.display = 'block';
                }
                
                function closeAddUserModal() {
                    document.getElementById('addUserModal').style.display = 'none';
                }
                
                function showBulkUploadModal() {
                    document.getElementById('bulkUploadModal').style.display = 'block';
                }
                
                function closeBulkUploadModal() {
                    document.getElementById('bulkUploadModal').style.display = 'none';
                }
                
                console.log('Bulk upload script loading...');
                
                document.addEventListener('DOMContentLoaded', function() {
                    console.log('DOMContentLoaded - Initializing bulk upload');
                    
                    const uploadZone = document.getElementById('uploadZone');
                    const csvFile = document.getElementById('csvFile');
                    
                    // Only initialize if these elements exist
                    if (!uploadZone || !csvFile) {
                        console.log('Upload zone or CSV file input not found');
                        return;
                    }
                    
                    console.log('Initializing bulk upload handler');
                    const confirmUploadBtn = document.getElementById('confirmUploadBtn');
                    let previewData = [];

                    const courseMap = {
                        BSIS: 'Bachelor of Science in Information Systems (BSIS)',
                        BSOM: 'Bachelor of Science in Office Management (BSOM)',
                        BSAIS: 'Bachelor of Science in Accounting Information System (BSAIS)',
                        BTVTED: 'Bachelor of Technical Vocational Teacher Education (BTVTED)',
                        BSCA: 'Bachelor of Science in Customs Administration (BSCA)',
                        ACT: 'Associate in Computer Technology',
                        DHRMT: 'Diploma in Hotel and Restaurant Management Technology (DHRMT)',
                        HB: 'Hotel and Restaurant Services (Bundled) HB',
                        SMAW: 'Shielded Metal Arc Welding (SMAW)',
                        BOOKKEEPING: 'Bookkeeping',
                        BKKP: 'Bookkeeping',
                        EIM: 'Electrical Installations and Maintenance (EIM)'
                    };

                    function normalizeCourseName(course) {
                        if (!course) return '';
                        const key = course.trim().toUpperCase();
                        return courseMap[key] || course.trim();
                    }

                    function normalizePhoneNumber(phone) {
                        if (!phone) return '';
                        const digits = phone.toString().replace(/\D/g, '');
                        if (digits.length === 10) {
                            return '0' + digits;
                        }
                        if (digits.length === 12 && digits.startsWith('63')) {
                            return '0' + digits.slice(2);
                        }
                        return digits;
                    }


                    // File selection
                    uploadZone.addEventListener('click', function() {
                        console.log('Upload zone clicked');
                        csvFile.click();
                    });

                    csvFile.addEventListener('change', function(e) {
                        console.log('File selected');
                        if (csvFile.files.length > 0) {
                            processCSVFile(csvFile.files[0]);
                        }
                    });

                    // Drag and drop
                    uploadZone.addEventListener('dragover', function(e) {
                        e.preventDefault();
                        uploadZone.classList.add('bg-light', 'border-success');
                    });

                    uploadZone.addEventListener('dragleave', function() {
                        uploadZone.classList.remove('bg-light', 'border-success');
                    });

                    uploadZone.addEventListener('drop', function(e) {
                        e.preventDefault();
                        uploadZone.classList.remove('bg-light', 'border-success');
                        
                        const files = e.dataTransfer.files;
                        if (files.length > 0) {
                            processCSVFile(files[0]);
                        }
                    });

                    function processCSVFile(file) {
                        if (!file.name.endsWith('.csv')) {
                            alert('Please upload a CSV file.');
                            return;
                        }

                        const reader = new FileReader();
                        reader.onload = function(e) {
                            const csv = e.target.result;
                            const lines = csv.split('\n');
                            const headers = lines[0].split(',').map(h => h.trim().toLowerCase());

                            // Validate headers
                            const required = ['student_id', 'full_name', 'email', 'phone', 'course', 'year_level', 'section'];
                            const missing = required.filter(h => !headers.includes(h));

                            if (missing.length > 0) {
                                alert('Missing required columns: ' + missing.join(', '));
                                return;
                            }

                            // Parse data
                            previewData = [];
                            for (let i = 1; i < lines.length; i++) {
                                if (!lines[i].trim()) continue;
                                const values = lines[i].split(',').map(v => v.trim());
                                const row = {};
                                headers.forEach((h, idx) => {
                                    row[h] = values[idx] || '';
                                });
                                row.course = normalizeCourseName(row.course);
                                row.phone = normalizePhoneNumber(row.phone);
                                previewData.push({row: i + 1, ...row});
                            }

                            if (previewData.length === 0) {
                                alert('CSV file is empty.');
                                return;
                            }

                            showPreview();
                        };
                        reader.readAsText(file);
                    }

                    function showPreview() {
                        document.getElementById('bulkUploadStep1').style.display = 'none';
                        document.getElementById('bulkUploadStep2').style.display = 'block';
                        document.getElementById('confirmUploadBtn').style.display = 'inline-block';

                        const tbody = document.getElementById('previewTableBody');
                        tbody.innerHTML = '';
                        previewData.forEach((data, idx) => {
                            const tr = document.createElement('tr');
                            tr.innerHTML = `
                                <td>${idx + 1}</td>
                                <td>${data.student_id}</td>
                                <td>${data.full_name}</td>
                                <td>${data.email}</td>
                                <td>${data.phone}</td>
                                <td>${data.course}</td>
                                <td>${data.year_level}</td>
                                <td>${data.section}</td>
                            `;
                            tbody.appendChild(tr);
                        });
                    }

                    if (confirmUploadBtn) {
                        confirmUploadBtn.addEventListener('click', function() {
                            console.log('Confirm upload clicked');
                            confirmUploadBtn.disabled = true;
                            confirmUploadBtn.innerHTML = '<i class="bi bi-spinner fa-spin me-2"></i>Processing...';
                            
                            fetch('/unineed/api/bulk-upload-students.php', {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/json'
                                },
                                body: JSON.stringify({
                                    action: 'confirm_upload',
                                    preview_data: previewData
                                })
                            })
                            .then(response => response.json())
                            .then(data => {
                                if (data.success) {
                                    showResults(data.results);
                                } else {
                                    alert('Error: ' + data.message);
                                    location.reload();
                                }
                            })
                            .catch(error => {
                                console.error('Error:', error);
                                alert('An error occurred. Please try again.');
                                location.reload();
                            });
                        });
                    }

                    function showResults(results) {
                        document.getElementById('bulkUploadStep2').style.display = 'none';
                        document.getElementById('bulkUploadStep3').style.display = 'block';
                        document.getElementById('confirmUploadBtn').style.display = 'none';
                        document.getElementById('refreshBtn').style.display = 'inline-block';

                        const successful = results.filter(r => r.status === 'success').length;
                        const failed = results.filter(r => r.status === 'error').length;

                        const msgDiv = document.getElementById('uploadResultMessage');
                        msgDiv.innerHTML = `
                            <div class="alert ${failed === 0 ? 'alert-success' : 'alert-warning'}">
                                <i class="bi bi-chart-pie me-2"></i>
                                <strong>Upload Complete</strong> - Total: ${results.length} | 
                                <span class="text-success"><i class="bi bi-check me-1"></i>Success: ${successful}</span> | 
                                <span class="text-danger"><i class="bi bi-x me-1"></i>Failed: ${failed}</span>
                            </div>
                        `;

                        const tbody = document.getElementById('resultTableBody');
                        tbody.innerHTML = '';
                        results.forEach(r => {
                            const tr = document.createElement('tr');
                            const statusBadge = r.status === 'success' 
                                ? '<span class="badge bg-success">✓ Success</span>'
                                : '<span class="badge bg-danger">✗ Failed</span>';
                            tr.innerHTML = `
                                <td>${statusBadge}</td>
                                <td>${r.data.full_name}</td>
                                <td>${r.data.email}</td>
                                <td>${r.message}</td>
                            `;
                            tbody.appendChild(tr);
                        });
                    }
                });
            </script>


            <!-- Download Template Functionality -->
            <script>
                document.addEventListener('DOMContentLoaded', function() {
                    const downloadLink = document.querySelector('a[download]');
                    if (downloadLink) {
                        downloadLink.addEventListener('click', function(e) {
                            const currentURL = new URL(window.location);
                            currentURL.search = '?action=download_template';
                            window.location.href = currentURL.toString();
                        });
                    }
                });
            </script>
            
            <script src="../assets/js/script.js"></script>
</body>
</html>
