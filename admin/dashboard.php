<?php
require_once '../config/database.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: admin_login.php");
    exit();
}

// Ensure email is in session (fix for existing sessions)
if (!isset($_SESSION['email']) && isset($_SESSION['user_id'])) {
    $u_stmt = $conn->prepare("SELECT email FROM users WHERE id = ?");
    $u_stmt->bind_param("i", $_SESSION['user_id']);
    $u_stmt->execute();
    $u_res = $u_stmt->get_result();
    if ($u_row = $u_res->fetch_assoc()) {
        $_SESSION['email'] = $u_row['email'];
    }
}

/* Summary Counts */
$total = $conn->query("SELECT COUNT(*) AS count FROM employee_tickets")
              ->fetch_assoc()['count'];

$open = $conn->query("SELECT COUNT(*) AS count FROM employee_tickets WHERE status='Open'")
             ->fetch_assoc()['count'];

$progress = $conn->query("SELECT COUNT(*) AS count FROM employee_tickets WHERE status='In Progress'")
                 ->fetch_assoc()['count'];

$resolved = $conn->query("SELECT COUNT(*) AS count FROM employee_tickets WHERE status='Resolved'")
                 ->fetch_assoc()['count'];
/* ===== DEPARTMENT DATA ===== */

$deptQuery = $conn->query("
    SELECT assigned_department, COUNT(*) as count
    FROM employee_tickets
    GROUP BY assigned_department
");

$departments = [];
$deptCounts = [];

while($row = $deptQuery->fetch_assoc()) {
    $departments[] = $row['assigned_department'];
    $deptCounts[] = $row['count'];
}

/* ===== PRIORITY DATA ===== */

$priorityQuery = $conn->query("
    SELECT priority, COUNT(*) as count
    FROM employee_tickets
    GROUP BY priority
");

$priorities = [];
$priorityCounts = [];

while($row = $priorityQuery->fetch_assoc()) {
    $priorities[] = $row['priority'];
    $priorityCounts[] = $row['count'];
}
?>


<!DOCTYPE html>
<html>
<head>
    <title>Admin Dashboard</title>
    <link rel="stylesheet" href="../css/admin.css?v=<?php echo time(); ?>">
</head>
<body>

<div class="admin-page">

    <?php include '../includes/admin_navbar.php'; ?>

    <div class="admin-container">
        <div class="admin-content">

            <div class="admin-page-header">
                <div>
                    <div class="admin-page-title">Admin Dashboard</div>
                    <div class="admin-page-subtitle">
                        Overview of ticket activity and system performance.
                    </div>
                </div>
            </div>

            <section class="admin-stats-grid">
                <div class="admin-stat-card">
                    <div class="admin-stat-icon total">⏱</div>
                    <div class="admin-stat-label">Total Tickets</div>
                    <div class="admin-stat-value"><?= $total ?></div>
                </div>

                <div class="admin-stat-card">
                    <div class="admin-stat-icon open">📂</div>
                    <div class="admin-stat-label">Open</div>
                    <div class="admin-stat-value"><?= $open ?></div>
                </div>

                <div class="admin-stat-card">
                    <div class="admin-stat-icon progress">⚙️</div>
                    <div class="admin-stat-label">In Progress</div>
                    <div class="admin-stat-value"><?= $progress ?></div>
                </div>

                <div class="admin-stat-card">
                    <div class="admin-stat-icon resolved">✅</div>
                    <div class="admin-stat-label">Resolved</div>
                    <div class="admin-stat-value"><?= $resolved ?></div>
                </div>
            </section>

            <section class="admin-analytics-section">
                <div class="admin-card">
                    <h3>Tickets by Department</h3>
                    <div class="chart-container">
                        <canvas id="deptChart"></canvas>
                    </div>
                </div>

                <div class="admin-card">
                    <h3>Tickets by Priority</h3>
                    <div class="chart-container">
                        <canvas id="priorityChart" width="400" height="400"></canvas>
                    </div>
                </div>
            </section>

        </div>
    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="../js/admin.js"></script>

<script>
new Chart(document.getElementById('deptChart'), {
    type: 'bar',
    data: {
        labels: <?= json_encode($departments); ?>,
        datasets: [{
            data: <?= json_encode($deptCounts); ?>,
            backgroundColor: '#1B5E20',
            borderRadius: 6
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        devicePixelRatio: 2,
        plugins: { legend: { display: false } },
        scales: { y: { beginAtZero: true } }
    }
});

new Chart(document.getElementById('priorityChart'), {
    type: 'pie',
    data: {
        labels: <?= json_encode($priorities); ?>,
        datasets: [{
            data: <?= json_encode($priorityCounts); ?>,
            backgroundColor: [
                '#1B5E20',   // Low (Primary Green)
                '#F4C430',   // Medium (Accent Yellow)
                '#F97316',   // High (Orange)
                '#DC2626'    // Critical (Red)
            ]
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        devicePixelRatio: 2,
        plugins: { 
            legend: { 
                labels: { 
                    font: { 
                        size: 14, 
                        weight: 'bold' 
                    } 
                } 
            } 
        }
    }
});
</script>
</body>
</html>
