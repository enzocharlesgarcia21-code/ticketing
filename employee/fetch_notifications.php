<?php
require_once '../config/database.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'employee') {
    echo json_encode(['unread_count' => 0, 'notifications' => []]);
    exit;
}

$user_id = $_SESSION['user_id'];

// Count unread
$count_res = $conn->query("SELECT COUNT(*) as c FROM notifications WHERE user_id = $user_id AND is_read = 0");
$unread_count = $count_res->fetch_assoc()['c'];

// Fetch recent
$notifs = [];
$res = $conn->query("SELECT * FROM notifications WHERE user_id = $user_id ORDER BY created_at DESC LIMIT 10");
while ($row = $res->fetch_assoc()) {
    $notifs[] = [
        'id' => $row['id'],
        'ticket_id' => $row['ticket_id'],
        'message' => $row['message'],
        'type' => $row['type'],
        'is_read' => $row['is_read'],
        'time_ago' => time_elapsed_string($row['created_at'])
    ];
}

echo json_encode(['unread_count' => $unread_count, 'notifications' => $notifs]);

function time_elapsed_string($datetime, $full = false) {
    $now = new DateTime;
    $ago = new DateTime($datetime);
    $diff = $now->diff($ago);

    $diff->w = floor($diff->d / 7);
    $diff->d -= $diff->w * 7;

    $string = array(
        'y' => 'year',
        'm' => 'month',
        'w' => 'week',
        'd' => 'day',
        'h' => 'hour',
        'i' => 'minute',
        's' => 'second',
    );
    foreach ($string as $k => &$v) {
        if ($diff->$k) {
            $v = $diff->$k . ' ' . $v . ($diff->$k > 1 ? 's' : '');
        } else {
            unset($string[$k]);
        }
    }

    if (!$full) $string = array_slice($string, 0, 1);
    return $string ? implode(', ', $string) . ' ago' : 'just now';
}
?>