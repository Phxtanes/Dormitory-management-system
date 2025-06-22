<?php
// ไฟล์สำหรับตรวจสอบการเข้าสู่ระบบ
// ใช้ include ไฟล์นี้ในหน้าที่ต้องการการป้องกัน

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// ตรวจสอบว่าผู้ใช้เข้าสู่ระบบแล้วหรือไม่
if (!isset($_SESSION['user_id'])) {
    // เก็บ URL ปัจจุบันเพื่อกลับมาหลังจากล็อกอิน
    $current_url = $_SERVER['REQUEST_URI'];
    $redirect_url = 'login.php?redirect=' . urlencode($current_url);
    
    header('Location: ' . $redirect_url);
    exit;
}

// ตรวจสอบว่าบัญชีผู้ใช้ยังคงใช้งานได้
require_once 'config.php';

try {
    $stmt = $pdo->prepare("SELECT is_active FROM users WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();
    
    if (!$user || !$user['is_active']) {
        // บัญชีถูกปิดการใช้งาน
        session_destroy();
        header('Location: login.php?message=account_disabled');
        exit;
    }
    
} catch(PDOException $e) {
    // หากเกิดข้อผิดพลาดในการเชื่อมต่อฐานข้อมูล
    error_log("Database error in auth_check: " . $e->getMessage());
}

// Function สำหรับตรวจสอบสิทธิ์ตาม Role
function check_user_role($required_role = null) {
    if ($required_role === null) {
        return true; // ไม่ต้องการสิทธิ์พิเศษ
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

// Function สำหรับแสดงข้อผิดพลาดเมื่อไม่มีสิทธิ์
function require_role($required_role) {
    if (!check_user_role($required_role)) {
        http_response_code(403);
        $page_title = "ไม่มีสิทธิ์เข้าถึง";
        include 'includes/header.php';
        ?>
        <div class="container mt-5">
            <div class="row justify-content-center">
                <div class="col-md-6">
                    <div class="card border-danger">
                        <div class="card-body text-center">
                            <i class="bi bi-exclamation-triangle-fill text-danger" style="font-size: 4rem;"></i>
                            <h3 class="card-title text-danger mt-3">ไม่มีสิทธิ์เข้าถึง</h3>
                            <p class="card-text">
                                คุณไม่มีสิทธิ์เข้าถึงหน้านี้<br>
                                กรุณาติดต่อผู้ดูแลระบบหากคิดว่าเป็นข้อผิดพลาด
                            </p>
                            <a href="index.php" class="btn btn-primary">
                                <i class="bi bi-house me-2"></i>กลับหน้าแรก
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php
        include 'includes/footer.php';
        exit;
    }
}

// Function สำหรับแสดงข้อมูลผู้ใช้ปัจจุบัน
function get_current_user_info() {
    return [
        'user_id' => $_SESSION['user_id'],
        'username' => $_SESSION['username'],
        'full_name' => $_SESSION['full_name'],
        'user_role' => $_SESSION['user_role']
    ];
}

// Function สำหรับตรวจสอบว่าเป็น Admin หรือไม่
function is_admin() {
    return isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin';
}

// Function สำหรับตรวจสอบว่าเป็น Staff หรือไม่ (รวม Admin ด้วย)
function is_staff() {
    return isset($_SESSION['user_role']) && in_array($_SESSION['user_role'], ['admin', 'staff']);
}
?>