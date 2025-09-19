<?php
$page_title = "ตั้งค่าระบบ";
require_once 'auth_check.php';
require_once 'config.php';

// ตรวจสอบสิทธิ์ (เฉพาะ admin เท่านั้น)
require_role('admin');

$success_message = '';
$error_message = '';

// สร้างตาราง system_settings หากยังไม่มี
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS system_settings (
        setting_id INT AUTO_INCREMENT PRIMARY KEY,
        setting_key VARCHAR(100) NOT NULL UNIQUE,
        setting_value TEXT,
        setting_description TEXT,
        setting_type ENUM('text', 'number', 'boolean', 'email', 'url') DEFAULT 'text',
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        updated_by INT,
        FOREIGN KEY (updated_by) REFERENCES users(user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    
    // เพิ่มการตั้งค่าเริ่มต้น
    $default_settings = [
        ['dormitory_name', 'หอพักตัวอย่าง', 'ชื่อหอพัก', 'text'],
        ['dormitory_address', '', 'ที่อยู่หอพัก', 'text'],
        ['dormitory_phone', '', 'เบอร์โทรศัพท์', 'text'],
        ['dormitory_email', '', 'อีเมลติดต่อ', 'email'],
        ['water_unit_price', '25.00', 'ราคาน้ำต่อหน่วย (บาท)', 'number'],
        ['electric_unit_price', '8.00', 'ราคาไฟต่อหน่วย (บาท)', 'number'],
        ['late_fee_per_day', '50.00', 'ค่าปรับล่าช้าต่อวัน (บาท)', 'number'],
        ['payment_due_days', '7', 'จำนวนวันครบกำหนดชำระ', 'number'],
        ['auto_backup', '1', 'สำรองข้อมูลอัตโนมัติ', 'boolean'],
        ['notification_email', '1', 'แจ้งเตือนทางอีเมล', 'boolean'],
        ['system_maintenance', '0', 'โหมดปิดปรุงระบบ', 'boolean'],
        ['max_login_attempts', '5', 'จำนวนครั้งล็อกอินผิดสูงสุด', 'number'],
        ['session_timeout', '30', 'หมดอายุ Session (นาที)', 'number']
    ];
    
    $check_stmt = $pdo->prepare("SELECT COUNT(*) FROM system_settings");
    $check_stmt->execute();
    $setting_count = $check_stmt->fetchColumn();
    
    if ($setting_count == 0) {
        $insert_stmt = $pdo->prepare("INSERT IGNORE INTO system_settings (setting_key, setting_value, setting_description, setting_type) VALUES (?, ?, ?, ?)");
        foreach ($default_settings as $setting) {
            $insert_stmt->execute($setting);
        }
    }
} catch(PDOException $e) {
    $error_message = 'เกิดข้อผิดพลาดในการสร้างตารางการตั้งค่า';
}

// บันทึกการตั้งค่า
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['save_settings'])) {
    try {
        $pdo->beginTransaction();
        
        foreach ($_POST as $key => $value) {
            if ($key !== 'save_settings') {
                // ตรวจสอบประเภทข้อมูล
                $check_type = $pdo->prepare("SELECT setting_type FROM system_settings WHERE setting_key = ?");
                $check_type->execute([$key]);
                $setting_type = $check_type->fetchColumn();
                
                if ($setting_type) {
                    // แปลงค่าตามประเภท
                    if ($setting_type === 'boolean') {
                        $value = isset($_POST[$key]) ? '1' : '0';
                    } elseif ($setting_type === 'number') {
                        $value = is_numeric($value) ? $value : '0';
                    } elseif ($setting_type === 'email' && !empty($value)) {
                        if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
                            throw new Exception('รูปแบบอีเมลไม่ถูกต้อง: ' . $key);
                        }
                    }
                    
                    $update_stmt = $pdo->prepare("UPDATE system_settings SET setting_value = ?, updated_by = ? WHERE setting_key = ?");
                    $update_stmt->execute([$value, $_SESSION['user_id'], $key]);
                }
            }
        }
        
        $pdo->commit();
        $success_message = 'บันทึกการตั้งค่าเรียบร้อยแล้ว';
    } catch(Exception $e) {
        $pdo->rollback();
        $error_message = $e->getMessage();
    } catch(PDOException $e) {
        $pdo->rollback();
        $error_message = 'เกิดข้อผิดพลาดในการบันทึกการตั้งค่า';
    }
}

// ดึงการตั้งค่าทั้งหมด
try {
    $stmt = $pdo->query("SELECT s.*, u.full_name as updated_by_name 
                        FROM system_settings s 
                        LEFT JOIN users u ON s.updated_by = u.user_id 
                        ORDER BY setting_key");
    $settings = $stmt->fetchAll();
    
    // แปลงเป็น associative array
    $settings_array = [];
    foreach ($settings as $setting) {
        $settings_array[$setting['setting_key']] = $setting;
    }
} catch(PDOException $e) {
    $error_message = 'เกิดข้อผิดพลาดในการโหลดการตั้งค่า';
    $settings_array = [];
}

include 'includes/header.php';
?>

<div class="container mt-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="index.php">หน้าแรก</a></li>
            <li class="breadcrumb-item active">ตั้งค่าระบบ</li>
        </ol>
    </nav>

    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2><i class="bi bi-gear-wide-connected me-2"></i>ตั้งค่าระบบ</h2>
        <div>
            <button class="btn btn-outline-secondary" onclick="resetToDefault()">
                <i class="bi bi-arrow-clockwise me-2"></i>รีเซ็ตเป็นค่าเริ่มต้น
            </button>
            <button class="btn btn-info" onclick="exportSettings()">
                <i class="bi bi-download me-2"></i>ส่งออกการตั้งค่า
            </button>
        </div>
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

    <form method="POST" action="">
        <div class="row">
            <!-- ข้อมูลหอพัก -->
            <div class="col-md-6">
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="card-title mb-0">
                            <i class="bi bi-building me-2"></i>ข้อมูลหอพัก
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label for="dormitory_name" class="form-label">ชื่อหอพัก</label>
                            <input type="text" class="form-control" id="dormitory_name" name="dormitory_name" 
                                   value="<?php echo htmlspecialchars($settings_array['dormitory_name']['setting_value'] ?? ''); ?>">
                        </div>
                        
                        <div class="mb-3">
                            <label for="dormitory_address" class="form-label">ที่อยู่หอพัก</label>
                            <textarea class="form-control" id="dormitory_address" name="dormitory_address" rows="3"><?php echo htmlspecialchars($settings_array['dormitory_address']['setting_value'] ?? ''); ?></textarea>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="dormitory_phone" class="form-label">เบอร์โทรศัพท์</label>
                                    <input type="text" class="form-control" id="dormitory_phone" name="dormitory_phone" 
                                           value="<?php echo htmlspecialchars($settings_array['dormitory_phone']['setting_value'] ?? ''); ?>">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="dormitory_email" class="form-label">อีเมลติดต่อ</label>
                                    <input type="email" class="form-control" id="dormitory_email" name="dormitory_email" 
                                           value="<?php echo htmlspecialchars($settings_array['dormitory_email']['setting_value'] ?? ''); ?>">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ค่าใช้จ่าย -->
            <div class="col-md-6">
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="card-title mb-0">
                            <i class="bi bi-calculator me-2"></i>ค่าใช้จ่าย
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="water_unit_price" class="form-label">ราคาน้ำต่อหน่วย (บาท)</label>
                                    <div class="input-group">
                                        <input type="number" class="form-control" id="water_unit_price" name="water_unit_price" 
                                               value="<?php echo $settings_array['water_unit_price']['setting_value'] ?? '25'; ?>" 
                                               step="0.01" min="0">
                                        <span class="input-group-text">บาท</span>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="electric_unit_price" class="form-label">ราคาไฟต่อหน่วย (บาท)</label>
                                    <div class="input-group">
                                        <input type="number" class="form-control" id="electric_unit_price" name="electric_unit_price" 
                                               value="<?php echo $settings_array['electric_unit_price']['setting_value'] ?? '8'; ?>" 
                                               step="0.01" min="0">
                                        <span class="input-group-text">บาท</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="late_fee_per_day" class="form-label">ค่าปรับล่าช้าต่อวัน</label>
                                    <div class="input-group">
                                        <input type="number" class="form-control" id="late_fee_per_day" name="late_fee_per_day" 
                                               value="<?php echo $settings_array['late_fee_per_day']['setting_value'] ?? '50'; ?>" 
                                               step="0.01" min="0">
                                        <span class="input-group-text">บาท</span>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="payment_due_days" class="form-label">วันครบกำหนดชำระ</label>
                                    <div class="input-group">
                                        <input type="number" class="form-control" id="payment_due_days" name="payment_due_days" 
                                               value="<?php echo $settings_array['payment_due_days']['setting_value'] ?? '7'; ?>" 
                                               min="1" max="30">
                                        <span class="input-group-text">วัน</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <!-- การแจ้งเตือนและสำรองข้อมูล -->
            <div class="col-md-6">
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="card-title mb-0">
                            <i class="bi bi-bell me-2"></i>การแจ้งเตือนและสำรองข้อมูล
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-check form-switch mb-3">
                                    <input class="form-check-input" type="checkbox" id="auto_backup" name="auto_backup" 
                                           <?php echo ($settings_array['auto_backup']['setting_value'] ?? '1') == '1' ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="auto_backup">
                                        สำรองข้อมูลอัตโนมัติ
                                    </label>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-check form-switch mb-3">
                                    <input class="form-check-input" type="checkbox" id="notification_email" name="notification_email" 
                                           <?php echo ($settings_array['notification_email']['setting_value'] ?? '1') == '1' ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="notification_email">
                                        แจ้งเตือนทางอีเมล
                                    </label>
                                </div>
                            </div>
                        </div>
                        
                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" id="system_maintenance" name="system_maintenance" 
                                   <?php echo ($settings_array['system_maintenance']['setting_value'] ?? '0') == '1' ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="system_maintenance">
                                <span class="text-warning">โหมดปิดปรุงระบบ</span>
                                <small class="d-block text-muted">เมื่อเปิดใช้ ผู้ใช้ทั่วไปจะไม่สามารถเข้าใช้ระบบได้</small>
                            </label>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ความปลอดภัย -->
            <div class="col-md-6">
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="card-title mb-0">
                            <i class="bi bi-shield-check me-2"></i>ความปลอดภัย
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="max_login_attempts" class="form-label">จำนวนครั้งล็อกอินผิดสูงสุด</label>
                                    <input type="number" class="form-control" id="max_login_attempts" name="max_login_attempts" 
                                           value="<?php echo $settings_array['max_login_attempts']['setting_value'] ?? '5'; ?>" 
                                           min="3" max="10">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="session_timeout" class="form-label">หมดอายุ Session (นาที)</label>
                                    <input type="number" class="form-control" id="session_timeout" name="session_timeout" 
                                           value="<?php echo $settings_array['session_timeout']['setting_value'] ?? '30'; ?>" 
                                           min="15" max="120">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ข้อมูลการอัพเดทล่าสุด -->
        <div class="card mb-4">
            <div class="card-header">
                <h6 class="card-title mb-0">
                    <i class="bi bi-info-circle me-2"></i>ข้อมูลการอัพเดทล่าสุด
                </h6>
            </div>
            <div class="card-body">
                <div class="row">
                    <?php 
                    $latest_update = null;
                    $updated_by = '';
                    foreach ($settings_array as $setting) {
                        if ($setting['updated_at'] && (!$latest_update || $setting['updated_at'] > $latest_update)) {
                            $latest_update = $setting['updated_at'];
                            $updated_by = $setting['updated_by_name'] ?? 'ไม่ทราบ';
                        }
                    }
                    ?>
                    <div class="col-md-6">
                        <strong>อัพเดทล่าสุด:</strong> 
                        <?php echo $latest_update ? date('d/m/Y H:i:s', strtotime($latest_update)) : 'ยังไม่เคยอัพเดท'; ?>
                    </div>
                    <div class="col-md-6">
                        <strong>อัพเดทโดย:</strong> <?php echo htmlspecialchars($updated_by); ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- ปุ่มบันทึก -->
        <div class="d-flex justify-content-between mb-4">
            <a href="index.php" class="btn btn-secondary">
                <i class="bi bi-arrow-left me-2"></i>กลับหน้าแรก
            </a>
            <button type="submit" name="save_settings" class="btn btn-primary btn-lg">
                <i class="bi bi-check-lg me-2"></i>บันทึกการตั้งค่า
            </button>
        </div>
    </form>
</div>

<!-- Modal ยืนยันการรีเซ็ต -->
<div class="modal fade" id="resetModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-exclamation-triangle text-warning me-2"></i>ยืนยันการรีเซ็ต
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>คุณต้องการรีเซ็ตการตั้งค่าทั้งหมดเป็นค่าเริ่มต้นหรือไม่?</p>
                <p class="text-danger"><strong>การดำเนินการนี้ไม่สามารถยกเลิกได้</strong></p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ยกเลิก</button>
                <button type="button" class="btn btn-danger" onclick="confirmReset()">
                    <i class="bi bi-arrow-clockwise me-2"></i>รีเซ็ต
                </button>
            </div>
        </div>
    </div>
</div>

<script>
// ฟังก์ชันรีเซ็ตเป็นค่าเริ่มต้น
function resetToDefault() {
    new bootstrap.Modal(document.getElementById('resetModal')).show();
}

function confirmReset() {
    // สร้างฟอร์มซ่อนเพื่อส่งคำขอรีเซ็ต
    const form = document.createElement('form');
    form.method = 'POST';
    form.style.display = 'none';
    
    const input = document.createElement('input');
    input.type = 'hidden';
    input.name = 'reset_to_default';
    input.value = '1';
    
    form.appendChild(input);
    document.body.appendChild(form);
    form.submit();
}

// ฟังก์ชันส่งออกการตั้งค่า
function exportSettings() {
    const settings = {};
    const form = document.querySelector('form');
    const inputs = form.querySelectorAll('input, textarea, select');
    
    inputs.forEach(input => {
        if (input.name && input.name !== 'save_settings') {
            if (input.type === 'checkbox') {
                settings[input.name] = input.checked ? '1' : '0';
            } else {
                settings[input.name] = input.value;
            }
        }
    });
    
    const dataStr = JSON.stringify(settings, null, 2);
    const dataBlob = new Blob([dataStr], {type: 'application/json'});
    const url = URL.createObjectURL(dataBlob);
    
    const link = document.createElement('a');
    link.href = url;
    link.download = 'system_settings_' + new Date().toISOString().slice(0,10) + '.json';
    link.click();
    
    URL.revokeObjectURL(url);
}

// ตรวจสอบการเปลี่ยนแปลง
let initialFormData = new FormData(document.querySelector('form'));
let hasChanges = false;

document.querySelector('form').addEventListener('input', function() {
    hasChanges = true;
});

window.addEventListener('beforeunload', function(e) {
    if (hasChanges) {
        e.preventDefault();
        e.returnValue = '';
    }
});

// ตรวจสอบฟอร์ม
document.querySelector('form').addEventListener('submit', function(e) {
    const waterPrice = parseFloat(document.getElementById('water_unit_price').value);
    const electricPrice = parseFloat(document.getElementById('electric_unit_price').value);
    const lateFee = parseFloat(document.getElementById('late_fee_per_day').value);
    
    if (waterPrice < 0 || electricPrice < 0 || lateFee < 0) {
        e.preventDefault();
        alert('ราคาและค่าปรับต้องไม่ติดลบ');
        return;
    }
    
    if (waterPrice > 1000 || electricPrice > 1000) {
        if (!confirm('ราคาน้ำหรือไฟสูงมาก คุณแน่ใจหรือไม่?')) {
            e.preventDefault();
            return;
        }
    }
    
    hasChanges = false;
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

// ตรวจสอบโหมดปิดปรุงระบบ
document.getElementById('system_maintenance').addEventListener('change', function() {
    if (this.checked) {
        if (!confirm('การเปิดโหมดปิดปรุงระบบจะทำให้ผู้ใช้ทั่วไปไม่สามารถเข้าใช้ระบบได้\nคุณแน่ใจหรือไม่?')) {
            this.checked = false;
        }
    }
});
</script>

<?php 
// จัดการการรีเซ็ตเป็นค่าเริ่มต้น
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['reset_to_default'])) {
    try {
        $pdo->exec("DROP TABLE IF EXISTS system_settings");
        echo "<script>window.location.reload();</script>";
    } catch(PDOException $e) {
        echo "<script>alert('เกิดข้อผิดพลาดในการรีเซ็ต');</script>";
    }
}

include 'includes/footer.php'; 
?>