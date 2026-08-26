<?php
$page = $_GET['page'] ?? 'dashboard';
$pageTitles = [
    'dashboard' => 'Dashboard',
    'booking' => 'Booking Management',
    'tables' => 'Table Layout',
    'customers' => 'Customer Management',
    'reports' => 'Reports & Analytics',
    'settings' => 'Settings'
];
$pageTitle = $pageTitles[$page] ?? 'Dashboard';

// ดึงข้อมูล staff จาก session
$staffName = $_SESSION['firstname'] ?? 'Staff';
$staffEmail = $_SESSION['email'] ?? 'staff@brewly.com';
?>
<div class="header">
    <h2 style="margin:0;color:#6b2b6b"><?= $pageTitle ?></h2>
    <div style="display:flex;align-items:center;gap:16px">
        <div style="text-align:right">
            <div style="font-weight:600;color:#333"><?= htmlspecialchars($staffName) ?></div>
            <div style="font-size:12px;color:#666"><?= htmlspecialchars($staffEmail) ?></div>
        </div>
        <div 
            style="width:40px;height:40px;border-radius:50%;background:linear-gradient(135deg,#a76de6,#b98fe6);display:flex;align-items:center;justify-content:center;color:white;font-weight:700;box-shadow:0 4px 12px rgba(167,109,230,0.3)"
        >
            <?= strtoupper(substr($staffName, 0, 1)) ?>
        </div>
        <a 
            href="../login/logout.php" 
            style="padding:10px 24px;background:#f44336;color:white;text-decoration:none;border-radius:8px;font-weight:600;border-radius: 50px;font-size:14px;transition:all 0.2s;display:inline-block"
            onmouseover="this.style.background='#d32f2f'" 
            onmouseout="this.style.background='#f44336'"
        >
            Logout
        </a>
    </div>
</div>