<?php
require_once '../config/database.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: dashboard.php");
    exit();
}

// Ensure email is in session
if (!isset($_SESSION['email']) && isset($_SESSION['user_id'])) {
    $u_stmt = $conn->prepare("SELECT email FROM users WHERE id = ?");
    $u_stmt->bind_param("i", $_SESSION['user_id']);
    $u_stmt->execute();
    $u_res = $u_stmt->get_result();
    if ($u_row = $u_res->fetch_assoc()) {
        $_SESSION['email'] = $u_row['email'];
    }
}

$message = '';

// Handle Promotion Logic
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['promote_user_id'])) {
    $promote_id = (int)$_POST['promote_user_id'];
    
    // Double check if the user is eligible (IT department, employee role)
    // although the query only shows them, it's good to be safe
    $check_stmt = $conn->prepare("SELECT id FROM users WHERE id = ? AND department = 'IT' AND role = 'employee'");
    $check_stmt->bind_param("i", $promote_id);
    $check_stmt->execute();
    if ($check_stmt->get_result()->num_rows > 0) {
        $update_stmt = $conn->prepare("UPDATE users SET role = 'admin' WHERE id = ?");
        $update_stmt->bind_param("i", $promote_id);
        
        if ($update_stmt->execute()) {
            $message = "User successfully promoted to Admin.";
        } else {
            $message = "Error promoting user.";
        }
    } else {
        $message = "Invalid user selected or user is not eligible.";
    }
}

// Query IT Employees
$query = "SELECT id, name, email, department FROM users WHERE department = 'IT' AND role = 'employee'";
$result = $conn->query($query);

// Query Current IT Admins
$admins_query = "SELECT id, name, email FROM users WHERE department = 'IT' AND role = 'admin'";
$admins_result = $conn->query($admins_query);

?>

<!DOCTYPE html>
<html>
<head>
    <title>Admin Management</title>
    <link rel="stylesheet" href="../css/admin.css?v=<?php echo time(); ?>">
    <style>
        .create-admin-container {
            padding: 20px;
            max-width: 1000px;
            margin: 0 auto;
        }
        .user-table {
            width: 100%;
            border-collapse: collapse;
            background: white;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            margin-top: 20px;
        }
        .user-table th, .user-table td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid #eee;
        }
        .user-table th {
            background-color: #f8f9fa;
            font-weight: 600;
            color: #333;
        }
        .promote-btn {
            background-color: #28a745;
            color: white;
            border: none;
            padding: 8px 12px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
        }
        .promote-btn:hover {
            background-color: #218838;
        }
        .alert-success {
            padding: 10px;
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
            border-radius: 4px;
            margin-bottom: 20px;
        }

        /* --- New Admin Grid Styles --- */
        .section-title {
            margin-top: 50px;
            margin-bottom: 20px;
            font-size: 20px;
            font-weight: 600;
            color: #1B5E20; /* Primary Green */
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .section-title::before {
            content: '';
            display: block;
            width: 4px;
            height: 24px;
            background: #F4C430; /* Accent Yellow */
            border-radius: 2px;
        }

        .admin-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 24px;
        }

        .admin-card {
            background: white;
            border-radius: 16px;
            padding: 24px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.05);
            border: 1px solid #e5e7eb;
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
            transition: transform 0.2s, box-shadow 0.2s;
            position: relative;
            overflow: hidden;
        }

        .admin-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 20px rgba(0,0,0,0.08);
            border-color: #1B5E20;
        }

        .admin-card::after {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #1B5E20, #144a1e);
        }

        .admin-avatar {
            width: 64px;
            height: 64px;
            background-color: #e6f4ea;
            color: #1B5E20;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            font-weight: 700;
            margin-bottom: 16px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }

        .admin-name {
            font-size: 16px;
            font-weight: 600;
            color: #1F2937;
            margin-bottom: 4px;
        }

        .admin-email {
            font-size: 13px;
            color: #6B7280;
            margin-bottom: 16px;
        }

        .admin-badge {
            background-color: #dcfce7;
            color: #166534;
            padding: 6px 14px;
            border-radius: 99px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border: 1px solid #bbf7d0;
        }
    </style>
</head>
<body>

<div class="admin-page">

    <?php include '../includes/admin_navbar.php'; ?>

    <div class="create-admin-container">
        <h2>Promote IT Employees to Admin</h2>
        
        <?php if ($message): ?>
            <div class="alert-success"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

        <table class="user-table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Department</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($result->num_rows > 0): ?>
                    <?php while($row = $result->fetch_assoc()): ?>
                        <tr>
                            <td><?= htmlspecialchars($row['name']) ?></td>
                            <td><?= htmlspecialchars($row['email']) ?></td>
                            <td><?= htmlspecialchars($row['department']) ?></td>
                            <td>
                                <form method="POST" onsubmit="return confirm('Are you sure you want to add this user as Admin?');">
                                    <input type="hidden" name="promote_user_id" value="<?= $row['id'] ?>">
                                    <button type="submit" class="promote-btn">Add as Admin</button>
                                </form>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="4" style="text-align: center;">No eligible IT employees found.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>

        <!-- New Section: Current IT Admins -->
        <h3 class="section-title">Current IT Admins</h3>

        <div class="admin-grid">
            <?php if ($admins_result->num_rows > 0): ?>
                <?php while($admin = $admins_result->fetch_assoc()): ?>
                    <div class="admin-card">
                        <div class="admin-avatar">
                            <?= strtoupper(substr($admin['name'], 0, 1)) ?>
                        </div>
                        <div class="admin-name"><?= htmlspecialchars($admin['name']) ?></div>
                        <div class="admin-email"><?= htmlspecialchars($admin['email']) ?></div>
                        <span class="admin-badge">Admin</span>
                    </div>
                <?php endwhile; ?>
            <?php else: ?>
                <p style="color: #6B7280;">No IT Admins found.</p>
            <?php endif; ?>
        </div>

    </div>

</div>

<script src="../js/admin.js"></script>

</body>
</html>
