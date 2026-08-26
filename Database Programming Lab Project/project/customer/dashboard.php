<?php
session_start();
require_once('../connect.php');

// ตรวจสอบว่า login แล้วหรือยัง
if (!isset($_SESSION['user_id'])) {
    header('Location: ../login/login.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'] ?? 'Guest';
$login_time = $_SESSION['login_time'] ?? 'Unknown';

// ดึงข้อมูลร้านอาหารแนะนำ (Must Try) - เพิ่ม rest_description
$sql = "SELECT 
            r.restaurant_id,
            r.rest_name,
            r.rest_description,
            r.rest_address,
            r.rest_phone,
            COUNT(DISTINCT res.reserve_id) as total_bookings
        FROM restaurant r
        LEFT JOIN reservation res ON r.restaurant_id = res.restaurant_id
        WHERE r.rest_status = 'OPEN'
        GROUP BY r.restaurant_id
        ORDER BY total_bookings DESC
        LIMIT 2";

$result = $mysqli->query($sql);
$featured_restaurants = [];
while ($row = $result->fetch_assoc()) {
    $featured_restaurants[] = $row;
}

// ถ้าไม่มีข้อมูล ให้แสดงข้อมูลเริ่มต้น
if (empty($featured_restaurants)) {
    $featured_restaurants = [
        [
            'restaurant_id' => 1,
            'rest_name' => 'The Chill Bar & Restaurant',
            'rest_description' => 'Experience the perfect blend of music and mixology at The Chill Bar. Our venue features live DJ performances, craft cocktails, and a vibrant atmosphere perfect for night owls.',
            'rest_address' => '123 Sukhumvit Rd, Bangkok',
            'total_bookings' => 0
        ],
        [
            'restaurant_id' => 2,
            'rest_name' => 'Brewly Lounge & Dining',
            'rest_description' => 'Brewly Lounge & Dining offers an upscale dining experience with our signature craft beers and gourmet cuisine. Our elegant Main Hall and exclusive Private Room provide the perfect setting.',
            'rest_address' => '456 Silom Rd, Bangkok',
            'total_bookings' => 0
        ]
    ];
}

// ดึงจำนวน booking ของ user
$count_sql = "SELECT COUNT(*) as user_bookings FROM reservation WHERE user_id = ?";
$count_stmt = $mysqli->prepare($count_sql);
$count_stmt->bind_param("i", $user_id);
$count_stmt->execute();
$count_result = $count_stmt->get_result();
$user_stats = $count_result->fetch_assoc();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="css/dashboard.css">
    <link rel="stylesheet" href="css/notification.css">
</head>
<body 

class="dashboard-body">
    <nav class="navbar">

        <div class="nav-content">
            <div class="nav-left">
            <img src="css/catlog.png"  alt="cat"style="height:36px;position: relative; top: -18px;left:-40px;object-fit:contain"> <img src="css/logo.png" alt="logo" style="height:36px;position: relative;left:-30px;object-fit:contain"/> 
            </div>
            <div class="nav-right">
                <span class="notification-icon" id="notificationBtn" style="cursor: pointer; position: relative;">🔔</span>
                <span class="calendar-icon" onclick="window.location.href='my_bookings.php'" style="cursor: pointer;" title="My Bookings">📅</span>
                <span class="user-icon active" onclick="window.location.href='profile.php'" style="cursor: pointer;" title="Profile"><img src="css/druckcat_profile.jpeg" alt="drunkcat" style="height:28px;border-radius:22px; object-fit:contain;"></span>
                <a href="../login/logout.php" class="btn-logout-icon">Logout</a>
            </div>
        </div>
    </nav>

    <div class="dashboard-main">
        <div class="must-try-section">
            <div class="info-card">
                <img src="css/drunkcat.jpeg" alt="drunkcat" style="height:180px; object-fit:contain;">
                <h3> Welcome, <?php echo htmlspecialchars($username); ?>!</h3>
                <p style="font-size: 14px; color: #666; margin-top: 5px;">
                    📊 You have <?php echo $user_stats['user_bookings']; ?> total booking.<?php echo $user_stats['user_bookings'] != 1 ? 's' : ''; ?>
                </p>
            </div>

            <h2 class="section-title">MUST TRY</h2>
            
            <div class="venue-grid">
                <?php foreach ($featured_restaurants as $restaurant): ?>
                <div class="venue-card">
                    <div class="venue-image">
                        <img src="https://images.unsplash.com/photo-<?php echo $restaurant['restaurant_id'] == 1 ? '1514933651103-005eec06c04b' : '1555396273-367ea4eb4db5'; ?>?w=500" alt="<?php echo htmlspecialchars($restaurant['rest_name']); ?>">
                    </div>
                    <div class="venue-description">
                        <h2 class="venue-title-card"><?php echo htmlspecialchars($restaurant['rest_name']); ?></h2>
                        <br>
                        <h3>DESCRIPTION</h3>
                        <p>
                            <?php 
                            // แสดง rest_description ถ้ามี ถ้าไม่มีให้แสดง address
                            if (!empty($restaurant['rest_description'])) {
                                echo htmlspecialchars($restaurant['rest_description']);
                            } else {
                                echo htmlspecialchars($restaurant['rest_address']);
                            }
                            ?>
                        </p>
                        <p style="color: #2ecc71; font-size: 13px; margin-top: 10px;">
                            📍 <?php echo htmlspecialchars($restaurant['rest_address']); ?>
                        </p>
                        <p style="color: #f39c12; font-size: 13px; margin-top: 5px;">
                            ⭐ <?php echo $restaurant['total_bookings']; ?> booking<?php echo $restaurant['total_bookings'] != 1 ? 's' : ''; ?>
                        </p>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <div class="more-details-section">
                <button class="btn-more-details" onclick="window.location.href='venues.php'">
                    MORE DETAILS →
                </button>
            </div>
        </div>
    </div>

    <?php include 'notification_widget.php'; ?>
</body>
</html>