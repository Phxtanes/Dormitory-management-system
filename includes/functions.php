<?php
// includes/functions.php - รวมฟังก์ชันทั้งหมดไว้ที่เดียว

// เริ่ม session หากยังไม่ได้เริ่ม
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// ตรวจสอบการล็อกอิน
if (!function_exists('check_login')) {
    function check_login() {
        if (!isset($_SESSION['user_id'])) {
            $current_url = $_SERVER['REQUEST_URI'];
            $redirect_url = 'login.php?redirect=' . urlencode($current_url);
            header('Location: ' . $redirect_url);
            exit;
        }
    }
}

// ตรวจสอบสิทธิ์ Admin
if (!function_exists('is_admin')) {
    function is_admin() {
        return isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin';
    }
}

// ตรวจสอบสิทธิ์ Staff (รวม Admin)
if (!function_exists('is_staff')) {
    function is_staff() {
        return isset($_SESSION['user_role']) && in_array($_SESSION['user_role'], ['admin', 'staff']);
    }
}

// ตรวจสอบสิทธิ์ตาม Role
if (!function_exists('check_user_role')) {
    function check_user_role($required_role = null) {
        if ($required_role === null) {
            return true;
        }
        
        $user_role = $_SESSION['user_role'] ?? '';
        
        if ($required_role === 'admin' && $user_role !== 'admin') {
            return false;
        }
        
        if ($required_role === 'staff' && !in_array($user_role, ['admin', 'staff'])) {
            return false;
        }
        
        return true;
    }
}

// บังคับใช้สิทธิ์ตาม Role
if (!function_exists('require_role')) {
    function require_role($required_role) {
        check_login(); // ตรวจสอบล็อกอินก่อน
        
        if (!check_user_role($required_role)) {
            http_response_code(403);
            ?>
            <!DOCTYPE html>
            <html lang="th">
            <head>
                <meta charset="UTF-8">
                <meta name="viewport" content="width=device-width, initial-scale=1.0">
                <title>ไม่มีสิทธิ์เข้าถึง</title>
                <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
                <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
            </head>
            <body>
                <div class="container mt-5">
                    <div class="row justify-content-center">
                        <div class="col-md-6">
                            <div class="card border-danger">
                                <div class="card-body text-center">
                                    <i class="bi bi-exclamation-triangle-fill text-danger" style="font-size: 4rem;"></i>
                                    <h3 class="card-title text-danger mt-3">ไม่มีสิทธิ์เข้าถึง</h3>
                                    <p class="card-text">
                                        คุณไม่มีสิทธิ์เข้าถึงหน้านี้<br>
                                        <small class="text-muted">ต้องการสิทธิ์: <?php echo $required_role; ?></small>
                                    </p>
                                    <div class="d-grid gap-2">
                                        <a href="index.php" class="btn btn-primary">
                                            <i class="bi bi-house me-2"></i>กลับหน้าแรก
                                        </a>
                                        <a href="login.php" class="btn btn-outline-secondary">
                                            <i class="bi bi-box-arrow-in-right me-2"></i>เข้าสู่ระบบใหม่
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </body>
            </html>
            <?php
            exit;
        }
    }
}

// ข้อมูลผู้ใช้ปัจจุบัน
if (!function_exists('get_current_user_info')) {
    function get_current_user_info() {
        return [
            'user_id' => $_SESSION['user_id'] ?? null,
            'username' => $_SESSION['username'] ?? '',
            'full_name' => $_SESSION['full_name'] ?? '',
            'user_role' => $_SESSION['user_role'] ?? ''
        ];
    }
}

// ตรวจสอบว่าผู้ใช้ล็อกอินแล้วหรือไม่
if (!function_exists('is_logged_in')) {
    function is_logged_in() {
        return isset($_SESSION['user_id']);
    }
}

// JavaScript สำหรับยืนยันการลบ
if (!function_exists('confirm_delete_script')) {
    function confirm_delete_script() {
        echo '<script>
        function confirmDelete(message) {
            return confirm(message || "คุณต้องการลบรายการนี้หรือไม่?");
        }
        </script>';
    }
}

// เรียกใช้ script อัตโนมัติ
confirm_delete_script();
?>