<?php
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'ADMIN') {
    header("Location: ../login/login.php");
    exit();
}

require_once('../connect.php');
$conn = $mysqli;

// Handle Logout  
if (isset($_GET['logout'])) {
    // บันทึก logout_history
    $logout_sql = "INSERT INTO logout_history (user_id, logout_at) VALUES (?, NOW())";
    $logout_stmt = $conn->prepare($logout_sql);
    $logout_stmt->bind_param("i", $_SESSION['user_id']);
    $logout_stmt->execute();
    $logout_stmt->close();
    
    session_destroy();
    header("Location: ../login/login.php");
    exit();
}

// Stats
$stmt = $conn->query("SELECT COUNT(*) as total FROM restaurant");
$totalRestaurants = $stmt->fetch_assoc()['total'];

$stmt = $conn->query("SELECT COUNT(*) as total FROM user");
$totalUsers = $stmt->fetch_assoc()['total'];

// 3. นับจำนวน Staff ที่ active
$stmt = $conn->query("SELECT COUNT(*) as total FROM user WHERE UPPER(role) = 'STAFF' AND is_active = 'Y'");
$activeStaff = $stmt->fetch_assoc()['total'];

// 4. นับจำนวนผู้ใช้ที่ online อยู่ (is_active = 'Y')
$stmt = $conn->query("SELECT COUNT(*) as total FROM user WHERE is_active = 'Y'");
$currentlyOnline = $stmt->fetch_assoc()['total'];

// Get all staff with their restaurant
$staff_query = "SELECT u.user_id, u.firstname, u.lastname, u.email, 
                       s.staff_id, s.restaurant_id, r.rest_name
                FROM user u
                LEFT JOIN staff s ON u.user_id = s.user_id
                LEFT JOIN restaurant r ON s.restaurant_id = r.restaurant_id
                WHERE LOWER(u.role) = 'staff'
                ORDER BY u.firstname, u.lastname";
$staff_result = $conn->query($staff_query);

// Get all restaurants for dropdown
$restaurants_query = "SELECT restaurant_id, rest_name FROM restaurant ORDER BY rest_name";
$restaurants_result = $conn->query($restaurants_query);
$restaurants = [];
while ($row = $restaurants_result->fetch_assoc()) {
    $restaurants[] = $row;
}

// Handle Update Staff Restaurant
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_staff_restaurant'])) {
    $user_id = (int)$_POST['user_id'];
    $new_restaurant_id = (int)$_POST['restaurant_id'];
    
    $check_sql = "SELECT staff_id FROM staff WHERE user_id = ?";
    $check_stmt = $conn->prepare($check_sql);
    $check_stmt->bind_param("i", $user_id);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    
    if ($check_result->num_rows > 0) {
        $update_sql = "UPDATE staff SET restaurant_id = ? WHERE user_id = ?";
        $update_stmt = $conn->prepare($update_sql);
        $update_stmt->bind_param("ii", $new_restaurant_id, $user_id);
        $update_stmt->execute();
        $update_stmt->close();
    } else {
        $insert_sql = "INSERT INTO staff (user_id, restaurant_id) VALUES (?, ?)";
        $insert_stmt = $conn->prepare($insert_sql);
        $insert_stmt->bind_param("ii", $user_id, $new_restaurant_id);
        $insert_stmt->execute();
        $insert_stmt->close();
    }
    
    $check_stmt->close();
    header("Location: index.php?success=staff_updated");
    exit();
}


// Handle Add Restaurant
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_restaurant'])) {
    try {
        // ✅ แก้: ใช้ bind_param แทน execute() แบบ array
        $stmt = $conn->prepare("INSERT INTO restaurant (rest_name, rest_description, open_time, close_time, rest_phone, rest_status, rest_address, rest_email) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("ssssssss", 
            $_POST['rest_name'], 
            $_POST['rest_description'], 
            $_POST['open_time'], 
            $_POST['close_time'], 
            $_POST['rest_phone'], 
            $_POST['rest_status'], 
            $_POST['rest_address'], 
            $_POST['rest_email']
        );
        $stmt->execute();
        $stmt->close();
        header("Location: index.php?success=restaurant");
        exit();
    } catch(Exception $e) {
        $error_msg = "Error: " . $e->getMessage();
    }
}

// Handle Add User
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_user'])) {
    try {
        $role = strtoupper(trim($_POST['role']));
        // Auto set is_active: Staff = 'N' (รอ approve), อื่น ๆ = 'Y'
        $is_active = ($role === 'STAFF') ? 'N' : 'Y';
        
        // ✅ แก้: Hash password ด้วย SHA-256 เหมือน login_process.php
        $password = trim($_POST['password']);
        $hashed_password = hash('sha256', $password);
        
        $stmt = $conn->prepare("INSERT INTO user (email, username, password, firstname, lastname, dob, user_phone, role, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("sssssssss", 
            $_POST['email'], 
            $_POST['username'], 
            $hashed_password,  // ✅ ใช้ hashed password
            $_POST['firstname'], 
            $_POST['lastname'], 
            $_POST['dob'], 
            $_POST['user_phone'], 
            $role, 
            $is_active
        );
        
        if ($stmt->execute()) {
            if ($role === 'STAFF' && isset($_POST['restaurant_id']) && !empty($_POST['restaurant_id'])) {
                $new_user_id = $stmt->insert_id;
                $restaurant_id = (int)$_POST['restaurant_id'];
                $staff_stmt = $conn->prepare("INSERT INTO staff (user_id, restaurant_id) VALUES (?, ?)");
                $staff_stmt->bind_param("ii", $new_user_id, $restaurant_id);
                $staff_stmt->execute();
                $staff_stmt->close();
            }
            header("Location: index.php?success=user");
            exit();
        }
        $stmt->close();
    } catch(Exception $e) {
        $error_msg = "Error: " . $e->getMessage();
    }
}

// Handle Reset Password
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset_password'])) {
    $reset_username = trim($_POST['reset_username']);
    $new_password = trim($_POST['new_password']);
    $confirm_password = trim($_POST['confirm_password']);
    
    if ($new_password === $confirm_password) {
        // ✅ แก้: Hash password ด้วย SHA-256
        $hashed_password = hash('sha256', $new_password);
        
        $reset_stmt = $conn->prepare("UPDATE user SET password = ? WHERE username = ?");
        $reset_stmt->bind_param("ss", $hashed_password, $reset_username);  // ✅ ใช้ hashed password
        
        if ($reset_stmt->execute() && $reset_stmt->affected_rows > 0) {
            header("Location: index.php?success=password_reset");
            exit();
        } else {
            $error_msg = "Username not found!";
        }
        $reset_stmt->close();
    } else {
        $error_msg = "Passwords do not match!";
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Dashboard - Super Admin</title>
  <link rel="stylesheet" href="style.css">
  <style>
    .stats { display: flex; gap: 15px; margin-bottom: 20px; }
    .stat-box { flex: 1; background-color: white; border-radius: 12px; padding: 20px; text-align: center; font-weight: 600; color: white; box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
    .stat-box b { font-size: 32px; display: block; margin-top: 10px; }
    .pink { background-color: #FDC9FD; color: #000000ff; }
    .cyan { background-color: #C8F5FF; color: #000000ff; }
    .yellow { background-color: #FFF6B0; color: #333; }
    .green { background-color: #CCFFD9; color: #333; }
    
    .edit-restaurant-form { display: inline-flex; align-items: center; gap: 8px; }
    .restaurant-select { padding: 8px 12px; border: 2px solid #9a5ef5; border-radius: 20px; background: white; color: #5c2471; font-weight: 600; font-size: 12px; cursor: pointer; outline: none; min-width: 180px; }
    .restaurant-select:focus { border-color: #7a3ec5; }
    .update-btn { padding: 8px 16px; background: #65a5ffff; color: white; border: none; border-radius: 20px; font-weight: 600; font-size: 12px; cursor: pointer; transition: all 0.2s; text-transform: uppercase; letter-spacing: 0.5px; }
    .update-btn:hover { background: #44a3a0; transform: translateY(-1px); box-shadow: 0 2px 8px rgba(78, 205, 196, 0.3); }
    
    .action-buttons { display: flex; gap: 15px; margin-top: 20px; }
    .action-buttons button { background-color: #b97af9ff; border: none; color: white; font-weight: 600; font-size: 15px; padding: 30px 20px; border-radius: 10px; cursor: pointer; flex: 1; transition: background-color 0.3s ease; }
    .action-buttons button:hover { background-color: #702fd0ff; }
    
    .modal { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5); animation: fadeIn 0.3s; }
    @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
    .modal-content { background-color: #fff; margin: 5% auto; padding: 30px; border-radius: 15px; width: 80%; max-width: 600px; box-shadow: 0 5px 20px rgba(0,0,0,0.3); animation: slideDown 0.3s; max-height: 80vh; overflow-y: auto; }
    @keyframes slideDown { from { transform: translateY(-50px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
    .close { color: #aaa; float: right; font-size: 28px; font-weight: bold; cursor: pointer; transition: color 0.3s; }
    .close:hover { color: #5c2471; }
    .modal h2 { color: #5c2471; margin-top: 0; }
    .form-group { margin-bottom: 15px; }
    .form-group label { display: block; margin-bottom: 5px; color: #5c2471; font-weight: 600; }
    .form-group input, .form-group select, .form-group textarea { width: 100%; padding: 10px; border: 2px solid #d7b3f9; border-radius: 8px; font-size: 14px; transition: border-color 0.3s; box-sizing: border-box; }
    .form-group input:focus, .form-group select:focus, .form-group textarea:focus { outline: none; border-color: #9a5ef5; }
    .form-group textarea { resize: vertical; min-height: 80px; }
    .submit-btn { background-color: #b66dfd; color: white; border: none; padding: 12px 30px; border-radius: 8px; font-weight: 600; cursor: pointer; width: 100%; font-size: 16px; transition: background-color 0.3s; }
    .submit-btn:hover { background-color: #9a5ef5; }
    .success-message { background-color: #5dd35d; color: white; padding: 15px; border-radius: 8px; margin-bottom: 20px; text-align: center; font-weight: 600; }
    
    .logout-btn { display: flex; align-items: center; justify-content: center; gap: 8px; padding: 10px 28px; background: #ff4444; color: white; border: none; border-radius: 25px; text-decoration: none; font-size: 15px; font-weight: 600; transition: all 0.2s; }
    .logout-btn:hover { background-color: #ee5a52; }
    .profile-info { display: flex; align-items: center; gap: 10px; }
    .profile-details { text-align: right; }
    .profile-name { font-weight: 600; color: #5c2471; font-size: 14px; }
    .profile-email { font-size: 12px; color: #9a5ef5; }
    .corner-cat { position: fixed; top: 0px; left: 0px; width: 50px; z-index: 1500; pointer-events: none; transform: translate(-10px, -10px); }
    table td { vertical-align: middle; padding: 12px 15px; }
  </style>
</head>
<body>
  <img src="cf1.png" class="corner-cat" alt="cat">

  <div class="sidebar">
    <div class="logo"><img src="logo.png" alt="BrewlyChillInSeat Logo"></div>
    <div class="menu">
      <a href="index.php" class="active"><img src="homepage.png" alt="homepage" style="height:25px;border-radius:22px; object-fit:contain;position:relative; top:5px;"> Dashboard</a>
      <a href="restaurants.php"><img src="restaurant.png" alt="restaurant" style="height:25px;border-radius:22px; object-fit:contain;position:relative; top:5px; right:-2px;"> <span style="margin-left:8px;">Restaurants</span></a>
      <a href="users.php"><img src="usericon.png" alt="usericon" style="height:25px;border-radius:22px; object-fit:contain;position:relative; top:5px; right:-2px;"> <span style="margin-left:8px;">User</span></a>
      <a href="activity_log.php"><img src="time.png" alt="time" style="height:25px;border-radius:22px; object-fit:contain;position:relative; top:5px; right:-2px;"> <span style="margin-left:8px;">Activity Logs</span></a>
    </div>
  </div>

  <div class="main">
    <div class="header">
      <div class="title"></div>
      <div class="profile-info">
        <div class="profile">
          <div class="profile-details">
            <div class="profile-name"><?php echo htmlspecialchars($_SESSION['firstname'] . ' ' . $_SESSION['lastname']); ?></div>
            <div class="profile-email"><?php echo htmlspecialchars($_SESSION['email']); ?></div>
          </div>
        </div>
        <img src="catsuitv2.jpeg" alt="profile" style="height:36px;width:36px;border-radius:22px;">
        <a href="?logout=true" ><button class="logout-btn"><img src="catout.jpeg" alt="catout" style="height:24px;border-radius:22px; object-fit:contain;"> Logout</button></a>
      </div>
    </div>

    <div class="content">
      <?php if(isset($_GET['success'])): ?>
        <div class="success-message">
          <?php 
            if($_GET['success'] == 'restaurant') echo '✓ Restaurant added successfully!';
            elseif($_GET['success'] == 'user') echo '✓ User added successfully!';
            elseif($_GET['success'] == 'staff_updated') echo '✓ Staff restaurant updated successfully!';
            elseif($_GET['success'] == 'password_reset') echo '✓ Password reset successfully!';
          ?>
        </div>
      <?php endif; ?>
      
      <?php if(isset($error_msg)): ?>
        <div style="background-color: #ff6b6b; color: white; padding: 15px; border-radius: 8px; margin-bottom: 20px; text-align: center; font-weight: 600;">✗ <?php echo $error_msg; ?></div>
      <?php endif; ?>

      <div class="stats">
        <div class="stat-box pink">Total Restaurants<b><?php echo $totalRestaurants; ?></b></div>
        <div class="stat-box cyan">Total Users<b><?php echo $totalUsers; ?></b></div>
        <div class="stat-box yellow">Active Staff<b><?php echo $activeStaff; ?></b></div>
        <div class="stat-box green">Currently Online<b><?php echo $currentlyOnline; ?></b></div>
      </div>

      <div class="table-section">
        <h3>Staff Restaurant Management</h3>
        <table>
          <thead>
            <tr><th>Name</th><th>Email</th><th>Current Restaurant</th><th>Action</th></tr>
          </thead>
          <tbody>
            <?php 
            if ($staff_result && $staff_result->num_rows > 0) {
                while($row = $staff_result->fetch_assoc()) {
                    echo "<tr>";
                    echo "<td>" . htmlspecialchars($row['firstname'] . ' ' . $row['lastname']) . "</td>";
                    echo "<td>" . htmlspecialchars($row['email']) . "</td>";
                    echo "<td>" . ($row['rest_name'] ? htmlspecialchars($row['rest_name']) : '-') . "</td>";
                    echo "<td>";
                    echo "<form method='POST' class='edit-restaurant-form'>";
                    echo "<input type='hidden' name='user_id' value='" . $row['user_id'] . "'>";
                    echo "<select name='restaurant_id' class='restaurant-select' required>";
                    echo "<option value=''>Select Restaurant</option>";
                    foreach ($restaurants as $restaurant) {
                        $selected = ($restaurant['restaurant_id'] == $row['restaurant_id']) ? ' selected' : '';
                        echo "<option value='" . $restaurant['restaurant_id'] . "'" . $selected . ">" . htmlspecialchars($restaurant['rest_name']) . "</option>";
                    }
                    echo "</select>";
                    echo "<button type='submit' name='update_staff_restaurant' class='update-btn'>Update</button>";
                    echo "</form>";
                    echo "</td></tr>";
                }
            } else {
                echo "<tr><td colspan='4' style='text-align: center; padding: 20px;'>No staff found</td></tr>";
            }
            ?>
          </tbody>
        </table>
      </div>

      <div class="action-buttons">
        <button onclick="openRestaurantModal()">➕ Add Restaurant</button>
        <button onclick="openUserModal()">➕ Add User</button>
        <button onclick="openSystemModal()">⚙️ System Settings</button> 
      </div>
    </div>
  </div>

  <!-- Modal Add Restaurant -->
  <div id="restaurantModal" class="modal">
    <div class="modal-content">
      <span class="close" onclick="closeRestaurantModal()">&times;</span>
      <h2>➕ Add New Restaurant</h2>
      <form method="POST" action="index.php">
        <div class="form-group"><label>Restaurant Name *</label><input type="text" name="rest_name" required></div>
        <div class="form-group"><label>Description</label><textarea name="rest_description"></textarea></div>
        <div class="form-group"><label>Opening Time *</label><input type="time" name="open_time" required></div>
        <div class="form-group"><label>Closing Time *</label><input type="time" name="close_time" required></div>
        <div class="form-group"><label>Phone Number *</label><input type="text" name="rest_phone" maxlength="10" required></div>
        <div class="form-group"><label>Status *</label><select name="rest_status" required><option value="OPEN">OPEN</option><option value="CLOSED">CLOSED</option></select></div>
        <div class="form-group"><label>Address *</label><textarea name="rest_address" required></textarea></div>
        <div class="form-group"><label>Email *</label><input type="email" name="rest_email" required></div>
        <button type="submit" name="add_restaurant" class="submit-btn">Add Restaurant</button>
      </form>
    </div>
  </div>

  <!-- Modal Add User -->
  <div id="userModal" class="modal">
    <div class="modal-content">
      <span class="close" onclick="closeUserModal()">&times;</span>
      <h2>➕ Add New User</h2>
      <form method="POST" action="index.php">
        <div class="form-group"><label>Email *</label><input type="email" name="email" required></div>
        <div class="form-group"><label>Username *</label><input type="text" name="username" required></div>
        <div class="form-group"><label>Password *</label><input type="password" name="password" required></div>
        <div class="form-group"><label>First Name *</label><input type="text" name="firstname" required></div>
        <div class="form-group"><label>Last Name *</label><input type="text" name="lastname" required></div>
        <div class="form-group"><label>Date of Birth *</label><input type="date" name="dob" required></div>
        <div class="form-group"><label>Phone Number *</label><input type="text" name="user_phone" maxlength="10" required></div>
        <div class="form-group"><label>Role *</label><select name="role" required><option value="CUSTOMER">CUSTOMER</option><option value="STAFF">STAFF</option><option value="ADMIN">ADMIN</option></select></div>
        <button type="submit" name="add_user" class="submit-btn">Add User</button>
      </form>
    </div>
  </div>

  <!-- Modal System Settings -->
  <div id="systemModal" class="modal">
    <div class="modal-content">
      <span class="close" onclick="closeSystemModal()">&times;</span>
      <h2>⚙️ System Settings - Reset Password</h2>
      <form method="POST" action="index.php">
        <div class="form-group"><label>Username *</label><input type="text" name="reset_username" placeholder="Enter username" required></div>
        <div class="form-group"><label>New Password *</label><input type="password" name="new_password" placeholder="Enter new password" required></div>
        <div class="form-group"><label>Confirm New Password *</label><input type="password" name="confirm_password" placeholder="Confirm new password" required></div>
        <button type="submit" name="reset_password" class="submit-btn">Reset Password</button>
      </form>
    </div>
  </div>

  <script>
    function openRestaurantModal() { document.getElementById('restaurantModal').style.display = 'block'; }
    function closeRestaurantModal() { document.getElementById('restaurantModal').style.display = 'none'; }
    function openUserModal() { document.getElementById('userModal').style.display = 'block'; }
    function closeUserModal() { document.getElementById('userModal').style.display = 'none'; }
    function openSystemModal() { document.getElementById('systemModal').style.display = 'block'; }
    function closeSystemModal() { document.getElementById('systemModal').style.display = 'none'; }
    
    window.onclick = function(event) {
      let restaurantModal = document.getElementById('restaurantModal');
      let userModal = document.getElementById('userModal');
      let systemModal = document.getElementById('systemModal');
      
      if (event.target == restaurantModal) closeRestaurantModal();
      if (event.target == userModal) closeUserModal();
      if (event.target == systemModal) closeSystemModal();
    }
  </script>
</body>
</html>