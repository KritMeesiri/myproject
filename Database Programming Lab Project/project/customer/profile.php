<?php
session_start();
require_once('../connect.php');

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login/login.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$message = '';
$error = '';

// ดึงข้อมูล user จากฐานข้อมูล
$sql = "SELECT u.*, 
        (SELECT COUNT(*) FROM reservation WHERE user_id = u.user_id) as total_bookings
        FROM user u 
        WHERE u.user_id = ?";

$stmt = $mysqli->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$user_data = $result->fetch_assoc();

if (!$user_data) {
    header('Location: ../login/logout.php');
    exit();
}

// จัดการ Update Profile
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] == 'update_profile') {
        $firstname = $mysqli->real_escape_string(trim($_POST['firstname']));
        $lastname = $mysqli->real_escape_string(trim($_POST['lastname']));
        $email = $mysqli->real_escape_string(trim($_POST['email']));
        $phone = $mysqli->real_escape_string(trim($_POST['phone']));
        
        // Validate email
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Invalid email format';
        } else {
            // เช็ค email ซ้ำ (ยกเว้นของตัวเอง)
            $check_sql = "SELECT user_id FROM user WHERE email = ? AND user_id != ?";
            $check_stmt = $mysqli->prepare($check_sql);
            $check_stmt->bind_param("si", $email, $user_id);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            
            if ($check_result->num_rows > 0) {
                $error = 'Email already exists';
            } else {
                // Update ข้อมูล
                $update_sql = "UPDATE user SET firstname = ?, lastname = ?, email = ?, user_phone = ? WHERE user_id = ?";
                $update_stmt = $mysqli->prepare($update_sql);
                $update_stmt->bind_param("ssssi", $firstname, $lastname, $email, $phone, $user_id);
                
                if ($update_stmt->execute()) {
                    $_SESSION['firstname'] = $firstname;
                    $_SESSION['lastname'] = $lastname;
                    $_SESSION['email'] = $email;
                    $message = 'Profile updated successfully!';
                    
                    // Refresh data
                    $stmt->execute();
                    $result = $stmt->get_result();
                    $user_data = $result->fetch_assoc();
                } else {
                    $error = 'Failed to update profile';
                }
            }
        }
    } elseif ($_POST['action'] == 'change_password') {
        $current_password = trim($_POST['current_password']);
        $new_password = trim($_POST['new_password']);
        $confirm_password = trim($_POST['confirm_password']);
        
        // Validate
        if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
            $error = 'All password fields are required';
        } elseif ($new_password !== $confirm_password) {
            $error = 'New passwords do not match';
        } elseif (strlen($new_password) < 4) {
            $error = 'Password must be at least 4 characters';
        } elseif (hash('sha256', $current_password) !== $user_data['password']) {
            $error = 'Current password is incorrect';
        } else {
            // Update password - HASH ก่อนบันทึก
            $hashed_password = hash('sha256', $new_password);
            $update_sql = "UPDATE user SET password = ? WHERE user_id = ?";
            $update_stmt = $mysqli->prepare($update_sql);
            $update_stmt->bind_param("si", $hashed_password, $user_id);
            
            if ($update_stmt->execute()) {
                $message = 'Password changed successfully!';
            } else {
                $error = 'Failed to change password';
            }
        }
    }
}

$full_name = trim(($user_data['firstname'] ?? '') . ' ' . ($user_data['lastname'] ?? ''));
if (empty(trim($full_name))) {
    $full_name = $user_data['username'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="css/profile.css">
    <link rel="stylesheet" href="css/notification.css">
</head>
<body class="profile-body">
    <nav class="navbar">
        <div class="nav-content">
            <div class="nav-left">
                <img src="css/catlog.png"  alt="cat"style="height:36px;position: relative; top: -18px;left:-70px;object-fit:contain"> <img src="css/logo.png" alt="logo" style="height:36px;position: relative;left:-55px;object-fit:contain"/> 
            </div>
            <div class="nav-right">
                <span class="notification-icon" id="notificationBtn" style="cursor: pointer; position: relative;">🔔</span>
                <span class="calendar-icon" onclick="window.location.href='my_bookings.php'" style="cursor: pointer;" title="My Bookings">📅</span>
                <span class="user-icon active" onclick="window.location.href='profile.php'" style="cursor: pointer;" title="Profile"><img src="css/druckcat_profile.jpeg" alt="drunkcat" style="height:28px;border-radius:22px; object-fit:contain;"></span>
                <a href="../login/logout.php" class="btn-logout-icon">Logout</a>
            </div>
        </div>
    </nav>

    <div class="back-button-below-nav">
        <button class="btn-back-below-nav" onclick="window.location.href='dashboard.php'">
            ← Back to Dashboard
        </button>
    </div>

    <div class="profile-container">
        <?php if ($message): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>
        
        <?php if ($error): ?>
        <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        
        <div class="profile-header">
            <div class="profile-avatar">
            <span class="avatar-icon"></span> <img src="css/druckcat_profile.jpeg" alt="drunkcat" style="width:100px;height:100px;border-radius:50px; object-fit:contain;"></span>
            </div>
            <h1 class="profile-name"><?php echo htmlspecialchars($full_name); ?></h1>
            <p class="profile-username">@<?php echo htmlspecialchars($user_data['username']); ?></p>
            <p class="profile-member-since">Member since <?php echo date('F Y', strtotime($user_data['user_created_at'])); ?></p>
        </div>

        <div class="stats-grid stats-grid-single">
            <div class="stat-card">
                <div class="stat-icon">📅</div>
                <div class="stat-info">
                    <span class="stat-value"><?php echo $user_data['total_bookings'] ?? 0; ?></span>
                    <span class="stat-label">Total Bookings</span>
                </div>
            </div>
        </div>

        <form method="POST" id="profileForm">
            <input type="hidden" name="action" value="update_profile">
            <div class="profile-section">
                <h2 class="section-title">Personal Information</h2>
                <div class="info-form">
                    <div class="form-group">
                        <label>First Name</label>
                        <input type="text" class="form-input" name="firstname" value="<?php echo htmlspecialchars($user_data['firstname'] ?? ''); ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Last Name</label>
                        <input type="text" class="form-input" name="lastname" value="<?php echo htmlspecialchars($user_data['lastname'] ?? ''); ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Username (Read-only)</label>
                        <input type="text" class="form-input" value="<?php echo htmlspecialchars($user_data['username']); ?>" readonly>
                    </div>
                    <div class="form-group">
                        <label>Email</label>
                        <input type="email" class="form-input" name="email" value="<?php echo htmlspecialchars($user_data['email']); ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Phone Number</label>
                        <input type="tel" class="form-input" name="phone" value="<?php echo htmlspecialchars($user_data['user_phone'] ?? ''); ?>" pattern="[0-9]{10}" maxlength="10">
                    </div>
                </div>
                <button type="submit" class="btn-save">Save Profile</button>
            </div>
        </form>

        <form method="POST" id="passwordForm">
            <input type="hidden" name="action" value="change_password">
            <div class="profile-section">
                <h2 class="section-title">Change Password</h2>
                <div class="info-form">
                    <div class="form-group">
                        <label>Current Password</label>
                        <input type="password" class="form-input" name="current_password" placeholder="Enter current password">
                    </div>
                    <div class="form-group">
                        <label>New Password</label>
                        <input type="password" class="form-input" name="new_password" placeholder="Enter new password" minlength="4">
                    </div>
                    <div class="form-group">
                        <label>Confirm New Password</label>
                        <input type="password" class="form-input" name="confirm_password" placeholder="Confirm new password" minlength="4">
                    </div>
                </div>
                <button type="submit" class="btn-save">Change Password</button>
            </div>
        </form>
    </div>

    <?php include 'notification_widget.php'; ?>
</body>
</html>