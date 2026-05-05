<?php

require_once '../config/database.php';
requireAdmin();
$is_superadmin = isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'superadmin';

if (!$is_superadmin) {
    header('Location: dashboard.php');
    exit();
}

// Prevent caching
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$success = '';
$error = '';
$preview_data = [];
$upload_attempted = false;

// Handle CSV Preview
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file'])) {
    $upload_attempted = true;
    
    if ($_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
        $error = "File upload failed. Please try again.";
    } else {
        $filename = $_FILES['csv_file']['tmp_name'];
        $original_filename = $_FILES['csv_file']['name'];
        
        // Check file extension
        $file_extension = strtolower(pathinfo($original_filename, PATHINFO_EXTENSION));
        if ($file_extension !== 'csv') {
            $error = "Invalid file format. Please upload a CSV file.";
        }
        
        if (!$error) {
            $file = fopen($filename, 'r');
            $headers = fgetcsv($file);
            
            if (!$headers) {
                $error = "Failed to read CSV file.";
            } else {
                // Validate headers
                $required_headers = ['student_id', 'full_name', 'email', 'phone', 'course', 'year_level', 'section'];
                $headers_lower = array_map('strtolower', $headers);
                
                $missing_headers = [];
                foreach ($required_headers as $header) {
                    if (!in_array($header, $headers_lower)) {
                        $missing_headers[] = $header;
                    }
                }
                
                if (!empty($missing_headers)) {
                    $error = "Missing required columns: " . implode(', ', $missing_headers);
                } else {
                    // Read data
                    $row_num = 1;
                    while (($row = fgetcsv($file)) !== false) {
                        $row_num++;
                        if (empty(array_filter($row))) {
                            continue;
                        }
                        
                        $data = array_combine($headers_lower, $row);
                        $preview_data[] = [
                            'row' => $row_num,
                            'student_id' => trim($data['student_id'] ?? ''),
                            'full_name' => trim($data['full_name'] ?? ''),
                            'email' => trim($data['email'] ?? ''),
                            'phone' => trim($data['phone'] ?? ''),
                            'course' => trim($data['course'] ?? ''),
                            'year_level' => trim($data['year_level'] ?? ''),
                            'section' => trim($data['section'] ?? '')
                        ];
                    }
                    
                    if (empty($preview_data)) {
                        $error = "CSV file is empty.";
                    }
                }
            }
            fclose($file);
        }
    }
}

// Handle Download Template
if (isset($_GET['action']) && $_GET['action'] === 'download_template') {
    $filename = 'students_bulk_upload_template.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=' . $filename);
    
    echo "student_id,full_name,email,phone,course,year_level,section\n";
    echo "22013938,Justine Martin,martin@gmail.com,9933447697,BSIS,4,C\n";
    echo "20013456,Juan Santos,juan@gmail.com,9912345678,BSOM,2,B\n";
    echo "21005789,Maria Cruz,maria@gmail.com,9923456789,BSAIS,3,A\n";
    exit();
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bulk Upload Students - UniNeeds</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
        }
        .main-content {
            flex: 1;
            padding: 20px;
        }
        .card {
            border: none;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            margin-bottom: 20px;
        }
        .card-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            padding: 1.5rem;
        }
        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
        }
        .btn-primary:hover {
            background: linear-gradient(135deg, #764ba2 0%, #667eea 100%);
        }
        .preview-table {
            margin-top: 2rem;
        }
        .table-responsive {
            max-height: 500px;
            overflow-y: auto;
        }
        .instruction-box {
            background-color: #e8f4f8;
            border-left: 4px solid #667eea;
            padding: 1rem;
            margin-bottom: 1rem;
        }
        .instruction-box h5 {
            color: #667eea;
            margin-bottom: 0.5rem;
        }
        .upload-zone {
            border: 2px dashed #667eea;
            border-radius: 8px;
            padding: 2rem;
            text-align: center;
            background-color: #f8f9fa;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        .upload-zone:hover {
            background-color: #f0f0f0;
            border-color: #764ba2;
        }
        .upload-zone.dragover {
            background-color: #e8f4f8;
            border-color: #667eea;
        }
        .table thead {
            position: sticky;
            top: 0;
            background: #f8f9fa;
        }
    </style>
</head>
<body>
    <div class="container-fluid main-content">
        <div class="row">
            <div class="col-md-12">
                <h1 class="mb-4 text-white">
                    <i class="fas fa-upload me-2"></i>Bulk Upload Students
                </h1>

                <?php if ($success): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="fas fa-check-circle me-2"></i><?php echo $success; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if ($error): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="fas fa-exclamation-circle me-2"></i><?php echo $error; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-info-circle me-2"></i>Instructions</h5>
                    </div>
                    <div class="card-body">
                        <div class="instruction-box">
                            <h5><i class="fas fa-download me-2"></i>Step 1: Download Template</h5>
                            <p>Click the button below to download the CSV template. This file contains the exact format and column headers you need to use.</p>
                            <a href="?action=download_template" class="btn btn-outline-primary btn-sm">
                                <i class="fas fa-file-csv me-2"></i>Download Template
                            </a>
                        </div>

                        <div class="instruction-box">
                            <h5><i class="fas fa-pencil me-2"></i>Step 2: Fill in Student Data</h5>
                            <p>Open the downloaded CSV file in Excel or a text editor and fill in the following columns:</p>
                            <ul>
                                <li><strong>student_id</strong> - Unique student ID (will be auto-prefixed with MA-)</li>
                                <li><strong>full_name</strong> - Student's full name</li>
                                <li><strong>email</strong> - Student's email address (must be unique)</li>
                                <li><strong>phone</strong> - Student's phone number</li>
                                <li><strong>course</strong> - Course code (e.g., BSIS, BSOM, BSAIS)</li>
                                <li><strong>year_level</strong> - Year level (1-4)</li>
                                <li><strong>section</strong> - Section (A, B, C, etc.)</li>
                            </ul>
                        </div>

                        <div class="instruction-box">
                            <h5><i class="fas fa-cloud-upload-alt me-2"></i>Step 3: Upload CSV File</h5>
                            <p>Upload your completed CSV file below. The system will preview the data before confirming the upload.</p>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-cloud-upload-alt me-2"></i>Upload CSV File</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" enctype="multipart/form-data" id="uploadForm">
                            <div class="upload-zone" id="uploadZone">
                                <i class="fas fa-cloud-upload-alt fa-3x text-primary mb-3"></i>
                                <h5>Drag and drop your CSV file here</h5>
                                <p class="text-muted">or click to select file</p>
                                <input type="file" name="csv_file" id="csvFile" accept=".csv" hidden required>
                            </div>
                            <div class="mt-3 text-center">
                                <small class="text-muted">Maximum file size: 10MB. Accepted format: CSV</small>
                            </div>
                        </form>
                    </div>
                </div>

                <?php if (!empty($preview_data)): ?>
                    <div class="card preview-table">
                        <div class="card-header">
                            <h5 class="mb-0"><i class="fas fa-eye me-2"></i>Preview (<?php echo count($preview_data); ?> students)</h5>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-striped table-hover">
                                    <thead>
                                        <tr>
                                            <th>#</th>
                                            <th>Student ID</th>
                                            <th>Full Name</th>
                                            <th>Email</th>
                                            <th>Phone</th>
                                            <th>Course</th>
                                            <th>Year</th>
                                            <th>Section</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($preview_data as $idx => $data): ?>
                                            <tr>
                                                <td><?php echo $idx + 1; ?></td>
                                                <td><?php echo htmlspecialchars($data['student_id']); ?></td>
                                                <td><?php echo htmlspecialchars($data['full_name']); ?></td>
                                                <td><?php echo htmlspecialchars($data['email']); ?></td>
                                                <td><?php echo htmlspecialchars($data['phone']); ?></td>
                                                <td><?php echo htmlspecialchars($data['course']); ?></td>
                                                <td><?php echo htmlspecialchars($data['year_level']); ?></td>
                                                <td><?php echo htmlspecialchars($data['section']); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <div class="mt-3">
                                <button type="button" class="btn btn-success btn-lg" id="confirmUpload">
                                    <i class="fas fa-check me-2"></i>Confirm & Upload All Students
                                </button>
                                <button type="button" class="btn btn-secondary btn-lg" onclick="location.reload()">
                                    <i class="fas fa-times me-2"></i>Cancel
                                </button>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const uploadZone = document.getElementById('uploadZone');
        const csvFile = document.getElementById('csvFile');
        const uploadForm = document.getElementById('uploadForm');
        const confirmUploadBtn = document.getElementById('confirmUpload');

        // File selection
        uploadZone.addEventListener('click', () => csvFile.click());

        csvFile.addEventListener('change', (e) => {
            if (csvFile.files.length > 0) {
                uploadForm.submit();
            }
        });

        // Drag and drop
        uploadZone.addEventListener('dragover', (e) => {
            e.preventDefault();
            uploadZone.classList.add('dragover');
        });

        uploadZone.addEventListener('dragleave', () => {
            uploadZone.classList.remove('dragover');
        });

        uploadZone.addEventListener('drop', (e) => {
            e.preventDefault();
            uploadZone.classList.remove('dragover');
            
            const files = e.dataTransfer.files;
            if (files.length > 0) {
                csvFile.files = files;
                uploadForm.submit();
            }
        });

        // Confirm upload
        if (confirmUploadBtn) {
            confirmUploadBtn.addEventListener('click', () => {
                confirmUploadBtn.disabled = true;
                confirmUploadBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Processing...';
                
                fetch('/unineed/api/bulk-upload-students.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        action: 'confirm_upload',
                        preview_data: <?php echo json_encode($preview_data); ?>
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showResultModal(data.results);
                    } else {
                        alert('Error: ' + data.message);
                        confirmUploadBtn.disabled = false;
                        confirmUploadBtn.innerHTML = '<i class="fas fa-check me-2"></i>Confirm & Upload All Students';
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('An error occurred. Please try again.');
                    confirmUploadBtn.disabled = false;
                    confirmUploadBtn.innerHTML = '<i class="fas fa-check me-2"></i>Confirm & Upload All Students';
                });
            });
        }

        function showResultModal(results) {
            const successful = results.filter(r => r.status === 'success').length;
            const failed = results.filter(r => r.status === 'error').length;
            
            let html = `
                <div class="modal fade" id="resultModal" tabindex="-1">
                    <div class="modal-dialog modal-dialog-scrollable">
                        <div class="modal-content">
                            <div class="modal-header ${failed === 0 ? 'bg-success' : 'bg-warning'} text-white">
                                <h5 class="modal-title">Upload Results</h5>
                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                            </div>
                            <div class="modal-body">
                                <div class="alert ${failed === 0 ? 'alert-success' : 'alert-warning'}">
                                    <i class="fas fa-chart-pie me-2"></i>
                                    <strong>Total: ${results.length}</strong> | 
                                    <span class="text-success"><i class="fas fa-check me-1"></i>Successful: ${successful}</span> | 
                                    <span class="text-danger"><i class="fas fa-times me-1"></i>Failed: ${failed}</span>
                                </div>
            `;

            if (successful > 0) {
                html += `
                    <div class="mb-3">
                        <h6 class="text-success"><i class="fas fa-check-circle me-2"></i>Successfully Added Students</h6>
                        <div class="table-responsive" style="max-height: 300px; overflow-y: auto;">
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>Name</th>
                                        <th>Email</th>
                                        <th>Student ID</th>
                                    </tr>
                                </thead>
                                <tbody>
                `;
                results.filter(r => r.status === 'success').forEach(r => {
                    html += `
                        <tr>
                            <td>${r.data.full_name}</td>
                            <td>${r.data.email}</td>
                            <td>${r.data.student_id}</td>
                        </tr>
                    `;
                });
                html += `
                                </tbody>
                            </table>
                        </div>
                    </div>
                `;
            }

            if (failed > 0) {
                html += `
                    <div class="mb-3">
                        <h6 class="text-danger"><i class="fas fa-exclamation-circle me-2"></i>Failed to Add Students</h6>
                        <div class="table-responsive" style="max-height: 300px; overflow-y: auto;">
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>Row</th>
                                        <th>Name</th>
                                        <th>Reason</th>
                                    </tr>
                                </thead>
                                <tbody>
                `;
                results.filter(r => r.status === 'error').forEach(r => {
                    html += `
                        <tr>
                            <td>${r.row}</td>
                            <td>${r.data.full_name}</td>
                            <td><small>${r.message}</small></td>
                        </tr>
                    `;
                });
                html += `
                                </tbody>
                            </table>
                        </div>
                    </div>
                `;
            }

            html += `
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-primary" onclick="location.href='users.php'">Go to Users</button>
                                <button type="button" class="btn btn-secondary" onclick="location.reload()">Upload More</button>
                            </div>
                        </div>
                    </div>
                </div>
            `;

            document.body.insertAdjacentHTML('beforeend', html);
            const resultModal = new bootstrap.Modal(document.getElementById('resultModal'));
            resultModal.show();
        }
    </script>
</body>
</html>
