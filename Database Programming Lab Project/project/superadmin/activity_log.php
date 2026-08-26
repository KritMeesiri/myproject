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

// Initialize search variable
$search = isset($_GET['search']) ? $_GET['search'] : '';

// ✅ Query รวมทุก activity พร้อมเวลาที่ถูกต้อง
$sql = "
-- Login activities
SELECT 
    u.firstname,
    u.lastname,
    u.role,
    'login' as activity,
    lh.login_at as activity_time,
    u.user_id
FROM login_history lh
INNER JOIN user u ON lh.user_id = u.user_id

UNION ALL

-- Logout activities
SELECT 
    u.firstname,
    u.lastname,
    u.role,
    'logout' as activity,
    loh.logout_at as activity_time,
    u.user_id
FROM logout_history loh
INNER JOIN user u ON loh.user_id = u.user_id

UNION ALL

-- Made reservation (ยังไม่ confirm/cancel)
SELECT 
    u.firstname,
    u.lastname,
    u.role,
    'made reservation' as activity,
    r.created_at as activity_time,
    u.user_id
FROM reservation r
INNER JOIN user u ON r.user_id = u.user_id
WHERE r.reserve_status IN ('HOLD', '', NULL)

UNION ALL

-- Confirmed reservation
SELECT 
    u.firstname,
    u.lastname,
    u.role,
    'confirmed reservation' as activity,
    r.created_at as activity_time,
    u.user_id
FROM reservation r
INNER JOIN user u ON r.user_id = u.user_id
WHERE r.reserve_status = 'CONFIRMED'

UNION ALL

-- Cancelled reservation (ใช้เวลา cancelled_at)
SELECT 
    u.firstname,
    u.lastname,
    u.role,
    'cancelled reservation' as activity,
    COALESCE(r.cancelled_at, r.created_at) as activity_time,
    u.user_id
FROM reservation r
INNER JOIN user u ON r.user_id = u.user_id
WHERE r.reserve_status = 'CANCELLED'

UNION ALL

-- Check-in activities
SELECT 
    u.firstname,
    u.lastname,
    u.role,
    'checked in customer' as activity,
    c.checkin_at as activity_time,
    u.user_id
FROM checkin c
INNER JOIN staff s ON c.staff_id = s.staff_id
INNER JOIN user u ON s.user_id = u.user_id

UNION ALL

-- Payment activities
SELECT 
    u.firstname,
    u.lastname,
    u.role,
    CONCAT('payment ', LOWER(p.payment_status)) as activity,
    COALESCE(p.paid_at, NOW()) as activity_time,
    u.user_id
FROM payment p
INNER JOIN reservation r ON p.reserve_id = r.reserve_id
INNER JOIN user u ON r.user_id = u.user_id
";

// Add search filter if search term exists
if (!empty($search)) {
    $sql = "
    SELECT * FROM (
        " . $sql . "
    ) as all_activities
    WHERE firstname LIKE ? 
       OR lastname LIKE ?
       OR role LIKE ?
       OR activity LIKE ?
    ORDER BY activity_time DESC
    LIMIT 100
    ";
    
    $stmt = $conn->prepare($sql);
    $searchParam = "%{$search}%";
    $stmt->bind_param("ssss", $searchParam, $searchParam, $searchParam, $searchParam);
    $stmt->execute();
    $result = $stmt->get_result();
} else {
    $sql .= " ORDER BY activity_time DESC LIMIT 100";
    $result = $conn->query($sql);
}

// Function to get avatar URL
function getAvatarUrl($user_id, $role) {
    $avatars = [
        'STAFF' => [
            'https://cdn-icons-png.flaticon.com/512/4140/4140048.png',
            'https://cdn-icons-png.flaticon.com/512/4202/4202835.png',
            'https://cdn-icons-png.flaticon.com/512/3135/3135715.png',
            'https://cdn-icons-png.flaticon.com/512/236/236832.png'
        ],
        'CUSTOMER' => [
            'https://cdn-icons-png.flaticon.com/512/4140/4140037.png',
            'https://cdn-icons-png.flaticon.com/512/219/219970.png',
            'https://cdn-icons-png.flaticon.com/512/3135/3135768.png',
            'https://cdn-icons-png.flaticon.com/512/4202/4202831.png'
        ],
        'ADMIN' => [
            'https://cdn-icons-png.flaticon.com/512/219/219983.png',
            'https://cdn-icons-png.flaticon.com/512/3135/3135789.png'
        ]
    ];
    
    $role_avatars = $avatars[$role] ?? $avatars['CUSTOMER'];
    return $role_avatars[$user_id % count($role_avatars)];
}
?>

<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8">
  <title>Activity Logs - BrewlyChillInSeat</title>
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

    .result-count {
      color: #5c2471;
      font-size: 14px;
      margin-bottom: 10px;
      font-weight: 500;
      font-style: italic;
    }

    .corner-bottom-left {
      position: fixed;
      bottom: 0;
      left: 0;
      width: 50px;
      pointer-events: none;
      transform: translate(-10px, 10px);
    }
  </style>
</head>
<body>

<div class="sidebar">
  <img src="cf1.png" class="corner-bottom-left" alt="cat">
    <div class="logo">
      <img src="logo.png" alt="BrewlyChillInSeat Logo" >
    </div>
    <div class="menu">
      <a href="index.php" >  <img src="homepage.png" alt="homepage" style="height:25px;border-radius:22px; object-fit:contain;position:relative; top:5px;">  Dashboard </a>
      <a href="restaurants.php"  > <img src="restaurant.png" alt="restaurant" style="height:25px;border-radius:22px; object-fit:contain;position:relative; top:5px; right:-2px;"> <span style="margin-left:8px;">Restaurants</span></a>
      <a href="users.php"><img src="usericon.png" alt="usericon" style="height:25px;border-radius:22px; object-fit:contain;position:relative; top:5px; right:-2px;"> <span style="margin-left:8px;">User</span> </a>
      <a href="activity_log.php" class="active"  ><img src="time.png" alt="time" style="height:25px;border-radius:22px; object-fit:contain;position:relative; top:5px; right:-2px;"> <span style="margin-left:8px;"> Activity Logs</span></a>
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
        <a href="index.php?logout=true"><button class="logout-btn"><img src="catout.jpeg" alt="catout" style="height:28px;border-radius:22px; object-fit:contain;"> Logout</button></a>
      </div>
    </div>

    <div class="content">
      <h2>Activity logs</h2>
      <div class="search-box">
        <form method="GET" action="">
          <input type="text" name="search" placeholder="🔍 Search by name, role, or activity" value="<?php echo htmlspecialchars($search); ?>">
          <?php if (!empty($search)): ?>
            <a href="activity_log.php" style="margin-left: 10px;">Clear</a>
          <?php endif; ?>
        </form>
      </div>

      <?php if (!empty($search)): ?>
        <div class="result-count">
          Found <?php echo $result->num_rows; ?> result(s) for "<?php echo htmlspecialchars($search); ?>"
        </div>
      <?php endif; ?>

      <div class="table-section">
        <table>
          <thead>
            <tr>
              <th>Name</th>
              <th>Role</th>
              <th>Activity</th>
              <th>Time</th>
            </tr>
          </thead>
          <tbody>
            <?php
            if ($result && $result->num_rows > 0) {
                while($row = $result->fetch_assoc()) {
                    $full_name = htmlspecialchars($row['firstname'] . ' ' . $row['lastname']);
                    $role = strtolower(htmlspecialchars($row['role']));
                    $role_display = ucfirst($role);
                    $activity = htmlspecialchars($row['activity']);
                    
                    $time_obj = new DateTime($row['activity_time']);
                    $formatted_time = $time_obj->format('H:i');
                    
                    $avatar_url = getAvatarUrl($row['user_id'], $row['role']);
                    $role_class = $role;
                    
                    echo "<tr>";
                    echo "<td><img class='avatar' src='" . htmlspecialchars($avatar_url) . "'> " . $full_name . "</td>";
                    echo "<td><span class='role " . $role_class . "'>" . $role_display . "</span></td>";
                    echo "<td>" . $activity . "</td>";
                    echo "<td>" . $formatted_time . "</td>";
                    echo "</tr>";
                }
            } else {
                echo "<tr><td colspan='4' style='text-align: center; padding: 20px;'>";
                if (!empty($search)) {
                    echo "No activity logs found matching '<strong>" . htmlspecialchars($search) . "</strong>'";
                } else {
                    echo "No activity logs found";
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