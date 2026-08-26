<?php
session_start();

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'ADMIN') {
    header("Location: ../login/login.php");
    exit();
}

// Include database configuration
require_once('../connect.php');
$conn = $mysqli;

// Handle role change
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_role'])) {
    $user_id = (int)$_POST['user_id'];
    $new_role = $_POST['new_role'];
    
    // Validate role
    if (in_array($new_role, ['ADMIN', 'STAFF', 'CUSTOMER'])) {
        $update_sql = "UPDATE user SET role = ? WHERE user_id = ?";
        $update_stmt = $conn->prepare($update_sql);
        $update_stmt->bind_param("si", $new_role, $user_id);
        
        if ($update_stmt->execute()) {
            $_SESSION['success'] = "Role changed successfully!";
        } else {
            $_SESSION['error'] = "Failed to change role.";
        }
        $update_stmt->close();
    }
    
    header("Location: users.php");
    exit();
}

// Initialize search variable
$search = isset($_GET['search']) ? $_GET['search'] : '';

// Prepare SQL query to get ALL users with restaurant information
$sql = "
SELECT 
    u.user_id,
    u.firstname,
    u.lastname,
    u.email,
    u.role,
    u.is_active,
    COALESCE(r.rest_name, '-') as restaurant_name
FROM user u
LEFT JOIN staff s ON u.user_id = s.user_id
LEFT JOIN restaurant r ON s.restaurant_id = r.restaurant_id
";

// Add search filter if search term exists
if (!empty($search)) {
    $sql .= " WHERE (
        u.firstname LIKE ? 
        OR u.lastname LIKE ?
        OR u.email LIKE ?
        OR u.role LIKE ?
        OR r.rest_name LIKE ?
    )";
}

$sql .= " ORDER BY 
    CASE LOWER(u.role)
        WHEN 'admin' THEN 1 
        WHEN 'staff' THEN 2 
        WHEN 'customer' THEN 3 
        ELSE 4
    END,
    u.firstname, 
    u.lastname";

// Execute query with or without search
if (!empty($search)) {
    $stmt = $conn->prepare($sql);
    $searchParam = "%{$search}%";
    $stmt->bind_param("sssss", $searchParam, $searchParam, $searchParam, $searchParam, $searchParam);
    $stmt->execute();
    $result = $stmt->get_result();
} else {
    $result = $conn->query($sql);
}

// Check for query errors
if (!$result) {
    die("Query failed: " . $conn->error);
}
?>

<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8">
  <title>User - BrewlyChillInSeat</title>
  <link rel="stylesheet" href="style.css">
  <style>
    .logout-btn {
      display: flex;
      align-items: center;        
      justify-content: center;    
      gap: 8px;                
      padding: 10px 28px;
      background: #ff4444;
      color: white;
      border: none;
      border-radius: 25px;
      text-decoration: none;
      font-size: 15px;
      font-weight: 600;
      transition: all 0.2s;
    }

    .logout-btn:hover {
      background-color: #ee5a52;
    }

    .profile-info {
      display: flex;
      align-items: center;
      gap: 10px;
    }

    .profile-details {
      text-align: right;
    }

    .profile-email {
      font-size: 12px;
      color: #9a5ef5;
    }

    .status-active,
    .status-inactive {
      display: inline-block;
      padding: 5px 12px;
      border-radius: 15px;
      color: white;
      font-weight: 600;
      font-size: 12px;
      text-transform: uppercase;
      letter-spacing: 0.5px;
      box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
    }

    .status-active {
      background: linear-gradient(135deg, #5dd35d 0%, #4bc04b 100%);
    }

    .status-inactive {
      background: linear-gradient(135deg, #ffa500 0%, #ff8c00 100%);
    }

    .corner-bottom-right {
      position: fixed;
      bottom: 0;
      right: 0;
      width: 50px;
      pointer-events: none;
      transform: scaleX(-1) translate(-10px, 10px);
    }

    /* ✅ Role Change Button */
    .role-change-form {
      display: inline-flex;
      align-items: center;
      gap: 8px;
    }

    .role-select {
      padding: 8px 12px;
      border: none;
      border-radius: 20px;
      color: white;
      font-weight: 600;
      font-size: 12px;
      cursor: pointer;
      outline: none;
      transition: all 0.2s;
      text-transform: uppercase;
      letter-spacing: 0.5px;
    }

    /* สีตาม role เหมือน badge เดิม */
    .role-select.admin {
      background: linear-gradient(135deg, #ff6b6b 0%, #ee5a52 100%);
    }

    .role-select.staff {
      background: linear-gradient(135deg, #60e2d9ff 0%, #55cac6ff 100%);
    }

    .role-select.customer {
      background: linear-gradient(135deg, #f093fb 0%, #c86dd7 100%);
    }

    .role-select:hover {
      transform: translateY(-1px);
      box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
    }

    /* Style for dropdown options - ทำให้มองเห็นชัดเจน */
    .role-select option {
      background: white;
      color: #333;
      font-weight: 600;
      padding: 10px;
    }

    .change-btn {
      padding: 8px 16px;
      background: #9a5ef5;
      color: white;
      border: none;
      border-radius: 20px;
      font-weight: 600;
      font-size: 12px;
      cursor: pointer;
      transition: all 0.2s;
      text-transform: uppercase;
      letter-spacing: 0.5px;
    }

    .change-btn:hover {
      background: #7a3ec5;
      transform: translateY(-1px);
      box-shadow: 0 2px 8px rgba(154, 94, 245, 0.3);
    }

    .success-message {
      background: #d4edda;
      color: #155724;
      padding: 12px 20px;
      border-radius: 8px;
      margin-bottom: 15px;
      border: 1px solid #c3e6cb;
    }

    .error-message {
      background: #f8d7da;
      color: #721c24;
      padding: 12px 20px;
      border-radius: 8px;
      margin-bottom: 15px;
      border: 1px solid #f5c6cb;
    }
  </style>
</head>
<body>
<div class="sidebar">
  <img src="cf1.png" class="corner-bottom-right" alt="cat">
    <div class="logo">
      <img src="logo.png" alt="BrewlyChillInSeat Logo">
    </div>
    <div class="menu">
      <a href="index.php">
        <img src="homepage.png" alt="homepage" style="height:25px;border-radius:22px; object-fit:contain;position:relative; top:5px;"> Dashboard
      </a>
      <a href="restaurants.php">
        <img src="restaurant.png" alt="restaurant" style="height:25px;border-radius:22px; object-fit:contain;position:relative; top:5px; right:-2px;">
        <span style="margin-left:8px;">Restaurants</span>
      </a>
      <a href="users.php" class="active">
        <img src="usericon.png" alt="usericon" style="height:25px;border-radius:22px; object-fit:contain;position:relative; top:5px; right:-2px;">
        <span style="margin-left:8px;">User</span>
      </a>
      <a href="activity_log.php">
        <img src="time.png" alt="time" style="height:25px;border-radius:22px; object-fit:contain;position:relative; top:5px; right:-2px;">
        <span style="margin-left:8px;">Activity Logs</span>
      </a>
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
        <a href="index.php?logout=true">
          <button class="logout-btn">
            <img src="catout.jpeg" alt="catout" style="height:28px;border-radius:22px; object-fit:contain;"> Logout
          </button>
        </a>
      </div>
    </div>

    <div class="content">
      <h2>User (Total: <?php echo $result->num_rows; ?>)</h2>

      <?php if (isset($_SESSION['success'])): ?>
        <div class="success-message">
          <?php 
            echo htmlspecialchars($_SESSION['success']); 
            unset($_SESSION['success']);
          ?>
        </div>
      <?php endif; ?>

      <?php if (isset($_SESSION['error'])): ?>
        <div class="error-message">
          <?php 
            echo htmlspecialchars($_SESSION['error']); 
            unset($_SESSION['error']);
          ?>
        </div>
      <?php endif; ?>

      <div class="search-box">
        <form method="GET" action="">
          <input type="text" name="search" placeholder="🔍 Search by name, email, restaurant, or role" value="<?php echo htmlspecialchars($search); ?>">
          <?php if (!empty($search)): ?>
            <a href="users.php" style="margin-left: 10px;">Clear</a>
          <?php endif; ?>
        </form>
      </div>

      <div class="table-section">
        <table>
          <thead>
            <tr>
              <th>Name</th>
              <th>Email</th>
              <th>Restaurant Name</th>
              <th>Role</th>
              <th>Status</th>
            </tr>
          </thead>
          <tbody>
            <?php
            if ($result && $result->num_rows > 0) {
                while($row = $result->fetch_assoc()) {
                    $full_name = htmlspecialchars($row['firstname'] . ' ' . $row['lastname']);
                    $email = htmlspecialchars($row['email']);
                    $restaurant_name = htmlspecialchars($row['restaurant_name']);
                    $role = strtoupper($row['role']);
                    $role_display = ucfirst(strtolower($row['role']));
                    $is_active = $row['is_active'];
                    $user_id = $row['user_id'];
                    
                    $role_class = strtolower($row['role']);
                    $status_class = ($is_active === 'Y') ? 'status-active' : 'status-inactive';
                    $status_text = ($is_active === 'Y') ? 'Active' : 'Not Active';
                    
                    echo "<tr>";
                    echo "<td>" . $full_name . "</td>";
                    echo "<td>" . $email . "</td>";
                    echo "<td>" . $restaurant_name . "</td>";
                    echo "<td>";
                    
                    // ✅ Role change form
                    echo "<form method='POST' class='role-change-form'>";
                    echo "<input type='hidden' name='user_id' value='" . $user_id . "'>";
                    echo "<select name='new_role' class='role-select " . $role_class . "'>";
                    echo "<option value='ADMIN'" . ($role === 'ADMIN' ? ' selected' : '') . ">Admin</option>";
                    echo "<option value='STAFF'" . ($role === 'STAFF' ? ' selected' : '') . ">Staff</option>";
                    echo "<option value='CUSTOMER'" . ($role === 'CUSTOMER' ? ' selected' : '') . ">Customer</option>";
                    echo "</select>";
                    echo "<button type='submit' name='change_role' class='change-btn'>Change</button>";
                    echo "</form>";
                    
                    echo "</td>";
                    echo "<td><span class='" . $status_class . "'>" . $status_text . "</span></td>";
                    echo "</tr>";
                }
            } else {
                echo "<tr><td colspan='5' style='text-align: center; padding: 20px;'>";
                if (!empty($search)) {
                    echo "No users found matching '<strong>" . htmlspecialchars($search) . "</strong>'";
                } else {
                    echo "No users found";
                }
                echo "</td></tr>";
            }
            
            $conn->close();
            ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</body>
</html>