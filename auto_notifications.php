<?php
// auto_notifications.php - ระบบจัดการการแจ้งเตือนอัตโนมัติ
$page_title = "การแจ้งเตือนอัตโนมัติ";
require_once 'includes/header.php';
require_once 'includes/email_functions.php';

// ตรวจสอบสิทธิ์ Admin
require_role('admin');

// ตรวจสอบการเรียกใช้ manual run
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['run_auto_notifications'])) {
    $results = sendAutomaticNotifications();
    
    $total_sent = $results['payment_reminders'] + $results['overdue_reminders'] + $results['contract_expiry'];
    
    if ($total_sent > 0) {
        $success_message = "ส่งการแจ้งเตือนอัตโนมัติเรียบร้อยแล้ว<br>";
        $success_message .= "- แจ้งเตือนชำระเงิน: {$results['payment_reminders']} ราย<br>";
        $success_message .= "- แจ้งเตือนเงินค้างชำระ: {$results['overdue_reminders']} ราย<br>";
        $success_message .= "- แจ้งเตือนสัญญาหมดอายุ: {$results['contract_expiry']} ราย";
    } else {
        $info_message = "ไม่มีการแจ้งเตือนที่ต้องส่งในขณะนี้";
    }
    
    if (!empty($results['errors'])) {
        $error_message = "พบข้อผิดพลาด: " . implode(', ', $results['errors']);
    }
}

// ดึงข้อมูลการตั้งค่า
$settings = getSystemSettings();

// ดึงข้อมูลรายการที่ต้องแจ้งเตือน
try {
    // รายการที่ต้องแจ้งเตือนชำระเงิน
    $payment_reminders_sql = "
        SELECT i.*, t.first_name, t.last_name, t.email, r.room_number,
               DATEDIFF(i.due_date, CURDATE()) as days_until_due
        FROM invoices i
        JOIN contracts c ON i.contract_id = c.contract_id
        JOIN tenants t ON c.tenant_id = t.tenant_id
        JOIN rooms r ON c.room_id = r.room_id
        WHERE i.invoice_status = 'pending'
        AND DATEDIFF(i.due_date, CURDATE()) <= ?
        AND DATEDIFF(i.due_date, CURDATE()) >= 0
        AND t.email IS NOT NULL AND t.email != ''
        ORDER BY i.due_date ASC
    ";
    
    $reminder_days = intval($settings['payment_reminder_days'] ?? 3);
    $stmt = $pdo->prepare($payment_reminders_sql);
    $stmt->execute([$reminder_days]);
    $payment_reminders = $stmt->fetchAll();
    
    // รายการที่ค้างชำระ
    $overdue_sql = "
        SELECT i.*, t.first_name, t.last_name, t.email, r.room_number,
               DATEDIFF(CURDATE(), i.due_date) as days_overdue
        FROM invoices i
        JOIN contracts c ON i.contract_id = c.contract_id
        JOIN tenants t ON c.tenant_id = t.tenant_id
        JOIN rooms r ON c.room_id = r.room_id
        WHERE i.invoice_status = 'pending'
        AND i.due_date < CURDATE()
        AND t.email IS NOT NULL AND t.email != ''
        ORDER BY i.due_date ASC
    ";
    
    $stmt = $pdo->prepare($overdue_sql);
    $stmt->execute();
    $overdue_payments = $stmt->fetchAll();
    
    // รายการสัญญาที่ใกล้หมดอายุ
    $contract_expiry_sql = "
        SELECT c.*, t.first_name, t.last_name, t.email, r.room_number,
               DATEDIFF(c.contract_end, CURDATE()) as days_until_expiry
        FROM contracts c
        JOIN tenants t ON c.tenant_id = t.tenant_id
        JOIN rooms r ON c.room_id = r.room_id
        WHERE c.contract_status = 'active'
        AND DATEDIFF(c.contract_end, CURDATE()) <= ?
        AND DATEDIFF(c.contract_end, CURDATE()) >= 0
        AND t.email IS NOT NULL AND t.email != ''
        ORDER BY c.contract_end ASC
    ";
    
    $expiry_days = intval($settings['contract_expiry_days'] ?? 30);
    $stmt = $pdo->prepare($contract_expiry_sql);
    $stmt->execute([$expiry_days]);
    $contract_expiries = $stmt->fetchAll();
    
} catch(PDOException $e) {
    $error_message = "เกิดข้อผิดพลาดในการดึงข้อมูล: " . $e->getMessage();
    $payment_reminders = [];
    $overdue_payments = [];
    $contract_expiries = [];
}

// ดึงประวัติการทำงานอัตโนมัติ
try {
    $auto_history_sql = "
        SELECT DATE(created_at) as run_date,
               COUNT(*) as notification_count,
               GROUP_CONCAT(DISTINCT notification_type) as types_sent
        FROM notifications
        WHERE send_method IN ('email', 'both')
        AND sent_date IS NOT NULL
        AND created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
        GROUP BY DATE(created_at)
        ORDER BY run_date DESC
        LIMIT 10
    ";
    
    $stmt = $pdo->query($auto_history_sql);
    $auto_history = $stmt->fetchAll();
    
} catch(PDOException $e) {
    $auto_history = [];
}
?>

<?php include 'includes/navbar.php'; ?>

<div class="container-fluid mt-4">
    <div class="row">
        <div class="col-12">
            <!-- หัวข้อหน้า -->
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2 class="mb-0">
                    <i class="bi bi-robot"></i>
                    การแจ้งเตือนอัตโนมัติ
                </h2>
                <div class="btn-group">
                    <a href="email_settings.php" class="btn btn-outline-primary">
                        <i class="bi bi-gear"></i>
                        ตั้งค่าอีเมล
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

            <?php if (isset($info_message)): ?>
                <div class="alert alert-info alert-dismissible fade show" role="alert">
                    <i class="bi bi-info-circle"></i>
                    <?php echo $info_message; ?>
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

            <div class="row">
                <!-- สถานะการตั้งค่า -->
                <div class="col-lg-4 mb-4">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">
                                <i class="bi bi-toggles"></i>
                                สถานะการตั้งค่า
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="list-group list-group-flush">
                                <div class="list-group-item d-flex justify-content-between align-items-center px-0">
                                    <div>
                                        <strong>แจ้งเตือนชำระเงิน</strong>
                                        <br><small class="text-muted">ก่อนครบกำหนด <?php echo $settings['payment_reminder_days'] ?? 3; ?> วัน</small>
                                    </div>
                                    <span class="badge bg-<?php echo ($settings['auto_payment_reminder'] ?? '0') == '1' ? 'success' : 'secondary'; ?>">
                                        <?php echo ($settings['auto_payment_reminder'] ?? '0') == '1' ? 'เปิด' : 'ปิด'; ?>
                                    </span>
                                </div>
                                
                                <div class="list-group-item d-flex justify-content-between align-items-center px-0">
                                    <div>
                                        <strong>แจ้งเตือนเงินค้างชำระ</strong>
                                        <br><small class="text-muted">เมื่อเกินกำหนดชำระ</small>
                                    </div>
                                    <span class="badge bg-<?php echo ($settings['auto_overdue_reminder'] ?? '0') == '1' ? 'success' : 'secondary'; ?>">
                                        <?php echo ($settings['auto_overdue_reminder'] ?? '0') == '1' ? 'เปิด' : 'ปิด'; ?>
                                    </span>
                                </div>
                                
                                <div class="list-group-item d-flex justify-content-between align-items-center px-0">
                                    <div>
                                        <strong>แจ้งเตือนสัญญาหมดอายุ</strong>
                                        <br><small class="text-muted">ก่อนหมดอายุ <?php echo $settings['contract_expiry_days'] ?? 30; ?> วัน</small>
                                    </div>
                                    <span class="badge bg-<?php echo ($settings['auto_contract_expiry'] ?? '0') == '1' ? 'success' : 'secondary'; ?>">
                                        <?php echo ($settings['auto_contract_expiry'] ?? '0') == '1' ? 'เปิด' : 'ปิด'; ?>
                                    </span>
                                </div>
                            </div>
                            
                            <div class="mt-3 d-grid">
                                <form method="POST" style="display: inline;">
                                    <input type="hidden" name="run_auto_notifications" value="1">
                                    <button type="submit" class="btn btn-primary" onclick="return confirm('ต้องการเรียกใช้การแจ้งเตือนอัตโนมัติตอนนี้หรือไม่?')">
                                        <i class="bi bi-play-circle"></i>
                                        เรียกใช้ตอนนี้
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>

                    <!-- Cron Job Setup -->
                    <div class="card mt-4">
                        <div class="card-header">
                            <h6 class="mb-0">
                                <i class="bi bi-clock"></i>
                                ตั้งค่า Cron Job
                            </h6>
                        </div>
                        <div class="card-body">
                            <p class="text-muted mb-3">เพิ่มคำสั่งนี้ใน crontab เพื่อใช้งานอัตโนมัติ:</p>
                            
                            <div class="mb-3">
                                <label class="form-label">รายวัน (เวลา 09:00)</label>
                                <div class="input-group">
                                    <input type="text" class="form-control font-monospace" 
                                           value="0 9 * * * /usr/bin/php <?php echo realpath(__DIR__); ?>/cron_notifications.php" 
                                           readonly>
                                    <button type="button" class="btn btn-outline-secondary" onclick="copyToClipboard(this.previousElementSibling)">
                                        <i class="bi bi-copy"></i>
                                    </button>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">ทุก 6 ชั่วโมง</label>
                                <div class="input-group">
                                    <input type="text" class="form-control font-monospace" 
                                           value="0 */6 * * * /usr/bin/php <?php echo realpath(__DIR__); ?>/cron_notifications.php" 
                                           readonly>
                                    <button type="button" class="btn btn-outline-secondary" onclick="copyToClipboard(this.previousElementSibling)">
                                        <i class="bi bi-copy"></i>
                                    </button>
                                </div>
                            </div>
                            
                            <small class="text-muted">
                                <i class="bi bi-info-circle"></i>
                                สำหรับผู้ใช้ shared hosting ให้ตรวจสอบ path ของ PHP ที่ถูกต้อง
                            </small>
                        </div>
                    </div>
                </div>

                <!-- รายการที่ต้องแจ้งเตือน -->
                <div class="col-lg-8 mb-4">
                    <!-- แจ้งเตือนชำระเงิน -->
                    <div class="card mb-4">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5 class="mb-0">
                                <i class="bi bi-currency-dollar text-warning"></i>
                                รอแจ้งเตือนชำระเงิน
                            </h5>
                            <span class="badge bg-warning"><?php echo count($payment_reminders); ?> รายการ</span>
                        </div>
                        <div class="card-body">
                            <?php if (empty($payment_reminders)): ?>
                                <div class="text-center text-muted py-3">
                                    <i class="bi bi-check-circle fs-1"></i>
                                    <p class="mt-2">ไม่มีรายการที่ต้องแจ้งเตือน</p>
                                </div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-sm">
                                        <thead>
                                            <tr>
                                                <th>ห้อง</th>
                                                <th>ผู้เช่า</th>
                                                <th>ยอดเงิน</th>
                                                <th>ครบกำหนด</th>
                                                <th>เหลือ (วัน)</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($payment_reminders as $reminder): ?>
                                                <tr>
                                                    <td><span class="badge bg-info"><?php echo $reminder['room_number']; ?></span></td>
                                                    <td><?php echo $reminder['first_name'] . ' ' . $reminder['last_name']; ?></td>
                                                    <td><?php echo formatCurrency($reminder['total_amount']); ?></td>
                                                    <td><?php echo formatDate($reminder['due_date']); ?></td>
                                                    <td>
                                                        <span class="badge bg-<?php echo $reminder['days_until_due'] <= 1 ? 'danger' : 'warning'; ?>">
                                                            <?php echo $reminder['days_until_due']; ?> วัน
                                                        </span>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- แจ้งเตือนเงินค้างชำระ -->
                    <div class="card mb-4">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5 class="mb-0">
                                <i class="bi bi-exclamation-triangle text-danger"></i>
                                เงินค้างชำระ
                            </h5>
                            <span class="badge bg-danger"><?php echo count($overdue_payments); ?> รายการ</span>
                        </div>
                        <div class="card-body">
                            <?php if (empty($overdue_payments)): ?>
                                <div class="text-center text-muted py-3">
                                    <i class="bi bi-check-circle fs-1"></i>
                                    <p class="mt-2">ไม่มีเงินค้างชำระ</p>
                                </div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-sm">
                                        <thead>
                                            <tr>
                                                <th>ห้อง</th>
                                                <th>ผู้เช่า</th>
                                                <th>ยอดเงิน</th>
                                                <th>ครบกำหนด</th>
                                                <th>เกิน (วัน)</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($overdue_payments as $overdue): ?>
                                                <tr>
                                                    <td><span class="badge bg-info"><?php echo $overdue['room_number']; ?></span></td>
                                                    <td><?php echo $overdue['first_name'] . ' ' . $overdue['last_name']; ?></td>
                                                    <td><?php echo formatCurrency($overdue['total_amount']); ?></td>
                                                    <td><?php echo formatDate($overdue['due_date']); ?></td>
                                                    <td>
                                                        <span class="badge bg-danger">
                                                            <?php echo $overdue['days_overdue']; ?> วัน
                                                        </span>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- แจ้งเตือนสัญญาหมดอายุ -->
                    <div class="card mb-4">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5 class="mb-0">
                                <i class="bi bi-calendar-x text-info"></i>
                                สัญญาใกล้หมดอายุ
                            </h5>
                            <span class="badge bg-info"><?php echo count($contract_expiries); ?> รายการ</span>
                        </div>
                        <div class="card-body">
                            <?php if (empty($contract_expiries)): ?>
                                <div class="text-center text-muted py-3">
                                    <i class="bi bi-check-circle fs-1"></i>
                                    <p class="mt-2">ไม่มีสัญญาที่ใกล้หมดอายุ</p>
                                </div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-sm">
                                        <thead>
                                            <tr>
                                                <th>ห้อง</th>
                                                <th>ผู้เช่า</th>
                                                <th>เริ่มสัญญา</th>
                                                <th>หมดอายุ</th>
                                                <th>เหลือ (วัน)</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($contract_expiries as $expiry): ?>
                                                <tr>
                                                    <td><span class="badge bg-info"><?php echo $expiry['room_number']; ?></span></td>
                                                    <td><?php echo $expiry['first_name'] . ' ' . $expiry['last_name']; ?></td>
                                                    <td><?php echo formatDate($expiry['contract_start']); ?></td>
                                                    <td><?php echo formatDate($expiry['contract_end']); ?></td>
                                                    <td>
                                                        <span class="badge bg-<?php echo $expiry['days_until_expiry'] <= 7 ? 'danger' : 'warning'; ?>">
                                                            <?php echo $expiry['days_until_expiry']; ?> วัน
                                                        </span>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- ประวัติการทำงานอัตโนมัติ -->
                    <div class="card">
                        <div class="card-header">
                            <h6 class="mb-0">
                                <i class="bi bi-clock-history"></i>
                                ประวัติการทำงานอัตโนมัติ (30 วันล่าสุด)
                            </h6>
                        </div>
                        <div class="card-body">
                            <?php if (empty($auto_history)): ?>
                                <div class="text-center text-muted py-3">
                                    <i class="bi bi-inbox fs-1"></i>
                                    <p class="mt-2">ยังไม่มีประวัติการทำงาน</p>
                                </div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-sm">
                                        <thead>
                                            <tr>
                                                <th>วันที่</th>
                                                <th>จำนวนการแจ้งเตือน</th>
                                                <th>ประเภท</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($auto_history as $history): ?>
                                                <tr>
                                                    <td><?php echo formatDate($history['run_date']); ?></td>
                                                    <td><span class="badge bg-success"><?php echo $history['notification_count']; ?></span></td>
                                                    <td>
                                                        <?php
                                                        $types = explode(',', $history['types_sent']);
                                                        $type_labels = [
                                                            'payment_due' => 'แจ้งชำระ',
                                                            'payment_overdue' => 'ค้างชำระ',
                                                            'contract_expiring' => 'สัญญาหมดอายุ'
                                                        ];
                                                        
                                                        foreach ($types as $type) {
                                                            echo '<span class="badge bg-secondary me-1">' . ($type_labels[trim($type)] ?? trim($type)) . '</span>';
                                                        }
                                                        ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function copyToClipboard(element) {
    element.select();
    element.setSelectionRange(0, 99999);
    navigator.clipboard.writeText(element.value).then(function() {
        // แสดง tooltip หรือ alert
        const btn = element.nextElementSibling;
        const originalText = btn.innerHTML;
        btn.innerHTML = '<i class="bi bi-check"></i>';
        btn.classList.remove('btn-outline-secondary');
        btn.classList.add('btn-success');
        
        setTimeout(function() {
            btn.innerHTML = originalText;
            btn.classList.remove('btn-success');
            btn.classList.add('btn-outline-secondary');
        }, 2000);
    });
}
</script>

<?php require_once 'includes/footer.php'; ?>

---

<!-- cron_notifications.php - ไฟล์สำหรับ Cron Job -->
<?php
// cron_notifications.php - ไฟล์สำหรับเรียกใช้จาก Cron Job
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/email_functions.php';

// ป้องกันการเรียกใช้จาก web browser
if (php_sapi_name() !== 'cli' && !isset($_GET['manual'])) {
    die('This script can only be run from command line.');
}

try {
    $log_file = __DIR__ . '/logs/cron_notifications.log';
    $log_dir = dirname($log_file);
    
    // สร้างโฟลเดอร์ logs หากยังไม่มี
    if (!file_exists($log_dir)) {
        mkdir($log_dir, 0755, true);
    }
    
    $start_time = date('Y-m-d H:i:s');
    $log_message = "[$start_time] Starting automatic notifications...\n";
    
    // เรียกใช้ฟังก์ชันส่งการแจ้งเตือนอัตโนมัติ
    $results = sendAutomaticNotifications();
    
    $total_sent = $results['payment_reminders'] + $results['overdue_reminders'] + $results['contract_expiry'];
    
    $log_message .= "[$start_time] Results:\n";
    $log_message .= "  - Payment reminders: {$results['payment_reminders']}\n";
    $log_message .= "  - Overdue reminders: {$results['overdue_reminders']}\n";
    $log_message .= "  - Contract expiry: {$results['contract_expiry']}\n";
    $log_message .= "  - Total sent: $total_sent\n";
    
    if (!empty($results['errors'])) {
        $log_message .= "  - Errors: " . implode(', ', $results['errors']) . "\n";
    }
    
    $end_time = date('Y-m-d H:i:s');
    $log_message .= "[$end_time] Completed automatic notifications.\n\n";
    
    // เขียน log
    file_put_contents($log_file, $log_message, FILE_APPEND | LOCK_EX);
    
    // หากเรียกใช้จาก command line ให้แสดงผล
    if (php_sapi_name() === 'cli') {
        echo $log_message;
    } else {
        echo "Cron job completed successfully. Total notifications sent: $total_sent";
    }
    
} catch (Exception $e) {
    $error_time = date('Y-m-d H:i:s');
    $error_message = "[$error_time] ERROR: " . $e->getMessage() . "\n\n";
    
    if (isset($log_file)) {
        file_put_contents($log_file, $error_message, FILE_APPEND | LOCK_EX);
    }
    
    if (php_sapi_name() === 'cli') {
        echo $error_message;
    } else {
        echo "Error: " . $e->getMessage();
    }
}
?>