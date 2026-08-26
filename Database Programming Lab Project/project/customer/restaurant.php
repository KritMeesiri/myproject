<?php
session_start();
require_once '../connect.php';

// ตรวจสอบว่า login แล้วหรือยัง
if (!isset($_SESSION['user_id'])) {
    header('Location: ../login/login.php');
    exit();
}

// รับ restaurant_id จาก URL
$restaurant_id = isset($_GET['id']) ? (int)$_GET['id'] : 1;

// ดึงข้อมูล restaurant จาก database
$sql = "SELECT * FROM restaurant WHERE restaurant_id = ?";
$stmt = $mysqli->prepare($sql);
$stmt->bind_param("i", $restaurant_id);
$stmt->execute();
$result = $stmt->get_result();
$restaurant = $result->fetch_assoc();

if (!$restaurant) {
    header('Location: venues.php');
    exit();
}

// ดึงข้อมูล policy
$sql_policy = "SELECT * FROM policy WHERE restaurant_id = ?";
$stmt_policy = $mysqli->prepare($sql_policy);
$stmt_policy->bind_param("i", $restaurant_id);
$stmt_policy->execute();
$policy_result = $stmt_policy->get_result();
$policy = $policy_result->fetch_assoc();

$username = $_SESSION['username'] ?? 'Guest';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="css/restaurant.css">
    <link rel="stylesheet" href="css/notification.css">
</head>
<body class="venue-detail-body">
    <!-- Navbar -->
    <div class="venue-top-section">
        <div class="venue-top-logo">
            <img src="css/catlog.png"  alt="cat"style="height:36px;position: relative; top: -18px;left:-70px;object-fit:contain"> <img src="css/logo.png" alt="logo" style="height:36px;position: relative;left:-490px;object-fit:contain"/> 
            
            <div class="top-right-icons">
                <span class="icon-notif" id="notificationBtn" style="cursor: pointer; position: relative;">🔔</span>
                <span class="icon-calendar" onclick="window.location.href='my_bookings.php'" style="cursor: pointer;" title="My Bookings">📅</span>
                <span class="icon-user" onclick="window.location.href='profile.php'" style="cursor: pointer;" title="Profile">
                    <img src="css/druckcat_profile.jpeg" alt="drunkcat" style="height:28px;border-radius:22px; object-fit:contain;">
                </span>
                <a href="../login/logout.php" class="btn-logout-icon">Logout</a>
            </div>
        </div>
    </div>


    <!-- Main Content -->
    <div class="venue-detail-container">
        <!-- Venue Image -->
        <div class="venue-detail-image">
            <?php if ($restaurant_id == 1): ?>
                <img src="https://images.unsplash.com/photo-1514933651103-005eec06c04b?w=800" alt="<?php echo htmlspecialchars($restaurant['rest_name']); ?>">
            <?php else: ?>
                <img src="https://images.unsplash.com/photo-1517248135467-4c7edcad34c4?w=800" alt="<?php echo htmlspecialchars($restaurant['rest_name']); ?>">
            <?php endif; ?>
        </div>

        <!-- Venue Info -->
        <div class="venue-detail-info">
            <div class="venue-title-section">
                <span class="map-icon">🗺️</span>
                <h1 class="venue-title"><?php echo htmlspecialchars($restaurant['rest_name']); ?></h1>
            </div>

            <div class="venue-details-list">
                <div class="detail-line">
                    <span class="detail-label">Location:</span>
                    <span class="detail-value"><?php echo htmlspecialchars($restaurant['rest_address']); ?></span>
                </div>
                <div class="detail-line">
                    <span class="detail-label">Phone:</span>
                    <span class="detail-value"><?php echo htmlspecialchars($restaurant['rest_phone']); ?></span>
                </div>
                <div class="detail-line">
                    <span class="detail-label">Email:</span>
                    <span class="detail-value"><?php echo htmlspecialchars($restaurant['rest_email']); ?></span>
                </div>
                <div class="detail-line">
                    <span class="detail-label">Opening Hours:</span>
                    <span class="detail-value">
                        <?php 
                        echo date('g:i A', strtotime($restaurant['open_time'])) . ' - ' . 
                        date('g:i A', strtotime($restaurant['close_time'])); 
                        ?>
                    </span>
                </div>
                <div class="detail-line">
                    <span class="detail-label">Status:</span>
                    <span class="detail-value <?php echo $restaurant['rest_status'] == 'OPEN' ? 'status-open' : 'status-closed'; ?>">
                        <?php echo $restaurant['rest_status']; ?>
                    </span>
                </div>
                <?php if ($policy): ?>
                <div class="detail-line">
                    <span class="detail-label">Minimum Age:</span>
                    <span class="detail-value"><?php echo $policy['min_age']; ?> years</span>
                </div>
                <div class="detail-line">
                    <span class="detail-label">Claim by:</span>
                    <span class="detail-value"><?php echo date('g:i A', strtotime($policy['claim_by_time'])); ?></span>
                </div>
                <?php endif; ?>
            </div>

            <?php if ($restaurant['rest_status'] == 'OPEN'): ?>
            <button class="btn-next" onclick="window.location.href='booking.php?restaurant_id=<?php echo $restaurant_id; ?>'">
                BOOK NOW ➤
            </button>
            
            <?php else: ?>
            <button class="btn-next" disabled style="background: #999; cursor: not-allowed;">
                CURRENTLY CLOSED
            </button>
            <?php endif; ?>
        </div>
    </div>
    <!-- Back Button  -->
    <div class="back-button-section">
    <a href="venues.php" class="back-link-below">← Back to List</a>
    </div>

    <?php include 'notification_widget.php'; ?>
</body>
</html>