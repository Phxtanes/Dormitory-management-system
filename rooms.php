<?php
$page_title = "จัดการห้องพัก";
require_once 'includes/header.php';

// ตรวจสอบการลบห้องพัก
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    try {
        // ตรวจสอบว่าห้องมีผู้เช่าอยู่หรือไม่
        $stmt = $pdo->prepare("SELECT COUNT(*) as contract_count FROM contracts WHERE room_id = ? AND contract_status = 'active'");
        $stmt->execute([$_GET['delete']]);
        $contract_count = $stmt->fetch()['contract_count'];
        
        if ($contract_count > 0) {
            $error_message = "ไม่สามารถลบห้องนี้ได้ เนื่องจากมีผู้เช่าอยู่";
        } else {
            $stmt = $pdo->prepare("DELETE FROM rooms WHERE room_id = ?");
            $stmt->execute([$_GET['delete']]);
            $success_message = "ลบข้อมูลห้องพักเรียบร้อยแล้ว";
        }
    } catch(PDOException $e) {
        $error_message = "เกิดข้อผิดพลาด: " . $e->getMessage();
    }
}

// ดึงข้อมูลห้องพักทั้งหมด
$search = isset($_GET['search']) ? $_GET['search'] : '';
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';

$sql = "SELECT r.*, 
        (SELECT COUNT(*) FROM contracts c WHERE c.room_id = r.room_id AND c.contract_status = 'active') as has_tenant,
        (SELECT CONCAT(t.first_name, ' ', t.last_name) 
         FROM contracts c 
         JOIN tenants t ON c.tenant_id = t.tenant_id 
         WHERE c.room_id = r.room_id AND c.contract_status = 'active' 
         LIMIT 1) as tenant_name
        FROM rooms r WHERE 1=1";

$params = [];

if (!empty($search)) {
    $sql .= " AND (r.room_number LIKE ? OR r.room_description LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if (!empty($status_filter)) {
    $sql .= " AND r.room_status = ?";
    $params[] = $status_filter;
}

$sql .= " ORDER BY r.room_number";

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rooms = $stmt->fetchAll();
} catch(PDOException $e) {
    $error_message = "เกิดข้อผิดพลาดในการดึงข้อมูล: " . $e->getMessage();
}
?>


<?php include 'includes/navbar.php'; ?>

<div class="container-fluid mt-4">
    <div class="row">
        <div class="col-12">
            <!-- หัวข้อหน้า -->
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2 class="mb-0">
                    <i class="bi bi-door-open"></i>
                    จัดการห้องพัก
                </h2>
                <a href="add_room.php" class="btn btn-primary">
                    <i class="bi bi-plus-circle"></i>
                    เพิ่มห้องพัก
                </a>
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

            <!-- ฟอร์มค้นหาและกรอง -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0">
                        <i class="bi bi-funnel"></i>
                        ค้นหาและกรองข้อมูล
                    </h5>
                </div>
                <div class="card-body">
                    <form method="GET" class="row g-3">
                        <div class="col-md-6">
                            <label for="search" class="form-label">ค้นหา</label>
                            <input type="text" class="form-control" id="search" name="search" 
                                   value="<?php echo htmlspecialchars($search); ?>" 
                                   placeholder="หมายเลขห้อง หรือ คำอธิบาย">
                        </div>
                        <div class="col-md-4">
                            <label for="status" class="form-label">สถานะห้อง</label>
                            <select class="form-select" id="status" name="status">
                                <option value="">ทั้งหมด</option>
                                <option value="available" <?php echo $status_filter == 'available' ? 'selected' : ''; ?>>ว่าง</option>
                                <option value="occupied" <?php echo $status_filter == 'occupied' ? 'selected' : ''; ?>>มีผู้เช่า</option>
                                <option value="maintenance" <?php echo $status_filter == 'maintenance' ? 'selected' : ''; ?>>ปรับปรุง</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">&nbsp;</label>
                            <div class="d-grid">
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-search"></i>
                                    ค้นหา
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <!-- ตารางแสดงข้อมูลห้องพัก -->
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">
                        <i class="bi bi-list"></i>
                        รายการห้องพัก
                    </h5>
                    <span class="badge bg-primary"><?php echo count($rooms); ?> ห้อง</span>
                </div>
                <div class="card-body">
                    <?php if (empty($rooms)): ?>
                        <div class="text-center py-5">
                            <i class="bi bi-inbox display-1 text-muted"></i>
                            <h4 class="text-muted mt-3">ไม่พบข้อมูลห้องพัก</h4>
                            <p class="text-muted">ยังไม่มีข้อมูลห้องพัก หรือไม่มีข้อมูลที่ตรงกับเงื่อนไขการค้นหา</p>
                            <a href="add_room.php" class="btn btn-primary">
                                <i class="bi bi-plus-circle"></i>
                                เพิ่มห้องพักแรก
                            </a>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>หมายเลขห้อง</th>
                                        <th>ประเภท</th>
                                        <th>ชั้น</th>
                                        <th>ค่าเช่า/เดือน</th>
                                        <th>เงินมัดจำ</th>
                                        <th>สถานะ</th>
                                        <th>ผู้เช่าปัจจุบัน</th>
                                        <th class="text-center">จัดการ</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($rooms as $room): ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo $room['room_number']; ?></strong>
                                            </td>
                                            <td>
                                                <?php
                                                switch ($room['room_type']) {
                                                    case 'single':
                                                        echo '<span class="badge bg-info">ห้องเดี่ยว</span>';
                                                        break;
                                                    case 'double':
                                                        echo '<span class="badge bg-warning">ห้องคู่</span>';
                                                        break;
                                                    case 'triple':
                                                        echo '<span class="badge bg-secondary">ห้องสาม</span>';
                                                        break;
                                                }
                                                ?>
                                            </td>
                                            <td>ชั้น <?php echo $room['floor_number']; ?></td>
                                            <td><?php echo formatCurrency($room['monthly_rent']); ?></td>
                                            <td><?php echo formatCurrency($room['deposit']); ?></td>
                                            <td>
                                                <?php
                                                $status_class = '';
                                                $status_text = '';
                                                $status_icon = '';
                                                
                                                if ($room['has_tenant'] > 0) {
                                                    $status_class = 'bg-danger';
                                                    $status_text = 'มีผู้เช่า';
                                                    $status_icon = 'bi-person-fill';
                                                } else {
                                                    switch ($room['room_status']) {
                                                        case 'available':
                                                            $status_class = 'bg-success';
                                                            $status_text = 'ว่าง';
                                                            $status_icon = 'bi-check-circle';
                                                            break;
                                                        case 'maintenance':
                                                            $status_class = 'bg-warning';
                                                            $status_text = 'ปรับปรุง';
                                                            $status_icon = 'bi-tools';
                                                            break;
                                                        default:
                                                            $status_class = 'bg-secondary';
                                                            $status_text = $room['room_status'];
                                                            $status_icon = 'bi-question-circle';
                                                    }
                                                }
                                                ?>
                                                <span class="badge <?php echo $status_class; ?>">
                                                    <i class="<?php echo $status_icon; ?>"></i>
                                                    <?php echo $status_text; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php if ($room['tenant_name']): ?>
                                                    <small><?php echo $room['tenant_name']; ?></small>
                                                <?php else: ?>
                                                    <span class="text-muted">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-center">
                                                <div class="btn-group" role="group">
                                                    <a href="view_room.php?id=<?php echo $room['room_id']; ?>" 
                                                       class="btn btn-sm btn-outline-info" 
                                                       data-bs-toggle="tooltip" 
                                                       title="ดูรายละเอียด">
                                                        <i class="bi bi-eye"></i>
                                                    </a>
                                                    <a href="edit_room.php?id=<?php echo $room['room_id']; ?>" 
                                                       class="btn btn-sm btn-outline-primary" 
                                                       data-bs-toggle="tooltip" 
                                                       title="แก้ไข">
                                                        <i class="bi bi-pencil"></i>
                                                    </a>
                                                    <?php if ($room['has_tenant'] == 0): ?>
                                                        <a href="rooms.php?delete=<?php echo $room['room_id']; ?>" 
                                                           class="btn btn-sm btn-outline-danger" 
                                                           data-bs-toggle="tooltip" 
                                                           title="ลบ"
                                                           onclick="return confirmDelete('คุณต้องการลบห้อง <?php echo $room['room_number']; ?> หรือไม่?')">
                                                            <i class="bi bi-trash"></i>
                                                        </a>
                                                    <?php endif; ?>
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
        </div>
    </div>
</div>

<style>
.table th {
    white-space: nowrap;
}

.btn-group .btn {
    margin: 0 1px;
}

.badge {
    font-size: 0.75rem;
}
</style>

<?php include 'includes/footer.php'; ?>