<?php
require_once '../connect.php';

header('Content-Type: application/json');

// Log request for debugging
error_log("Received request: " . print_r($_POST, true));

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Invalid request method']);
    exit;
}

$reserve_id = isset($_POST['reserve_id']) ? intval($_POST['reserve_id']) : 0;
$reserve_status = isset($_POST['reserve_status']) ? trim($_POST['reserve_status']) : '';
$staff_id = isset($_POST['staff_id']) ? intval($_POST['staff_id']) : 0;
$user_id = isset($_POST['user_id']) ? intval($_POST['user_id']) : 0;

// Validation
if ($reserve_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid reservation ID: ' . $reserve_id]);
    exit;
}

if ($staff_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid staff ID']);
    exit;
}

// ตรวจสอบว่า status ถูกต้องตาม ENUM ของ database
$allowed_statuses = ['HOLD', 'CONFIRMED', 'CANCELLED'];
if (!in_array($reserve_status, $allowed_statuses)) {
    echo json_encode(['success' => false, 'error' => 'Invalid reservation status: ' . $reserve_status]);
    exit;
}

try {
    // ตรวจสอบว่า reservation มีอยู่จริงและดึง table_id จาก reservation_tables
    $check_sql = "SELECT r.reserve_id, r.reserve_status, r.reserve_date, r.user_id,
                         GROUP_CONCAT(rt.table_id) as table_ids
                  FROM reservation r
                  LEFT JOIN reservation_tables rt ON r.reserve_id = rt.reserve_id
                  WHERE r.reserve_id = ?
                  GROUP BY r.reserve_id";
    
    $check_stmt = $mysqli->prepare($check_sql);
    $check_stmt->bind_param('i', $reserve_id);
    $check_stmt->execute();
    $result = $check_stmt->get_result();
    $existing = $result->fetch_assoc();
    $check_stmt->close();
    
    if (!$existing) {
        echo json_encode(['success' => false, 'error' => 'Reservation not found']);
        exit;
    }
    
    $checkin_created = false;
    $table_id = null;
    
    // ถ้าเป็นการยกเลิก ให้ใช้ stored procedure cancel_reservation
    if ($reserve_status === 'CANCELLED') {
        // ✅ ใช้ stored procedure cancel_reservation แทนการเขียน logic เอง
        $call_sql = "CALL cancel_reservation(?, ?, @is_refunded)";
        $call_stmt = $mysqli->prepare($call_sql);
        $call_stmt->bind_param('ii', $reserve_id, $existing['user_id']);
        
        if (!$call_stmt->execute()) {
            echo json_encode(['success' => false, 'error' => 'Failed to cancel reservation: ' . $call_stmt->error]);
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
            'message' => 'Reservation cancelled successfully',
            'new_status' => 'CANCELLED',
            'reserve_id' => $reserve_id,
            'is_refunded' => (bool)$is_refunded
        ]);
        exit;
    }
    
    // ถ้าเป็นการ CONFIRM
    if ($reserve_status === 'CONFIRMED') {
        // เริ่ม transaction
        $mysqli->begin_transaction();
        
        try {
            // ตรวจสอบว่ามี table_id หรือไม่
            if (!$existing['table_ids']) {
                $mysqli->rollback();
                echo json_encode(['success' => false, 'error' => 'No table assigned to this reservation']);
                exit;
            }
            
            // แยก table_ids (กรณีที่จองหลายโต๊ะ)
            $table_ids = explode(',', $existing['table_ids']);
            
            // เลือก table_id ตัวแรกสำหรับ check-in
            $table_id = intval($table_ids[0]);
            
            // ตรวจสอบว่ามี check-in record อยู่แล้วหรือไม่
            $check_checkin_sql = "SELECT checkin_id FROM checkin WHERE reserve_id = ? LIMIT 1";
            $check_checkin_stmt = $mysqli->prepare($check_checkin_sql);
            $check_checkin_stmt->bind_param('i', $reserve_id);
            $check_checkin_stmt->execute();
            $check_checkin_result = $check_checkin_stmt->get_result();
            $existing_checkin = $check_checkin_result->fetch_assoc();
            $check_checkin_stmt->close();
            
            if (!$existing_checkin) {
                // สร้าง check-in record
                $checkin_sql = "INSERT INTO checkin (staff_id, reserve_id, table_id, checkin_at)
                               VALUES (?, ?, ?, NOW())";
                $checkin_stmt = $mysqli->prepare($checkin_sql);
                $checkin_stmt->bind_param('iii', $staff_id, $reserve_id, $table_id);
                $checkin_result = $checkin_stmt->execute();
                $checkin_stmt->close();
                
                if (!$checkin_result) {
                    $mysqli->rollback();
                    echo json_encode(['success' => false, 'error' => 'Failed to create check-in record']);
                    exit;
                }
                $checkin_created = true;

                // อัพเดทสถานะของโต๊ะทั้งหมดเป็น occupied
                $tables_updated = 0;
                foreach ($table_ids as $tid) {
                    $tid = intval($tid);
                    $update_table_sql = "UPDATE `table` SET table_status = 'occupied' WHERE table_id = ?";
                    $update_table_stmt = $mysqli->prepare($update_table_sql);
                    $update_table_stmt->bind_param('i', $tid);
                    
                    if (!$update_table_stmt->execute()) {
                        $mysqli->rollback();
                        echo json_encode(['success' => false, 'error' => 'Failed to update table status to occupied']);
                        $update_table_stmt->close();
                        exit;
                    }
                    $tables_updated++;
                    $update_table_stmt->close();
                }
            }
            
            // อัพเดทสถานะ reservation
            $sql = "UPDATE reservation 
                    SET reserve_status = ?
                    WHERE reserve_id = ?";
            
            $stmt = $mysqli->prepare($sql);
            $stmt->bind_param('si', $reserve_status, $reserve_id);
            $result = $stmt->execute();
            $stmt->close();
            
            if (!$result) {
                $mysqli->rollback();
                echo json_encode(['success' => false, 'error' => 'Failed to update reservation status']);
                exit;
            }
            
            // Commit transaction
            $mysqli->commit();
            
            echo json_encode([
                'success' => true,
                'message' => 'Reservation confirmed successfully and tables set to occupied',
                'tables_updated' => $tables_updated,
                'new_status' => $reserve_status,
                'reserve_id' => $reserve_id,
                'checkin_created' => $checkin_created,
                'table_id' => $table_id
            ]);
            
        } catch (Exception $e) {
            $mysqli->rollback();
            throw $e;
        }
        exit;
    }
    
    // ถ้าเป็นการเปลี่ยนเป็น HOLD (ไม่ควรเกิดขึ้นบ่อย แต่รองรับไว้)
    if ($reserve_status === 'HOLD') {
        $sql = "UPDATE reservation 
                SET reserve_status = ?
                WHERE reserve_id = ?";
        
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param('si', $reserve_status, $reserve_id);
        $result = $stmt->execute();
        $stmt->close();
        
        if ($result) {
            echo json_encode([
                'success' => true,
                'message' => 'Reservation status updated successfully',
                'new_status' => $reserve_status,
                'reserve_id' => $reserve_id
            ]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Failed to update reservation status']);
        }
        exit;
    }
    
} catch (Exception $e) {
    error_log("Error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => 'Error: ' . $e->getMessage()
    ]);
}
?>