<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$db_host = 'localhost';
$db_name = 'project';

// ถ้า login แล้ว ใช้ role ที่ถูกต้อง
// ถ้ายังไม่ login ใช้ customer_user (สำหรับหน้า login/register)
if (isset($_SESSION['role'])) {
    switch ($_SESSION['role']) {
        case 'CUSTOMER':
            $db_user = 'customer_user';
            $db_pass = 'customer_pass_2024!';
            break;
        
        case 'STAFF':
            $db_user = 'staff_user';
            $db_pass = 'staff_pass_2024!';
            break;
        
        case 'ADMIN':
            $db_user = 'admin_user';
            $db_pass = 'admin_pass_2024!';
            break;
        
        default:
            $db_user = 'customer_user';
            $db_pass = 'customer_pass_2024!';
    }
} else {
    // ยังไม่ได้ login - สำหรับหน้า login/register/public
    $db_user = 'customer_user';
    $db_pass = 'customer_pass_2024!';
}

$mysqli = new mysqli($db_host, $db_user, $db_pass, $db_name);

if ($mysqli->connect_errno) {
    echo $mysqli->connect_errno . ": " . $mysqli->connect_error;
}

// ✅ ตั้งค่า Timezone สำหรับ PHP และ MySQL
date_default_timezone_set('Asia/Bangkok');
$mysqli->query("SET time_zone = '+07:00'");
$mysqli->set_charset("utf8mb4");
?>