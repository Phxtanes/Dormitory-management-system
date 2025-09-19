<?php
$page_title = "สถานะห้องพัก";
require_once 'includes/header.php';

// ตรวจสอบการอัพเดทสถานะห้อง
if (isset($_POST['update_status']) && isset($_POST['room_id']) && isset($_POST['new_status'])) {
    try {
        $stmt = $pdo->prepare("UPDATE rooms SET room_status = ? WHERE room_id = ?");
        $stmt->execute([$_POST['new_status'], $_POST['room_id']]);
        $success_message = "อัพเดทสถานะห้องเรียบร้อยแล้ว";
    } catch(PDOException $e) {
        $error_message = "เกิดข้อผิดพลาด: " . $e->getMessage();
    }
}

// ดึงข้อมูลห้องพักทั้งหมดพร้อมข้อมูลผู้เช่า
$floor_filter = isset($_GET['floor']) ? $_GET['floor'] : '';
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';
$type_filter = isset($_GET['type']) ? $_GET['type'] : '';

$sql = "SELECT r.*,
        (SELECT COUNT(*) FROM contracts c WHERE c.room_id = r.room_id AND c.contract_status = 'active') as has_tenant,
        (SELECT CONCAT(t.first_name, ' ', t.last_name) 
         FROM contracts c 
         JOIN tenants t ON c.tenant_id = t.tenant_id 
         WHERE c.room_id = r.room_id AND c.contract_status = 'active' 
         LIMIT 1) as tenant_name,
        (SELECT c.contract_start 
         FROM contracts c 
         WHERE c.room_id = r.room_id AND c.contract_status = 'active' 
         LIMIT 1) as move_in_date,
        (SELECT c.contract_end 
         FROM contracts c 
         WHERE c.room_id = r.room_id AND c.contract_status = 'active' 
         LIMIT 1) as contract_end_date,
        (SELECT t.phone 
         FROM contracts c 
         JOIN tenants t ON c.tenant_id = t.tenant_id 
         WHERE c.room_id = r.room_id AND c.contract_status = 'active' 
         LIMIT 1) as tenant_phone
        FROM rooms r WHERE 1=1";

$params = [];

if (!empty($floor_filter)) {
    $sql .= " AND r.floor_number = ?";
    $params[] = $floor_filter;
}

if (!empty($status_filter)) {
    if ($status_filter == 'occupied') {
        $sql .= " AND EXISTS (SELECT 1 FROM contracts c WHERE c.room_id = r.room_id AND c.contract_status = 'active')";
    } else {
        $sql .= " AND r.room_status = ? AND NOT EXISTS (SELECT 1 FROM contracts c WHERE c.room_id = r.room_id AND c.contract_status = 'active')";
        $params[] = $status_filter;
    }
}

if (!empty($type_filter)) {
    $sql .= " AND r.room_type = ?";
    $params[] = $type_filter;
}

$sql .= " ORDER BY r.floor_number ASC, CAST(SUBSTRING(r.room_number, 2) AS UNSIGNED) ASC";

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rooms = $stmt->fetchAll();
    
    // ดึงข้อมูลสถิติ
    $stats = [
        'total' => 0,
        'available' => 0,
        'occupied' => 0,
        'maintenance' => 0
    ];
    
    foreach ($rooms as $room) {
        $stats['total']++;
        if ($room['has_tenant'] > 0) {
            $stats['occupied']++;
        } else {
            $stats[$room['room_status']]++;
        }
    }
    
    // ดึงรายการชั้น
    $floor_stmt = $pdo->query("SELECT DISTINCT floor_number FROM rooms ORDER BY floor_number");
    $floors = $floor_stmt->fetchAll();
    
} catch(PDOException $e) {
    $error_message = "เกิดข้อผิดพลาดในการดึงข้อมูล: " . $e->getMessage();
}
?>

<style>
    body {
        background-color : #CCE5FF;
    }
</style>

<?php include 'includes/navbar.php'; ?>

<div class="container-fluid mt-4">
    <div class="row">
        <div class="col-12">
            <!-- หัวข้อหน้า -->
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2 class="mb-0">
                    <i class="bi bi-diagram-3"></i>
                    สถานะห้องพัก
                </h2>
                <div class="btn-group">
                    <a href="rooms.php" class="btn btn-outline-secondary">
                        <i class="bi bi-list"></i>
                        จัดการห้องพัก
                    </a>
                    <a href="add_room.php" class="btn btn-primary">
                        <i class="bi bi-plus-circle"></i>
                        เพิ่มห้องพัก
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

            <!-- สถิติภาพรวม -->
            <div class="row mb-4">
                <div class="col-md-3">
                    <div class="card text-white bg-primary">
                        <div class="card-body text-center">
                            <i class="bi bi-building fs-2"></i>
                            <h4 class="mt-2"><?php echo $stats['total']; ?></h4>
                            <p class="mb-0">ห้องทั้งหมด</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card text-white bg-success">
                        <div class="card-body text-center">
                            <i class="bi bi-door-open fs-2"></i>
                            <h4 class="mt-2"><?php echo $stats['available']; ?></h4>
                            <p class="mb-0">ว่าง</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card text-white bg-danger">
                        <div class="card-body text-center">
                            <i class="bi bi-person-fill fs-2"></i>
                            <h4 class="mt-2"><?php echo $stats['occupied']; ?></h4>
                            <p class="mb-0">มีผู้เช่า</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card text-white bg-warning">
                        <div class="card-body text-center">
                            <i class="bi bi-tools fs-2"></i>
                            <h4 class="mt-2"><?php echo $stats['maintenance']; ?></h4>
                            <p class="mb-0">ปรับปรุง</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ฟอร์มกรองข้อมูล -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0">
                        <i class="bi bi-funnel"></i>
                        กรองข้อมูล
                    </h5>
                </div>
                <div class="card-body">
                    <form method="GET" class="row g-3">
                        <div class="col-md-3">
                            <label for="floor" class="form-label">ชั้น</label>
                            <select class="form-select" id="floor" name="floor">
                                <option value="">ทุกชั้น</option>
                                <?php foreach ($floors as $floor): ?>
                                    <option value="<?php echo $floor['floor_number']; ?>" 
                                            <?php echo $floor_filter == $floor['floor_number'] ? 'selected' : ''; ?>>
                                        ชั้น <?php echo $floor['floor_number']; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label for="status" class="form-label">สถานะ</label>
                            <select class="form-select" id="status" name="status">
                                <option value="">ทุกสถานะ</option>
                                <option value="available" <?php echo $status_filter == 'available' ? 'selected' : ''; ?>>ว่าง</option>
                                <option value="occupied" <?php echo $status_filter == 'occupied' ? 'selected' : ''; ?>>มีผู้เช่า</option>
                                <option value="maintenance" <?php echo $status_filter == 'maintenance' ? 'selected' : ''; ?>>ปรับปรุง</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label for="type" class="form-label">ประเภทห้อง</label>
                            <select class="form-select" id="type" name="type">
                                <option value="">ทุกประเภท</option>
                                <option value="single" <?php echo $type_filter == 'single' ? 'selected' : ''; ?>>ห้องเดี่ยว</option>
                                <option value="double" <?php echo $type_filter == 'double' ? 'selected' : ''; ?>>ห้องคู่</option>
                                <option value="triple" <?php echo $type_filter == 'triple' ? 'selected' : ''; ?>>ห้องสาม</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">&nbsp;</label>
                            <div class="d-grid">
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-search"></i>
                                    กรอง
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <!-- แสดงสถานะห้องพักแบบ Grid -->
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">
                        <i class="bi bi-grid"></i>
                        แผนผังห้องพัก
                    </h5>
                    <div class="d-flex gap-3">
                        <span class="badge bg-success"><i class="bi bi-circle-fill"></i> ว่าง</span>
                        <span class="badge bg-danger"><i class="bi bi-circle-fill"></i> มีผู้เช่า</span>
                        <span class="badge bg-warning"><i class="bi bi-circle-fill"></i> ปรับปรุง</span>
                    </div>
                </div>
                <div class="card-body">
                    <?php if (empty($rooms)): ?>
                        <div class="text-center py-5">
                            <i class="bi bi-building display-1 text-muted"></i>
                            <h4 class="text-muted mt-3">ไม่พบข้อมูลห้องพัก</h4>
                            <p class="text-muted">ไม่มีข้อมูลที่ตรงกับเงื่อนไขการกรอง</p>
                        </div>
                    <?php else: ?>
                        <?php
                        // จัดกลุ่มห้องตามชั้น
                        $rooms_by_floor = [];
                        foreach ($rooms as $room) {
                            $rooms_by_floor[$room['floor_number']][] = $room;
                        }
                        ksort($rooms_by_floor);
                        ?>
                        
                        <?php foreach ($rooms_by_floor as $floor => $floor_rooms): ?>
                            <div class="mb-4">
                                <h6 class="text-primary mb-3">
                                    <i class="bi bi-building"></i>
                                    ชั้น <?php echo $floor; ?>
                                </h6>
                                <div class="row g-3">
                                    <?php foreach ($floor_rooms as $room): ?>
                                        <?php
                                        $room_status = '';
                                        $card_class = '';
                                        $icon = '';
                                        $status_text = '';
                                        
                                        if ($room['has_tenant'] > 0) {
                                            $card_class = 'border-danger';
                                            $room_status = 'occupied';
                                            $icon = 'bi-person-fill';
                                            $status_text = 'มีผู้เช่า';
                                        } else {
                                            switch ($room['room_status']) {
                                                case 'available':
                                                    $card_class = 'border-success';
                                                    $room_status = 'available';
                                                    $icon = 'bi-door-open';
                                                    $status_text = 'ว่าง';
                                                    break;
                                                case 'maintenance':
                                                    $card_class = 'border-warning';
                                                    $room_status = 'maintenance';
                                                    $icon = 'bi-tools';
                                                    $status_text = 'ปรับปรุง';
                                                    break;
                                            }
                                        }
                                        ?>
                                        <div class="col-xl-2 col-lg-3 col-md-4 col-sm-6">
                                            <div class="card h-100 <?php echo $card_class; ?>" style="min-height: 200px;">
                                                <div class="card-header text-center bg-light">
                                                    <h6 class="mb-0">ห้อง <?php echo $room['room_number']; ?></h6>
                                                    <small class="text-muted">
                                                        <?php
                                                        switch ($room['room_type']) {
                                                            case 'single': echo 'เดี่ยว'; break;
                                                            case 'double': echo 'คู่'; break;
                                                            case 'triple': echo 'สาม'; break;
                                                        }
                                                        ?>
                                                    </small>
                                                </div>
                                                <div class="card-body text-center d-flex flex-column">
                                                    <div class="mb-3">
                                                        <i class="<?php echo $icon; ?> fs-1 text-<?php echo $room_status == 'occupied' ? 'danger' : ($room_status == 'available' ? 'success' : 'warning'); ?>"></i>
                                                    </div>
                                                    
                                                    <div class="mb-2">
                                                        <span class="badge bg-<?php echo $room_status == 'occupied' ? 'danger' : ($room_status == 'available' ? 'success' : 'warning'); ?>">
                                                            <?php echo $status_text; ?>
                                                        </span>
                                                    </div>
                                                    
                                                    <div class="mb-2">
                                                        <small class="text-muted d-block">ค่าเช่า</small>
                                                        <strong><?php echo formatCurrency($room['monthly_rent']); ?></strong>
                                                    </div>
                                                    
                                                    <?php if ($room['has_tenant'] > 0): ?>
                                                        <div class="mb-2">
                                                            <small class="text-muted d-block">ผู้เช่า</small>
                                                            <small class="fw-bold"><?php echo $room['tenant_name']; ?></small>
                                                            <?php if ($room['tenant_phone']): ?>
                                                                <br><small class="text-muted"><?php echo $room['tenant_phone']; ?></small>
                                                            <?php endif; ?>
                                                        </div>
                                                        <div class="mb-2">
                                                            <small class="text-muted d-block">สิ้นสุดสัญญา</small>
                                                            <small><?php echo formatDate($room['contract_end_date']); ?></small>
                                                        </div>
                                                    <?php endif; ?>
                                                    
                                                    <div class="mt-auto">
                                                        <div class="btn-group w-100" role="group">
                                                            <a href="view_room.php?id=<?php echo $room['room_id']; ?>" 
                                                               class="btn btn-sm btn-outline-info" 
                                                               data-bs-toggle="tooltip" 
                                                               title="ดูรายละเอียด">
                                                                <i class="bi bi-eye"></i>
                                                            </a>
                                                            <?php if ($room['has_tenant'] == 0): ?>
                                                                <button type="button" 
                                                                        class="btn btn-sm btn-outline-warning" 
                                                                        data-bs-toggle="modal" 
                                                                        data-bs-target="#statusModal" 
                                                                        data-room-id="<?php echo $room['room_id']; ?>"
                                                                        data-room-number="<?php echo $room['room_number']; ?>"
                                                                        data-current-status="<?php echo $room['room_status']; ?>"
                                                                        title="เปลี่ยนสถานะ">
                                                                    <i class="bi bi-gear"></i>
                                                                </button>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal เปลี่ยนสถานะห้อง -->
<div class="modal fade" id="statusModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header">
                    <h5 class="modal-title">เปลี่ยนสถานะห้อง</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">ห้องหมายเลข:</label>
                        <strong id="room-number-display"></strong>
                    </div>
                    <div class="mb-3">
                        <label for="new_status" class="form-label">สถานะใหม่</label>
                        <select class="form-select" id="new_status" name="new_status" required>
                            <option value="available">ว่าง</option>
                            <option value="maintenance">ปรับปรุง</option>
                        </select>
                    </div>
                    <input type="hidden" id="room_id" name="room_id">
                    <input type="hidden" name="update_status" value="1">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ยกเลิก</button>
                    <button type="submit" class="btn btn-primary">บันทึก</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Handle status modal
    const statusModal = document.getElementById('statusModal');
    statusModal.addEventListener('show.bs.modal', function (event) {
        const button = event.relatedTarget;
        const roomId = button.getAttribute('data-room-id');
        const roomNumber = button.getAttribute('data-room-number');
        const currentStatus = button.getAttribute('data-current-status');
        
        document.getElementById('room_id').value = roomId;
        document.getElementById('room-number-display').textContent = roomNumber;
        document.getElementById('new_status').value = currentStatus;
    });
});
</script>

<style>
.card {
    transition: all 0.3s ease-in-out;
}

.card:hover {
    transform: translateY(-2px);
    box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15) !important;
}

.border-success {
    border-width: 2px !important;
}

.border-danger {
    border-width: 2px !important;
}

.border-warning {
    border-width: 2px !important;
}
</style>

<?php include 'includes/footer.php'; ?>