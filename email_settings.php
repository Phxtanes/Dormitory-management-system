<?php
// email_settings.php - ตั้งค่าระบบอีเมล
$page_title = "ตั้งค่าระบบอีเมล";
require_once 'includes/header.php';
require_once 'includes/email_functions.php';

// ตรวจสอบสิทธิ์ Admin
require_role('admin');

// ตรวจสอบการบันทึกการตั้งค่า
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['save_settings'])) {
    try {
        $pdo->beginTransaction();
        
        $settings = [
            'smtp_host' => trim($_POST['smtp_host']),
            'smtp_port' => intval($_POST['smtp_port']),
            'smtp_username' => trim($_POST['smtp_username']),
            'smtp_password' => trim($_POST['smtp_password']),
            'smtp_encryption' => $_POST['smtp_encryption'],
            'email_from_name' => trim($_POST['email_from_name']),
            'email_from_address' => trim($_POST['email_from_address']),
            'auto_payment_reminder' => isset($_POST['auto_payment_reminder']) ? '1' : '0',
            'auto_overdue_reminder' => isset($_POST['auto_overdue_reminder']) ? '1' : '0',
            'auto_contract_expiry' => isset($_POST['auto_contract_expiry']) ? '1' : '0',
            'payment_reminder_days' => intval($_POST['payment_reminder_days']),
            'contract_expiry_days' => intval($_POST['contract_expiry_days']),
            'email_template_header' => trim($_POST['email_template_header']),
            'email_template_footer' => trim($_POST['email_template_footer'])
        ];
        
        foreach ($settings as $key => $value) {
            $stmt = $pdo->prepare("
                INSERT INTO system_settings (setting_key, setting_value, setting_description, updated_by) 
                VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE 
                setting_value = VALUES(setting_value), 
                updated_by = VALUES(updated_by),
                updated_at = CURRENT_TIMESTAMP
            ");
            
            $descriptions = [
                'smtp_host' => 'SMTP Server Host',
                'smtp_port' => 'SMTP Server Port',
                'smtp_username' => 'SMTP Username',
                'smtp_password' => 'SMTP Password',
                'smtp_encryption' => 'SMTP Encryption Type',
                'email_from_name' => 'ชื่อผู้ส่งอีเมล',
                'email_from_address' => 'อีเมลผู้ส่ง',
                'auto_payment_reminder' => 'แจ้งเตือนชำระเงินอัตโนมัติ',
                'auto_overdue_reminder' => 'แจ้งเตือนเงินค้างชำระอัตโนมัติ',
                'auto_contract_expiry' => 'แจ้งเตือนสัญญาหมดอายุอัตโนมัติ',
                'payment_reminder_days' => 'จำนวนวันก่อนครบกำหนดชำระ',
                'contract_expiry_days' => 'จำนวนวันก่อนสัญญาหมดอายุ',
                'email_template_header' => 'Header template อีเมล',
                'email_template_footer' => 'Footer template อีเมล'
            ];
            
            $stmt->execute([$key, $value, $descriptions[$key] ?? '', $_SESSION['user_id']]);
        }
        
        $pdo->commit();
        $success_message = "บันทึกการตั้งค่าเรียบร้อยแล้ว";
        
    } catch(PDOException $e) {
        $pdo->rollBack();
        $error_message = "เกิดข้อผิดพลาด: " . $e->getMessage();
    }
}

// ทดสอบส่งอีเมล
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['test_email'])) {
    $test_email = trim($_POST['test_email_address']);
    
    if (!empty($test_email) && filter_var($test_email, FILTER_VALIDATE_EMAIL)) {
        $test_result = sendNotificationEmail(
            $test_email,
            'ผู้ทดสอบ',
            'TEST',
            'ทดสอบการส่งอีเมล',
            'นี่คือข้อความทดสอบการส่งอีเมลจากระบบจัดการหอพัก\n\nหากคุณได้รับอีเมลนี้ แสดงว่าการตั้งค่าถูกต้อง',
            'general'
        );
        
        if ($test_result) {
            $test_message = "ส่งอีเมลทดสอบสำเร็จ กรุณาตรวจสอบกล่องจดหมาย";
            $test_status = "success";
        } else {
            $test_message = "ส่งอีเมลทดสอบไม่สำเร็จ กรุณาตรวจสอบการตั้งค่า";
            $test_status = "danger";
        }
    } else {
        $test_message = "กรุณาระบุอีเมลที่ถูกต้อง";
        $test_status = "warning";
    }
}

// ดึงการตั้งค่าปัจจุบัน
try {
    $current_settings = getSystemSettings();
} catch(PDOException $e) {
    $current_settings = [];
}
?>

<?php include 'includes/navbar.php'; ?>

<div class="container-fluid mt-4">
    <div class="row">
        <div class="col-12">
            <!-- หัวข้อหน้า -->
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2 class="mb-0">
                    <i class="bi bi-gear"></i>
                    ตั้งค่าระบบอีเมล
                </h2>
                <div class="btn-group">
                    <a href="send_notifications.php" class="btn btn-outline-primary">
                        <i class="bi bi-envelope"></i>
                        ส่งการแจ้งเตือน
                    </a>
                    <a href="notification_history.php" class="btn btn-outline-secondary">
                        <i class="bi bi-clock-history"></i>
                        ประวัติการแจ้งเตือน
                    </a>
                </div>
            </div>

            <!-- แสดงข้อความแจ้งเตือน -->
            <?php if (isset($success_message)): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="bi bi-check-circle"></i>
                    <?php echo $success_message; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php if (isset($error_message)): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="bi bi-exclamation-triangle"></i>
                    <?php echo $error_message; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php if (isset($test_message)): ?>
                <div class="alert alert-<?php echo $test_status; ?> alert-dismissible fade show" role="alert">
                    <i class="bi bi-envelope"></i>
                    <?php echo $test_message; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <div class="row">
                <!-- การตั้งค่า SMTP -->
                <div class="col-lg-8 mb-4">
                    <form method="POST">
                        <input type="hidden" name="save_settings" value="1">
                        
                        <div class="card mb-4">
                            <div class="card-header">
                                <h5 class="mb-0">
                                    <i class="bi bi-server"></i>
                                    การตั้งค่า SMTP Server
                                </h5>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-8 mb-3">
                                        <label for="smtp_host" class="form-label">SMTP Host <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" id="smtp_host" name="smtp_host" 
                                               value="<?php echo htmlspecialchars($current_settings['smtp_host'] ?? ''); ?>" 
                                               placeholder="smtp.gmail.com" required>
                                        <div class="form-text">เช่น smtp.gmail.com, smtp.outlook.com</div>
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <label for="smtp_port" class="form-label">Port <span class="text-danger">*</span></label>
                                        <input type="number" class="form-control" id="smtp_port" name="smtp_port" 
                                               value="<?php echo htmlspecialchars($current_settings['smtp_port'] ?? '587'); ?>" 
                                               min="1" max="65535" required>
                                        <div class="form-text">587 (TLS) หรือ 465 (SSL)
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label for="smtp_username" class="form-label">Username/Email <span class="text-danger">*</span></label>
                                        <input type="email" class="form-control" id="smtp_username" name="smtp_username" 
                                               value="<?php echo htmlspecialchars($current_settings['smtp_username'] ?? ''); ?>" 
                                               placeholder="your-email@gmail.com" required>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label for="smtp_password" class="form-label">Password/App Password <span class="text-danger">*</span></label>
                                        <div class="input-group">
                                            <input type="password" class="form-control" id="smtp_password" name="smtp_password" 
                                                   value="<?php echo htmlspecialchars($current_settings['smtp_password'] ?? ''); ?>" 
                                                   placeholder="รหัสผ่านแอป" required>
                                            <button type="button" class="btn btn-outline-secondary" onclick="togglePassword()">
                                                <i class="bi bi-eye" id="passwordIcon"></i>
                                            </button>
                                        </div>
                                        <div class="form-text">สำหรับ Gmail ใช้ App Password</div>
                                    </div>
                                </div>
                                
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label for="smtp_encryption" class="form-label">การเข้ารหัส</label>
                                        <select class="form-select" id="smtp_encryption" name="smtp_encryption">
                                            <option value="tls" <?php echo ($current_settings['smtp_encryption'] ?? 'tls') == 'tls' ? 'selected' : ''; ?>>TLS</option>
                                            <option value="ssl" <?php echo ($current_settings['smtp_encryption'] ?? '') == 'ssl' ? 'selected' : ''; ?>>SSL</option>
                                            <option value="none" <?php echo ($current_settings['smtp_encryption'] ?? '') == 'none' ? 'selected' : ''; ?>>ไม่มี</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- การตั้งค่าผู้ส่ง -->
                        <div class="card mb-4">
                            <div class="card-header">
                                <h5 class="mb-0">
                                    <i class="bi bi-person-badge"></i>
                                    ข้อมูลผู้ส่งอีเมล
                                </h5>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label for="email_from_name" class="form-label">ชื่อผู้ส่ง <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" id="email_from_name" name="email_from_name" 
                                               value="<?php echo htmlspecialchars($current_settings['email_from_name'] ?? $current_settings['dormitory_name'] ?? 'หอพัก'); ?>" 
                                               placeholder="หอพักตัวอย่าง" required>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label for="email_from_address" class="form-label">อีเมลผู้ส่ง <span class="text-danger">*</span></label>
                                        <input type="email" class="form-control" id="email_from_address" name="email_from_address" 
                                               value="<?php echo htmlspecialchars($current_settings['email_from_address'] ?? $current_settings['dormitory_email'] ?? ''); ?>" 
                                               placeholder="noreply@dormitory.local" required>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- การตั้งค่าการแจ้งเตือนอัตโนมัติ -->
                        <div class="card mb-4">
                            <div class="card-header">
                                <h5 class="mb-0">
                                    <i class="bi bi-robot"></i>
                                    การแจ้งเตือนอัตโนมัติ
                                </h5>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-12 mb-3">
                                        <div class="form-check form-switch">
                                            <input class="form-check-input" type="checkbox" id="auto_payment_reminder" 
                                                   name="auto_payment_reminder" <?php echo ($current_settings['auto_payment_reminder'] ?? '0') == '1' ? 'checked' : ''; ?>>
                                            <label class="form-check-label" for="auto_payment_reminder">
                                                <strong>แจ้งเตือนชำระเงินอัตโนมัติ</strong>
                                                <div class="text-muted">ส่งการแจ้งเตือนก่อนครบกำหนดชำระ</div>
                                            </label>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label for="payment_reminder_days" class="form-label">แจ้งเตือนก่อนครบกำหนด (วัน)</label>
                                        <input type="number" class="form-control" id="payment_reminder_days" name="payment_reminder_days" 
                                               value="<?php echo htmlspecialchars($current_settings['payment_reminder_days'] ?? '3'); ?>" 
                                               min="1" max="30">
                                    </div>
                                </div>
                                
                                <div class="row">
                                    <div class="col-12 mb-3">
                                        <div class="form-check form-switch">
                                            <input class="form-check-input" type="checkbox" id="auto_overdue_reminder" 
                                                   name="auto_overdue_reminder" <?php echo ($current_settings['auto_overdue_reminder'] ?? '0') == '1' ? 'checked' : ''; ?>>
                                            <label class="form-check-label" for="auto_overdue_reminder">
                                                <strong>แจ้งเตือนเงินค้างชำระอัตโนมัติ</strong>
                                                <div class="text-muted">ส่งการแจ้งเตือนเมื่อเกินกำหนดชำระ</div>
                                            </label>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="row">
                                    <div class="col-12 mb-3">
                                        <div class="form-check form-switch">
                                            <input class="form-check-input" type="checkbox" id="auto_contract_expiry" 
                                                   name="auto_contract_expiry" <?php echo ($current_settings['auto_contract_expiry'] ?? '0') == '1' ? 'checked' : ''; ?>>
                                            <label class="form-check-label" for="auto_contract_expiry">
                                                <strong>แจ้งเตือนสัญญาหมดอายุอัตโนมัติ</strong>
                                                <div class="text-muted">ส่งการแจ้งเตือนก่อนสัญญาหมดอายุ</div>
                                            </label>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label for="contract_expiry_days" class="form-label">แจ้งเตือนก่อนหมดอายุ (วัน)</label>
                                        <input type="number" class="form-control" id="contract_expiry_days" name="contract_expiry_days" 
                                               value="<?php echo htmlspecialchars($current_settings['contract_expiry_days'] ?? '30'); ?>" 
                                               min="1" max="365">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Template อีเมล -->
                        <div class="card mb-4">
                            <div class="card-header">
                                <h5 class="mb-0">
                                    <i class="bi bi-file-text"></i>
                                    Template อีเมล (ไม่บังคับ)
                                </h5>
                            </div>
                            <div class="card-body">
                                <div class="mb-3">
                                    <label for="email_template_header" class="form-label">Header Template</label>
                                    <textarea class="form-control" id="email_template_header" name="email_template_header" 
                                              rows="3" placeholder="ข้อความที่จะแสดงที่ด้านบนของอีเมลทุกฉบับ"><?php echo htmlspecialchars($current_settings['email_template_header'] ?? ''); ?></textarea>
                                    <div class="form-text">ตัวแปรที่ใช้ได้: {dormitory_name}, {tenant_name}, {room_number}</div>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="email_template_footer" class="form-label">Footer Template</label>
                                    <textarea class="form-control" id="email_template_footer" name="email_template_footer" 
                                              rows="3" placeholder="ข้อความที่จะแสดงที่ด้านล่างของอีเมลทุกฉบับ"><?php echo htmlspecialchars($current_settings['email_template_footer'] ?? ''); ?></textarea>
                                    <div class="form-text">ตัวแปรที่ใช้ได้: {dormitory_name}, {dormitory_phone}, {dormitory_address}</div>
                                </div>
                            </div>
                        </div>

                        <!-- ปุ่มบันทึก -->
                        <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                            <button type="submit" class="btn btn-primary btn-lg">
                                <i class="bi bi-save"></i>
                                บันทึกการตั้งค่า
                            </button>
                        </div>
                    </form>
                </div>

                <!-- การทดสอบและข้อมูลเพิ่มเติม -->
                <div class="col-lg-4 mb-4">
                    <!-- ทดสอบการส่งอีเมล -->
                    <div class="card mb-4">
                        <div class="card-header">
                            <h5 class="mb-0">
                                <i class="bi bi-send-check"></i>
                                ทดสอบการส่งอีเมล
                            </h5>
                        </div>
                        <div class="card-body">
                            <form method="POST">
                                <input type="hidden" name="test_email" value="1">
                                <div class="mb-3">
                                    <label for="test_email_address" class="form-label">อีเมลทดสอบ</label>
                                    <input type="email" class="form-control" id="test_email_address" name="test_email_address" 
                                           placeholder="test@example.com" required>
                                </div>
                                <div class="d-grid">
                                    <button type="submit" class="btn btn-success">
                                        <i class="bi bi-send"></i>
                                        ส่งอีเมลทดสอบ
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- คำแนะนำการตั้งค่า -->
                    <div class="card mb-4">
                        <div class="card-header">
                            <h6 class="mb-0">
                                <i class="bi bi-info-circle"></i>
                                คำแนะนำการตั้งค่า
                            </h6>
                        </div>
                        <div class="card-body">
                            <div class="accordion accordion-flush" id="settingsAccordion">
                                <div class="accordion-item">
                                    <h2 class="accordion-header">
                                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#gmail-settings">
                                            Gmail
                                        </button>
                                    </h2>
                                    <div id="gmail-settings" class="accordion-collapse collapse" data-bs-parent="#settingsAccordion">
                                        <div class="accordion-body">
                                            <strong>Host:</strong> smtp.gmail.com<br>
                                            <strong>Port:</strong> 587<br>
                                            <strong>การเข้ารหัส:</strong> TLS<br>
                                            <strong>หมายเหตุ:</strong> ต้องสร้าง App Password ใน Google Account
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="accordion-item">
                                    <h2 class="accordion-header">
                                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#outlook-settings">
                                            Outlook/Hotmail
                                        </button>
                                    </h2>
                                    <div id="outlook-settings" class="accordion-collapse collapse" data-bs-parent="#settingsAccordion">
                                        <div class="accordion-body">
                                            <strong>Host:</strong> smtp-mail.outlook.com<br>
                                            <strong>Port:</strong> 587<br>
                                            <strong>การเข้ารหัส:</strong> TLS<br>
                                            <strong>หมายเหตุ:</strong> ใช้อีเมลและรหัสผ่านปกติ
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="accordion-item">
                                    <h2 class="accordion-header">
                                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#yahoo-settings">
                                            Yahoo Mail
                                        </button>
                                    </h2>
                                    <div id="yahoo-settings" class="accordion-collapse collapse" data-bs-parent="#settingsAccordion">
                                        <div class="accordion-body">
                                            <strong>Host:</strong> smtp.mail.yahoo.com<br>
                                            <strong>Port:</strong> 587 หรือ 465<br>
                                            <strong>การเข้ารหัส:</strong> TLS/SSL<br>
                                            <strong>หมายเหตุ:</strong> ต้องเปิดใช้งาน Less secure app access
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- สถิติการใช้งาน -->
                    <div class="card">
                        <div class="card-header">
                            <h6 class="mb-0">
                                <i class="bi bi-graph-up"></i>
                                สถิติการใช้งาน
                            </h6>
                        </div>
                        <div class="card-body">
                            <?php
                            try {
                                // สถิติการส่งอีเมลเดือนนี้
                                $stmt = $pdo->query("
                                    SELECT COUNT(*) as email_count 
                                    FROM notifications 
                                    WHERE send_method IN ('email', 'both') 
                                    AND sent_date IS NOT NULL 
                                    AND MONTH(sent_date) = MONTH(CURDATE())
                                    AND YEAR(sent_date) = YEAR(CURDATE())
                                ");
                                $monthly_emails = $stmt->fetch()['email_count'];
                                
                                // สถิติการส่งอีเมลวันนี้
                                $stmt = $pdo->query("
                                    SELECT COUNT(*) as email_count 
                                    FROM notifications 
                                    WHERE send_method IN ('email', 'both') 
                                    AND sent_date = CURDATE()
                                ");
                                $daily_emails = $stmt->fetch()['email_count'];
                                
                                // อีเมลที่ล้มเหลว
                                $stmt = $pdo->query("
                                    SELECT COUNT(*) as failed_count 
                                    FROM notifications 
                                    WHERE send_method IN ('email', 'both') 
                                    AND sent_date IS NULL 
                                    AND created_at < DATE_SUB(NOW(), INTERVAL 1 HOUR)
                                ");
                                $failed_emails = $stmt->fetch()['failed_count'];
                                
                            } catch(PDOException $e) {
                                $monthly_emails = 0;
                                $daily_emails = 0;
                                $failed_emails = 0;
                            }
                            ?>
                            
                            <div class="row text-center">
                                <div class="col-4">
                                    <div class="border-end">
                                        <h5 class="text-primary"><?php echo $daily_emails; ?></h5>
                                        <small class="text-muted">วันนี้</small>
                                    </div>
                                </div>
                                <div class="col-4">
                                    <div class="border-end">
                                        <h5 class="text-success"><?php echo $monthly_emails; ?></h5>
                                        <small class="text-muted">เดือนนี้</small>
                                    </div>
                                </div>
                                <div class="col-4">
                                    <h5 class="text-danger"><?php echo $failed_emails; ?></h5>
                                    <small class="text-muted">ล้มเหลว</small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function togglePassword() {
    const passwordField = document.getElementById('smtp_password');
    const passwordIcon = document.getElementById('passwordIcon');
    
    if (passwordField.type === 'password') {
        passwordField.type = 'text';
        passwordIcon.classList.remove('bi-eye');
        passwordIcon.classList.add('bi-eye-slash');
    } else {
        passwordField.type = 'password';
        passwordIcon.classList.remove('bi-eye-slash');
        passwordIcon.classList.add('bi-eye');
    }
}

// Auto-fill email address from username
document.getElementById('smtp_username').addEventListener('blur', function() {
    const fromEmailField = document.getElementById('email_from_address');
    if (!fromEmailField.value && this.value) {
        fromEmailField.value = this.value;
    }
});
</script>

<?php require_once 'includes/footer.php'; ?>