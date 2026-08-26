<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();

$username = $_POST['username'] ?? '';
$password = $_POST['password'] ?? '';

if (empty($username) || empty($password)) {
    $_SESSION['error'] = 'Please enter username and password!';
    header('Location: login.php?error=empty');
    exit();
}

$hashed_password = hash('sha256', $password);

// ✅ ใช้ connect.php (customer_user มีสิทธิ์พอสำหรับ login แล้ว)
require_once('../connect.php');

$sql = "SELECT user_id, username, firstname, lastname, email, role, is_active 
        FROM `user` 
        WHERE username = ? AND password = ?";

$stmt = $mysqli->prepare($sql);
$stmt->bind_param("ss", $username, $hashed_password);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    $user = $result->fetch_assoc();
    
    // Update is_active
    $update_sql = "UPDATE `user` SET is_active = 'Y' WHERE user_id = ?";
    $update_stmt = $mysqli->prepare($update_sql);
    $update_stmt->bind_param("i", $user['user_id']);
    $update_stmt->execute();
    $update_stmt->close();
    
    // ✅ บันทึก login_history
    $login_history_sql = "INSERT INTO login_history (user_id, login_at) 
                          VALUES (?, NOW())";
    $login_stmt = $mysqli->prepare($login_history_sql);
    $login_stmt->bind_param("i", $user['user_id']);
    $login_stmt->execute();
    $login_stmt->close();
    
    // 🔥 For STAFF role, get staff_id and restaurant_id
    if ($user['role'] === 'STAFF') {
        $staff_sql = "SELECT staff_id, restaurant_id FROM staff WHERE user_id = ?";
        $staff_stmt = $mysqli->prepare($staff_sql);
        $staff_stmt->bind_param("i", $user['user_id']);
        $staff_stmt->execute();
        $staff_result = $staff_stmt->get_result();
        
        if ($staff_result->num_rows > 0) {
            $staff_data = $staff_result->fetch_assoc();
            $_SESSION['staff_id'] = $staff_data['staff_id'];
            $_SESSION['restaurant_id'] = $staff_data['restaurant_id'];
        }
        $staff_stmt->close();
    }
    
    // 🔥 For ADMIN role, get admin_id (optional - future-proof)
    if ($user['role'] === 'ADMIN') {
        $admin_sql = "SELECT admin_id FROM admin WHERE user_id = ?";
        $admin_stmt = $mysqli->prepare($admin_sql);
        $admin_stmt->bind_param("i", $user['user_id']);
        $admin_stmt->execute();
        $admin_result = $admin_stmt->get_result();
        
        if ($admin_result->num_rows > 0) {
            $admin_data = $admin_result->fetch_assoc();
            $_SESSION['admin_id'] = $admin_data['admin_id'];
        }
        $admin_stmt->close();
    }
    
    // Set session
    $_SESSION['user_id'] = $user['user_id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['role'] = $user['role'];
    $_SESSION['firstname'] = $user['firstname'];
    $_SESSION['lastname'] = $user['lastname'];
    $_SESSION['email'] = $user['email'];
    $_SESSION['login_time'] = date('Y-m-d H:i:s');
    
    $stmt->close();
    $mysqli->close();
    
    // Redirect
    switch ($user['role']) {
        case 'ADMIN':
            header('Location: ../superadmin/index.php');
            exit();
        case 'STAFF':
            header('Location: ../staff/index.php');
            exit();
        case 'CUSTOMER':
        default:
            header('Location: ../customer/dashboard.php');
            exit();
    }
} else {
    $mysqli->close();
    $_SESSION['error'] = 'Invalid username or password!';
    header('Location: login.php?error=invalid');
    exit();
}
?>