<?php
session_start();
require_once '../connect.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login/login.php');
    exit();
}

// ✅ รับข้อมูลจาก booking พร้อม validation
$restaurant_id = isset($_GET['restaurant_id']) ? (int)$_GET['restaurant_id'] : 0;
$table_ids = isset($_GET['table_ids']) ? trim($_GET['table_ids']) : '';
$tables_count = isset($_GET['tables']) ? (int)$_GET['tables'] : 0;
$zones = isset($_GET['zones']) ? $_GET['zones'] : 'N/A';
$total_price = isset($_GET['price']) ? (float)$_GET['price'] : 0;
$max_capacity = isset($_GET['max_capacity']) ? (int)$_GET['max_capacity'] : 10;

// ✅ Validate inputs
if ($restaurant_id <= 0 || $tables_count <= 0 || empty($table_ids)) {
    header('Location: venues.php');
    exit();
}

// ✅ จองได้เฉพาะวันนี้เท่านั้น
$reserve_date = date('Y-m-d');

// ดึงข้อมูล restaurant
$sql_restaurant = "SELECT * FROM restaurant WHERE restaurant_id = ?";
$stmt_restaurant = $mysqli->prepare($sql_restaurant);
$stmt_restaurant->bind_param("i", $restaurant_id);
$stmt_restaurant->execute();
$restaurant = $stmt_restaurant->get_result()->fetch_assoc();

$venue_name = $restaurant ? $restaurant['rest_name'] : 'Restaurant';

// คำนวณ party size range ตามจำนวน table
// สูตร: 1 table = 1-4 คน, 2 tables = 5-8 คน, 3 tables = 9-12 คน
$min_party_size = ($tables_count - 1) * 4 + 1; // ถ้า 1 table = (1-1)*4+1 = 1, ถ้า 2 tables = (2-1)*4+1 = 5
$max_party_size = $tables_count * 4; // ถ้า 1 table = 1*4 = 4, ถ้า 2 tables = 2*4 = 8

// ป้องกันเกิน max_capacity ของโต๊ะที่เลือก
if ($max_party_size > $max_capacity) {
    $max_party_size = $max_capacity;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Select Party Size - <?php echo htmlspecialchars($venue_name); ?></title>
    <link rel="stylesheet" href="css/party_size.css">
    <link rel="stylesheet" href="css/notification.css">
</head>
<body class="party-size-body">
    <!-- Navbar -->
    <div class="party-size-header">
        <div class="party-size-logo">
            <img src="css/catlog.png"  alt="cat"style="height:36px;position: relative; top: -18px;left:-40px;object-fit:contain"> <img src="css/logo.png" alt="logo" style="height:36px;position: relative;left:-490px;object-fit:contain"/> 
            <div class="top-icons-party">
                <span class="icon-small" id="notificationBtn" style="cursor: pointer; position: relative;">🔔</span>
                <span class="icon-small" onclick="window.location.href='my_bookings.php'" style="cursor: pointer;" title="My Bookings">📅</span>
                <span class="icon-small" onclick="window.location.href='profile.php'" style="cursor: pointer;" title="Profile">
                    <img src="css/druckcat_profile.jpeg" alt="drunkcat" style="height:28px;border-radius:22px; object-fit:contain;">
                </span>
                <a href="../login/logout.php" class="btn-logout-icon">Logout</a>
            </div>
        </div>
    </div>

    <!-- Main Container -->
    <div class="party-size-container">
        <!-- Venue Logo & Name -->
        <div class="venue-logo-section">
            <div class="venue-logo-circle">
                <span style="font-size: 48px;">🍽️</span>
            </div>
            <h2 class="venue-name-party"><?php echo htmlspecialchars($venue_name); ?></h2>
        </div>

        <!-- Party Size Info -->
        <div style="text-align: center; margin-bottom: 20px;">
            <div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); 
                        color: white; 
                        padding: 15px 30px; 
                        border-radius: 15px; 
                        display: inline-block;
                        font-weight: bold;
                        font-size: 18px;">
                <?php echo $tables_count; ?> Table<?php echo $tables_count > 1 ? 's' : ''; ?> Selected
            </div>
            <div style="color: #64748b; margin-top: 10px; font-size: 14px;">
                Please select party size: <?php echo $min_party_size; ?>-<?php echo $max_party_size; ?> people
            </div>
        </div>

        <!-- Party Size Selector -->
        <div class="party-selector">
            <button class="party-btn party-minus" id="minusBtn">
                <span>−</span>
            </button>
            <div class="party-number" id="partyNumber"><?php echo $min_party_size; ?></div>
            <button class="party-btn party-plus" id="plusBtn">
                <span>+</span>
            </button>
        </div>

        <div style="text-align: center; color: #94a3b8; margin-top: 15px; font-size: 14px;">
            <div>
                Min: <?php echo $min_party_size; ?> people | 
                Max: <?php echo $max_party_size; ?> people
            </div>
            <div style="margin-top: 5px; font-size: 12px; color: #cbd5e1;">
                (Each table accommodates up to 4 people)
            </div>
        </div>

        <!-- Book Now Button -->
        <button class="btn-book-now" id="bookNowBtn">
            PROCEED TO PAYMENT →
        </button>

        <!-- Booking Details -->
        <div class="booking-details-box">
            <h3 class="details-title">Booking Details</h3>
            <div class="detail-row">
                <span class="detail-label">Restaurant:</span>
                <span class="detail-value"><?php echo htmlspecialchars($venue_name); ?></span>
            </div>
            <div class="detail-row">
                <span class="detail-label">Zones:</span>
                <span class="detail-value"><?php echo htmlspecialchars($zones); ?></span>
            </div>
            <div class="detail-row">
                <span class="detail-label">Number of Tables:</span>
                <span class="detail-value"><?php echo $tables_count; ?> table<?php echo $tables_count > 1 ? 's' : ''; ?></span>
            </div>
            <div class="detail-row">
                <span class="detail-label">Party Size Range:</span>
                <span class="detail-value"><?php echo $min_party_size; ?>-<?php echo $max_party_size; ?> people</span>
            </div>
            <div class="detail-row">
                <span class="detail-label">Selected Party Size:</span>
                <span class="detail-value" id="partySizeDisplay"><?php echo $min_party_size; ?> person<?php echo $min_party_size > 1 ? 's' : ''; ?></span>
            </div>
            <div class="detail-row total-row">
                <span class="detail-label">Deposit Amount:</span>
                <span class="detail-value price-highlight"><?php echo number_format($total_price); ?> THB</span>
            </div>
        </div>

        <!-- Back Button -->
        <button class="btn-back-party" onclick="history.back()">
            ← Back
        </button>
    </div>

    <script>
        // ตั้งค่า min/max ตามจำนวน table
        const tablesCount = <?php echo $tables_count; ?>;
        const minSize = <?php echo $min_party_size; ?>;
        const maxSize = <?php echo $max_party_size; ?>;
        
        let partySize = minSize; // เริ่มที่ minimum
        
        const partyNumberEl = document.getElementById('partyNumber');
        const partySizeDisplayEl = document.getElementById('partySizeDisplay');
        const minusBtn = document.getElementById('minusBtn');
        const plusBtn = document.getElementById('plusBtn');
        const bookNowBtn = document.getElementById('bookNowBtn');

        function updatePartySize() {
            partyNumberEl.textContent = partySize;
            partySizeDisplayEl.textContent = partySize + (partySize === 1 ? ' person' : ' people');
            
            // Disable/enable buttons
            minusBtn.disabled = partySize <= minSize;
            plusBtn.disabled = partySize >= maxSize;
            
            // Update button style when disabled
            if (minusBtn.disabled) {
                minusBtn.style.opacity = '0.5';
                minusBtn.style.cursor = 'not-allowed';
            } else {
                minusBtn.style.opacity = '1';
                minusBtn.style.cursor = 'pointer';
            }
            
            if (plusBtn.disabled) {
                plusBtn.style.opacity = '0.5';
                plusBtn.style.cursor = 'not-allowed';
            } else {
                plusBtn.style.opacity = '1';
                plusBtn.style.cursor = 'pointer';
            }
        }

        minusBtn.addEventListener('click', function() {
            if (partySize > minSize) {
                partySize--;
                updatePartySize();
            }
        });

        plusBtn.addEventListener('click', function() {
            if (partySize < maxSize) {
                partySize++;
                updatePartySize();
            }
        });

        bookNowBtn.addEventListener('click', function() {
            // Validate party size
            if (partySize < minSize) {
                alert(`Minimum party size is ${minSize} people for ${tablesCount} table(s)!`);
                return;
            }
            
            if (partySize > maxSize) {
                alert(`Maximum party size is ${maxSize} people for ${tablesCount} table(s)!`);
                return;
            }
            
            // ✅ ใช้ encodeURIComponent เพื่อ encode ข้อมูลอย่างถูกต้อง
            const params = new URLSearchParams({
                restaurant_id: <?php echo json_encode($restaurant_id); ?>,
                table_ids: <?php echo json_encode($table_ids); ?>,
                tables: <?php echo json_encode($tables_count); ?>,
                zones: <?php echo json_encode($zones); ?>,
                price: <?php echo json_encode($total_price); ?>,
                party_size: partySize,
                venue: <?php echo json_encode($venue_name); ?>
                // ไม่ต้องส่ง date - ใช้วันนี้อัตโนมัติ
            });
            window.location.href = 'payment.php?' + params.toString();
        });

        // Initialize
        updatePartySize();
    </script>

    <?php include 'notification_widget.php'; ?>
</body>
</html>