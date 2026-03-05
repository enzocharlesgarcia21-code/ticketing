<?php
require_once '../config/database.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
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

// 🟢 START TIMER LOGIC (Only for Admin)
// If admin views the ticket and started_at is NULL, set it to NOW()
$checkStmt = $conn->prepare("SELECT started_at FROM employee_tickets WHERE id = ?");
$checkStmt->bind_param("i", $id);
$checkStmt->execute();
$checkResult = $checkStmt->get_result();

if ($checkResult->num_rows > 0) {
    $ticketData = $checkResult->fetch_assoc();
    if (is_null($ticketData['started_at'])) {
        $updateStart = $conn->prepare("UPDATE employee_tickets SET started_at = NOW() WHERE id = ?");
        $updateStart->bind_param("i", $id);
        $updateStart->execute();
    }
}

$stmt = $conn->prepare("
    SELECT 
        t.*, 
        u.name as created_by_name, 
        u.email as created_by_email, 
        u.company as user_company,
        u.department as user_department
    FROM employee_tickets t 
    JOIN users u ON t.user_id = u.id 
    WHERE t.id = ?
");
$stmt->bind_param("i", $id);
$stmt->execute();
$result = $stmt->get_result();

if ($row = $result->fetch_assoc()) {
    // Fallbacks for display
    $row['company'] = !empty($row['company']) ? $row['company'] : $row['user_company'];
    $row['department'] = !empty($row['department']) ? $row['department'] : ($row['user_department'] ?? null);
    
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
