<?php
$page_title = "เปลี่ยนรหัสผ่าน";
require_once 'includes/header.php';

$success_message = '';
$error_message = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $current_password = $_POST['current_password'];
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];
    
    // ตรวจสอบข้อมูล
    if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
        $error_message = 'กรุณากรอกข้อมูลให้ครบถ้วน';
    } elseif (strlen($new_password) < 6) {
        $error_message = 'รหัสผ่านใหม่ต้องมีความยาวอย่างน้อย 6 ตัวอักษร';
    } elseif ($new_password !== $confirm_password) {
        $error_message = 'รหัสผ่านใหม่และการยืนยันไม่ตรงกัน';
    } else {
        try {
            // ตรวจสอบรหัสผ่านปัจจุบัน
            $stmt = $pdo->prepare("SELECT password_hash FROM users WHERE user_id = ?");
            $stmt->execute([$_SESSION['user_id']]);
            $user = $stmt->fetch();
            
            if ($user && password_verify($current_password, $user['password_hash'])) {
                // อัพเดทรหัสผ่านใหม่
                $new_hash = password_hash($new_password, PASSWORD_DEFAULT);
                $update_stmt = $pdo->prepare("UPDATE users SET password_hash = ? WHERE user_id = ?");
                $update_stmt->execute([$new_hash, $_SESSION['user_id']]);
                
                $success_message = 'เปลี่ยนรหัสผ่านเรียบร้อยแล้ว';
                
                // Clear form
                $_POST = [];
            } else {
                $error_message = 'รหัสผ่านปัจจุบันไม่ถูกต้อง';
            }
            
        } catch(PDOException $e) {
            $error_message = 'เกิดข้อผิดพลาดในระบบ กรุณาลองใหม่อีกครั้ง';
        }
    }
}
?>

<div class="container mt-4">
    <div class="row justify-content-center">
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">
                    <h4 class="mb-0">
                        <i class="bi bi-key me-2"></i>เปลี่ยนรหัสผ่าน
                    </h4>
                </div>
                <div class="card-body">
                    <?php if ($success_message): ?>
                        <div class="alert alert-success">
                            <i class="bi bi-check-circle me-2"></i><?php echo $success_message; ?>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($error_message): ?>
                        <div class="alert alert-danger">
                            <i class="bi bi-exclamation-triangle me-2"></i><?php echo $error_message; ?>
                        </div>
                    <?php endif; ?>
                    
                    <form method="POST" action="">
                        <div class="mb-3">
                            <label for="current_password" class="form-label">
                                รหัสผ่านปัจจุบัน <span class="text-danger">*</span>
                            </label>
                            <div class="input-group">
                                <input type="password" class="form-control" id="current_password" 
                                       name="current_password" required>
                                <button class="btn btn-outline-secondary" type="button" 
                                        onclick="togglePassword('current_password', this)">
                                    <i class="bi bi-eye"></i>
                                </button>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="new_password" class="form-label">
                                รหัสผ่านใหม่ <span class="text-danger">*</span>
                            </label>
                            <div class="input-group">
                                <input type="password" class="form-control" id="new_password" 
                                       name="new_password" required minlength="6">
                                <button class="btn btn-outline-secondary" type="button" 
                                        onclick="togglePassword('new_password', this)">
                                    <i class="bi bi-eye"></i>
                                </button>
                            </div>
                            <div class="form-text">รหัสผ่านต้องมีความยาวอย่างน้อย 6 ตัวอักษร</div>
                        </div>
                        
                        <div class="mb-4">
                            <label for="confirm_password" class="form-label">
                                ยืนยันรหัสผ่านใหม่ <span class="text-danger">*</span>
                            </label>
                            <div class="input-group">
                                <input type="password" class="form-control" id="confirm_password" 
                                       name="confirm_password" required minlength="6">
                                <button class="btn btn-outline-secondary" type="button" 
                                        onclick="togglePassword('confirm_password', this)">
                                    <i class="bi bi-eye"></i>
                                </button>
                            </div>
                        </div>
                        
                        <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                            <a href="index.php" class="btn btn-secondary">
                                <i class="bi bi-arrow-left me-2"></i>ยกเลิก
                            </a>
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-check-lg me-2"></i>เปลี่ยนรหัสผ่าน
                            </button>
                        </div>
                    </form>
                </div>
            </div>
            
            <!-- Security Tips -->
            <div class="card mt-4">
                <div class="card-header">
                    <h6 class="mb-0">
                        <i class="bi bi-shield-check me-2"></i>คำแนะนำความปลอดภัย
                    </h6>
                </div>
                <div class="card-body">
                    <ul class="list-unstyled mb-0">
                        <li class="mb-2">
                            <i class="bi bi-check-circle text-success me-2"></i>
                            ใช้รหัสผ่านที่มีความยาวอย่างน้อย 8 ตัวอักษร
                        </li>
                        <li class="mb-2">
                            <i class="bi bi-check-circle text-success me-2"></i>
                            รวมตัวอักษรพิมพ์ใหญ่ พิมพ์เล็ก ตัวเลข และสัญลักษณ์
                        </li>
                        <li class="mb-2">
                            <i class="bi bi-check-circle text-success me-2"></i>
                            หลีกเลี่ยงการใช้ข้อมูลส่วนตัวที่เดาได้ง่าย
                        </li>
                        <li class="mb-0">
                            <i class="bi bi-check-circle text-success me-2"></i>
                            เปลี่ยนรหัสผ่านเป็นประจำทุก 3-6 เดือน
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function togglePassword(fieldId, button) {
    const field = document.getElementById(fieldId);
    const icon = button.querySelector('i');
    
    if (field.type === 'password') {
        field.type = 'text';
        icon.className = 'bi bi-eye-slash';
    } else {
        field.type = 'password';
        icon.className = 'bi bi-eye';
    }
}

// Real-time password validation
document.getElementById('new_password').addEventListener('input', function() {
    const password = this.value;
    const confirmField = document.getElementById('confirm_password');
    
    // Update confirm password validation
    if (confirmField.value && confirmField.value !== password) {
        confirmField.setCustomValidity('รหัสผ่านไม่ตรงกัน');
    } else {
        confirmField.setCustomValidity('');
    }
});

document.getElementById('confirm_password').addEventListener('input', function() {
    const newPassword = document.getElementById('new_password').value;
    
    if (this.value !== newPassword) {
        this.setCustomValidity('รหัสผ่านไม่ตรงกัน');
    } else {
        this.setCustomValidity('');
    }
});
</script>

<?php include 'includes/footer.php'; ?>