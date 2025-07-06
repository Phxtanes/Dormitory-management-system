<?php
// notification_history.php - ประวัติการแจ้งเตือน
$page_title = "ประวัติการแจ้งเตือน";
require_once 'includes/header.php';

// ตรวจสอบการลบการแจ้งเตือน
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    try {
        $stmt = $pdo->prepare("DELETE FROM notifications WHERE notification_id = ?");
        $stmt->execute([$_GET['delete']]);
        $success_message = "ลบการแจ้งเตือนเรียบร้อยแล้ว";
    } catch(PDOException $e) {
        $error_message = "เกิดข้อผิดพลาด: " . $e->getMessage();
    }
}

// ตรวจสอบการลบหลายรายการ
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['delete_selected'])) {
    $selected_notifications = $_POST['notifications'] ?? [];
    if (!empty($selected_notifications)) {
        try {
            $placeholders = str_repeat('?,', count($selected_notifications) - 1) . '?';
            $stmt = $pdo->prepare("DELETE FROM notifications WHERE notification_id IN ($placeholders)");
            $stmt->execute($selected_notifications);
            $success_message = "ลบการแจ้งเตือน " . count($selected_notifications) . " รายการเรียบร้อยแล้ว";
        } catch(PDOException $e) {
            $error_message = "เกิดข้อผิดพลาด: " . $e->getMessage();
        }
    }
}

// ตัวกรองข้อมูล
$search = isset($_GET['search']) ? $_GET['search'] : '';
$type_filter = isset($_GET['type']) ? $_GET['type'] : '';
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : '';

// สร้าง SQL query
$sql = "SELECT n.*, t.first_name, t.last_name, t.phone, r.room_number
        FROM notifications n
        LEFT JOIN tenants t ON n.tenant_id = t.tenant_id
        LEFT JOIN contracts c ON t.tenant_id = c.tenant_id AND c.contract_status = 'active'
        LEFT JOIN rooms r ON c.room_id = r.room_id
        WHERE 1=1";

$params = [];

if (!empty($search)) {
    $sql .= " AND (n.title LIKE ? OR n.message LIKE ? OR t.first_name LIKE ? OR t.last_name LIKE ? OR r.room_number LIKE ?)";
    $search_term = "%$search%";
    $params = array_fill(0, 5, $search_term);
}

if (!empty($type_filter)) {
    $sql .= " AND n.notification_type = ?";
    $params[] = $type_filter;
}

if (!empty($status_filter)) {
    if ($status_filter === 'sent') {
        $sql .= " AND n.sent_date IS NOT NULL";
    } elseif ($status_filter === 'pending') {
        $sql .= " AND n.sent_date IS NULL";
    }
}

if (!empty($date_from)) {
    $sql .= " AND DATE(n.created_at) >= ?";
    $params[] = $date_from;
}

if (!empty($date_to)) {
    $sql .= " AND DATE(n.created_at) <= ?";
    $params[] = $date_to;
}

$sql .= " ORDER BY n.created_at DESC";

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $notifications = $stmt->fetchAll();
    
    // สถิติ
    $stats_sql = "SELECT 
        COUNT(*) as total_notifications,
        SUM(CASE WHEN sent_date IS NOT NULL THEN 1 ELSE 0 END) as sent_count,
        SUM(CASE WHEN sent_date IS NULL THEN 1 ELSE 0 END) as pending_count,
        COUNT(DISTINCT notification_type) as type_count
        FROM notifications n";
    
    if (!empty($params)) {
        // ใช้ WHERE clause เดียวกันสำหรับสถิติ
        $stats_where = str_replace('n.*, t.first_name, t.last_name, t.phone, r.room_number', 'n.notification_id', $sql);
        $stats_where = str_replace('ORDER BY n.created_at DESC', '', $stats_where);
        $stats_sql = str_replace('SELECT n.notification_id FROM notifications n', 'SELECT COUNT(*) as total_notifications, SUM(CASE WHEN n.sent_date IS NOT NULL THEN 1 ELSE 0 END) as sent_count, SUM(CASE WHEN n.sent_date IS NULL THEN 1 ELSE 0 END) as pending_count, COUNT(DISTINCT n.notification_type) as type_count FROM notifications n', $stats_where);
        
        $stmt = $pdo->prepare($stats_sql);
        $stmt->execute($params);
    } else {
        $stmt = $pdo->prepare($stats_sql);
        $stmt->execute();
    }
    
    $stats = $stmt->fetch();
    
} catch(PDOException $e) {
    $error_message = "เกิดข้อผิดพลาดในการดึงข้อมูล: " . $e->getMessage();
    $notifications = [];
    $stats = ['total_notifications' => 0, 'sent_count' => 0, 'pending_count' => 0, 'type_count' => 0];
}
?>

<?php include 'includes/navbar.php'; ?>

<div class="container-fluid mt-4">
    <div class="row">
        <div class="col-12">
            <!-- หัวข้อหน้า -->
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2 class="mb-0">
                    <i class="bi bi-clock-history"></i>
                    ประวัติการแจ้งเตือน
                </h2>
                <div class="btn-group">
                    <a href="send_notifications.php" class="btn btn-primary">
                        <i class="bi bi-plus-circle"></i>
                        ส่งการแจ้งเตือนใหม่
                    </a>
                    <a href="auto_notifications.php" class="btn btn-outline-success">
                        <i class="bi bi-robot"></i>
                        การแจ้งเตือนอัตโนมัติ
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

            <!-- สถิติ -->
            <div class="row mb-4">
                <div class="col-lg-3 col-md-6 mb-3">
                    <div class="card bg-primary text-white">
                        <div class="card-body">
                            <div class="d-flex justify-content-between">
                                <div>
                                    <h6 class="card-title">ทั้งหมด</h6>
                                    <h4 class="mb-0"><?php echo number_format($stats['total_notifications']); ?></h4>
                                </div>
                                <div class="align-self-center">
                                    <i class="bi bi-bell fs-2"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-lg-3 col-md-6 mb-3">
                    <div class="card bg-success text-white">
                        <div class="card-body">
                            <div class="d-flex justify-content-between">
                                <div>
                                    <h6 class="card-title">ส่งแล้ว</h6>
                                    <h4 class="mb-0"><?php echo number_format($stats['sent_count']); ?></h4>
                                </div>
                                <div class="align-self-center">
                                    <i class="bi bi-check-circle fs-2"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-lg-3 col-md-6 mb-3">
                    <div class="card bg-warning text-white">
                        <div class="card-body">
                            <div class="d-flex justify-content-between">
                                <div>
                                    <h6 class="card-title">รอส่ง</h6>
                                    <h4 class="mb-0"><?php echo number_format($stats['pending_count']); ?></h4>
                                </div>
                                <div class="align-self-center">
                                    <i class="bi bi-clock fs-2"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-lg-3 col-md-6 mb-3">
                    <div class="card bg-info text-white">
                        <div class="card-body">
                            <div class="d-flex justify-content-between">
                                <div>
                                    <h6 class="card-title">ประเภท</h6>
                                    <h4 class="mb-0"><?php echo number_format($stats['type_count']); ?></h4>
                                </div>
                                <div class="align-self-center">
                                    <i class="bi bi-tags fs-2"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ฟอร์มค้นหาและกรอง -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="card-title mb-0">
                        <i class="bi bi-funnel"></i>
                        ค้นหาและกรอง
                    </h5>
                </div>
                <div class="card-body">
                    <form method="GET" class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label">ค้นหา</label>
                            <input type="text" class="form-control" name="search" 
                                   value="<?php echo htmlspecialchars($search); ?>" 
                                   placeholder="หัวข้อ, เนื้อหา, ชื่อผู้เช่า, ห้อง">
                        </div>
                        
                        <div class="col-md-2">
                            <label class="form-label">ประเภท</label>
                            <select class="form-select" name="type">
                                <option value="">ทั้งหมด</option>
                                <option value="payment_due" <?php echo $type_filter == 'payment_due' ? 'selected' : ''; ?>>แจ้งชำระ</option>
                                <option value="payment_overdue" <?php echo $type_filter == 'payment_overdue' ? 'selected' : ''; ?>>ค้างชำระ</option>
                                <option value="contract_expiring" <?php echo $type_filter == 'contract_expiring' ? 'selected' : ''; ?>>สัญญาหมดอายุ</option>
                                <option value="maintenance" <?php echo $type_filter == 'maintenance' ? 'selected' : ''; ?>>ซ่อมบำรุง</option>
                                <option value="general" <?php echo $type_filter == 'general' ? 'selected' : ''; ?>>ทั่วไป</option>
                            </select>
                        </div>
                        
                        <div class="col-md-2">
                            <label class="form-label">สถานะ</label>
                            <select class="form-select" name="status">
                                <option value="">ทั้งหมด</option>
                                <option value="sent" <?php echo $status_filter == 'sent' ? 'selected' : ''; ?>>ส่งแล้ว</option>
                                <option value="pending" <?php echo $status_filter == 'pending' ? 'selected' : ''; ?>>รอส่ง</option>
                            </select>
                        </div>
                        
                        <div class="col-md-2">
                            <label class="form-label">วันที่เริ่มต้น</label>
                            <input type="date" class="form-control" name="date_from" 
                                   value="<?php echo htmlspecialchars($date_from); ?>">
                        </div>
                        
                        <div class="col-md-2">
                            <label class="form-label">วันที่สิ้นสุด</label>
                            <input type="date" class="form-control" name="date_to" 
                                   value="<?php echo htmlspecialchars($date_to); ?>">
                        </div>
                        
                        <div class="col-md-1">
                            <label class="form-label">&nbsp;</label>
                            <div class="d-grid">
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-search"></i>
                                </button>
                            </div>
                        </div>
                    </form>
                    
                    <?php if (!empty($search) || !empty($type_filter) || !empty($status_filter) || !empty($date_from) || !empty($date_to)): ?>
                        <div class="mt-3">
                            <a href="notification_history.php" class="btn btn-outline-secondary btn-sm">
                                <i class="bi bi-x-circle"></i>
                                ล้างตัวกรอง
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- ตารางข้อมูล -->
            <form method="POST" id="deleteForm">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="card-title mb-0">
                            <i class="bi bi-list"></i>
                            รายการการแจ้งเตือน
                        </h5>
                        <div class="btn-group">
                            <button type="button" class="btn btn-sm btn-outline-danger" onclick="deleteSelected()" disabled id="deleteBtn">
                                <i class="bi bi-trash"></i>
                                ลบที่เลือก
                            </button>
                            <span class="badge bg-primary align-self-center ms-2"><?php echo count($notifications); ?> รายการ</span>
                        </div>
                    </div>
                    <div class="card-body">
                        <?php if (empty($notifications)): ?>
                            <div class="text-center py-5">
                                <i class="bi bi-bell-slash display-1 text-muted"></i>
                                <h4 class="text-muted mt-3">ไม่พบการแจ้งเตือน</h4>
                                <p class="text-muted">ยังไม่มีการแจ้งเตือน หรือไม่มีข้อมูลที่ตรงกับเงื่อนไขการค้นหา</p>
                                <a href="send_notifications.php" class="btn btn-primary">
                                    <i class="bi bi-plus-circle"></i>
                                    ส่งการแจ้งเตือนแรก
                                </a>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead class="table-light">
                                        <tr>
                                            <th width="3%">
                                                <input type="checkbox" class="form-check-input" id="selectAll">
                                            </th>
                                            <th width="12%">วันที่สร้าง</th>
                                            <th width="10%">ประเภท</th>
                                            <th width="20%">หัวข้อ</th>
                                            <th width="15%">ผู้รับ</th>
                                            <th width="8%">ห้อง</th>
                                            <th width="10%">วิธีส่ง</th>
                                            <th width="10%">สถานะ</th>
                                            <th width="12%">จัดการ</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($notifications as $notification): ?>
                                            <tr>
                                                <td>
                                                    <input type="checkbox" class="form-check-input notification-checkbox" 
                                                           name="notifications[]" value="<?php echo $notification['notification_id']; ?>">
                                                </td>
                                                <td>
                                                    <div class="fw-bold"><?php echo formatDate($notification['created_at']); ?></div>
                                                    <small class="text-muted"><?php echo date('H:i', strtotime($notification['created_at'])); ?></small>
                                                </td>
                                                <td>
                                                    <?php
                                                    $type_badges = [
                                                        'payment_due' => '<span class="badge bg-warning">แจ้งชำระ</span>',
                                                        'payment_overdue' => '<span class="badge bg-danger">ค้างชำระ</span>',
                                                        'contract_expiring' => '<span class="badge bg-info">สัญญาหมดอายุ</span>',
                                                        'maintenance' => '<span class="badge bg-secondary">ซ่อมบำรุง</span>',
                                                        'general' => '<span class="badge bg-primary">ทั่วไป</span>'
                                                    ];
                                                    echo $type_badges[$notification['notification_type']] ?? '<span class="badge bg-light text-dark">' . $notification['notification_type'] . '</span>';
                                                    ?>
                                                </td>
                                                <td>
                                                    <div class="fw-bold"><?php echo htmlspecialchars($notification['title']); ?></div>
                                                    <small class="text-muted">
                                                        <?php echo mb_substr(htmlspecialchars($notification['message']), 0, 50, 'UTF-8'); ?>
                                                        <?php if (mb_strlen($notification['message'], 'UTF-8') > 50) echo '...'; ?>
                                                    </small>
                                                </td>
                                                <td>
                                                    <?php if ($notification['first_name']): ?>
                                                        <div class="d-flex align-items-center">
                                                            <div class="avatar bg-primary text-white rounded-circle me-2 flex-shrink-0" 
                                                                 style="width: 28px; height: 28px; display: flex; align-items: center; justify-content: center; font-size: 0.7rem;">
                                                                <?php echo mb_substr($notification['first_name'], 0, 1, 'UTF-8'); ?>
                                                            </div>
                                                            <div class="min-w-0">
                                                                <div class="fw-bold text-truncate"><?php echo $notification['first_name'] . ' ' . $notification['last_name']; ?></div>
                                                                <small class="text-muted"><?php echo $notification['phone']; ?></small>
                                                            </div>
                                                        </div>
                                                    <?php else: ?>
                                                        <span class="text-muted">-</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ($notification['room_number']): ?>
                                                        <span class="badge bg-info"><?php echo $notification['room_number']; ?></span>
                                                    <?php else: ?>
                                                        <span class="text-muted">-</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php
                                                    $method_badges = [
                                                        'email' => '<span class="badge bg-primary">อีเมล</span>',
                                                        'sms' => '<span class="badge bg-success">SMS</span>',
                                                        'system' => '<span class="badge bg-secondary">ระบบ</span>'
                                                    ];
                                                    echo $method_badges[$notification['send_method']] ?? '<span class="badge bg-light text-dark">' . $notification['send_method'] . '</span>';
                                                    ?>
                                                </td>
                                                <td>
                                                    <?php if ($notification['sent_date']): ?>
                                                        <span class="badge bg-success">
                                                            <i class="bi bi-check-circle"></i>
                                                            ส่งแล้ว
                                                        </span>
                                                        <br><small class="text-muted"><?php echo formatDate($notification['sent_date']); ?></small>
                                                    <?php else: ?>
                                                        <span class="badge bg-warning">
                                                            <i class="bi bi-clock"></i>
                                                            รอส่ง
                                                        </span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <div class="btn-group" role="group">
                                                        <button type="button" class="btn btn-sm btn-outline-info" 
                                                                data-bs-toggle="modal" 
                                                                data-bs-target="#viewModal"
                                                                data-title="<?php echo htmlspecialchars($notification['title']); ?>"
                                                                data-message="<?php echo htmlspecialchars($notification['message']); ?>"
                                                                data-type="<?php echo $notification['notification_type']; ?>"
                                                                data-created="<?php echo formatDateTime($notification['created_at']); ?>"
                                                                title="ดูรายละเอียด">
                                                            <i class="bi bi-eye"></i>
                                                        </button>
                                                        <a href="notification_history.php?delete=<?php echo $notification['notification_id']; ?>" 
                                                           class="btn btn-sm btn-outline-danger" 
                                                           title="ลบ"
                                                           onclick="return confirm('ต้องการลบการแจ้งเตือนนี้หรือไม่?')">
                                                            <i class="bi bi-trash"></i>
                                                        </a>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                <input type="hidden" name="delete_selected" value="1">
            </form>
        </div>
    </div>
</div>

<!-- Modal สำหรับดูรายละเอียดการแจ้งเตือน -->
<div class="modal fade" id="viewModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">รายละเอียดการแจ้งเตือน</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row">
                    <div class="col-md-6">
                        <strong>หัวข้อ:</strong>
                        <p id="modalTitle" class="mt-1"></p>
                    </div>
                    <div class="col-md-6">
                        <strong>ประเภท:</strong>
                        <p id="modalType" class="mt-1"></p>
                    </div>
                </div>
                <div class="row">
                    <div class="col-12">
                        <strong>เนื้อหา:</strong>
                        <div id="modalMessage" class="mt-1 p-3 bg-light rounded" style="white-space: pre-line;"></div>
                    </div>
                </div>
                <div class="row mt-3">
                    <div class="col-12">
                        <strong>วันที่สร้าง:</strong>
                        <p id="modalCreated" class="mt-1"></p>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ปิด</button>
            </div>
        </div>
    </div>
</div>

<script>
// JavaScript สำหรับจัดการการเลือกและลบ
document.addEventListener('DOMContentLoaded', function() {
    const selectAllCheckbox = document.getElementById('selectAll');
    const notificationCheckboxes = document.querySelectorAll('.notification-checkbox');
    const deleteBtn = document.getElementById('deleteBtn');
    
    // Select all functionality
    selectAllCheckbox.addEventListener('change', function() {
        notificationCheckboxes.forEach(checkbox => {
            checkbox.checked = this.checked;
        });
        updateDeleteButton();
    });
    
    // Individual checkbox change
    notificationCheckboxes.forEach(checkbox => {
        checkbox.addEventListener('change', function() {
            updateDeleteButton();
            updateSelectAll();
        });
    });
    
    function updateDeleteButton() {
        const checkedCount = document.querySelectorAll('.notification-checkbox:checked').length;
        deleteBtn.disabled = checkedCount === 0;
        deleteBtn.textContent = checkedCount > 0 ? `ลบที่เลือก (${checkedCount})` : 'ลบที่เลือก';
    }
    
    function updateSelectAll() {
        const checkedCount = document.querySelectorAll('.notification-checkbox:checked').length;
        selectAllCheckbox.checked = checkedCount === notificationCheckboxes.length && checkedCount > 0;
        selectAllCheckbox.indeterminate = checkedCount > 0 && checkedCount < notificationCheckboxes.length;
    }
    
    // Modal functionality
    const viewModal = document.getElementById('viewModal');
    viewModal.addEventListener('show.bs.modal', function(event) {
        const button = event.relatedTarget;
        
        document.getElementById('modalTitle').textContent = button.getAttribute('data-title');
        document.getElementById('modalMessage').textContent = button.getAttribute('data-message');
        document.getElementById('modalCreated').textContent = button.getAttribute('data-created');
        
        const type = button.getAttribute('data-type');
        const typeLabels = {
            'payment_due': 'แจ้งเตือนชำระเงิน',
            'payment_overdue': 'แจ้งเตือนเงินค้างชำระ',
            'contract_expiring': 'แจ้งเตือนสัญญาใกล้หมดอายุ',
            'maintenance': 'แจ้งเตือนการซ่อมบำรุง',
            'general': 'ประกาศทั่วไป'
        };
        document.getElementById('modalType').textContent = typeLabels[type] || type;
    });
});

function deleteSelected() {
    const checkedCount = document.querySelectorAll('.notification-checkbox:checked').length;
    if (checkedCount === 0) {
        alert('กรุณาเลือกรายการที่ต้องการลบ');
        return;
    }
    
    if (confirm(`คุณต้องการลบการแจ้งเตือน ${checkedCount} รายการที่เลือกหรือไม่?`)) {
        document.getElementById('deleteForm').submit();
    }
}
</script>

<?php require_once 'includes/footer.php'; ?>