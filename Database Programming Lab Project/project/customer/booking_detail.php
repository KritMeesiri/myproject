<?php
session_start();
require_once '../connect.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login/login.php');
    exit();
}

$reserve_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$user_id = $_SESSION['user_id'];

// ดึงข้อมูลการจอง - แยก query เพื่อหลีกเลี่ยง GROUP BY issue
$sql = "SELECT r.*, 
        res.rest_name, res.rest_address, res.rest_phone,
        p.payment_status, p.payment_method, p.paid_at,
        u.firstname, u.lastname, u.email, u.user_phone
        FROM reservation r
        INNER JOIN restaurant res ON r.restaurant_id = res.restaurant_id
        LEFT JOIN payment p ON r.reserve_id = p.reserve_id
        INNER JOIN user u ON r.user_id = u.user_id
        WHERE r.reserve_id = ? AND r.user_id = ?";

$stmt = $mysqli->prepare($sql);
if (!$stmt) {
    die("SQL Prepare Error: " . $mysqli->error);
}

$stmt->bind_param("ii", $reserve_id, $user_id);
if (!$stmt->execute()) {
    die("SQL Execute Error: " . $stmt->error);
}

$result = $stmt->get_result();
$booking = $result->fetch_assoc();

if (!$booking) {
    header('Location: my_bookings.php');
    exit();
}

// ดึงข้อมูลโต๊ะแยกต่างหาก
$sql_tables = "SELECT 
               t.table_number, t.capacity,
               a.area_name
               FROM reservation_tables rt
               INNER JOIN `table` t ON rt.table_id = t.table_id
               INNER JOIN area a ON t.area_id = a.area_id
               WHERE rt.reserve_id = ?
               ORDER BY t.table_number";

$stmt_tables = $mysqli->prepare($sql_tables);
if ($stmt_tables) {
    $stmt_tables->bind_param("i", $reserve_id);
    $stmt_tables->execute();
    $tables_result = $stmt_tables->get_result();
    
    $tables = [];
    $areas = [];
    $total_capacity = 0;
    
    while ($table = $tables_result->fetch_assoc()) {
        $tables[] = $table['table_number'];
        if (!in_array($table['area_name'], $areas)) {
            $areas[] = $table['area_name'];
        }
        $total_capacity += $table['capacity'];
    }
    
    $booking['table_numbers'] = !empty($tables) ? implode(', ', $tables) : 'N/A';
    $booking['area_names'] = !empty($areas) ? implode(', ', $areas) : 'N/A';
    $booking['total_capacity'] = $total_capacity;
} else {
    $booking['table_numbers'] = 'N/A';
    $booking['area_names'] = 'N/A';
    $booking['total_capacity'] = 0;
}

$reserve_date_formatted = date('D, M j, Y', strtotime($booking['reserve_date']));
$created_at_formatted = date('M j, Y g:i A', strtotime($booking['created_at']));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="css/booking_detail.css">
    <link rel="stylesheet" href="css/notification.css">
</head>
<body class="booking-detail-body">
    <!-- Navbar -->
    <div class="navbar">
        <div class="nav-content">
            <div class="nav-left">
                <img src="css/catlog.png"  alt="cat"style="height:36px;position: relative; top: -18px;left:-70px;object-fit:contain"> <img src="css/logo.png" alt="logo" style="height:36px;position: relative;left:-60px;object-fit:contain"/> 
            </div>
            <div class="nav-right">
                <span class="notification-icon" id="notificationBtn" style="cursor: pointer; position: relative;">🔔</span>
                <span class="calendar-icon" onclick="window.location.href='my_bookings.php'" style="cursor: pointer;" title="My Bookings">📅</span>
                <span class="user-icon" onclick="window.location.href='profile.php'" style="cursor: pointer;" title="Profile"><img src="css/druckcat_profile.jpeg" alt="drunkcat" style="height:28px;border-radius:22px; object-fit:contain;"></span>
                <a href="../login/logout.php" class="btn-logout-icon">Logout</a>
            </div>
        </div>
    </div>

    <!-- Main Container -->
    <div class="booking-detail-container">
        <!-- Status Badge -->
        <span class="status-badge status-<?php echo strtolower($booking['reserve_status']); ?>">
            <?php echo $booking['reserve_status']; ?>
        </span>

        <!-- Venue Section -->
        <div class="detail-section venue-section">
            <div class="venue-icon-large">🍽️</div>
            <h2 class="venue-name-large"><?php echo htmlspecialchars($booking['rest_name']); ?></h2>
            <p class="booking-id-text">Booking #<?php echo str_pad($booking['reserve_id'], 6, '0', STR_PAD_LEFT); ?></p>
        </div>

        <!-- Date & Time Section -->
        <div class="detail-section datetime-section">
            <div class="datetime-card">
                <span class="datetime-icon">📅</span>
                <div class="datetime-info">
                    <span class="datetime-label">Date</span>
                    <span class="datetime-value"><?php echo $reserve_date_formatted; ?></span>
                </div>
            </div>
            <div class="datetime-card">
                <span class="datetime-icon">🕐</span>
                <div class="datetime-info">
                    <span class="datetime-label">Booked At</span>
                    <span class="datetime-value"><?php echo $created_at_formatted; ?></span>
                </div>
            </div>
        </div>

        <!-- Booking Info -->
        <div class="detail-section info-section">
            <h3 class="section-title">Booking Information</h3>
            <div class="info-grid">
                <div class="info-item">
                    <span class="info-label">Area(s)</span>
                    <span class="info-value"><?php echo htmlspecialchars($booking['area_names']); ?></span>
                </div>
                <div class="info-item">
                    <span class="info-label">Table Number(s)</span>
                    <span class="info-value"><?php echo htmlspecialchars($booking['table_numbers']); ?></span>
                </div>
                <div class="info-item">
                    <span class="info-label">Party Size</span>
                    <span class="info-value"><?php echo $booking['party_size']; ?> people</span>
                </div>
                <div class="info-item">
                    <span class="info-label">Payment Status</span>
                    <span class="info-value"><?php echo $booking['payment_status'] ?? 'Pending'; ?></span>
                </div>
            </div>
        </div>

        <!-- Restaurant Info -->
        <div class="detail-section info-section">
            <h3 class="section-title">Restaurant Information</h3>
            <div class="info-grid">
                <div class="info-item">
                    <span class="info-label">Address</span>
                    <span class="info-value"><?php echo htmlspecialchars($booking['rest_address']); ?></span>
                </div>
                <div class="info-item">
                    <span class="info-label">Phone</span>
                    <span class="info-value"><?php echo htmlspecialchars($booking['rest_phone']); ?></span>
                </div>
            </div>
        </div>

        <!-- Price Section -->
        <div class="detail-section price-section">
            <div class="price-row">
                <span class="price-label">Total Price:</span>
                <span class="price-value"><?php echo number_format($booking['reserve_deposit'], 0); ?> ฿</span>
            </div>
        </div>

        <!-- Action Buttons - แสดงเฉพาะ HOLD เท่านั้น -->
        <?php if ($booking['reserve_status'] == 'HOLD'): ?>
        <div class="action-buttons">
            <button class="btn-detail-action btn-cancel" onclick="confirmCancel(<?php echo $reserve_id; ?>)">
                Cancel Booking
            </button>
        </div>
        <?php endif; ?>

        <!-- แสดงข้อความสำหรับ CONFIRMED -->
        <?php if ($booking['reserve_status'] == 'CONFIRMED'): ?>
        <div class="detail-section" style="text-align: center; padding: 20px; background: #f0f9ff; border-radius: 8px; margin-top: 20px;">
            <p style="color: #0369a1; font-weight: 500; margin: 0;">
                ✓ This booking has been confirmed and checked-in. No further actions available.
            </p>
        </div>
        <?php endif; ?>

        <!-- แสดงข้อความสำหรับ CANCELLED -->
        <?php if ($booking['reserve_status'] == 'CANCELLED'): ?>
        <div class="detail-section" style="text-align: center; padding: 20px; background: #fef2f2; border-radius: 8px; margin-top: 20px;">
            <p style="color: #dc2626; font-weight: 500; margin: 0;">
                ✗ This booking has been cancelled.
            </p>
        </div>
        <?php endif; ?>

        <!-- Back Button -->
        <div class="back-button-below-nav">
            <button class="btn-back-below-nav" onclick="window.location.href='my_bookings.php'">
                ← Back to My Bookings
            </button>
        </div>
    </div>

    <script>
        function confirmCancel(reserveId) {
            if (confirm('Are you sure you want to cancel this booking?')) {
                fetch('booking_cancel.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'reserve_id=' + reserveId
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert('Booking cancelled successfully');
                        location.reload();
                    } else {
                        alert('Failed to cancel: ' + data.message);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('An error occurred. Please try again.');
                });
            }
        }
    </script>
    <?php include 'notification_widget.php'; ?>
</body>
</html>