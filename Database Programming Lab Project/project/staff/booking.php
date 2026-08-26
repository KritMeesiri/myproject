<?php
require '../connect.php';

// 🔥 NEW: ใช้ข้อมูลจาก session
$staff_id = $_SESSION['staff_id'] ?? null;
$restaurant_id = $_SESSION['restaurant_id'] ?? null;

if (!$staff_id || !$restaurant_id) {
    die("Error: Staff information not found in session. Please login again.");
}

// ดึงข้อมูล reservations พร้อมหมายเลขโต๊ะ
$sql = "SELECT 
            r.reserve_id,
            r.user_id,
            r.reserve_date,
            r.party_size,
            r.reserve_status,
            r.claim_deadline_at,
            r.created_at,
            u.firstname,
            u.lastname,
            u.email,
            res.rest_name,
            GROUP_CONCAT(t.table_number ORDER BY t.table_number SEPARATOR ', ') as table_numbers
        FROM reservation r
        INNER JOIN user u ON r.user_id = u.user_id
        INNER JOIN restaurant res ON r.restaurant_id = res.restaurant_id
        LEFT JOIN reservation_tables rt ON r.reserve_id = rt.reserve_id
        LEFT JOIN `table` t ON rt.table_id = t.table_id
        WHERE r.restaurant_id = ?
        GROUP BY r.reserve_id
        ORDER BY r.reserve_date DESC, r.created_at DESC";

$stmt = $mysqli->prepare($sql);
$stmt->bind_param("i", $restaurant_id);
$stmt->execute();
$result = $stmt->get_result();
$reservations = [];
while ($row = $result->fetch_assoc()) {
    $reservations[] = $row;
}
$stmt->close();
?>

<style>
/* Booking Page Styles */
.booking-container {
  max-width: 100%;
}

.booking-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 20px;
  padding-bottom: 16px;
  border-bottom: 2px solid #f0e6ff;
}

.booking-header h2 {
  margin: 0;
  color: #6b2b6b;
  font-size: 24px;
}

.header-actions {
  display: flex;
  gap: 12px;
  align-items: center;
}

.search-input {
  padding: 10px 16px;
  border: 2px solid #e8d3f2;
  border-radius: 8px;
  width: 300px;
  font-size: 14px;
  transition: border-color 0.2s;
}

.search-input:focus {
  outline: none;
  border-color: #a76de6;
}

.filter-select {
  padding: 10px 16px;
  border: 2px solid #e8d3f2;
  border-radius: 8px;
  font-size: 14px;
  background: white;
  cursor: pointer;
  transition: border-color 0.2s;
}

.filter-select:focus {
  outline: none;
  border-color: #a76de6;
}

.table-wrapper {
  overflow-x: auto;
}

.status-badge {
  display: inline-block;
  padding: 6px 12px;
  border-radius: 12px;
  font-size: 12px;
  font-weight: 600;
  text-transform: uppercase;
}

.status-badge.status-hold {
  background: #fff3b0;
  color: #856404;
}

.status-badge.status-confirmed {
  background: #d4edda;
  color: #155724;
}

.status-badge.status-cancelled {
  background: #f8d7da;
  color: #721c24;
}

.party-badge {
  background: #e8f5ff;
  padding: 4px 10px;
  border-radius: 8px;
  font-size: 12px;
  font-weight: 600;
  color: #0066cc;
}

.table-badge {
  background: #f0e6ff;
  padding: 4px 10px;
  border-radius: 8px;
  font-size: 12px;
  font-weight: 600;
  color: #6b2b6b;
}

.action-cell {
  display: flex;
  gap: 8px;
  align-items: center;
}

.btn-action {
  padding: 8px 14px;
  border: none;
  border-radius: 8px;
  font-size: 13px;
  font-weight: 600;
  cursor: pointer;
  transition: all 0.2s;
}

.btn-action:hover {
  transform: translateY(-2px);
  box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
}

.btn-confirm {
  background: #7fe463ff;
  color: white;
}

.btn-confirm:hover {
  background: #31b938ff;
}

.btn-cancel {
  background: #e66d78ff;
  color: white;
}

.btn-cancel:hover {
  background: #de4136ff;
}

.status-text {
  padding: 6px 12px;
  border-radius: 8px;
  font-size: 13px;
  font-weight: 600;
}

.status-text.confirmed {
  background: #d4edda;
  color: #3bbe5aff;
}

.status-text.cancelled {
  background: #f8d7da;
  color: #ce5460ff;
}

/* Success Toast Message */
.success-toast {
  position: fixed;
  top: 20px;
  right: 20px;
  background: #4caf50;
  color: white;
  padding: 16px 24px;
  border-radius: 12px;
  box-shadow: 0 8px 24px rgba(0, 0, 0, 0.2);
  font-weight: 600;
  font-size: 14px;
  z-index: 10000;
  opacity: 0;
  transform: translateX(400px);
  transition: all 0.3s ease;
}

.success-toast.show {
  opacity: 1;
  transform: translateX(0);
}

/* Responsive */
@media (max-width: 768px) {
  .booking-header {
    flex-direction: column;
    align-items: flex-start;
    gap: 12px;
  }
  
  .header-actions {
    width: 100%;
    flex-direction: column;
  }
  
  .search-input,
  .filter-select {
    width: 100%;
  }
  
  .action-cell {
    flex-direction: column;
  }
}
</style>

<div class="booking-container">
  <div class="card">
    <div class="booking-header">
      <h2>Bookings Management</h2>
      <div class="header-actions">
        <input 
          type="text" 
          id="searchInput" 
          class="search-input" 
          placeholder="Search by customer name..." 
          onkeyup="searchBookings()"
        >
        <select id="filterStatus" class="filter-select" onchange="filterByStatus()">
          <option value="">All Status</option>
          <option value="hold">Hold</option>
          <option value="confirmed">Confirmed</option>
          <option value="cancelled">Cancelled</option>
        </select>
      </div>
    </div>

    <div class="table-wrapper">
      <table class="table">
        <thead>
          <tr>
            <th>Reserve ID</th>
            <th>Customer Name</th>
            <th>Email</th>
            <th>Restaurant</th>
            <th>Date</th>
            <th>Deadline Time</th>
            <th>Party Size</th>
            <th>Tables</th>
            <th>Status</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody id="bookingsTableBody">
          <?php if (empty($reservations)): ?>
            <tr>
              <td colspan="10" style="text-align: center; padding: 40px; color: #999;">
                No reservations found
              </td>
            </tr>
          <?php else: ?>
            <?php foreach($reservations as $reservation): 
              $fullName = htmlspecialchars($reservation['firstname'] . ' ' . $reservation['lastname']);
              $statusClass = strtolower($reservation['reserve_status']);
              
              // แปลงเวลา claim_deadline_at ให้อ่านง่าย
              $deadlineTime = '';
              if ($reservation['claim_deadline_at']) {
                $dt = new DateTime($reservation['claim_deadline_at']);
                $deadlineTime = $dt->format('H:i');
              }
            ?>
            <tr 
              id="booking-row-<?= $reservation['reserve_id'] ?>" 
              class="booking-row"
              data-customer-name="<?= strtolower($fullName) ?>"
              data-status="<?= $statusClass ?>"
            >
              <td class="reserve-id">#<?= $reservation['reserve_id'] ?></td>
              <td class="customer-name">
                <strong><?= $fullName ?></strong>
              </td>
              <td class="customer-email"><?= htmlspecialchars($reservation['email']) ?></td>
              <td><?= htmlspecialchars($reservation['rest_name']) ?></td>
              <td class="reserve-date">
                <?= date('M d, Y', strtotime($reservation['reserve_date'])) ?>
              </td>
              <td class="deadline-time">
                <?= $deadlineTime ?>
              </td>
              <td class="party-size">
                <span class="party-badge"><?= $reservation['party_size'] ?> people</span>
              </td>
              <td class="table-numbers">
                <span class="table-badge"><?= $reservation['table_numbers'] ?: 'N/A' ?></span>
              </td>
              <td>
                <span class="status-badge status-<?= $statusClass ?>" id="status-<?= $reservation['reserve_id'] ?>">
                  <?= htmlspecialchars($reservation['reserve_status']) ?>
                </span>
              </td>
              <td class="action-cell">
                <?php if($reservation['reserve_status'] == 'HOLD'): ?>
                  <button 
                    class="btn-action btn-confirm" 
                    onclick="updateReservationStatus(<?= $reservation['reserve_id'] ?>, 'CONFIRMED')"
                    title="Confirm this reservation"
                  >
                    ✓ Confirm
                  </button>
                  <button 
                    class="btn-action btn-cancel" 
                    onclick="updateReservationStatus(<?= $reservation['reserve_id'] ?>, 'CANCELLED')"
                    title="Cancel this reservation"
                  >
                    ✕ Cancel
                  </button>
                <?php elseif($reservation['reserve_status'] == 'CONFIRMED'): ?>
                  <span class="status-text confirmed">✓ Confirmed</span>
                <?php else: ?>
                  <span class="status-text cancelled">✕ Cancelled</span>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<script>
// Pass staff_id to JavaScript
const STAFF_ID = <?= $staff_id ?>;

// ฟังก์ชันค้นหา bookings ตามชื่อลูกค้า
function searchBookings() {
  const input = document.getElementById('searchInput');
  const filter = input.value.toLowerCase();
  const rows = document.getElementsByClassName('booking-row');
  
  for (let row of rows) {
    const customerName = row.getAttribute('data-customer-name');
    if (customerName.includes(filter)) {
      row.style.display = '';
    } else {
      row.style.display = 'none';
    }
  }
}

// ฟังก์ชันกรองตามสถานะ
function filterByStatus() {
  const select = document.getElementById('filterStatus');
  const filter = select.value.toLowerCase();
  const rows = document.getElementsByClassName('booking-row');
  
  for (let row of rows) {
    const status = row.getAttribute('data-status');
    if (filter === '' || status === filter) {
      row.style.display = '';
    } else {
      row.style.display = 'none';
    }
  }
}

// ฟังก์ชันอัพเดทสถานะ reservation
async function updateReservationStatus(reserveId, newStatus) {
  const actionText = newStatus === 'CONFIRMED' ? 'confirm' : 'cancel';
  
  if (!confirm(`Are you sure you want to ${actionText} this reservation?`)) {
    return;
  }
  
  const formData = new FormData();
  formData.append('reserve_id', reserveId);
  formData.append('reserve_status', newStatus);
  formData.append('staff_id', STAFF_ID);
  
  try {
    const response = await fetch('update_reservation_status.php', {
      method: 'POST',
      body: formData
    });
    
    const result = await response.json();
    
    if (result.success) {
      // อัพเดท badge สถานะ
      const statusBadge = document.getElementById('status-' + reserveId);
      statusBadge.textContent = newStatus;
      statusBadge.className = 'status-badge status-' + newStatus.toLowerCase();
      
      // อัพเดท action buttons
      const row = document.getElementById('booking-row-' + reserveId);
      const actionCell = row.querySelector('.action-cell');
      
      if (newStatus === 'CONFIRMED') {
        actionCell.innerHTML = '<span class="status-text confirmed">✓ Confirmed</span>';
      } else if (newStatus === 'CANCELLED') {
        actionCell.innerHTML = '<span class="status-text cancelled">✕ Cancelled</span>';
      }
      
      // อัพเดท data-status attribute
      row.setAttribute('data-status', newStatus.toLowerCase());
      
      // แสดงข้อความสำเร็จ
      showSuccessMessage(`Reservation ${actionText}ed successfully!`);
    } else {
      alert('Error: ' + (result.error || 'Failed to update reservation status'));
    }
  } catch (error) {
    console.error('Error:', error);
    alert('An error occurred while updating the reservation.');
  }
}

// แสดงข้อความสำเร็จ
function showSuccessMessage(message) {
  // ลบข้อความเก่าถ้ามี
  const existingMsg = document.querySelector('.success-toast');
  if (existingMsg) {
    existingMsg.remove();
  }
  
  // สร้างข้อความใหม่
  const toast = document.createElement('div');
  toast.className = 'success-toast';
  toast.textContent = message;
  document.body.appendChild(toast);
  
  // แสดง toast
  setTimeout(() => toast.classList.add('show'), 100);
  
  // ซ่อน toast หลัง 3 วินาที
  setTimeout(() => {
    toast.classList.remove('show');
    setTimeout(() => toast.remove(), 300);
  }, 3000);
}

// เพิ่ม keyboard shortcut สำหรับ search
document.getElementById('searchInput').addEventListener('keyup', function(e) {
  if (e.key === 'Escape') {
    this.value = '';
    searchBookings();
  }
});
</script>