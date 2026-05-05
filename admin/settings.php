<?php
require_once '../config/database.php';
requireAdmin();

$user_id = $_SESSION['user_id'];
$success = null;
$error = null;

// Get current user data
$query = "SELECT * FROM users WHERE user_id = $user_id";
$result = mysqli_query($conn, $query);
$user = mysqli_fetch_assoc($result);

// Handle Profile Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $full_name = clean($_POST['full_name']);
    $phone = clean($_POST['phone']);
    
    $update_query = "UPDATE users SET full_name = '$full_name', phone = '$phone' WHERE user_id = $user_id";
    
    if (mysqli_query($conn, $update_query)) {
        $_SESSION['full_name'] = $full_name;
        $success = "Profile updated successfully!";
        $result = mysqli_query($conn, $query);
        $user = mysqli_fetch_assoc($result);
    } else {
        $error = "Failed to update profile.";
    }
}

// Handle Password Change
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    $current_password = $_POST['current_password'];
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];
    
    if (password_verify($current_password, $user['password'])) {
        if ($new_password === $confirm_password) {
            if (strlen($new_password) >= 6) {
                $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                $update_query = "UPDATE users SET password = '$hashed_password' WHERE user_id = $user_id";
                if (mysqli_query($conn, $update_query)) {
                    $success = "Password changed successfully!";
                } else {
                    $error = "Failed to change password.";
                }
            } else { $error = "Password must be at least 6 characters."; }
        } else { $error = "Passwords do not match."; }
    } else { $error = "Current password is incorrect."; }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Settings - UniNeeds</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/mobile.css">
    <style>
        .card-header[aria-expanded="true"] .toggle-icon { transform: rotate(180deg); }
        .toggle-icon { transition: transform 0.3s ease; }
        .qr-preview { max-width: 150px; border: 1px solid #ddd; padding: 5px; border-radius: 5px; }
    </style>
</head>
<body>
    <?php include 'includes/sidebar.php'; ?>
    <div class="main-content">
        <div class="top-bar">
            <button class="btn btn-link d-md-none" id="sidebarToggle"><i class="bi bi-list fs-3"></i></button>
            <h2>Settings</h2>
        </div>
        <div class="content-area">
            <?php if (isset($success)): ?>
            <div class="alert alert-success alert-dismissible fade show"><i class="bi bi-check-circle me-2"></i><?php echo $success; ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
            <?php endif; ?>
            <?php if (isset($error)): ?>
            <div class="alert alert-danger alert-dismissible fade show"><i class="bi bi-exclamation-triangle me-2"></i><?php echo $error; ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
            <?php endif; ?>

            <div class="accordion" id="adminSettingsAccordion">
                <div class="card mb-3">
                    <div class="card-header d-flex justify-content-between align-items-center p-3" role="button" data-bs-toggle="collapse" data-bs-target="#collapseProfile">
                        <h5 class="mb-0"><i class="bi bi-person me-2"></i>Admin Profile Information</h5>
                        <i class="bi bi-chevron-down toggle-icon"></i>
                    </div>
                    <div class="collapse show" id="collapseProfile" data-bs-parent="#adminSettingsAccordion">
                        <div class="card-body">
                            <form method="POST">
                                <div class="mb-3"><label class="form-label">Full Name</label><input type="text" class="form-control" name="full_name" value="<?php echo htmlspecialchars($user['full_name']); ?>" required></div>
                                <div class="mb-3"><label class="form-label">Phone Number</label><input type="text" class="form-control" name="phone" value="<?php echo htmlspecialchars($user['phone']); ?>" maxlength="11" inputmode="numeric" pattern="\d*" oninput="this.value=this.value.replace(/\D/g,'').slice(0,11)"></div>
                                <button type="submit" name="update_profile" class="btn btn-primary"><i class="bi bi-save me-2"></i>Update Profile</button>
                            </form>
                        </div>
                    </div>
                </div>

                <div class="card mb-3">
                    <div class="card-header d-flex justify-content-between align-items-center p-3" role="button" data-bs-toggle="collapse" data-bs-target="#collapsePassword">
                        <h5 class="mb-0"><i class="bi bi-shield-lock me-2"></i>Change Password</h5>
                        <i class="bi bi-chevron-down toggle-icon"></i>
                    </div>
                    <div class="collapse" id="collapsePassword" data-bs-parent="#adminSettingsAccordion">
                        <div class="card-body">
                            <form method="POST">
                                <div class="mb-3"><label class="form-label">Current Password *</label><input type="password" class="form-control" name="current_password" required></div>
                                <div class="mb-3"><label class="form-label">New Password *</label><input type="password" class="form-control" name="new_password" required minlength="6"></div>
                                <div class="mb-3"><label class="form-label">Confirm New Password *</label><input type="password" class="form-control" name="confirm_password" required></div>
                                <button type="submit" name="change_password" class="btn btn-warning"><i class="bi bi-key me-2"></i>Change Password</button>
                            </form>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/script.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            var accordion = document.getElementById('adminSettingsAccordion');
            if (accordion) {
                accordion.addEventListener('shown.bs.collapse', function (e) {
                    e.target.previousElementSibling.querySelector('.toggle-icon').style.transform = 'rotate(180deg)';
                });
                accordion.addEventListener('hidden.bs.collapse', function (e) {
                    e.target.previousElementSibling.querySelector('.toggle-icon').style.transform = 'rotate(0deg)';
                });
            }
        });
    </script>
</body>
</html>