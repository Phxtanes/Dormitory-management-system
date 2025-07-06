<?php
// send_notifications.php - ระบบส่งอีเมลแจ้งเตือนอัตโนมัติ
$page_title = "ส่งการแจ้งเตือน";
require_once 'includes/header.php';
require_once 'includes/email_functions.php';

// ตรวจสอบการส่งการแจ้งเตือน
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['send_notifications'])) {
    $notification_type = $_POST['notification_type'];
    $message_title = trim($_POST['message_title']);
    $message_content = trim($_POST['message_content']);
    $selected_rooms = isset($_POST['rooms']) ? $_POST['rooms'] : [];
    $send_method = $_POST['send_method'] ?? 'email';
    
    if (empty($selected_rooms)) {
        $error_message = "กรุณาเลือกห้องที่ต้องการส่งการแจ้งเตือน";
    } elseif (empty($message_title) || empty($message_content)) {
        $error_message = "กรุณากรอกหัวข้อและเนื้อหาการแจ้งเตือน";
    } else {
        try {
            $pdo->beginTransaction();
            $success_count = 0;
            $failed_count = 0;
            $failed_details = [];
            
            foreach ($selected_rooms as $room_id) {
                // ดึงข้อมูลผู้เช่าในห้อง
                $stmt = $pdo->prepare("
                    SELECT t.*, r.room_number, c.contract_id
                    FROM tenants t
                    JOIN contracts c ON t.tenant_id = c.tenant_id
                    JOIN rooms r ON c.room_id = r.room_id
                    WHERE c.room_id = ? AND c.contract_status = 'active'
                ");
                $stmt->execute([$room_id]);
                $tenant = $stmt->fetch();
                
                if (!$tenant || empty($tenant['email'])) {
                    $failed_count++;
                    $room_number = $tenant['room_number'] ?? "ID: $room_id";
                    $failed_details[] = "ห้อง $room_number - ไม่มีอีเมลผู้เช่า";
                    continue;
                }
                
                // บันทึกการแจ้งเตือนในระบบ
                $stmt = $pdo->prepare("
                    INSERT INTO notifications (
                        tenant_id, notification_type, title, message, 
                        send_method, scheduled_date, created_at
                    ) VALUES (?, ?, ?, ?, ?, CURDATE(), NOW())
                ");
                $stmt->execute([
                    $tenant['tenant_id'], 
                    $notification_type, 
                    $message_title, 
                    $message_content,
                    $send_method
                ]);
                
                $notification_id = $pdo->lastInsertId();
                
                // ส่งอีเมล
                if ($send_method === 'email' || $send_method === 'both') {
                    $email_sent = sendNotificationEmail(
                        $tenant['email'],
                        $tenant['first_name'] . ' ' . $tenant['last_name'],
                        $tenant['room_number'],
                        $message_title,
                        $message_content,
                        $notification_type
                    );
                    
                    if ($email_sent) {
                        // อัพเดทสถานะการส่ง
                        $stmt = $pdo->prepare("
                            UPDATE notifications 
                            SET sent_date = CURDATE() 
                            WHERE notification_id = ?
                        ");
                        $stmt->execute([$notification_id]);
                        $success_count++;
                    } else {
                        $failed_count++;
                        $failed_details[] = "ห้อง {$tenant['room_number']} - ส่งอีเมลไม่สำเร็จ";
                    }
                } else {
                    $success_count++;
                }
            }
            
            $pdo->commit();
            
            $success_message = "ส่งการแจ้งเตือนเรียบร้อยแล้ว $success_count รายการ";
            if ($failed_count > 0) {
                $success_message .= " (ไม่สำเร็จ $failed_count รายการ)";
                if (!empty($failed_details)) {
                    $error_message = "รายละเอียดที่ไม่สำเร็จ:<br>" . implode('<br>', $failed_details);
                }
            }
            
        } catch(PDOException $e) {
            $pdo->rollBack();
            $error_message = "เกิดข้อผิดพลาด: " . $e->getMessage();
        }
    }
}

// ดึงข้อมูลห้องพักและผู้เช่า
try {
    $rooms_sql = "
        SELECT r.*, 
               t.first_name, t.last_name, t.email, t.phone,
               c.contract_start, c.contract_end
        FROM rooms r
        LEFT JOIN contracts c ON r.room_id = c.room_id AND c.contract_status = 'active'
        LEFT JOIN tenants t ON c.tenant_id = t.tenant_id
        ORDER BY CAST(SUBSTRING(r.room_number FROM '[0-9]+') AS UNSIGNED), r.room_number
    ";
    
    $stmt = $pdo->query($rooms_sql);
    $rooms = $stmt->fetchAll();
    
    // แยกห้องที่มีผู้เช่าและไม่มีผู้เช่า
    $occupied_rooms = array_filter($rooms, function($room) {
        return !empty($room['first_name']);
    });
    
    $empty_rooms = array_filter($rooms, function($room) {
        return empty($room['first_name']);
    });
    
} catch(PDOException $e) {
    $error_message = "เกิดข้อผิดพลาดในการดึงข้อมูล: " . $e->getMessage();
    $rooms = [];
    $occupied_rooms = [];
    $empty_rooms = [];
}

// ดึงประวัติการแจ้งเตือนล่าสุด
try {
    $history_sql = "
        SELECT n.*, t.first_name, t.last_name, r.room_number
        FROM notifications n
        JOIN tenants t ON n.tenant_id = t.tenant_id
        LEFT JOIN contracts c ON t.tenant_id = c.tenant_id AND c.contract_status = 'active'
        LEFT JOIN rooms r ON c.room_id = r.room_id
        ORDER BY n.created_at DESC
        LIMIT 10
    ";
    
    $stmt = $pdo->query($history_sql);
    $recent_notifications = $stmt->fetchAll();
    
} catch(PDOException $e) {
    $recent_notifications = [];
}
?>

<?php include 'includes/navbar.php'; ?>

<div class="container-fluid mt-4">
    <div class="row">
        <div class="col-12">
            <!-- หัวข้อหน้า -->
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2 class="mb-0">
                    <i class="bi bi-envelope-fill"></i>
                    ส่งการแจ้งเตือน
                </h2>
                <div class="btn-group">
                    <a href="notification_history.php" class="btn btn-outline-secondary">
                        <i class="bi bi-clock-history"></i>
                        ประวัติการแจ้งเตือน
                    </a>
                    <a href="email_settings.php" class="btn btn-outline-primary">
                        <i class="bi bi-gear"></i>
                        ตั้งค่าอีเมล
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

            <div class="row">
                <!-- ฟอร์มส่งการแจ้งเตือน -->
                <div class="col-lg-8 mb-4">
                    <form method="POST" id="notificationForm">
                        <input type="hidden" name="send_notifications" value="1">
                        
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0">
                                    <i class="bi bi-pencil-square"></i>
                                    สร้างการแจ้งเตือน
                                </h5>
                            </div>
                            <div class="card-body">
                                <!-- ประเภทการแจ้งเตือน -->
                                <div class="row mb-3">
                                    <div class="col-md-6">
                                        <label for="notification_type" class="form-label">ประเภทการแจ้งเตือน <span class="text-danger">*</span></label>
                                        <select class="form-select" id="notification_type" name="notification_type" required>
                                            <option value="">เลือกประเภท</option>
                                            <option value="payment_due">แจ้งเตือนชำระเงิน</option>
                                            <option value="payment_overdue">แจ้งเตือนเงินค้างชำระ</option>
                                            <option value="contract_expiring">แจ้งเตือนสัญญาใกล้หมดอายุ</option>
                                            <option value="maintenance">แจ้งเตือนการซ่อมบำรุง</option>
                                            <option value="general">ประกาศทั่วไป</option>
                                        </select>
                                    </div>
                                    <div class="col-md-6">
                                        <label for="send_method" class="form-label">วิธีส่ง</label>
                                        <select class="form-select" id="send_method" name="send_method">
                                            <option value="email">อีเมลเท่านั้น</option>
                                            <option value="system">ระบบเท่านั้น</option>
                                            <option value="both">ทั้งอีเมลและระบบ</option>
                                        </select>
                                    </div>
                                </div>

                                <!-- หัวข้อและเนื้อหา -->
                                <div class="mb-3">
                                    <label for="message_title" class="form-label">หัวข้อการแจ้งเตือน <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" id="message_title" name="message_title" 
                                           placeholder="ระบุหัวข้อการแจ้งเตือน" required>
                                </div>

                                <div class="mb-3">
                                    <label for="message_content" class="form-label">เนื้อหาการแจ้งเตือน <span class="text-danger">*</span></label>
                                    <textarea class="form-control" id="message_content" name="message_content" 
                                              rows="5" placeholder="ระบุเนื้อหาการแจ้งเตือน" required></textarea>
                                    <div class="form-text">
                                        ตัวแปรที่ใช้ได้: {tenant_name}, {room_number}, {dormitory_name}
                                    </div>
                                </div>

                                <!-- เลือกห้อง -->
                                <div class="mb-3">
                                    <label class="form-label">เลือกห้องที่ต้องการส่ง <span class="text-danger">*</span></label>
                                    <div class="border rounded p-3" style="max-height: 300px; overflow-y: auto;">
                                        <div class="mb-3">
                                            <div class="btn-group w-100" role="group">
                                                <button type="button" class="btn btn-outline-primary" onclick="selectAllRooms()">
                                                    <i class="bi bi-check-all"></i>
                                                    เลือกทั้งหมด
                                                </button>
                                                <button type="button" class="btn btn-outline-secondary" onclick="selectNoneRooms()">
                                                    <i class="bi bi-x-square"></i>
                                                    ยกเลิกทั้งหมด
                                                </button>
                                                <button type="button" class="btn btn-outline-success" onclick="selectOccupiedRooms()">
                                                    <i class="bi bi-people"></i>
                                                    ห้องที่มีผู้เช่า
                                                </button>
                                            </div>
                                        </div>
                                        
                                        <?php if (empty($occupied_rooms)): ?>
                                            <div class="text-center text-muted py-3">
                                                <i class="bi bi-inbox fs-1"></i>
                                                <p class="mt-2">ไม่มีห้องที่มีผู้เช่า</p>
                                            </div>
                                        <?php else: ?>
                                            <div class="row">
                                                <?php foreach ($occupied_rooms as $room): ?>
                                                    <div class="col-md-6 mb-2">
                                                        <div class="form-check">
                                                            <input class="form-check-input room-checkbox" 
                                                                   type="checkbox" 
                                                                   name="rooms[]" 
                                                                   value="<?php echo $room['room_id']; ?>" 
                                                                   id="room_<?php echo $room['room_id']; ?>">
                                                            <label class="form-check-label" for="room_<?php echo $room['room_id']; ?>">
                                                                <div class="d-flex align-items-center">
                                                                    <span class="badge bg-info me-2"><?php echo $room['room_number']; ?></span>
                                                                    <div>
                                                                        <div class="fw-bold"><?php echo $room['first_name'] . ' ' . $room['last_name']; ?></div>
                                                                        <small class="text-muted">
                                                                            <?php if ($room['email']): ?>
                                                                                <i class="bi bi-envelope"></i> <?php echo $room['email']; ?>
                                                                            <?php else: ?>
                                                                                <i class="bi bi-exclamation-triangle text-warning"></i> ไม่มีอีเมล
                                                                            <?php endif; ?>
                                                                        </small>
                                                                    </div>
                                                                </div>
                                                            </label>
                                                        </div>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="form-text">
                                        เลือกแล้ว: <strong id="selectedCount">0</strong> ห้อง
                                    </div>
                                </div>

                                <!-- ปุ่มส่ง -->
                                <div class="d-grid">
                                    <button type="submit" class="btn btn-success btn-lg" id="sendBtn" disabled>
                                        <i class="bi bi-send"></i>
                                        ส่งการแจ้งเตือน
                                    </button>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>

                <!-- แม่แบบข้อความ -->
                <div class="col-lg-4 mb-4">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">
                                <i class="bi bi-file-text"></i>
                                แม่แบบข้อความ
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="list-group">
                                <a href="#" class="list-group-item list-group-item-action template-item" 
                                   data-type="payment_due"
                                   data-title="แจ้งเตือนชำระค่าเช่า"
                                   data-content="เรียน {tenant_name}&#10;&#10;ขอแจ้งให้ทราบว่า ค่าเช่าห้อง {room_number} ประจำเดือนนี้ ยังไม่ได้รับการชำระ&#10;&#10;กรุณาชำระภายในกำหนด เพื่อหลีกเลี่ยงค่าปรับ&#10;&#10;ขอบคุณครับ/ค่ะ&#10;{dormitory_name}">
                                    <div class="d-flex w-100 justify-content-between">
                                        <h6 class="mb-1">แจ้งเตือนชำระค่าเช่า</h6>
                                        <small>แจ้งชำระ</small>
                                    </div>
                                    <p class="mb-1">แจ้งเตือนผู้เช่าชำระค่าเช่าประจำเดือน</p>
                                </a>
                                
                                <a href="#" class="list-group-item list-group-item-action template-item"
                                   data-type="payment_overdue"
                                   data-title="แจ้งเตือนค่าเช่าค้างชำระ"
                                   data-content="เรียน {tenant_name}&#10;&#10;ขอแจ้งให้ทราบว่า ค่าเช่าห้อง {room_number} ค้างชำระเกินกำหนดแล้ว&#10;&#10;กรุณาติดต่อชำระโดยด่วน มิฉะนั้นอาจต้องดำเนินการตามสัญญา&#10;&#10;ขอบคุณครับ/ค่ะ&#10;{dormitory_name}">
                                    <div class="d-flex w-100 justify-content-between">
                                        <h6 class="mb-1">เตือนค่าเช่าค้างชำระ</h6>
                                        <small>ค้างชำระ</small>
                                    </div>
                                    <p class="mb-1">แจ้งเตือนผู้เช่าที่ค้างชำระค่าเช่า</p>
                                </a>
                                
                                <a href="#" class="list-group-item list-group-item-action template-item"
                                   data-type="maintenance"
                                   data-title="แจ้งปิดปรับปรุง"
                                   data-content="เรียน {tenant_name}&#10;&#10;ขอแจ้งให้ทราบว่า หอพัก {dormitory_name} จะมีการปิดปรับปรุงระบบน้ำ&#10;&#10;วันที่: [ระบุวันที่]&#10;เวลา: [ระบุเวลา]&#10;&#10;ขออภัยในความไม่สะดวก&#10;{dormitory_name}">
                                    <div class="d-flex w-100 justify-content-between">
                                        <h6 class="mb-1">แจ้งปิดปรับปรุง</h6>
                                        <small>ซ่อมบำรุง</small>
                                    </div>
                                    <p class="mb-1">แจ้งการปิดปรับปรุงหรือซ่อมบำรุง</p>
                                </a>
                                
                                <a href="#" class="list-group-item list-group-item-action template-item"
                                   data-type="general"
                                   data-title="ประกาศทั่วไป"
                                   data-content="เรียน {tenant_name}&#10;&#10;ขอแจ้งให้ทราบ [เนื้อหาประกาศ]&#10;&#10;ขอบคุณครับ/ค่ะ&#10;{dormitory_name}">
                                    <div class="d-flex w-100 justify-content-between">
                                        <h6 class="mb-1">ประกาศทั่วไป</h6>
                                        <small>ทั่วไป</small>
                                    </div>
                                    <p class="mb-1">แม่แบบข้อความประกาศทั่วไป</p>
                                </a>
                            </div>
                        </div>
                    </div>

                    <!-- ประวัติการแจ้งเตือนล่าสุด -->
                    <div class="card mt-4">
                        <div class="card-header">
                            <h6 class="mb-0">
                                <i class="bi bi-clock-history"></i>
                                ประวัติล่าสุด
                            </h6>
                        </div>
                        <div class="card-body">
                            <?php if (empty($recent_notifications)): ?>
                                <div class="text-center text-muted py-3">
                                    <i class="bi bi-bell-slash"></i>
                                    <p class="mt-2 mb-0">ยังไม่มีการแจ้งเตือน</p>
                                </div>
                            <?php else: ?>
                                <div class="list-group list-group-flush">
                                    <?php foreach (array_slice($recent_notifications, 0, 5) as $notification): ?>
                                        <div class="list-group-item px-0">
                                            <div class="d-flex w-100 justify-content-between">
                                                <h6 class="mb-1"><?php echo htmlspecialchars($notification['title']); ?></h6>
                                                <small><?php echo formatDate($notification['created_at']); ?></small>
                                            </div>
                                            <p class="mb-1">
                                                <span class="badge bg-secondary"><?php echo $notification['room_number'] ?? 'N/A'; ?></span>
                                                <?php echo htmlspecialchars($notification['first_name'] . ' ' . $notification['last_name']); ?>
                                            </p>
                                            <small class="text-muted">
                                                <?php
                                                $type_labels = [
                                                    'payment_due' => 'แจ้งชำระ',
                                                    'payment_overdue' => 'ค้างชำระ',
                                                    'contract_expiring' => 'สัญญาหมดอายุ',
                                                    'maintenance' => 'ซ่อมบำรุง',
                                                    'general' => 'ทั่วไป'
                                                ];
                                                echo $type_labels[$notification['notification_type']] ?? $notification['notification_type'];
                                                ?>
                                            </small>
                                        </div>
                                    <?php endforeach; ?>
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
// JavaScript สำหรับจัดการฟอร์ม
function selectAllRooms() {
    const checkboxes = document.querySelectorAll('.room-checkbox');
    checkboxes.forEach(checkbox => {
        checkbox.checked = true;
    });
    updateSelectedCount();
}

function selectNoneRooms() {
    const checkboxes = document.querySelectorAll('.room-checkbox');
    checkboxes.forEach(checkbox => {
        checkbox.checked = false;
    });
    updateSelectedCount();
}

function selectOccupiedRooms() {
    // ฟังก์ชันนี้จะเลือกห้องที่มีผู้เช่าเท่านั้น (ในที่นี้คือทั้งหมดที่แสดง)
    selectAllRooms();
}

function updateSelectedCount() {
    const checkboxes = document.querySelectorAll('.room-checkbox:checked');
    const count = checkboxes.length;
    document.getElementById('selectedCount').textContent = count;
    document.getElementById('sendBtn').disabled = count === 0;
}

// อัพเดทจำนวนที่เลือกเมื่อ checkbox เปลี่ยนแปลง
document.addEventListener('DOMContentLoaded', function() {
    const checkboxes = document.querySelectorAll('.room-checkbox');
    checkboxes.forEach(checkbox => {
        checkbox.addEventListener('change', updateSelectedCount);
    });
    
    // จัดการแม่แบบข้อความ
    const templateItems = document.querySelectorAll('.template-item');
    templateItems.forEach(item => {
        item.addEventListener('click', function(e) {
            e.preventDefault();
            
            const type = this.dataset.type;
            const title = this.dataset.title;
            const content = this.dataset.content.replace(/&#10;/g, '\n');
            
            document.getElementById('notification_type').value = type;
            document.getElementById('message_title').value = title;
            document.getElementById('message_content').value = content;
            
            // เพิ่ม highlight effect
            this.classList.add('active');
            setTimeout(() => {
                this.classList.remove('active');
            }, 200);
        });
    });
    
    // ยืนยันก่อนส่ง
    document.getElementById('notificationForm').addEventListener('submit', function(e) {
        const selectedCount = document.querySelectorAll('.room-checkbox:checked').length;
        if (selectedCount === 0) {
            e.preventDefault();
            alert('กรุณาเลือกห้องที่ต้องการส่งการแจ้งเตือน');
            return false;
        }
        
        if (!confirm(`คุณต้องการส่งการแจ้งเตือนไปยัง ${selectedCount} ห้องใช่หรือไม่?`)) {
            e.preventDefault();
            return false;
        }
    });
    
    updateSelectedCount();
});
</script>

<?php require_once 'includes/footer.php'; ?>