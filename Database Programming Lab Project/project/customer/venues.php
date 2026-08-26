<?php
session_start();
require_once '../connect.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login/login.php');
    exit();
}

// ดึงข้อมูล restaurants ทั้งหมด
$sql = "SELECT r.*, 
        (SELECT COUNT(*) FROM `table` t 
         INNER JOIN area a ON t.area_id = a.area_id 
         WHERE a.restaurant_id = r.restaurant_id AND t.table_status = 'AVAILABLE') as available_tables
        FROM restaurant r 
        ORDER BY r.rest_name";

$result = $mysqli->query($sql);
$restaurants = [];
while ($row = $result->fetch_assoc()) {
    $restaurants[] = $row;
}

$username = $_SESSION['username'] ?? 'Guest';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="css/venues.css">
    <link rel="stylesheet" href="css/notification.css">
</head>
<body class="dashboard-body">
    <nav class="navbar">
        <div class="nav-content">
            <!-- LEFT: logo -->
            <div class="nav-left">
                <img src="css/catlog.png"  alt="cat"style="height:36px;position: relative; top: -18px;left:-40px;object-fit:contain"> <img src="css/logo.png" alt="logo" style="height:36px;position: relative;left:-30px;object-fit:contain"/> 
            </div>
            
            <!-- RIGHT: icons -->
            <div class="nav-right">
                <span class="notification-icon" id="notificationBtn" style="cursor: pointer; position: relative;">🔔</span>
                <span class="calendar-icon" onclick="window.location.href='my_bookings.php'" style="cursor: pointer;" title="My Bookings">📅</span>
                <span class="user-icon" onclick="window.location.href='profile.php'" style="cursor: pointer;" title="Profile">
                    <img src="css/druckcat_profile.jpeg" alt="drunkcat" style="height:28px;border-radius:22px; object-fit:contain;">
                </span>
                <a href="../login/logout.php" class="btn-logout-icon">Logout</a>
            </div>
        </div>
    </nav>

    <!-- Venue List Container -->
    <div class="venue-list-container">
        <div style="text-align: center; margin-bottom: 30px;">
            <h1 style="font-size: 36px; font-weight: 700; color: #333; margin-bottom: 10px;">Browse Venues</h1>
            <p style="font-size: 18px; color: #666;">Select a venue to view details and make a reservation</p>
        </div>

        <!-- Venues List -->
        <!-- Venues List -->
        <?php 
        $icons = ['green-icon', 'purple-icon', 'blue-icon', 'orange-icon'];
        $icon_index = 0;
        foreach ($restaurants as $restaurant): 
            $icon_class = $icons[$icon_index % 4];
            $icon_index++;
        ?>
        <div class="venue-list-item" onclick="window.location.href='restaurant.php?id=<?php echo $restaurant['restaurant_id']; ?>'" style="cursor: pointer;">
            <div class="venue-icon <?php echo $icon_class; ?>">
                🍽️
            </div>
            
            <div class="venue-info">
                <h3 class="venue-name"><?php echo htmlspecialchars($restaurant['rest_name']); ?></h3>
                <p class="venue-distance">
                    📍 <?php echo htmlspecialchars($restaurant['rest_address']); ?> | 
                    🕐 <?php echo date('g:i A', strtotime($restaurant['open_time'])) . ' - ' . date('g:i A', strtotime($restaurant['close_time'])); ?> | 
                    📞 <?php echo htmlspecialchars($restaurant['rest_phone']); ?>
                    <?php if ($restaurant['rest_status'] == 'OPEN'): ?>
                        <span style="color: #4CAF50; font-weight: bold;"> • OPEN</span>
                    <?php else: ?>
                        <span style="color: #F44336; font-weight: bold;"> • CLOSED</span>
                    <?php endif; ?>
                </p>
                
            </div>
            
            <button class="venue-arrow">
                →
            </button>
        </div>
        <?php endforeach; ?>
        </div>

        <?php if (empty($restaurants)): ?>
        <div style="background: white; padding: 60px 40px; border-radius: 20px; text-align: center; box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);">
            <p style="font-size: 24px; color: #666;">No venues available at the moment</p>
        </div>
        <?php endif; ?>
    </div>

    <!-- Back Button -->
    <div class="back-button-container">
        <button class="btn-back" onclick="window.location.href='dashboard.php'">
            ← Back to Dashboard
        </button>
    </div>

    <?php include 'notification_widget.php'; ?>
</body>
</html>