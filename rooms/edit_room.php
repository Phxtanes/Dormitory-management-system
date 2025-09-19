<?php
$page_title = "แก้ไขข้อมูลห้องพัก";
require_once 'includes/header.php';

$success_message = '';
$error_message = '';
$room = null;

// ตรวจสอบ ID ห้องพัก
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header('Location: rooms.php');
    exit;
}

$room_id = $_GET['id'];

// ดึงข้อมูลห้องพัก
try {
    $stmt = $pdo->prepare("
        SELECT r.*, 
        (SELECT COUNT(*) FROM contracts c WHERE c.room_id = r.room_id AND c.contract_status = 'active') as has_active_contract,
        (SELECT CONCAT(t.first_name, ' ', t.last_name) 
         FROM contracts c 
         JOIN tenants t ON c.tenant_id = t.tenant_id 
         WHERE c.room_id = r.room_id AND c.contract_status = 'active' 
         LIMIT 1) as tenant_name,
        (SELECT c.contract_start 
         FROM contracts c 
         WHERE c.room_id = r.room_id AND c.contract_status = 'active' 
         LIMIT 1) as contract_start_date,
        (SELECT c.contract_end 
         FROM contracts c 
         WHERE c.room_id = r.room_id AND c.contract_status = 'active' 
         LIMIT 1) as contract_end_date
        FROM rooms r 
        WHERE r.room_id = ?
    ");
    $stmt->execute([$room_id]);
    $room = $stmt->fetch();
    
    if (!$room) {
        header('Location: rooms.php');
        exit;
    }
} catch(PDOException $e) {
    $error_message = "เกิดข้อผิดพลาดในการดึงข้อมูล: " . $e->getMessage();
}

// ตรวจสอบการส่งฟอร์ม
if ($_SERVER['REQUEST_METHOD'] == 'POST' && $room) {
    $room_number = trim($_POST['room_number']);
    $room_type = $_POST['room_type'];
    $monthly_rent = floatval($_POST['monthly_rent']);
    $deposit = floatval($_POST['deposit']);
    $floor_number = intval($_POST['floor_number']);
    $room_description = trim($_POST['room_description']);
    $room_status = $_POST['room_status'];
    
    // ตรวจสอบข้อมูล
    $errors = [];
    
    if (empty($room_number)) {
        $errors[] = "กรุณากรอกหมายเลขห้อง";
    }
    
    if ($monthly_rent <= 0) {
        $errors[] = "กรุณากรอกค่าเช่าที่ถูกต้อง";
    }
    
    if ($deposit < 0) {
        $errors[] = "เงินมัดจำต้องไม่น้อยกว่า 0";
    }
    
    if ($floor_number <= 0) {
        $errors[] = "กรุณากรอกหมายเลขชั้นที่ถูกต้อง";
    }
    
    // ตรวจสอบหมายเลขห้องซ้ำ (ยกเว้นห้องปัจจุบัน)
    if (empty($errors)) {
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM rooms WHERE room_number = ? AND room_id != ?");
            $stmt->execute([$room_number, $room_id]);
            $count = $stmt->fetch()['count'];
            
            if ($count > 0) {
                $errors[] = "หมายเลขห้อง $room_number มีอยู่ในระบบแล้ว";
            }
        } catch(PDOException $e) {
            $errors[] = "เกิดข้อผิดพลาดในการตรวจสอบข้อมูล";
        }
    }
    
    // ตรวจสอบการเปลี่ยนสถานะห้องที่มีผู้เช่า
    if (empty($errors) && $room['has_active_contract'] > 0) {
        if ($room_status != 'occupied' && $room_status != $room['room_status']) {
            $errors[] = "ไม่สามารถเปลี่ยนสถานะห้องที่มีผู้เช่าได้";
        }
        // กำหนดสถานะเป็น occupied อัตโนมัติสำหรับห้องที่มีผู้เช่า
        $room_status = 'occupied';
    }
    
    // อัปเดตข้อมูล
    if (empty($errors)) {
        try {
            $stmt = $pdo->prepare("
                UPDATE rooms 
                SET room_number = ?, room_type = ?, monthly_rent = ?, deposit = ?, 
                    floor_number = ?, room_description = ?, room_status = ?
                WHERE room_id = ?
            ");
            
            $stmt->execute([
                $room_number,
                $room_type,
                $monthly_rent,
                $deposit,
                $floor_number,
                $room_description,
                $room_status,
                $room_id
            ]);
            
            $success_message = "อัปเดตข้อมูลห้องพัก $room_number เรียบร้อยแล้ว";
            
            // รีเฟรชข้อมูลห้อง
            $stmt = $pdo->prepare("
                SELECT r.*, 
                (SELECT COUNT(*) FROM contracts c WHERE c.room_id = r.room_id AND c.contract_status = 'active') as has_active_contract,
                (SELECT CONCAT(t.first_name, ' ', t.last_name) 
                 FROM contracts c 
                 JOIN tenants t ON c.tenant_id = t.tenant_id 
                 WHERE c.room_id = r.room_id AND c.contract_status = 'active' 
                 LIMIT 1) as tenant_name,
                (SELECT c.contract_start 
                 FROM contracts c 
                 WHERE c.room_id = r.room_id AND c.contract_status = 'active' 
                 LIMIT 1) as contract_start_date,
                (SELECT c.contract_end 
                 FROM contracts c 
                 WHERE c.room_id = r.room_id AND c.contract_status = 'active' 
                 LIMIT 1) as contract_end_date
                FROM rooms r 
                WHERE r.room_id = ?
            ");
            $stmt->execute([$room_id]);
            $room = $stmt->fetch();
            
        } catch(PDOException $e) {
            $error_message = "เกิดข้อผิดพลาด: " . $e->getMessage();
        }
    } else {
        $error_message = implode('<br>', $errors);
    }
}

// ดึงข้อมูลประวัติการเช่า
try {
    $stmt = $pdo->prepare("
        SELECT c.*, t.first_name, t.last_name, t.phone,
        CASE 
            WHEN c.contract_status = 'active' THEN 'กำลังเช่าอยู่'
            WHEN c.contract_status = 'expired' THEN 'หมดอายุ'
            WHEN c.contract_status = 'terminated' THEN 'ยกเลิก'
        END as status_text
        FROM contracts c
        JOIN tenants t ON c.tenant_id = t.tenant_id
        WHERE c.room_id = ?
        ORDER BY c.contract_start DESC
    ");
    $stmt->execute([$room_id]);
    $contract_history = $stmt->fetchAll();
} catch(PDOException $e) {
    $contract_history = [];
}

// ดึงข้อมูลการใช้ไฟฟ้าน้ำประปาล่าสุด
try {
    $stmt = $pdo->prepare("
        SELECT * FROM utility_readings 
        WHERE room_id = ? 
        ORDER BY reading_month DESC 
        LIMIT 6
    ");
    $stmt->execute([$room_id]);
    $utility_history = $stmt->fetchAll();
} catch(PDOException $e) {
    $utility_history = [];
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
                    <i class="bi bi-pencil-square"></i>
                    แก้ไขข้อมูลห้องพัก
                    <?php if ($room): ?>
                        <span class="text-primary">ห้อง <?php echo $room['room_number']; ?></span>
                    <?php endif; ?>
                </h2>
                <div class="btn-group">
                    <a href="rooms.php" class="btn btn-secondary">
                        <i class="bi bi-arrow-left"></i>
                        กลับไปรายการห้องพัก
                    </a>
                    <?php if ($room): ?>
                        <a href="view_room.php?id=<?php echo $room['room_id']; ?>" class="btn btn-outline-info">
                            <i class="bi bi-eye"></i>
                            ดูรายละเอียด
                        </a>
                    <?php endif; ?>
                </div>
            </div>

            <!-- แสดงข้อความแจ้งเตือน -->
            <?php if (!empty($success_message)): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="bi bi-check-circle"></i>
                    <?php echo $success_message; ?>
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

            <?php if ($room): ?>
                <!-- สถานะปัจจุบันของห้อง -->
                <div class="row mb-4">
                    <div class="col-md-3">
                        <div class="card text-center">
                            <div class="card-body">
                                <i class="bi bi-door-open fs-2 text-primary"></i>
                                <h5 class="mt-2">ห้อง <?php echo $room['room_number']; ?></h5>
                                <p class="text-muted mb-0">
                                    <?php
                                    switch ($room['room_type']) {
                                        case 'single': echo 'ห้องเดี่ยว'; break;
                                        case 'double': echo 'ห้องคู่'; break;
                                        case 'triple': echo 'ห้องสาม'; break;
                                    }
                                    ?> | ชั้น <?php echo $room['floor_number']; ?>
                                </p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card text-center">
                            <div class="card-body">
                                <?php
                                $status_icon = '';
                                $status_color = '';
                                $status_text = '';
                                if ($room['has_active_contract'] > 0) {
                                    $status_icon = 'bi-person-fill';
                                    $status_color = 'text-danger';
                                    $status_text = 'มีผู้เช่า';
                                } else {
                                    switch ($room['room_status']) {
                                        case 'available':
                                            $status_icon = 'bi-check-circle';
                                            $status_color = 'text-success';
                                            $status_text = 'ว่าง';
                                            break;
                                        case 'maintenance':
                                            $status_icon = 'bi-tools';
                                            $status_color = 'text-warning';
                                            $status_text = 'ปรับปรุง';
                                            break;
                                    }
                                }
                                ?>
                                <i class="<?php echo $status_icon; ?> fs-2 <?php echo $status_color; ?>"></i>
                                <h5 class="mt-2"><?php echo $status_text; ?></h5>
                                <p class="text-muted mb-0">สถานะปัจจุบัน</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card text-center">
                            <div class="card-body">
                                <i class="bi bi-currency-dollar fs-2 text-info"></i>
                                <h5 class="mt-2"><?php echo formatCurrency($room['monthly_rent']); ?></h5>
                                <p class="text-muted mb-0">ค่าเช่าต่อเดือน</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card text-center">
                            <div class="card-body">
                                <?php if ($room['tenant_name']): ?>
                                    <i class="bi bi-person-check fs-2 text-success"></i>
                                    <h6 class="mt-2"><?php echo $room['tenant_name']; ?></h6>
                                    <p class="text-muted mb-0">
                                        เข้าพัก <?php echo formatDate($room['contract_start_date']); ?>
                                    </p>
                                <?php else: ?>
                                    <i class="bi bi-person-dash fs-2 text-muted"></i>
                                    <h6 class="mt-2 text-muted">ไม่มีผู้เช่า</h6>
                                    <p class="text-muted mb-0">ห้องว่าง</p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ฟอร์มแก้ไขข้อมูลห้องพัก -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0">
                            <i class="bi bi-door-open"></i>
                            ข้อมูลห้องพัก
                        </h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" class="needs-validation" novalidate>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="room_number" class="form-label">
                                        หมายเลขห้อง <span class="text-danger">*</span>
                                    </label>
                                    <input type="text" class="form-control" id="room_number" name="room_number" 
                                           value="<?php echo htmlspecialchars($room['room_number']); ?>" 
                                           placeholder="เช่น 101, 202" required>
                                    <div class="invalid-feedback">
                                        กรุณากรอกหมายเลขห้อง
                                    </div>
                                </div>

                                <div class="col-md-6 mb-3">
                                    <label for="room_type" class="form-label">
                                        ประเภทห้อง <span class="text-danger">*</span>
                                    </label>
                                    <select class="form-select" id="room_type" name="room_type" required>
                                        <option value="">เลือกประเภทห้อง</option>
                                        <option value="single" <?php echo $room['room_type'] == 'single' ? 'selected' : ''; ?>>
                                            ห้องเดี่ยว (Single)
                                        </option>
                                        <option value="double" <?php echo $room['room_type'] == 'double' ? 'selected' : ''; ?>>
                                            ห้องคู่ (Double)
                                        </option>
                                        <option value="triple" <?php echo $room['room_type'] == 'triple' ? 'selected' : ''; ?>>
                                            ห้องสาม (Triple)
                                        </option>
                                    </select>
                                    <div class="invalid-feedback">
                                        กรุณาเลือกประเภทห้อง
                                    </div>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-4 mb-3">
                                    <label for="floor_number" class="form-label">
                                        ชั้น <span class="text-danger">*</span>
                                    </label>
                                    <input type="number" class="form-control" id="floor_number" name="floor_number" 
                                           value="<?php echo $room['floor_number']; ?>" 
                                           min="1" max="50" required>
                                    <div class="invalid-feedback">
                                        กรุณากรอกหมายเลขชั้น
                                    </div>
                                </div>

                                <div class="col-md-4 mb-3">
                                    <label for="monthly_rent" class="form-label">
                                        ค่าเช่าต่อเดือน (บาท) <span class="text-danger">*</span>
                                    </label>
                                    <input type="number" class="form-control" id="monthly_rent" name="monthly_rent" 
                                           value="<?php echo $room['monthly_rent']; ?>" 
                                           min="0" step="0.01" required>
                                    <div class="invalid-feedback">
                                        กรุณากรอกค่าเช่าที่ถูกต้อง
                                    </div>
                                    <?php if ($room['has_active_contract'] > 0): ?>
                                        <div class="form-text text-warning">
                                            <i class="bi bi-exclamation-triangle"></i>
                                            การเปลี่ยนค่าเช่าจะมีผลกับใบแจ้งหนี้ใหม่เท่านั้น
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <div class="col-md-4 mb-3">
                                    <label for="deposit" class="form-label">
                                        เงินมัดจำ (บาท)
                                    </label>
                                    <input type="number" class="form-control" id="deposit" name="deposit" 
                                           value="<?php echo $room['deposit']; ?>" 
                                           min="0" step="0.01">
                                    <small class="form-text text-muted">หากไม่มีให้ใส่ 0</small>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-12 mb-3">
                                    <label for="room_status" class="form-label">
                                        สถานะห้อง
                                    </label>
                                    <select class="form-select" id="room_status" name="room_status" 
                                            <?php echo $room['has_active_contract'] > 0 ? 'disabled' : ''; ?>>
                                        <?php if ($room['has_active_contract'] > 0): ?>
                                            <option value="occupied" selected>มีผู้เช่า (ไม่สามารถเปลี่ยนได้)</option>
                                        <?php else: ?>
                                            <option value="available" <?php echo $room['room_status'] == 'available' ? 'selected' : ''; ?>>
                                                ว่าง (Available)
                                            </option>
                                            <option value="maintenance" <?php echo $room['room_status'] == 'maintenance' ? 'selected' : ''; ?>>
                                                ปรับปรุง (Maintenance)
                                            </option>
                                        <?php endif; ?>
                                    </select>
                                    <?php if ($room['has_active_contract'] > 0): ?>
                                        <input type="hidden" name="room_status" value="occupied">
                                        <div class="form-text text-info">
                                            <i class="bi bi-info-circle"></i>
                                            ห้องนี้มีผู้เช่าอยู่ จึงไม่สามารถเปลี่ยนสถานะได้
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-12 mb-3">
                                    <label for="room_description" class="form-label">
                                        คำอธิบายห้อง
                                    </label>
                                    <textarea class="form-control" id="room_description" name="room_description" 
                                              rows="3" placeholder="รายละเอียดเพิ่มเติมเกี่ยวกับห้อง เช่น สิ่งอำนวยความสะดวก วิว ฯลฯ"><?php echo htmlspecialchars($room['room_description']); ?></textarea>
                                </div>
                            </div>

                            <hr>

                            <div class="row">
                                <div class="col-12">
                                    <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                                        <a href="rooms.php" class="btn btn-secondary me-md-2">
                                            <i class="bi bi-x-circle"></i>
                                            ยกเลิก
                                        </a>
                                        <button type="submit" class="btn btn-primary">
                                            <i class="bi bi-check-circle"></i>
                                            บันทึกการเปลี่ยนแปลง
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- การคำนวณแบบเรียลไทม์ -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h6 class="mb-0">
                            <i class="bi bi-calculator"></i>
                            ตัวอย่างการคำนวณ
                        </h6>
                    </div>
                    <div class="card-body">
                        <div class="row text-center">
                            <div class="col-md-4">
                                <div class="border rounded p-3 h-100">
                                    <i class="bi bi-currency-dollar text-primary fs-2"></i>
                                    <h6 class="mt-2">ค่าเช่าต่อเดือน</h6>
                                    <p class="text-muted mb-0" id="rent-display"><?php echo formatCurrency($room['monthly_rent']); ?></p>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="border rounded p-3 h-100">
                                    <i class="bi bi-piggy-bank text-success fs-2"></i>
                                    <h6 class="mt-2">เงินมัดจำ</h6>
                                    <p class="text-muted mb-0" id="deposit-display"><?php echo formatCurrency($room['deposit']); ?></p>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="border rounded p-3 h-100">
                                    <i class="bi bi-cash-stack text-warning fs-2"></i>
                                    <h6 class="mt-2">รวมเงินต้องจ่าย</h6>
                                    <p class="text-muted mb-0" id="total-display"><?php echo formatCurrency($room['monthly_rent'] + $room['deposit']); ?></p>
                                    <small class="text-muted">(เดือนแรก)</small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <!-- ประวัติการเช่า -->
                    <div class="col-lg-8 mb-4">
                        <div class="card">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <h6 class="mb-0">
                                    <i class="bi bi-clock-history"></i>
                                    ประวัติการเช่า
                                </h6>
                                <span class="badge bg-primary"><?php echo count($contract_history); ?> สัญญา</span>
                            </div>
                            <div class="card-body">
                                <?php if (empty($contract_history)): ?>
                                    <div class="text-center py-3">
                                        <i class="bi bi-file-earmark-text text-muted fs-1"></i>
                                        <p class="text-muted mt-2">ยังไม่มีประวัติการเช่า</p>
                                    </div>
                                <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table table-sm">
                                            <thead>
                                                <tr>
                                                    <th>ผู้เช่า</th>
                                                    <th>วันที่เริ่ม</th>
                                                    <th>วันที่สิ้นสุด</th>
                                                    <th>ค่าเช่า</th>
                                                    <th>สถานะ</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($contract_history as $contract): ?>
                                                    <tr>
                                                        <td>
                                                            <div><?php echo $contract['first_name'] . ' ' . $contract['last_name']; ?></div>
                                                            <small class="text-muted"><?php echo $contract['phone']; ?></small>
                                                        </td>
                                                        <td><?php echo formatDate($contract['contract_start']); ?></td>
                                                        <td><?php echo formatDate($contract['contract_end']); ?></td>
                                                        <td><?php echo formatCurrency($contract['monthly_rent']); ?></td>
                                                        <td>
                                                            <?php
                                                            $status_class = '';
                                                            switch ($contract['contract_status']) {
                                                                case 'active':
                                                                    $status_class = 'bg-success';
                                                                    break;
                                                                case 'expired':
                                                                    $status_class = 'bg-secondary';
                                                                    break;
                                                                case 'terminated':
                                                                    $status_class = 'bg-danger';
                                                                    break;
                                                            }
                                                            ?>
                                                            <span class="badge <?php echo $status_class; ?>">
                                                                <?php echo $contract['status_text']; ?>
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
                    </div>

                    <!-- ประวัติการใช้สาธารณูปโภค -->
                    <div class="col-lg-4 mb-4">
                        <div class="card">
                            <div class="card-header">
                                <h6 class="mb-0">
                                    <i class="bi bi-speedometer"></i>
                                    ประวัติมิเตอร์ล่าสุด
                                </h6>
                            </div>
                            <div class="card-body">
                                <?php if (empty($utility_history)): ?>
                                    <div class="text-center py-3">
                                        <i class="bi bi-speedometer text-muted fs-1"></i>
                                        <p class="text-muted mt-2">ยังไม่มีข้อมูล</p>
                                    </div>
                                <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table table-sm">
                                            <thead>
                                                <tr>
                                                    <th>เดือน</th>
                                                    <th>ไฟฟ้า</th>
                                                    <th>น้ำ</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach (array_slice($utility_history, 0, 5) as $utility): ?>
                                                    <tr>
                                                        <td>
                                                            <small><?php echo thaiMonth(substr($utility['reading_month'], 5, 2)) . ' ' . substr($utility['reading_month'], 0, 4); ?></small>
                                                        </td>
                                                        <td>
                                                            <small><?php echo number_format($utility['electric_current'], 0); ?> หน่วย</small>
                                                        </td>
                                                        <td>
                                                            <small><?php echo number_format($utility['water_current'], 0); ?> หน่วย</small>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                    <?php if (count($utility_history) > 5): ?>
                                        <div class="text-center">
                                            <a href="utility_readings.php?room_id=<?php echo $room['room_id']; ?>" class="btn btn-sm btn-outline-primary">
                                                ดูทั้งหมด
                                            </a>
                                        </div>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ข้อมูลเพิ่มเติมและการดำเนินการ -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h6 class="mb-0">
                            <i class="bi bi-gear"></i>
                            การดำเนินการเพิ่มเติม
                        </h6>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-3 mb-3">
                                <div class="d-grid">
                                    <a href="view_room.php?id=<?php echo $room['room_id']; ?>" class="btn btn-outline-info">
                                        <i class="bi bi-eye"></i>
                                        ดูรายละเอียดเต็ม
                                    </a>
                                </div>
                            </div>
                            <div class="col-md-3 mb-3">
                                <div class="d-grid">
                                    <a href="utility_readings.php?room_id=<?php echo $room['room_id']; ?>" class="btn btn-outline-success">
                                        <i class="bi bi-speedometer"></i>
                                        บันทึกมิเตอร์
                                    </a>
                                </div>
                            </div>
                            <?php if ($room['has_active_contract'] > 0): ?>
                                <div class="col-md-3 mb-3">
                                    <div class="d-grid">
                                        <a href="contracts.php?room_id=<?php echo $room['room_id']; ?>" class="btn btn-outline-primary">
                                            <i class="bi bi-file-earmark-text"></i>
                                            ดูสัญญาปัจจุบัน
                                        </a>
                                    </div>
                                </div>
                                <div class="col-md-3 mb-3">
                                    <div class="d-grid">
                                        <a href="invoices.php?contract_id=<?php echo $room['room_id']; ?>" class="btn btn-outline-warning">
                                            <i class="bi bi-receipt"></i>
                                            ดูใบแจ้งหนี้
                                        </a>
                                    </div>
                                </div>
                            <?php else: ?>
                                <div class="col-md-3 mb-3">
                                    <div class="d-grid">
                                        <a href="add_tenant.php" class="btn btn-outline-primary">
                                            <i class="bi bi-person-plus"></i>
                                            เพิ่มผู้เช่าใหม่
                                        </a>
                                    </div>
                                </div>
                                <div class="col-md-3 mb-3">
                                    <div class="d-grid">
                                        <button type="button" class="btn btn-outline-danger" onclick="confirmDeleteRoom()">
                                            <i class="bi bi-trash"></i>
                                            ลบห้องนี้
                                        </button>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- ข้อมูลการสร้างและอัปเดต -->
                <div class="card mt-4">
                    <div class="card-header">
                        <h6 class="mb-0">
                            <i class="bi bi-info-circle"></i>
                            ข้อมูลระบบ
                        </h6>
                    </div>
                    <div class="card-body">
                        <div class="row text-center">
                            <div class="col-md-6">
                                <div class="border-end pe-3">
                                    <h6 class="text-muted mb-1">วันที่สร้าง</h6>
                                    <p class="mb-0"><?php echo formatDateTime($room['created_at']); ?></p>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="ps-3">
                                    <h6 class="text-muted mb-1">ID ห้อง</h6>
                                    <p class="mb-0">#<?php echo str_pad($room['room_id'], 6, '0', STR_PAD_LEFT); ?></p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

            <?php else: ?>
                <!-- กรณีไม่พบข้อมูลห้อง -->
                <div class="alert alert-danger">
                    <h4 class="alert-heading">ไม่พบข้อมูลห้องพัก</h4>
                    <p>ไม่พบข้อมูลห้องพักที่คุณต้องการแก้ไข กรุณาตรวจสอบและลองใหม่อีกครั้ง</p>
                    <hr>
                    <p class="mb-0">
                        <a href="rooms.php" class="btn btn-primary">
                            <i class="bi bi-arrow-left"></i>
                            กลับไปรายการห้องพัก
                        </a>
                    </p>
                </div>
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
    
    // การคำนวณแบบเรียลไทม์
    function updateCalculation() {
        const rent = parseFloat(document.getElementById('monthly_rent').value) || 0;
        const deposit = parseFloat(document.getElementById('deposit').value) || 0;
        const total = rent + deposit;
        
        document.getElementById('rent-display').textContent = formatCurrency(rent);
        document.getElementById('deposit-display').textContent = formatCurrency(deposit);
        document.getElementById('total-display').textContent = formatCurrency(total);
    }
    
    // เพิ่ม event listener สำหรับการเปลี่ยนแปลงค่า
    const rentInput = document.getElementById('monthly_rent');
    const depositInput = document.getElementById('deposit');
    
    if (rentInput) {
        rentInput.addEventListener('input', updateCalculation);
    }
    
    if (depositInput) {
        depositInput.addEventListener('input', updateCalculation);
    }
    
    // ฟังก์ชันฟอร์แมตเงิน
    function formatCurrency(amount) {
        return new Intl.NumberFormat('th-TH', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        }).format(amount) + ' บาท';
    }
    
    // Real-time validation feedback
    const requiredInputs = document.querySelectorAll('input[required], select[required]');
    requiredInputs.forEach(input => {
        input.addEventListener('blur', function() {
            if (this.value.trim() === '') {
                this.classList.add('is-invalid');
                this.classList.remove('is-valid');
            } else {
                this.classList.remove('is-invalid');
                this.classList.add('is-valid');
            }
        });
        
        input.addEventListener('input', function() {
            if (this.classList.contains('is-invalid') && this.value.trim() !== '') {
                this.classList.remove('is-invalid');
                this.classList.add('is-valid');
            }
        });
    });
    
    // ตรวจสอบค่าตัวเลข
    const numberInputs = document.querySelectorAll('input[type="number"]');
    numberInputs.forEach(input => {
        input.addEventListener('blur', function() {
            const value = parseFloat(this.value);
            const min = parseFloat(this.min);
            
            if (this.hasAttribute('required') && (isNaN(value) || value < min)) {
                this.classList.add('is-invalid');
                this.classList.remove('is-valid');
            } else if (this.value !== '' && !isNaN(value) && value >= min) {
                this.classList.remove('is-invalid');
                this.classList.add('is-valid');
            }
        });
    });
});

// ฟังก์ชันยืนยันการลบห้อง
function confirmDeleteRoom() {
    if (confirm('คุณต้องการลบห้องนี้หรือไม่?\n\nการลบห้องจะไม่สามารถยกเลิกได้ และจะส่งผลต่อข้อมูลที่เกี่ยวข้องทั้งหมด')) {
        window.location.href = 'rooms.php?delete=<?php echo $room_id; ?>';
    }
}

// ฟังก์ชันสำหรับ tooltip
document.addEventListener('DOMContentLoaded', function() {
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
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

.border-end {
    border-right: 1px solid #dee2e6 !important;
}

.badge {
    font-size: 0.75rem;
}

.btn {
    transition: all 0.2s ease-in-out;
}

.btn:hover {
    transform: translateY(-1px);
}

.table th {
    background-color: #f8f9fa;
    font-weight: 600;
    font-size: 0.875rem;
}

.table td {
    font-size: 0.875rem;
}

.form-control:focus,
.form-select:focus {
    border-color: #80bdff;
    box-shadow: 0 0 0 0.2rem rgba(0, 123, 255, 0.25);
}

.is-valid {
    border-color: #28a745;
}

.is-invalid {
    border-color: #dc3545;
}

@media (max-width: 768px) {
    .border-end {
        border-right: none !important;
        border-bottom: 1px solid #dee2e6 !important;
        margin-bottom: 1rem;
        padding-bottom: 1rem;
    }
    
    .border-end:last-child {
        border-bottom: none !important;
        margin-bottom: 0;
        padding-bottom: 0;
    }
    
    .btn-group {
        flex-direction: column;
        gap: 0.5rem;
    }
    
    .d-grid {
        margin-bottom: 0.5rem;
    }
}

@media print {
    .btn, .card-header .btn-group, .d-grid {
        display: none !important;
    }
    
    .card {
        border: 1px solid #000 !important;
        box-shadow: none !important;
        page-break-inside: avoid;
        margin-bottom: 20px !important;
    }
    
    .card-header {
        background-color: #f8f9fa !important;
        -webkit-print-color-adjust: exact;
    }
    
    .table {
        font-size: 12px;
    }
    
    .badge {
        border: 1px solid #000;
        -webkit-print-color-adjust: exact;
    }
    
    @page {
        margin: 1cm;
        size: A4;
    }
    
    body {
        font-size: 12px;
    }
    
    h2, h5, h6 {
        color: #000 !important;
    }
}

/* Custom Scrollbar */
.table-responsive::-webkit-scrollbar {
    height: 8px;
}

.table-responsive::-webkit-scrollbar-track {
    background: #f1f1f1;
    border-radius: 10px;
}

.table-responsive::-webkit-scrollbar-thumb {
    background: #c1c1c1;
    border-radius: 10px;
}

.table-responsive::-webkit-scrollbar-thumb:hover {
    background: #a8a8a8;
}

/* Animation for alerts */
.alert {
    animation: slideInDown 0.5s ease-out;
}

@keyframes slideInDown {
    from {
        transform: translateY(-100%);
        opacity: 0;
    }
    to {
        transform: translateY(0);
        opacity: 1;
    }
}

/* Improved form styling */
.form-label {
    font-weight: 500;
    color: #495057;
}

.form-control, .form-select {
    border-radius: 0.375rem;
    border: 1px solid #ced4da;
    transition: all 0.15s ease-in-out;
}

.form-control:focus, .form-select:focus {
    border-color: #86b7fe;
    outline: 0;
    box-shadow: 0 0 0 0.25rem rgba(13, 110, 253, 0.25);
}

/* Status indicators */
.text-success {
    color: #198754 !important;
}

.text-danger {
    color: #dc3545 !important;
}

.text-warning {
    color: #fd7e14 !important;
}

.text-info {
    color: #0dcaf0 !important;
}

.text-primary {
    color: #0d6efd !important;
}

/* Enhanced card styling */
.card-header {
    background-color: #fff;
    border-bottom: 1px solid #dee2e6;
    font-weight: 500;
}

.card-body {
    padding: 1.5rem;
}

/* Better spacing for form groups */
.row .col-md-3,
.row .col-md-4,
.row .col-md-6 {
    margin-bottom: 1rem;
}

/* Improved button styling */
.btn {
    font-weight: 500;
    border-radius: 0.375rem;
    padding: 0.5rem 1rem;
}

.btn-sm {
    padding: 0.25rem 0.5rem;
    font-size: 0.875rem;
}

/* Better table styling */
.table-responsive {
    border-radius: 0.375rem;
    border: 1px solid #dee2e6;
}

.table thead th {
    border-bottom: 2px solid #dee2e6;
    vertical-align: bottom;
}

.table tbody + tbody {
    border-top: 2px solid #dee2e6;
}

/* Enhanced badge styling */
.badge {
    font-weight: 500;
    font-size: 0.75rem;
    padding: 0.35em 0.65em;
}

/* Loading animation for form submission */
.btn:disabled {
    opacity: 0.65;
    cursor: not-allowed;
}

/* Improved responsive design */
@media (max-width: 576px) {
    .card-body {
        padding: 1rem;
    }
    
    .btn-group-vertical .btn {
        margin-bottom: 0.25rem;
    }
    
    .table-responsive {
        font-size: 0.875rem;
    }
    
    .card-header h6 {
        font-size: 0.9rem;
    }
}
</style>

<?php include 'includes/footer.php'; ?>