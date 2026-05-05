<?php

require_once '../config/database.php';
requireAdmin();

// Only superadmins can access this page
if ($_SESSION['user_type'] !== 'superadmin') {
    $_SESSION['error'] = 'Access denied. Only superadmins can access bulk operations.';
    header('Location: dashboard.php');
    exit();
}

// Prevent caching
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

// Get all active students for year level editing
$students_query = "SELECT user_id, full_name, email, student_id, course, year_level, section, status FROM users WHERE user_type = 'student' AND status = 'active' ORDER BY full_name ASC";
$students_result = mysqli_query($conn, $students_query);
$students = [];
while ($row = mysqli_fetch_assoc($students_result)) {
    $students[] = $row;
}

// Get current auto archive criteria
$criteria_query = "SELECT setting_value FROM settings WHERE setting_key = 'archive_student_criteria'";
$criteria_result = mysqli_query($conn, $criteria_query);
$archive_criteria = [];
if ($criteria_result && mysqli_num_rows($criteria_result) > 0) {
    $row = mysqli_fetch_assoc($criteria_result);
    $archive_criteria = json_decode($row['setting_value'], true) ?: [];
}

// Get unique courses for the dropdowns
$courses_query = "SELECT DISTINCT course FROM users WHERE user_type = 'student' AND course != '' ORDER BY course ASC";
$courses_result = mysqli_query($conn, $courses_query);
$courses = [];
while ($row = mysqli_fetch_assoc($courses_result)) {
    if (!empty($row['course'])) {
        $courses[] = $row['course'];
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bulk Operations - UniNeeds Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/mobile.css">
    <style>
        .criteria-card {
            background: var(--white);
            border: none;
            padding: 16px 20px;
            margin-bottom: 12px;
            border-radius: 12px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
            transition: all 0.3s ease;
        }
        .criteria-card:hover {
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
            transform: translateY(-2px);
        }
        .criteria-info {
            flex-grow: 1;
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 8px;
        }
        .date-badge {
            background: linear-gradient(135deg, #0dcaf0 0%, #0bb5d9 100%);
            color: white;
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 500;
            box-shadow: 0 2px 4px rgba(13, 202, 240, 0.2);
        }
        .year-badge {
            background: linear-gradient(135deg, #198754 0%, #155c44 100%);
            color: white;
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 500;
            box-shadow: 0 2px 4px rgba(25, 135, 84, 0.2);
        }
        .course-badge {
            background: linear-gradient(135deg, #6c757d 0%, #5a6268 100%);
            color: white;
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 500;
            box-shadow: 0 2px 4px rgba(108, 117, 125, 0.2);
        }
        .filter-section {
            background: var(--white);
            padding: 20px 24px;
            border-radius: 12px;
            margin-bottom: 20px;
            border: none;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
        }
        .student-list {
            max-height: 500px;
            overflow-y: auto;
            border-radius: 10px;
        }
        .bulk-select-all {
            margin: 16px 0 0 0;
            padding: 12px 0;
            border-top: 1px solid #f0f0f0;
        }
        .count-badge {
            display: inline-block;
            background: linear-gradient(135deg, #6c757d 0%, #5a6268 100%);
            color: white;
            padding: 5px 12px;
            border-radius: 8px;
            font-size: 0.85rem;
            font-weight: 600;
            margin-left: 8px;
            box-shadow: 0 2px 4px rgba(108, 117, 125, 0.2);
        }
        .table td,
        .table th {
            padding: 0.5rem;
            vertical-align: middle;
            font-size: 0.9rem;
        }
        /* Enhanced card headers */
        .card-header {
            background: var(--white) !important;
            border-bottom: 1px solid #f0f0f0 !important;
            padding: 16px 20px !important;
            border-radius: 12px 12px 0 0 !important;
        }
        .card-header.bg-primary {
            background: linear-gradient(135deg, #33186B 0%, #7360DF 100%) !important;
        }
        .card-header.bg-info {
            background: linear-gradient(135deg, #0dcaf0 0%, #0bb5d9 100%) !important;
        }
        /* Impact Preview Info Box - Static, Never Disappears */
        .impact-info-box {
            background: #fff3cd;
            border: 1px solid #ffc107;
            border-radius: 8px;
            padding: 16px 20px;
            margin-bottom: 16px;
            color: #664d03;
            display: block !important;
            visibility: visible !important;
            opacity: 1;
            position: relative;
        }
        .impact-info-box .info-header {
            font-weight: 600;
            font-size: 1rem;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .impact-info-box .info-text {
            margin: 10px 0;
            font-size: 0.95rem;
        }
        .impact-info-box .info-list {
            margin: 10px 0 0 20px;
            padding-left: 0;
            list-style-position: inside;
        }
        .impact-info-box .info-list li {
            margin-bottom: 8px;
            font-size: 0.95rem;
        }
        /* Archive Info Box - Static, Always Visible */
        .archive-info-box {
            background: #d1ecf1;
            border: 1px solid #bee5eb;
            border-radius: 8px;
            padding: 16px 20px;
            margin-bottom: 16px;
            color: #0c5460;
            display: block !important;
            visibility: visible !important;
            opacity: 1;
            position: relative;
        }
        .archive-info-box .info-header {
            font-weight: 600;
            font-size: 1rem;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .archive-info-box .info-text {
            margin: 10px 0;
            font-size: 0.95rem;
        }
        .archive-info-box .info-subtext {
            margin: 10px 0 0 0;
            font-size: 0.9rem;
            display: block;
        }
    </style>
</head>
<body>
    <?php include 'includes/sidebar.php'; ?>
    
    <div class="main-content">
        <div class="top-bar">
            <button class="btn btn-link d-md-none" id="sidebarToggle"><i class="bi bi-list fs-3"></i></button>
            <h2>Bulk Operations</h2>
        </div>
        <div class="content-area">
            <!-- Display Messages -->
            <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="bi bi-check-circle me-2"></i><?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="bi bi-exclamation-triangle me-2"></i><?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

                    <!-- Tab Navigation -->
                    <ul class="nav nav-tabs mb-4" id="bulkOperationsTabs" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="year-level-tab" data-bs-toggle="tab" data-bs-target="#year-level-content" type="button">
                                <i class="bi bi-sort-up"></i> Bulk Year Level Edit
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" id="auto-archive-tab" data-bs-toggle="tab" data-bs-target="#auto-archive-content" type="button">
                                <i class="bi bi-archive"></i> Auto Archive Settings
                            </button>
                        </li>
                    </ul>

                    <!-- Tab Content -->
                    <div class="tab-content">
                        <!-- Year Level Edit Tab -->
                        <div class="tab-pane fade" id="year-level-content" role="tabpanel">
                            <div class="card mb-3">
                                <div class="card-header bg-primary text-white">
                                    <h5 class="mb-0"><i class="bi bi-sort-up"></i> Bulk Year Level Edit</h5>
                                </div>
                                <div class="card-body">
                                    <p class="text-muted mb-3">Select students and change their year level in bulk.</p>

                                    <!-- Filter Section -->
                                    <div class="filter-section">
                                        <div class="row g-3">
                                            <div class="col-md-4">
                                                <label class="form-label fw-bold">Filter by Course</label>
                                                <select id="yearFilterCourse" class="form-select">
                                                    <option value="">-- All Courses --</option>
                                                    <?php foreach ($courses as $course): ?>
                                                        <option value="<?php echo htmlspecialchars($course); ?>"><?php echo htmlspecialchars($course); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="col-md-4">
                                                <label class="form-label fw-bold">Filter by Current Year</label>
                                                <select id="yearFilterLevel" class="form-select">
                                                    <option value="">-- All Years --</option>
                                                    <option value="1">1st Year</option>
                                                    <option value="2">2nd Year</option>
                                                    <option value="3">3rd Year</option>
                                                    <option value="4">4th Year</option>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="bulk-select-all mt-3">
                                            <input type="checkbox" id="yearSelectAll" class="form-check-input">
                                            <label class="form-check-label" for="yearSelectAll">
                                                Select All <span class="count-badge" id="yearCountBadge">0</span>
                                            </label>
                                        </div>
                                    </div>

                                    <!-- Students List -->
                                    <div class="card mb-3">
                                        <div class="card-header">
                                            <span class="fw-bold">Students</span>
                                            <span class="count-badge" id="yearSelectedCount">0 selected</span>
                                        </div>
                                        <div class="student-list">
                                            <div class="list-group list-group-flush" id="yearStudentList">
                                                <?php foreach ($students as $student): ?>
                                                    <div class="list-group-item student-row" data-course="<?php echo htmlspecialchars($student['course']); ?>" data-year="<?php echo $student['year_level']; ?>">
                                                        <div class="d-flex align-items-center">
                                                            <input type="checkbox" class="form-check-input year-student-checkbox" value="<?php echo $student['user_id']; ?>" data-name="<?php echo htmlspecialchars($student['full_name']); ?>">
                                                            <div class="ms-3 flex-grow-1">
                                                                <div class="fw-bold"><?php echo htmlspecialchars($student['full_name']); ?></div>
                                                                <div class="small text-muted"><?php echo htmlspecialchars($student['email']); ?></div>
                                                            </div>
                                                            <div class="text-end">
                                                                <span class="badge bg-info ms-1"><?php echo htmlspecialchars($student['student_id']); ?></span>
                                                                <span class="badge bg-secondary ms-1"><?php echo htmlspecialchars($student['course']); ?></span>
                                                                <span class="badge bg-primary ms-1">Year <?php echo $student['year_level'] ?: 'N/A'; ?></span>
                                                            </div>
                                                        </div>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Action Section -->
                                    <div class="card">
                                        <div class="card-body">
                                            <div class="row align-items-end g-3">
                                                <div class="col-md-4">
                                                    <label class="form-label fw-bold">Change Year Level To</label>
                                                    <select id="newYearLevel" class="form-select">
                                                        <option value="">-- Select New Year --</option>
                                                        <option value="1">1st Year</option>
                                                        <option value="2">2nd Year</option>
                                                        <option value="3">3rd Year</option>
                                                        <option value="4">4th Year</option>
                                                    </select>
                                                </div>
                                                <div class="col-md-4">
                                                    <button type="button" class="btn btn-primary w-100" id="bulkUpdateYearBtn" disabled>
                                                        <i class="bi bi-pencil-square"></i> Update Selected
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Auto Archive Tab -->
                        <div class="tab-pane fade show active" id="auto-archive-content" role="tabpanel">
                            <div class="card mb-3">
                                <div class="card-header bg-primary text-white">
                                    <h5 class="mb-0"><i class="bi bi-archive"></i> Auto Archive Settings</h5>
                                </div>
                                <div class="card-body">
                                    <p class="text-muted mb-3">Set automatic archival rules that will be triggered on their scheduled dates.</p>

                                    <!-- Add New Criteria Form -->
                                    <div class="card mb-4">
                                        <div class="card-header bg-info text-white">
                                            <h6 class="mb-0"><i class="bi bi-plus-circle"></i> Add New Archive Criteria</h6>
                                        </div>
                                        <div class="card-body">
                                            <!-- Impact Preview Box - Static Info (NOT an alert) -->
                                            <div id="impactWarning" class="impact-info-box">
                                                <div class="info-header">
                                                    <i class="bi bi-exclamation-triangle"></i>
                                                    <strong>Impact Preview:</strong>
                                                </div>
                                                <p class="info-text">When you add this criteria, this rule will:</p>
                                                <ul class="info-list">
                                                    <li>Check daily starting from the set date</li>
                                                    <li>Archive <strong id="impactCount">0</strong> active student(s) matching the criteria</li>
                                                    <li>Cannot be undone without manual un-archival</li>
                                                </ul>
                                            </div>

                                            <form id="archiveCriteriaForm" method="POST" action="../api/bulk-archive-criteria.php">
                                                <div class="row g-3">
                                                    <div class="col-md-4">
                                                        <label class="form-label fw-bold">Archive Date <span class="text-danger">*</span></label>
                                                        <input type="date" name="archive_date" id="archiveDate" class="form-control" required>
                                                        <small class="text-muted">Students matching this rule will be archived on or after this date</small>
                                                    </div>
                                                    <div class="col-md-4">
                                                        <label class="form-label fw-bold">Year Level <span class="text-muted">(Optional)</span></label>
                                                        <select name="year_level" id="archiveYearLevel" class="form-select">
                                                            <option value="">-- Any Year --</option>
                                                            <option value="1">1st Year</option>
                                                            <option value="2">2nd Year</option>
                                                            <option value="3">3rd Year</option>
                                                            <option value="4">4th Year</option>
                                                        </select>
                                                    </div>
                                                    <div class="col-md-4">
                                                        <label class="form-label fw-bold">Course <span class="text-muted">(Optional)</span></label>
                                                        <select name="course" id="archiveCourse" class="form-select">
                                                            <option value="">-- Any Course --</option>
                                                            <?php foreach ($courses as $course): ?>
                                                                <option value="<?php echo htmlspecialchars($course); ?>"><?php echo htmlspecialchars($course); ?></option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </div>
                                                </div>
                                                <div class="mt-3">
                                                    <button type="button" class="btn btn-outline-secondary me-2" id="previewCriteriaBtn">
                                                        <i class="bi bi-eye"></i> Preview Impact
                                                    </button>
                                                    <button type="submit" name="action" value="add" class="btn btn-success">
                                                        <i class="bi bi-plus-circle"></i> Add Criteria
                                                    </button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>

                                    <!-- Automatic Archive Info Box - Always Visible -->
                                    <div class="archive-info-box">
                                        <div class="info-header">
                                            <i class="bi bi-check-circle"></i>
                                            <strong>Automatic Archive Enabled:</strong>
                                        </div>
                                        <p class="info-text">Your archive criteria is set up and will be executed automatically. Students matching these rules will be automatically archived on their scheduled dates.</p>
                                        <small class="info-subtext">
                                            <i class="bi bi-lightning-charge"></i> <strong>How it works:</strong> The archive system runs automatically on every page load. Each day, the system checks if any criteria dates have arrived and archives matching students. This happens once per day to avoid performance overhead.
                                        </small>
                                    </div>

                                    <?php if (!empty($archive_criteria)): ?>
                                        <div class="card">
                                            <div class="card-header">
                                                <h6 class="mb-0">Active Archive Criteria</h6>
                                            </div>
                                            <div class="card-body">
                                                <?php foreach ($archive_criteria as $index => $criteria): 
                                                    $year_text = !empty($criteria['year_level']) ? "Year " . $criteria['year_level'] : "Any Year";
                                                    $course_text = !empty($criteria['course']) ? $criteria['course'] : "Any Course";
                                                    $criteria_date = isset($criteria['date']) ? $criteria['date'] : 'N/A';
                                                ?>
                                                    <div class="criteria-card">
                                                        <div class="criteria-info">
                                                            <span class="date-badge"><i class="bi bi-calendar-event"></i> <?php echo htmlspecialchars($criteria_date); ?></span>
                                                            <span class="year-badge"><?php echo htmlspecialchars($year_text); ?></span>
                                                            <span class="course-badge"><?php echo htmlspecialchars($course_text); ?></span>
                                                        </div>
                                                        <form method="POST" action="../api/bulk-archive-criteria.php" style="display: inline;">
                                                            <input type="hidden" name="action" value="delete">
                                                            <input type="hidden" name="criteria_index" value="<?php echo $index; ?>">
                                                            <button type="submit" class="btn btn-sm btn-danger" onclick="return confirm('Are you sure you want to delete this criteria?');">
                                                                <i class="bi bi-trash"></i> Remove
                                                            </button>
                                                        </form>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal for Bulk Update Confirmation -->
    <div class="modal fade" id="bulkUpdateModal" tabindex="-1">
        <div class="modal-dialog modal-sm">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-exclamation-circle"></i> Confirm Bulk Year Level Update</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>You are about to update the year level for the following students:</p>
                    <div class="alert alert-warning">
                        <strong>New Year Level:</strong> <span id="confirmYearLevel">-</span>
                    </div>
                    <div class="border rounded p-3 mb-3" style="max-height: 300px; overflow-y: auto;">
                        <h6>Selected Students:</h6>
                        <ul id="confirmStudentList"></ul>
                    </div>
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle"></i> This action will update <strong id="confirmCount">0</strong> student records.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" id="confirmBulkUpdateBtn">
                        <i class="bi bi-pencil-square"></i> Confirm Update
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/script.js"></script>
    <script>
        // Wait for DOM to be fully loaded before running scripts
        document.addEventListener('DOMContentLoaded', function() {
            console.log('DOMContentLoaded fired - initializing bulk operations');
            
            // Year Level Edit functionality
            const yearSelectAll = document.getElementById('yearSelectAll');
            const yearStudentCheckboxes = document.querySelectorAll('.year-student-checkbox');
            const newYearLevel = document.getElementById('newYearLevel');
            const bulkUpdateYearBtn = document.getElementById('bulkUpdateYearBtn');
            const yearCountBadge = document.getElementById('yearCountBadge');
            const yearSelectedCount = document.getElementById('yearSelectedCount');
            const yearFilterCourse = document.getElementById('yearFilterCourse');
            const yearFilterLevel = document.getElementById('yearFilterLevel');
            const yearStudentList = document.getElementById('yearStudentList');
            
            console.log('Elements found:', { yearSelectAll, yearStudentCheckboxes, newYearLevel, bulkUpdateYearBtn });

        // Update count badge
        function updateYearCounts() {
            const visibleRows = Array.from(yearStudentList.querySelectorAll('.student-row')).filter(row => row.style.display !== 'none');
            yearCountBadge.textContent = visibleRows.length;
            
            const selected = Array.from(yearStudentCheckboxes).filter(cb => cb.checked).length;
            yearSelectedCount.textContent = selected + ' selected';
            bulkUpdateYearBtn.disabled = selected === 0 || !newYearLevel.value;
        }

        // Filter students
        function filterYearStudents() {
            const courseFilter = yearFilterCourse.value;
            const yearFilter = yearFilterLevel.value;

            yearStudentList.querySelectorAll('.student-row').forEach(row => {
                let show = true;
                if (courseFilter && row.dataset.course !== courseFilter) show = false;
                if (yearFilter && row.dataset.year !== yearFilter) show = false;
                row.style.display = show ? '' : 'none';
            });

            // Uncheck select all when filtering
            yearSelectAll.checked = false;
            updateYearCounts();
        }

        yearFilterCourse.addEventListener('change', filterYearStudents);
        yearFilterLevel.addEventListener('change', filterYearStudents);

        // Select all functionality
        yearSelectAll.addEventListener('change', function() {
            yearStudentList.querySelectorAll('.student-row').forEach(row => {
                if (row.style.display !== 'none') {
                    row.querySelector('.year-student-checkbox').checked = this.checked;
                }
            });
            updateYearCounts();
        });

        yearStudentCheckboxes.forEach(checkbox => {
            checkbox.addEventListener('change', function() {
                const visibleRows = Array.from(yearStudentList.querySelectorAll('.student-row')).filter(row => row.style.display !== 'none');
                const selectedInVisible = visibleRows.filter(row => row.querySelector('.year-student-checkbox').checked).length;
                yearSelectAll.checked = selectedInVisible === visibleRows.length && visibleRows.length > 0;
                updateYearCounts();
            });
        });

        newYearLevel.addEventListener('change', updateYearCounts);

        // Bulk update button
        bulkUpdateYearBtn.addEventListener('click', function() {
            console.log('Update Selected button clicked');
            
            const selected = Array.from(yearStudentCheckboxes)
                .filter(cb => cb.checked)
                .map(cb => cb.value);
            
            if (selected.length === 0) {
                alert('Please select at least one student');
                return;
            }

            if (!newYearLevel.value) {
                alert('Please select a year level');
                return;
            }

            try {
                const yearLabels = { '1': '1st Year', '2': '2nd Year', '3': '3rd Year', '4': '4th Year' };
                const bulkUpdateModal = document.getElementById('bulkUpdateModal');
                
                if (!bulkUpdateModal) {
                    alert('Error: Modal not found');
                    return;
                }
                
                // Rebuild modal body completely
                const modalBody = bulkUpdateModal.querySelector('.modal-body');
                if (modalBody) {
                    modalBody.innerHTML = `
                        <p>You are about to update the year level for the following students:</p>
                        <div class="alert alert-warning">
                            <strong>New Year Level:</strong> <span>${yearLabels[newYearLevel.value]}</span>
                        </div>
                        <div class="border rounded p-3 mb-3" style="max-height: 300px; overflow-y: auto;">
                            <h6>Selected Students:</h6>
                            <ul style="margin-left: 20px;">
                                ${selected.map(id => `<li>Student #${id}</li>`).join('')}
                            </ul>
                        </div>
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle"></i> This action will update <strong>${selected.length}</strong> student records.
                        </div>
                    `;
                }
                
                // Show modal
                const modal = new bootstrap.Modal(bulkUpdateModal);
                modal.show();
                console.log('Modal shown');
                
            } catch (error) {
                console.error('Error:', error);
                alert('Error: ' + error.message);
            }
        });

        // Confirm bulk update
        document.getElementById('confirmBulkUpdateBtn').addEventListener('click', function() {
            console.log('Confirm button clicked');
            const selected = Array.from(yearStudentCheckboxes).filter(cb => cb.checked).map(cb => cb.value);
            console.log('Selected IDs:', selected);
            console.log('Year Level:', newYearLevel.value);
            
            if (selected.length === 0) {
                alert('Please select at least one student');
                return;
            }
            
            if (!newYearLevel.value) {
                alert('Please select a year level');
                return;
            }
            
            const btn = this;
            btn.disabled = true;
            btn.textContent = 'Updating...';
            
            const requestBody = `action=update&user_ids=${selected.join(',')}&year_level=${newYearLevel.value}`;
            console.log('Request body:', requestBody);
            
            fetch('../api/bulk-update-year-level.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: requestBody
            })
            .then(response => {
                console.log('Response status:', response.status);
                return response.json();
            })
            .then(data => {
                console.log('Response data:', data);
                if (data.success) {
                    // Close modal
                    const modalElement = document.getElementById('bulkUpdateModal');
                    const modalInstance = bootstrap.Modal.getInstance(modalElement);
                    if (modalInstance) modalInstance.hide();
                    
                    alert(data.message);
                    setTimeout(() => location.reload(), 500);
                } else {
                    alert('Error: ' + data.message);
                    btn.disabled = false;
                    btn.textContent = 'Confirm Update';
                }
            })
            .catch(error => {
                console.error('Fetch error:', error);
                alert('An error occurred: ' + error.message);
                btn.disabled = false;
                btn.textContent = 'Confirm Update';
            });
        });

        // Auto Archive Preview
        document.getElementById('previewCriteriaBtn').addEventListener('click', function() {
            const formData = new FormData(document.getElementById('archiveCriteriaForm'));
            fetch('../api/bulk-archive-criteria.php?action=preview', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                document.getElementById('impactCount').textContent = data.count;
            })
            .catch(error => console.error('Error:', error));
        });

            // Initialize counts
            updateYearCounts();
        }); // End DOMContentLoaded
    </script>
</body>
</html>
