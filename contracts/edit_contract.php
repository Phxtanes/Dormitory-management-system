<?php
$page_title = "แก้ไขข้อมูลสัญญาเช่า";
require_once 'includes/header.php';

$success_message = '';
$error_message = '';

// รับ contract_id จาก URL
$contract_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($contract_id <= 0) {
    header('Location: contracts.php');
    exit;
}

// ดึงข้อมูลสัญญาเช่าปัจจุบัน
try {
    $stmt = $pdo->prepare("
        SELECT 
            c.*,
            t.first_name, t.last_name, t.phone, t.email, t.id_card,
            r.room_number, r.room_type, r.monthly_rent as room_monthly_rent, r.deposit as room_deposit
        FROM contracts c
        LEFT JOIN tenants t ON c.tenant_id = t.tenant_id
        LEFT JOIN rooms r ON c.room_id = r.room_id
        WHERE c.contract_id = ?
    ");
    $stmt->execute([$contract_id]);
    $contract = $stmt->fetch();
    
    if (!$contract) {
        header('Location: contracts.php');
        exit;
    }
    
    // ตรวจสอบว่าสัญญายังสามารถแก้ไขได้หรือไม่
    if ($contract['contract_status'] == 'terminated') {
        $error_message = "ไม่สามารถแก้ไขสัญญาที่ยกเลิกแล้วได้";
    }
} catch(PDOException $e) {
    $error_message = "เกิดข้อผิดพลาด: " . $e->getMessage();
    $contract = null;
}

// ตรวจสอบการส่งฟอร์ม
if ($_SERVER['REQUEST_METHOD'] == 'POST' && $contract && $contract['contract_status'] != 'terminated') {
    $contract_start = $_POST['contract_start'];
    $contract_end = $_POST['contract_end'];
    $monthly_rent = floatval($_POST['monthly_rent']);
    $deposit_paid = floatval($_POST['deposit_paid']);
    $special_conditions = trim($_POST['special_conditions']);
    $contract_status = $_POST['contract_status'];
    
    // ตรวจสอบข้อมูล
    $errors = [];
    
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
    
    // ตรวจสอบสถานะสัญญา
    if (!in_array($contract_status, ['active', 'expired', 'terminated'])) {
        $errors[] = "สถานะสัญญาไม่ถูกต้อง";
    }
    
    // ตรวจสอบว่ามีใบแจ้งหนี้ที่ยังไม่ได้ชำระหรือไม่ ถ้าต้องการเปลี่ยนสธานะเป็น terminated
    if ($contract_status == 'terminated' && $contract['contract_status'] != 'terminated') {
        try {
            $stmt = $pdo->prepare("
                SELECT COUNT(*) as pending_count 
                FROM invoices 
                WHERE contract_id = ? AND invoice_status = 'pending'
            ");
            $stmt->execute([$contract_id]);
            $pending_result = $stmt->fetch();
            
            if ($pending_result['pending_count'] > 0) {
                $errors[] = "ไม่สามารถยกเลิกสัญญาได้ เนื่องจากมีใบแจ้งหนี้ที่ยังไม่ได้ชำระ";
            }
        } catch(PDOException $e) {
            $errors[] = "เกิดข้อผิดพลาดในการตรวจสอบใบแจ้งหนี้";
        }
    }
    
    // บันทึกข้อมูลถ้าไม่มีข้อผิดพลาด
    if (empty($errors)) {
        try {
            $pdo->beginTransaction();
            
            // อัปเดตข้อมูลสัญญา
            $stmt = $pdo->prepare("
                UPDATE contracts SET
                    contract_start = ?,
                    contract_end = ?,
                    monthly_rent = ?,
                    deposit_paid = ?,
                    special_conditions = ?,
                    contract_status = ?
                WHERE contract_id = ?
            ");
            
            $stmt->execute([
                $contract_start,
                $contract_end,
                $monthly_rent,
                $deposit_paid,
                $special_conditions,
                $contract_status,
                $contract_id
            ]);
            
            // ถ้าเปลี่ยนสถานะเป็น terminated ต้องอัปเดตสถานะห้องด้วย
            if ($contract_status == 'terminated' && $contract['contract_status'] != 'terminated') {
                $stmt = $pdo->prepare("UPDATE rooms SET room_status = 'available' WHERE room_id = ?");
                $stmt->execute([$contract['room_id']]);
            }
            
            // ถ้าเปลี่ยนสถานะเป็น active จากสถานะอื่น ต้องอัปเดตสถานะห้องเป็น occupied
            if ($contract_status == 'active' && $contract['contract_status'] != 'active') {
                $stmt = $pdo->prepare("UPDATE rooms SET room_status = 'occupied' WHERE room_id = ?");
                $stmt->execute([$contract['room_id']]);
            }
            
            $pdo->commit();
            $success_message = "อัปเดตข้อมูลสัญญาเช่าเรียบร้อยแล้ว";
            
            // ดึงข้อมูลใหม่หลังจากอัปเดต
            $stmt = $pdo->prepare("
                SELECT 
                    c.*,
                    t.first_name, t.last_name, t.phone, t.email, t.id_card,
                    r.room_number, r.room_type, r.monthly_rent as room_monthly_rent, r.deposit as room_deposit
                FROM contracts c
                LEFT JOIN tenants t ON c.tenant_id = t.tenant_id
                LEFT JOIN rooms r ON c.room_id = r.room_id
                WHERE c.contract_id = ?
            ");
            $stmt->execute([$contract_id]);
            $contract = $stmt->fetch();
            
        } catch(PDOException $e) {
            $pdo->rollBack();
            $error_message = "เกิดข้อผิดพลาด: " . $e->getMessage();
        }
    } else {
        $error_message = implode('<br>', $errors);
    }
}
?>

<?php include 'includes/navbar.php' ?>

<div class="container-fluid">
    <!-- หัวข้อหน้า -->
    <div class="row mb-4 mt-3">
        <div class="col-md-8">
            <h2 class="mb-1">
                <i class="bi bi-pencil-square text-warning"></i>
                แก้ไขสัญญาเช่า #<?php echo $contract['contract_id']; ?>
            </h2>
            <p class="text-muted mb-0">
                แก้ไขรายละเอียดของสัญญาเช่า
            </p>
        </div>
        <div class="col-md-4 text-md-end">
            <div class="btn-group">
                <a href="view_contract.php?id=<?php echo $contract['contract_id']; ?>" class="btn btn-secondary">
                    <i class="bi bi-arrow-left"></i>
                    กลับ
                </a>
                <a href="contracts.php" class="btn btn-outline-secondary">
                    <i class="bi bi-list"></i>
                    รายการสัญญา
                </a>
            </div>
        </div>
    </div>

    <!-- แสดงข้อความ -->
    <?php if ($success_message): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <i class="bi bi-check-circle-fill"></i>
            <?php echo $success_message; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if ($error_message): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <i class="bi bi-exclamation-triangle-fill"></i>
            <?php echo $error_message; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if ($contract && $contract['contract_status'] != 'terminated'): ?>
        <form method="POST" class="needs-validation" novalidate>
            <div class="row">
                <!-- ข้อมูลผู้เช่าและห้อง -->
                <div class="col-lg-4 mb-4">
                    <div class="card h-100">
                        <div class="card-header">
                            <h5 class="card-title mb-0">
                                <i class="bi bi-info-circle text-info"></i>
                                ข้อมูลอ้างอิง
                            </h5>
                        </div>
                        <div class="card-body">
                            <!-- ข้อมูลผู้เช่า -->
                            <div class="mb-4">
                                <h6 class="text-primary mb-3">
                                    <i class="bi bi-person"></i>
                                    ข้อมูลผู้เช่า
                                </h6>
                                <div class="d-flex align-items-center mb-3">
                                    <div class="avatar bg-primary text-white rounded-circle me-3 flex-shrink-0" 
                                         style="width: 50px; height: 50px; display: flex; align-items: center; justify-content: center;">
                                        <?php echo mb_substr($contract['first_name'], 0, 1, 'UTF-8'); ?>
                                    </div>
                                    <div>
                                        <div class="fw-bold"><?php echo $contract['first_name'] . ' ' . $contract['last_name']; ?></div>
                                        <small class="text-muted"><?php echo $contract['phone']; ?></small>
                                    </div>
                                </div>
                                <div class="small text-muted">
                                    <div><strong>เลขบัตรประชาชน:</strong> <?php echo $contract['id_card']; ?></div>
                                    <?php if ($contract['email']): ?>
                                        <div><strong>อีเมล:</strong> <?php echo $contract['email']; ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- ข้อมูลห้อง -->
                            <div class="mb-4">
                                <h6 class="text-success mb-3">
                                    <i class="bi bi-door-open"></i>
                                    ข้อมูลห้อง
                                </h6>
                                <div class="text-center mb-3">
                                    <span class="badge bg-success fs-6 px-3 py-2">
                                        ห้อง <?php echo $contract['room_number']; ?>
                                    </span>
                                </div>
                                <div class="small text-muted">
                                    <div><strong>ประเภทห้อง:</strong> 
                                        <?php 
                                        $room_types = ['single' => 'เดี่ยว', 'double' => 'คู่', 'triple' => 'สามเตียง'];
                                        echo $room_types[$contract['room_type']] ?? $contract['room_type']; 
                                        ?>
                                    </div>
                                    <div><strong>ค่าเช่าห้อง:</strong> <?php echo number_format($contract['room_monthly_rent'], 2); ?> บาท</div>
                                    <div><strong>เงินมัดจำห้อง:</strong> <?php echo number_format($contract['room_deposit'], 2); ?> บาท</div>
                                </div>
                            </div>

                            <!-- สถานะปัจจุบัน -->
                            <div>
                                <h6 class="text-warning mb-3">
                                    <i class="bi bi-clipboard-check"></i>
                                    สถานะปัจจุบัน
                                </h6>
                                <div class="text-center">
                                    <?php
                                    $status_colors = [
                                        'active' => 'success',
                                        'expired' => 'warning', 
                                        'terminated' => 'danger'
                                    ];
                                    $status_texts = [
                                        'active' => 'ใช้งาน',
                                        'expired' => 'หมดอายุ',
                                        'terminated' => 'ยกเลิก'
                                    ];
                                    ?>
                                    <span class="badge bg-<?php echo $status_colors[$contract['contract_status']]; ?> fs-6 px-3 py-2">
                                        <?php echo $status_texts[$contract['contract_status']]; ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ฟอร์มแก้ไข -->
                <div class="col-lg-8">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="card-title mb-0">
                                <i class="bi bi-pencil-square"></i>
                                แก้ไขข้อมูลสัญญา
                            </h5>
                        </div>
                        <div class="card-body">
                            <!-- วันที่สัญญา -->
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="contract_start" class="form-label">
                                        วันที่เริ่มสัญญา <span class="text-danger">*</span>
                                    </label>
                                    <input type="date" class="form-control" id="contract_start" name="contract_start" 
                                           value="<?php echo isset($_POST['contract_start']) ? $_POST['contract_start'] : $contract['contract_start']; ?>" 
                                           required>
                                    <div class="invalid-feedback">
                                        กรุณากรอกวันที่เริ่มสัญญา
                                    </div>
                                </div>

                                <div class="col-md-6 mb-3">
                                    <label for="contract_end" class="form-label">
                                        วันที่สิ้นสุดสัญญา <span class="text-danger">*</span>
                                    </label>
                                    <input type="date" class="form-control" id="contract_end" name="contract_end" 
                                           value="<?php echo isset($_POST['contract_end']) ? $_POST['contract_end'] : $contract['contract_end']; ?>" 
                                           required>
                                    <div class="invalid-feedback">
                                        กรุณากรอกวันที่สิ้นสุดสัญญา
                                    </div>
                                </div>
                            </div>

                            <!-- แสดงระยะเวลาสัญญา -->
                            <div class="row">
                                <div class="col-12 mb-3">
                                    <div class="alert alert-info" id="contract-duration" style="display: none;">
                                        <i class="bi bi-calendar-range"></i>
                                        <span id="duration-text"></span>
                                    </div>
                                </div>
                            </div>

                            <!-- ข้อมูลการเงิน -->
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="monthly_rent" class="form-label">
                                        ค่าเช่าต่อเดือน <span class="text-danger">*</span>
                                    </label>
                                    <div class="input-group">
                                        <input type="number" class="form-control" id="monthly_rent" name="monthly_rent" 
                                               value="<?php echo isset($_POST['monthly_rent']) ? $_POST['monthly_rent'] : $contract['monthly_rent']; ?>" 
                                               min="0" step="0.01" required>
                                        <span class="input-group-text">บาท</span>
                                        <div class="invalid-feedback">
                                            กรุณากรอกค่าเช่าต่อเดือน
                                        </div>
                                    </div>
                                    <small class="form-text text-muted">
                                        ค่าเช่าของห้อง: <?php echo number_format($contract['room_monthly_rent'], 2); ?> บาท
                                    </small>
                                </div>

                                <div class="col-md-6 mb-3">
                                    <label for="deposit_paid" class="form-label">
                                        เงินมัดจำที่ได้รับ <span class="text-danger">*</span>
                                    </label>
                                    <div class="input-group">
                                        <input type="number" class="form-control" id="deposit_paid" name="deposit_paid" 
                                               value="<?php echo isset($_POST['deposit_paid']) ? $_POST['deposit_paid'] : $contract['deposit_paid']; ?>" 
                                               min="0" step="0.01" required>
                                        <span class="input-group-text">บาท</span>
                                        <div class="invalid-feedback">
                                            กรุณากรอกจำนวนเงินมัดจำ
                                        </div>
                                    </div>
                                    <small class="form-text text-muted">
                                        เงินมัดจำของห้อง: <?php echo number_format($contract['room_deposit'], 2); ?> บาท
                                    </small>
                                </div>
                            </div>

                            <!-- สถานะสัญญา -->
                            <div class="row">
                                <div class="col-md-12 mb-3">
                                    <label for="contract_status" class="form-label">
                                        สถานะสัญญา <span class="text-danger">*</span>
                                    </label>
                                    <select class="form-select" id="contract_status" name="contract_status" required>
                                        <option value="active" <?php echo (isset($_POST['contract_status']) ? $_POST['contract_status'] : $contract['contract_status']) == 'active' ? 'selected' : ''; ?>>
                                            ใช้งาน (Active)
                                        </option>
                                        <option value="expired" <?php echo (isset($_POST['contract_status']) ? $_POST['contract_status'] : $contract['contract_status']) == 'expired' ? 'selected' : ''; ?>>
                                            หมดอายุ (Expired)
                                        </option>
                                        <option value="terminated" <?php echo (isset($_POST['contract_status']) ? $_POST['contract_status'] : $contract['contract_status']) == 'terminated' ? 'selected' : ''; ?>>
                                            ยกเลิก (Terminated)
                                        </option>
                                    </select>
                                    <div class="invalid-feedback">
                                        กรุณาเลือกสถานะสัญญา
                                    </div>
                                    <small class="form-text text-muted">
                                        การเปลี่ยนสถานะเป็น "ยกเลิก" จะทำให้ห้องกลับมาว่างอัตโนมัติ
                                    </small>
                                </div>
                            </div>

                            <!-- เงื่อนไขพิเศษ -->
                            <div class="row">
                                <div class="col-md-12 mb-3">
                                    <label for="special_conditions" class="form-label">
                                        เงื่อนไขพิเศษ
                                    </label>
                                    <textarea class="form-control" id="special_conditions" name="special_conditions" 
                                              rows="4" placeholder="เงื่อนไขพิเศษของสัญญา (ถ้ามี)"><?php echo isset($_POST['special_conditions']) ? htmlspecialchars($_POST['special_conditions']) : htmlspecialchars($contract['special_conditions']); ?></textarea>
                                </div>
                            </div>

                            <hr>

                            <!-- ปุ่มดำเนินการ -->
                            <div class="row">
                                <div class="col-12">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <small class="text-muted">
                                                <i class="bi bi-info-circle"></i>
                                                ข้อมูลจะถูกบันทึกทันทีเมื่อกดปุ่มบันทึก
                                            </small>
                                        </div>
                                        <div>
                                            <button type="button" class="btn btn-secondary me-2" onclick="resetForm()">
                                                <i class="bi bi-arrow-counterclockwise"></i>
                                                รีเซ็ต
                                            </button>
                                            <button type="submit" class="btn btn-warning">
                                                <i class="bi bi-save"></i>
                                                บันทึกการเปลี่ยนแปลง
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </form>

    <?php elseif ($contract && $contract['contract_status'] == 'terminated'): ?>
        <!-- แสดงข้อมูลสัญญาที่ยกเลิกแล้ว -->
        <div class="card">
            <div class="card-header bg-danger text-white">
                <h5 class="card-title mb-0">
                    <i class="bi bi-x-circle"></i>
                    สัญญาถูกยกเลิกแล้ว
                </h5>
            </div>
            <div class="card-body">
                <div class="alert alert-danger">
                    <i class="bi bi-exclamation-triangle-fill"></i>
                    สัญญาเช่านี้ถูกยกเลิกแล้ว ไม่สามารถแก้ไขได้
                </div>
                <p>สัญญาเช่า #<?php echo $contract['contract_id']; ?> ของคุณ<?php echo $contract['first_name'] . ' ' . $contract['last_name']; ?> 
                   ห้อง <?php echo $contract['room_number']; ?> ได้ถูกยกเลิกแล้ว</p>
                
                <div class="mt-3">
                    <a href="view_contract.php?id=<?php echo $contract['contract_id']; ?>" class="btn btn-secondary">
                        <i class="bi bi-eye"></i>
                        ดูรายละเอียดสัญญา
                    </a>
                    <a href="contracts.php" class="btn btn-primary">
                        <i class="bi bi-list"></i>
                        กลับไปรายการสัญญา
                    </a>
                </div>
            </div>
        </div>

    <?php else: ?>
        <!-- ไม่พบข้อมูลสัญญา -->
        <div class="card">
            <div class="card-body text-center">
                <div class="alert alert-warning">
                    <i class="bi bi-exclamation-triangle-fill"></i>
                    ไม่พบข้อมูลสัญญาที่ต้องการแก้ไข
                </div>
                <a href="contracts.php" class="btn btn-primary">
                    <i class="bi bi-arrow-left"></i>
                    กลับไปรายการสัญญา
                </a>
            </div>
        </div>
    <?php endif; ?>
</div>

<script>
// เก็บค่าเดิมของฟอร์ม
const originalFormData = new FormData();
<?php if ($contract): ?>
originalFormData.append('contract_start', '<?php echo $contract['contract_start']; ?>');
originalFormData.append('contract_end', '<?php echo $contract['contract_end']; ?>');
originalFormData.append('monthly_rent', '<?php echo $contract['monthly_rent']; ?>');
originalFormData.append('deposit_paid', '<?php echo $contract['deposit_paid']; ?>');
originalFormData.append('special_conditions', <?php echo json_encode($contract['special_conditions']); ?>);
originalFormData.append('contract_status', '<?php echo $contract['contract_status']; ?>');
<?php endif; ?>

// ฟังก์ชันคำนวณระยะเวลาสัญญา
function calculateContractDuration() {
    const startDate = document.getElementById('contract_start').value;
    const endDate = document.getElementById('contract_end').value;
    const durationDiv = document.getElementById('contract-duration');
    const durationText = document.getElementById('duration-text');
    
    if (startDate && endDate) {
        const start = new Date(startDate);
        const end = new Date(endDate);
        const diffTime = end - start;
        const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24));
        
        if (diffDays > 0) {
            const months = Math.floor(diffDays / 30);
            const days = diffDays % 30;
            
            let durationStr = 'ระยะเวลาสัญญา: ';
            if (months > 0) {
                durationStr += months + ' เดือน ';
            }
            if (days > 0) {
                durationStr += days + ' วัน';
            }
            durationStr += ' (รวม ' + diffDays + ' วัน)';
            
            durationText.textContent = durationStr;
            durationDiv.style.display = 'block';
            durationDiv.className = 'alert alert-info';
        } else {
            durationText.textContent = 'วันที่สิ้นสุดต้องมากกว่าวันที่เริ่มต้น';
            durationDiv.style.display = 'block';
            durationDiv.className = 'alert alert-danger';
        }
    } else {
        durationDiv.style.display = 'none';
    }
}

// ฟังก์ชันรีเซ็ตฟอร์ม
function resetForm() {
    if (confirm('คุณต้องการรีเซ็ตข้อมูลกลับเป็นค่าเดิมหรือไม่?')) {
        document.querySelector('form').reset();
        
        // รีเซ็ตค่าเดิม
        for (let [key, value] of originalFormData.entries()) {
            const field = document.querySelector(`[name="${key}"]`);
            if (field) {
                field.value = value;
            }
        }
        
        calculateContractDuration();
        
        // ลบ validation classes
        document.querySelectorAll('.is-valid, .is-invalid').forEach(el => {
            el.classList.remove('is-valid', 'is-invalid');
        });
    }
}

// Event listeners
document.addEventListener('DOMContentLoaded', function() {
    const form = document.querySelector('form');
    
    // คำนวณระยะเวลาสัญญาเมื่อเริ่มต้น
    calculateContractDuration();
    
    // Event listeners สำหรับการเปลี่ยนแปลงวันที่
    document.getElementById('contract_start').addEventListener('change', calculateContractDuration);
    document.getElementById('contract_end').addEventListener('change', calculateContractDuration);
    
    // Bootstrap form validation
    form.addEventListener('submit', function(event) {
        if (!form.checkValidity()) {
            event.preventDefault();
            event.stopPropagation();
        } else {
            // ตรวจสอบวันที่
            const startDate = new Date(document.getElementById('contract_start').value);
            const endDate = new Date(document.getElementById('contract_end').value);
            
            if (endDate <= startDate) {
                event.preventDefault();
                alert('วันที่สิ้นสุดสัญญาต้องมากกว่าวันที่เริ่มสัญญา');
                return false;
            }
            
            // ยืนยันการบันทึก
            if (!confirm('คุณต้องการบันทึกการเปลี่ยนแปลงหรือไม่?')) {
                event.preventDefault();
                return false;
            }
        }
        
        form.classList.add('was-validated');
    });
    
    // Format number inputs
    function formatNumberInput(input) {
        input.addEventListener('input', function() {
            // ลบตัวอักษรที่ไม่ใช่ตัวเลขและจุดทศนิยม
            this.value = this.value.replace(/[^0-9.]/g, '');
            
            // ป้องกันจุดทศนิยมมากกว่า 1 จุด
            const parts = this.value.split('.');
            if (parts.length > 2) {
                this.value = parts[0] + '.' + parts.slice(1).join('');
            }
            
            // จำกัดทศนิยม 2 ตำแหน่ง
            if (parts[1] && parts[1].length > 2) {
                this.value = parts[0] + '.' + parts[1].substring(0, 2);
            }
        });
    }

    // ใช้งานกับ input ที่เป็นตัวเลข
    const numberInputs = document.querySelectorAll('#monthly_rent, #deposit_paid');
    numberInputs.forEach(formatNumberInput);
    
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
    
    // แสดงคำเตือนเมื่อเปลี่ยนสถานะเป็น terminated
    const statusSelect = document.getElementById('contract_status');
    statusSelect.addEventListener('change', function() {
        const warningDiv = document.getElementById('termination-warning');
        if (this.value === 'terminated') {
            if (!warningDiv) {
                const warning = document.createElement('div');
                warning.id = 'termination-warning';
                warning.className = 'alert alert-warning mt-2';
                warning.innerHTML = '<i class="bi bi-exclamation-triangle-fill"></i> ' +
                    'การยกเลิกสัญญาจะทำให้ห้องกลับมาว่างอัตโนมัติ และไม่สามารถแก้ไขสัญญานี้ได้อีก';
                this.parentNode.appendChild(warning);
            }
        } else if (warningDiv) {
            warningDiv.remove();
        }
    });
});
</script>

<style>
/* Custom styles for edit form */
.avatar {
    font-weight: 600;
}

.card {
    transition: all 0.3s ease-in-out;
}

.card:hover {
    transform: translateY(-2px);
    box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15) !important;
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

.form-label {
    font-weight: 500;
    color: #495057;
    margin-bottom: 0.5rem;
}

.text-danger {
    color: #dc3545 !important;
}

.text-success {
    color: #198754 !important;
}

.text-warning {
    color: #ffc107 !important;
}

.text-info {
    color: #0dcaf0 !important;
}

/* Enhanced validation styles */
.was-validated .form-control:valid,
.was-validated .form-select:valid,
.form-control.is-valid,
.form-select.is-valid {
    border-color: #198754;
    padding-right: calc(1.5em + 0.75rem);
    background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 8 8'%3e%3cpath fill='%23198754' d='m2.3 6.73.13-.13L5.71 3.32 7.07 4.68l-5.57 5.57-2.83-2.83z'/%3e%3c/svg%3e");
    background-repeat: no-repeat;
    background-position: right calc(0.375em + 0.1875rem) center;
    background-size: calc(0.75em + 0.375rem) calc(0.75em + 0.375rem);
}

.was-validated .form-control:invalid,
.was-validated .form-select:invalid,
.form-control.is-invalid,
.form-select.is-invalid {
    border-color: #dc3545;
    padding-right: calc(1.5em + 0.75rem);
    background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 12 12' width='12' height='12' fill='none' stroke='%23dc3545'%3e%3ccircle cx='6' cy='6' r='4.5'/%3e%3cpath d='m5.8 4.6 2.4 2.4M8.2 4.6l-2.4 2.4'/%3e%3c/svg%3e");
    background-repeat: no-repeat;
    background-position: right calc(0.375em + 0.1875rem) center;
    background-size: calc(0.75em + 0.375rem) calc(0.75em + 0.375rem);
}

/* Loading animation */
.btn:disabled {
    pointer-events: none;
}

/* Responsive adjustments */
@media (max-width: 768px) {
    .btn-group {
        display: flex;
        flex-direction: column;
        gap: 0.5rem;
    }
    
    .btn-group .btn {
        border-radius: 0.375rem !important;
    }
}

/* Animation for alerts */
.alert {
    animation: slideIn 0.3s ease-out;
}

@keyframes slideIn {
    from {
        transform: translateY(-20px);
        opacity: 0;
    }
    to {
        transform: translateY(0);
        opacity: 1;
    }
}
</style>

<?php include 'includes/footer.php'; ?>