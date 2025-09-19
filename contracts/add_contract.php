<?php
$page_title = "สร้างสัญญาเช่าใหม่";
require_once 'includes/header.php';

$success_message = '';
$error_message = '';

// รับค่า tenant_id จาก URL (ถ้ามี)
$pre_selected_tenant = isset($_GET['tenant_id']) ? intval($_GET['tenant_id']) : 0;

// ตรวจสอบการส่งฟอร์ม
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $tenant_id = intval($_POST['tenant_id']);
    $room_id = intval($_POST['room_id']);
    $contract_start = $_POST['contract_start'];
    $contract_end = $_POST['contract_end'];
    $monthly_rent = floatval($_POST['monthly_rent']);
    $deposit_paid = floatval($_POST['deposit_paid']);
    $special_conditions = trim($_POST['special_conditions']);
    
    // ตรวจสอบข้อมูล
    $errors = [];
    
    if ($tenant_id <= 0) {
        $errors[] = "กรุณาเลือกผู้เช่า";
    }
    
    if ($room_id <= 0) {
        $errors[] = "กรุณาเลือกห้องพัก";
    }
    
    if (empty($contract_start)) {
        $errors[] = "กรุณากรอกวันที่เริ่มสัญญา";
    }
    
    if (empty($contract_end)) {
        $errors[] = "กรุณากรอกวันที่สิ้นสุดสัญญา";
    }
    
    if ($monthly_rent <= 0) {
        $errors[] = "กรุณากรอกค่าเช่าต่อเดือนที่ถูกต้อง";
    }
    
    if ($deposit_paid < 0) {
        $errors[] = "เงินมัดจำต้องไม่น้อยกว่า 0";
    }
    
    // ตรวจสอบวันที่
    if (!empty($contract_start) && !empty($contract_end)) {
        if (strtotime($contract_end) <= strtotime($contract_start)) {
            $errors[] = "วันที่สิ้นสุดสัญญาต้องมากกว่าวันที่เริ่มสัญญา";
        }
    }
    
    // ตรวจสอบว่าผู้เช่ามีสัญญาใช้งานอยู่แล้วหรือไม่
    if (empty($errors) && $tenant_id > 0) {
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM contracts WHERE tenant_id = ? AND contract_status = 'active'");
            $stmt->execute([$tenant_id]);
            $count = $stmt->fetch()['count'];
            
            if ($count > 0) {
                $errors[] = "ผู้เช่านี้มีสัญญาที่ใช้งานอยู่แล้ว";
            }
        } catch(PDOException $e) {
            $errors[] = "เกิดข้อผิดพลาดในการตรวจสอบข้อมูลผู้เช่า";
        }
    }
    
    // ตรวจสอบว่าห้องว่างหรือไม่
    if (empty($errors) && $room_id > 0) {
        try {
            $stmt = $pdo->prepare("
                SELECT r.room_status, 
                       (SELECT COUNT(*) FROM contracts c WHERE c.room_id = r.room_id AND c.contract_status = 'active') as active_contracts
                FROM rooms r 
                WHERE r.room_id = ?
            ");
            $stmt->execute([$room_id]);
            $room_data = $stmt->fetch();
            
            if (!$room_data) {
                $errors[] = "ไม่พบข้อมูลห้องที่เลือก";
            } elseif ($room_data['active_contracts'] > 0) {
                $errors[] = "ห้องที่เลือกมีผู้เช่าอยู่แล้ว";
            } elseif ($room_data['room_status'] == 'maintenance') {
                $errors[] = "ห้องที่เลือกอยู่ระหว่างการปรับปรุง ไม่สามารถให้เช่าได้";
            }
        } catch(PDOException $e) {
            $errors[] = "เกิดข้อผิดพลาดในการตรวจสอบข้อมูลห้อง";
        }
    }
    
    // บันทึกข้อมูล
    if (empty($errors)) {
        try {
            $pdo->beginTransaction();
            
            // สร้างสัญญาใหม่
            $stmt = $pdo->prepare("
                INSERT INTO contracts (tenant_id, room_id, contract_start, contract_end, monthly_rent, deposit_paid, special_conditions, contract_status) 
                VALUES (?, ?, ?, ?, ?, ?, ?, 'active')
            ");
            
            $stmt->execute([
                $tenant_id,
                $room_id,
                $contract_start,
                $contract_end,
                $monthly_rent,
                $deposit_paid,
                $special_conditions ?: null
            ]);
            
            $contract_id = $pdo->lastInsertId();
            
            // อัพเดทสถานะห้องเป็น "มีผู้เช่า"
            $stmt = $pdo->prepare("UPDATE rooms SET room_status = 'occupied' WHERE room_id = ?");
            $stmt->execute([$room_id]);
            
            $pdo->commit();
            
            $success_message = "สร้างสัญญาเช่าเรียบร้อยแล้ว";
            
            // รีเซ็ตฟอร์ม
            $_POST = [];
            $pre_selected_tenant = 0;
            
        } catch(PDOException $e) {
            $pdo->rollBack();
            $error_message = "เกิดข้อผิดพลาด: " . $e->getMessage();
        }
    } else {
        $error_message = implode('<br>', $errors);
    }
}

// ดึงข้อมูลผู้เช่าที่ไม่มีสัญญาใช้งานอยู่
try {
    $tenants_sql = "SELECT t.* FROM tenants t 
                   WHERE t.tenant_status = 'active' 
                   AND NOT EXISTS (SELECT 1 FROM contracts c WHERE c.tenant_id = t.tenant_id AND c.contract_status = 'active')
                   ORDER BY t.first_name, t.last_name";
    $stmt = $pdo->query($tenants_sql);
    $available_tenants = $stmt->fetchAll();
} catch(PDOException $e) {
    $available_tenants = [];
}

// ดึงข้อมูลห้องที่ว่าง
try {
    $rooms_sql = "SELECT r.* FROM rooms r 
                 WHERE r.room_status = 'available' 
                 AND NOT EXISTS (SELECT 1 FROM contracts c WHERE c.room_id = r.room_id AND c.contract_status = 'active')
                 ORDER BY r.floor_number, CAST(SUBSTRING(r.room_number, 2) AS UNSIGNED)";
    $stmt = $pdo->query($rooms_sql);
    $available_rooms = $stmt->fetchAll();
} catch(PDOException $e) {
    $available_rooms = [];
}

// ดึงข้อมูลผู้เช่าที่เลือกล่วงหน้า (ถ้ามี)
$selected_tenant = null;
if ($pre_selected_tenant > 0) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM tenants WHERE tenant_id = ? AND tenant_status = 'active'");
        $stmt->execute([$pre_selected_tenant]);
        $selected_tenant = $stmt->fetch();
        
        // ตรวจสอบว่าผู้เช่านี้ไม่มีสัญญาใช้งานอยู่
        if ($selected_tenant) {
            $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM contracts WHERE tenant_id = ? AND contract_status = 'active'");
            $stmt->execute([$pre_selected_tenant]);
            $has_active_contract = $stmt->fetch()['count'] > 0;
            
            if ($has_active_contract) {
                $selected_tenant = null;
                $error_message = "ผู้เช่าที่เลือกมีสัญญาที่ใช้งานอยู่แล้ว";
            }
        }
    } catch(PDOException $e) {
        $selected_tenant = null;
    }
}
?>

<?php include 'includes/navbar.php'; ?>

<div class="container-fluid mt-4">
    <div class="row justify-content-center">
        <div class="col-xl-10">
            <!-- หัวข้อหน้า -->
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2 class="mb-0">
                    <i class="bi bi-file-earmark-plus"></i>
                    สร้างสัญญาเช่าใหม่
                </h2>
                <a href="contracts.php" class="btn btn-secondary">
                    <i class="bi bi-arrow-left"></i>
                    กลับไปรายการสัญญา
                </a>
            </div>

            <!-- แสดงข้อความแจ้งเตือน -->
            <?php if (!empty($success_message)): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="bi bi-check-circle"></i>
                    <?php echo $success_message; ?>
                    <div class="mt-2">
                        <a href="contracts.php" class="btn btn-sm btn-outline-success me-2">
                            <i class="bi bi-list"></i>
                            ดูรายการสัญญา
                        </a>
                        <?php if (isset($contract_id)): ?>
                            <a href="view_contract.php?id=<?php echo $contract_id; ?>" class="btn btn-sm btn-outline-primary me-2">
                                <i class="bi bi-eye"></i>
                                ดูรายละเอียดสัญญา
                            </a>
                            <a href="print_contract.php?id=<?php echo $contract_id; ?>" class="btn btn-sm btn-outline-secondary" target="_blank">
                                <i class="bi bi-printer"></i>
                                พิมพ์สัญญา
                            </a>
                        <?php endif; ?>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php if (!empty($error_message)): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="bi bi-exclamation-triangle"></i>
                    <?php echo $error_message; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <!-- ตรวจสอบความพร้อม -->
            <?php if (empty($available_tenants) || empty($available_rooms)): ?>
                <div class="alert alert-warning" role="alert">
                    <h5 class="alert-heading">
                        <i class="bi bi-exclamation-triangle"></i>
                        ไม่สามารถสร้างสัญญาได้
                    </h5>
                    <p class="mb-3">เนื่องจาก:</p>
                    <ul class="mb-3">
                        <?php if (empty($available_tenants)): ?>
                            <li>ไม่มีผู้เช่าที่สามารถทำสัญญาได้ (ผู้เช่าทุกคนมีสัญญาใช้งานอยู่แล้ว หรือไม่มีผู้เช่าในระบบ)</li>
                        <?php endif; ?>
                        <?php if (empty($available_rooms)): ?>
                            <li>ไม่มีห้องว่างที่สามารถให้เช่าได้</li>
                        <?php endif; ?>
                    </ul>
                    <div>
                        <?php if (empty($available_tenants)): ?>
                            <a href="add_tenant.php" class="btn btn-primary me-2">
                                <i class="bi bi-person-plus"></i>
                                เพิ่มผู้เช่าใหม่
                            </a>
                        <?php endif; ?>
                        <?php if (empty($available_rooms)): ?>
                            <a href="add_room.php" class="btn btn-success">
                                <i class="bi bi-plus-circle"></i>
                                เพิ่มห้องพัก
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php else: ?>
                <!-- ฟอร์มสร้างสัญญา -->
                <form method="POST" class="needs-validation" novalidate>
                    <div class="row">
                        <!-- ข้อมูลผู้เช่า -->
                        <div class="col-md-6">
                            <div class="card mb-4">
                                <div class="card-header">
                                    <h5 class="mb-0">
                                        <i class="bi bi-person-fill"></i>
                                        ข้อมูลผู้เช่า
                                    </h5>
                                </div>
                                <div class="card-body">
                                    <div class="mb-3">
                                        <label for="tenant_id" class="form-label">
                                            เลือกผู้เช่า <span class="text-danger">*</span>
                                        </label>
                                        <select class="form-select" id="tenant_id" name="tenant_id" required <?php echo $selected_tenant ? 'disabled' : ''; ?>>
                                            <option value="">เลือกผู้เช่า</option>
                                            <?php foreach ($available_tenants as $tenant): ?>
                                                <option value="<?php echo $tenant['tenant_id']; ?>" 
                                                        <?php echo ($selected_tenant && $selected_tenant['tenant_id'] == $tenant['tenant_id']) || 
                                                                  (!$selected_tenant && isset($_POST['tenant_id']) && $_POST['tenant_id'] == $tenant['tenant_id']) ? 'selected' : ''; ?>
                                                        data-phone="<?php echo htmlspecialchars($tenant['phone']); ?>"
                                                        data-email="<?php echo htmlspecialchars($tenant['email']); ?>"
                                                        data-id-card="<?php echo htmlspecialchars($tenant['id_card']); ?>">
                                                    <?php echo $tenant['first_name'] . ' ' . $tenant['last_name']; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <?php if ($selected_tenant): ?>
                                            <input type="hidden" name="tenant_id" value="<?php echo $selected_tenant['tenant_id']; ?>">
                                        <?php endif; ?>
                                        <div class="invalid-feedback">
                                            กรุณาเลือกผู้เช่า
                                        </div>
                                    </div>
                                    
                                    <!-- แสดงข้อมูลผู้เช่าที่เลือก -->
                                    <div id="tenant-info" class="border rounded p-3 bg-light" style="display: none;">
                                        <h6 class="text-primary mb-2">ข้อมูลผู้เช่า</h6>
                                        <div class="row">
                                            <div class="col-sm-6">
                                                <small class="text-muted">โทรศัพท์:</small>
                                                <div id="tenant-phone" class="fw-bold"></div>
                                            </div>
                                            <div class="col-sm-6">
                                                <small class="text-muted">อีเมล:</small>
                                                <div id="tenant-email" class="fw-bold"></div>
                                            </div>
                                        </div>
                                        <div class="mt-2">
                                            <small class="text-muted">เลขบัตรประชาชน:</small>
                                            <div id="tenant-id-card" class="fw-bold font-monospace"></div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- ข้อมูลห้องพัก -->
                        <div class="col-md-6">
                            <div class="card mb-4">
                                <div class="card-header">
                                    <h5 class="mb-0">
                                        <i class="bi bi-door-open"></i>
                                        ข้อมูลห้องพัก
                                    </h5>
                                </div>
                                <div class="card-body">
                                    <div class="mb-3">
                                        <label for="room_id" class="form-label">
                                            เลือกห้องพัก <span class="text-danger">*</span>
                                        </label>
                                        <select class="form-select" id="room_id" name="room_id" required>
                                            <option value="">เลือกห้องพัก</option>
                                            <?php foreach ($available_rooms as $room): ?>
                                                <option value="<?php echo $room['room_id']; ?>" 
                                                        <?php echo (isset($_POST['room_id']) && $_POST['room_id'] == $room['room_id']) ? 'selected' : ''; ?>
                                                        data-rent="<?php echo $room['monthly_rent']; ?>"
                                                        data-deposit="<?php echo $room['deposit']; ?>"
                                                        data-type="<?php echo $room['room_type']; ?>"
                                                        data-floor="<?php echo $room['floor_number']; ?>"
                                                        data-description="<?php echo htmlspecialchars($room['room_description']); ?>">
                                                    ห้อง <?php echo $room['room_number']; ?> - 
                                                    <?php
                                                    switch ($room['room_type']) {
                                                        case 'single': echo 'เดี่ยว'; break;
                                                        case 'double': echo 'คู่'; break;
                                                        case 'triple': echo 'สาม'; break;
                                                    }
                                                    ?>
                                                    (<?php echo formatCurrency($room['monthly_rent']); ?>)
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <div class="invalid-feedback">
                                            กรุณาเลือกห้องพัก
                                        </div>
                                    </div>
                                    
                                    <!-- แสดงข้อมูลห้องที่เลือก -->
                                    <div id="room-info" class="border rounded p-3 bg-light" style="display: none;">
                                        <h6 class="text-primary mb-2">ข้อมูลห้องพัก</h6>
                                        <div class="row">
                                            <div class="col-sm-6">
                                                <small class="text-muted">ประเภท:</small>
                                                <div id="room-type" class="fw-bold"></div>
                                            </div>
                                            <div class="col-sm-6">
                                                <small class="text-muted">ชั้น:</small>
                                                <div id="room-floor" class="fw-bold"></div>
                                            </div>
                                        </div>
                                        <div class="row mt-2">
                                            <div class="col-sm-6">
                                                <small class="text-muted">ค่าเช่า/เดือน:</small>
                                                <div id="room-rent" class="fw-bold text-primary"></div>
                                            </div>
                                            <div class="col-sm-6">
                                                <small class="text-muted">เงินมัดจำ:</small>
                                                <div id="room-deposit" class="fw-bold text-info"></div>
                                            </div>
                                        </div>
                                        <div class="mt-2">
                                            <small class="text-muted">รายละเอียด:</small>
                                            <div id="room-description" class="text-muted"></div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ข้อมูลสัญญา -->
                    <div class="card mb-4">
                        <div class="card-header">
                            <h5 class="mb-0">
                                <i class="bi bi-file-earmark-text"></i>
                                ข้อมูลสัญญา
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-3 mb-3">
                                    <label for="contract_start" class="form-label">
                                        วันที่เริ่มสัญญา <span class="text-danger">*</span>
                                    </label>
                                    <input type="date" class="form-control" id="contract_start" name="contract_start" 
                                           value="<?php echo isset($_POST['contract_start']) ? $_POST['contract_start'] : date('Y-m-d'); ?>" 
                                           required>
                                    <div class="invalid-feedback">
                                        กรุณากรอกวันที่เริ่มสัญญา
                                    </div>
                                </div>

                                <div class="col-md-3 mb-3">
                                    <label for="contract_end" class="form-label">
                                        วันที่สิ้นสุดสัญญา <span class="text-danger">*</span>
                                    </label>
                                    <input type="date" class="form-control" id="contract_end" name="contract_end" 
                                           value="<?php echo isset($_POST['contract_end']) ? $_POST['contract_end'] : date('Y-m-d', strtotime('+1 year')); ?>" 
                                           required>
                                    <div class="invalid-feedback">
                                        กรุณากรอกวันที่สิ้นสุดสัญญา
                                    </div>
                                </div>

                                <div class="col-md-3 mb-3">
                                    <label for="monthly_rent" class="form-label">
                                        ค่าเช่าต่อเดือน (บาท) <span class="text-danger">*</span>
                                    </label>
                                    <input type="number" class="form-control" id="monthly_rent" name="monthly_rent" 
                                           value="<?php echo isset($_POST['monthly_rent']) ? $_POST['monthly_rent'] : ''; ?>" 
                                           min="0" step="0.01" required>
                                    <div class="invalid-feedback">
                                        กรุณากรอกค่าเช่าที่ถูกต้อง
                                    </div>
                                </div>

                                <div class="col-md-3 mb-3">
                                    <label for="deposit_paid" class="form-label">
                                        เงินมัดจำที่รับ (บาท)
                                    </label>
                                    <input type="number" class="form-control" id="deposit_paid" name="deposit_paid" 
                                           value="<?php echo isset($_POST['deposit_paid']) ? $_POST['deposit_paid'] : ''; ?>" 
                                           min="0" step="0.01">
                                    <small class="form-text text-muted">หากไม่มีให้ใส่ 0</small>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-12 mb-3">
                                    <label for="special_conditions" class="form-label">
                                        เงื่อนไขพิเศษ
                                    </label>
                                    <textarea class="form-control" id="special_conditions" name="special_conditions" 
                                              rows="4" placeholder="เงื่อนไขพิเศษ หรือ ข้อตกลงเพิ่มเติม (ถ้ามี)"><?php echo isset($_POST['special_conditions']) ? htmlspecialchars($_POST['special_conditions']) : ''; ?></textarea>
                                </div>
                            </div>

                            <!-- แสดงข้อมูลสรุป -->
                            <div class="card bg-light">
                                <div class="card-header">
                                    <h6 class="mb-0">
                                        <i class="bi bi-calculator"></i>
                                        สรุปข้อมูลสัญญา
                                    </h6>
                                </div>
                                <div class="card-body">
                                    <div class="row text-center">
                                        <div class="col-md-3">
                                            <div class="border rounded p-3 h-100">
                                                <i class="bi bi-calendar fs-4 text-primary"></i>
                                                <h6 class="mt-2">ระยะเวลาสัญญา</h6>
                                                <p class="text-primary mb-0" id="contract-duration">- เดือน</p>
                                            </div>
                                        </div>
                                        <div class="col-md-3">
                                            <div class="border rounded p-3 h-100">
                                                <i class="bi bi-currency-dollar fs-4 text-success"></i>
                                                <h6 class="mt-2">ค่าเช่าต่อเดือน</h6>
                                                <p class="text-success mb-0" id="contract-rent">0.00 บาท</p>
                                            </div>
                                        </div>
                                        <div class="col-md-3">
                                            <div class="border rounded p-3 h-100">
                                                <i class="bi bi-piggy-bank fs-4 text-info"></i>
                                                <h6 class="mt-2">เงินมัดจำ</h6>
                                                <p class="text-info mb-0" id="contract-deposit">0.00 บาท</p>
                                            </div>
                                        </div>
                                        <div class="col-md-3">
                                            <div class="border rounded p-3 h-100">
                                                <i class="bi bi-cash-stack fs-4 text-warning"></i>
                                                <h6 class="mt-2">รายได้รวมทั้งสัญญา</h6>
                                                <p class="text-warning mb-0" id="total-income">0.00 บาท</p>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ปุ่มบันทึก -->
                    <div class="card">
                        <div class="card-body">
                            <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                                <a href="contracts.php" class="btn btn-secondary me-md-2">
                                    <i class="bi bi-x-circle"></i>
                                    ยกเลิก
                                </a>
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-check-circle"></i>
                                    สร้างสัญญา
                                </button>
                            </div>
                        </div>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Bootstrap validation
    const forms = document.querySelectorAll('.needs-validation');
    Array.from(forms).forEach(form => {
        form.addEventListener('submit', event => {
            if (!form.checkValidity()) {
                event.preventDefault();
                event.stopPropagation();
            }
            form.classList.add('was-validated');
        });
    });
    
    // ฟังก์ชันแสดงข้อมูลผู้เช่า
    function showTenantInfo() {
        const tenantSelect = document.getElementById('tenant_id');
        const tenantInfo = document.getElementById('tenant-info');
        
        if (tenantSelect.value) {
            const selectedOption = tenantSelect.options[tenantSelect.selectedIndex];
            document.getElementById('tenant-phone').textContent = selectedOption.dataset.phone || '-';
            document.getElementById('tenant-email').textContent = selectedOption.dataset.email || '-';
            document.getElementById('tenant-id-card').textContent = selectedOption.dataset.idCard || '-';
            tenantInfo.style.display = 'block';
        } else {
            tenantInfo.style.display = 'none';
        }
    }
    
    // ฟังก์ชันแสดงข้อมูลห้องพัก
    function showRoomInfo() {
        const roomSelect = document.getElementById('room_id');
        const roomInfo = document.getElementById('room-info');
        const monthlyRentInput = document.getElementById('monthly_rent');
        const depositInput = document.getElementById('deposit_paid');
        
        if (roomSelect.value) {
            const selectedOption = roomSelect.options[roomSelect.selectedIndex];
            const rent = parseFloat(selectedOption.dataset.rent) || 0;
            const deposit = parseFloat(selectedOption.dataset.deposit) || 0;
            
            // แสดงข้อมูลห้อง
            document.getElementById('room-type').textContent = getRoomTypeText(selectedOption.dataset.type);
            document.getElementById('room-floor').textContent = 'ชั้น ' + selectedOption.dataset.floor;
            document.getElementById('room-rent').textContent = formatCurrency(rent);
            document.getElementById('room-deposit').textContent = formatCurrency(deposit);
            document.getElementById('room-description').textContent = selectedOption.dataset.description || 'ไม่มีรายละเอียด';
            
            // อัพเดทค่าในฟอร์ม
            monthlyRentInput.value = rent;
            if (depositInput.value === '' || depositInput.value === '0') {
                depositInput.value = deposit;
            }
            
            roomInfo.style.display = 'block';
            updateContractSummary();
        } else {
            roomInfo.style.display = 'none';
            monthlyRentInput.value = '';
            if (depositInput.value === document.getElementById('room_id').dataset.lastDeposit) {
                depositInput.value = '';
            }
        }
    }
    
    // ฟังก์ชันอัพเดทสรุปสัญญา
    function updateContractSummary() {
        const startDate = document.getElementById('contract_start').value;
        const endDate = document.getElementById('contract_end').value;
        const monthlyRent = parseFloat(document.getElementById('monthly_rent').value) || 0;
        const deposit = parseFloat(document.getElementById('deposit_paid').value) || 0;
        
        // คำนวณระยะเวลาสัญญา
        let duration = 0;
        if (startDate && endDate) {
            const start = new Date(startDate);
            const end = new Date(endDate);
            const diffTime = Math.abs(end - start);
            const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24));
            duration = Math.round(diffDays / 30.44); // เฉลี่ยวันต่อเดือน
        }
        
        // คำนวณรายได้รวม
        const totalIncome = monthlyRent * duration;
        
        // อัพเดทการแสดงผล
        document.getElementById('contract-duration').textContent = duration + ' เดือน';
        document.getElementById('contract-rent').textContent = formatCurrency(monthlyRent);
        document.getElementById('contract-deposit').textContent = formatCurrency(deposit);
        document.getElementById('total-income').textContent = formatCurrency(totalIncome);
    }
    
    // ฟังก์ชันช่วยเหลือ
    function formatCurrency(amount) {
        return new Intl.NumberFormat('th-TH', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        }).format(amount) + ' บาท';
    }
    
    function getRoomTypeText(type) {
        switch (type) {
            case 'single': return 'ห้องเดี่ยว';
            case 'double': return 'ห้องคู่';
            case 'triple': return 'ห้องสาม';
            default: return type;
        }
    }
    
    // Event listeners
    document.getElementById('tenant_id').addEventListener('change', showTenantInfo);
    document.getElementById('room_id').addEventListener('change', showRoomInfo);
    document.getElementById('contract_start').addEventListener('change', updateContractSummary);
    document.getElementById('contract_end').addEventListener('change', updateContractSummary);
    document.getElementById('monthly_rent').addEventListener('input', updateContractSummary);
    document.getElementById('deposit_paid').addEventListener('input', updateContractSummary);
    
    // การตรวจสอบวันที่
    const startDateInput = document.getElementById('contract_start');
    const endDateInput = document.getElementById('contract_end');
    
    startDateInput.addEventListener('change', function() {
        if (this.value) {
            // ตั้งค่าวันที่สิ้นสุดขั้นต่ำเป็นวันถัดไปจากวันเริ่มต้น
            const startDate = new Date(this.value);
            startDate.setDate(startDate.getDate() + 1);
            endDateInput.min = startDate.toISOString().split('T')[0];
            
            // ถ้าวันสิ้นสุดน้อยกว่าหรือเท่ากับวันเริ่มต้น ให้ตั้งค่าใหม่
            if (endDateInput.value && new Date(endDateInput.value) <= new Date(this.value)) {
                const defaultEnd = new Date(this.value);
                defaultEnd.setFullYear(defaultEnd.getFullYear() + 1);
                endDateInput.value = defaultEnd.toISOString().split('T')[0];
            }
        }
    });
    
    // เรียกใช้ฟังก์ชันเริ่มต้น
    showTenantInfo();
    showRoomInfo();
    updateContractSummary();
    
    // ตั้งค่าวันที่ขั้นต่ำเป็นวันนี้
    const today = new Date().toISOString().split('T')[0];
    startDateInput.min = today;
    
    // การตรวจสอบเพิ่มเติมก่อนส่งฟอร์ม
    document.querySelector('form').addEventListener('submit', function(e) {
        const startDate = new Date(document.getElementById('contract_start').value);
        const endDate = new Date(document.getElementById('contract_end').value);
        const monthlyRent = parseFloat(document.getElementById('monthly_rent').value) || 0;
        
        // ตรวจสอบวันที่
        if (endDate <= startDate) {
            e.preventDefault();
            alert('วันที่สิ้นสุดสัญญาต้องมากกว่าวันที่เริ่มสัญญา');
            return false;
        }
        
        // ตรวจสอบค่าเช่า
        if (monthlyRent <= 0) {
            e.preventDefault();
            alert('กรุณากรอกค่าเช่าที่ถูกต้อง');
            return false;
        }
        
        // ยืนยันการบันทึก
        const tenantName = document.getElementById('tenant_id').options[document.getElementById('tenant_id').selectedIndex].text;
        const roomNumber = document.getElementById('room_id').options[document.getElementById('room_id').selectedIndex].text;
        
        if (!confirm(`คุณต้องการสร้างสัญญาเช่าสำหรับ\n\nผู้เช่า: ${tenantName}\n${roomNumber}\nระยะเวลา: ${document.getElementById('contract-duration').textContent}\n\nใช่หรือไม่?`)) {
            e.preventDefault();
            return false;
        }
    });
    
    // เพิ่ม tooltip
    const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    const tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
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

.form-select:focus,
.form-control:focus {
    border-color: #0d6efd;
    box-shadow: 0 0 0 0.2rem rgba(13, 110, 253, 0.25);
}

.invalid-feedback {
    display: block;
}

.font-monospace {
    font-family: 'Courier New', monospace;
}

.bg-light {
    background-color: #f8f9fa !important;
}

#tenant-info,
#room-info {
    border: 1px solid #e9ecef !important;
    background-color: #f8f9fa !important;
}

.border {
    border: 1px solid #dee2e6 !important;
}

.rounded {
    border-radius: 0.375rem !important;
}

@media (max-width: 768px) {
    .col-md-3 {
        margin-bottom: 1rem;
    }
    
    .d-md-flex {
        flex-direction: column;
    }
    
    .me-md-2 {
        margin-right: 0 !important;
        margin-bottom: 0.5rem;
    }
}

/* การปรับปรุงการแสดงผลในโหมดพิมพ์ */
@media print {
    .btn, .alert {
        display: none !important;
    }
    
    .card {
        border: 1px solid #000 !important;
        box-shadow: none !important;
        page-break-inside: avoid;
    }
    
    .card-header {
        background-color: #f8f9fa !important;
        -webkit-print-color-adjust: exact;
    }
}

/* Animation สำหรับการแสดง/ซ่อนข้อมูล */
#tenant-info,
#room-info {
    animation: fadeIn 0.3s ease-in-out;
}

@keyframes fadeIn {
    from {
        opacity: 0;
        transform: translateY(-10px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

/* สไตล์สำหรับการแสดงข้อมูลสรุป */
.card.bg-light .card-body .border {
    background-color: #fff;
    transition: all 0.3s ease;
}

.card.bg-light .card-body .border:hover {
    transform: translateY(-2px);
    box-shadow: 0 0.25rem 0.5rem rgba(0, 0, 0, 0.1);
}

/* ปรับปรุงสีของ badge และ button */
.text-primary {
    color: #0d6efd !important;
}

.text-success {
    color: #198754 !important;
}

.text-info {
    color: #0dcaf0 !important;
}

.text-warning {
    color: #ffc107 !important;
}

/* สไตล์สำหรับฟอร์มที่ validated */
.was-validated .form-select:valid,
.was-validated .form-control:valid {
    border-color: #198754;
    padding-right: calc(1.5em + 0.75rem);
    background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 8 8'%3e%3cpath fill='%23198754' d='m2.3 6.73.98-.98 2.49-2.49c.15-.15.15-.39 0-.54s-.39-.15-.54 0L3.26 5.17l-.98-.98c-.15-.15-.39-.15-.54 0s-.15.39 0 .54z'/%3e%3c/svg%3e");
    background-repeat: no-repeat;
    background-position: right calc(0.375em + 0.1875rem) center;
    background-size: calc(0.75em + 0.375rem) calc(0.75em + 0.375rem);
}

.was-validated .form-select:invalid,
.was-validated .form-control:invalid {
    border-color: #dc3545;
    padding-right: calc(1.5em + 0.75rem);
    background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 12 12' width='12' height='12' fill='none' stroke='%23dc3545'%3e%3ccircle cx='6' cy='6' r='4.5'/%3e%3cpath d='m5.8 4.6 2.4 2.4'/%3e%3cpath d='m8.2 4.6-2.4 2.4'/%3e%3c/svg%3e");
    background-repeat: no-repeat;
    background-position: right calc(0.375em + 0.1875rem) center;
    background-size: calc(0.75em + 0.375rem) calc(0.75em + 0.375rem);
}
</style>

<?php include 'includes/footer.php'; ?>