<?php
session_start();

// ทำลาย session ทั้งหมด
session_unset();
session_destroy();

// ลบ session cookie
if (isset($_COOKIE[session_name()])) {
    setcookie(session_name(), '', time()-3600, '/');
}

// กลับไปหน้าล็อกอิน
header('Location: login.php?message=logged_out');
exit;
?>