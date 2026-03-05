<?php
require_once '../config/database.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'employee') {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

if (!isset($_GET['id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing ID']);
    exit;
}

$id = (int)$_GET['id'];

/* Mark notifications as read for this ticket */
$user_id = $_SESSION['user_id'];
$conn->query("UPDATE notifications SET is_read = 1 WHERE ticket_id = $id AND user_id = $user_id");

// 🟢 START TIMER LOGIC (For Employees working on the ticket)
// Only if the ticket is assigned to their department
$dept = $_SESSION['department'];
// Fetch user company if not in session
if (!isset($_SESSION['company'])) {
    $c_stmt = $conn->prepare("SELECT company FROM users WHERE id = ?");
    $c_stmt->bind_param("i", $_SESSION['user_id']);
    $c_stmt->execute();
    $c_res = $c_stmt->get_result();
    if ($c_row = $c_res->fetch_assoc()) {
        $_SESSION['company'] = $c_row['company'];
    }
}
$company = $_SESSION['company'];

$checkStmt = $conn->prepare("SELECT started_at, assigned_department, assigned_company FROM employee_tickets WHERE id = ?");
$checkStmt->bind_param("i", $id);
$checkStmt->execute();
$checkResult = $checkStmt->get_result();

if ($checkResult->num_rows > 0) {
    $ticketData = $checkResult->fetch_assoc();
    // Only start timer if it's assigned to their department AND company and hasn't started
    if ($ticketData['assigned_department'] === $dept && $ticketData['assigned_company'] === $company && is_null($ticketData['started_at'])) {
        $updateStart = $conn->prepare("UPDATE employee_tickets SET started_at = NOW() WHERE id = ?");
        $updateStart->bind_param("i", $id);
        $updateStart->execute();
    }
}

$stmt = $conn->prepare("
    SELECT t.*, u.name as created_by_name, u.email as created_by_email, u.company as user_company 
    FROM employee_tickets t 
    JOIN users u ON t.user_id = u.id 
    WHERE t.id = ? AND ((t.assigned_department = ? AND t.assigned_company = ?) OR t.user_id = ?)
");
$stmt->bind_param("issi", $id, $dept, $company, $_SESSION['user_id']);
$stmt->execute();
$result = $stmt->get_result();

if ($row = $result->fetch_assoc()) {
    // Use ticket company if set, otherwise user company
    $row['company'] = !empty($row['company']) ? $row['company'] : $row['user_company'];

    // Calculate Duration
    $duration = "Not Started";
    if (!is_null($row['started_at'])) {
        if (is_null($row['resolved_at'])) {
            $duration = "In Progress";
        } else {
            $start = new DateTime($row['started_at']);
            $end = new DateTime($row['resolved_at']);
            $diff = $start->diff($end);
            
            $parts = [];
            if ($diff->d > 0) $parts[] = $diff->d . " days";
            if ($diff->h > 0) $parts[] = $diff->h . " hrs";
            if ($diff->i > 0) $parts[] = $diff->i . " mins";
            
            $duration = empty($parts) ? "< 1 min" : implode(" ", $parts);
        }
    }
    $row['duration'] = $duration;

    echo json_encode($row);
} else {
    http_response_code(404);
    echo json_encode(['error' => 'Ticket not found']);
}
?>