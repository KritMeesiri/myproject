<?php
require '../connect.php';

// 🔥 NEW: ใช้ restaurant_id จาก session
$restaurant_id = $_SESSION['restaurant_id'] ?? null;

if (!$restaurant_id) {
    die("Error: Restaurant ID not found in session. Please login again.");
}

$sql = "SELECT restaurant_id, rest_name, open_time, close_time 
        FROM restaurant 
        WHERE restaurant_id = ?";
$stmt = $mysqli->prepare($sql);
$stmt->bind_param("i", $restaurant_id);
$stmt->execute();
$result = $stmt->get_result();
$restaurant = $result->fetch_assoc();
$stmt->close();

if (!$restaurant) {
    echo "Restaurant not found";
    exit;
}
?>

<!-- เชื่อม CSS สำหรับหน้า Settings -->
<link rel="stylesheet" href="setting_style.css">

<div class="settings-container">
  <!-- Opening Hours Section -->
  <div class="card settings-card">
    <div class="settings-header">
      <h3>Opening Hours</h3>
      <p class="settings-subtitle">Set your restaurant's operating hours</p>
    </div>

    <form id="openingHoursForm" class="settings-form">
      <input type="hidden" name="restaurant_id" value="<?= $restaurant_id ?>">
      
      <div class="time-inputs">
        <div class="input-group">
          <label for="open_time">Start Time</label>
          <input 
            type="time" 
            id="open_time" 
            name="open_time" 
            class="time-input"
            value="<?= htmlspecialchars($restaurant['open_time']) ?>"
            required
          >
        </div>

        <div class="input-group">
          <label for="close_time">End Time</label>
          <input 
            type="time" 
            id="close_time" 
            name="close_time" 
            class="time-input"
            value="<?= htmlspecialchars($restaurant['close_time']) ?>"
            required
          >
        </div>
      </div>

      <button type="submit" class="btn-save">Save Changes</button>
    </form>
  </div>

  <!-- Payment Methods Section -->
  <div class="card settings-card">
    <div class="settings-header">
      <h3>Payment Methods</h3>
      <p class="settings-subtitle">Configure accepted payment methods</p>
    </div>

    <form id="paymentMethodsForm" class="settings-form">
      <div class="payment-options">
        <label class="payment-checkbox">
          <input type="checkbox" name="credit_card" checked>
          <span class="checkmark"></span>
          <span class="payment-label">Credit Card</span>
        </label>

        <label class="payment-checkbox">
          <input type="checkbox" name="paypal">
          <span class="checkmark"></span>
          <span class="payment-label">PayPal</span>
        </label>

        <label class="payment-checkbox">
          <input type="checkbox" name="qrcode" checked>
          <span class="checkmark"></span>
          <span class="payment-label">QR Code</span>
        </label>
      </div>

      <button type="submit" class="btn-save">Save Changes</button>
    </form>
  </div>
</div>

<!-- Success Message -->
<div id="successMessage" class="success-message">
  <span id="successText">Settings saved successfully!</span>
</div>

<script>
// Handle Opening Hours Form Submission
document.getElementById('openingHoursForm').addEventListener('submit', async function(e) {
  e.preventDefault();
  
  const formData = new FormData(this);
  const submitBtn = this.querySelector('.btn-save');
  
  // Disable button during submission
  submitBtn.disabled = true;
  submitBtn.textContent = 'Saving...';
  
  try {
    const response = await fetch('update_opening_hours.php', {
      method: 'POST',
      body: formData
    });
    
    const result = await response.json();
    
    if (result.success) {
      showSuccessMessage('Opening hours updated successfully!');
    } else {
      alert('Error: ' + (result.error || 'Failed to update opening hours'));
    }
  } catch (error) {
    console.error('Error:', error);
    alert('An error occurred while saving. Please try again.');
  } finally {
    submitBtn.disabled = false;
    submitBtn.textContent = 'Save Changes';
  }
});

// Handle Payment Methods Form Submission (Demo Mode)
document.getElementById('paymentMethodsForm').addEventListener('submit', function(e) {
  e.preventDefault();
  alert('Sorry, this is demo');
});

// Show success message function
function showSuccessMessage(message) {
  const successMsg = document.getElementById('successMessage');
  const successText = document.getElementById('successText');
  
  successText.textContent = message;
  successMsg.classList.add('show');
  
  setTimeout(() => {
    successMsg.classList.remove('show');
  }, 3000);
}
</script>