<?php
// dashboard.php - Dashboard content only (no sidebar/header)
require_once '../connect.php';

// 🔥 NEW: ใช้ restaurant_id จาก session ของ staff
$staff_restaurant_id = $_SESSION['restaurant_id'] ?? null;

if (!$staff_restaurant_id) {
    die("Error: Restaurant ID not found in session. Please login again.");
}

// ดึงข้อมูลร้านอาหารที่ staff สังกัด
$sql = "SELECT * FROM restaurant WHERE restaurant_id = ? LIMIT 1";
$stmt = $mysqli->prepare($sql);
$stmt->bind_param("i", $staff_restaurant_id);
$stmt->execute();
$result = $stmt->get_result();
$restaurant = $result->fetch_assoc();
$stmt->close();

if (!$restaurant) {
    die("Error: Restaurant not found.");
}

// 1. Today's Bookings - จำนวน reservation ของร้านที่ staff สังกัด (ไม่นับ CANCELLED)
$sql = "SELECT COUNT(*) as total 
        FROM reservation 
        WHERE reserve_date = CURDATE() 
        AND reserve_status != 'CANCELLED'
        AND restaurant_id = ?";
$stmt = $mysqli->prepare($sql);
$stmt->bind_param("i", $staff_restaurant_id);
$stmt->execute();
$result = $stmt->get_result();
$todayBookings = $result->fetch_assoc()['total'];
$stmt->close();

// 2. Today's Revenue - รวม payment_amount ของร้านที่ staff สังกัดวันนี้ (ไม่นับ CANCELLED)
$sql = "SELECT COALESCE(SUM(p.payment_amount), 0) as total 
        FROM payment p
        INNER JOIN reservation r ON p.reserve_id = r.reserve_id
        WHERE DATE(p.paid_at) = CURDATE() 
        AND p.payment_status = 'PAID'
        AND r.reserve_status != 'CANCELLED'
        AND r.restaurant_id = ?";
$stmt = $mysqli->prepare($sql);
$stmt->bind_param("i", $staff_restaurant_id);
$stmt->execute();
$result = $stmt->get_result();
$todayRevenue = $result->fetch_assoc()['total'];
$stmt->close();

// 3. Available Tables - นับโต๊ะว่างจริงๆ (ไม่รวม UNAVAILABLE และไม่รวมที่มีการจองวันนี้)
$sql = "SELECT COUNT(*) as total 
        FROM `table` t
        INNER JOIN area a ON t.area_id = a.area_id
        WHERE t.table_status = 'AVAILABLE'
        AND a.restaurant_id = ?
        AND NOT EXISTS (
            SELECT 1 
            FROM reservation r
            INNER JOIN reservation_tables rt ON r.reserve_id = rt.reserve_id
            WHERE rt.table_id = t.table_id
            AND r.reserve_status IN ('CONFIRMED', 'HOLD')
            AND DATE(r.reserve_date) = CURDATE()
        )";
$stmt = $mysqli->prepare($sql);
$stmt->bind_param("i", $staff_restaurant_id);
$stmt->execute();
$result = $stmt->get_result();
$availableTables = $result->fetch_assoc()['total'];
$stmt->close();

// 4. Customer Number - นับลูกค้าที่มีการจองร้านที่ staff สังกัดวันนี้ (ไม่นับ CANCELLED)
$sql = "SELECT COUNT(DISTINCT r.user_id) as total 
        FROM reservation r
        INNER JOIN user u ON r.user_id = u.user_id
        WHERE r.reserve_date = CURDATE()
        AND r.reserve_status != 'CANCELLED'
        AND r.restaurant_id = ?
        AND u.is_active = 'Y'";
$stmt = $mysqli->prepare($sql);
$stmt->bind_param("i", $staff_restaurant_id);
$stmt->execute();
$result = $stmt->get_result();
$customerNumber = $result->fetch_assoc()['total'];
$stmt->close();

// 5. Bookings List - รายการ booking พร้อมหมายเลขโต๊ะ
$searchName = isset($_GET['search']) ? trim($_GET['search']) : '';
$sql = "SELECT 
            r.reserve_id,
            r.reserve_date,
            r.claim_deadline_at,
            r.party_size,
            r.reserve_status,
            u.firstname,
            u.lastname,
            GROUP_CONCAT(t.table_number ORDER BY t.table_number SEPARATOR ', ') as table_numbers
        FROM reservation r
        INNER JOIN user u ON r.user_id = u.user_id
        LEFT JOIN reservation_tables rt ON r.reserve_id = rt.reserve_id
        LEFT JOIN `table` t ON rt.table_id = t.table_id
        WHERE r.restaurant_id = ?";

if ($searchName) {
    $sql .= " AND (u.firstname LIKE ? OR u.lastname LIKE ?)";
}

$sql .= " GROUP BY r.reserve_id ORDER BY r.reserve_date DESC, r.claim_deadline_at DESC LIMIT 20";

$stmt = $mysqli->prepare($sql);
if ($searchName) {
    $searchParam = '%' . $searchName . '%';
    $stmt->bind_param("iss", $staff_restaurant_id, $searchParam, $searchParam);
} else {
    $stmt->bind_param("i", $staff_restaurant_id);
}
$stmt->execute();
$result = $stmt->get_result();
$bookings = [];
while ($row = $result->fetch_assoc()) {
    $bookings[] = $row;
}
$stmt->close();

// 6. Calendar Events - วันที่มี reservation ของร้านที่ staff สังกัด
$sql = "SELECT DISTINCT reserve_date
        FROM reservation 
        WHERE reserve_date >= CURDATE() - INTERVAL 30 DAY
        AND restaurant_id = ?";
$stmt = $mysqli->prepare($sql);
$stmt->bind_param("i", $staff_restaurant_id);
$stmt->execute();
$result = $stmt->get_result();
$reservedDates = [];
while ($row = $result->fetch_assoc()) {
    $reservedDates[] = $row['reserve_date'];
}
$stmt->close();

// 7. Tables for mini layout - โต๊ะของร้านที่ staff สังกัด (with real-time status)
$sql = "SELECT 
            t.table_id, 
            t.table_number, 
            t.capacity, 
            a.area_id,
            CASE
                -- ถ้าโต๊ะถูก set เป็น UNAVAILABLE ใน table (ปิดใช้งานชั่วคราว)
                WHEN t.table_status = 'UNAVAILABLE' THEN 'UNAVAILABLE'
                -- ถ้ามีการจองที่ CONFIRMED หรือ HOLD วันนี้
                WHEN EXISTS (
                    SELECT 1 
                    FROM reservation r
                    JOIN reservation_tables rt ON r.reserve_id = rt.reserve_id
                    WHERE rt.table_id = t.table_id
                    AND r.reserve_status IN ('CONFIRMED', 'HOLD')
                    AND DATE(r.reserve_date) = CURDATE()
                ) THEN 'OCCUPIED'
                -- ถ้าไม่มีเงื่อนไขใดเป็นจริง = ว่าง
                ELSE 'AVAILABLE'
            END as table_status
        FROM `table` t
        INNER JOIN area a ON t.area_id = a.area_id
        WHERE a.restaurant_id = ?
        ORDER BY a.area_id, t.table_id
        LIMIT 15";
$stmt = $mysqli->prepare($sql);
$stmt->bind_param("i", $staff_restaurant_id);
$stmt->execute();
$result = $stmt->get_result();
$tables = [];
while ($row = $result->fetch_assoc()) {
    $tables[] = $row;
}
$stmt->close();
?>

<!-- Restaurant Info Card (ไม่มี selector เพราะ staff เห็นแค่ร้านตัวเอง) -->
<div class="card" style="margin-bottom: 24px; padding: 20px; background: linear-gradient(135deg, #f9f4ff 0%, #f0d9ff 100%); border: 2px solid #e8d3f2;">
    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px;">
        <div>
            <h3 style="margin: 0 0 8px 0; color: #6b2b6b; font-size: 20px; font-weight: 700;">
                📍 <?= htmlspecialchars($restaurant['rest_name']) ?>
            </h3>
            <p style="margin: 0; color: #666; font-size: 14px;">
                <?= htmlspecialchars($restaurant['rest_address']) ?>
            </p>
        </div>
        <div style="padding: 10px 20px; background: white; border-radius: 10px; border: 2px solid #e8d3f2;">
            <span style="font-weight: 600; color: #6b2b6b; font-size: 14px;">Your Restaurant</span>
        </div>
    </div>
</div>

<!-- Stats Cards -->
<div class="stats-grid">
    <div class="stat-card" style="background:#fbc4ff;">
        <div class="stat-label">Today's Bookings</div>
        <div class="stat-value"><?= $todayBookings ?></div>
    </div>
    <div class="stat-card" style="background:#bdf3ff;">
        <div class="stat-label">Today's Revenue</div>
        <div class="stat-value"><?= number_format($todayRevenue, 2) ?> ฿</div>
    </div>
    <div class="stat-card" style="background:#fff6b3;">
        <div class="stat-label">Available Tables</div>
        <div class="stat-value"><?= $availableTables ?></div>
    </div>
    <div class="stat-card" style="background:#c8ffd4;">
        <div class="stat-label">Customer Number</div>
        <div class="stat-value"><?= $customerNumber ?></div>
    </div>
</div>

<!-- Bookings Table -->
<div class="card">
    <div class="controls">
        <form method="GET" style="display:flex;gap:8px;width:100%;align-items:center">
            <input type="hidden" name="page" value="dashboard">
            <input 
                class="search" 
                name="search" 
                placeholder="Search bookings by name..." 
                value="<?= htmlspecialchars($searchName) ?>">
            <button type="submit" class="btn primary">Search</button>
            <?php if ($searchName): ?>
                <a href="?page=dashboard" class="btn">Clear</a>
            <?php endif; ?>
            <select class="btn" style="margin-left:auto">
                <option>Today</option>
                <option>This week</option>
                <option>This month</option>
            </select>
        </form>
    </div>

    <table class="table">
        <thead>
            <tr>
                <th>Name</th>
                <th>Date</th>
                <th>Time</th>
                <th>Party Size</th>
                <th>Tables</th>
                <th>Status</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($bookings)): ?>
                <tr>
                    <td colspan="7" style="text-align:center;padding:20px;color:#999">
                        No bookings found
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach($bookings as $booking): ?>
                <tr>
                    <td><?= htmlspecialchars($booking['firstname'] . ' ' . $booking['lastname']) ?></td>
                    <td><?= date('Y-m-d', strtotime($booking['reserve_date'])) ?></td>
                    <td><?= $booking['claim_deadline_at'] ? date('H:i', strtotime($booking['claim_deadline_at'])) : '-' ?></td>
                    <td><?= $booking['party_size'] ?></td>
                    <td><span style="color:#6b2b6b;font-weight:600"><?= $booking['table_numbers'] ?: 'N/A' ?></span></td>
                    <td>
                        <span class="badge status-<?= strtolower($booking['reserve_status']) ?>">
                            <?= $booking['reserve_status'] ?>
                        </span>
                    </td>
                    <td>
                        <button class="btn" onclick="manageBooking(<?= $booking['reserve_id'] ?>)">
                            Manage
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- Calendar & Tables Grid -->
<div class="dashboard-grid">
    <!-- Calendar -->
    <div class="card">
        <h3 style="margin:0 0 16px 0;color:#6b2b6b">Calendar</h3>
        <div id="calendar"></div>
    </div>

    <!-- Mini Tables Layout -->
    <div class="card">
        <h3 style="margin:0 0 16px 0;color:#6b2b6b">Tables</h3>
        <div class="mini-legend">
            <div class="legend-item">
                <span class="legend-dot" style="background: linear-gradient(135deg, #5b8db8 0%, #4a7399 100%);"></span>
                <span>Available</span>
            </div>
            <div class="legend-item">
                <span class="legend-dot" style="background: linear-gradient(135deg, #a0605f 0%, #8b4a49 100%);"></span>
                <span>Booking</span>
            </div>
            <div class="legend-item">
                <span class="legend-dot" style="background: linear-gradient(135deg, #8a8a8a 0%, #6b6b6b 100%);"></span>
                <span>CheckIn</span>
            </div>
        </div>
        <div class="mini-table-grid">
            <?php foreach($tables as $table): 
                $statusClass = 'status-' . strtolower($table['table_status']);
            ?>
                <div class="mini-table-cell <?= $statusClass ?>" 
                     onclick="viewTableDetails(<?= $table['table_id'] ?>)">
                    <div class="mini-table-number"><?= htmlspecialchars($table['table_number']) ?></div>
                    <div class="mini-table-seats"><?= $table['capacity'] ?> seats</div>
                </div>
            <?php endforeach; ?>
        </div>
        <a href="?page=tables" class="view-all-link">View All Tables →</a>
    </div>
</div>

<script>
// Calendar initialization
document.addEventListener('DOMContentLoaded', function() {
    var calendarEl = document.getElementById('calendar');
    if (!calendarEl) return;
    
    var reservedDates = <?= json_encode($reservedDates) ?>;
    
    var calendar = new FullCalendar.Calendar(calendarEl, {
        initialView: 'dayGridMonth',
        height: 400,
        headerToolbar: {
            left: 'prev,next today',
            center: 'title',
            right: 'dayGridMonth,timeGridWeek,timeGridDay'
        },
        buttonText: {
            today: 'Today',
            month: 'Month',
            week: 'Week',
            day: 'Day'
        },
        dayCellDidMount: function(info) {
            var dateStr = info.date.toISOString().split('T')[0];
            if (reservedDates.includes(dateStr)) {
                info.el.style.backgroundColor = '#fff9e6';
            }
        },
        dateClick: function(info) {
            alert('Sorry, this is demo.');
        }
    });
    
    calendar.render();
});

function manageBooking(reserveId) {
    window.location.href = '?page=booking&id=' + reserveId;
}

function viewTableDetails(tableId) {
    window.location.href = '?page=tables&highlight=' + tableId;
}
</script>