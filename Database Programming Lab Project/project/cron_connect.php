<?php
/**
 * Database Connection for Cron Jobs
 * 
 * Cron jobs ต้องการสิทธิ์พิเศษ:
 * - EXECUTE procedures (cancel_reservation, reset_tables_after_closing)
 * - INSERT notification
 * - UPDATE table status
 * 
 * ใช้ admin_user เพื่อให้มีสิทธิ์ครบ
 */

$db_host = 'localhost';
$db_name = 'project';

// ✅ Cron jobs ใช้ admin_user (มีสิทธิ์ครบ)
$db_user = 'admin_user';
$db_pass = 'admin_pass_2024!';

$mysqli = new mysqli($db_host, $db_user, $db_pass, $db_name);

if ($mysqli->connect_errno) {
    echo "[ERROR] Database connection failed: " . $mysqli->connect_error . "\n";
    exit(1);
}

// ตั้งค่า Timezone สำหรับ PHP และ MySQL
date_default_timezone_set('Asia/Bangkok');
$mysqli->query("SET time_zone = '+07:00'");
$mysqli->set_charset("utf8mb4");

// Log connection (optional - สำหรับ debug)
// echo "[INFO] Connected to database as: " . $db_user . "\n";
?>