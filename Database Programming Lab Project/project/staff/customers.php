<?php
require_once '../connect.php';

// 🔥 NEW: ใช้ restaurant_id จาก session
$restaurant_id = $_SESSION['restaurant_id'] ?? null;

if (!$restaurant_id) {
    die("Error: Restaurant ID not found in session. Please login again.");
}

// ดึงข้อมูล customers ที่เคยจองร้านนี้ พร้อมนับจำนวน reservations และยอดเงินรวม
$sql = "SELECT 
            u.user_id,
            u.firstname,
            u.lastname,
            u.email,
            u.user_phone,
            COUNT(DISTINCT r.reserve_id) as total_reservations,
            COALESCE(SUM(p.payment_amount), 0) as total_credit
        FROM user u
        INNER JOIN reservation r ON u.user_id = r.user_id
        LEFT JOIN payment p ON r.reserve_id = p.reserve_id AND p.payment_status = 'PAID'
        WHERE u.role = 'CUSTOMER'
        AND u.is_active = 'Y'
        AND r.restaurant_id = ?
        GROUP BY u.user_id, u.firstname, u.lastname, u.email, u.user_phone
        ORDER BY total_reservations DESC, u.firstname ASC";

$stmt = $mysqli->prepare($sql);
$stmt->bind_param("i", $restaurant_id);
$stmt->execute();
$result = $stmt->get_result();
$customers = [];
while ($row = $result->fetch_assoc()) {
    $customers[] = $row;
}
$stmt->close();
?>

<!-- เชื่อม CSS ของหน้า Customers -->
<link rel="stylesheet" href="customers_styles.css">

<div class="customers-container">
  <!-- Header Section -->
  <div class="customers-header">
    <h2>Customers</h2>
  </div>

  <!-- Search Box -->
  <div class="search-section">
    <input 
      type="text" 
      id="searchInput" 
      class="customers-search" 
      placeholder="Search customers..." 
      onkeyup="searchCustomers()"
    >
  </div>

  <!-- Customer List -->
  <div class="customers-list">
    <?php if (empty($customers)): ?>
      <div class="no-customers">
        <p>No customers with reservations found.</p>
      </div>
    <?php else: ?>
      <?php foreach($customers as $customer): 
        // สร้าง initials สำหรับ avatar
        $initials = strtoupper(substr($customer['firstname'], 0, 1) . substr($customer['lastname'], 0, 1));
        
        // สร้างสีสุ่มสำหรับ avatar แต่ละคน (ตาม user_id)
        $colors = ['#ffb3ba', '#ffdfba', '#ffffba', '#baffc9', '#bae1ff', '#e0bbff', '#ffccf9'];
        $avatarColor = $colors[$customer['user_id'] % count($colors)];
      ?>
        <div class="customer-card" data-customer-name="<?= strtolower($customer['firstname'] . ' ' . $customer['lastname']) ?>">
          <!-- Avatar -->
          <div class="customer-avatar" style="background-color: <?= $avatarColor ?>">
            <span><?= $initials ?></span>
          </div>

          <!-- Customer Info -->
          <div class="customer-info">
            <div class="customer-name">
              <?= htmlspecialchars($customer['firstname'] . ' ' . $customer['lastname']) ?>
            </div>
            <div class="customer-contact">
              <span class="contact-email"><?= htmlspecialchars($customer['email']) ?></span>
              <span class="contact-separator">&nbsp;&nbsp;</span>
              <span class="contact-phone"><?= htmlspecialchars($customer['user_phone']) ?></span>
            </div>
          </div>

          <!-- Stats -->
          <div class="customer-stats">
            <div class="stat-item">
              <div class="stat-value"><?= $customer['total_reservations'] ?> reservations</div>
            </div>
            <div class="stat-item">
              <div class="stat-credit"><?= number_format($customer['total_credit'], 0) ?> ฿</div>
            </div>
          </div>

          <!-- Action Button -->
          <div class="customer-actions">
            <button 
              class="btn-view-details" 
              onclick="viewCustomerDetails(<?= $customer['user_id'] ?>, '<?= htmlspecialchars($customer['firstname'] . ' ' . $customer['lastname']) ?>')">
              View Details
            </button>
          </div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>

<!-- Modal สำหรับแสดงรายละเอียด Customer -->
<div id="customerModal" class="customer-modal">
  <div class="customer-modal-content">
    <span class="customer-modal-close" onclick="closeCustomerModal()">&times;</span>
    <h3 id="modalCustomerName">Customer Details</h3>
    <div id="modalCustomerReservations">
      <p>Loading...</p>
    </div>
  </div>
</div>

<script>
// ฟังก์ชันค้นหา customers
function searchCustomers() {
  const input = document.getElementById('searchInput');
  const filter = input.value.toLowerCase();
  const cards = document.querySelectorAll('.customer-card');
  
  cards.forEach(card => {
    const name = card.getAttribute('data-customer-name');
    if (name.includes(filter)) {
      card.style.display = 'flex';
    } else {
      card.style.display = 'none';
    }
  });
}

// ฟังก์ชันแสดงรายละเอียด customer
async function viewCustomerDetails(userId, customerName) {
  const modal = document.getElementById('customerModal');
  const modalTitle = document.getElementById('modalCustomerName');
  const modalContent = document.getElementById('modalCustomerReservations');
  
  modalTitle.textContent = customerName + ' - Reservations';
  modalContent.innerHTML = '<p style="text-align:center; color:#999;">Loading...</p>';
  
  modal.style.display = 'block';
  
  try {
    // เรียก API เพื่อดึงข้อมูล reservations
    const response = await fetch('get_customer_reservations.php?user_id=' + userId);
    const data = await response.json();
    
    if (data.success && data.reservations.length > 0) {
      let html = '<div class="reservations-list">';
      
      data.reservations.forEach(reservation => {
        const statusClass = reservation.reserve_status.toLowerCase();
        html += `
          <div class="reservation-item">
            <div class="reservation-header">
              <span class="reservation-id">#${reservation.reserve_id}</span>
              <span class="reservation-status status-${statusClass}">${reservation.reserve_status}</span>
            </div>
            <div class="reservation-details">
              <p><strong>Restaurant:</strong> ${reservation.rest_name}</p>
              <p><strong>Date:</strong> ${reservation.reserve_date}</p>
              <p><strong>Party Size:</strong> ${reservation.party_size} people</p>
              <p><strong>Tables:</strong> ${reservation.table_numbers || 'N/A'}</p>
              <p><strong>Deposit:</strong> ${parseFloat(reservation.reserve_deposit).toLocaleString()} ฿</p>
              <p><strong>Payment Status:</strong> ${reservation.payment_status || 'N/A'}</p>
            </div>
          </div>
        `;
      });
      
      html += '</div>';
      modalContent.innerHTML = html;
    } else {
      modalContent.innerHTML = '<p style="text-align:center; color:#999;">No reservations found.</p>';
    }
  } catch (error) {
    console.error('Error:', error);
    modalContent.innerHTML = '<p style="text-align:center; color:#f44336;">Error loading reservations.</p>';
  }
}

// ฟังก์ชันปิด modal
function closeCustomerModal() {
  document.getElementById('customerModal').style.display = 'none';
}

// ปิด modal เมื่อคลิกข้างนอก
window.onclick = function(event) {
  const modal = document.getElementById('customerModal');
  if (event.target == modal) {
    closeCustomerModal();
  }
}
</script>