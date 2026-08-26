<?php
// index.php - main router
session_start();

// ตรวจสอบว่า login แล้วหรือยัง
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'STAFF') {
    header('Location: ../login/login.php');
    exit;
}

// 🔥 NEW: ตรวจสอบว่ามี restaurant_id ใน session หรือไม่
// ถ้าไม่มี (อาจเป็นการ login แบบเก่า) ให้ดึงข้อมูลจาก database
if (!isset($_SESSION['restaurant_id']) || !isset($_SESSION['staff_id'])) {
    require_once '../connect.php';
    
    $sql = "SELECT staff_id, restaurant_id FROM staff WHERE user_id = ?";
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param("i", $_SESSION['user_id']);
    $stmt->execute();
    $result = $stmt->get_result();
    $staff_data = $result->fetch_assoc();
    $stmt->close();
    
    if ($staff_data) {
        $_SESSION['staff_id'] = $staff_data['staff_id'];
        $_SESSION['restaurant_id'] = $staff_data['restaurant_id'];
    } else {
        // ถ้าไม่เจอข้อมูล staff ให้ logout
        session_destroy();
        header('Location: ../login/login.php?error=invalid_staff');
        exit;
    }
}

$page = $_GET['page'] ?? 'dashboard';
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>BrewlyChillnSeat - Staff</title>
  
  <!-- Main Styles -->
  <link rel="stylesheet" href="assets/css/styles.css">
  
  <!-- FullCalendar (CSS + JS) -->
  <link href='https://cdn.jsdelivr.net/npm/fullcalendar@6.1.8/index.global.min.css' rel='stylesheet' />
  <script src='https://cdn.jsdelivr.net/npm/fullcalendar@6.1.8/index.global.min.js'></script>
  
  <!-- Page-specific CSS -->
  <?php if ($page === 'dashboard'): ?>
    <style>
      /* Dashboard Styles - Updated <?= time() ?> */
      .stats-grid {
          display: grid;
          grid-template-columns: repeat(4, 1fr);
          gap: 18px;
          margin-bottom: 24px;
      }
      
      .stat-card {
          background: white;
          border-radius: 12px;
          padding: 20px;
          box-shadow: 0 4px 12px rgba(179, 148, 200, 0.12);
          transition: transform 0.2s ease;
      }
      
      .stat-card:hover {
          transform: translateY(-4px);
      }
      
      .stat-label {
          font-weight: 700;
          font-size: 14px;
          color: #333;
          margin-bottom: 8px;
      }
      
      .stat-value {
          font-size: 32px;
          font-weight: 700;
          color: #1f2937;
      }
      
      .dashboard-grid {
          display: grid;
          grid-template-columns: 400px 1fr;
          gap: 18px;
          margin-top: 24px;
      }
      
      #calendar {
          background: white;
          border-radius: 12px;
          padding: 12px;
      }
      
      .mini-legend {
          display: flex;
          gap: 16px;
          margin-bottom: 16px;
          padding: 12px;
          background: #f9f4ff;
          border-radius: 8px;
      }
      
      .mini-legend .legend-item {
          display: flex;
          align-items: center;
          gap: 6px;
          font-size: 12px;
          font-weight: 600;
          color: #666;
      }
      
      .mini-legend .legend-dot {
          width: 12px;
          height: 12px;
          border-radius: 50%;
          display: inline-block;
      }
      
      .mini-table-grid {
          display: grid;
          grid-template-columns: repeat(5, 1fr);
          gap: 10px;
          margin-bottom: 16px;
      }
      
      .mini-table-cell {
          padding: 12px 8px;
          border-radius: 10px;
          text-align: center;
          cursor: pointer;
          transition: all 0.2s ease;
          box-shadow: 0 2px 6px rgba(0, 0, 0, 0.08);
          min-height: 70px;
          display: flex;
          flex-direction: column;
          justify-content: center;
          align-items: center;
      }
      
      .mini-table-cell:hover {
          transform: translateY(-3px);
          box-shadow: 0 4px 12px rgba(0, 0, 0, 0.12);
      }
      
      .mini-table-cell.status-available {
          background: linear-gradient(135deg, #5b8db8 0%, #4a7399 100%); /* Blue */
          color: white;
      }
      
      .mini-table-cell.status-unavailable {
          background: linear-gradient(135deg, #a0605f 0%, #8b4a49 100%); /* Red */
          color: white;
      }
      
      .mini-table-cell.status-occupied {
          background: linear-gradient(135deg, #8a8a8a 0%, #6b6b6b 100%); /* Gray */
          color: white;
      }
      
      .mini-table-number {
          font-weight: 700;
          font-size: 16px;
          color: white;
          margin-bottom: 4px;
      }
      
      .mini-table-seats {
          font-size: 11px;
          color: rgba(255, 255, 255, 0.9);
          font-weight: 500;
      }
      
      .view-all-link {
          display: inline-block;
          color: #a76de6;
          font-weight: 600;
          font-size: 14px;
          text-decoration: none;
          padding: 8px 16px;
          border-radius: 8px;
          background: #f9f4ff;
          transition: all 0.2s;
      }
      
      .view-all-link:hover {
          background: #f0d9ff;
          transform: translateX(4px);
      }
      
      .badge.status-hold {
          background: #fff3b0;
          color: #856404;
      }
      
      .badge.status-confirmed {
          background: #d4edda;
          color: #155724;
      }
      
      .badge.status-cancelled {
          background: #f8d7da;
          color: #721c24;
      }
      
      .fc .fc-toolbar-title {
          color: #6b2b6b;
          font-weight: 700;
          font-size: 18px;
      }
      
      .fc .fc-button {
          background: #a76de6;
          border: none;
          border-radius: 6px;
          padding: 6px 12px;
          font-size: 13px;
      }
      
      .fc .fc-button:hover {
          background: #8e4ed4;
      }
      
      .fc .fc-button-primary:not(:disabled).fc-button-active {
          background: #6b2b6b;
      }
      
      .fc .fc-daygrid-day.fc-day-today {
          background: #f0d9ff !important;
      }
      
      .fc .fc-col-header-cell {
          background: #f9f4ff;
          font-weight: 600;
          color: #6b2b6b;
      }
      
      @media (max-width: 1200px) {
          .stats-grid {
              grid-template-columns: repeat(2, 1fr);
          }
          .dashboard-grid {
              grid-template-columns: 1fr;
          }
      }
      
      @media (max-width: 768px) {
          .stats-grid {
              grid-template-columns: 1fr;
          }
          .stat-value {
              font-size: 28px;
          }
          .mini-table-grid {
              grid-template-columns: repeat(3, 1fr);
              gap: 8px;
          }
          .mini-legend {
              flex-direction: column;
              gap: 8px;
          }
      }
    </style>
  <?php elseif ($page === 'tables'): ?>
    <link rel="stylesheet" href="assets/css/table_layout.css">
  <?php endif; ?>

</head>
<body>
<div class="app">
  <?php include 'sidebar.php'; ?>
  <div style="flex:1;display:flex;flex-direction:column">
    <?php include 'header.php'; ?>
    <div class="content">
      <?php
        $allowed = ['dashboard','booking','tables','customers','reports','settings'];
        if(!in_array($page,$allowed)) $page='dashboard';
        include $page . '.php';
      ?>
    </div>
    <div style="padding:12px"><div class="footer"></div></div>
  </div>
</div>
</body>
</html>