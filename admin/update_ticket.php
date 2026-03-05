<?php
require_once '../config/database.php';

// Import PHPMailer classes into the global namespace
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

require '../vendor/phpmailer/src/Exception.php';
require '../vendor/phpmailer/src/PHPMailer.php';
require '../vendor/phpmailer/src/SMTP.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: admin_login.php");
    exit();
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {

    if (!isset($_POST['id'])) {
        // Redirect if ID is missing
        header("Location: all_tickets.php");
        exit();
    }

    $id = (int) $_POST['id'];
    $new_status = isset($_POST['status']) ? trim($_POST['status']) : '';
    $new_department = isset($_POST['assigned_department']) ? trim($_POST['assigned_department']) : '';
    $admin_note = isset($_POST['admin_note']) ? trim($_POST['admin_note']) : null;

    if (isset($_GET['debug_status'])) {
        var_dump($_POST['status']);
        exit();
    }

    // --- FETCH OLD DATA FOR COMPARISON & NOTIFICATIONS ---
    $old_stmt = $conn->prepare("SELECT user_id, status, assigned_department, admin_note FROM employee_tickets WHERE id = ?");
    $old_stmt->bind_param("i", $id);
    $old_stmt->execute();
    $old_res = $old_stmt->get_result();
    $old_data = $old_res->fetch_assoc();
    $old_stmt->close();

    // Normalize and validate status, prevent blank status
    $allowed_statuses = ['Open', 'In Progress', 'Resolved', 'Closed'];
    if ($new_status === '' || !in_array($new_status, $allowed_statuses, true)) {
        $new_status = $old_data && isset($old_data['status']) ? $old_data['status'] : 'Open';
    }

    // Update status, department, admin_note and mark as read
    // Also update resolved_at if status is Resolved or Closed AND it hasn't been set yet
    $update = $conn->prepare("
        UPDATE employee_tickets
        SET 
            status = ?, 
            assigned_department = ?, 
            admin_note = ?,
            is_read = 1, 
            updated_at = NOW(),
            resolved_at = CASE 
                WHEN (? = 'Resolved' OR ? = 'Closed') AND resolved_at IS NULL THEN NOW() 
                ELSE resolved_at 
            END
        WHERE id = ?
    ");
    
    $update->bind_param("sssssi", $new_status, $new_department, $admin_note, $new_status, $new_status, $id);
    
    if ($update->execute()) {
        $_SESSION['success'] = "Ticket #$id successfully updated.";

        // --- TICKET ACTIVITY LOG: Status change ---
        if ($old_data && isset($old_data['status']) && $old_data['status'] !== $new_status) {
            $activity_desc = "Status changed to " . $new_status;
            $act = $conn->prepare("INSERT INTO ticket_activity (ticket_id, activity_type, description, created_at) VALUES (?, 'status_change', ?, NOW())");
            if ($act) {
                $act->bind_param("is", $id, $activity_desc);
                $act->execute();
                $act->close();
            }
        }
        // Optional explicit close activity
        if ($new_status === 'Closed') {
            $act2 = $conn->prepare("INSERT INTO ticket_activity (ticket_id, activity_type, description, created_at) VALUES (?, 'status_change', 'Ticket closed', NOW())");
            if ($act2) {
                $act2->bind_param("i", $id);
                $act2->execute();
                $act2->close();
            }
        }

        // --- INSERT NOTIFICATIONS ---
        if ($old_data) {
            $notif_user_id = $old_data['user_id'];
            $notifications = [];

            // 1. Status Change
            if ($old_data['status'] !== $new_status) {
                if ($new_status === 'Resolved' || $new_status === 'Closed') {
                     $notifications[] = [
                        'msg' => "Your ticket #$id has been closed.",
                        'type' => 'ticket_closed'
                    ];
                } else {
                    $notifications[] = [
                        'msg' => "Your ticket #$id status was updated to $new_status.",
                        'type' => 'status_update'
                    ];
                }
            }

            // 2. Department Change
            if ($old_data['assigned_department'] !== $new_department) {
                $notifications[] = [
                    'msg' => "Your ticket #$id was reassigned to $new_department.",
                    'type' => 'reassigned'
                ];

                if ($new_department !== '') {
                    $dept_users_stmt = $conn->prepare("SELECT id FROM users WHERE role = 'employee' AND department = ?");
                    if ($dept_users_stmt) {
                        $dept_users_stmt->bind_param("s", $new_department);
                        $dept_users_stmt->execute();
                        $dept_users_res = $dept_users_stmt->get_result();

                        $dept_notif_stmt = $conn->prepare("INSERT INTO notifications (user_id, ticket_id, message, type) VALUES (?, ?, ?, ?)");
                        if ($dept_notif_stmt) {
                            $dept_type = 'dept_assigned';
                            $dept_msg = "New ticket #$id was assigned to your department.";
                            while ($u = $dept_users_res->fetch_assoc()) {
                                $target_user_id = (int)$u['id'];
                                $dept_notif_stmt->bind_param("iiss", $target_user_id, $id, $dept_msg, $dept_type);
                                $dept_notif_stmt->execute();
                            }
                            $dept_notif_stmt->close();
                        }

                        $dept_users_stmt->close();
                    }
                }
            }

            // 3. Admin Note Added
            if (!empty($admin_note) && $admin_note !== $old_data['admin_note']) {
                 $preview = strlen($admin_note) > 50 ? substr($admin_note, 0, 50) . '...' : $admin_note;
                 $notifications[] = [
                    'msg' => "Admin added a note to ticket #$id: '$preview'",
                    'type' => 'note_added'
                ];
            }

            if (!empty($notifications)) {
                $ins_notif = $conn->prepare("INSERT INTO notifications (user_id, ticket_id, message, type) VALUES (?, ?, ?, ?)");
                foreach ($notifications as $n) {
                    $ins_notif->bind_param("iiss", $notif_user_id, $id, $n['msg'], $n['type']);
                    $ins_notif->execute();
                }
                $ins_notif->close();
            }
        }

        // --- SEND EMAIL NOTIFICATION ---
        // 1. Get user details for this ticket
        $stmt = $conn->prepare("
            SELECT t.subject, t.priority, t.category, u.name, u.email 
            FROM employee_tickets t
            JOIN users u ON t.user_id = u.id
            WHERE t.id = ?
        ");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $res = $stmt->get_result();
        
        if ($ticket = $res->fetch_assoc()) {
            $mail = new PHPMailer(true);

            try {
                //Server settings
                $mail->isSMTP();
                $mail->Host       = 'smtp.gmail.com';
                $mail->SMTPAuth   = true;
                $mail->Username   = 'matthewpascua052203@gmail.com'; 
                $mail->Password   = 'tmwtjqjvadsmgzje'; 
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                $mail->Port       = 587;

                // Disable SSL verification for XAMPP/Localhost
                $mail->SMTPOptions = array(
                    'ssl' => array(
                        'verify_peer' => false,
                        'verify_peer_name' => false,
                        'allow_self_signed' => true
                    )
                );

                //Recipients
                $mail->setFrom('matthewpascua052203@gmail.com', 'Leads Agri Helpdesk');
                $mail->addAddress($ticket['email'], $ticket['name']);

                //Content
                $mail->isHTML(true);
                $mail->Subject = "Ticket Update: #$id - " . $ticket['subject'];
                
                $adminNoteHtml = '';
                if (!empty($admin_note)) {
                    $adminNoteHtml = "
                        <div style='background-color: #f0fdf4; border-left: 4px solid #16a34a; padding: 15px; margin: 15px 0; color: #14532d;'>
                            <strong style='color: #166534;'>Admin Note:</strong><br>
                            " . nl2br(htmlspecialchars($admin_note)) . "
                        </div>
                    ";
                }

                $mail->Body    = "
                    <div style='font-family: Arial, sans-serif; color: #333;'>
                        <h2>Ticket Updated</h2>
                        <p>Hello <strong>{$ticket['name']}</strong>,</p>
                        <p>Your ticket <strong>#$id</strong> has been updated by the admin.</p>
                        <hr>
                        <p><strong>Status:</strong> <span style='color: #1B5E20; font-weight: bold;'>$new_status</span></p>
                        <p><strong>Assigned To:</strong> $new_department</p>
                        $adminNoteHtml
                        <hr>
                        <p>You can view the full details by logging into your dashboard.</p>
                        <p>Best regards,<br>Leads Agri Helpdesk Team</p>
                    </div>
                ";

                $mail->send();
            } catch (Exception $e) {
                // Log error or ignore
            }
        }
    }
    
    $update->close();

    header("Location: all_tickets.php");
    exit();
}

// If accessed directly via GET, redirect back to all tickets
header("Location: all_tickets.php");
exit();
?>
