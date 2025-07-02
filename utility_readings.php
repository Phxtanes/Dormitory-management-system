<?php
$page_title = "บันทึกค่าน้ำค่าไฟ";
require_once 'includes/header.php';

// ตรวจสอบการบันทึกข้อมูล
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $room_id = $_POST['room_id'];
    $reading_month = $_POST['reading_month'];
    $water_current = floatval($_POST['water_current']);
    $electric_current = floatval($_POST['electric_current']);
    $water_unit_price = floatval($_POST['water_unit_price']);
    $electric_unit_price = floatval($_POST['electric_unit_price']);
    $reading_date = $_POST['reading_date'];
    
    try {
        // ดึงค่าก่อนหน้า
        $stmt = $pdo->prepare("SELECT water_current, electric_current FROM utility_readings WHERE room_id = ? AND reading_month < ? ORDER BY reading_month DESC LIMIT 1");
        $stmt->execute([$room_id, $reading_month]);
        $previous = $stmt->fetch();
        
        $water_previous = $previous ? $previous['water_current'] : 0;
        $electric_previous = $previous ? $previous['electric_current'] : 0;
        
        // ตรวจสอบข้อมูลซ้ำ
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM utility_readings WHERE room_id = ? AND reading_month = ?");
        $stmt->execute([$room_id, $reading_month]);
        $exists = $stmt->fetch()['count'];
        
        if ($exists > 0) {
            // อัพเดทข้อมูล
            $stmt = $pdo->prepare("UPDATE utility_readings SET water_previous = ?, water_current = ?, water_unit_price = ?, electric_previous = ?, electric_current = ?, electric_unit_price = ?, reading_date = ? WHERE room_id = ? AND reading_month = ?");
            $stmt->execute([$water_previous, $water_current, $water_unit_price, $electric_previous, $electric_current, $electric_unit_price, $reading_date, $room_id, $reading_month]);
            $success_message = "อัพเดทข้อมูลมิเตอร์เรียบร้อยแล้ว";
        } else {
            // เพิ่มข้อมูลใหม่
            $stmt = $pdo->prepare("INSERT INTO utility_readings (room_id, reading_month, water_previous, water_current, water_unit_price, electric_previous, electric_current, electric_unit_price, reading_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$room_id, $reading_month, $water_previous, $water_current, $water_unit_price, $electric_previous, $electric_current, $electric_unit_price, $reading_date]);
            $success_message = "บันทึกข้อมูลมิเตอร์เรียบร้อยแล้ว";
        }
        
    } catch(PDOException $e) {
        $error_message = "เกิดข้อผิดพลาด: " . $e->getMessage();
    }
}

// ตรวจสอบการลบข้อมูล
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    try {
        $stmt = $pdo->prepare("DELETE FROM utility_readings WHERE reading_id = ?");
        $stmt->execute([$_GET['delete']]);
        $success_message = "ลบข้อมูลมิเตอร์เรียบร้อยแล้ว";
    } catch(PDOException $e) {
        $error_message = "เกิดข้อผิดพลาด: " . $e->getMessage();
    }
}

// ดึงข้อมูลมิเตอร์
$search_month = isset($_GET['month']) ? $_GET['month'] : date('Y-m');
$search_room = isset($_GET['room']) ? $_GET['room'] : '';

$sql = "SELECT ur.*, r.room_number, r.room_type, r.floor_number,
        (ur.water_current - ur.water_previous) as water_usage,
        (ur.electric_current - ur.electric_previous) as electric_usage,
        (ur.water_current - ur.water_previous) * ur.water_unit_price as water_cost,
        (ur.electric_current - ur.electric_previous) * ur.electric_unit_price as electric_cost,
        (SELECT CONCAT(t.first_name, ' ', t.last_name) 
         FROM contracts c 
         JOIN tenants t ON c.tenant_id = t.tenant_id 
         WHERE c.room_id = ur.room_id AND c.contract_status = 'active' 
         LIMIT 1) as tenant_name
        FROM utility_readings ur
        JOIN rooms r ON ur.room_id = r.room_id
        WHERE 1=1";

$params = [];

if (!empty($search_month)) {
    $sql .= " AND ur.reading_month = ?";
    $params[] = $search_month;
}

if (!empty($search_room)) {
    $sql .= " AND r.room_number LIKE ?";
    $params[] = "%$search_room%";
}

$sql .= " ORDER BY ur.reading_month DESC, r.room_number";

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $readings = $stmt->fetchAll();
    
    // ดึงรายการห้องที่มีผู้เช่า
    $rooms_sql = "SELECT r.room_id, r.room_number, r.room_type, r.floor_number,
                  CONCAT(t.first_name, ' ', t.last_name) as tenant_name
                  FROM rooms r
                  JOIN contracts c ON r.room_id = c.room_id
                  JOIN tenants t ON c.tenant_id = t.tenant_id
                  WHERE c.contract_status = 'active'
                  ORDER BY r.room_number";
    
    $stmt = $pdo->query($rooms_sql);
    $active_rooms = $stmt->fetchAll();
    
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
                    <i class="bi bi-speedometer"></i>
                    บันทึกค่าน้ำค่าไฟ
                </h2>
                <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addReadingModal">
                    <i class="bi bi-plus-circle"></i>
                    บันทึกมิเตอร์
                </button>
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

            <!-- ฟอร์มค้นหา -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0">
                        <i class="bi bi-funnel"></i>
                        ค้นหาข้อมูล
                    </h5>
                </div>
                <div class="card-body">
                    <form method="GET" class="row g-3">
                        <div class="col-md-4">
                            <label for="month" class="form-label">เดือน</label>
                            <input type="month" class="form-control" id="month" name="month" 
                                   value="<?php echo htmlspecialchars($search_month); ?>">
                        </div>
                        <div class="col-md-4">
                            <label for="room" class="form-label">หมายเลขห้อง</label>
                            <input type="text" class="form-control" id="room" name="room" 
                                   value="<?php echo htmlspecialchars($search_room); ?>" 
                                   placeholder="หมายเลขห้อง">
                        </div>
                        <div class="col-md-4">
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

            <!-- ตารางแสดงข้อมูล -->
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">
                        <i class="bi bi-list"></i>
                        รายการบันทึกมิเตอร์
                        <?php if ($search_month): ?>
                            <span class="badge bg-primary"><?php echo thaiMonth(substr($search_month, 5, 2)) . ' ' . substr($search_month, 0, 4); ?></span>
                        <?php endif; ?>
                    </h5>
                    <span class="badge bg-primary"><?php echo count($readings); ?> รายการ</span>
                </div>
                <div class="card-body">
                    <?php if (empty($readings)): ?>
                        <div class="text-center py-5">
                            <i class="bi bi-speedometer display-1 text-muted"></i>
                            <h4 class="text-muted mt-3">ไม่พบข้อมูลมิเตอร์</h4>
                            <p class="text-muted">ยังไม่มีการบันทึกข้อมูลมิเตอร์สำหรับเดือนนี้</p>
                            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addReadingModal">
                                <i class="bi bi-plus-circle"></i>
                                บันทึกมิเตอร์
                            </button>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>ห้อง</th>
                                        <th>ผู้เช่า</th>
                                        <th>เดือน</th>
                                        <th class="text-center">น้ำ (หน่วย)</th>
                                        <th class="text-center">ไฟฟ้า (หน่วย)</th>
                                        <th class="text-end">ค่าน้ำ</th>
                                        <th class="text-end">ค่าไฟ</th>
                                        <th class="text-end">รวม</th>
                                        <th class="text-center">วันที่บันทึก</th>
                                        <th class="text-center">จัดการ</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($readings as $reading): ?>
                                        <tr>
                                            <td>
                                                <strong>ห้อง <?php echo $reading['room_number']; ?></strong>
                                                <br><small class="text-muted">ชั้น <?php echo $reading['floor_number']; ?></small>
                                            </td>
                                            <td>
                                                <?php if ($reading['tenant_name']): ?>
                                                    <?php echo $reading['tenant_name']; ?>
                                                <?php else: ?>
                                                    <span class="text-muted">ไม่มีผู้เช่า</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <strong><?php echo thaiMonth(substr($reading['reading_month'], 5, 2)); ?></strong>
                                                <br><small class="text-muted"><?php echo substr($reading['reading_month'], 0, 4); ?></small>
                                            </td>
                                            <td class="text-center">
                                                <div class="small">
                                                    <div class="text-muted">ก่อน: <?php echo number_format($reading['water_previous'], 2); ?></div>
                                                    <div class="fw-bold">ปัจจุบัน: <?php echo number_format($reading['water_current'], 2); ?></div>
                                                    <div class="text-primary">ใช้: <?php echo number_format($reading['water_usage'], 2); ?></div>
                                                </div>
                                            </td>
                                            <td class="text-center">
                                                <div class="small">
                                                    <div class="text-muted">ก่อน: <?php echo number_format($reading['electric_previous'], 2); ?></div>
                                                    <div class="fw-bold">ปัจจุบัน: <?php echo number_format($reading['electric_current'], 2); ?></div>
                                                    <div class="text-primary">ใช้: <?php echo number_format($reading['electric_usage'], 2); ?></div>
                                                </div>
                                            </td>
                                            <td class="text-end">
                                                <div><?php echo formatCurrency($reading['water_cost']); ?></div>
                                                <small class="text-muted"><?php echo $reading['water_unit_price']; ?> บาท/หน่วย</small>
                                            </td>
                                            <td class="text-end">
                                                <div><?php echo formatCurrency($reading['electric_cost']); ?></div>
                                                <small class="text-muted"><?php echo $reading['electric_unit_price']; ?> บาท/หน่วย</small>
                                            </td>
                                            <td class="text-end">
                                                <strong class="text-primary"><?php echo formatCurrency($reading['water_cost'] + $reading['electric_cost']); ?></strong>
                                            </td>
                                            <td class="text-center">
                                                <small><?php echo formatDate($reading['reading_date']); ?></small>
                                            </td>
                                            <td class="text-center">
                                                <div class="btn-group" role="group">
                                                    <button type="button" 
                                                            class="btn btn-sm btn-outline-warning" 
                                                            data-bs-toggle="modal" 
                                                            data-bs-target="#editReadingModal"
                                                            data-reading-id="<?php echo $reading['reading_id']; ?>"
                                                            data-room-id="<?php echo $reading['room_id']; ?>"
                                                            data-room-number="<?php echo $reading['room_number']; ?>"
                                                            data-reading-month="<?php echo $reading['reading_month']; ?>"
                                                            data-water-current="<?php echo $reading['water_current']; ?>"
                                                            data-electric-current="<?php echo $reading['electric_current']; ?>"
                                                            data-water-unit-price="<?php echo $reading['water_unit_price']; ?>"
                                                            data-electric-unit-price="<?php echo $reading['electric_unit_price']; ?>"
                                                            data-reading-date="<?php echo $reading['reading_date']; ?>"
                                                            title="แก้ไข">
                                                        <i class="bi bi-pencil"></i>
                                                    </button>
                                                    <a href="utility_readings.php?delete=<?php echo $reading['reading_id']; ?>" 
                                                       class="btn btn-sm btn-outline-danger" 
                                                       title="ลบ"
                                                       onclick="return confirmDelete('คุณต้องการลบข้อมูลมิเตอร์ห้อง <?php echo $reading['room_number']; ?> เดือน <?php echo thaiMonth(substr($reading['reading_month'], 5, 2)) . ' ' . substr($reading['reading_month'], 0, 4); ?> หรือไม่?')">
                                                        <i class="bi bi-trash"></i>
                                                    </a>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                                <tfoot>
                                    <tr class="table-light">
                                        <th colspan="5">รวมทั้งหมด</th>
                                        <th class="text-end"><?php echo formatCurrency(array_sum(array_column($readings, 'water_cost'))); ?></th>
                                        <th class="text-end"><?php echo formatCurrency(array_sum(array_column($readings, 'electric_cost'))); ?></th>
                                        <th class="text-end"><?php echo formatCurrency(array_sum(array_map(function($r) { return $r['water_cost'] + $r['electric_cost']; }, $readings))); ?></th>
                                        <th colspan="2"></th>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal เพิ่มข้อมูลมิเตอร์ -->
<div class="modal fade" id="addReadingModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bi bi-plus-circle"></i>
                        บันทึกมิเตอร์
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="room_id" class="form-label">ห้อง <span class="text-danger">*</span></label>
                            <select class="form-select" id="room_id" name="room_id" required>
                                <option value="">เลือกห้อง</option>
                                <?php foreach ($active_rooms as $room): ?>
                                    <option value="<?php echo $room['room_id']; ?>">
                                        ห้อง <?php echo $room['room_number']; ?> - <?php echo $room['tenant_name']; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="reading_month" class="form-label">เดือน <span class="text-danger">*</span></label>
                            <input type="month" class="form-control" id="reading_month" name="reading_month" 
                                   value="<?php echo date('Y-m'); ?>" required>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="water_current" class="form-label" style="color:#3399FF;">มิเตอร์น้ำ (หน่วย) <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" id="water_current" name="water_current" 
                                   step="0.01" min="0" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="electric_current" class="form-label" style="color:#FF9933;">มิเตอร์ไฟฟ้า (หน่วย) <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" id="electric_current" name="electric_current" 
                                   step="0.01" min="0" required>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="water_unit_price" class="form-label" style="color:#3399FF;">ราคาน้ำ (บาท/หน่วย)</label>
                            <input type="number" class="form-control" id="water_unit_price" name="water_unit_price" 
                                   step="0.01" min="0" value="25.00">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="electric_unit_price" class="form-label" style="color:#FF9933;">ราคาไฟฟ้า (บาท/หน่วย)</label>
                            <input type="number" class="form-control" id="electric_unit_price" name="electric_unit_price" 
                                   step="0.01" min="0" value="8.50">
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="reading_date" class="form-label">วันที่บันทึก <span class="text-danger">*</span></label>
                        <input type="date" class="form-control" id="reading_date" name="reading_date" 
                               value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="bi bi-x"></i>
                        ยกเลิก
                    </button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-circle"></i>
                        บันทึก
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal แก้ไขข้อมูลมิเตอร์ -->
<div class="modal fade" id="editReadingModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bi bi-pencil"></i>
                        แก้ไขข้อมูลมิเตอร์
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">ห้อง:</label>
                            <div class="fw-bold" id="edit-room-number"></div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="edit_reading_month" class="form-label">เดือน <span class="text-danger">*</span></label>
                            <input type="month" class="form-control" id="edit_reading_month" name="reading_month" required>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="edit_water_current" class="form-label">มิเตอร์น้ำ (หน่วย) <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" id="edit_water_current" name="water_current" 
                                   step="0.01" min="0" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="edit_electric_current" class="form-label">มิเตอร์ไฟฟ้า (หน่วย) <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" id="edit_electric_current" name="electric_current" 
                                   step="0.01" min="0" required>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="edit_water_unit_price" class="form-label">ราคาน้ำ (บาท/หน่วย)</label>
                            <input type="number" class="form-control" id="edit_water_unit_price" name="water_unit_price" 
                                   step="0.01" min="0">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="edit_electric_unit_price" class="form-label">ราคาไฟฟ้า (บาท/หน่วย)</label>
                            <input type="number" class="form-control" id="edit_electric_unit_price" name="electric_unit_price" 
                                   step="0.01" min="0">
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="edit_reading_date" class="form-label">วันที่บันทึก <span class="text-danger">*</span></label>
                        <input type="date" class="form-control" id="edit_reading_date" name="reading_date" required>
                    </div>
                    
                    <input type="hidden" id="edit_room_id" name="room_id">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="bi bi-x"></i>
                        ยกเลิก
                    </button>
                    <button type="submit" class="btn btn-warning">
                        <i class="bi bi-check-circle"></i>
                        อัพเดท
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Handle edit modal
    const editModal = document.getElementById('editReadingModal');
    editModal.addEventListener('show.bs.modal', function (event) {
        const button = event.relatedTarget;
        
        document.getElementById('edit_room_id').value = button.getAttribute('data-room-id');
        document.getElementById('edit-room-number').textContent = 'ห้อง ' + button.getAttribute('data-room-number');
        document.getElementById('edit_reading_month').value = button.getAttribute('data-reading-month');
        document.getElementById('edit_water_current').value = button.getAttribute('data-water-current');
        document.getElementById('edit_electric_current').value = button.getAttribute('data-electric-current');
        document.getElementById('edit_water_unit_price').value = button.getAttribute('data-water-unit-price');
        document.getElementById('edit_electric_unit_price').value = button.getAttribute('data-electric-unit-price');
        document.getElementById('edit_reading_date').value = button.getAttribute('data-reading-date');
    });
});
</script>

<?php include 'includes/footer.php'; ?>