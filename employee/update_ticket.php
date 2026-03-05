<?php
require_once '../config/database.php';

// Import PHPMailer classes into the global namespace
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

require '../vendor/phpmailer/src/Exception.php';
require '../vendor/phpmailer/src/PHPMailer.php';
require '../vendor/phpmailer/src/SMTP.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'employee') {
    header("Location: employee_login.php");
    exit();
}

// Ensure department and company are in session
if (!isset($_SESSION['department']) || !isset($_SESSION['company'])) {
    $u_stmt = $conn->prepare("SELECT department, company FROM users WHERE id = ?");
    $u_stmt->bind_param("i", $_SESSION['user_id']);
    $u_stmt->execute();
    $u_res = $u_stmt->get_result();
    if ($u_row = $u_res->fetch_assoc()) {
        $_SESSION['department'] = $u_row['department'];
        $_SESSION['company'] = $u_row['company'];
    }
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {

    if (!isset($_POST['id'])) {
        header("Location: my_task.php");
        exit();
    }

    $id = (int) $_POST['id'];
    $new_status = isset($_POST['status']) ? trim($_POST['status']) : '';
    $new_department = isset($_POST['assigned_department']) ? trim($_POST['assigned_department']) : '';
    $new_company = isset($_POST['assigned_company']) ? trim($_POST['assigned_company']) : '';
    $admin_note = isset($_POST['admin_note']) ? trim($_POST['admin_note']) : null;

    // --- PERMISSION CHECK ---
    // Employee can only update tickets assigned to their department AND company
    $check_stmt = $conn->prepare("SELECT user_id, status, assigned_department, assigned_company, admin_note FROM employee_tickets WHERE id = ?");
    $check_stmt->bind_param("i", $id);
    $check_stmt->execute();
    $check_res = $check_stmt->get_result();
    $old_data = $check_res->fetch_assoc();
    $check_stmt->close();

    if (!$old_data) {
        header("Location: my_task.php?error=notfound");
        exit();
    }

    if ($old_data['assigned_department'] !== $_SESSION['department'] || $old_data['assigned_company'] !== $_SESSION['company']) {
        header("Location: my_task.php?error=unauthorized");
        exit();
    }

    // Normalize and validate status
    $allowed_statuses = ['Open', 'In Progress', 'Resolved', 'Closed'];
    if ($new_status === '' || !in_array($new_status, $allowed_statuses, true)) {
        $new_status = $old_data['status'];
    }
    
    // If company is not set (shouldn't happen with dropdown), keep old company
    if (empty($new_company)) {
        $new_company = $old_data['assigned_company'];
    }

    // Update ticket
    $update = $conn->prepare("
        UPDATE employee_tickets
        SET 
            status = ?, 
            assigned_department = ?, 
            assigned_company = ?,
            admin_note = ?,
            is_read = 1, 
            updated_at = NOW(),
            resolved_at = CASE 
                WHEN (? = 'Resolved' OR ? = 'Closed') AND resolved_at IS NULL THEN NOW() 
                ELSE resolved_at 
            END
        WHERE id = ?
    ");
    
    $update->bind_param("ssssssi", $new_status, $new_department, $new_company, $admin_note, $new_status, $new_status, $id);
    
    if ($update->execute()) {
        $_SESSION['success'] = "Ticket #$id successfully updated.";

        // --- TICKET ACTIVITY LOG ---
        // Status change
        if ($old_data['status'] !== $new_status) {
            $activity_desc = "Status changed to " . $new_status . " by " . $_SESSION['department'];
            $act = $conn->prepare("INSERT INTO ticket_activity (ticket_id, activity_type, description, created_at) VALUES (?, 'status_change', ?, NOW())");
            if ($act) {
                $act->bind_param("is", $id, $activity_desc);
                $act->execute();
                $act->close();
            }
        }
        
        // Department reassignment
        if ($old_data['assigned_department'] !== $new_department) {
            $activity_desc = "Reassigned from " . $old_data['assigned_department'] . " to " . $new_department;
            $act = $conn->prepare("INSERT INTO ticket_activity (ticket_id, activity_type, description, created_at) VALUES (?, 'department_change', ?, NOW())");
            if ($act) {
                $act->bind_param("is", $id, $activity_desc);
                $act->execute();
                $act->close();
            }
        }

        // Company reassignment
        if ($old_data['assigned_company'] !== $new_company) {
            $activity_desc = "Reassigned from company " . $old_data['assigned_company'] . " to " . $new_company;
            $act = $conn->prepare("INSERT INTO ticket_activity (ticket_id, activity_type, description, created_at) VALUES (?, 'company_change', ?, NOW())");
            if ($act) {
                $act->bind_param("is", $id, $activity_desc);
                $act->execute();
                $act->close();
            }
        }

        // Note added
        if (!empty($admin_note) && $admin_note !== $old_data['admin_note']) {
            $activity_desc = $_SESSION['department'] . " added a note";
            $act = $conn->prepare("INSERT INTO ticket_activity (ticket_id, activity_type, description, created_at) VALUES (?, 'note_added', ?, NOW())");
            if ($act) {
                $act->bind_param("is", $id, $activity_desc);
                $act->execute();
                $act->close();
            }
        }

        // --- INSERT NOTIFICATIONS ---
        $notif_user_id = $old_data['user_id'];
        $notifications = [];

        // 1. Status Change
        if ($old_data['status'] !== $new_status) {
            if ($new_status === 'Resolved' || $new_status === 'Closed') {
                    $notifications[] = [
                    'msg' => "Your ticket #$id has been closed by " . $_SESSION['department'] . ".",
                    'type' => 'ticket_closed'
                ];
            } else {
                $notifications[] = [
                    'msg' => "Your ticket #$id status was updated to $new_status by " . $_SESSION['department'] . ".",
                    'type' => 'status_update'
                ];
            }
        }

        // 2. Department/Company Change
        if ($old_data['assigned_department'] !== $new_department || $old_data['assigned_company'] !== $new_company) {
            $notifications[] = [
                'msg' => "Your ticket #$id was reassigned to $new_department at $new_company.",
                'type' => 'reassigned'
            ];

            if ($new_department !== '' && $new_company !== '') {
                $dept_users_stmt = $conn->prepare("SELECT id FROM users WHERE role = 'employee' AND department = ? AND company = ?");
                if ($dept_users_stmt) {
                    $dept_users_stmt->bind_param("ss", $new_department, $new_company);
                    $dept_users_stmt->execute();
                    $dept_users_res = $dept_users_stmt->get_result();

                    $dept_notif_stmt = $conn->prepare("INSERT INTO notifications (user_id, ticket_id, message, type) VALUES (?, ?, ?, ?)");
                    if ($dept_notif_stmt) {
                        $dept_type = 'dept_assigned';
                        $dept_msg = "New ticket #$id was assigned to your department by " . $_SESSION['department'] . " (" . $_SESSION['company'] . ").";
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
                'msg' => $_SESSION['department'] . " added a note to ticket #$id: '$preview'",
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

        // --- SEND EMAIL NOTIFICATION ---
        // Get user details
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
                            <strong style='color: #166534;'>Note from {$_SESSION['department']}:</strong><br>
                            " . nl2br(htmlspecialchars($admin_note)) . "
                        </div>
                    ";
                }

                $mail->Body    = "
                    <div style='font-family: Arial, sans-serif; color: #333;'>
                        <h2>Ticket Updated</h2>
                        <p>Hello <strong>{$ticket['name']}</strong>,</p>
                        <p>Your ticket <strong>#$id</strong> has been updated by <strong>{$_SESSION['department']}</strong>.</p>
                        <hr>
                        <p><strong>Status:</strong> <span style='color: #1B5E20; font-weight: bold;'>$new_status</span></p>
                        <p><strong>Assigned To:</strong> $new_department ($new_company)</p>
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

    header("Location: my_task.php");
    exit();
}

header("Location: my_task.php");
exit();
?>