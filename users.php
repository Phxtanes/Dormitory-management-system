<?php
$page_title = "จัดการผู้ใช้งาน";
require_once 'auth_check.php';
require_once 'config.php';

// ตรวจสอบสิทธิ์ (เฉพาะ admin เท่านั้น)
require_role('admin');

$success_message = '';
$error_message = '';

// เพิ่มผู้ใช้ใหม่
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_user'])) {
    $username = trim($_POST['username']);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    $full_name = trim($_POST['full_name']);
    $email = trim($_POST['email']);
    $user_role = $_POST['user_role'];
    
    // ตรวจสอบข้อมูล
    if (empty($username) || empty($password) || empty($full_name)) {
        $error_message = 'กรุณากรอกข้อมูลที่จำเป็นให้ครบถ้วน';
    } elseif ($password !== $confirm_password) {
        $error_message = 'รหัสผ่านไม่ตรงกัน';
    } elseif (strlen($password) < 6) {
        $error_message = 'รหัสผ่านต้องมีอย่างน้อย 6 ตัวอักษร';
    } elseif (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error_message = 'รูปแบบอีเมลไม่ถูกต้อง';
    } else {
        try {
            // ตรวจสอบชื่อผู้ใช้ซ้ำ
            $check_stmt = $pdo->prepare("SELECT user_id FROM users WHERE username = ?");
            $check_stmt->execute([$username]);
            if ($check_stmt->fetch()) {
                $error_message = 'ชื่อผู้ใช้นี้มีอยู่แล้ว';
            } else {
                // ตรวจสอบอีเมลซ้ำ
                if (!empty($email)) {
                    $check_email = $pdo->prepare("SELECT user_id FROM users WHERE email = ?");
                    $check_email->execute([$email]);
                    if ($check_email->fetch()) {
                        $error_message = 'อีเมลนี้มีผู้ใช้งานแล้ว';
                    }
                }
                
                if (empty($error_message)) {
                    $password_hash = password_hash($password, PASSWORD_DEFAULT);
                    $insert_stmt = $pdo->prepare("INSERT INTO users (username, password_hash, full_name, email, user_role) VALUES (?, ?, ?, ?, ?)");
                    $insert_stmt->execute([$username, $password_hash, $full_name, $email, $user_role]);
                    $success_message = 'เพิ่มผู้ใช้ใหม่เรียบร้อยแล้ว';
                }
            }
        } catch(PDOException $e) {
            $error_message = 'เกิดข้อผิดพลาดในการเพิ่มผู้ใช้';
        }
    }
}

// แก้ไขข้อมูลผู้ใช้
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['edit_user'])) {
    $user_id = $_POST['user_id'];
    $full_name = trim($_POST['edit_full_name']);
    $email = trim($_POST['edit_email']);
    $user_role = $_POST['edit_user_role'];
    $is_active = isset($_POST['edit_is_active']) ? 1 : 0;
    
    // ไม่ให้แก้ไขบัญชีตัวเอง
    if ($user_id == $_SESSION['user_id']) {
        $error_message = 'ไม่สามารถแก้ไขบัญชีของตัวเองได้';
    } elseif (empty($full_name)) {
        $error_message = 'กรุณากรอกชื่อ-นามสกุล';
    } elseif (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error_message = 'รูปแบบอีเมลไม่ถูกต้อง';
    } else {
        try {
            // ตรวจสอบอีเมลซ้ำ
            if (!empty($email)) {
                $check_email = $pdo->prepare("SELECT user_id FROM users WHERE email = ? AND user_id != ?");
                $check_email->execute([$email, $user_id]);
                if ($check_email->fetch()) {
                    $error_message = 'อีเมลนี้มีผู้ใช้งานแล้ว';
                }
            }
            
            if (empty($error_message)) {
                $update_stmt = $pdo->prepare("UPDATE users SET full_name = ?, email = ?, user_role = ?, is_active = ? WHERE user_id = ?");
                $update_stmt->execute([$full_name, $email, $user_role, $is_active, $user_id]);
                $success_message = 'อัพเดทข้อมูลผู้ใช้เรียบร้อยแล้ว';
            }
        } catch(PDOException $e) {
            $error_message = 'เกิดข้อผิดพลาดในการอัพเดทข้อมูล';
        }
    }
}

// รีเซ็ตรหัสผ่าน
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['reset_password'])) {
    $user_id = $_POST['user_id'];
    $new_password = $_POST['new_password'];
    
    if ($user_id == $_SESSION['user_id']) {
        $error_message = 'ไม่สามารถรีเซ็ตรหัสผ่านของตัวเองได้';
    } elseif (strlen($new_password) < 6) {
        $error_message = 'รหัสผ่านต้องมีอย่างน้อย 6 ตัวอักษร';
    } else {
        try {
            $password_hash = password_hash($new_password, PASSWORD_DEFAULT);
            $update_stmt = $pdo->prepare("UPDATE users SET password_hash = ? WHERE user_id = ?");
            $update_stmt->execute([$password_hash, $user_id]);
            $success_message = 'รีเซ็ตรหัสผ่านเรียบร้อยแล้ว';
        } catch(PDOException $e) {
            $error_message = 'เกิดข้อผิดพลาดในการรีเซ็ตรหัสผ่าน';
        }
    }
}

// ลบผู้ใช้
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['delete_user'])) {
    $user_id = $_POST['user_id'];
    
    if ($user_id == $_SESSION['user_id']) {
        $error_message = 'ไม่สามารถลบบัญชีของตัวเองได้';
    } else {
        try {
            $delete_stmt = $pdo->prepare("DELETE FROM users WHERE user_id = ?");
            $delete_stmt->execute([$user_id]);
            $success_message = 'ลบผู้ใช้เรียบร้อยแล้ว';
        } catch(PDOException $e) {
            $error_message = 'เกิดข้อผิดพลาดในการลบผู้ใช้';
        }
    }
}

// ดึงข้อมูลผู้ใช้ทั้งหมด
try {
    $stmt = $pdo->query("SELECT * FROM users ORDER BY created_at DESC");
    $users = $stmt->fetchAll();
} catch(PDOException $e) {
    $error_message = 'เกิดข้อผิดพลาดในการโหลดข้อมูล';
    $users = [];
}

include 'includes/header.php';
?>

<div class="container mt-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="index.php">หน้าแรก</a></li>
            <li class="breadcrumb-item active">จัดการผู้ใช้งาน</li>
        </ol>
    </nav>

    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2><i class="bi bi-people me-2"></i>จัดการผู้ใช้งาน</h2>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addUserModal">
            <i class="bi bi-person-plus me-2"></i>เพิ่มผู้ใช้ใหม่
        </button>
    </div>

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

    <!-- ตารางผู้ใช้ -->
    <div class="card">
        <div class="card-header">
            <h5 class="card-title mb-0">รายการผู้ใช้ทั้งหมด (<?php echo count($users); ?> คน)</h5>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead class="table-light">
                        <tr>
                            <th>ลำดับ</th>
                            <th>ชื่อผู้ใช้</th>
                            <th>ชื่อ-นามสกุล</th>
                            <th>อีเมล</th>
                            <th>สิทธิ์</th>
                            <th>สถานะ</th>
                            <th>เข้าสู่ระบบล่าสุด</th>
                            <th class="text-center">จัดการ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $index => $user): ?>
                        <tr>
                            <td><?php echo $index + 1; ?></td>
                            <td>
                                <strong><?php echo htmlspecialchars($user['username']); ?></strong>
                                <?php if ($user['user_id'] == $_SESSION['user_id']): ?>
                                    <span class="badge bg-info ms-1">คุณ</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo htmlspecialchars($user['full_name']); ?></td>
                            <td>
                                <?php if ($user['email']): ?>
                                    <a href="mailto:<?php echo htmlspecialchars($user['email']); ?>">
                                        <?php echo htmlspecialchars($user['email']); ?>
                                    </a>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($user['user_role'] === 'admin'): ?>
                                    <span class="badge bg-danger">
                                        <i class="bi bi-shield-fill me-1"></i>ผู้ดูแลระบบ
                                    </span>
                                <?php else: ?>
                                    <span class="badge bg-primary">
                                        <i class="bi bi-person-badge me-1"></i>เจ้าหน้าที่
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($user['is_active']): ?>
                                    <span class="badge bg-success">
                                        <i class="bi bi-check-circle me-1"></i>ใช้งาน
                                    </span>
                                <?php else: ?>
                                    <span class="badge bg-secondary">
                                        <i class="bi bi-x-circle me-1"></i>ปิดใช้งาน
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($user['last_login']): ?>
                                    <?php echo date('d/m/Y H:i', strtotime($user['last_login'])); ?>
                                <?php else: ?>
                                    <span class="text-muted">ยังไม่เคยเข้าสู่ระบบ</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <?php if ($user['user_id'] != $_SESSION['user_id']): ?>
                                    <div class="btn-group" role="group">
                                        <button class="btn btn-sm btn-outline-primary" 
                                                onclick="editUser(<?php echo htmlspecialchars(json_encode($user)); ?>)">
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                        <button class="btn btn-sm btn-outline-warning" 
                                                onclick="resetPassword(<?php echo $user['user_id']; ?>, '<?php echo htmlspecialchars($user['username']); ?>')">
                                            <i class="bi bi-key"></i>
                                        </button>
                                        <button class="btn btn-sm btn-outline-danger" 
                                                onclick="deleteUser(<?php echo $user['user_id']; ?>, '<?php echo htmlspecialchars($user['username']); ?>')">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </div>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Modal เพิ่มผู้ใช้ใหม่ -->
<div class="modal fade" id="addUserModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-person-plus me-2"></i>เพิ่มผู้ใช้ใหม่
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="">
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="username" class="form-label">ชื่อผู้ใช้ <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="username" name="username" required maxlength="50">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="user_role" class="form-label">สิทธิ์การใช้งาน <span class="text-danger">*</span></label>
                                <select class="form-select" id="user_role" name="user_role" required>
                                    <option value="staff">เจ้าหน้าที่</option>
                                    <option value="admin">ผู้ดูแลระบบ</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="full_name" class="form-label">ชื่อ-นามสกุล <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="full_name" name="full_name" required maxlength="100">
                    </div>
                    
                    <div class="mb-3">
                        <label for="email" class="form-label">อีเมล</label>
                        <input type="email" class="form-control" id="email" name="email" maxlength="100">
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="password" class="form-label">รหัสผ่าน <span class="text-danger">*</span></label>
                                <input type="password" class="form-control" id="password" name="password" required minlength="6">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="confirm_password" class="form-label">ยืนยันรหัสผ่าน <span class="text-danger">*</span></label>
                                <input type="password" class="form-control" id="confirm_password" name="confirm_password" required>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ยกเลิก</button>
                    <button type="submit" name="add_user" class="btn btn-primary">
                        <i class="bi bi-check-lg me-2"></i>เพิ่มผู้ใช้
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal แก้ไขผู้ใช้ -->
<div class="modal fade" id="editUserModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-pencil me-2"></i>แก้ไขข้อมูลผู้ใช้
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="">
                <div class="modal-body">
                    <input type="hidden" id="edit_user_id" name="user_id">
                    
                    <div class="mb-3">
                        <label class="form-label">ชื่อผู้ใช้</label>
                        <input type="text" class="form-control" id="edit_username" readonly>
                    </div>
                    
                    <div class="mb-3">
                        <label for="edit_full_name" class="form-label">ชื่อ-นามสกุล <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="edit_full_name" name="edit_full_name" required maxlength="100">
                    </div>
                    
                    <div class="mb-3">
                        <label for="edit_email" class="form-label">อีเมล</label>
                        <input type="email" class="form-control" id="edit_email" name="edit_email" maxlength="100">
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="edit_user_role" class="form-label">สิทธิ์การใช้งาน</label>
                                <select class="form-select" id="edit_user_role" name="edit_user_role" required>
                                    <option value="staff">เจ้าหน้าที่</option>
                                    <option value="admin">ผู้ดูแลระบบ</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <div class="form-check mt-4">
                                    <input class="form-check-input" type="checkbox" id="edit_is_active" name="edit_is_active" checked>
                                    <label class="form-check-label" for="edit_is_active">
                                        เปิดใช้งานบัญชี
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ยกเลิก</button>
                    <button type="submit" name="edit_user" class="btn btn-primary">
                        <i class="bi bi-check-lg me-2"></i>บันทึกการเปลี่ยนแปลง
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Form ซ่อนสำหรับการลบและรีเซ็ตรหัสผ่าน -->
<form id="deleteForm" method="POST" style="display: none;">
    <input type="hidden" name="user_id" id="delete_user_id">
    <input type="hidden" name="delete_user" value="1">
</form>

<form id="resetPasswordForm" method="POST" style="display: none;">
    <input type="hidden" name="user_id" id="reset_user_id">
    <input type="hidden" name="new_password" id="reset_new_password">
    <input type="hidden" name="reset_password" value="1">
</form>

<script>
// ฟังก์ชันแก้ไขผู้ใช้
function editUser(user) {
    document.getElementById('edit_user_id').value = user.user_id;
    document.getElementById('edit_username').value = user.username;
    document.getElementById('edit_full_name').value = user.full_name;
    document.getElementById('edit_email').value = user.email || '';
    document.getElementById('edit_user_role').value = user.user_role;
    document.getElementById('edit_is_active').checked = user.is_active == 1;
    
    new bootstrap.Modal(document.getElementById('editUserModal')).show();
}

// ฟังก์ชันรีเซ็ตรหัสผ่าน
function resetPassword(userId, username) {
    const newPassword = prompt(`รีเซ็ตรหัสผ่านสำหรับ: ${username}\n\nกรุณาใส่รหัสผ่านใหม่ (อย่างน้อย 6 ตัวอักษร):`);
    
    if (newPassword !== null) {
        if (newPassword.length < 6) {
            alert('รหัสผ่านต้องมีอย่างน้อย 6 ตัวอักษร');
            return;
        }
        
        if (confirm(`ยืนยันการรีเซ็ตรหัสผ่านสำหรับ ${username}?`)) {
            document.getElementById('reset_user_id').value = userId;
            document.getElementById('reset_new_password').value = newPassword;
            document.getElementById('resetPasswordForm').submit();
        }
    }
}

// ฟังก์ชันลบผู้ใช้
function deleteUser(userId, username) {
    if (confirm(`ยืนยันการลบผู้ใช้: ${username}?\n\nการดำเนินการนี้ไม่สามารถยกเลิกได้`)) {
        document.getElementById('delete_user_id').value = userId;
        document.getElementById('deleteForm').submit();
    }
}

// ตรวจสอบรหัสผ่านตรงกัน
document.getElementById('confirm_password').addEventListener('input', function() {
    const password = document.getElementById('password').value;
    const confirmPassword = this.value;
    
    if (password !== confirmPassword) {
        this.setCustomValidity('รหัสผ่านไม่ตรงกัน');
    } else {
        this.setCustomValidity('');
    }
});

// Auto-hide alerts
document.addEventListener('DOMContentLoaded', function() {
    const alerts = document.querySelectorAll('.alert');
    alerts.forEach(function(alert) {
        setTimeout(function() {
            if (alert && !alert.classList.contains('fade')) {
                alert.classList.add('fade');
            }
            setTimeout(function() {
                if (alert && alert.parentNode) {
                    alert.parentNode.removeChild(alert);
                }
            }, 150);
        }, 5000);
    });
});
</script>

<?php include 'includes/footer.php'; ?>