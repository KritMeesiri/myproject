<?php
require '../connect.php';

// 🔥 NEW: ใช้ restaurant_id จาก session
$restaurant_id = $_SESSION['restaurant_id'] ?? null;

if (!$restaurant_id) {
    die("Error: Restaurant ID not found in session. Please login again.");
}

// Fetch all tables with area information and REAL-TIME status from reservations
$sql = "SELECT 
            t.table_id, 
            t.area_id, 
            t.table_number, 
            t.capacity,
            a.area_name, 
            a.area_type,
            CASE
                -- ✅ เช็คว่ามีการ check-in แล้ววันนี้หรือยัง → CHECKIN (สีเทา)
                WHEN EXISTS (
                    SELECT 1 
                    FROM checkin c
                    JOIN reservation r ON c.reserve_id = r.reserve_id
                    JOIN reservation_tables rt ON r.reserve_id = rt.reserve_id
                    WHERE rt.table_id = t.table_id
                    AND DATE(c.checkin_at) = CURDATE()
                ) THEN 'CHECKIN'
                -- 🔴 ถ้ามีการจอง CONFIRMED/HOLD วันนี้ (แต่ยังไม่ check-in) → BOOKING (สีแดง)
                WHEN EXISTS (
                    SELECT 1 
                    FROM reservation r
                    JOIN reservation_tables rt ON r.reserve_id = rt.reserve_id
                    WHERE rt.table_id = t.table_id
                    AND r.reserve_status IN ('CONFIRMED', 'HOLD')
                    AND DATE(r.reserve_date) = CURDATE()
                ) THEN 'BOOKING'
                -- 🔵 ถ้าไม่มีเงื่อนไขใดเป็นจริง = ว่าง (สีฟ้า)
                ELSE 'AVAILABLE'
            END as table_status
        FROM `table` t
        INNER JOIN area a ON t.area_id = a.area_id
        WHERE a.restaurant_id = ?
        ORDER BY t.area_id, t.table_id";

$stmt = $mysqli->prepare($sql);
$stmt->bind_param("i", $restaurant_id);
$stmt->execute();
$result = $stmt->get_result();
$tables = [];
while ($row = $result->fetch_assoc()) {
    $tables[] = $row;
}
$stmt->close();

// Separate tables by area
$frontRowTables = [];
$backRowTables = [];
$allTables = []; // รวมทุกโต๊ะไว้แสดงแบบ grid เดียว

foreach ($tables as $table) {
    // เพิ่ม area_type class เข้าไปใน array
    $table['area_class'] = strtolower($table['area_type']); // 'infront' หรือ 'backside'
    $allTables[] = $table;
    
    if ($table['area_type'] == 'INFRONT') {
        $frontRowTables[] = $table;
    } else if ($table['area_type'] == 'BACKSIDE') {
        $backRowTables[] = $table;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Restaurant Table Layout</title>
    <link rel="stylesheet" href="styles.css">
    <link rel="stylesheet" href="table_layout.css">
    <style>
        /* Additional styles for disabled tables */
        .table-btn.disabled {
            cursor: not-allowed;
            opacity: 0.6;
            filter: grayscale(50%);
        }
        
        .table-btn.disabled:hover {
            transform: none;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }
    </style>
</head>
<body>
    <div class="layout-container">
        <div class="layout-content">
            <!-- Header -->
            <div class="layout-header">
                <h1>Table Layout</h1>
            </div>

            <!-- Legend (Moved to top) -->
            <div class="legend-container">
                <div class="legend-item">
                    <div class="legend-dot available"></div>
                    <span>available = Cannot click (Table is free)</span>
                </div>
                <div class="legend-item">
                    <div class="legend-dot unavailable"></div>
                    <span>unavailable = Clickable (Can check-in or cancel)</span>
                </div>
                <div class="legend-item">
                    <div class="legend-dot occupied"></div>
                    <span>occupied = Clickable (Can check-out)</span>
                </div>
            </div>

            <!-- Venue Layout with Stage -->
            <div class="venue-layout">
                <!-- Stage -->
                <div class="stage-area">
                    <div class="stage-icon">🎭</div>
                    <div class="stage-text">STAGE</div>
                    <div class="stage-subtitle">Live Performance Area</div>
                </div>

                <!-- All Tables in Single Grid -->
                <div class="table-grid">
                    <?php foreach ($allTables as $table): 
                        $statusClass = strtolower($table['table_status']);
                        $areaClass = $table['area_class']; // 'infront' หรือ 'backside'
                        $isAvailable = $table['table_status'] === 'AVAILABLE';
                        $disabledClass = $isAvailable ? 'disabled' : '';
                        $onclickAttr = $isAvailable ? '' : "onclick=\"openTableModal({$table['table_id']}, '" . htmlspecialchars($table['table_number']) . "', '" . htmlspecialchars($table['area_name']) . "', {$table['capacity']}, '{$table['table_status']}')\"";
                    ?>
                        <button 
                            class="table-btn status-<?= $statusClass ?> area-<?= $areaClass ?> <?= $disabledClass ?>"
                            <?= $onclickAttr ?>
                            <?= $isAvailable ? 'disabled' : '' ?>
                            title="<?= htmlspecialchars($table['area_name']) ?> - <?= htmlspecialchars($table['table_number']) ?> (<?= $table['capacity'] ?> seats)">
                            <div class="table-number"><?= htmlspecialchars($table['table_number']) ?></div>
                            <div class="table-seats">(<?= $table['capacity'] ?>)</div>
                        </button>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal -->
    <div id="tableModal" class="modal">
        <div class="modal-content">
            <span class="modal-close" onclick="closeTableModal()">&times;</span>
            <h3 id="modalTableNumber">Table Info</h3>
            <div class="modal-details">
                <p><strong>Area:</strong> <span id="modalArea"></span></p>
                <p><strong>Capacity:</strong> <span id="modalCapacity"></span> seats</p>
                <p><strong>Status:</strong> <span id="modalStatus" class="status-badge"></span></p>
            </div>
            <p class="modal-question">What would you like to do?</p>
            <div class="modal-actions" id="modalActions">
                <!-- จะถูกเปลี่ยนตาม status -->
            </div>
            <button class="btn-close-modal" onclick="closeTableModal()">Close</button>
        </div>
    </div>

    <script>
        let currentTableId = null;
        let currentTableStatus = null;

        function openTableModal(tableId, tableNumber, areaName, capacity, status) {
            currentTableId = tableId;
            currentTableStatus = status;
            
            document.getElementById('modalTableNumber').textContent = 'Table ' + tableNumber;
            document.getElementById('modalArea').textContent = areaName;
            document.getElementById('modalCapacity').textContent = capacity;
            
            const statusBadge = document.getElementById('modalStatus');
            statusBadge.textContent = status;
            statusBadge.className = 'status-badge status-' + status.toLowerCase();
            
            // เปลี่ยนปุ่มตาม status
            const actionsDiv = document.getElementById('modalActions');
            
            if (status === 'CHECKIN') {
                // ถ้าเป็น OCCUPIED (CHECKIN) → แสดงปุ่ม Check Out แต่แสดง demo message
                actionsDiv.innerHTML = `
                    <button class="btn-checkin" onclick="handleCheckOut()" style="background: #6c757d;">Check Out</button>
                `;
            } else if (status === 'BOOKING') {
                // ถ้าเป็น BOOKING (UNAVAILABLE) → แสดงปุ่ม Check In และ Set Available
                actionsDiv.innerHTML = `
                    <button class="btn-checkin" onclick="handleCheckIn()">Check In</button>
                    <button class="btn-cancel" onclick="handleCancel()">Set Available</button>
                `;
            } else {
                actionsDiv.innerHTML = '<p>No actions available</p>';
            }
            
            document.getElementById('tableModal').style.display = 'flex';
        }

        function closeTableModal() {
            document.getElementById('tableModal').style.display = 'none';
            currentTableId = null;
            currentTableStatus = null;
        }

        function handleCheckOut() {
            // 🔥 แสดงข้อความ demo แทนการ check-out จริง
            alert('Sorry, this is demo.');
            closeTableModal();
        }

        function handleCheckIn() {
            if (!currentTableId) return;
            
            if (!confirm('Are you sure you want to check in this table?')) {
                return;
            }
            
            const formData = new FormData();
            formData.append('table_id', currentTableId);
            formData.append('action', 'checkin');
            
            fetch('update_table_status.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('Table checked in successfully!');
                    location.reload();
                } else {
                    alert('Error: ' + (data.message || 'Failed to check in'));
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Failed to connect to server');
            });
        }

        function handleCancel() {
            if (!currentTableId) return;
            
            if (!confirm('Are you sure you want to set this table as available?')) {
                return;
            }
            
            const formData = new FormData();
            formData.append('table_id', currentTableId);
            formData.append('action', 'cancel');
            
            fetch('update_table_status.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('Table set to available successfully!');
                    location.reload();
                } else {
                    alert('Error: ' + (data.message || 'Failed to update'));
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Failed to connect to server');
            });
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            const modal = document.getElementById('tableModal');
            if (event.target == modal) {
                closeTableModal();
            }
        }
    </script>
</body>
</html>