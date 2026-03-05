<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once '../config/database.php';

require '../vendor/phpmailer/src/PHPMailer.php';
require '../vendor/phpmailer/src/SMTP.php';
require '../vendor/phpmailer/src/Exception.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'employee') {
    header("Location: employee_login.php");
    exit();
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {

    $user_id    = $_SESSION['user_id'];
    $subject    = $_POST['subject'] ?? '';
    $category   = $_POST['category'] ?? '';
    $priority   = $_POST['priority'] ?? '';
    $department = $_SESSION['department'] ?? '';
    if (empty($department)) {
        $dept_stmt = $conn->prepare("SELECT department FROM users WHERE id = ?");
        if ($dept_stmt) {
            $dept_stmt->bind_param("i", $user_id);
            $dept_stmt->execute();
            $dept_res = $dept_stmt->get_result();
            if ($dept_row = $dept_res->fetch_assoc()) {
                $department = $dept_row['department'] ?? $department;
            }
            $dept_stmt->close();
        }
    }
    $assigned_department = $_POST['assigned_department'] ?? '';
    $assigned_company = $_POST['assigned_company'] ?? '';
    $description = !empty($_POST['description']) ? $_POST['description'] : NULL;

    $attachmentName = NULL;

    /* ================= FILE UPLOAD ================= */

    if(isset($_FILES['attachment']) && $_FILES['attachment']['error'] == 0) {

        $allowedTypes = ['jpg','jpeg','png','pdf','doc','docx'];
        $fileName = $_FILES['attachment']['name'];
        $fileTmp  = $_FILES['attachment']['tmp_name'];
        $fileSize = $_FILES['attachment']['size'];

        $fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

        if(in_array($fileExt, $allowedTypes) && $fileSize <= 5 * 1024 * 1024) {

            if(!is_dir("../uploads")){
                mkdir("../uploads", 0777, true);
            }

            $newFileName = time() . "_" . uniqid() . "." . $fileExt;
            $uploadPath  = "../uploads/" . $newFileName;

            if(move_uploaded_file($fileTmp, $uploadPath)) {
                $attachmentName = $newFileName;
            }
        }
    }

    /* ================= INSERT INTO DATABASE ================= */

    $stmt = $conn->prepare("
        INSERT INTO employee_tickets
        (user_id, subject, category, priority, department, assigned_department, assigned_company, description, attachment)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    if(!$stmt){
        die("Prepare Failed: " . $conn->error);
    }

    $stmt->bind_param(
        "issssssss",
        $user_id,
        $subject,
        $category,
        $priority,
        $department,
        $assigned_department,
        $assigned_company,
        $description,
        $attachmentName
    );

    if(!$stmt->execute()){
        die("Execute Failed: " . $stmt->error);
    }
    
    $ticket_id = $stmt->insert_id;

    $stmt->close();

    /* ================= NOTIFICATIONS FOR ADMINS ================= */
    // 1. Get User Details
    $user_stmt = $conn->prepare("SELECT name, company FROM users WHERE id = ?");
    $user_stmt->bind_param("i", $user_id);
    $user_stmt->execute();
    $user_res = $user_stmt->get_result();
    $user_data = $user_res->fetch_assoc();
    $user_name = $user_data['name'] ?? 'Unknown User';
    $user_company = $user_data['company'] ?? 'Unknown Company';
    $user_stmt->close();

    // 2. Format Message
    $ticket_number = str_pad($ticket_id, 6, '0', STR_PAD_LEFT);
    $notif_msg = "New $priority priority ticket #$ticket_number from $user_name - $department";

    if (!empty($assigned_department) && !empty($assigned_company)) {
        $dept_users_stmt = $conn->prepare("SELECT id FROM users WHERE role = 'employee' AND department = ? AND company = ?");
        if ($dept_users_stmt) {
            $dept_users_stmt->bind_param("ss", $assigned_department, $assigned_company);
            $dept_users_stmt->execute();
            $dept_users_res = $dept_users_stmt->get_result();

            $dept_notif_stmt = $conn->prepare("INSERT INTO notifications (user_id, ticket_id, message, type) VALUES (?, ?, ?, ?)");
            if ($dept_notif_stmt) {
                $dept_type = 'dept_assigned';
                $dept_msg = "New ticket #$ticket_number from $user_name was assigned to your department.";
                while ($u = $dept_users_res->fetch_assoc()) {
                    $target_user_id = (int)$u['id'];
                    $dept_notif_stmt->bind_param("iiss", $target_user_id, $ticket_id, $dept_msg, $dept_type);
                    $dept_notif_stmt->execute();
                }
                $dept_notif_stmt->close();
            }

            $dept_users_stmt->close();
        }
    }

    // 3. Insert Notification for ALL Admins
    $admin_query = "SELECT id FROM users WHERE role = 'admin'";
    $admin_result = $conn->query($admin_query);

    if ($admin_result) {
        $notif_stmt = $conn->prepare("INSERT INTO notifications (user_id, ticket_id, message, type) VALUES (?, ?, ?, 'new_ticket')");
        while ($admin = $admin_result->fetch_assoc()) {
            $admin_id = $admin['id'];
            $notif_stmt->bind_param("iis", $admin_id, $ticket_id, $notif_msg);
            $notif_stmt->execute();
        }
        $notif_stmt->close();
    }

    /* ================= SEND EMAIL ================= */

    $mail = new PHPMailer(true);

    try {

        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'matthewpascua052203@gmail.com';
        $mail->Password   = 'tmwtjqjvadsmgzje'; // App Password
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;

        $mail->SMTPOptions = [
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true
            ]
        ];

        $mail->setFrom('matthewpascua052203@gmail.com', 'Leads Agri Helpdesk'); // Changed name

        /* Add all admin emails */
        $admins = $conn->query("SELECT email FROM users WHERE role='admin'");
        if ($admins->num_rows > 0) {
            while($admin = $admins->fetch_assoc()){
                $mail->addAddress($admin['email']);
            }
        } else {
             // Fallback if no admin found in DB (unlikely but good safety)
             $mail->addAddress('matthewpascua052203@gmail.com');
        }
        
        // Also add the user's email if not set in session
        $user_email = $_SESSION['email'] ?? '';
        if (empty($user_email)) {
             $u_stmt = $conn->prepare("SELECT email FROM users WHERE id = ?");
             $u_stmt->bind_param("i", $user_id);
             $u_stmt->execute();
             $u_res = $u_stmt->get_result();
             if ($u_row = $u_res->fetch_assoc()) {
                 $user_email = $u_row['email'];
                 $_SESSION['email'] = $user_email; // Update session
             }
        }

        /* Attach file if exists */
        if (!empty($attachmentName)) {
            $mail->addAttachment('../uploads/' . $attachmentName);
        }

        $mail->isHTML(true);
        $mail->Subject = "New Ticket Submitted - $subject";

        $mail->Body = "
            <div style='font-family:Segoe UI; padding:15px'>
                <h2 style='color:#1B5E20'>New Ticket Submitted</h2>
                <hr>
                <p><strong>Employee:</strong> {$_SESSION['name']}</p>
                <p><strong>Email:</strong> $user_email</p>
                <p><strong>Subject:</strong> $subject</p>
                <p><strong>Category:</strong> $category</p>
                <p><strong>Priority:</strong> $priority</p>
                <p><strong>Department:</strong> $department</p>
                <p><strong>Description:</strong><br>" . nl2br(htmlspecialchars($description ?? 'None')) . "</p>
                <p><strong>Attachment:</strong> " . ($attachmentName ? "Included in this email" : "None") . "</p>
                <hr>
                <p style='font-size:12px;color:#64748B'>
                    This is an automated message from Leads Agri Helpdesk.
                </p>
            </div>
        ";

        $mail->send();

    } catch (Exception $e) {
        // Log error instead of crashing user experience
    }

    /* ================= SUCCESS MESSAGE ================= */

    $_SESSION['success'] = "Ticket successfully submitted!";
    header("Location: my_tickets.php");
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Request Ticket | Leads Agri Helpdesk</title>
    <link rel="stylesheet" href="../css/employee-dashboard.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>

    <!-- 2️⃣ TOP NAVIGATION BAR -->
    <?php include '../includes/employee_navbar.php'; ?>

    <div class="dashboard-container">
        <div class="content-wrapper">

            <!-- 4️⃣ REQUEST TICKET PAGE – REDESIGN -->
            <div class="page-header" style="text-align: center; margin-bottom: 40px;">
                <h1 class="page-title">Submit Ticket</h1>
                <p class="page-subtitle">Please provide detailed information to help us resolve your issue as quickly as possible.</p>
            </div>

            <div class="form-card">
                <form method="POST" enctype="multipart/form-data">
                    
                    <!-- 🔹 Request Information -->
                    <h3 class="form-section-title">Request Information</h3>

                    <div class="form-group">
                        <label>Assign Company</label>
                        <div class="select-wrapper">
                            <select name="assigned_company" class="form-control" required>
                                <option value="" disabled selected hidden>Select Company</option>
                                <option value="FARMEX">FARMEX</option>
                                <option value="Malveda Holdings Corporation - MHC">Malveda Holdings Corporation - MHC</option>
                                <option value="FARMESSE">FARMESSE</option>
                                <option value="Golden Primestocks Chemical Inc - GPSCI">Golden Primestocks Chemical Inc - GPSCI</option>
                                <option value="LEADS Animal Health - LAH">LEADS Animal Health - LAH</option>
                                <option value="Leads Tech Corporation - LTC">Leads Tech Corporation - LTC</option>
                                <option value="LINGAP LEADS FOUNDATION - Lingap">LINGAP LEADS FOUNDATION - Lingap</option>
                                <option value="Malveda Properties & Development Corporation - MPDC">Malveda Properties & Development Corporation - MPDC</option>
                            </select>
                            <i class="fas fa-chevron-down select-icon"></i>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Assign Department</label>
                        <div class="select-wrapper">
                            <select name="assigned_department" class="form-control" required>
                                <option value=""disabled selected hidden>Assign Department</option>
                                <option>Accounting</option>
                                <option>Admin</option>
                                <option>Bidding</option>
                                <option>E-Comm</option>
                                <option>HR</option>
                                <option>IT</option>
                                <option>Marketing</option>
                                <option>Sales</option>
                            </select>
                            <i class="fas fa-chevron-down select-icon"></i>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Category</label>
                        <div class="select-wrapper">
                            <select name="category" id="category" class="form-control" required>
                                <option value=""disabled selected hidden>Select Category</option>
                                <option>Network Issue</option>
                                <option>Hardware Issue</option>
                                <option>Software Issue</option>
                                <option>Email Problem</option>
                                <option>Account Access</option>
                            </select>
                            <i class="fas fa-chevron-down select-icon"></i>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="sub_category">Sub-Category / Issue Type *</label>
                        <div class="select-wrapper">
                            <select name="sub_category" id="sub_category" class="form-control" required>
                                <option value="" disabled selected hidden>Select sub-category</option>
                            </select>
                            <i class="fas fa-chevron-down select-icon"></i>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Subject</label>
                        <input type="text" name="subject" class="form-control" placeholder="Enter a brief title for the issue" required>
                    </div>

                    <!-- 🔹 Impact & Urgency -->
                    <h3 class="form-section-title">Impact & Urgency</h3>

                    <div class="form-group">
                        <label>Priority / Urgency</label>
                        <div class="select-wrapper">
                            <select name="priority" class="form-control" required>
                                <option value=""disabled selected hidden>Select Priority</option>
                                <option>Low</option>
                                <option>Medium</option>
                                <option>High</option>
                                <option>Critical</option>
                            </select>
                            <i class="fas fa-chevron-down select-icon"></i>
                        </div>
                    </div>

                    <!-- 🔹 Ticket Details -->
                    <h3 class="form-section-title">Ticket Details</h3>

                    <div class="form-group">
                        <label>Detailed Description</label>
                        <textarea name="description" class="form-control" placeholder="Describe your issue in detail..."></textarea>
                    </div>

                    <div class="form-group">
                        <label>Attachment</label>
                        <div class="file-upload-wrapper">
                            <label for="attachment" class="file-label">
                                <i class="fas fa-cloud-upload-alt"></i> Choose File
                            </label>
                            <input type="file" name="attachment" id="attachment" class="file-input">
                            <span class="file-name">No file chosen</span>
                        </div>
                        <div id="attachment-preview" style="margin-top: 10px; display: none;">
                            <!-- Preview content will be injected here -->
                        </div>
                        <small class="form-text">Supported formats: JPG, PNG, PDF, DOCX (Max 5MB)</small>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn-submit">Submit Ticket</button>
                    </div>
                </form>
            </div>

        </div>
    </div>

    <script src="../js/employee-dashboard.js"></script>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const fileInput = document.getElementById('attachment');
        const fileNameSpan = document.querySelector('.file-name');
        const previewContainer = document.getElementById('attachment-preview');
        let currentObjectUrl = null;

        if(fileInput) {
            fileInput.addEventListener('change', function(e) {
                const file = e.target.files[0];
                
                if (currentObjectUrl) {
                    URL.revokeObjectURL(currentObjectUrl);
                    currentObjectUrl = null;
                }

                previewContainer.innerHTML = '';
                previewContainer.style.display = 'none';

                if (file) {
                    fileNameSpan.textContent = file.name;
                    previewContainer.style.display = 'block';

                    currentObjectUrl = URL.createObjectURL(file);

                    if (file.type.startsWith('image/')) {
                        const link = document.createElement('a');
                        link.href = currentObjectUrl;
                        link.target = '_blank';
                        link.rel = 'noopener';
                        link.style.display = 'inline-block';

                        const img = document.createElement('img');
                        img.src = currentObjectUrl;
                        img.style.maxWidth = '100%';
                        img.style.maxHeight = '200px';
                        img.style.borderRadius = '8px';
                        img.style.border = '1px solid #e2e8f0';
                        img.style.cursor = 'pointer';

                        link.appendChild(img);
                        previewContainer.appendChild(link);
                    } 
                    else {
                        const link = document.createElement('a');
                        link.href = currentObjectUrl;
                        link.target = '_blank';
                        link.rel = 'noopener';
                        link.style.display = 'inline-block';
                        link.style.textDecoration = 'none';

                        const fileInfo = document.createElement('div');
                        fileInfo.style.display = 'flex';
                        fileInfo.style.alignItems = 'center';
                        fileInfo.style.padding = '12px';
                        fileInfo.style.background = '#f8fafc';
                        fileInfo.style.border = '1px solid #e2e8f0';
                        fileInfo.style.borderRadius = '8px';
                        fileInfo.style.cursor = 'pointer';
                        
                        fileInfo.innerHTML = `
                            <i class="fas fa-file-alt" style="font-size: 24px; color: #64748b; margin-right: 12px;"></i>
                            <div>
                                <div style="font-weight: 600; color: #334155;">${file.name}</div>
                                <div style="font-size: 12px; color: #94a3b8;">${(file.size / 1024).toFixed(2)} KB</div>
                            </div>
                        `;

                        link.appendChild(fileInfo);
                        previewContainer.appendChild(link);
                    }
                } else {
                    fileNameSpan.textContent = 'No file chosen';
                }
            });
        }
    });
    </script>
</body>
</html>
