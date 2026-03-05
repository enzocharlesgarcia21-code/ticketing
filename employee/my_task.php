<?php
require_once '../config/database.php';

/* Protect page */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'employee') {
    header("Location: employee_login.php");
    exit();
}

$user_id = $_SESSION['user_id'];

// Get the logged-in employee's department and company
$user_dept_stmt = $conn->prepare("SELECT department, company FROM users WHERE id = ?");
$user_dept_stmt->bind_param("i", $user_id);
$user_dept_stmt->execute();
$user_dept_result = $user_dept_stmt->get_result();
$user_department = '';
$user_company = '';
if ($row = $user_dept_result->fetch_assoc()) {
    $user_department = $row['department'];
    $user_company = $row['company'];
}
$user_dept_stmt->close();

/* ================= GET VALUES ================= */

$search = $_GET['search'] ?? '';

// --- PAGINATION LOGIC ---
$limit = 10;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;
$offset = ($page - 1) * $limit;

// --- BUILD DYNAMIC QUERY ---
$where = [];
$params = [];
$types = "";

// 🎯 MAIN FILTER: Assigned to user's department AND Company AND Not Closed
$where[] = "t.assigned_department = ?";
$params[] = $user_department;
$types .= "s";

$where[] = "t.assigned_company = ?";
$params[] = $user_company;
$types .= "s";

$where[] = "t.status != 'Closed'";

// 1. Search
if (!empty($search)) {
    $term = "%$search%";
    
    // Parse ID from search (remove non-digits)
    $searchId = preg_replace('/[^0-9]/', '', $search);
    $searchIdInt = (int)$searchId;
    $searchById = ($searchId !== '' && $searchIdInt > 0);

    if ($searchById) {
        $where[] = "(t.subject LIKE ? OR t.category LIKE ? OR t.id LIKE ? OR u.name LIKE ? OR u.email LIKE ? OR t.id = ?)";
        $params[] = $term;
        $params[] = $term;
        $params[] = $term;
        $params[] = $term;
        $params[] = $term;
        $params[] = $searchIdInt;
        $types .= "sssssi";
    } else {
        $where[] = "(t.subject LIKE ? OR t.category LIKE ? OR t.id LIKE ? OR u.name LIKE ? OR u.email LIKE ?)";
        $params[] = $term;
        $params[] = $term;
        $params[] = $term;
        $params[] = $term;
        $params[] = $term;
        $types .= "sssss";
    }
}

// Construct SQL
$sql = "SELECT t.*, u.name as user_name, u.email as user_email 
        FROM employee_tickets t 
        JOIN users u ON t.user_id = u.id";
$countSql = "SELECT COUNT(*) as total 
             FROM employee_tickets t 
             JOIN users u ON t.user_id = u.id";

if (!empty($where)) {
    $sql .= " WHERE " . implode(" AND ", $where);
    $countSql .= " WHERE " . implode(" AND ", $where);
}

$sql .= " ORDER BY t.created_at DESC LIMIT ?, ?";

// --- GET TOTAL COUNT ---
if (!empty($where)) {
    $stmt = $conn->prepare($countSql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $total_row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
} else {
    $count_result = $conn->query($countSql);
    $total_row = $count_result->fetch_assoc();
}

$total_records = $total_row['total'] ?? 0;
$total_pages = ceil($total_records / $limit);

// --- EXECUTE MAIN QUERY ---
$stmt = $conn->prepare($sql);

// Add Limit/Offset to params
$params[] = $offset;
$params[] = $limit;
$types .= "ii";

$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Tasks | Leads Agri Helpdesk</title>
    <link rel="stylesheet" href="../css/employee-dashboard.css">
    <link rel="stylesheet" href="../css/view-tickets.css">
    <!-- Include Admin CSS for Modal Styles -->
    <link rel="stylesheet" href="../css/admin.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>

    <!-- TOP NAVIGATION BAR -->
    <?php include '../includes/employee_navbar.php'; ?>

    <div class="dashboard-container">
        <div class="content-wrapper">

            <div class="page-header">
                <h1 class="page-title">My Tasks</h1>
                <p class="page-subtitle">Tickets assigned to <strong><?= htmlspecialchars($user_department) ?></strong> department</p>
            </div>

            <!-- FILTERS CARD -->
            <div class="filter-card">
                <form method="GET" id="filterForm" class="filter-form">
                    
                    <div class="search-wrapper" style="width: 100%; max-width: 400px;">
                        <i class="fas fa-search search-icon"></i>
                        <input type="text"
                               name="search"
                               id="searchInput"
                               class="search-input"
                               placeholder="Search tasks..."
                               value="<?= htmlspecialchars($search); ?>">
                    </div>

                    <div class="filters-wrapper">
                        <a href="my_task.php" class="clear-btn">Clear Search</a>
                    </div>
                </form>
            </div>

            <!-- TABLE CARD -->
            <div class="table-card">
                <div class="table-responsive">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Subject</th>
                                <th>Requested By</th>
                                <th>Original Dept</th>
                                <th>Assigned Dept</th>
                                <th>Priority</th>
                                <th>Status</th>
                                <th>Date Created</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($result && $result->num_rows > 0): ?>
                                <?php while($row = $result->fetch_assoc()) { ?>
                                <tr class="ticket-row" data-id="<?= $row['id']; ?>" style="cursor:pointer;">
                                    <td>#<?= str_pad($row['id'], 6, '0', STR_PAD_LEFT); ?></td>
                                    <td class="subject-cell">
                                        <strong><?= htmlspecialchars($row['subject']); ?></strong>
                                    </td>
                                    <td>
                                        <div class="user-info">
                                            <strong><?= htmlspecialchars($row['user_email']); ?></strong><br>
                                            <small><?= htmlspecialchars($row['user_name']); ?></small>
                                        </div>
                                    </td>
                                    <td><?= htmlspecialchars($row['department']); ?></td>
                                    <td><?= htmlspecialchars($row['assigned_department']); ?></td>
                                    
                                    <td>
                                        <span class="badge badge-<?= strtolower($row['priority']); ?>">
                                            <?= htmlspecialchars($row['priority']); ?>
                                        </span>
                                    </td>

                                    <td>
                                        <span class="status-<?= strtolower(str_replace(' ', '-', $row['status'])); ?>">
                                            <?= htmlspecialchars($row['status']); ?>
                                        </span>
                                    </td>

                                    <td><?= date("M d, Y", strtotime($row['created_at'])); ?></td>
                                </tr>
                                <?php } ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="8" style="text-align:center; color: #94a3b8; padding: 40px;">
                                        <div class="empty-state">
                                            <i class="fas fa-tasks" style="font-size: 48px; margin-bottom: 16px; color: #cbd5e1;"></i>
                                            <p>No tasks found for your department.</p>
                                        </div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- PAGINATION UI -->
                <?php if ($total_pages > 1): ?>
                <div class="pagination-glass">
                    <!-- Previous Link -->
                    <a href="?page=<?= $page - 1; ?>&search=<?= urlencode($search); ?>" 
                       class="page-btn prev <?= ($page <= 1) ? 'disabled' : ''; ?>">
                        Previous
                    </a>

                    <div class="page-numbers">
                        <!-- Page Numbers -->
                        <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                            <a href="?page=<?= $i; ?>&search=<?= urlencode($search); ?>" 
                               class="page-btn <?= ($i == $page) ? 'active' : ''; ?>">
                                <?= $i; ?>
                            </a>
                        <?php endfor; ?>
                    </div>

                    <!-- Next Link -->
                    <a href="?page=<?= $page + 1; ?>&search=<?= urlencode($search); ?>" 
                       class="page-btn next <?= ($page >= $total_pages) ? 'disabled' : ''; ?>">
                        Next
                    </a>
                </div>
                <?php endif; ?>

            </div>

        </div>
    </div>

    <!-- Ticket Details Modal (Admin Style) -->
    <div id="ticketModal" class="modal-overlay">
        <div class="modal-content" id="modalContent">
            <!-- Content injected via JS -->
        </div>
    </div>

    <!-- Chat Modal -->
    <div id="chatModal" class="modal-overlay">
        <div class="modal-content" style="width: 560px; max-width: 95%;">
            <div class="tm-header">
                <div class="tm-header-left">
                    <div class="tm-title">Ticket Chat</div>
                    <div class="tm-chat-peer">
                        <div class="tm-peer-avatar" id="chatPeerAvatar">--</div>
                        <div class="tm-peer-meta">
                            <div class="tm-peer-name" id="chatPeerName">-</div>
                            <div class="tm-peer-email" id="chatPeerEmail">-</div>
                        </div>
                    </div>
                </div>
                <button class="tm-close-btn" type="button" onclick="closeChatModal()">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                </button>
            </div>
            <div style="padding: 20px 24px 24px;">
                <div class="chat-wrapper" style="margin-top: 0;">
                    <div id="chatMessages" class="chat-messages">
                        <div style="text-align:center; color:#999; margin-top:20px;">Loading chat...</div>
                    </div>
                    <div class="chat-input-area">
                        <input type="hidden" id="chatTicketId" value="">
                        <input type="text" id="chatInput" placeholder="Type a message..." autocomplete="off">
                        <button id="chatSendBtn" type="button">➤</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Image Preview Modal -->
    <div id="imagePreviewModal" class="image-preview-modal" onclick="closeImagePreview(event)">
        <div class="preview-content">
            <button class="preview-close" onclick="closeImagePreview(event)">×</button>
            <img id="previewImage" src="" alt="Preview" class="preview-image">
        </div>
    </div>

    <script src="../js/employee-dashboard.js"></script>
    <script>
        let typingTimer;
        const doneTypingInterval = 600;

        const searchInput = document.getElementById("searchInput");

        if(searchInput){
            searchInput.addEventListener("keyup", function () {
                clearTimeout(typingTimer);
                typingTimer = setTimeout(doneTyping, doneTypingInterval);
            });

            searchInput.addEventListener("keydown", function () {
                clearTimeout(typingTimer);
            });
        }

        function doneTyping() {
            document.getElementById("filterForm").submit();
        }

        // Chat Functions
        const CURRENT_USER_ID = <?php echo isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 0; ?>;
        let chatInterval;
        let chatUiBound = false;

        function startChat(ticketId) {
            stopChat(); // Clear any existing interval
            loadMessages(ticketId, true); // Initial load with scroll
            chatInterval = setInterval(() => {
                loadMessages(ticketId, false); // Auto-refresh
            }, 3000);
        }

        function stopChat() {
            if (chatInterval) {
                clearInterval(chatInterval);
                chatInterval = null;
            }
        }

        function loadMessages(ticketId, scrollBottom = false) {
            const formData = new FormData();
            formData.append('ticket_id', ticketId);

            fetch('chat_fetch.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.error) {
                    console.error('Chat Error:', data.error);
                    return;
                }
                renderMessages(data, scrollBottom);
            })
            .catch(err => console.error('Chat Fetch Error:', err));
        }

        function renderMessages(messages, scrollBottom) {
            const container = document.getElementById('chatMessages');
            if (!container) return;

            // Preserve scroll position if refreshing
            const isNearBottom = container.scrollHeight - container.scrollTop - container.clientHeight < 100;
            
            // Clear and rebuild
            container.innerHTML = '';

            if (messages.length === 0) {
                container.innerHTML = '<div style="text-align:center; color:#ccc; margin-top:20px;">No messages yet.</div>';
                return;
            }

            messages.forEach(msg => {
                const bubble = document.createElement("div");
                bubble.classList.add("chat-bubble");
                
                if (msg.is_me) {
                    bubble.classList.add("me");
                } else {
                    bubble.classList.add("other");
                }
                
                // Message Content
                const contentDiv = document.createElement("div");
                contentDiv.textContent = msg.message;
                
                // Time
                const timeDiv = document.createElement("div");
                timeDiv.classList.add("chat-time");
                timeDiv.textContent = msg.created_at; 
                
                bubble.appendChild(contentDiv);
                bubble.appendChild(timeDiv);
                
                container.appendChild(bubble);
            });

            if (scrollBottom || isNearBottom) {
                container.scrollTop = container.scrollHeight;
            }
        }

        function sendMessage() {
            const input = document.getElementById('chatInput');
            const ticketId = document.getElementById('chatTicketId').value;
            const message = input.value.trim();
            const btn = document.getElementById('chatSendBtn');

            if (!message) return;

            if(btn.disabled) return;
            
            btn.disabled = true;
            const originalText = btn.textContent;
            btn.textContent = '...';

            const formData = new FormData();
            formData.append('ticket_id', ticketId);
            formData.append('message', message);

            fetch('chat_send.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                btn.disabled = false;
                btn.textContent = originalText;
                
                if (data.success) {
                    input.value = '';
                    loadMessages(ticketId, true); 
                } else {
                    alert('Error: ' + (data.error || 'Failed to send'));
                }
            })
            .catch(err => {
                btn.disabled = false;
                btn.textContent = originalText;
                console.error(err);
                alert('Network error');
            });
        }

        function bindChatUiOnce() {
            if (chatUiBound) return;
            chatUiBound = true;

            const chatInput = document.getElementById('chatInput');
            const chatSendBtn = document.getElementById('chatSendBtn');

            if (chatInput) {
                chatInput.addEventListener('keypress', function(e) {
                    if (e.key === 'Enter') {
                        sendMessage();
                    }
                });
            }

            if (chatSendBtn) {
                chatSendBtn.addEventListener('click', function() {
                    sendMessage();
                });
            }
        }

        function openChatModal(ticketId) {
            if (!chatModal) return;
            bindChatUiOnce();
            document.getElementById('chatTicketId').value = ticketId;
            document.getElementById('chatMessages').innerHTML = '<div style="text-align:center; color:#999; margin-top:20px;">Loading chat...</div>';
            
            // Update chat header with peer info
            const name = (window.tmChatPeer && window.tmChatPeer.name) ? String(window.tmChatPeer.name) : '';
            const email = (window.tmChatPeer && window.tmChatPeer.email) ? String(window.tmChatPeer.email) : '';
            const nameEl = document.getElementById('chatPeerName');
            const emailEl = document.getElementById('chatPeerEmail');
            const avatarEl = document.getElementById('chatPeerAvatar');
            if (nameEl) nameEl.textContent = name || 'Requestor';
            if (emailEl) emailEl.textContent = email || '';
            if (avatarEl) {
                const initials = (name || email || '--').trim().split(' ').map(p => p[0]).join('').slice(0,2).toUpperCase();
                avatarEl.textContent = initials || '--';
            }
            
            chatModal.style.display = 'flex';
            startChat(ticketId);
            setTimeout(() => {
                const input = document.getElementById('chatInput');
                if (input) input.focus();
            }, 0);
        }

        function closeChatModal() {
            if (!chatModal) return;
            chatModal.style.display = 'none';
            stopChat();
            const input = document.getElementById('chatInput');
            if (input) input.value = '';
        }

        // Modal Logic
        const modal = document.getElementById('ticketModal');
        const modalContent = document.getElementById('modalContent');
        const chatModal = document.getElementById('chatModal');

        document.querySelectorAll('.ticket-row').forEach(row => {
            row.addEventListener('click', function() {
                const ticketId = this.dataset.id;
                openModal(ticketId);
            });
        });

        function openModal(id) {
            modal.style.display = 'flex';
            modalContent.innerHTML = '<div style="padding:40px; text-align:center; color:#64748b;">Loading details...</div>';

            fetch(`get_ticket_details.php?id=${id}`) 
            .then(response => response.json())
            .then(data => {
                if(data.error) {
                    modalContent.innerHTML = `<div style="padding:40px; text-align:center; color:#ef4444;">${data.error}</div>`;
                    return;
                }
                renderTicketDetails(data);
            })
            .catch(err => {
                console.error(err);
                modalContent.innerHTML = '<div style="padding:40px; text-align:center; color:#ef4444;">Failed to load details.</div>';
            });
        }

        function closeModal() {
            modal.style.display = 'none';
            closeChatModal();
        }

        window.onclick = function(event) {
            if (event.target == modal) {
                closeModal();
            }
            if (event.target == chatModal) {
                closeChatModal();
            }
        }

        function escapeHtml(text) {
            if (!text) return '';
            return String(text)
                .replace(/&/g, "&amp;")
                .replace(/</g, "&lt;")
                .replace(/>/g, "&gt;")
                .replace(/"/g, "&quot;")
                .replace(/'/g, "&#039;");
        }

        // Image Preview Logic
        function viewImage(src) {
            const modal = document.getElementById('imagePreviewModal');
            const img = document.getElementById('previewImage');
            img.src = src;
            modal.classList.add('show');
        }

        function closeImagePreview(e) {
            if (e.target.id === 'imagePreviewModal' || e.target.classList.contains('preview-close')) {
                const modal = document.getElementById('imagePreviewModal');
                modal.classList.remove('show');
                setTimeout(() => {
                    document.getElementById('previewImage').src = '';
                }, 300);
            }
        }

        function updateStatusColor(select) {
            if (!select) return;
            const status = select.value;
            select.classList.remove('status-open', 'status-progress', 'status-resolved', 'status-closed');
            
            if (status === 'Open') select.classList.add('status-open');
            else if (status === 'In Progress') select.classList.add('status-progress');
            else if (status === 'Resolved') select.classList.add('status-resolved');
            else if (status === 'Closed') select.classList.add('status-closed');
        }

        // --- Render Helpers ---

        const formatTimelineTime = (dateLike) => {
            if (!dateLike) return '-';
            const d = dateLike instanceof Date ? dateLike : new Date(dateLike);
            if (Number.isNaN(d.getTime())) return '-';
            return d.toLocaleString(undefined, {
                year: 'numeric',
                month: 'short',
                day: 'numeric',
                hour: 'numeric',
                minute: '2-digit'
            });
        };

        const renderTimeline = (ticket) => {
            const createdAt = ticket.created_at ? new Date(ticket.created_at) : null;
            const updatedAt = ticket.updated_at ? new Date(ticket.updated_at) : null;
            const fallbackWhen = updatedAt || createdAt;

            const events = [
                { title: 'Ticket created', when: createdAt }
            ];

            if (ticket.assigned_department) {
                events.push({ title: `Assigned to ${ticket.assigned_department}`, when: fallbackWhen });
            }

            if (ticket.admin_note && String(ticket.admin_note).trim() !== '') {
                events.push({ title: 'Admin added a note', when: fallbackWhen });
            }

            if (ticket.status && ticket.status !== 'Open') {
                events.push({ title: `Status changed to ${ticket.status}`, when: fallbackWhen });
            }

            return `
                <div class="tm-timeline">
                    ${events.map(e => `
                        <div class="tm-timeline-item">
                            <div class="tm-timeline-title">${escapeHtml(e.title)}</div>
                            <div class="tm-timeline-time">${formatTimelineTime(e.when)}</div>
                        </div>
                    `).join('')}
                </div>
            `;
        };

        const renderAttachment = (filename) => {
            const ext = filename.split('.').pop().toLowerCase();
            const isImage = ['jpg', 'jpeg', 'png', 'gif', 'webp'].includes(ext);
            
            let viewBtn = '';
            if (isImage) {
                viewBtn = `
                    <button type="button" class="tm-view-btn" data-src="../uploads/${escapeHtml(filename)}" onclick="viewImage(this.dataset.src)">
                        <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="margin-right:4px;"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path></svg>
                        View
                    </button>
                `;
            }

            return `
            <div class="tm-attachment">
                <div class="tm-file-info">
                    <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13"></path></svg>
                    <span>${escapeHtml(filename)}</span>
                </div>
                <div class="tm-actions">
                    ${viewBtn}
                    <a href="../uploads/${filename}" target="_blank" class="tm-download-btn">Download</a>
                </div>
            </div>
        `;
        };

        const computeResolutionMinutes = (createdAt, updatedAt) => {
            if (!createdAt || !updatedAt) return null;
            const c = new Date(createdAt);
            const u = new Date(updatedAt);
            if (Number.isNaN(c.getTime()) || Number.isNaN(u.getTime())) return null;
            const diffMs = u.getTime() - c.getTime();
            if (diffMs <= 0) return null;
            return Math.round(diffMs / 60000); // minutes
        };

        const formatResolutionString = (minutes) => {
            if (minutes == null) return null;
            if (minutes < 60) return `${minutes} mins`;
            const hrs = Math.floor(minutes / 60);
            const mins = minutes % 60;
            if (mins === 0) return `${hrs} ${hrs === 1 ? 'hr' : 'hrs'}`;
            return `${hrs} ${hrs === 1 ? 'hr' : 'hrs'} ${mins} mins`;
        };

        const getDurationClass = (durationStr, minutes) => {
            if (typeof minutes === 'number') {
                if (minutes < 30) return 'green';
                if (minutes <= 120) return 'yellow';
                return 'red';
            }
            if (!durationStr) return 'neutral';
            const s = String(durationStr).toLowerCase();
            if (s.includes('in progress') || s.includes('not started')) return 'neutral';
            let hrs = 0, mins = 0;
            const hMatch = s.match(/(\d+)\s*h(?:r|our)s?/);
            const mMatch = s.match(/(\d+)\s*m(?:in)?s?/);
            if (hMatch) hrs = parseInt(hMatch[1], 10) || 0;
            if (mMatch) mins = parseInt(mMatch[1], 10) || 0;
            const total = hrs * 60 + mins;
            if (total === 0) return 'neutral';
            if (total < 30) return 'green';
            if (total <= 120) return 'yellow';
            return 'red';
        };

        function renderTicketDetails(data) {
            const statusSlug = data.status ? data.status.toLowerCase().replace(/\s+/g, '') : 'default';
            const prioritySlug = data.priority ? data.priority.toLowerCase() : 'default';

            let html = `
                <div class="tm-header">
                    <div class="tm-header-left">
                        <div class="tm-title">${escapeHtml(data.subject)}</div>
                        <div class="tm-chips">
                            <span class="tm-chip tm-chip-${statusSlug}">${escapeHtml(data.status)}</span>
                            <span class="tm-chip tm-chip-${prioritySlug}">${escapeHtml(data.priority)}</span>
                            <span class="tm-id">#${data.id.toString().padStart(6, '0')}</span>
                        </div>
                    </div>
                    <button class="tm-close-btn" onclick="closeModal()">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                    </button>
                </div>

                <div class="tm-body">
                    <div class="tm-info-col">
                        <div class="tm-card">
                            <div class="tm-card-header">
                                <span class="tm-card-title">Ticket Information</span>
                            </div>
                            <div class="tm-card-body">
                                <div class="tm-info-grid">
                                    <div class="tm-info-label">CREATED BY</div>
                                    <div class="tm-info-value">${data.created_by_name ? escapeHtml(String(data.created_by_name)) : '-'}</div>

                                    <div class="tm-info-label">EMAIL</div>
                                    <div class="tm-info-value">${data.created_by_email ? escapeHtml(String(data.created_by_email)) : '-'}</div>

                                    <div class="tm-info-label">DEPARTMENT</div>
                                    <div class="tm-info-value">${data.department ? escapeHtml(String(data.department)) : '-'}</div>

                                    <div class="tm-info-label">COMPANY</div>
                                    <div class="tm-info-value">${data.company ? escapeHtml(String(data.company)) : '-'}</div>

                                    <div class="tm-info-label">CREATED AT</div>
                                    <div class="tm-info-value">${data.created_at ? formatTimelineTime(data.created_at) : '-'}</div>

                                    <div class="tm-info-label">LAST UPDATED</div>
                                    <div class="tm-info-value">${data.updated_at ? formatTimelineTime(data.updated_at) : '-'}</div>

                                    <div class="tm-info-label">ASSIGNED TO</div>
                                    <div class="tm-info-value">
                                        ${data.assigned_department ? escapeHtml(String(data.assigned_department)) : '-'}
                                        ${data.assigned_company ? `<br><small class="text-muted">(${escapeHtml(String(data.assigned_company))})</small>` : ''}
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="tm-card">
                            <div class="tm-card-header">
                                <span class="tm-card-title">Ticket Activity</span>
                            </div>
                            <div class="tm-card-body">
                                ${renderTimeline(data)}
                            </div>
                        </div>
                    </div>

                    <div class="tm-desc-col">
                        <div class="tm-card">
                            <div class="tm-card-header">
                                <span class="tm-card-title">Description</span>
                            </div>
                            <div class="tm-card-body">
                                <div class="tm-desc-text">${escapeHtml(data.description)}</div>
                                ${data.attachment ? renderAttachment(data.attachment) : ''}
                            </div>
                        </div>

                        ${(data.impact && data.impact !== '-') ? `
                            <div class="tm-card">
                                <div class="tm-card-header">
                                    <span class="tm-card-title">Impact</span>
                                </div>
                                <div class="tm-card-body">
                                    <div class="tm-info-value">${escapeHtml(String(data.impact))}</div>
                                </div>
                            </div>
                        ` : ''}

                        ${(data.urgency && data.urgency !== '-') ? `
                            <div class="tm-card">
                                <div class="tm-card-header">
                                    <span class="tm-card-title">Urgency</span>
                                </div>
                                <div class="tm-card-body">
                                    <div class="tm-info-value">${escapeHtml(String(data.urgency))}</div>
                                </div>
                            </div>
                        ` : ''}

                        <div class="tm-card">
                            <div class="tm-card-header">
                                <span class="tm-card-title">Admin Note (Visible to Requestor)</span>
                            </div>
                            <div class="tm-card-body">
                                <textarea 
                                    name="admin_note" 
                                    form="ticketUpdateForm"
                                    class="tm-admin-note"
                                    placeholder="Enter a note for the requestor..."
                                >${data.admin_note ? escapeHtml(data.admin_note) : ''}</textarea>
                                <div class="tm-duration-block">
                                    <div class="tm-duration-title">Resolution Time</div>
                                    ${
                                        (() => {
                                            const minutes = computeResolutionMinutes(data.created_at, data.updated_at);
                                            const backendStr = data.duration && !/^(in progress|not started)$/i.test(String(data.duration)) 
                                                ? String(data.duration) 
                                                : null;
                                            const displayStr = backendStr || formatResolutionString(minutes);
                                            const cls = getDurationClass(backendStr, minutes);
                                            return displayStr
                                                ? `<span class="tm-duration-badge ${cls}">${escapeHtml(displayStr)}</span>`
                                                : `<span class="tm-duration-badge neutral">-</span>`;
                                        })()
                                    }
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="tm-footer">
                    <div class="tm-action-bar">
                        <span class="tm-action-title">Ticket Actions</span>
                        <form id="ticketUpdateForm" method="POST" action="update_ticket.php" class="tm-action-form">
                            <input type="hidden" name="id" value="${data.id}">
                            
                            <div class="tm-action-controls-v2" style="display: flex; flex-direction: column; gap: 24px;">
                                
                                <!-- ROW 1: Dropdowns -->
                                <div style="display: flex; flex-wrap: wrap; gap: 20px; align-items: flex-start;">
                                    
                                    <!-- Status -->
                                    <div style="flex: 1; min-width: 200px; display: flex; flex-direction: column; gap: 8px;">
                                        <label class="tm-control-label" style="font-size: 0.85rem; color: #64748b; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em;">Status</label>
                                        <div class="tm-select-wrapper" style="width: 100%;">
                                            <select class="tm-select tm-status-select" name="status" onchange="updateStatusColor(this)" style="width: 100%; height: 42px;">
                                                <option value="Open" ${data.status === 'Open' ? 'selected' : ''}>Open</option>
                                                <option value="In Progress" ${data.status === 'In Progress' ? 'selected' : ''}>In Progress</option>
                                                <option value="Resolved" ${data.status === 'Resolved' ? 'selected' : ''}>Resolved</option>
                                                <option value="Closed" ${data.status === 'Closed' ? 'selected' : ''}>Closed</option>
                                            </select>
                                        </div>
                                    </div>

                                    <!-- Department -->
                                    <div style="flex: 1; min-width: 200px; display: flex; flex-direction: column; gap: 8px;">
                                        <label class="tm-control-label" style="font-size: 0.85rem; color: #64748b; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em;">Assign Dept</label>
                                        <div class="tm-dept-wrapper" style="width: 100%;">
                                            <span class="tm-dept-icon" style="top: 50%; transform: translateY(-50%);">🏢</span>
                                            <select class="tm-select tm-dept-select" name="assigned_department" style="width: 100%; height: 42px;">
                                                <option value="" selected>Assign Department</option>
                                                <option value="IT" ${data.assigned_department === 'IT' ? 'selected' : ''}>IT</option>
                                                <option value="HR" ${data.assigned_department === 'HR' ? 'selected' : ''}>HR</option>
                                                <option value="Marketing" ${data.assigned_department === 'Marketing' ? 'selected' : ''}>Marketing</option>
                                                <option value="Admin" ${data.assigned_department === 'Admin' ? 'selected' : ''}>Admin</option>
                                                <option value="Technical" ${data.assigned_department === 'Technical' ? 'selected' : ''}>Technical</option>
                                                <option value="Accounting" ${data.assigned_department === 'Accounting' ? 'selected' : ''}>Accounting</option>
                                                <option value="Supply Chain" ${data.assigned_department === 'Supply Chain' ? 'selected' : ''}>Supply Chain</option>
                                                <option value="MPDC" ${data.assigned_department === 'MPDC' ? 'selected' : ''}>MPDC</option>
                                                <option value="E-Comm" ${data.assigned_department === 'E-Comm' ? 'selected' : ''}>E-Comm</option>
                                                <option value="Sales" ${data.assigned_department === 'Sales' ? 'selected' : ''}>Sales</option>
                                                <option value="Bidding" ${data.assigned_department === 'Bidding' ? 'selected' : ''}>Bidding</option>
                                            </select>
                                        </div>
                                    </div>

                                    <!-- Company -->
                                    <div style="flex: 1; min-width: 200px; display: flex; flex-direction: column; gap: 8px;">
                                        <label class="tm-control-label" style="font-size: 0.85rem; color: #64748b; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em;">Assign Company</label>
                                        <div class="tm-dept-wrapper" style="width: 100%;">
                                            <span class="tm-dept-icon" style="top: 50%; transform: translateY(-50%);">🏢</span>
                                            <select class="tm-select tm-dept-select" name="assigned_company" style="width: 100%; height: 42px;">
                                                <option value="" disabled ${!data.assigned_company ? 'selected' : ''}>Select Company</option>
                                                <option value="FARMEX" ${data.assigned_company === 'FARMEX' ? 'selected' : ''}>FARMEX</option>
                                                <option value="FARMASEE" ${data.assigned_company === 'FARMASEE' ? 'selected' : ''}>FARMASEE</option>
                                                <option value="Golden Primestocks Chemical Inc - GPSCI" ${data.assigned_company === 'Golden Primestocks Chemical Inc - GPSCI' ? 'selected' : ''}>Golden Primestocks Chemical Inc - GPSCI</option>
                                                <option value="Leads Animal Health - LAH" ${data.assigned_company === 'Leads Animal Health - LAH' ? 'selected' : ''}>Leads Animal Health - LAH</option>
                                                <option value="Leads Environmental Health - LEH" ${data.assigned_company === 'Leads Environmental Health - LEH' ? 'selected' : ''}>Leads Environmental Health - LEH</option>
                                                <option value="Leads Tech Corporation - LTC" ${data.assigned_company === 'Leads Tech Corporation - LTC' ? 'selected' : ''}>Leads Tech Corporation - LTC</option>
                                                <option value="LINGAP LEADS FOUNDATION - Lingap" ${data.assigned_company === 'LINGAP LEADS FOUNDATION - Lingap' ? 'selected' : ''}>LINGAP LEADS FOUNDATION - Lingap</option>
                                                <option value="Malveda Holdings Corporation - MHC" ${data.assigned_company === 'Malveda Holdings Corporation - MHC' ? 'selected' : ''}>Malveda Holdings Corporation - MHC</option>
                                                <option value="Malveda Properties & Development Corporation - MPDC" ${data.assigned_company === 'Malveda Properties & Development Corporation - MPDC' ? 'selected' : ''}>Malveda Properties & Development Corporation - MPDC</option>
                                                <option value="Primestocks Chemical Corporation - PCC" ${data.assigned_company === 'Primestocks Chemical Corporation - PCC' ? 'selected' : ''}>Primestocks Chemical Corporation - PCC</option>
                                            </select>
                                        </div>
                                    </div>

                                </div>

                                <!-- ROW 2: Actions -->
                                <div style="display: flex; justify-content: flex-end; align-items: center; gap: 12px; padding-top: 24px; border-top: 1px solid #e2e8f0;">
                                    
                                    <div class="tm-chat-action" onclick="openChatModal(${data.id})" style="margin-right: auto; height: 42px; padding: 0 20px;">
                                        <span class="tm-chat-icon">💬</span>
                                        <span>Chat</span>
                                    </div>

                                    <button type="button" class="tm-btn tm-btn-secondary" onclick="closeModal()" style="height: 42px;">Close</button>
                                    <button type="submit" class="tm-btn tm-btn-primary" style="height: 42px; min-width: 120px;">Save Ticket</button>
                                </div>

                            </div>
                        </form>
                    </div>
                </div>
            `;

            modalContent.innerHTML = html;
            
            setTimeout(() => {
                const statusSelect = modalContent.querySelector('.tm-status-select');
                if(statusSelect) updateStatusColor(statusSelect);

                // Store peer info for chat header
                window.tmChatPeer = {
                    name: data.created_by_name || '',
                    email: data.created_by_email || ''
                };
            }, 0);
        }

        // Auto-open modal if ID is in URL
        const urlParams = new URLSearchParams(window.location.search);
        const ticketIdParam = urlParams.get('id');
        if (ticketIdParam) {
            openModal(ticketIdParam);
        }
    </script>
</body>
</html>