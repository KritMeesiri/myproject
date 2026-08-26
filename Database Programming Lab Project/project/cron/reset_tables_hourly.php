<?php
/**
 * Cron Job: Reset Tables Hourly
 * 
 * ใช้สำหรับ: รีเซ็ตสถานะโต๊ะหลังร้านปิด
 * 
 * ตั้งค่า Cron:
 * 0 * * * * php /path/to/project/cron/reset_tables_hourly.php >> /var/log/cron_reset.log 2>&1
 */

// ✅ แก้ path ให้ถูกต้อง
require_once __DIR__ . '/../cron_connect.php';

$start_time = microtime(true);
$timestamp = date('Y-m-d H:i:s');

echo "[$timestamp] Starting reset_tables_hourly cron job...\n";

try {
    // เรียกใช้ stored procedure
    $result = $mysqli->query("CALL reset_tables_after_closing()");
    
    if (!$result) {
        throw new Exception("Failed to execute procedure: " . $mysqli->error);
    }
    
    // 🔥 Clear result sets - สำคัญมาก!
    $affected = 0;
    while ($mysqli->more_results()) {
        $mysqli->next_result();
        if ($res = $mysqli->store_result()) {
            $affected = $res->num_rows;
            $res->free();
        }
    }
    
    $end_time = microtime(true);
    $execution_time = round($end_time - $start_time, 2);
    
    echo "[$timestamp] Completed! Reset $affected table(s) in {$execution_time}s\n";
    echo str_repeat('-', 60) . "\n";
    
} catch (Exception $e) {
    $error_timestamp = date('Y-m-d H:i:s');
    echo "[$error_timestamp] ERROR: " . $e->getMessage() . "\n";
    echo str_repeat('-', 60) . "\n";
}

$mysqli->close();
?>