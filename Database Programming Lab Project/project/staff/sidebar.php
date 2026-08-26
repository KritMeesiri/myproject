<?php
$current = $_GET['page'] ?? 'dashboard';
?>
<div class="sidebar">
  <img src="assets/logo.png" alt="logo" style="height:36px;object-fit:contain"/>
  <div style="height:46px"></div>
  <div class="nav">
    <a href="?page=dashboard" class="<?= $current=='dashboard'?'active':'' ?>"><img src="assets/homepage1.png" alt="homepage" style="height:25px; object-fit:contain;position:relative; right:-2px; top:3px;"><span style="margin-left:8px;">DashBoard</span></a></a>
    <a href="?page=booking" class="<?= $current=='booking'?'active':'' ?>" > <img src="assets/booking5.png" alt="booking" style="height:25px; object-fit:contain;position:relative; right:-2px; top:4px;"><span style="margin-left:10px;">Booking</span></a>
    <a href="?page=tables" class="<?= $current=='tables'?'active':'' ?>"><img src="assets/table.png" alt="table" style="height:26px; object-fit:contain;position:relative; right:-2px; top:6px;"><span style="margin-left:10px;">Table</span></a>
    <a href="?page=customers" class="<?= $current=='customers'?'active':'' ?>"><img src="assets/customer.png" alt="table" style="height:26px; object-fit:contain;position:relative; right:-2px; top:6px;"><span style="margin-left:10px;">Customer</span></a>
    <a href="?page=reports" class="<?= $current=='reports'?'active':'' ?>"><img src="assets/reprot.png" alt="reprot" style="height:26px; object-fit:contain;position:relative; right:-2px; top:6px;"><span style="margin-left:10px;">Report</span></a>
    <a href="?page=settings" class="<?= $current=='settings'?'active':'' ?>"><img src="assets/setting.png" alt="setting" style="height:26px; object-fit:contain;position:relative; right:-2px; top:6px;"><span style="margin-left:10px;">Setting</span></a>
  </div>
</div>
