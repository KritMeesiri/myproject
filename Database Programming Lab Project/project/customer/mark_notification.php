<?php
session_start();
require_once '../connect.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit();
}

$user_id = $_SESSION['user_id'];

// รับ JSON data
$json = file_get_contents('php://input');
$data = json_decode($json, true);

$action = $data['action'] ?? '';

try {
    if ($action === 'mark_all_read') {
        // Mark all notifications as read - แก้ให้ตรงกับ database
        $sql = "UPDATE notification 
                SET is_read = 'Y' 
                WHERE user_id = ? AND is_read = 'N'";
        
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param("i", $user_id);
        
        if ($stmt->execute()) {
            echo json_encode([
                'success' => true,
                'message' => 'All notifications marked as read',
                'affected_rows' => $stmt->affected_rows
            ]);
        } else {
            throw new Exception('Failed to mark all as read: ' . $mysqli->error);
        }
        
    } elseif ($action === 'mark_one_read') {
        // Mark one notification as read - แก้ให้ตรงกับ database
        $noti_id = $data['noti_id'] ?? 0;
        
        if ($noti_id <= 0) {
            throw new Exception('Invalid notification ID');
        }
        
        $sql = "UPDATE notification 
                SET is_read = 'Y' 
                WHERE noti_id = ? AND user_id = ?";
        
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param("ii", $noti_id, $user_id);
        
        if ($stmt->execute()) {
            echo json_encode([
                'success' => true,
                'message' => 'Notification marked as read'
            ]);
        } else {
            throw new Exception('Failed to mark as read: ' . $mysqli->error);
        }
        
    } else {
        throw new Exception('Invalid action');
    }
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>