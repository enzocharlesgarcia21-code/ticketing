<?php
require_once '../config/database.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method Not Allowed']);
    exit;
}

if (!isset($_POST['ticket_id']) || !isset($_POST['message'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing Parameters']);
    exit;
}

$ticket_id = (int)$_POST['ticket_id'];
$message = trim($_POST['message']);
$sender_id = $_SESSION['user_id'];

if (empty($message)) {
    echo json_encode(['success' => false, 'error' => 'Empty message']);
    exit;
}

// Access Control
if ($_SESSION['role'] !== 'admin') {
     // Verify if the ticket belongs to the user
     $check = $conn->prepare("SELECT id FROM employee_tickets WHERE id = ? AND user_id = ?");
     $check->bind_param("ii", $ticket_id, $sender_id);
     $check->execute();
     if ($check->get_result()->num_rows === 0) {
         http_response_code(403);
         echo json_encode(['error' => 'Access Denied']);
         exit;
     }
}

// Insert Message
$stmt = $conn->prepare("INSERT INTO ticket_messages (ticket_id, sender_id, message) VALUES (?, ?, ?)");
$stmt->bind_param("iis", $ticket_id, $sender_id, $message);

if ($stmt->execute()) {
    echo json_encode(['success' => true]);
} else {
    http_response_code(500);
    echo json_encode(['error' => 'Database Error']);
}
?>