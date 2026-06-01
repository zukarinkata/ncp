<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: login.php');
    exit();
}
require_once 'config.php';

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');
    $role = trim($_POST['role'] ?? 'user');
    
    if (empty($username) || empty($password)) {
        $error = 'نام کاربری و رمز عبور اجباری هستند.';
    } elseif (strlen($username) < 3 || strlen($password) < 6) {
        $error = 'نام کاربری حداقل ۳ کاراکتر و رمز حداقل ۶ کاراکتر باشد.';
    } else {
        // چک تکراری بودن username
        $stmt = $conn->prepare("SELECT id FROM users WHERE username = ?");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $error = 'نام کاربری تکراری است.';
        } else {
            // تولید هش و ذخیره
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $insert_stmt = $conn->prepare("INSERT INTO users (username, password_hash, role) VALUES (?, ?, ?)");
            $insert_stmt->bind_param("sss", $username, $hash, $role);
            if ($insert_stmt->execute()) {
                $success = 'کاربر جدید با موفقیت ساخته شد.';
            } else {
                $error = 'خطا در ذخیره کاربر: ' . $insert_stmt->error;
            }
            $insert_stmt->close();
        }
        $stmt->close();
    }
}
?>

<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>افزودن کاربر جدید</title>
    <link rel="stylesheet" href="font/css/all.css">
    <style>
        body { text-align: center; padding: 50px; background: #f4f4f4; }
        form { max-width: 300px; margin: auto; background: white; padding: 20px; border-radius: 5px; box-shadow: 0 0 10px rgba(0,0,0,0.1); }
        input, select { width: 100%; padding: 10px; margin: 10px 0; border: 1px solid #ccc; border-radius: 3px; }
        button { padding: 10px; width: 100%; background: #28a745; color: white; border: none; cursor: pointer; border-radius: 3px; }
        .error { color: red; }
        .success { color: green; }
    </style>
</head>
<body>
    <h2>افزودن کاربر جدید</h2>
    <?php if ($error) echo "<p class='error'>$error</p>"; ?>
    <?php if ($success) echo "<p class='success'>$success</p>"; ?>
    <form method="post">
        <input type="text" name="username" placeholder="نام کاربری" required>
        <input type="password" name="password" placeholder="رمز عبور" required>
        <select name="role">
            <option value="user">کاربر عادی</option>
            <option value="admin">ادمین</option>
        </select>
        <button type="submit">افزودن کاربر</button>
    </form>
    <a href="index.php"><i class="fas fa-home fa-3x"></i></a>
</body>
</html>