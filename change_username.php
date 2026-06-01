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
    $current_username = trim($_POST['current_username'] ?? '');
    $new_username = trim($_POST['new_username'] ?? '');
    
    // exit();  // اگر می‌خواهید صفحه متوقف شود، این را uncomment کنید
    
    if (empty($current_username) || empty($new_username)) {
        $error = 'همه فیلدها اجباری هستند.';
    } elseif (strtolower($current_username) !== strtolower($_SESSION['username'])) {
        $error = 'نام کاربری فعلی اشتباه است.';
    } elseif (strlen($new_username) < 3) {
        $error = 'نام کاربری جدید باید حداقل ۳ کاراکتر باشد.';
    } else {
        // چک تکراری بودن نام جدید
        $stmt = $conn->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
        $stmt->bind_param("si", $new_username, $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $error = 'نام کاربری جدید تکراری است.';
        } else {
            // به‌روزرسانی
            $update_stmt = $conn->prepare("UPDATE users SET username = ? WHERE id = ?");
            $update_stmt->bind_param("si", $new_username, $user_id);
            if ($update_stmt->execute()) {
                $_SESSION['username'] = $new_username; // به‌روزرسانی session
                $success = 'نام کاربری با موفقیت تغییر یافت.';
            } else {
                $error = 'خطا در ذخیره نام کاربری جدید: ' . $update_stmt->error;
            }
            $update_stmt->close();
        }
        $stmt->close();
    }
}
?>

<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>تغییر نام کاربری</title>
    <link rel="stylesheet" href="font/css/all.css">
    <style>
        body {  text-align: center; padding: 50px; background: #79e5b6; }
        form { max-width: 300px; margin: auto; background: #d9e3a8; padding: 20px; border-radius: 5px; box-shadow: 0 5px 10px #5b5151; }
        input { width: 100%; padding: 10px; margin: 10px 0; border: 1px solid #ccc; border-radius: 3px; }
        button { padding: 10px; width: 100%; background: #28a745; color: white; border: double #e54949; cursor: pointer; border-radius: 3px; }
        .error { color: red; }
        .success { color: green; }
    </style>
</head>
<body>
    <h2>تغییر نام کاربری</h2>
    <?php if ($error) echo "<p class='error'>$error</p>"; ?>
    <?php if ($success) echo "<p class='success'>$success</p>"; ?>
    <form method="post">
        <input type="text" name="current_username" placeholder="نام کاربری فعلی" value="<?php echo htmlspecialchars($_SESSION['username'] ?? ''); ?>" required>
        <input type="text" name="new_username" placeholder="نام کاربری جدید" required>
        <button type="submit">تغییر نام کاربری</button>
    </form>
    <a href="index.php"><i class="fas fa-home fa-3x"></i></a>
</body>
</html>