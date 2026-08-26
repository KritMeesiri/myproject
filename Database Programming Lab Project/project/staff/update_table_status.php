<?php
session_start();
require '../connect.php';

header('Content-Type: application/json');

// Log request
error_log("Table Status Update - Received: " . print_r($_POST, true));

// ตรวจสอบ session
$staff_id = $_SESSION['staff_id'] ?? null;
$restaurant_id = $_SESSION['restaurant_id'] ?? null;

if (!$staff_id || !$restaurant_id) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized: Please login']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

$table_id = isset($_POST['table_id']) ? intval($_POST['table_id']) : 0;
$action = isset($_POST['action']) ? trim($_POST['action']) : '';

// Validation
if ($table_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid table ID']);
    exit;
}

if (!in_array($action, ['checkin', 'cancel'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid action: ' . $action]);
    exit;
}

try {
    // หา reservation ที่เกี่ยวข้องกับโต๊ะนี้ พร้อมกับ table_ids ทั้งหมด
    $sql = "SELECT r.reserve_id, r.user_id, r.reserve_status, r.reserve_date,
                   GROUP_CONCAT(rt.table_id) as table_ids
            FROM reservation r
            INNER JOIN reservation_tables rt ON r.reserve_id = rt.reserve_id
            INNER JOIN `table` t ON rt.table_id = t.table_id
            INNER JOIN area a ON t.area_id = a.area_id
            WHERE rt.reserve_id = (
                SELECT rt2.reserve_id 
                FROM reservation_tables rt2 
                WHERE rt2.table_id = ?
                LIMIT 1
            )
            AND r.reserve_status IN ('HOLD', 'CONFIRMED')
            AND a.restaurant_id = ?
            GROUP BY r.reserve_id
            ORDER BY r.reserve_date DESC, r.created_at DESC
            LIMIT 1";
    
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param('ii', $table_id, $restaurant_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $reservation = $result->fetch_assoc();
    $stmt->close();
    
    if (!$reservation) {
        echo json_encode(['success' => false, 'message' => 'No active reservation found for this table']);
        exit;
    }
    
    $reserve_id = $reservation['reserve_id'];
    $user_id = $reservation['user_id'];
    $all_table_ids = explode(',', $reservation['table_ids']); // โต๊ะทั้งหมดใน reservation นี้
    
    // ACTION: CHECK-IN
    if ($action === 'checkin') {
    // ตรวจสอบว่ามี check-in อยู่แล้วหรือไม่
    $check_sql = "SELECT checkin_id FROM checkin WHERE reserve_id = ? LIMIT 1";
    $check_stmt = $mysqli->prepare($check_sql);
    $check_stmt->bind_param('i', $reserve_id);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    $existing_checkin = $check_result->fetch_assoc();
    $check_stmt->close();
    
    if ($existing_checkin) {
        echo json_encode(['success' => false, 'message' => 'This reservation is already checked in']);
        exit;
    }
    
    // เริ่ม transaction
    $mysqli->begin_transaction();
    
    try {
        // สร้าง check-in record
        $checkin_sql = "INSERT INTO checkin (staff_id, reserve_id, table_id, checkin_at)
                       VALUES (?, ?, ?, NOW())";
        $checkin_stmt = $mysqli->prepare($checkin_sql);
        $checkin_stmt->bind_param('iii', $staff_id, $reserve_id, $table_id);
        
        if (!$checkin_stmt->execute()) {
            $mysqli->rollback();
            echo json_encode(['success' => false, 'message' => 'Failed to create check-in record']);
            exit;
        }
        $checkin_stmt->close();
        
        // 👇 วางโค้ดตรงนี้ - อัพเดทสถานะของโต๊ะทั้งหมดเป็น occupied
        $tables_updated = 0;
        foreach ($all_table_ids as $tid) {
            $tid = intval($tid);
            $update_table_sql = "UPDATE `table` SET table_status = 'occupied' WHERE table_id = ?";
            $update_table_stmt = $mysqli->prepare($update_table_sql);
            $update_table_stmt->bind_param('i', $tid);
            
            if (!$update_table_stmt->execute()) {
                $mysqli->rollback();
                echo json_encode(['success' => false, 'message' => 'Failed to update table status to occupied']);
                $update_table_stmt->close();
                exit;
            }
            $tables_updated++;
            $update_table_stmt->close();
        }
        
        // อัพเดทสถานะ reservation เป็น CONFIRMED
        $update_sql = "UPDATE reservation SET reserve_status = 'CONFIRMED' WHERE reserve_id = ?";
        $update_stmt = $mysqli->prepare($update_sql);
        $update_stmt->bind_param('i', $reserve_id);
        
        if (!$update_stmt->execute()) {
            $mysqli->rollback();
            echo json_encode(['success' => false, 'message' => 'Failed to update reservation status']);
            exit;
        }
        $update_stmt->close();
        
        // Commit
        $mysqli->commit();
        
        echo json_encode([
            'success' => true,
            'message' => 'Check-in successful - All tables set to occupied',
            'tables_updated' => $tables_updated,
            'reserve_id' => $reserve_id,
            'action' => 'checkin',
            'table_ids' => $all_table_ids,
            'table_count' => count($all_table_ids)
        ]);
        
        } catch (Exception $e) {
            $mysqli->rollback();
            throw $e;
        }
        exit;
    }
    
    // ACTION: CANCEL (Set Available)
    if ($action === 'cancel') {
        // ใช้ stored procedure cancel_reservation
        $call_sql = "CALL cancel_reservation(?, ?, @is_refunded)";
        $call_stmt = $mysqli->prepare($call_sql);
        $call_stmt->bind_param('ii', $reserve_id, $user_id);
        
        if (!$call_stmt->execute()) {
            echo json_encode(['success' => false, 'message' => 'Failed to cancel reservation: ' . $call_stmt->error]);
            $call_stmt->close();
            exit;
        }
        $call_stmt->close();
        
        // ดึงค่า @is_refunded
        $refund_result = $mysqli->query("SELECT @is_refunded as is_refunded");
        $refund_row = $refund_result->fetch_assoc();
        $is_refunded = $refund_row['is_refunded'];
        
        echo json_encode([
            'success' => true,
            'message' => 'Reservation cancelled successfully for all tables',
            'reserve_id' => $reserve_id,
            'is_refunded' => (bool)$is_refunded,
            'action' => 'cancel',
            'table_ids' => $all_table_ids, // ส่งโต๊ะทั้งหมดกลับไปเพื่อ refresh UI
            'table_count' => count($all_table_ids)
        ]);
        exit;
    }
    
} catch (Exception $e) {
    error_log("Error in update_table_status.php: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ]);
}
?>