<?php
session_start();
require_once 'database.php';

if (isLoggedIn()) {
    if (isAdmin()) {
        header('Location: /unineed/admin/dashboard.php');
    } else {
        header('Location: /unineed/student/products.php');
    }
    exit();
}

$error = '';
$success = '';
$token = $_GET['token'] ?? '';
$valid_token = false;
$user_id = null;

if (!empty($token)) {
    $token_hash = hash('sha256', $token);
    
    // Create password_resets table if it doesn't exist
    $create_table = "CREATE TABLE IF NOT EXISTS password_resets (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        token_hash VARCHAR(255) NOT NULL UNIQUE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        expires_at DATETIME NOT NULL,
        FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
    )";
    mysqli_query($conn, $create_table);
    $col_q = mysqli_query($conn, "SHOW COLUMNS FROM password_resets LIKE 'otp_hash'");
    if (!$col_q || mysqli_num_rows($col_q) === 0) {
        mysqli_query($conn, "ALTER TABLE password_resets ADD COLUMN otp_hash VARCHAR(255) NULL");
    }
    
    $query = "SELECT user_id FROM password_resets WHERE token_hash = '$token_hash' AND expires_at > NOW() LIMIT 1";
    $result = mysqli_query($conn, $query);
    
    if ($result && mysqli_num_rows($result) === 1) {
        $valid_token = true;
        $row = mysqli_fetch_assoc($result);
        $user_id = $row['user_id'];
    } else {
        $error = "This password reset link is invalid or has expired. Please request a new one.";
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $valid_token) {
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    
    if (empty($new_password) || empty($confirm_password)) {
        $error = "Please fill in all fields.";
    } elseif ($new_password !== $confirm_password) {
        $error = "Passwords do not match.";
    } elseif (strlen($new_password) < 6) {
        $error = "Password must be at least 6 characters long.";
    } else {
        $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
        $update_query = "UPDATE users SET password = '$hashed_password' WHERE user_id = $user_id";
        
        if (mysqli_query($conn, $update_query)) {
            $delete_query = "DELETE FROM password_resets WHERE user_id = $user_id";
            mysqli_query($conn, $delete_query);
            
            $success = "Password has been successfully reset! You can now login with your new password.";
            $valid_token = false; // Hide the form after successful reset
        } else {
            $error = "An error occurred while resetting your password. Please try again.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password - UniNeeds</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        body {
            background-image: url('../assets/images/bpcbg.png');
            background-size: cover;
            background-position: center;
            background-repeat: no-repeat;
            position: relative;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        body::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.3);
            z-index: 0;
        }
        .reset-container {
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            max-width: 500px;
            width: 100%;
            padding: 60px 40px;
            position: relative;
            z-index: 1;
        }
        .form-control:focus {
            border-color: #61B087;
            box-shadow: 0 0 0 0.2rem rgba(97, 176, 135, 0.25);
        }
        .btn-reset {
            background: linear-gradient(135deg, #61B087 0%, #4e8d6c 100%);
            border: none;
            padding: 12px;
            font-weight: 600;
            transition: transform 0.2s;
        }
        .btn-reset:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 20px rgba(97, 176, 135, 0.4);
            color: white;
        }
        .back-link {
            color: #61B087;
            text-decoration: none;
            font-size: 0.95rem;
        }
        .back-link:hover {
            text-decoration: underline;
        }
        .icon-header {
            font-size: 3rem;
            color: #61B087;
            text-align: center;
            margin-bottom: 20px;
        }
        .password-strength {
            margin-top: 10px;
        }
        .strength-bar {
            height: 5px;
            background: #e9ecef;
            border-radius: 5px;
            overflow: hidden;
        }
        .strength-fill {
            height: 100%;
            width: 0%;
            transition: width 0.3s;
        }
        @media (max-width: 480px) {
            .reset-container {
                margin: 15px;
                padding: 30px 20px;
            }
            h2 {
                font-size: 1.5rem;
            }
        }
    </style>
</head>
<body>
    <div class="reset-container">
        <div class="icon-header">
            <i class="bi bi-shield-lock"></i>
        </div>
        <h2 class="text-center mb-4">Create New Password</h2>
        
        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="bi bi-exclamation-triangle-fill me-2"></i><?php echo $error; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="bi bi-check-circle-fill me-2"></i><?php echo $success; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <?php if ($valid_token && !$success): ?>
            <p class="text-muted text-center mb-4">Enter your new password below.</p>
            
            <form method="POST" action="" id="resetForm">
                <div class="mb-3">
                    <label for="new_password" class="form-label">New Password</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-lock"></i></span>
                        <input type="password" class="form-control" id="new_password" name="new_password" required placeholder="Enter new password">
                        <button class="btn btn-outline-secondary" type="button" id="togglePassword1">
                            <i class="bi bi-eye-slash" id="toggleIcon1"></i>
                        </button>
                    </div>
                    <div class="password-strength">
                        <div class="strength-bar">
                            <div class="strength-fill" id="strengthFill"></div>
                        </div>
                        <small id="strengthText" class="text-muted d-block mt-1"></small>
                    </div>
                </div>
                
                <div class="mb-3">
                    <label for="confirm_password" class="form-label">Confirm Password</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-lock"></i></span>
                        <input type="password" class="form-control" id="confirm_password" name="confirm_password" required placeholder="Confirm new password">
                        <button class="btn btn-outline-secondary" type="button" id="togglePassword2">
                            <i class="bi bi-eye-slash" id="toggleIcon2"></i>
                        </button>
                    </div>
                    <small id="matchText" class="text-muted d-block mt-1"></small>
                </div>
                
                <button type="submit" class="btn btn-primary btn-reset w-100">
                    <i class="bi bi-check-circle me-2"></i>Reset Password
                </button>
            </form>
        <?php endif; ?>
        
        <?php if (!$valid_token && !$error && !$success): ?>
            <p class="text-center text-muted">Loading password reset form...</p>
        <?php endif; ?>
        
        <?php if ($success): ?>
            <hr class="my-4">
            <div class="text-center">
                <a href="/unineed/config/index.php" class="btn btn-primary btn-reset">
                    <i class="bi bi-box-arrow-in-right me-2"></i>Go to Login
                </a>
            </div>
        <?php endif; ?>
        
        <?php if (!$success): ?>
            <hr class="my-4">
            <div class="text-center">
                <a href="/unineed/config/index.php" class="back-link">
                    <i class="bi bi-arrow-left me-2"></i>Back to Login
                </a>
            </div>
        <?php endif; ?>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Toggle password visibility
        document.getElementById('togglePassword1').addEventListener('click', function() {
            const input = document.getElementById('new_password');
            const icon = document.getElementById('toggleIcon1');
            
            if (input.type === 'password') {
                input.type = 'text';
                icon.classList.remove('bi-eye-slash');
                icon.classList.add('bi-eye');
            } else {
                input.type = 'password';
                icon.classList.remove('bi-eye');
                icon.classList.add('bi-eye-slash');
            }
        });
        
        document.getElementById('togglePassword2').addEventListener('click', function() {
            const input = document.getElementById('confirm_password');
            const icon = document.getElementById('toggleIcon2');
            
            if (input.type === 'password') {
                input.type = 'text';
                icon.classList.remove('bi-eye-slash');
                icon.classList.add('bi-eye');
            } else {
                input.type = 'password';
                icon.classList.remove('bi-eye');
                icon.classList.add('bi-eye-slash');
            }
        });
        
        // Password strength indicator
        document.getElementById('new_password').addEventListener('input', function() {
            const password = this.value;
            const strengthFill = document.getElementById('strengthFill');
            const strengthText = document.getElementById('strengthText');
            let strength = 0;
            
            if (password.length >= 6) strength += 25;
            if (password.length >= 8) strength += 25;
            if (/[A-Z]/.test(password)) strength += 25;
            if (/[0-9]/.test(password)) strength += 25;
            
            strengthFill.style.width = strength + '%';
            
            if (strength < 25) {
                strengthFill.style.background = '#dc3545';
                strengthText.textContent = 'Weak';
            } else if (strength < 50) {
                strengthFill.style.background = '#fd7e14';
                strengthText.textContent = 'Fair';
            } else if (strength < 75) {
                strengthFill.style.background = '#ffc107';
                strengthText.textContent = 'Good';
            } else {
                strengthFill.style.background = '#28a745';
                strengthText.textContent = 'Strong';
            }
            
            checkPasswordMatch();
        });
        
        // Check password match
        function checkPasswordMatch() {
            const password = document.getElementById('new_password').value;
            const confirmPassword = document.getElementById('confirm_password').value;
            const matchText = document.getElementById('matchText');
            
            if (confirmPassword === '') {
                matchText.textContent = '';
            } else if (password === confirmPassword) {
                matchText.textContent = '✓ Passwords match';
                matchText.style.color = '#28a745';
            } else {
                matchText.textContent = '✗ Passwords do not match';
                matchText.style.color = '#dc3545';
            }
        }
        
        document.getElementById('confirm_password').addEventListener('input', checkPasswordMatch);
        
        // Validate form on submit
        document.getElementById('resetForm').addEventListener('submit', function(e) {
            const password = document.getElementById('new_password').value;
            const confirmPassword = document.getElementById('confirm_password').value;
            
            if (password !== confirmPassword) {
                e.preventDefault();
                alert('Passwords do not match!');
                return false;
            }
            
            if (password.length < 6) {
                e.preventDefault();
                alert('Password must be at least 6 characters long!');
                return false;
            }
        });
    </script>
    <style>
        #togglePassword1, #togglePassword2 {
            border-color: #dee2e6;
        }
        #togglePassword1:hover, #togglePassword2:hover {
            background-color: #f8f9fa;
        }
        #togglePassword1:focus, #togglePassword2:focus {
            box-shadow: none;
            border-color: #61B087;
        }
    </style>
</body>
</html>
