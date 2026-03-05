<?php
$host = "127.0.0.1";
$user = "root";
$pass = "";
$db   = "ticketing_system";

$conn = mysqli_connect($host, $user, $pass, $db, 3306);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!$conn) {
    die("Connection Failed: " . mysqli_connect_error());
}
