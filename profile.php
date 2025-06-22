<?php
$page_title = "โปรไฟล์ผู้ใช้";
require_once 'auth_check.php';
require_once 'config.php';

$success_message = '';
$error_message = '';

// ดึงข้อมูลผู้ใช้ปัจจุบัน
try {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();
    
    if (!$user) {
        header('Location: logout.php');
        exit;
    }
} catch(PDOException $e) {
    $error_message = 'เกิดข้อผิดพลาดในการโหลดข้อมูล';
}

// อัพเดทข้อมูลโปรไฟล์
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_profile'])) {
    $full_name = trim($_POST['full_name']);
    $email = trim($_POST['email']);
    
    // ตรวจสอบข้อมูล
    if (empty($full_name)) {
        $error_message = 'กรุณากรอกชื่อ-นามสกุล';
    } elseif (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error_message = 'รูปแบบอีเมลไม่ถูกต้อง';
    } else {
        try {
            // ตรวจสอบอีเมลซ้ำ (ถ้ามีการเปลี่ยน)
            if (!empty($email) && $email !== $user['email']) {
                $check_stmt = $pdo->prepare("SELECT user_id FROM users WHERE email = ? AND user_id != ?");
                $check_stmt->execute([$email, $_SESSION['user_id']]);
                if ($check_stmt->fetch()) {
                    $error_message = 'อีเมลนี้มีผู้ใช้งานแล้ว';
                }
            }
            
            if (empty($error_message)) {
                $update_stmt = $pdo->prepare("UPDATE users SET full_name = ?, email = ? WHERE user_id = ?");
                $update_stmt->execute([$full_name, $email, $_SESSION['user_id']]);
                
                // อัพเดท session
                $_SESSION['full_name'] = $full_name;
                
                // รีโหลดข้อมูลผู้ใช้
                $stmt->execute([$_SESSION['user_id']]);
                $user = $stmt->fetch();
                
                $success_message = 'อัพเดทข้อมูลเรียบร้อยแล้ว';
            }
        } catch(PDOException $e) {
            $error_message = 'เกิดข้อผิดพลาดในการบันทึกข้อมูล';
        }
    }
}

include 'includes/header.php';
?>

<div class="container mt-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="index.php">หน้าแรก</a></li>
            <li class="breadcrumb-item active">โปรไฟล์ผู้ใช้</li>
        </ol>
    </nav>

    <div class="row">
        <div class="col-md-8">
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title mb-0">
                        <i class="bi bi-person-circle me-2"></i>ข้อมูลโปรไฟล์
                    </h5>
                </div>
                <div class="card-body">
                    <?php if ($success_message): ?>
                        <div class="alert alert-success alert-dismissible fade show" role="alert">
                            <i class="bi bi-check-circle me-2"></i><?php echo $success_message; ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>

                    <?php if ($error_message): ?>
                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                            <i class="bi bi-exclamation-triangle me-2"></i><?php echo $error_message; ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>

                    <form method="POST" action="">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="username" class="form-label">
                                        <i class="bi bi-person me-1"></i>ชื่อผู้ใช้
                                    </label>
                                    <input type="text" class="form-control" id="username" 
                                           value="<?php echo htmlspecialchars($user['username']); ?>" 
                                           readonly>
                                    <div class="form-text">ไม่สามารถเปลี่ยนชื่อผู้ใช้ได้</div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="user_role" class="form-label">
                                        <i class="bi bi-shield me-1"></i>สิทธิ์การใช้งาน
                                    </label>
                                    <input type="text" class="form-control" id="user_role" 
                                           value="<?php echo $user['user_role'] === 'admin' ? 'ผู้ดูแลระบบ' : 'เจ้าหน้าที่'; ?>" 
                                           readonly>
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="full_name" class="form-label">
                                        <i class="bi bi-person-badge me-1"></i>ชื่อ-นามสกุล <span class="text-danger">*</span>
                                    </label>
                                    <input type="text" class="form-control" id="full_name" name="full_name" 
                                           value="<?php echo htmlspecialchars($user['full_name'] ?? ''); ?>" 
                                           required maxlength="100">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="email" class="form-label">
                                        <i class="bi bi-envelope me-1"></i>อีเมล
                                    </label>
                                    <input type="email" class="form-control" id="email" name="email" 
                                           value="<?php echo htmlspecialchars($user['email'] ?? ''); ?>" 
                                           maxlength="100">
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">
                                        <i class="bi bi-calendar-plus me-1"></i>วันที่สร้างบัญชี
                                    </label>
                                    <input type="text" class="form-control" 
                                           value="<?php echo date('d/m/Y H:i', strtotime($user['created_at'])); ?>" 
                                           readonly>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">
                                        <i class="bi bi-clock-history me-1"></i>เข้าสู่ระบบล่าสุด
                                    </label>
                                    <input type="text" class="form-control" 
                                           value="<?php echo $user['last_login'] ? date('d/m/Y H:i', strtotime($user['last_login'])) : 'ยังไม่เคยเข้าสู่ระบบ'; ?>" 
                                           readonly>
                                </div>
                            </div>
                        </div>

                        <hr>

                        <div class="d-flex justify-content-between">
                            <button type="submit" name="update_profile" class="btn btn-primary">
                                <i class="bi bi-check-lg me-2"></i>บันทึกการเปลี่ยนแปลง
                            </button>
                            <a href="change_password.php" class="btn btn-outline-secondary">
                                <i class="bi bi-key me-2"></i>เปลี่ยนรหัสผ่าน
                            </a>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <!-- ข้อมูลสถิติผู้ใช้ -->
            <div class="card mb-3">
                <div class="card-header">
                    <h6 class="card-title mb-0">
                        <i class="bi bi-info-circle me-2"></i>ข้อมูลบัญชี
                    </h6>
                </div>
                <div class="card-body">
                    <div class="row text-center">
                        <div class="col-6">
                            <div class="border-end">
                                <h4 class="text-primary mb-1">
                                    <?php echo $user['is_active'] ? '<i class="bi bi-check-circle-fill"></i>' : '<i class="bi bi-x-circle-fill text-danger"></i>'; ?>
                                </h4>
                                <small class="text-muted">สถานะบัญชี</small>
                            </div>
                        </div>
                        <div class="col-6">
                            <h4 class="text-info mb-1">
                                <i class="bi bi-shield-check"></i>
                            </h4>
                            <small class="text-muted">ปลอดภัย</small>
                        </div>
                    </div>
                </div>
            </div>

            <!-- การตั้งค่าด่วน -->
            <div class="card">
                <div class="card-header">
                    <h6 class="card-title mb-0">
                        <i class="bi bi-gear me-2"></i>การตั้งค่าด่วน
                    </h6>
                </div>
                <div class="card-body">
                    <div class="d-grid gap-2">
                        <a href="change_password.php" class="btn btn-outline-primary btn-sm">
                            <i class="bi bi-key me-2"></i>เปลี่ยนรหัสผ่าน
                        </a>
                        <?php if (isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin'): ?>
                        <a href="users.php" class="btn btn-outline-info btn-sm">
                            <i class="bi bi-people me-2"></i>จัดการผู้ใช้
                        </a>
                        <a href="system_settings.php" class="btn btn-outline-warning btn-sm">
                            <i class="bi bi-gear-wide-connected me-2"></i>ตั้งค่าระบบ
                        </a>
                        <?php endif; ?>
                        <a href="logout.php" class="btn btn-outline-danger btn-sm" 
                           onclick="return confirm('ต้องการออกจากระบบหรือไม่?')">
                            <i class="bi bi-box-arrow-right me-2"></i>ออกจากระบบ
                        </a>
                    </div>
                </div>
            </div>

            <!-- เคล็ดลับการใช้งาน -->
            <div class="card mt-3">
                <div class="card-header">
                    <h6 class="card-title mb-0">
                        <i class="bi bi-lightbulb me-2"></i>เคล็ดลับการใช้งาน
                    </h6>
                </div>
                <div class="card-body">
                    <div class="small">
                        <p class="mb-2">
                            <i class="bi bi-check2 text-success me-1"></i>
                            ควรเปลี่ยนรหัสผ่านเป็นประจำ
                        </p>
                        <p class="mb-2">
                            <i class="bi bi-check2 text-success me-1"></i>
                            ใช้อีเมลที่ใช้งานจริงเพื่อรับการแจ้งเตือน
                        </p>
                        <p class="mb-0">
                            <i class="bi bi-check2 text-success me-1"></i>
                            ออกจากระบบเมื่อใช้งานเสร็จ
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Auto-hide alerts after 5 seconds
document.addEventListener('DOMContentLoaded', function() {
    const alerts = document.querySelectorAll('.alert');
    alerts.forEach(function(alert) {
        setTimeout(function() {
            const bsAlert = new bootstrap.Alert(alert);
            bsAlert.close();
        }, 5000);
    });
});

// Form validation
document.querySelector('form').addEventListener('submit', function(e) {
    const fullName = document.getElementById('full_name').value.trim();
    const email = document.getElementById('email').value.trim();
    
    if (!fullName) {
        e.preventDefault();
        alert('กรุณากรอกชื่อ-นามสกุล');
        document.getElementById('full_name').focus();
        return;
    }
    
    if (email && !isValidEmail(email)) {
        e.preventDefault();
        alert('รูปแบบอีเมลไม่ถูกต้อง');
        document.getElementById('email').focus();
        return;
    }
});

function isValidEmail(email) {
    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    return emailRegex.test(email);
}
</script>

<?php include 'includes/footer.php'; ?>