<?php
require_once '../connect.php';

// 🔥 NEW: ใช้ restaurant_id จาก session
$restaurant_id = $_SESSION['restaurant_id'] ?? null;

if (!$restaurant_id) {
    die("Error: Restaurant ID not found in session. Please login again.");
}

// Calculate statistics (filtered by restaurant_id)
// 1. Total Revenue (ไม่นับ CANCELLED)
$sql = "SELECT COALESCE(SUM(p.payment_amount), 0) as total 
        FROM payment p
        INNER JOIN reservation r ON p.reserve_id = r.reserve_id
        WHERE p.payment_status = 'PAID'
        AND r.reserve_status != 'CANCELLED'
        AND r.restaurant_id = ?";
$stmt = $mysqli->prepare($sql);
$stmt->bind_param("i", $restaurant_id);
$stmt->execute();
$result = $stmt->get_result();
$totalRevenue = $result->fetch_assoc()['total'];
$stmt->close();

// 2. Total Bookings (ไม่นับ CANCELLED)
$sql = "SELECT COUNT(*) as total 
        FROM reservation 
        WHERE restaurant_id = ?
        AND reserve_status != 'CANCELLED'";
$stmt = $mysqli->prepare($sql);
$stmt->bind_param("i", $restaurant_id);
$stmt->execute();
$result = $stmt->get_result();
$totalBookings = $result->fetch_assoc()['total'];
$stmt->close();

// 3. Revenue by Category (ไม่นับ CANCELLED) - FIXED: ใช้โต๊ะแรกของแต่ละ reservation เพื่อจัดหมวดหมู่
$sql = "SELECT 
            COALESCE(SUM(CASE WHEN a.area_type = 'INFRONT' THEN p.payment_amount ELSE 0 END), 0) as front_revenue,
            COALESCE(SUM(CASE WHEN a.area_type = 'BACKSIDE' THEN p.payment_amount ELSE 0 END), 0) as back_revenue
        FROM payment p
        INNER JOIN reservation r ON p.reserve_id = r.reserve_id
        INNER JOIN reservation_tables rt ON r.reserve_id = rt.reserve_id
        INNER JOIN (
            SELECT rt2.reserve_id, MIN(rt2.table_id) as first_table_id
            FROM reservation_tables rt2
            GROUP BY rt2.reserve_id
        ) first_tables ON rt.reserve_id = first_tables.reserve_id AND rt.table_id = first_tables.first_table_id
        INNER JOIN `table` t ON rt.table_id = t.table_id
        INNER JOIN area a ON t.area_id = a.area_id
        WHERE p.payment_status = 'PAID'
        AND r.reserve_status != 'CANCELLED'
        AND r.restaurant_id = ?";
$stmt = $mysqli->prepare($sql);
$stmt->bind_param("i", $restaurant_id);
$stmt->execute();
$result = $stmt->get_result();
$revenueByCategory = $result->fetch_assoc();
$stmt->close();

// Payment Breakdown (ไม่นับ CANCELLED)
$sql = "SELECT 
            COUNT(*) as count,
            SUM(p.payment_amount) as total
        FROM payment p
        INNER JOIN reservation r ON p.reserve_id = r.reserve_id
        WHERE p.payment_status = 'PAID'
        AND r.reserve_status != 'CANCELLED'
        AND r.restaurant_id = ?";
$stmt = $mysqli->prepare($sql);
$stmt->bind_param("i", $restaurant_id);
$stmt->execute();
$result = $stmt->get_result();
$paymentBreakdown = $result->fetch_assoc();
$stmt->close();

// Reservation Status
$sql = "SELECT 
            reserve_status,
            COUNT(*) as count,
            SUM(reserve_deposit) as total
        FROM reservation
        WHERE restaurant_id = ?
        GROUP BY reserve_status";
$stmt = $mysqli->prepare($sql);
$stmt->bind_param("i", $restaurant_id);
$stmt->execute();
$result = $stmt->get_result();
$reservationStats = [];
while ($row = $result->fetch_assoc()) {
    $reservationStats[] = $row;
}
$stmt->close();

// Chart data - Revenue by date (last 7 days) - ไม่นับ CANCELLED
$sql = "SELECT 
            DATE(p.paid_at) as payment_date,
            SUM(p.payment_amount) as daily_revenue
        FROM payment p
        INNER JOIN reservation r ON p.reserve_id = r.reserve_id
        WHERE p.payment_status = 'PAID'
        AND r.reserve_status != 'CANCELLED'
        AND p.paid_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        AND r.restaurant_id = ?
        GROUP BY DATE(p.paid_at)
        ORDER BY payment_date ASC";
$stmt = $mysqli->prepare($sql);
$stmt->bind_param("i", $restaurant_id);
$stmt->execute();
$result = $stmt->get_result();
$chartData = [];
while ($row = $result->fetch_assoc()) {
    $chartData[] = $row;
}
$stmt->close();

$chartDates = [];
$chartRevenues = [];
foreach ($chartData as $row) {
    $chartDates[] = $row['payment_date'];
    $chartRevenues[] = $row['daily_revenue'];
}
?>

<!-- Stats Cards -->
<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-bottom:20px">
    <div style="background:#e0f2ff;padding:20px;border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,0.08)">
        <div style="color:#666;font-size:14px;margin-bottom:8px;font-weight:600">Total Revenue</div>
        <div style="font-size:28px;font-weight:700;color:#1f2937">
            ฿ <?= number_format($totalRevenue, 2) ?>
        </div>
    </div>
    <div style="background:#fff7c2;padding:20px;border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,0.08)">
        <div style="color:#666;font-size:14px;margin-bottom:8px;font-weight:600">Total Booking</div>
        <div style="font-size:28px;font-weight:700;color:#1f2937">
            <?= $totalBookings ?>
        </div>
    </div>
    <div style="background:#e6cfff;padding:20px;border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,0.08)">
        <div style="color:#666;font-size:14px;margin-bottom:8px;font-weight:600">Revenue by Category</div>
        <div style="font-size:20px;font-weight:700;color:#1f2937">
            Front: ฿<?= number_format($revenueByCategory['front_revenue'], 0) ?>
        </div>
        <div style="font-size:16px;color:#666;margin-top:4px">
            Back: ฿<?= number_format($revenueByCategory['back_revenue'], 0) ?>
        </div>
    </div>
</div>

<!-- Chart Section -->
<div class="card" style="padding:16px">
    <div style="display:flex;gap:12px;margin-bottom:20px">
        <button class="btn primary" onclick="filterReport('week')">This week</button>
        <button class="btn" onclick="filterReport('month')">This month</button>
    </div>
    
    <div style="background:#f9f4ff;border-radius:12px;padding:20px;margin-bottom:20px">
        <h3 style="margin:0 0 16px 0;color:#6b2b6b">📈 Revenue Trend (Last 7 Days)</h3>
        <div style="position:relative;height:400px">
            <canvas id="revenueChart"></canvas>
        </div>
    </div>
</div>

<!-- Payment Breakdown -->
<div class="card">
    <h3 style="margin:0 0 16px 0;color:#6b2b6b">Payment Breakdown</h3>
    <div style="display:grid;grid-template-columns:repeat(2,1fr);gap:16px">
        <div style="background:#e8f5ff;padding:20px;border-radius:12px">
            <div style="color:#666;font-size:14px;margin-bottom:8px">PAID Payments</div>
            <div style="font-size:28px;font-weight:700;color:#1f2937">
                <?= $paymentBreakdown['count'] ?> payments
            </div>
            <div style="font-size:18px;color:#666;margin-top:4px">
                ฿ <?= number_format($paymentBreakdown['total'], 2) ?>
            </div>
        </div>
        <div style="background:#fff3e6;padding:20px;border-radius:12px">
            <div style="color:#666;font-size:14px;margin-bottom:8px">Average per Payment</div>
            <div style="font-size:28px;font-weight:700;color:#1f2937">
                ฿ <?= $paymentBreakdown['count'] > 0 ? number_format($paymentBreakdown['total'] / $paymentBreakdown['count'], 2) : '0.00' ?>
            </div>
        </div>
    </div>
</div>

<!-- Reservation Status -->
<div class="card">
    <h3 style="margin:0 0 16px 0;color:#6b2b6b">Reservation Status</h3>
    <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:16px">
        <?php foreach ($reservationStats as $stat): 
            $bgColor = '#f0e6ff';
            if ($stat['reserve_status'] == 'CONFIRMED') $bgColor = '#d4edda';
            if ($stat['reserve_status'] == 'CANCELLED') $bgColor = '#f8d7da';
        ?>
            <div style="background:<?= $bgColor ?>;padding:20px;border-radius:12px;text-align:center">
                <div style="font-weight:700;font-size:14px;color:#666;margin-bottom:8px;text-transform:uppercase">
                    <?= $stat['reserve_status'] ?>
                </div>
                <div style="font-size:32px;font-weight:700;color:#1f2937">
                    <?= $stat['count'] ?>
                </div>
                <div style="font-size:14px;color:#666;margin-top:4px">
                    reservations
                </div>
                <div style="font-size:16px;font-weight:600;color:#333;margin-top:8px">
                    ฿ <?= number_format($stat['total'], 2) ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
// Revenue Chart
const ctx = document.getElementById('revenueChart').getContext('2d');
const chartDates = <?= json_encode($chartDates) ?>;
const chartRevenues = <?= json_encode($chartRevenues) ?>;

const revenueChart = new Chart(ctx, {
    type: 'line',
    data: {
        labels: chartDates,
        datasets: [{
            label: 'Daily Revenue (฿)',
            data: chartRevenues,
            borderColor: '#a76de6',
            backgroundColor: 'rgba(167, 109, 230, 0.1)',
            borderWidth: 3,
            fill: true,
            tension: 0.4,
            pointRadius: 5,
            pointHoverRadius: 7,
            pointBackgroundColor: '#a76de6',
            pointBorderColor: '#fff',
            pointBorderWidth: 2
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                display: true,
                position: 'top',
                onClick: function() { return false; } // Disable legend clicking completely
            }
        },
        scales: {
            y: {
                beginAtZero: true,
                ticks: {
                    callback: function(value) {
                        return '฿' + value.toLocaleString();
                    }
                }
            }
        }
    }
});

// Remove clickable cursor
document.getElementById('revenueChart').style.cursor = 'default';

function filterReport(period) {
    if (period === 'month') {
        alert('Sorry, this is demo');
    }
    // This week already displayed by default, no action needed
}
</script>