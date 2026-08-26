<?php
require '../connect.php';

header('Content-Type: application/json; charset=utf-8');

$user_id = intval($_GET['user_id'] ?? 0);

if (!$user_id) {
    echo json_encode(['success' => false, 'error' => 'Invalid user ID']);
    exit;
}

// 🔥 NEW: ใช้ restaurant_id จาก session
$restaurant_id = $_SESSION['restaurant_id'] ?? null;

if (!$restaurant_id) {
    echo json_encode(['success' => false, 'error' => 'Restaurant ID not found in session']);
    exit;
}

try {
    // ดึงข้อมูล reservations พร้อมหมายเลขโต๊ะ
    $sql = "SELECT 
                r.reserve_id,
                r.reserve_date,
                r.party_size,
                r.reserve_status,
                r.reserve_deposit,
                r.created_at,
                res.rest_name,
                p.payment_status,
                p.payment_amount,
                GROUP_CONCAT(t.table_number ORDER BY t.table_number SEPARATOR ', ') as table_numbers
            FROM reservation r
            INNER JOIN restaurant res ON r.restaurant_id = res.restaurant_id
            LEFT JOIN payment p ON r.reserve_id = p.reserve_id
            LEFT JOIN reservation_tables rt ON r.reserve_id = rt.reserve_id
            LEFT JOIN `table` t ON rt.table_id = t.table_id
            WHERE r.user_id = ?
            AND r.restaurant_id = ?
            GROUP BY r.reserve_id, r.reserve_date, r.party_size, r.reserve_status, 
                     r.reserve_deposit, r.created_at, res.rest_name, 
                     p.payment_status, p.payment_amount
            ORDER BY r.created_at DESC";
    
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param("ii", $user_id, $restaurant_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $reservations = [];
    while ($row = $result->fetch_assoc()) {
        $reservations[] = $row;
    }
    $stmt->close();
    
    echo json_encode([
        'success' => true,
        'reservations' => $reservations
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => 'Database error: ' . $e->getMessage()
    ]);
}
?>