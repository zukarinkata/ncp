<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}
require_once 'config.php';

$user_id = $_SESSION['user_id'];
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $current_password = trim($_POST['current_password'] ?? '');
    $new_password = trim($_POST['new_password'] ?? '');
    $confirm_password = trim($_POST['confirm_password'] ?? '');
    
    if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
        $error = 'همه فیلدها اجباری هستند.';
    } elseif ($new_password !== $confirm_password) {
        $error = 'رمز جدید و تکرار آن مطابقت ندارند.';
    } elseif (strlen($new_password) < 6) {
        $error = 'رمز جدید باید حداقل ۶ کاراکتر باشد.';
    } else {
        // چک رمز فعلی
        $stmt = $conn->prepare("SELECT password_hash FROM users WHERE id = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            if (password_verify($current_password, $row['password_hash'])) {
                // تولید هش جدید و ذخیره
                $new_hash = password_hash($new_password, PASSWORD_DEFAULT);
                $update_stmt = $conn->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
                $update_stmt->bind_param("si", $new_hash, $user_id);
                if ($update_stmt->execute()) {
                    $success = 'رمز عبور با موفقیت تغییر یافت.';
                } else {
                    $error = 'خطا در ذخیره رمز جدید: ' . $update_stmt->error;
                }
                $update_stmt->close();
            } else {
                $error = 'رمز فعلی اشتباه است.';
            }
        } else {
            $error = 'کاربر یافت نشد.';
        }
        $stmt->close();
    }
}
?>

<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>تغییر رمز عبور</title>
    <link rel="stylesheet" href="font/css/all.css">
    <style>
        body { text-align: center; padding: 50px; background: #79e5b6; }
        form { max-width: 300px; margin: auto; background: #d9e3a8; padding: 20px; border-radius: 5px; box-shadow: 0 5px 10px #5b5151; }
        input { width: 100%; padding: 10px; margin: 10px 0; border: 2px solid #828dc1; border-radius: 6px; }
        button { padding: 10px; width: 90%; background: #28a745; color: white; border: double #e54949; cursor: pointer; border-radius: 5px; }
        .error { color: red; }
        .success { color: green; }
    </style>
</head>
<body>
    <h2>تغییر رمز عبور</h2>
    <?php if ($error) echo "<p class='error'>$error</p>"; ?>
    <?php if ($success) echo "<p class='success'>$success</p>"; ?>
    <form method="post">
        <input type="password" name="current_password" placeholder="رمز فعلی" required>
        <input type="password" name="new_password" placeholder="رمز جدید" required>
        <input type="password" name="confirm_password" placeholder="تکرار رمز جدید" required>
        <button type="submit">تغییر رمز</button>
    </form>
    <a href="index.php"><i class="fas fa-home fa-3x"></i></a>
</body>
</html>