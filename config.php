<?php
// فایل: config.php
// تنظیم عمر session به ۱۴ ساعت (۱۴ ساعت = ۵۰۴۰۰ ثانیه) بروزرسانی زمان فعالیت
require_once 'license_check.php';
date_default_timezone_set('Asia/Tehran');

$servername = "localhost";
$username = "root";
$password = "";
$dbname = "ncp";

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

mysqli_set_charset($conn, "utf8mb4");
?>
