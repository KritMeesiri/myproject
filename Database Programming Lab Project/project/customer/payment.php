<?php
// ✅ เปิด Error Display เพื่อ Debug
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

session_start();
require_once '../connect.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login/login.php');
    exit();
}

// ตรวจสอบว่ามีการ submit form หรือไม่
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['payment_method'])) {
    $user_id = $_SESSION['user_id'];
    $payment_method = $_POST['payment_method'];
    
    // รับข้อมูลจาก POST
    $restaurant_id = $_POST['restaurant_id'];
    $table_ids = $_POST['table_ids'];
    $tables_count = $_POST['tables_count'];
    $total_price = $_POST['total_price'];
    $party_size = $_POST['party_size'];
    
    // ✅ จองได้เฉพาะวันนี้เท่านั้น
    $reserve_date = date('Y-m-d');
    
    try {
        // แปลง table_ids จาก string เป็น array
        $table_ids_array = explode(',', $table_ids);
        
        // ✅ กรอง table_ids ที่ซ้ำออก และเอาช่องว่างออก
        $table_ids_array = array_unique(array_filter(array_map('trim', $table_ids_array)));
        
        // แปลงกลับเป็น string
        $table_ids = implode(',', $table_ids_array);
        
        // ✅ เรียกใช้ Procedure pay_and_hold
        // Procedure จะสร้าง reservation + payment โดยอัตโนมัติ
        // Triggers จะทำงานอัตโนมัติ:
        // - reservation_deadline_calc: คำนวณ claim_deadline_at
        // - reservation_booked_noti: สร้าง notification
        // - reservation_age_guard: เช็คอายุ
        // - reservation_day_guard: เช็ควันที่
        
        $sql_procedure = "CALL pay_and_hold(?, ?, ?, ?, ?, ?, ?)";
        $stmt_procedure = $mysqli->prepare($sql_procedure);
        $stmt_procedure->bind_param(
            "iiissss",  // 7 parameters
            $user_id,
            $restaurant_id,
            $party_size,
            $reserve_date,
            $total_price,
            $payment_method,
            $table_ids
        );
        
        if (!$stmt_procedure->execute()) {
            $error_msg = $stmt_procedure->error;
            $stmt_procedure->close();
            throw new Exception("Failed to create reservation: " . $error_msg);
        }
        $stmt_procedure->close();
        
        // ดึง reserve_id ที่สร้างจาก procedure ผ่าน LAST_INSERT_ID()
        $result = $mysqli->query("SELECT LAST_INSERT_ID() as reserve_id");
        $row = $result->fetch_assoc();
        $reserve_id = $row['reserve_id'];
        
        if (!$reserve_id) {
            throw new Exception("Failed to get reservation ID");
        }
        
        // ✅ Procedure pay_and_hold จัดการโต๊ะให้หมดแล้ว
        // ไม่ต้อง INSERT ซ้ำอีก!
        
        // Redirect to booking detail
        header("Location: booking_detail.php?id=" . $reserve_id);
        exit();
        
    } catch (Exception $e) {
        // แสดง error message
        $error_message = $e->getMessage();
        error_log("Payment Error: " . $error_message);
        
        // ถ้าเป็นการจองซ้ำ ให้ไปหน้า my_bookings
        if (strpos($error_message, 'duplicate') !== false || strpos($error_message, 'already booked') !== false) {
            header("Location: my_bookings.php?error=" . urlencode("Table already booked"));
            exit();
        }
        
        // กรณีอื่นๆ แสดง error
        // ไม่ redirect ให้แสดง error ในหน้าเดิม
    }
}

// รับข้อมูลจาก URL
$restaurant_id = $_GET['restaurant_id'] ?? 1;
$table_ids = $_GET['table_ids'] ?? '';
$tables_count = $_GET['tables'] ?? 0;
$zones = $_GET['zones'] ?? 'N/A';
$total_price = $_GET['price'] ?? 0;
$party_size = $_GET['party_size'] ?? 0;
$venue_name = $_GET['venue'] ?? 'Restaurant';

// ✅ จองได้เฉพาะวันนี้เท่านั้น
$reserve_date = date('Y-m-d');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment - <?php echo htmlspecialchars($venue_name); ?></title>
    <link rel="stylesheet" href="css/payment.css">
    <link rel="stylesheet" href="css/notification.css">
</head>
<body class="payment-body">
    <!-- Navbar -->
    <div class="payment-header">
        <div class="payment-logo">
            <img src="css/catlog.png"  alt="cat"style="height:36px;position: relative; top: -18px;left:-70px;object-fit:contain"> <img src="css/logo.png" alt="logo" style="height:36px;position: relative;left:-490px;object-fit:contain"/> 
            
            <div class="top-icons-payment">
                <span class="icon-small" id="notificationBtn" style="cursor: pointer; position: relative;">🔔</span>
                <span class="icon-small" onclick="window.location.href='my_bookings.php'" style="cursor: pointer;" title="My Bookings">📅</span>
                <span class="icon-small" onclick="window.location.href='profile.php'" style="cursor: pointer;" title="Profile">
                    <img src="css/druckcat_profile.jpeg" alt="drunkcat" style="height:28px;border-radius:22px; object-fit:contain;">
                </span>
                <a href="../login/logout.php" class="btn-logout-icon">Logout</a>
            </div>
        </div>
    </div>

    <!-- Payment Container -->
    <div class="payment-container">
        <h2 class="payment-title">Select Payment Method</h2>
        
        <?php if (isset($error_message)): ?>
        <div style="background: #f44336; color: white; padding: 15px; border-radius: 10px; margin-bottom: 20px;">
            ❌ <?php echo htmlspecialchars($error_message); ?>
        </div>
        <?php endif; ?>
        
        <!-- Amount Display -->
        <div class="payment-amount">
            <span class="currency-symbol">฿</span>
            <span class="amount-value"><?php echo number_format($total_price); ?></span>
        </div>

        <div style="text-align: center; color: #ffd700; margin-bottom: 20px; font-size: 14px;">
            * This is a deposit amount. Full payment at the venue.
        </div>

        <!-- Payment Method Selection -->
        <form method="POST" id="paymentForm">
            <input type="hidden" name="restaurant_id" value="<?php echo $restaurant_id; ?>">
            <input type="hidden" name="table_ids" value="<?php echo htmlspecialchars($table_ids); ?>">
            <input type="hidden" name="tables_count" value="<?php echo $tables_count; ?>">
            <input type="hidden" name="total_price" value="<?php echo $total_price; ?>">
            <input type="hidden" name="party_size" value="<?php echo $party_size; ?>">
            <input type="hidden" name="reserve_date" value="<?php echo htmlspecialchars($reserve_date); ?>">
            <input type="hidden" name="payment_method" id="paymentMethodInput" value="">
            
            <div class="payment-methods">
                <div class="payment-method-card" data-method="Credit Card">
                    <div class="payment-method-icon">💳</div>
                    <div class="payment-method-name">Credit Card</div>
                </div>
                <div class="payment-method-card" data-method="PayPal">
                    <div class="payment-method-icon">🅿️</div>
                    <div class="payment-method-name">PayPal</div>
                </div>
                <div class="payment-method-card" data-method="Qrcode">
                    <div class="payment-method-icon">📱</div>
                    <div class="payment-method-name">QR Code</div>
                </div>
            </div>

            <!-- Payment Forms -->
            
            <!-- Credit Card Form -->
            <div class="payment-form-section" id="creditcardForm" style="display: none;">
                <h3 class="form-title">Credit Card Information</h3>
                <div class="form-group">
                    <label>Card Number</label>
                    <input type="text" class="form-input" placeholder="1234 5678 9012 3456" maxlength="19">
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Expiry Date</label>
                        <input type="text" class="form-input" placeholder="MM/YY" maxlength="5">
                    </div>
                    <div class="form-group">
                        <label>CVV</label>
                        <input type="text" class="form-input" placeholder="123" maxlength="3">
                    </div>
                </div>
                <div class="form-group">
                    <label>Cardholder Name</label>
                    <input type="text" class="form-input" placeholder="John Doe">
                </div>
                <button type="button" class="btn-pay-now" onclick="confirmPayment('Credit Card')">
                    Pay Now
                </button>
            </div>

            <!-- PayPal Form -->
            <div class="payment-form-section" id="paypalForm" style="display: none;">
                <h3 class="form-title">PayPal Login</h3>
                <div class="paypal-info">
                    <p>You will be redirected to PayPal to complete your payment securely.</p>
                </div>
                <div class="form-group">
                    <label>PayPal Email</label>
                    <input type="email" class="form-input" placeholder="your@email.com">
                </div>
                <button type="button" class="btn-pay-now btn-paypal" onclick="confirmPayment('PayPal')">
                    Continue with PayPal
                </button>
            </div>

            <!-- QR Code Section -->
            <div class="payment-form-section" id="qrcodeForm" style="display: none;">
                <h3 class="form-title">Scan to Pay</h3>
                <div class="qr-code-section">
                    <div class="qr-code-wrapper" id="qrCodeWrapper">
                        <!-- QR Code จะถูกสร้างที่นี่ -->
                    </div>
                </div>
                <div class="qr-instructions">
                    <p>📱 Scan this QR code with your mobile banking app</p>
                    <p>✓ Complete the payment in your banking app</p>
                    <p>🔘 Click "Complete Payment" button below when done</p>
                </div>
                <button type="button" class="btn-pay-now" onclick="confirmPayment('Qrcode')">
                    Complete Payment
                </button>
            </div>
        </form>

        <!-- Booking Details Summary -->
        <div class="payment-details-box">
            <h3 class="payment-details-title">Booking Summary</h3>
            <div class="payment-detail-row">
                <span class="payment-detail-label">Venue:</span>
                <span class="payment-detail-value"><?php echo htmlspecialchars($venue_name); ?></span>
            </div>
            <div class="payment-detail-row">
                <span class="payment-detail-label">Reservation Date:</span>
                <span class="payment-detail-value"><?php echo htmlspecialchars($reserve_date); ?></span>
            </div>
            <div class="payment-detail-row">
                <span class="payment-detail-label">Zones:</span>
                <span class="payment-detail-value"><?php echo htmlspecialchars($zones); ?></span>
            </div>
            <div class="payment-detail-row">
                <span class="payment-detail-label">Tables:</span>
                <span class="payment-detail-value"><?php echo $tables_count; ?> tables</span>
            </div>
            <div class="payment-detail-row">
                <span class="payment-detail-label">Party Size:</span>
                <span class="payment-detail-value"><?php echo $party_size; ?> people</span>
            </div>
            <div class="payment-detail-row payment-total-row">
                <span class="payment-detail-label">Deposit Amount:</span>
                <span class="payment-detail-value payment-price-highlight"><?php echo number_format($total_price); ?> THB</span>
            </div>
        </div>

        <!-- Cancel Button -->
        <button class="btn-payment-cancel" onclick="history.back()">
            ← Back
        </button>
    </div>

    <!-- QR Code Generator Library -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
    
    <script>
        let selectedMethod = null;
        let qrCodeGenerated = false;

        // Payment method selection
        const methodCards = document.querySelectorAll('.payment-method-card');
        methodCards.forEach(card => {
            card.addEventListener('click', function() {
                // Remove active class from all cards
                methodCards.forEach(c => c.classList.remove('active'));
                
                // Add active class to selected card
                this.classList.add('active');
                
                // Get selected method
                selectedMethod = this.dataset.method;
                
                // Hide all forms
                document.getElementById('creditcardForm').style.display = 'none';
                document.getElementById('paypalForm').style.display = 'none';
                document.getElementById('qrcodeForm').style.display = 'none';
                
                // Show selected form
                if (selectedMethod === 'Credit Card') {
                    document.getElementById('creditcardForm').style.display = 'block';
                } else if (selectedMethod === 'PayPal') {
                    document.getElementById('paypalForm').style.display = 'block';
                } else if (selectedMethod === 'Qrcode') {
                    document.getElementById('qrcodeForm').style.display = 'block';
                    
                    // สร้าง QR Code (เฉพาะครั้งแรก)
                    if (!qrCodeGenerated) {
                        generateQRCode();
                        qrCodeGenerated = true;
                    }
                }
            });
        });

        // สร้าง QR Code
        function generateQRCode() {
            const qrCodeData = `AMT:<?php echo htmlspecialchars($total_price, ENT_QUOTES, 'UTF-8'); ?>|VENUE:<?php echo htmlspecialchars($venue_name, ENT_QUOTES, 'UTF-8'); ?>|DATE:<?php echo htmlspecialchars($reserve_date, ENT_QUOTES, 'UTF-8'); ?>`;
            
            const qrcode = new QRCode(document.getElementById("qrCodeWrapper"), {
                text: qrCodeData,
                width: 200,
                height: 200,
                colorDark: "#000000",
                colorLight: "#ffffff",
                correctLevel: QRCode.CorrectLevel.H
            });
        }

        // ยืนยันการชำระเงิน
        let isSubmitting = false; // ป้องกันการ submit ซ้ำ
        
        function confirmPayment(method) {
            // ป้องกันการกดซ้ำ
            if (isSubmitting) {
                console.log('Payment is already being processed...');
                return false;
            }
            
            if (!selectedMethod) {
                alert('Please select a payment method');
                return false;
            }

            // ล็อกการ submit
            isSubmitting = true;
            
            // แสดง loading
            const button = event.target;
            const originalText = button.textContent;
            button.disabled = true;
            button.textContent = 'Processing...';
            
            // Disable ปุ่มทั้งหมด
            document.querySelectorAll('.btn-pay-now').forEach(btn => {
                btn.disabled = true;
                btn.style.opacity = '0.5';
                btn.style.cursor = 'not-allowed';
            });
            
            // ตั้งค่า payment method และ submit form
            document.getElementById('paymentMethodInput').value = method;
            document.getElementById('paymentForm').submit();
            
            return false;
        }
    </script>

    <?php include 'notification_widget.php'; ?>
</body>
</html>