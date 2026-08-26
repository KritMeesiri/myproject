<?php
session_start();
require_once '../connect.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login/login.php');
    exit();
}

$restaurant_id = isset($_GET['restaurant_id']) ? (int)$_GET['restaurant_id'] : 1;

// ✅ จองได้เฉพาะวันนี้เท่านั้น - ไม่รับ date parameter
$reserve_date = date('Y-m-d');

$party_size = isset($_GET['party_size']) ? (int)$_GET['party_size'] : 0;

// ดึงข้อมูล restaurant
$sql_restaurant = "SELECT * FROM restaurant WHERE restaurant_id = ?";
$stmt_restaurant = $mysqli->prepare($sql_restaurant);
$stmt_restaurant->bind_param("i", $restaurant_id);
$stmt_restaurant->execute();
$restaurant = $stmt_restaurant->get_result()->fetch_assoc();

if (!$restaurant) {
    header('Location: venues.php');
    exit();
}

// ดึงข้อมูล areas และ tables พร้อมเช็คสถานะการจอง
$sql_areas = "SELECT a.* 
              FROM area a 
              WHERE a.restaurant_id = ? 
              ORDER BY 
                CASE 
                    WHEN a.area_type = 'INFRONT' THEN 1
                    WHEN a.area_type = 'BACKSIDE' THEN 2
                    ELSE 3
                END,
                a.area_price DESC";
$stmt_areas = $mysqli->prepare($sql_areas);
$stmt_areas->bind_param("i", $restaurant_id);
$stmt_areas->execute();
$areas = $stmt_areas->get_result();

// จัดกลุ่ม tables ตาม area และเช็คสถานะการจอง
$tables_by_area = [];
while ($area = $areas->fetch_assoc()) {
    $area_id = $area['area_id'];
    
    // Query ที่เช็คว่าโต๊ะถูกจองในวันที่เลือกหรือไม่
    $sql_tables = "SELECT 
                    t.*,
                    CASE 
                        WHEN EXISTS (
                            SELECT 1 
                            FROM reservation_tables rt
                            INNER JOIN reservation r ON rt.reserve_id = r.reserve_id
                            WHERE rt.table_id = t.table_id
                            AND r.reserve_date = ?
                            AND r.reserve_status IN ('HOLD', 'CONFIRMED')
                        ) THEN 'BOOKED'
                        WHEN t.table_status = 'UNAVAILABLE' THEN 'UNAVAILABLE'
                        ELSE 'AVAILABLE'
                    END as display_status
                FROM `table` t 
                WHERE t.area_id = ? 
                ORDER BY 
                    LENGTH(t.table_number),
                    t.table_number";
    
    $stmt_tables = $mysqli->prepare($sql_tables);
    $stmt_tables->bind_param("si", $reserve_date, $area_id);
    $stmt_tables->execute();
    $tables_result = $stmt_tables->get_result();
    
    $area['tables'] = [];
    while ($table = $tables_result->fetch_assoc()) {
        $area['tables'][] = $table;
    }
    
    // เก็บ area ที่มี tables (ไม่ว่าจะจองแล้วหรือไม่)
    if (count($area['tables']) > 0) {
        $tables_by_area[] = $area;
    }
}

$username = $_SESSION['username'] ?? 'Guest';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Book Seat - <?php echo htmlspecialchars($restaurant['rest_name']); ?></title>
    <link rel="stylesheet" href="css/booking.css">
    <link rel="stylesheet" href="css/notification.css">
</head>
<body class="booking-body booking-theme-1">
    <!-- Navbar -->
    <div class="booking-header">
        <div class="booking-logo">
            <img src="css/catlog.png"  alt="cat"style="height:36px;position: relative; top: -18px;left:-70px;object-fit:contain"> <img src="css/logo.png" alt="logo" style="height:36px;position: relative;left:-490px;object-fit:contain"/> 
            
            <div class="top-icons-booking">
                <span class="icon-small" id="notificationBtn">🔔</span>
                <span class="icon-small" onclick="window.location.href='my_bookings.php'" title="My Bookings">📅</span>
                <span class="icon-small" onclick="window.location.href='profile.php'" title="Profile">
                    <img src="css/druckcat_profile.jpeg" alt="drunkcat" class="profile-img">
                </span>
                <a href="../login/logout.php" class="btn-logout-icon">Logout</a>
            </div>
        </div>
    </div>

    <!-- Main Booking Container -->
    <div class="booking-container">
        <h1 class="booking-title">Select Your Tables</h1>
        <p class="venue-name-booking"><?php echo htmlspecialchars($restaurant['rest_name']); ?></p>

        <?php if (empty($tables_by_area)): ?>
        <div class="no-tables-message">
            <p>😔 Sorry, no tables are currently available.</p>
            <button class="btn-back-booking" onclick="window.location.href='restaurant.php?id=<?php echo $restaurant_id; ?>'">
                ← Back
            </button>
        </div>
        <?php else: ?>

        <!-- Venue Layout with Stage -->
        <div class="venue-layout">
            <!-- Stage -->
            <div class="stage">
                <div class="stage-icon">🎭</div>
                <div class="stage-text">STAGE</div>
            </div>

            <!-- Tables arranged around stage -->
            <div class="venue-tables">
                <?php foreach ($tables_by_area as $area): ?>
                    <?php foreach ($area['tables'] as $table): ?>
                    <?php 
                        $is_unavailable = ($table['display_status'] == 'BOOKED' || $table['display_status'] == 'UNAVAILABLE');
                        $status_text = $table['display_status'] == 'BOOKED' ? 'BOOKED' : ($table['display_status'] == 'UNAVAILABLE' ? 'UNAVAILABLE' : '');
                    ?>
                    <button class="table-seat area-<?php echo strtolower($area['area_type']); ?> <?php echo $is_unavailable ? 'booked' : ''; ?>" 
                            data-table-id="<?php echo $table['table_id']; ?>"
                            data-area-id="<?php echo $area['area_id']; ?>"
                            data-zone="<?php echo htmlspecialchars($area['area_name']); ?>" 
                            data-area-type="<?php echo $area['area_type']; ?>"
                            data-price="<?php echo $area['area_price']; ?>"
                            data-capacity="<?php echo $table['capacity']; ?>"
                            data-status="<?php echo $table['display_status']; ?>"
                            title="<?php echo htmlspecialchars($area['area_name']); ?> - Table <?php echo htmlspecialchars($table['table_number']); ?> (<?php echo $table['capacity']; ?> seats) <?php echo $status_text ? '- ' . $status_text : ''; ?>"
                            <?php echo $is_unavailable ? 'disabled' : ''; ?>>
                        <div class="table-number"><?php echo htmlspecialchars($table['table_number']); ?></div>
                        <div class="table-capacity">(<?php echo $table['capacity']; ?>)</div>
                    </button>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Legend -->
        <div class="legend">
            <div class="legend-title">Area Legend:</div>
            <?php foreach ($tables_by_area as $area): ?>
            <div class="legend-item">
                <span class="legend-color area-<?php echo strtolower($area['area_type']); ?>"></span>
                <span><?php echo htmlspecialchars($area['area_name']); ?> (<?php echo $area['area_type']; ?>) - <?php echo number_format($area['area_price']); ?>฿/table</span>
            </div>
            <?php endforeach; ?>
            <div class="legend-item">
                <span class="legend-color booked-color"></span>
                <span>Booked / Unavailable</span>
            </div>
        </div>

        <!-- Selected Info -->
        <div class="selected-info">
            <p>Selected Tables: <span id="selectedTables">None</span></p>
            <p>Number of Tables: <span id="tableCount">0</span></p>
            <p>Total Capacity: <span id="totalCapacity">0</span> people</p>
            <p>Total Price: <span id="totalPrice">0</span> THB</p>
        </div>

        <!-- Confirm Button -->
        <button class="btn-confirm-booking" id="confirmBtn" disabled>
            Select Party Size
        </button>

        <!-- Back Button -->
        <button class="btn-back-booking" onclick="window.location.href='restaurant.php?id=<?php echo $restaurant_id; ?>'">
            ← Back
        </button>

        <?php endif; ?>
    </div>

    <script>
        const seats = document.querySelectorAll('.table-seat:not(.booked)');
        const selectedTablesSpan = document.getElementById('selectedTables');
        const tableCountSpan = document.getElementById('tableCount');
        const totalCapacitySpan = document.getElementById('totalCapacity');
        const totalPriceSpan = document.getElementById('totalPrice');
        const confirmBtn = document.getElementById('confirmBtn');
        let selectedTables = [];

        seats.forEach(seat => {
            seat.addEventListener('click', function() {
                const status = this.dataset.status;
                if (status === 'BOOKED' || status === 'UNAVAILABLE' || this.disabled) {
                    return;
                }

                const tableId = this.dataset.tableId;
                const areaId = this.dataset.areaId;
                const zone = this.dataset.zone;
                const price = parseFloat(this.dataset.price);
                const capacity = parseInt(this.dataset.capacity);
                const tableNumber = this.querySelector('.table-number').textContent;
                
                if (this.classList.contains('selected')) {
                    this.classList.remove('selected');
                    selectedTables = selectedTables.filter(t => t.tableId !== tableId);
                } else {
                    this.classList.add('selected');
                    selectedTables.push({ 
                        tableId, areaId, tableNumber, zone, price, capacity
                    });
                }
                
                updateInfo();
            });
        });

        function updateInfo() {
            if (selectedTables.length === 0) {
                selectedTablesSpan.textContent = 'None';
                tableCountSpan.textContent = '0';
                totalCapacitySpan.textContent = '0';
                totalPriceSpan.textContent = '0';
                confirmBtn.disabled = true;
            } else {
                selectedTablesSpan.textContent = selectedTables.map(t => t.tableNumber).join(', ');
                tableCountSpan.textContent = selectedTables.length;
                totalCapacitySpan.textContent = selectedTables.reduce((sum, t) => sum + t.capacity, 0);
                totalPriceSpan.textContent = selectedTables.reduce((sum, t) => sum + t.price, 0).toLocaleString();
                confirmBtn.disabled = false;
            }
        }

        confirmBtn.addEventListener('click', function() {
            if (selectedTables.length > 0) {
                const tableIds = selectedTables.map(t => t.tableId).join(',');
                const zones = selectedTables.map(t => t.zone).join(', ');
                const total = selectedTables.reduce((sum, t) => sum + t.price, 0);
                const maxCapacity = selectedTables.reduce((sum, t) => sum + t.capacity, 0);
                
                window.location.href = `party_size.php?restaurant_id=<?php echo $restaurant_id; ?>&table_ids=${tableIds}&tables=${selectedTables.length}&zones=${encodeURIComponent(zones)}&price=${total}&max_capacity=${maxCapacity}`;
            }
        });
    </script>

    <?php include 'notification_widget.php'; ?>
</body>
</html>