<?php
// notification_widget.php - ดึงข้อมูล notification จาก database
if (!isset($_SESSION['user_id'])) {
    return; // ไม่แสดง notification ถ้ายังไม่ได้ login
}

$user_id = $_SESSION['user_id'];

// ดึง notifications ของ user (10 รายการล่าสุด)
$sql_notifications = "SELECT 
                        n.*,
                        r.reserve_date,
                        r.reserve_status,
                        res.rest_name,
                        CASE 
                            WHEN TIMESTAMPDIFF(MINUTE, n.sent_at, NOW()) < 60 
                                THEN CONCAT(TIMESTAMPDIFF(MINUTE, n.sent_at, NOW()), ' minutes ago')
                            WHEN TIMESTAMPDIFF(HOUR, n.sent_at, NOW()) < 24 
                                THEN CONCAT(TIMESTAMPDIFF(HOUR, n.sent_at, NOW()), ' hours ago')
                            WHEN TIMESTAMPDIFF(DAY, n.sent_at, NOW()) < 7 
                                THEN CONCAT(TIMESTAMPDIFF(DAY, n.sent_at, NOW()), ' days ago')
                            ELSE DATE_FORMAT(n.sent_at, '%b %d, %Y')
                        END as time_ago
                    FROM notification n
                    LEFT JOIN reservation r ON n.reserve_id = r.reserve_id
                    LEFT JOIN restaurant res ON r.restaurant_id = res.restaurant_id
                    WHERE n.user_id = ?
                    ORDER BY n.sent_at DESC
                    LIMIT 10";

$stmt_notifications = $mysqli->prepare($sql_notifications);
$stmt_notifications->bind_param("i", $user_id);
$stmt_notifications->execute();
$notifications_result = $stmt_notifications->get_result();

$notifications = [];
$unread_count = 0;

while ($noti = $notifications_result->fetch_assoc()) {
    $notifications[] = $noti;
    // แก้: ให้ตรงกับที่ query ใช้ 'N' แทน 0
    if ($noti['is_read'] == 'N') {
        $unread_count++;
    }
}

// เลือก icon ตาม notification type
function getNotificationIcon($noti_type) {
    switch ($noti_type) {
        case 'BOOKED':
            return '✅';
        case 'CONFIRMED':
            return '📅';
        case 'CANCELLED':
            return '❌';
        case 'REMINDER':
            return '⏰';
        default:
            return '🔔';
    }
}
?>

<!-- Notification Dropdown Widget -->
<!-- Include notification.css in your page header: <link rel="stylesheet" href="css/notification.css"> -->

<!-- Notification Dropdown -->
<div class="notification-dropdown" id="notificationDropdown">
    <div class="notification-header">
        <h3>Notifications</h3>
        <?php if ($unread_count > 0): ?>
            <button class="btn-mark-read" onclick="markAllAsRead()">Mark all as read</button>
        <?php endif; ?>
    </div>
    
    <div class="notification-list">
        <?php if (empty($notifications)): ?>
            <!-- ไม่มี notification -->
            <div class="notification-empty">
                <p>No notifications yet</p>
            </div>
        <?php else: ?>
            <?php foreach ($notifications as $noti): ?>
                <!-- แก้: เช็ค is_read ให้ตรงกับ database ที่ใช้ 'N' -->
                <div class="notification-item <?php echo ($noti['is_read'] == 'N') ? 'unread' : ''; ?>" 
                     onclick="handleNotificationClick(<?php echo $noti['noti_id']; ?>, <?php echo $noti['reserve_id'] ?? 'null'; ?>)">
                    <div class="notification-icon-badge">
                        <?php echo getNotificationIcon($noti['noti_type']); ?>
                    </div>
                    <div class="notification-content">
                        <p class="notification-title"><?php echo htmlspecialchars($noti['noti_title']); ?></p>
                        <p class="notification-text"><?php echo htmlspecialchars($noti['noti_message']); ?></p>
                        <span class="notification-time"><?php echo $noti['time_ago']; ?></span>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<style>
.notification-empty {
    padding: 40px 20px;
    text-align: center;
    color: #999;
}

.notification-empty p {
    margin: 0;
    font-size: 14px;
}
</style>

<script>
// Notification Dropdown Toggle
document.addEventListener('DOMContentLoaded', function() {
    const notificationBtn = document.getElementById('notificationBtn');
    const notificationDropdown = document.getElementById('notificationDropdown');
    
    if (notificationBtn && notificationDropdown) {
        // Add badge for unread notifications
        const unreadCount = <?php echo $unread_count; ?>;
        if (unreadCount > 0) {
            const badge = document.createElement('span');
            badge.className = 'notification-badge';
            badge.textContent = unreadCount;
            notificationBtn.style.position = 'relative';
            notificationBtn.appendChild(badge);
        }
        
        notificationBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            notificationDropdown.classList.toggle('show');
        });

        // Close dropdown when clicking outside
        document.addEventListener('click', function(e) {
            if (!notificationDropdown.contains(e.target) && e.target !== notificationBtn) {
                notificationDropdown.classList.remove('show');
            }
        });
    }
});

// Mark all as read - แก้ไฟล์เป็น mark_notification.php
function markAllAsRead() {
    fetch('mark_notification.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({ action: 'mark_all_read' })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Remove unread class from all items
            const unreadItems = document.querySelectorAll('.notification-item.unread');
            unreadItems.forEach(item => item.classList.remove('unread'));
            
            // Remove badge
            const badge = document.querySelector('.notification-badge');
            if (badge) {
                badge.remove();
            }
            
            // Hide "Mark all as read" button
            const markAllBtn = document.querySelector('.btn-mark-read');
            if (markAllBtn) {
                markAllBtn.style.display = 'none';
            }
            
            console.log('Marked all as read:', data);
        } else {
            console.error('Failed to mark as read:', data.message);
            alert('Failed to mark notifications as read: ' + data.message);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Network error. Please check your connection.');
    });
}

// Handle notification click - แก้ไฟล์เป็น mark_notification.php
function handleNotificationClick(notificationId, reserveId) {
    // Mark as read
    fetch('mark_notification.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({ 
            action: 'mark_one_read',
            noti_id: notificationId 
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Remove unread class
            event.currentTarget.classList.remove('unread');
            
            // Update badge count
            const unreadCount = document.querySelectorAll('.notification-item.unread').length;
            const badge = document.querySelector('.notification-badge');
            if (badge) {
                if (unreadCount > 0) {
                    badge.textContent = unreadCount;
                } else {
                    badge.remove();
                }
            }
            
            // Navigate to booking detail if reserveId exists
            if (reserveId) {
                window.location.href = 'booking_detail.php?id=' + reserveId;
            }
        }
    })
    .catch(error => console.error('Error:', error));
}
</script>