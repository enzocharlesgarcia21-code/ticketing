<?php
require_once "config/database.php";

if(isset($_POST['id'])){
    $id = intval($_POST['id']);
    $status = mysqli_real_escape_string($conn, $_POST['status']);

    mysqli_query($conn, "UPDATE tickets SET status='$status' WHERE id=$id");
}

header("Location: view_tickets.php");
exit();
?>
