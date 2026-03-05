<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$user_id = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;
$user_email = 'Account';

if ($user_id > 0 && isset($conn)) {
    $user_query = $conn->query("SELECT email FROM users WHERE id = $user_id");
    if ($user_query && $user_query->num_rows > 0) {
        $user_email = $user_query->fetch_assoc()['email'];
    }
}

// Helper to check active link
function isActive($page) {
    return basename($_SERVER['PHP_SELF']) == $page ? 'active' : '';
}
?>
<nav class="navbar">
    <div class="nav-left">
        <img src="../assets/img/image.png" alt="Leads Agri Logo" class="logo-icon">
        <div class="brand-name">Leads Agri Helpdesk</div>
        <button class="navbar-toggler" id="navbarToggler">
            <i class="fas fa-bars"></i>
        </button>
    </div>

    <div class="navbar-collapse" id="navbarCollapse">
        <div class="nav-center">
            <a href="dashboard.php" class="nav-link <?= isActive('dashboard.php') ?>">Dashboard</a>
            <a href="request_ticket.php" class="nav-link <?= isActive('request_ticket.php') ?>">Create Ticket</a>
            <a href="my_task.php" class="nav-link <?= isActive('my_task.php') ?>">My Task</a>
            <a href="my_tickets.php" class="nav-link <?= isActive('my_tickets.php') ?>">My Tickets</a>
            <a href="knowledge_base.php" class="nav-link <?= isActive('knowledge_base.php') ?>">Knowledge Base</a>
        </div>

        <div class="nav-right">
            <!-- Notification Bell -->
            <div class="notification-wrapper">
                <div class="notification-bell" id="notifBell">
                    <i class="fas fa-bell"></i>
                    <span class="notification-dot" id="notifDot" style="display: none;"></span>
                    <span class="notification-badge" id="notifBadge" style="display: none;">0</span>
                </div>
                <div class="notification-dropdown" id="notifDropdown">
                    <div class="notif-header">
                        <span>Notifications</span>
                    </div>
                    <div class="notif-list" id="notifList">
                        <div class="notif-empty">No notifications</div>
                    </div>
                    <div class="notif-footer">
                        <a href="notifications.php">View All Notifications</a>
                    </div>
                </div>
            </div>

            <div class="user-menu">
                <button class="user-btn">
                    <i class="fas fa-user-circle"></i>
                    <?= htmlspecialchars($user_email); ?>
                    <i class="fas fa-chevron-down" style="font-size: 10px;"></i>
                </button>
                <div class="user-dropdown">
                    <a href="my_profile.php" class="dropdown-item">My Profile</a>
                    <a href="logout.php" class="dropdown-item">Logout</a>
                </div>
            </div>
        </div>
    </div>
</nav>

<style>
/* Notification Styles */
.notification-wrapper {
    position: relative;
    margin-right: 15px;
}

.notification-bell {
    position: relative;
    cursor: pointer;
    font-size: 1.2rem;
    color: white;
    padding: 8px;
    transition: transform 0.2s;
}

.notification-bell:hover {
    transform: scale(1.1);
}

.notification-dot {
    position: absolute;
    top: 5px;
    right: 5px;
    width: 8px;
    height: 8px;
    background-color: #ff4444;
    border-radius: 50%;
    border: 2px solid #1B5E20; /* Match navbar bg */
}

.notification-badge {
    position: absolute;
    top: -2px;
    right: -10px;
    background-color: #ff4444;
    color: white;
    font-size: 0.7rem;
    padding: 2px 5px;
    border-radius: 10px;
    font-weight: bold;
    border: 2px solid #1B5E20;
}

.notification-dropdown {
    position: absolute;
    top: 40px;
    right: -10px;
    width: 320px;
    background: white;
    border-radius: 12px;
    box-shadow: 0 10px 30px rgba(0,0,0,0.2);
    display: none;
    z-index: 1000;
    overflow: hidden;
    animation: slideDown 0.2s ease-out;
}

.notification-dropdown.show {
    display: block;
}

@keyframes slideDown {
    from { opacity: 0; transform: translateY(-10px); }
    to { opacity: 1; transform: translateY(0); }
}

.notif-header {
    padding: 12px 15px;
    border-bottom: 1px solid #eee;
    font-weight: bold;
    color: #333;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.notif-list {
    max-height: 350px;
    overflow-y: auto;
}

.notif-item {
    padding: 12px 15px;
    border-bottom: 1px solid #f5f5f5;
    cursor: pointer;
    transition: background 0.2s;
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.notif-item:hover {
    background-color: #f9fafb;
}

.notif-item.unread {
    background-color: #e8f5e9; /* Light green for unread */
}

.notif-item .notif-msg {
    font-size: 0.9rem;
    color: #333;
    line-height: 1.4;
}

.notif-item .notif-time {
    font-size: 0.75rem;
    color: #888;
}

.notif-empty {
    padding: 20px;
    text-align: center;
    color: #888;
    font-size: 0.9rem;
}

.notif-footer {
    padding: 10px;
    text-align: center;
    border-top: 1px solid #eee;
    background: #f9f9f9;
}

.notif-footer a {
    color: #1B5E20;
    text-decoration: none;
    font-size: 0.9rem;
    font-weight: 600;
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const bell = document.getElementById('notifBell');
    const dropdown = document.getElementById('notifDropdown');
    const badge = document.getElementById('notifBadge');
    const dot = document.getElementById('notifDot');
    const list = document.getElementById('notifList');
    
    // Toggle dropdown
    bell.addEventListener('click', function(e) {
        e.stopPropagation();
        dropdown.classList.toggle('show');
    });

    // Close dropdown when clicking outside
    document.addEventListener('click', function(e) {
        if (!dropdown.contains(e.target) && !bell.contains(e.target)) {
            dropdown.classList.remove('show');
        }
    });

    // Fetch Notifications
    function fetchNotifications() {
        fetch('fetch_notifications.php')
            .then(response => response.json())
            .then(data => {
                // Update Badge
                if (data.unread_count > 0) {
                    badge.textContent = data.unread_count > 9 ? '9+' : data.unread_count;
                    badge.style.display = 'block';
                    dot.style.display = 'block';
                } else {
                    badge.style.display = 'none';
                    dot.style.display = 'none';
                }

                // Update List
                if (data.notifications.length > 0) {
                    list.innerHTML = data.notifications.map(n => `
                        <div class="notif-item ${n.is_read == 0 ? 'unread' : ''}" onclick="markAsRead(${n.id}, ${n.ticket_id}, '${n.type || ''}')">
                            <div class="notif-msg">${n.message}</div>
                            <div class="notif-time">${n.time_ago}</div>
                        </div>
                    `).join('');
                } else {
                    list.innerHTML = '<div class="notif-empty">No notifications</div>';
                }
            })
            .catch(err => console.error('Error fetching notifications:', err));
    }

    // Mark as Read & Redirect
    window.markAsRead = function(id, ticketId, type) {
        // Send request to mark as read
        fetch('mark_notification_read.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'id=' + id
        }).then(() => {
            if (type === 'dept_assigned') {
                window.location.href = `my_task.php?id=${ticketId}`;
            } else {
                window.location.href = `my_tickets.php?id=${ticketId}`;
            }
        });
    };

    // Initial fetch and poll every 5 seconds
    fetchNotifications();
    setInterval(fetchNotifications, 5000);
});
</script>
