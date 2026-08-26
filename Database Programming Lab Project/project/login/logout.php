<?php
session_start();
require_once('../connect.php');

if (isset($_SESSION['user_id'])) {
    $user_id = $_SESSION['user_id'];
    
    // ✅ 1. บันทึก logout_history ก่อน (สำคัญมาก!)
    $logout_sql = "INSERT INTO logout_history (user_id, logout_at) VALUES (?, NOW())";
    $logout_stmt = $mysqli->prepare($logout_sql);
    $logout_stmt->bind_param("i", $user_id);
    $logout_stmt->execute();
    $logout_stmt->close();
    
    // ✅ 2. อัพเดท is_active = 'N' เมื่อ logout
    $update_sql = "UPDATE `user` SET is_active = 'N' WHERE user_id = ?";
    $update_stmt = $mysqli->prepare($update_sql);
    $update_stmt->bind_param("i", $user_id);
    $update_stmt->execute();
    $update_stmt->close();
    
    $mysqli->close();
}

// ลบ session ทั้งหมด
session_unset();
session_destroy();

// Redirect กลับไปหน้า login (อยู่ folder เดียวกัน)
header('Location: login.php');
exit();
?>