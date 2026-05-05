<?php
/**
 * Archive 4th Year Students
 * This page allows admins to archive all 4th year students based on criteria
 */

require_once '../config/database.php';
requireAdmin();

// Only superadmins can access this
if ($_SESSION['user_type'] !== 'superadmin') {
    $_SESSION['error'] = 'Access denied. Only superadmins can archive 4th year students.';
    header('Location: dashboard.php');
    exit();
}

$success = null;
$error = null;
$archived_count = 0;

// Prevent caching
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

// Get unique courses
$courses_query = "SELECT DISTINCT course FROM users WHERE user_type = 'student' AND course != '' AND year_level = 4 ORDER BY course ASC";
$courses_result = mysqli_query($conn, $courses_query);
$courses = [];
while ($row = mysqli_fetch_assoc($courses_result)) {
    $courses[] = $row['course'];
}

// Get 4th year students grouped by course
$students_query = "SELECT user_id, full_name, email, student_id, course, section, status FROM users WHERE user_type = 'student' AND year_level = 4 ORDER BY course, full_name ASC";
$students_result = mysqli_query($conn, $students_query);
$students = [];
while ($row = mysqli_fetch_assoc($students_result)) {
    if (!isset($students[$row['course']])) {
        $students[$row['course']] = [];
    }
    $students[$row['course']][] = $row;
}

// Handle Archive All 4th Year
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['archive_all_4th_year'])) {
    $course_filter = isset($_POST['course_filter']) ? clean($_POST['course_filter']) : '';
    
    $where_parts = ["user_type = 'student'", "year_level = 4", "status = 'active'"];
    
    if (!empty($course_filter)) {
        $where_parts[] = "course = '" . mysqli_real_escape_string($conn, $course_filter) . "'";
    }
    
    $where_clause = implode(" AND ", $where_parts);
    $archive_query = "UPDATE users SET status = 'archived' WHERE $where_clause";
    
    if (mysqli_query($conn, $archive_query)) {
        $archived_count = mysqli_affected_rows($conn);
        $success = "Successfully archived $archived_count 4th year student(s)!";
    } else {
        $error = "Failed to archive students: " . mysqli_error($conn);
    }
}

// Handle Archive Selective
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['archive_selected_4th_year'])) {
    $selected = isset($_POST['selected_ids']) ? trim($_POST['selected_ids']) : '';
    $ids = array_filter(array_map('intval', explode(',', $selected)));
    
    if (count($ids) > 0) {
        $id_list = implode(',', $ids);
        $archive_query = "UPDATE users SET status = 'archived' WHERE user_id IN ($id_list)";
        
        if (mysqli_query($conn, $archive_query)) {
            $archived_count = mysqli_affected_rows($conn);
            $success = "Successfully archived $archived_count selected 4th year student(s)!";
        } else {
            $error = "Failed to archive students: " . mysqli_error($conn);
        }
    } else {
        $error = 'No students selected.';
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Archive 4th Year Students - UniNeeds Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/mobile.css">
    <style>
        .archive-card {
            background: var(--white);
            border: none;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
            margin-bottom: 20px;
            padding: 20px;
        }
        .course-section {
            margin-bottom: 20px;
            border: 1px solid #e9ecef;
            border-radius: 8px;
            padding: 15px;
            background: #f8f9fa;
        }
        .course-section h6 {
            margin-bottom: 15px;
            color: #33186B;
            font-weight: 600;
        }
        .student-item {
            padding: 10px;
            background: white;
            margin-bottom: 8px;
            border-radius: 6px;
            display: flex;
            align-items: center;
            border: 1px solid #e9ecef;
        }
        .student-item.archived {
            opacity: 0.6;
            background: #f0f0f0;
        }
        .badge-section {
            margin-left: 10px;
            flex-grow: 1;
        }
        .selection-count {
            display: inline-block;
            background: #33186B;
            color: white;
            padding: 5px 12px;
            border-radius: 8px;
            font-size: 0.85rem;
            margin-left: 10px;
        }
    </style>
</head>
<body>
    <?php include 'includes/sidebar.php'; ?>
    
    <div class="main-content">
        <div class="top-bar">
            <button class="btn btn-link d-md-none" id="sidebarToggle"><i class="bi bi-list fs-3"></i></button>
            <h2><i class="bi bi-archive"></i> Archive 4th Year Students</h2>
        </div>
        
        <div class="content-area">
            <!-- Success/Error Messages -->
            <?php if (isset($success)): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="bi bi-check-circle me-2"></i><?php echo htmlspecialchars($success); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            
            <?php if (isset($error)): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="bi bi-exclamation-triangle me-2"></i><?php echo htmlspecialchars($error); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            
            <!-- Archive All Section -->
            <div class="archive-card">
                <h5 class="mb-3"><i class="bi bi-lightning-charge"></i> Quick Archive All 4th Year</h5>
                <form method="POST" action="">
                    <div class="row align-items-end g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Filter by Course (Optional)</label>
                            <select name="course_filter" class="form-select">
                                <option value="">-- Archive All Courses --</option>
                                <?php foreach ($courses as $course): ?>
                                    <option value="<?php echo htmlspecialchars($course); ?>"><?php echo htmlspecialchars($course); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-muted d-block mt-2">Leave blank to archive all 4th year students</small>
                        </div>
                        <div class="col-md-6">
                            <button type="submit" name="archive_all_4th_year" class="btn btn-warning w-100" 
                                    onclick="return confirm('Are you sure? This will archive all matching 4th year students.');">
                                <i class="bi bi-archive"></i> Archive All
                            </button>
                        </div>
                    </div>
                </form>
                <div class="alert alert-warning mt-3 mb-0">
                    <i class="bi bi-info-circle"></i> <strong>Warning:</strong> This action cannot be easily undone. Archived students will need to be manually un-archived.
                </div>
            </div>
            
            <!-- Selective Archive Section -->
            <div class="archive-card">
                <h5 class="mb-3"><i class="bi bi-checkbox2"></i> Selective Archive</h5>
                <form id="selectiveArchiveForm" method="POST" action="">
                    <div class="mb-3">
                        <button type="button" class="btn btn-sm btn-outline-secondary" id="selectAllBtn">
                            <i class="bi bi-check-all"></i> Select All
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-secondary" id="deselectAllBtn">
                            <i class="bi bi-x-circle"></i> Deselect All
                        </button>
                        <span class="selection-count" id="selectionCount">0 selected</span>
                    </div>
                    
                    <!-- Students List by Course -->
                    <?php if (empty($students)): ?>
                        <div class="alert alert-info mt-3">
                            <i class="bi bi-info-circle"></i> No 4th year students found.
                        </div>
                    <?php else: ?>
                        <?php foreach ($students as $course => $course_students): ?>
                            <div class="course-section">
                                <h6><?php echo htmlspecialchars($course); ?> <span class="badge bg-secondary"><?php echo count($course_students); ?></span></h6>
                                <?php foreach ($course_students as $student): ?>
                                    <div class="student-item <?php echo $student['status'] !== 'active' ? 'archived' : ''; ?>">
                                        <input type="checkbox" class="form-check-input student-checkbox" 
                                               value="<?php echo $student['user_id']; ?>" 
                                               data-name="<?php echo htmlspecialchars($student['full_name']); ?>"
                                               <?php echo $student['status'] !== 'active' ? 'disabled' : ''; ?>>
                                        <div class="badge-section ms-2">
                                            <strong><?php echo htmlspecialchars($student['full_name']); ?></strong>
                                            <br>
                                            <small class="text-muted"><?php echo htmlspecialchars($student['email']); ?></small>
                                        </div>
                                        <span class="badge bg-info"><?php echo htmlspecialchars($student['student_id']); ?></span>
                                        <span class="badge bg-secondary"><?php echo htmlspecialchars($student['section']); ?></span>
                                        <span class="badge <?php echo $student['status'] === 'active' ? 'bg-success' : 'bg-warning'; ?>">
                                            <?php echo ucfirst($student['status']); ?>
                                        </span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    
                    <input type="hidden" name="selected_ids" id="selectedIds" value="">
                    
                    <div class="mt-4">
                        <button type="submit" name="archive_selected_4th_year" class="btn btn-danger" 
                                onclick="return confirm('Archive the selected students?');" id="submitBtn" disabled>
                            <i class="bi bi-archive"></i> Archive Selected
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/script.js"></script>
    <script>
        const checkboxes = document.querySelectorAll('.student-checkbox');
        const selectAllBtn = document.getElementById('selectAllBtn');
        const deselectAllBtn = document.getElementById('deselectAllBtn');
        const selectionCount = document.getElementById('selectionCount');
        const selectedIds = document.getElementById('selectedIds');
        const submitBtn = document.getElementById('submitBtn');

        function updateCounts() {
            const checked = document.querySelectorAll('.student-checkbox:checked').length;
            selectionCount.textContent = checked + ' selected';
            submitBtn.disabled = checked === 0;
            updateSelectedIds();
        }

        function updateSelectedIds() {
            const ids = Array.from(document.querySelectorAll('.student-checkbox:checked')).map(cb => cb.value);
            selectedIds.value = ids.join(',');
        }

        selectAllBtn.addEventListener('click', function() {
            checkboxes.forEach(cb => {
                if (!cb.disabled) cb.checked = true;
            });
            updateCounts();
        });

        deselectAllBtn.addEventListener('click', function() {
            checkboxes.forEach(cb => cb.checked = false);
            updateCounts();
        });

        checkboxes.forEach(checkbox => {
            checkbox.addEventListener('change', updateCounts);
        });

        updateCounts();
    </script>
</body>
</html>
