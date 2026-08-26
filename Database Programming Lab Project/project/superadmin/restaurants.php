<?php
session_start();

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'ADMIN') {
    header("Location: ../login/login.php");
    exit();
}

// เชื่อมต่อฐานข้อมูล
require_once('../connect.php');
$conn = $mysqli;

// รับค่าการค้นหา
$search = isset($_GET['search']) ? $_GET['search'] : '';

// Query ข้อมูลร้านอาหาร - ค้นหาเฉพาะชื่อร้าน
if (!empty($search)) {
    $search_param = '%' . $search . '%';
    $sql = "SELECT restaurant_id, rest_name, rest_address 
            FROM restaurant 
            WHERE rest_name LIKE ?
            ORDER BY restaurant_id";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $search_param);
    $stmt->execute();
    $result = $stmt->get_result();
} else {
    $sql = "SELECT restaurant_id, rest_name, rest_address 
            FROM restaurant 
            ORDER BY restaurant_id";
    $result = $conn->query($sql);
}

$restaurants = [];
while ($row = $result->fetch_assoc()) {
    $restaurants[] = $row;
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Restaurants - BrewlyChillInSeat</title>
  <link rel="stylesheet" href="style.css">
  <style>

    .search-box input:focus {
      border-color: #9a5ef5;
    }

    .result-count {
      color: #5c2471;
      font-size: 14px;
      margin-bottom: 10px;
      font-weight: 500;
      font-style: italic;
    }

    .no-results {
      text-align: center;
      padding: 40px 20px;
      color: #9a5ef5;
      font-size: 15px;
      font-style: italic;
    }

    /* Logout Button Style */
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
    .corner-top-right {
    position: fixed;
    top: 0;
    right: 0;
    width: 50px;
    pointer-events: none;
    transform: scaleX(-1) translateX(-10px) translateY(-10px);
}


  </style>
</head>
<body>
  <div class="sidebar">
    <div class="logo">
      <img src="logo.png" alt="BrewlyChillInSeat Logo">
    </div>
    <div class="menu">
      <a href="index.php" >  <img src="homepage.png" alt="homepage" style="height:25px;border-radius:22px; object-fit:contain;position:relative; top:5px;">  Dashboard </a>
      <a href="restaurants.php" class="active" > <img src="restaurant.png" alt="restaurant" style="height:25px;border-radius:22px; object-fit:contain;position:relative; top:5px; right:-2px;"> <span style="margin-left:8px;">Restaurants</span></a>
      <a href="users.php"><img src="usericon.png" alt="usericon" style="height:25px;border-radius:22px; object-fit:contain;position:relative; top:5px; right:-2px;"> <span style="margin-left:8px;">User</span> </a>
      <a href="activity_log.php"><img src="time.png" alt="time" style="height:25px;border-radius:22px; object-fit:contain;position:relative; top:5px; right:-2px;"> <span style="margin-left:8px;"> Activity Logs</span></a>
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
     <img src="cf1.png" class="corner-top-right" alt="cat">
    <div class="content">
      <h2>Restaurants</h2>
      
      <form method="GET" action="restaurants.php" id="searchForm">
        <div class="search-box">
          <input 
            type="text" 
            name="search" 
            id="searchInput"
            placeholder="🔍 Search by restaurant name..." 
            value="<?php echo htmlspecialchars($search); ?>"
            autocomplete="off">
        </div>
      </form>

      <?php if (!empty($search)): ?>
        <div class="result-count">
          Found <?php echo count($restaurants); ?> result(s) for "<?php echo htmlspecialchars($search); ?>"
        </div>
      <?php endif; ?>

      <div class="table-section">
        <table>
          <thead>
            <tr>
              <th>Restaurant ID</th>
              <th>Restaurant Name</th>
              <th>Address</th>
            </tr>
          </thead>
          <tbody>
            <?php if (count($restaurants) > 0): ?>
              <?php foreach ($restaurants as $restaurant): ?>
                <tr>
                  <td><?php echo htmlspecialchars($restaurant['restaurant_id']); ?></td>
                  <td><?php echo htmlspecialchars($restaurant['rest_name']); ?></td>
                  <td><?php echo htmlspecialchars($restaurant['rest_address']); ?></td>
                </tr>
              <?php endforeach; ?>
            <?php else: ?>
              <tr>
                <td colspan="3" class="no-results">
                  <?php if (!empty($search)): ?>
                    No restaurant found matching "<?php echo htmlspecialchars($search); ?>"
                  <?php else: ?>
                    No restaurants available
                  <?php endif; ?>
                </td>
              </tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <script>
    // Auto-submit form เมื่อพิมพ์ (Real-time search)
    const searchInput = document.getElementById('searchInput');
    const searchForm = document.getElementById('searchForm');
    let timeout = null;

    searchInput.addEventListener('input', function() {
      clearTimeout(timeout);
      timeout = setTimeout(function() {
        searchForm.submit();
      }, 500); // รอ 500ms หลังจากพิมพ์เสร็จแล้วค่อยค้นหา
    });
  </script>
</body>
</html>