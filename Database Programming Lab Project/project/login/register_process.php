<?php
session_start();
require_once('../connect.php');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: register.php');
    exit();
}

$username = trim($_POST['username'] ?? '');
$email = trim($_POST['email'] ?? '');
$password = trim($_POST['password'] ?? '');
$confirm_password = trim($_POST['confirm_password'] ?? '');
$firstname = trim($_POST['firstname'] ?? '');
$lastname = trim($_POST['lastname'] ?? '');
$phone = trim($_POST['phone'] ?? '');
$dob = trim($_POST['dob'] ?? '');

// Build URL parameters for redirecting back with data
function buildRedirectUrl($error, $data) {
    $params = array_filter([
        'error' => $error,
        'username' => $data['username'] ?? '',
        'email' => $data['email'] ?? '',
        'firstname' => $data['firstname'] ?? '',
        'lastname' => $data['lastname'] ?? '',
        'phone' => $data['phone'] ?? '',
        'dob' => $data['dob'] ?? ''
    ]);
    return 'register.php?' . http_build_query($params);
}

$userData = compact('username', 'email', 'firstname', 'lastname', 'phone', 'dob');

// Validate - ตรวจสอบทุกฟิลด์ที่บังคับ
if (empty($username) || empty($email) || empty($password) || empty($confirm_password) ||
    empty($firstname) || empty($lastname) || empty($phone) || empty($dob)) {
    header('Location: ' . buildRedirectUrl('empty', $userData));
    exit();
}

if ($password !== $confirm_password) {
    header('Location: ' . buildRedirectUrl('password_mismatch', $userData));
    exit();
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    unset($userData['email']);
    header('Location: ' . buildRedirectUrl('invalid_email', $userData));
    exit();
}

if (strlen($username) < 4) {
    header('Location: ' . buildRedirectUrl('username_short', $userData));
    exit();
}

if (strlen($password) < 4) {
    header('Location: ' . buildRedirectUrl('password_short', $userData));
    exit();
}

// Validate phone number (10 digits)
if (!preg_match('/^[0-9]{10}$/', $phone)) {
    unset($userData['phone']);
    header('Location: ' . buildRedirectUrl('invalid_phone', $userData));
    exit();
}

// ✅ ตรวจสอบ username ซ้ำ (ใช้ Prepared Statement)
$check_sql = "SELECT user_id FROM `user` WHERE username = ?";
$stmt = $mysqli->prepare($check_sql);
$stmt->bind_param("s", $username);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    $stmt->close();
    unset($userData['username']);
    header('Location: ' . buildRedirectUrl('username_exists', $userData));
    exit();
}
$stmt->close();

// ✅ ตรวจสอบ email ซ้ำ (ใช้ Prepared Statement)
$check_sql = "SELECT user_id FROM `user` WHERE email = ?";
$stmt = $mysqli->prepare($check_sql);
$stmt->bind_param("s", $email);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    $stmt->close();
    unset($userData['email']);
    header('Location: ' . buildRedirectUrl('email_exists', $userData));
    exit();
}
$stmt->close();

// ✅ ตรวจสอบ phone ซ้ำ (ใช้ Prepared Statement)
$check_sql = "SELECT user_id FROM `user` WHERE user_phone = ?";
$stmt = $mysqli->prepare($check_sql);
$stmt->bind_param("s", $phone);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    $stmt->close();
    unset($userData['phone']);
    header('Location: ' . buildRedirectUrl('phone_exists', $userData));
    exit();
}
$stmt->close();

// ✅ Hash password ด้วย SHA-256
$hashed_password = hash('sha256', $password);

// ✅ Insert ข้อมูล (ใช้ Prepared Statement)
$insert_sql = "INSERT INTO `user` (username, email, password, firstname, lastname, dob, user_phone, role, is_active) 
               VALUES (?, ?, ?, ?, ?, ?, ?, 'CUSTOMER', 'Y')";
$stmt = $mysqli->prepare($insert_sql);
$stmt->bind_param("sssssss", $username, $email, $hashed_password, $firstname, $lastname, $dob, $phone);

if ($stmt->execute()) {
    // ✅ สมัครสำเร็จ
    $user_id = $stmt->insert_id;
    
    // Auto login
    $_SESSION['user_id'] = $user_id;
    $_SESSION['username'] = $username;
    $_SESSION['role'] = 'CUSTOMER';
    $_SESSION['firstname'] = $firstname;
    $_SESSION['lastname'] = $lastname;
    $_SESSION['email'] = $email;
    $_SESSION['login_time'] = date('Y-m-d H:i:s');
    
    $stmt->close();
    $mysqli->close();
    
    // Redirect to dashboard
    header('Location: ../customer/dashboard.php');
    exit();
} else {
    // ❌ Insert ล้มเหลว
    $stmt->close();
    $mysqli->close();
    header('Location: ' . buildRedirectUrl('registration_failed', $userData));
    exit();
}
?>