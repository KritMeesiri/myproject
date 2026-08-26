<?php
/**
 * Cron Job: Send Deadline Warnings
 * Uses claim_deadline_at (datetime) to find reservations expiring ~1 hour ahead
 */

require_once __DIR__ . '/../cron_connect.php';

$start_time = microtime(true);
$timestamp = date('Y-m-d H:i:s');

echo "[$timestamp] Starting send_deadline_warnings cron job...\n";

try {
    // ใช้ claim_deadline_at (datetime) — หา reservation ที่จะครบกำหนดใน ~1 ชั่วโมง (50-70 นาที)
    $sql = "SELECT r.reserve_id, r.user_id, r.reserve_date, r.claim_deadline_at
            FROM reservation r
            WHERE r.reserve_status = 'HOLD'
              AND TIMESTAMPDIFF(MINUTE, NOW(), r.claim_deadline_at) BETWEEN 50 AND 70";
    
    $result = $mysqli->query($sql);
    if (!$result) {
        throw new Exception("Failed to query reservations: " . $mysqli->error);
    }
    
    $sent_count = 0;
    
    // เตรียม statement ให้สอดคล้องกับโครงสร้าง notification: 
    // (user_id, reserve_id, noti_type, noti_title, noti_message)
    $notification_sql = "INSERT INTO notification (user_id, reserve_id, noti_type, noti_title, noti_message)
                         VALUES (?, ?, 'REMINDER', ?, ?)";
    $stmt = $mysqli->prepare($notification_sql);
    if (!$stmt) {
        throw new Exception("Failed to prepare notification statement: " . $mysqli->error);
    }
    
    while ($row = $result->fetch_assoc()) {
        $reserveId = (int)$row['reserve_id'];
        $userId = (int)$row['user_id'];
        $claimDt = $row['claim_deadline_at']; // datetime string
        $dateStr = date('d/m/Y', strtotime($row['reserve_date']));
        $timeStr = date('H:i', strtotime($claimDt));
        
        $title = "Booking Deadline Reminder";
        $message = "Reminder: Your reservation is expiring at {$timeStr} on {$dateStr}. Please check in before this time.";
        
        // bind: user_id (i), reserve_id (i), title (s), message (s)
        $stmt->bind_param('iiss', $userId, $reserveId, $title, $message);
        
        if ($stmt->execute()) {
            $sent_count++;
            echo "  - Sent reminder to user #{$userId} for reservation #{$reserveId}\n";
        } else {
            echo "  - Failed to send reminder for reservation #{$reserveId}: " . $stmt->error . "\n";
        }
    }
    
    $stmt->close();
    $result->free();
    
    $end_time = microtime(true);
    $execution_time = round($end_time - $start_time, 2);
    
    echo "[$timestamp] Completed! Sent $sent_count notification(s) in {$execution_time}s\n";
    echo str_repeat('-', 60) . "\n";
    
} catch (Exception $e) {
    $error_timestamp = date('Y-m-d H:i:s');
    echo "[$error_timestamp] ERROR: " . $e->getMessage() . "\n";
    echo str_repeat('-', 60) . "\n";
}

$mysqli->close();
?>
