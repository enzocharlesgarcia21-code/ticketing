<?php
// Get current page for active link
$current_page = basename($_SERVER['PHP_SELF']);
?>
<header class="admin-navbar">
    <div class="admin-navbar-left">
        <img src="../assets/img/image.png" alt="Logo" class="admin-logo-img">
        <div>
            <div class="admin-logo-text-main">Leads Agri Helpdesk</div>
            <div class="admin-logo-text-sub">Admin</div>
        </div>
    </div>

    <button class="admin-navbar-toggle" id="adminNavbarToggle" aria-label="Toggle navigation" style="display:none;">
        <span></span><span></span><span></span>
    </button>

    <nav class="admin-navbar-center" id="adminNavbarCenter">
        <a href="dashboard.php" class="admin-nav-link <?= $current_page == 'dashboard.php' ? 'active' : '' ?>">Dashboard</a>
        <a href="all_tickets.php" class="admin-nav-link <?= $current_page == 'all_tickets.php' ? 'active' : '' ?>">All Tickets</a>
        <a href="analytics.php" class="admin-nav-link <?= $current_page == 'analytics.php' ? 'active' : '' ?>">Analytics</a>
        <a href="create_admin.php" class="admin-nav-link <?= $current_page == 'create_admin.php' ? 'active' : '' ?>">Admin Management</a>
        <a href="manage_kb.php" class="admin-nav-link <?= $current_page == 'manage_kb.php' ? 'active' : '' ?>">Knowledge Base</a>
    </nav>

    <div class="admin-navbar-right">
        <!-- Notification Bell -->
        <div class="notification-wrapper">
            <div class="notification-bell" id="notifBell" onclick="toggleNotifications()">
                🔔
                <span class="notification-dot" id="notifDot" style="display: none;"></span>
                <span class="notification-badge" id="notifBadge" style="display: none;">0</span>
            </div>
            <div class="notification-dropdown" id="notifDropdown">
                <div class="notif-header">Notifications</div>
                <div class="notif-list" id="notifList">
                    <div class="notif-empty">No new notifications</div>
                </div>
                <a href="all_tickets.php" class="notif-footer">View All Tickets</a>
            </div>
        </div>

        <div class="admin-user-dropdown">
            <button class="admin-user-pill">
                <span class="admin-user-icon">👤</span>
                <span class="admin-user-email">
                    <?= htmlspecialchars($_SESSION['email'] ?? 'Admin'); ?>
                </span>
                <span class="admin-arrow">▾</span>
            </button>
            <div class="admin-dropdown-menu">
                <a href="logout.php" class="logout-link">Logout</a>
            </div>
        </div>
    </div>
</header>

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
    color: white; /* Assuming admin navbar has dark background */
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
    display: none;
    position: absolute;
    top: 100%;
    right: 0;
    width: 320px;
    background: white;
    border-radius: 8px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
    z-index: 1000;
    overflow: hidden;
    color: #333;
    text-align: left;
}
.notification-dropdown.show {
    display: block;
}
.notif-header {
    padding: 12px 16px;
    font-weight: bold;
    border-bottom: 1px solid #eee;
    background: #f8f9fa;
    color: #333;
}
.notif-list {
    max-height: 300px;
    overflow-y: auto;
}
.notif-item {
    padding: 12px 16px;
    border-bottom: 1px solid #eee;
    cursor: pointer;
    transition: background 0.2s;
}
.notif-item:hover {
    background: #f1f8e9;
}
.notif-item.unread {
    background: #e8f5e9;
}
.notif-item.unread:hover {
    background: #dcedc8;
}
.notif-text {
    font-size: 0.9rem;
    margin-bottom: 4px;
    color: #333;
    font-weight: 500;
}
.notif-time {
    font-size: 0.75rem;
    color: #888;
}
.notif-footer {
    display: block;
    padding: 10px;
    text-align: center;
    background: #f8f9fa;
    color: #1B5E20;
    text-decoration: none;
    font-size: 0.9rem;
    border-top: 1px solid #eee;
}
.notif-empty {
    padding: 20px;
    text-align: center;
    color: #999;
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Mobile navbar toggle
    const adminToggle = document.getElementById('adminNavbarToggle');
    const adminCenter = document.getElementById('adminNavbarCenter');
    if (adminToggle && adminCenter) {
        adminToggle.addEventListener('click', function(e) {
            e.stopPropagation();
            adminCenter.classList.toggle('show');
        });
        document.addEventListener('click', function(e) {
            if (!adminCenter.contains(e.target) && !adminToggle.contains(e.target)) {
                adminCenter.classList.remove('show');
            }
        });
    }
    fetchAdminNotifications();
    setInterval(fetchAdminNotifications, 5000);

    // Close dropdown when clicking outside
    document.addEventListener('click', function(e) {
        const wrapper = document.querySelector('.notification-wrapper');
        const dropdown = document.getElementById('notifDropdown');
        if (wrapper && !wrapper.contains(e.target)) {
            dropdown.classList.remove('show');
        }
    });
});

function toggleNotifications() {
    document.getElementById('notifDropdown').classList.toggle('show');
}

function fetchAdminNotifications() {
    fetch('fetch_notifications.php')
        .then(response => {
            if (response.status === 403) {
                // Session expired
                window.location.reload();
                return null;
            }
            return response.json();
        })
        .then(data => {
            if (!data) return;

            const bell = document.getElementById('notifBell');
            const dot = document.getElementById('notifDot');
            const badge = document.getElementById('notifBadge');
            const list = document.getElementById('notifList');

            // Update Badge
            if (data.unread_count > 0) {
                dot.style.display = 'block';
                badge.style.display = 'block';
                badge.textContent = data.unread_count > 99 ? '99+' : data.unread_count;
            } else {
                dot.style.display = 'none';
                badge.style.display = 'none';
            }

            // Update List
            if (data.notifications.length === 0) {
                list.innerHTML = '<div class="notif-empty">No new notifications</div>';
            } else {
                list.innerHTML = data.notifications.map(n => `
                    <div class="notif-item ${n.is_read == 0 ? 'unread' : ''}" onclick="handleNotificationClick(${n.id}, ${n.ticket_id})">
                        <div class="notif-text">${escapeHtml(n.message)}</div>
                        <div class="notif-time">${n.time_ago}</div>
                    </div>
                `).join('');
            }
        })
        .catch(err => console.error('Error fetching notifications:', err));
}

function handleNotificationClick(notifId, ticketId) {
    // Mark as read
    const formData = new FormData();
    formData.append('id', notifId);
    
    fetch('mark_notification_read.php', {
        method: 'POST',
        body: formData
    }).then(() => {
        // Redirect to all_tickets.php with ticket ID to auto-open
        window.location.href = `all_tickets.php?id=${ticketId}`;
    });
}

function escapeHtml(text) {
    if (!text) return '';
    return text
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;");
}
</script>
