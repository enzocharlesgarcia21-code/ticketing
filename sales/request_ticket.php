<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once '../config/database.php';

require '../vendor/phpmailer/src/PHPMailer.php';
require '../vendor/phpmailer/src/SMTP.php';
require '../vendor/phpmailer/src/Exception.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$success_msg = "";
$error_msg = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {

    $name       = trim($_POST['name']);
    $email      = trim($_POST['email']);
    $subject    = $_POST['subject'] ?? '';
    $category   = $_POST['category'] ?? '';
    $priority   = $_POST['priority'] ?? '';
    $company    = $_POST['company'] ?? '';
    $department = "Sales"; // Fixed department
    $assigned_department = $_POST['assigned_department'] ?? '';
    $description = !empty($_POST['description']) ? $_POST['description'] : '';

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

    /* ================= GET/CREATE SALES GUEST USER ================= */
    
    $sales_email = 'sales_guest@leadsagri.com';
    $user_id = null;
    $stmt = $conn->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param("s", $sales_email);
        $stmt->execute();
        $stmt->bind_result($found_user_id);
        if ($stmt->fetch()) {
            $user_id = (int) $found_user_id;
        }
        $stmt->close();
    } else {
        $error_msg = "System error. Please try again later.";
    }

    if (empty($error_msg) && empty($user_id)) {
        $guest_pass = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
        $guest_name = 'Sales Department';
        $guest_company = 'Sales';
        $guest_department = 'Sales';
        $guest_role = 'employee';
        $guest_otp = '000000';
        $guest_verified = 1;

        $insert_stmt = $conn->prepare("
            INSERT INTO users (name, email, company, department, password, role, otp_code, is_verified)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        if ($insert_stmt) {
            $insert_stmt->bind_param(
                "sssssssi",
                $guest_name,
                $sales_email,
                $guest_company,
                $guest_department,
                $guest_pass,
                $guest_role,
                $guest_otp,
                $guest_verified
            );
            if ($insert_stmt->execute()) {
                $user_id = (int) $insert_stmt->insert_id;
            } else {
                $error_msg = "System error. Please try again later.";
            }
            $insert_stmt->close();
        } else {
            $error_msg = "System error. Please try again later.";
        }
    }

    /* ================= BASIC VALIDATION ================= */
    $valid_departments = ['Accounting','Admin','Bidding','E-Comm','HR','IT','Marketing','Sales'];
    if (empty($assigned_department) || !in_array($assigned_department, $valid_departments, true)) {
        $error_msg = "Assigned Department is required.";
    }

    /* ================= PREPARE DESCRIPTION ================= */
    
    $full_description = "REQUESTER NAME: $name\nREQUESTER EMAIL: $email\n\nDESCRIPTION:\n$description";

    /* ================= INSERT INTO DATABASE ================= */

    if (empty($error_msg)) {
        $stmt = $conn->prepare("
            INSERT INTO employee_tickets
            (user_id, subject, category, priority, company, department, assigned_department, description, attachment)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        if(!$stmt){
            $error_msg = "System error. Please try again later.";
        } else {
            $stmt->bind_param(
                "issssssss",
                $user_id,
                $subject,
                $category,
                $priority,
                $company,
                $department,
                $assigned_department,
                $full_description,
                $attachmentName
            );

            if($stmt->execute()){
                $success_msg = "Ticket successfully submitted! An admin will review it shortly.";
                
                /* ================= SEND EMAIL ================= */
                $mail = new PHPMailer(true);
                try {
                    $mail->isSMTP();
                    $mail->Host       = 'smtp.gmail.com';
                    $mail->SMTPAuth   = true;
                    $mail->Username   = 'matthewpascua052203@gmail.com';
                    $mail->Password   = 'tmwtjqjvadsmgzje';
                    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                    $mail->Port       = 587;
                    $mail->SMTPOptions = [
                        'ssl' => [
                            'verify_peer' => false,
                            'verify_peer_name' => false,
                            'allow_self_signed' => true
                        ]
                    ];

                    $mail->setFrom('matthewpascua052203@gmail.com', 'Leads Agri Helpdesk');

                    /* Add all admin emails */
                    $admins = $conn->query("SELECT email FROM users WHERE role='admin'");
                    if ($admins && $admins->num_rows > 0) {
                        while($admin = $admins->fetch_assoc()){
                            $mail->addAddress($admin['email']);
                        }
                    } else {
                        $mail->addAddress('matthewpascua052203@gmail.com');
                    }

                    /* Attach file if exists */
                    if (!empty($attachmentName)) {
                        $mail->addAttachment('../uploads/' . $attachmentName);
                    }

                    $mail->isHTML(true);
                    $mail->Subject = "New Sales Ticket - $subject";
                    $mail->Body = "
                        <div style='font-family:Segoe UI; padding:15px'>
                            <h2 style='color:#1B5E20'>New Sales Ticket Submitted</h2>
                            <hr>
                            <p><strong>Requester:</strong> $name ($email)</p>
                            <p><strong>Subject:</strong> $subject</p>
                            <p><strong>Category:</strong> $category</p>
                            <p><strong>Priority:</strong> $priority</p>
                            <p><strong>Department:</strong> Sales</p>
                            <p><strong>Description:</strong><br>" . nl2br(htmlspecialchars($description)) . "</p>
                            <hr>
                            <p style='font-size:12px;color:#64748B'>
                                This is an automated message from Leads Agri Helpdesk.
                            </p>
                        </div>
                    ";
                    $mail->send();
                } catch (Exception $e) {
                }

            } else {
                $error_msg = "Failed to submit ticket: " . $stmt->error;
            }
            $stmt->close();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sales Ticket Request | Leads Agri Helpdesk</title>
    <!-- Reuse existing CSS or inline minimal styles -->
    <link rel="stylesheet" href="../css/employee-dashboard.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        body {
            background: #f3f4f6;
            font-family: 'Inter', sans-serif;
        }
        .sales-container {
            max-width: 800px;
            margin: 40px auto;
            background: white;
            padding: 40px;
            border-radius: 16px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.05);
        }
        .header {
            text-align: center;
            margin-bottom: 30px;
        }
        .header h1 {
            color: #1B5E20;
            margin-bottom: 10px;
        }
        .header p {
            color: #6b7280;
        }
        .form-group {
            margin-bottom: 20px;
        }
        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #374151;
        }
        input, select, textarea {
            width: 100%;
            padding: 12px;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            font-size: 14px;
            box-sizing: border-box;
        }
        input:focus, select:focus, textarea:focus {
            outline: none;
            border-color: #1B5E20;
          
        }
        button {
            width: 100%;
            padding: 14px;
            background: #1B5E20;
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.2s;
        }
        button:hover {
            background: #144a1e;
        }
        .back-link {
            display: block;
            text-align: center;
            margin-top: 20px;
            color: #6b7280;
            text-decoration: none;
        }
        .back-link:hover {
            color: #1B5E20;
        }
        .alert {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            text-align: center;
        }
        .alert-success {
            background: #ecfdf5;
            color: #065f46;
            border: 1px solid #a7f3d0;
        }
        .alert-error {
            background: #fef2f2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }

        @media (min-width: 900px) and (orientation: landscape) {
            .sales-container {
                max-width: 1100px;
                margin: 16px auto;
                padding: 24px;
            }

            .header {
                margin-bottom: 16px;
            }

            .header h1 {
                margin-bottom: 6px;
            }

            .form-group {
                margin-bottom: 0;
            }

            label {
                margin-bottom: 6px;
                font-size: 13px;
            }

            input, select, textarea {
                padding: 10px 12px;
            }

            form {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 14px 20px;
            }

            form > .form-group:nth-of-type(7),
            form > .form-group:nth-of-type(8),
            form > button {
                grid-column: 1 / -1;
            }

            textarea[name="description"] {
                height: 120px;
                resize: none;
            }

            button {
                width: auto;
                justify-self: end;
                padding: 12px 18px;
            }

            .back-link {
                margin-top: 14px;
            }
        }
    </style>
</head>
<body>

<div class="sales-container">
    <div class="header">
        <h1>Submit a Ticket </h1>
        <p>Please fill out the form below.</p>
    </div>

    <?php if($success_msg): ?>
        <div class="alert alert-success"><?= $success_msg ?></div>
        <a href="../auth_select.php" class="back-link">Back to Home</a>
    <?php else: ?>

        <?php if($error_msg): ?>
            <div class="alert alert-error"><?= $error_msg ?></div>
        <?php endif; ?>

        <form method="POST" enctype="multipart/form-data">
            
            <div class="form-group">
                <label> Name *</label>
                <input type="text" name="name" required placeholder=" ">
            </div>

            <div class="form-group">
                <label> Email *</label>
                <input type="email" name="email" required placeholder="">
            </div>

            <div class="form-group">
                <label>Subject *</label>
                <input type="text" name="subject" required placeholder="Brief summary of the issue">
            </div>

            <div class="form-group">
                <label>Category *</label>
                <select name="category" required>
                    <option value=""disabled selected hidden>Select Category</option>
                    <option value="Network Issue">Network Issue</option>
                    <option value="Hardware Issue">Hardware Issue</option>
                    <option value="Software Issue">Software Issue</option>
                    <option value="Account Access">Account Access</option>
                    <option value="Other">Other</option>
                </select>
            </div>

            <div class="form-group">
                <label>Priority *</label>
                <select name="priority" required>
                    <option value="Low">Low</option>
                    <option value="Medium">Medium</option>
                    <option value="High">High</option>
                    <option value="Critical">Critical</option>
                </select>
            </div>

            <div class="form-group">
                <label>Company / Subsidiary *</label>
                <select name="company" required>
                    <option value=""disabled selected hidden>Select Company</option>
                    <option value="FARMASEE">FARMASEE</option>
                    <option value="FARMEX">FARMEX</option>
                    <option value="Golden Primestocks Chemical Inc - GPSCI">Golden Primestocks Chemical Inc - GPSCI</option>
                    <option value="Leads Animal Health - LAH">Leads Animal Health - LAH</option>
                    <option value="Leads Environmental Health - LEH">Leads Environmental Health - LEH</option>
                    <option value="Leads Tech Corporation - LTC">Leads Tech Corporation - LTC</option>
                    <option value="LINGAP LEADS FOUNDATION - Lingap">LINGAP LEADS FOUNDATION - Lingap</option>
                    <option value="Malveda Holdings Corporation - MHC">Malveda Holdings Corporation - MHC</option>
                    <option value="Malveda Properties & Development Corporation - MPDC">Malveda Properties & Development Corporation - MPDC</option>
                    <option value="Primestocks Chemical Corporation - PCC">Primestocks Chemical Corporation - PCC</option>
                </select>
            </div>

            <div class="form-group">
                <label>Assigned Department * </label>
                <select name="assigned_department" required>
                    <option value=""disabled selected hidden>Select Department</option>
                    <option value="Accounting">Accounting</option>
                    <option value="Admin">Admin</option>
                    <option value="Bidding">Bidding</option>
                    <option value="E-Comm">E-Comm</option>
                    <option value="HR">HR</option>
                    <option value="IT">IT</option>
                    <option value="Marketing">Marketing</option>
                    <option value="Sales">Sales</option>
                </select>
            </div>

            <div class="form-group">
                <label>Description *</label>
                <textarea name="description" rows="5" required placeholder="Describe your issue in detail..."></textarea>
            </div>

            <div class="form-group">
                <label>Attachment (Optional)</label>
                <input type="file" name="attachment" class="form-control" accept=".jpg,.jpeg,.png,.pdf,.doc,.docx">
                <small style="display:block; margin-top:5px; color:#666;">Allowed: jpg, jpeg, png, pdf, doc, docx (Max 5MB)</small>
                <div id="attachment-preview" style="margin-top: 10px;"></div>
            </div>

            <button type="submit" class="submit-btn">Submit Ticket</button>
        </form>

        <a href="../auth_select.php" class="back-link">Back to Home</a>

    <?php endif; ?>
</div>

<script>
var attachmentInput = document.querySelector('input[name="attachment"]');
var currentObjectUrl = null;

attachmentInput.addEventListener('change', function(e) {
    var preview = document.getElementById('attachment-preview');
    preview.innerHTML = '';
    var file = e.target.files[0];

    if (currentObjectUrl) {
        URL.revokeObjectURL(currentObjectUrl);
        currentObjectUrl = null;
    }

    if (!file) return;

    currentObjectUrl = URL.createObjectURL(file);

    if (file.type.startsWith('image/')) {
        var link = document.createElement('a');
        link.href = currentObjectUrl;
        link.target = '_blank';
        link.rel = 'noopener';
        link.style.display = 'inline-block';

        var img = document.createElement('img');
        img.src = currentObjectUrl;
        img.style.maxWidth = '100%';
        img.style.maxHeight = '200px';
        img.style.borderRadius = '5px';
        img.style.border = '1px solid #ddd';
        img.style.cursor = 'pointer';

        link.appendChild(img);
        preview.appendChild(link);
    } else {
        var link = document.createElement('a');
        link.href = currentObjectUrl;
        link.target = '_blank';
        link.rel = 'noopener';
        link.style.display = 'inline-block';
        link.style.textDecoration = 'none';

        var div = document.createElement('div');
        div.innerHTML = '<i class="fas fa-file-alt"></i> ' + file.name;
        div.style.padding = '10px';
        div.style.background = '#f8f9fa';
        div.style.border = '1px solid #ddd';
        div.style.borderRadius = '5px';
        div.style.display = 'inline-block';
        div.style.cursor = 'pointer';

        link.appendChild(div);
        preview.appendChild(link);
    }
});
</script>

</body>
</html>
