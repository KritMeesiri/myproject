<?php
session_start();
require_once '../connect.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit();
}

$reserve_id = isset($_POST['reserve_id']) ? (int)$_POST['reserve_id'] : 0;
$user_id = $_SESSION['user_id'];

if ($reserve_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid reservation ID']);
    exit();
}

try {
    // ตรวจสอบว่าการจองนี้เป็นของผู้ใช้หรือไม่
    $sql_check = "SELECT r.* FROM reservation r WHERE r.reserve_id = ? AND r.user_id = ?";
    
    $stmt_check = $mysqli->prepare($sql_check);
    if (!$stmt_check) {
        throw new Exception("Prepare failed: " . $mysqli->error);
    }
    
    $stmt_check->bind_param("ii", $reserve_id, $user_id);
    $stmt_check->execute();
    $result = $stmt_check->get_result();
    $booking = $result->fetch_assoc();

    if (!$booking) {
        echo json_encode(['success' => false, 'message' => 'Booking not found or access denied']);
        exit();
    }

    // ตรวจสอบว่าสามารถยกเลิกได้หรือไม่
    if ($booking['reserve_status'] == 'CANCELLED') {
        echo json_encode(['success' => false, 'message' => 'Booking already cancelled']);
        exit();
    }

    // ✅ เรียกใช้ Procedure cancel_reservation
    // Procedure จะทำ:
    // 1. ยกเลิก reservation (UPDATE status = CANCELLED)
    // 2. คืนสถานะโต๊ะ (UPDATE table status = AVAILABLE)
    // 3. Refund payment ถ้ายกเลิกก่อน deadline
    // Trigger reservation_cancel_noti จะสร้าง notification อัตโนมัติ
    
    $sql_procedure = "CALL cancel_reservation(?, ?, @is_refunded)";
    $stmt_procedure = $mysqli->prepare($sql_procedure);
    
    if (!$stmt_procedure) {
        throw new Exception("Failed to prepare procedure: " . $mysqli->error);
    }
    
    $stmt_procedure->bind_param("ii", $reserve_id, $user_id);
    
    if (!$stmt_procedure->execute()) {
        throw new Exception("Failed to cancel via procedure: " . $stmt_procedure->error);
    }
    
    $stmt_procedure->close();
    
    // ดึงค่า @is_refunded
    $result = $mysqli->query("SELECT @is_refunded as is_refunded");
    $row = $result->fetch_assoc();
    $is_refunded = $row['is_refunded'];
    
    // นับจำนวนโต๊ะที่ยกเลิก
    $sql_count = "SELECT COUNT(*) as table_count FROM reservation_tables WHERE reserve_id = ?";
    $stmt_count = $mysqli->prepare($sql_count);
    $stmt_count->bind_param("i", $reserve_id);
    $stmt_count->execute();
    $count_result = $stmt_count->get_result();
    $table_count = $count_result->fetch_assoc()['table_count'];
    
    $refund_message = $is_refunded 
        ? " Your refund will be processed within 3-5 business days." 
        : " No refund available (past deadline).";
    
    echo json_encode([
        'success' => true,
        'message' => "Booking cancelled successfully." . 
                    ($table_count > 0 ? " Total $table_count table(s) cancelled." : "") . 
                    $refund_message
    ]);
    
} catch (Exception $e) {
    // Rollback transaction
    if (isset($mysqli) && $mysqli && method_exists($mysqli, 'rollback')) {
        try {
            $mysqli->rollback();
        } catch (Exception $rollback_error) {
            // Ignore rollback error
        }
    }
    
    echo json_encode([
        'success' => false,
        'message' => 'Failed to cancel: ' . $e->getMessage()
    ]);
}
?>