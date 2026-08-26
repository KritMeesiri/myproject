<?php
session_start();
require_once '../connect.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login/login.php');
    exit();
}

$user_id = $_SESSION['user_id'];

// ดึงข้อมูลการจอง - Schema ใหม่
$sql = "SELECT 
        r.reserve_id,
        r.reserve_date,
        r.party_size,
        r.reserve_status,
        r.reserve_deposit,
        r.created_at,
        res.rest_name,
        GROUP_CONCAT(DISTINCT t.table_number ORDER BY t.table_number SEPARATOR ', ') as table_numbers,
        GROUP_CONCAT(DISTINCT a.area_name SEPARATOR ', ') as area_names,
        COUNT(DISTINCT rt.table_id) as total_tables_in_booking,
        p.payment_status
        FROM reservation r
        INNER JOIN restaurant res ON r.restaurant_id = res.restaurant_id
        LEFT JOIN reservation_tables rt ON r.reserve_id = rt.reserve_id
        LEFT JOIN `table` t ON rt.table_id = t.table_id
        LEFT JOIN area a ON t.area_id = a.area_id
        LEFT JOIN payment p ON r.reserve_id = p.reserve_id
        WHERE r.user_id = ?
        GROUP BY r.reserve_id, r.reserve_date, r.party_size, r.reserve_status, 
                 r.reserve_deposit, r.created_at, res.rest_name, p.payment_status
        ORDER BY r.created_at DESC";

$stmt = $mysqli->prepare($sql);
if (!$stmt) {
    die("SQL Prepare Error: " . $mysqli->error);
}

$stmt->bind_param("i", $user_id);
if (!$stmt->execute()) {
    die("SQL Execute Error: " . $stmt->error);
}

$result = $stmt->get_result();

$bookings = [];
while ($row = $result->fetch_assoc()) {
    $bookings[] = $row;
}

// แยกการจองตามสถานะ - HOLD และ CONFIRMED อยู่ Active
$active_bookings = array_filter($bookings, function($b) {
    return in_array($b['reserve_status'], ['HOLD', 'CONFIRMED']);
});

// Past = เฉพาะ CANCELLED
$past_bookings = array_filter($bookings, function($b) {
    return $b['reserve_status'] == 'CANCELLED';
});
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="css/my_bookings.css">
    <link rel="stylesheet" href="css/notification.css">
    <style>
        .tab-content { display: none; }
        .tab-content.active { display: block; }
        .tab-btn {
            flex: 1;
            padding: 15px;
            border-radius: 50px;
            font-size: 16px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        .tab-btn.active {
            background: linear-gradient(135deg, #6496e8 0%, #9c27b0 100%) !important;
            color: white !important;
            border: none !important;
        }
        .tab-btn:not(.active) {
            background: #e0e0e0 !important;
            color: #666 !important;
            border: 2px solid #e0e0e0 !important;
        }
        .tab-btn:not(.active):hover {
            background: #d0d0d0 !important;
            color: #333 !important;
        }
        .status-confirmed {
            color: #2ecc71;
            font-weight: 700;
            font-size: 14px;
        }
        .status-cancelled {
            color: #999;
            font-weight: 600;
            font-size: 14px;
        }
    </style>
</head>
<body class="my-bookings-body">
    <!-- Navbar -->
    <div class="navbar">
        <div class="nav-content">
            <div class="nav-left">
                <img src="css/catlog.png"  alt="cat"style="height:36px;position: relative; top: -18px;left:-70px;object-fit:contain"> <img src="css/logo.png" alt="logo" style="height:36px;position: relative;left:-70px;object-fit:contain"/> 
            </div>
            <div class="nav-right">
                <span class="notification-icon" id="notificationBtn" style="cursor: pointer; position: relative;">🔔</span>
                <span class="calendar-icon" style="cursor: pointer; color: #6496e8;" title="My Bookings">📅</span>
                <span class="user-icon" onclick="window.location.href='profile.php'" style="cursor: pointer;" title="Profile"><img src="css/druckcat_profile.jpeg" alt="drunkcat" style="height:28px;border-radius:22px; object-fit:contain;"></span>
                <a href="../login/logout.php" class="btn-logout-icon">Logout</a>
            </div>
        </div>
    </div>

    <!-- Back Button Below Navbar -->
    <div class="back-button-below-nav">
        <button class="btn-back-below-nav" onclick="window.location.href='dashboard.php'">
            ← Back
        </button>
    </div>

    <!-- Main Container -->
    <div class="my-bookings-container-full">
        <div class="bookings-title-section">
            <div>
                <h1 class="bookings-page-title">My Bookings</h1>
                <p class="bookings-page-subtitle">Manage your reservations</p>
            </div>
        </div>

        <!-- Tab Navigation -->
        <div style="display: flex; gap: 15px; margin-bottom: 30px;">
            <button class="tab-btn active" data-tab="active">
                Active (<?php echo count($active_bookings); ?>)
            </button>
            <button class="tab-btn" data-tab="past">
                Past (<?php echo count($past_bookings); ?>)
            </button>
        </div>

        <!-- Active Bookings (HOLD และ CONFIRMED) -->
        <div class="tab-content active" id="activeTab">
            <?php if (empty($active_bookings)): ?>
                <div class="empty-bookings">
                    <div class="empty-icon">📅</div>
                    <h3>No Active Bookings</h3>
                    <p>You don't have any active bookings</p>
                    <button class="btn-browse-venues" onclick="window.location.href='venues.php'">Browse Venues</button>
                </div>
            <?php else: ?>
                <div class="bookings-list-full">
                    <?php foreach ($active_bookings as $booking): ?>
                        <div class="booking-card-full" onclick="window.location.href='booking_detail.php?id=<?php echo $booking['reserve_id']; ?>'">
                            <div class="booking-card-left">
                                <div class="booking-icon">🍽️</div>
                                <div class="booking-info">
                                    <h3 class="booking-venue-name"><?php echo htmlspecialchars($booking['rest_name']); ?></h3>
                                    <p class="booking-date">
                                        <?php echo date('D, M j, Y', strtotime($booking['reserve_date'])); ?> • <?php echo $booking['party_size']; ?> people
                                        <?php if ($booking['total_tables_in_booking'] > 1): ?>
                                            <span style="color: #2ecc71; font-weight: bold; margin-left: 5px;">
                                                (<?php echo $booking['total_tables_in_booking']; ?> tables)
                                            </span>
                                        <?php endif; ?>
                                    </p>
                                    <?php if ($booking['total_tables_in_booking'] > 1 && $booking['table_numbers']): ?>
                                        <p style="font-size: 12px; color: #999; margin: 3px 0;">
                                            Tables: <?php echo htmlspecialchars($booking['table_numbers']); ?>
                                        </p>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="booking-card-right">
                                <?php if ($booking['reserve_status'] == 'CONFIRMED'): ?>
                                    <span class="booking-time status-confirmed">CONFIRMED</span>
                                <?php else: ?>
                                    <span class="booking-time"><?php echo number_format($booking['reserve_deposit'], 0); ?> ฿</span>
                                <?php endif; ?>
                                <span class="booking-arrow">→</span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Past Bookings (เฉพาะ CANCELLED) -->
        <div class="tab-content" id="pastTab">
            <?php if (empty($past_bookings)): ?>
                <div class="empty-bookings">
                    <div class="empty-icon">📋</div>
                    <h3>No Past Bookings</h3>
                    <p>You don't have any past bookings</p>
                </div>
            <?php else: ?>
                <div class="bookings-list-full">
                    <?php foreach ($past_bookings as $booking): ?>
                        <div class="booking-card-full" onclick="window.location.href='booking_detail.php?id=<?php echo $booking['reserve_id']; ?>'">
                            <div class="booking-card-left">
                                <div class="booking-icon" style="background: linear-gradient(135deg, #f3e8ff 0%, #e8d5ff 100%); border-color: #9c27b0;">🍽️</div>
                                <div class="booking-info">
                                    <h3 class="booking-venue-name"><?php echo htmlspecialchars($booking['rest_name']); ?></h3>
                                    <p class="booking-date">
                                        <?php echo date('D, M j, Y', strtotime($booking['reserve_date'])); ?> • <?php echo $booking['party_size']; ?> people
                                        <?php if ($booking['total_tables_in_booking'] > 1): ?>
                                            <span style="color: #999; font-weight: bold; margin-left: 5px;">
                                                (<?php echo $booking['total_tables_in_booking']; ?> tables)
                                            </span>
                                        <?php endif; ?>
                                    </p>
                                    <?php if ($booking['total_tables_in_booking'] > 1 && $booking['table_numbers']): ?>
                                        <p style="font-size: 12px; color: #999; margin: 3px 0;">
                                            Tables: <?php echo htmlspecialchars($booking['table_numbers']); ?>
                                        </p>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="booking-card-right">
                                <span class="booking-time status-cancelled">CANCELLED</span>
                                <span class="booking-arrow">→</span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        const tabBtns = document.querySelectorAll('.tab-btn');
        const tabContents = document.querySelectorAll('.tab-content');

        tabBtns.forEach(btn => {
            btn.addEventListener('click', function() {
                tabBtns.forEach(b => b.classList.remove('active'));
                tabContents.forEach(c => c.classList.remove('active'));
                
                this.classList.add('active');
                document.getElementById(this.dataset.tab + 'Tab').classList.add('active');
            });
        });
    </script>

    <?php include 'notification_widget.php'; ?>
</body>
</html>