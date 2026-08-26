<?php
require_once '../connect.php';

header('Content-Type: application/json; charset=utf-8');

// รับข้อมูลจาก POST
$restaurant_id = intval($_POST['restaurant_id'] ?? 0);
$open_time = $_POST['open_time'] ?? null;
$close_time = $_POST['close_time'] ?? null;

if (!$restaurant_id || !$open_time || !$close_time) {
    echo json_encode([
        'success' => false,
        'error' => 'Missing required fields'
    ]);
    exit;
}

// อัพเดทเวลาเปิด-ปิดของร้านอาหาร
$sql = "UPDATE restaurant 
        SET open_time = ?,
            close_time = ?
        WHERE restaurant_id = ?";

$stmt = $mysqli->prepare($sql);
if (!$stmt) {
    echo json_encode([
        'success' => false,
        'error' => 'Database error: ' . $mysqli->error
    ]);
    exit;
}

$stmt->bind_param('ssi', $open_time, $close_time, $restaurant_id);
$result = $stmt->execute();

if ($result) {
    echo json_encode([
        'success' => true,
        'message' => 'Opening hours updated successfully'
    ]);
} else {
    echo json_encode([
        'success' => false,
        'error' => 'Failed to update opening hours: ' . $stmt->error
    ]);
}

$stmt->close();
?>