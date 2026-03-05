<?php
require_once '../config/database.php';

if (!isset($_SESSION['reset_email'])) {
    header("Location: forgot_password.php");
    exit();
}

if (!isset($_SESSION['otp_verified']) || $_SESSION['otp_verified'] !== true) {
     header("Location: verify_reset_otp.php");
     exit();
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $pass = $_POST['password'];
    $confirm = $_POST['confirm_password'];

    // Validation
    if ($pass !== $confirm) {
        $error = "Passwords do not match.";
    } elseif (strlen($pass) < 8 || !preg_match('/[A-Z]/', $pass) || !preg_match('/[a-z]/', $pass) || !preg_match('/[0-9]/', $pass) || !preg_match('/[^A-Za-z0-9]/', $pass)) {
        $error = "Password must be at least 8 chars, include uppercase, lowercase, number, and special char.";
    } else {
        $hash = password_hash($pass, PASSWORD_DEFAULT);
        $email = $_SESSION['reset_email'];

        $update = $conn->prepare("UPDATE users SET password = ?, reset_otp = NULL, reset_otp_expiry = NULL WHERE email = ?");
        $update->bind_param("ss", $hash, $email);

        if ($update->execute()) {
            unset($_SESSION['reset_email']);
            unset($_SESSION['otp_verified']);
            // Redirect to login with success message
            header("Location: employee_login.php?password_reset=1");
            exit();
        } else {
            $error = "Error updating password.";
        }
    }
}
?>

<!DOCTYPE html>
<html>
<head>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Reset Password - Employee</title>
    <link rel="stylesheet" href="../css/employee-login.css">
</head>
<body>

<div class="login-container">
    <div class="login-card">

        <h2>Reset Password</h2>
        <p style="text-align:center; color:#666; font-size:0.9rem; margin-bottom:20px;">
            Create a new strong password.
        </p>

        <?php if(isset($error)) : ?>
            <div class="error"><?php echo $error; ?></div>
        <?php endif; ?>

        <form method="POST">
            <div class="form-group">
                <label>New Password</label>
                <input type="password" name="password" required placeholder="New Password">
            </div>

            <div class="form-group">
                <label>Confirm Password</label>
                <input type="password" name="confirm_password" required placeholder="Confirm Password">
            </div>

            <button type="submit">Reset Password</button>
        </form>

    </div>
</div>

</body>
</html>
