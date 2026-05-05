<?php
session_start();
require_once 'database.php';
require_once 'EmailHelper.php';

// Redirect if already logged in
if (isLoggedIn()) {
    if (isAdmin()) {
        header('Location: ../admin/dashboard.php');
    } else {
        header('Location: ../student/products.php');
    }
    exit();
}

$message = '';
$error = '';
$otp_sent = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $student_id = clean($_POST['student_id']);
    
    // Find user by student_id
    $query = "SELECT user_id, email, full_name, student_id FROM users WHERE student_id = '$student_id' AND status = 'active' LIMIT 1";
    $result = mysqli_query($conn, $query);
    
    if ($result && mysqli_num_rows($result) === 1) {
        $user = mysqli_fetch_assoc($result);
        
        // Generate 6-digit OTP
        $otp = str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT);
        $otp_hash = hash('sha256', $otp);
        $expires_at = date('Y-m-d H:i:s', strtotime('+15 minutes'));
        
        // Create password_resets table if it doesn't exist
        $create_table = "CREATE TABLE IF NOT EXISTS password_resets (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            token_hash VARCHAR(255),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            expires_at DATETIME NOT NULL,
            FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
        )";
        mysqli_query($conn, $create_table);

        $col_q = mysqli_query($conn, "SHOW COLUMNS FROM password_resets LIKE 'otp_hash'");
        if (!$col_q || mysqli_num_rows($col_q) === 0) {
            mysqli_query($conn, "ALTER TABLE password_resets ADD COLUMN otp_hash VARCHAR(255) NULL");
        }
        
        $delete_query = "DELETE FROM password_resets WHERE user_id = {$user['user_id']}";
        mysqli_query($conn, $delete_query);
        
        $insert_query = "REPLACE INTO password_resets (user_id, otp_hash, expires_at) VALUES ({$user['user_id']}, '$otp_hash', '$expires_at')";
        
        if (mysqli_query($conn, $insert_query)) {
            try {
                $emailer = new EmailHelper();
                $mail_sent = $emailer->sendPasswordResetOTP($user['email'], $user['full_name'], $otp);
            } catch (Exception $e) {
                error_log("EmailHelper Error: " . $e->getMessage());
                $mail_sent = false;
            }
            
            $_SESSION['forgot_user_id'] = $user['user_id'];
            $_SESSION['forgot_email'] = $user['email'];
            $_SESSION['forgot_student_id'] = $student_id;
            
            $message = "An OTP has been sent to <strong>{$user['email']}</strong>. Please check your email and enter the OTP to reset your password.";
            
            error_log("OTP Email Attempt - User: {$user['student_id']}, Email: {$user['email']}, Success: " . ($mail_sent ? "Yes" : "No (May need email configuration)"));
            
            $otp_sent = true;
        } else {
            $error = "An error occurred. Please try again.";
        }
    } else {
        $error = "No active account found with that Student ID. Please check and try again.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - UniNeeds</title>
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
            <i class="bi bi-key"></i>
        </div>
        <h2 class="text-center mb-4">Reset Your Password</h2>
        
        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="bi bi-exclamation-triangle-fill me-2"></i><?php echo $error; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <?php if ($message): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="bi bi-check-circle-fill me-2"></i><?php echo $message; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <?php if (!$otp_sent): ?>
            <p class="text-muted text-center mb-4">Enter your Student ID to receive an OTP (One-Time Password) via email.</p>
            
            <form method="POST" action="">
                <div class="mb-3">
                    <label for="student_id" class="form-label">Student ID</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-person-badge"></i></span>
                        <input type="text" class="form-control" id="student_id" name="student_id" required placeholder="Enter your student ID">
                    </div>
                </div>
                <button type="submit" class="btn btn-primary btn-reset w-100">
                    <i class="bi bi-send me-2"></i>Send OTP
                </button>
            </form>
        <?php else: ?>
            <p class="text-center text-muted mb-4">An OTP has been sent to your email. Please check your inbox (including spam folder) and proceed to enter the OTP.</p>
            <div class="alert alert-info">
                <i class="bi bi-info-circle-fill me-2"></i><small>The OTP will expire in 15 minutes.</small>
            </div>
            
            <a href="/unineed/config/verify-otp.php" class="btn btn-primary btn-reset w-100">
                <i class="bi bi-check-circle me-2"></i>Enter OTP & Reset Password
            </a>
        <?php endif; ?>
        
        <hr class="my-4">
        
        <div class="text-center">
            <a href="/unineed/config/index.php" class="back-link">
                <i class="bi bi-arrow-left me-2"></i>Back to Login
            </a>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
