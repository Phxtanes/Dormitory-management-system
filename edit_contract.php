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
    
    // ตรวจสอบว่ามีใบแจ้งหนี้ที่ยังไม่ได้ชำระหรือไม่ ถ้าต้องการเปลี่ยนสถานะเป็น terminated
    if ($contract_status == 'terminated' && $contract['contract_status'] != 'terminated') {
        try {
            $stmt = $pdo->prepare("
                SELECT COUNT(*) as pending_count 
                FROM invoices 
                WHERE contract_id = ? AND invoice_status = 'pending'
            ");
            $stmt->execute([$contract_id]);
            $pending_count = $stmt->fetch()['pending_count'];
            
            if ($pending_count > 0) {
                $errors[] = "ไม่สามารถยกเลิกสัญญาได้ เนื่องจากมีใบแจ้งหนี้ที่ยังไม่ได้ชำระ $pending_count ใบ";
            }
        } catch(PDOException $e) {
            $errors[] = "เกิดข้อผิดพลาดในการตรวจสอบใบแจ้งหนี้";
        }
    }
    
    // บันทึกข้อมูล
    if (empty($errors)) {
        try {
            $pdo->beginTransaction();
            
            // อัพเดทข้อมูลสัญญา
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
                $special_conditions ?: null,
                $contract_status,
                $contract_id
            ]);
            
            // อัพเดทสถานะห้องพักตามสถานะสัญญา
            $room_status = 'occupied';
            if ($contract_status == 'terminated' || $contract_status == 'expired') {
                // ตรวจสอบว่าห้องนี้มีสัญญาอื่นที่ยังใช้งานอยู่หรือไม่
                $stmt = $pdo->prepare("
                    SELECT COUNT(*) as active_contracts 
                    FROM contracts 
                    WHERE room_id = ? AND contract_status = 'active' AND contract_id != ?
                ");
                $stmt->execute([$contract['room_id'], $contract_id]);
                $active_contracts = $stmt->fetch()['active_contracts'];
                
                if ($active_contracts == 0) {
                    $room_status = 'available';
                }
            }
            
            $stmt = $pdo->prepare("UPDATE rooms SET room_status = ? WHERE room_id = ?");
            $stmt->execute([$room_status, $contract['room_id']]);
            
            $pdo->commit();
            
            $success_message = "อัพเดทข้อมูลสัญญาเรียบร้อยแล้ว";
            
            // รีโหลดข้อมูลสัญญา
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

<div class="container-fluid">
    <!-- หัวข้อหน้า -->
    <div class="row mb-4">
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
                <!-- ข้อมูลผู้เช่าและห้องพัก (แสดงอย่างเดียว) -->
                <div class="col-lg-4">
                    <!-- ข้อมูลผู้เช่า -->
                    <div class="card mb-4">
                        <div class="card-header">
                            <h5 class="mb-0">
                                <i class="bi bi-person-fill"></i>
                                ข้อมูลผู้เช่า
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="text-center mb-3">
                                <div class="avatar bg-primary text-white rounded-circle mx-auto mb-2" style="width: 60px; height: 60px; display: flex; align-items: center; justify-content: center; font-size: 1.5rem;">
                                    <?php echo mb_substr($contract['first_name'], 0, 1, 'UTF-8'); ?>
                                </div>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    <?php else: ?>
        <div class="card">
            <div class="card-body text-center py-5">
                <i class="bi bi-x-circle display-1 text-danger"></i>
                <h4 class="text-danger mt-3">ไม่สามารถแก้ไขสัญญาได้</h4>
                <p class="text-muted">
                    <?php if ($contract && $contract['contract_status'] == 'terminated'): ?>
                        สัญญานี้ถูกยกเลิกแล้ว ไม่สามารถแก้ไขได้
                    <?php else: ?>
                        ไม่พบข้อมูลสัญญา หรือข้อมูลไม่ถูกต้อง
                    <?php endif; ?>
                </p>
                <div class="mt-3">
                    <a href="contracts.php" class="btn btn-primary">
                        <i class="bi bi-arrow-left"></i>
                        กลับไปยังรายการสัญญา
                    </a>
                    <?php if ($contract): ?>
                    <a href="view_contract.php?id=<?php echo $contract['contract_id']; ?>" class="btn btn-info ms-2">
                        <i class="bi bi-eye"></i>
                        ดูข้อมูลสัญญา
                    </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<script>
// เก็บค่าเดิมของฟอร์ม
const originalFormData = new FormData(document.querySelector('form'));

// ฟังก์ชันคำนวณระยะเวลาสัญญา
function calculateContractDuration() {
    const startDate = document.getElementById('contract_start').value;
    const endDate = document.getElementById('contract_end').value;
    const durationField = document.getElementById('contract_duration');
    
    if (startDate && endDate) {
        const start = new Date(startDate);
        const end = new Date(endDate);
        const diffTime = Math.abs(end - start);
        const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24));
        const diffMonths = Math.ceil(diffDays / 30);
        
        if (end <= start) {
            durationField.value = 'วันที่ไม่ถูกต้อง';
            durationField.style.color = 'red';
        } else {
            durationField.value = `${diffDays} วัน (ประมาณ ${diffMonths} เดือน)`;
            durationField.style.color = '';
        }
    } else {
        durationField.value = '';
    }
}

// ฟังก์ชันตรวจสอบการเปลี่ยนแปลงในฟอร์ม
function checkForChanges() {
    const currentFormData = new FormData(document.querySelector('form'));
    let hasChanges = false;
    
    for (let [key, value] of currentFormData.entries()) {
        if (originalFormData.get(key) !== value) {
            hasChanges = true;
            break;
        }
    }
    
    return hasChanges;
}

// ฟังก์ชันรีเซ็ตฟอร์ม
function resetForm() {
    if (confirm('คุณต้องการยกเลิกการเปลี่ยนแปลงทั้งหมดหรือไม่?')) {
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
    }, false);
    
    // Real-time validation feedback
    const requiredInputs = document.querySelectorAll('input[required], select[required]');
    requiredInputs.forEach(input => {
        input.addEventListener('blur', function() {
            if (this.checkValidity()) {
                this.classList.add('is-valid');
                this.classList.remove('is-invalid');
            } else {
                this.classList.add('is-invalid');
                this.classList.remove('is-valid');
            }
        });
        
        input.addEventListener('input', function() {
            if (this.classList.contains('is-invalid') && this.checkValidity()) {
                this.classList.remove('is-invalid');
                this.classList.add('is-valid');
            }
        });
    });
    
    // เตือนเมื่อออกจากหน้าโดยไม่บันทึก
    let formChanged = false;
    form.addEventListener('input', function() {
        formChanged = checkForChanges();
    });
    
    form.addEventListener('change', function() {
        formChanged = checkForChanges();
    });
    
    window.addEventListener('beforeunload', function(e) {
        if (formChanged) {
            e.preventDefault();
            e.returnValue = 'คุณมีการเปลี่ยนแปลงข้อมูลที่ยังไม่ได้บันทึก คุณต้องการออกจากหน้านี้หรือไม่?';
            return e.returnValue;
        }
    });
    
    // ลบการเตือนเมื่อส่งฟอร์มสำเร็จ
    form.addEventListener('submit', function() {
        if (form.checkValidity()) {
            formChanged = false;
        }
    });
    
    // เพิ่ม tooltip สำหรับสถานะสัญญา
    const statusSelect = document.getElementById('contract_status');
    statusSelect.addEventListener('change', function() {
        const selectedOption = this.options[this.selectedIndex];
        let message = '';
        
        switch (this.value) {
            case 'active':
                message = 'สัญญาใช้งานปกติ ห้องจะมีสถานะ "มีผู้เช่า"';
                break;
            case 'expired':
                message = 'สัญญาหมดอายุ ห้องจะว่างและสามารถให้เช่าใหม่ได้';
                break;
            case 'terminated':
                message = 'สัญญาถูกยกเลิก ต้องชำระใบแจ้งหนี้ที่ค้างอยู่ให้หมดก่อน';
                break;
        }
        
        // แสดง tooltip หรือ alert เล็กๆ
        if (message) {
            // สร้าง tooltip element ถ้าไม่มี
            let tooltip = document.getElementById('status-tooltip');
            if (!tooltip) {
                tooltip = document.createElement('div');
                tooltip.id = 'status-tooltip';
                tooltip.className = 'small text-muted mt-1';
                statusSelect.parentNode.appendChild(tooltip);
            }
            tooltip.textContent = message;
        }
    });
    
    // Trigger tooltip เมื่อโหลดหน้า
    statusSelect.dispatchEvent(new Event('change'));
});

// ฟังก์ชันเพิ่มเติมสำหรับการจัดการฟอร์ม
function validateDates() {
    const startDate = document.getElementById('contract_start').value;
    const endDate = document.getElementById('contract_end').value;
    
    if (startDate && endDate) {
        const start = new Date(startDate);
        const end = new Date(endDate);
        
        if (end <= start) {
            document.getElementById('contract_end').setCustomValidity('วันที่สิ้นสุดต้องมากกว่าวันที่เริ่มสัญญา');
            return false;
        } else {
            document.getElementById('contract_end').setCustomValidity('');
            return true;
        }
    }
    return true;
}

// ฟังก์ชันจัดรูปแบบตัวเลข
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
document.addEventListener('DOMContentLoaded', function() {
    const numberInputs = document.querySelectorAll('#monthly_rent, #deposit_paid');
    numberInputs.forEach(formatNumberInput);
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
    background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 8 8'%3e%3cpath fill='%23198754' d='m2.3 6.73.98-.98 2.49-2.49c.15-.15.15-.39 0-.54s-.39-.15-.54 0L3.26 5.17l-.98-.98c-.15-.15-.39-.15-.54 0s-.15.39 0 .54z'/%3e%3c/svg%3e");
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
    background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 12 12' width='12' height='12' fill='none' stroke='%23dc3545'%3e%3ccircle cx='6' cy='6' r='4.5'/%3e%3cpath d='m5.8 4.6 2.4 2.4'/%3e%3cpath d='m8.2 4.6-2.4 2.4'/%3e%3c/svg%3e");
    background-repeat: no-repeat;
    background-position: right calc(0.375em + 0.1875rem) center;
    background-size: calc(0.75em + 0.375rem) calc(0.75em + 0.375rem);
}

/* Button enhancements */
.btn {
    font-weight: 500;
    border-radius: 0.375rem;
    transition: all 0.15s ease-in-out;
}

.btn:hover {
    transform: translateY(-1px);
}

.btn-lg {
    padding: 0.75rem 1.5rem;
    font-size: 1.125rem;
}

/* Card border variations */
.border-warning {
    border-color: #ffc107 !important;
}

.bg-warning {
    background-color: #ffc107 !important;
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

/* Responsive adjustments */
@media (max-width: 768px) {
    .card-body {
        padding: 1rem;
    }
    
    .btn-group {
        flex-direction: column;
        width: 100%;
    }
    
    .btn-group .btn {
        margin-bottom: 0.25rem;
        border-radius: 0.375rem !important;
    }
    
    .row.g-2 > .col-12,
    .row > .col-md-6 {
        margin-bottom: 1rem;
    }
    
    .d-grid.gap-2 .btn {
        margin-bottom: 0.5rem;
    }
}

/* Loading state for form submission */
.btn:disabled {
    opacity: 0.65;
    cursor: not-allowed;
}

/* Enhanced input group styling */
.input-group-text {
    background-color: #f8f9fa;
    border-color: #ced4da;
    color: #6c757d;
}

/* Tooltip styling */
#status-tooltip {
    font-style: italic;
    padding: 0.25rem 0;
    border-left: 3px solid #0d6efd;
    padding-left: 0.5rem;
    margin-top: 0.25rem;
}
</style>

<?php include 'includes/footer.php'; ?>                <h5><?php echo $contract['first_name'] . ' ' . $contract['last_name']; ?></h5>
                                <p class="text-muted mb-0">ผู้เช่า</p>
                            </div>
                            
                            <div class="row g-2">
                                <div class="col-12">
                                    <small class="text-muted">เลขบัตรประชาชน:</small>
                                    <div><?php echo $contract['id_card']; ?></div>
                                </div>
                                <div class="col-12">
                                    <small class="text-muted">เบอร์โทรศัพท์:</small>
                                    <div>
                                        <i class="bi bi-telephone"></i>
                                        <?php echo $contract['phone']; ?>
                                    </div>
                                </div>
                                <?php if ($contract['email']): ?>
                                <div class="col-12">
                                    <small class="text-muted">อีเมล:</small>
                                    <div>
                                        <i class="bi bi-envelope"></i>
                                        <?php echo $contract['email']; ?>
                                    </div>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- ข้อมูลห้องพัก -->
                    <div class="card mb-4">
                        <div class="card-header">
                            <h5 class="mb-0">
                                <i class="bi bi-door-open-fill"></i>
                                ข้อมูลห้องพัก
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="text-center mb-3">
                                <div class="badge bg-info fs-3 p-3 rounded-circle mb-2">
                                    <?php echo $contract['room_number']; ?>
                                </div>
                                <h5>ห้อง <?php echo $contract['room_number']; ?></h5>
                            </div>
                            
                            <div class="row g-2">
                                <div class="col-12">
                                    <small class="text-muted">ประเภทห้อง:</small>
                                    <div>
                                        <?php 
                                        $room_types = [
                                            'single' => 'ห้องเดี่ยว',
                                            'double' => 'ห้องคู่', 
                                            'triple' => 'ห้องสามเตียง'
                                        ];
                                        echo $room_types[$contract['room_type']] ?? $contract['room_type']; 
                                        ?>
                                    </div>
                                </div>
                                <div class="col-12">
                                    <small class="text-muted">ค่าเช่าปกติ:</small>
                                    <div class="text-info"><?php echo formatCurrency($contract['room_monthly_rent']); ?></div>
                                </div>
                                <div class="col-12">
                                    <small class="text-muted">เงินมัดจำปกติ:</small>
                                    <div class="text-info"><?php echo formatCurrency($contract['room_deposit']); ?></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- คำเตือน -->
                    <div class="card border-warning">
                        <div class="card-header bg-warning text-dark">
                            <h6 class="mb-0">
                                <i class="bi bi-exclamation-triangle"></i>
                                ข้อควรระวัง
                            </h6>
                        </div>
                        <div class="card-body">
                            <ul class="mb-0 small">
                                <li>การแก้ไขข้อมูลสัญญาจะมีผลต่อใบแจ้งหนี้ที่ยังไม่ได้สร้าง</li>
                                <li>หากเปลี่ยนสถานะเป็น "ยกเลิก" จะต้องชำระใบแจ้งหนี้ทั้งหมดก่อน</li>
                                <li>การเปลี่ยนแปลงจะถูกบันทึกในประวัติระบบ</li>
                            </ul>
                        </div>
                    </div>
                </div>

                <!-- ฟอร์มแก้ไขข้อมูล -->
                <div class="col-lg-8">
                    <!-- ข้อมูลสัญญา -->
                    <div class="card mb-4">
                        <div class="card-header">
                            <h5 class="mb-0">
                                <i class="bi bi-file-earmark-text"></i>
                                รายละเอียดสัญญา
                            </h5>
                        </div>
                        <div class="card-body">
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

                                <div class="col-md-6 mb-3">
                                    <label for="monthly_rent" class="form-label">
                                        ค่าเช่าต่อเดือน <span class="text-danger">*</span>
                                    </label>
                                    <div class="input-group">
                                        <input type="number" class="form-control" id="monthly_rent" name="monthly_rent" 
                                               value="<?php echo isset($_POST['monthly_rent']) ? $_POST['monthly_rent'] : $contract['monthly_rent']; ?>" 
                                               min="0" step="0.01" required>
                                        <span class="input-group-text">บาท</span>
                                    </div>
                                    <div class="invalid-feedback">
                                        กรุณากรอกค่าเช่าต่อเดือน
                                    </div>
                                    <small class="text-muted">
                                        ค่าเช่าปกติของห้องนี้: <?php echo formatCurrency($contract['room_monthly_rent']); ?>
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
                                    </div>
                                    <div class="invalid-feedback">
                                        กรุณากรอกจำนวนเงินมัดจำ
                                    </div>
                                    <small class="text-muted">
                                        เงินมัดจำปกติของห้องนี้: <?php echo formatCurrency($contract['room_deposit']); ?>
                                    </small>
                                </div>

                                <div class="col-md-6 mb-3">
                                    <label for="contract_status" class="form-label">
                                        สถานะสัญญา <span class="text-danger">*</span>
                                    </label>
                                    <select class="form-select" id="contract_status" name="contract_status" required>
                                        <option value="active" <?php echo (isset($_POST['contract_status']) ? $_POST['contract_status'] : $contract['contract_status']) == 'active' ? 'selected' : ''; ?>>
                                            ใช้งาน
                                        </option>
                                        <option value="expired" <?php echo (isset($_POST['contract_status']) ? $_POST['contract_status'] : $contract['contract_status']) == 'expired' ? 'selected' : ''; ?>>
                                            หมดอายุ
                                        </option>
                                        <option value="terminated" <?php echo (isset($_POST['contract_status']) ? $_POST['contract_status'] : $contract['contract_status']) == 'terminated' ? 'selected' : ''; ?>>
                                            ยกเลิก
                                        </option>
                                    </select>
                                    <div class="invalid-feedback">
                                        กรุณาเลือกสถานะสัญญา
                                    </div>
                                </div>

                                <div class="col-md-6 mb-3">
                                    <label class="form-label">ระยะเวลาสัญญา</label>
                                    <input type="text" class="form-control" id="contract_duration" readonly 
                                           style="background-color: #f8f9fa;">
                                    <small class="text-muted">คำนวณอัตโนมัติจากวันที่เริ่มและสิ้นสุดสัญญา</small>
                                </div>

                                <div class="col-12 mb-3">
                                    <label for="special_conditions" class="form-label">
                                        เงื่อนไขพิเศษ
                                    </label>
                                    <textarea class="form-control" id="special_conditions" name="special_conditions" 
                                              rows="4" placeholder="ระบุเงื่อนไขพิเศษของสัญญา (ถ้ามี)"><?php echo isset($_POST['special_conditions']) ? htmlspecialchars($_POST['special_conditions']) : htmlspecialchars($contract['special_conditions'] ?? ''); ?></textarea>
                                    <small class="text-muted">
                                        เช่น ข้อกำหนดพิเศษ, เงื่อนไขการชำระเงิน, ข้อจำกัดต่างๆ
                                    </small>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ข้อมูลเพิ่มเติม -->
                    <div class="card mb-4">
                        <div class="card-header">
                            <h5 class="mb-0">
                                <i class="bi bi-info-circle"></i>
                                ข้อมูลเพิ่มเติม
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <label class="form-label fw-bold">วันที่สร้างสัญญา:</label>
                                    <p class="mb-0"><?php echo formatDateTime($contract['created_at']); ?></p>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold">หมายเลขสัญญา:</label>
                                    <p class="mb-0">#<?php echo $contract['contract_id']; ?></p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ปุ่มดำเนินการ -->
                    <div class="card">
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="d-grid gap-2">
                                        <button type="submit" class="btn btn-primary btn-lg">
                                            <i class="bi bi-check-circle"></i>
                                            บันทึกการเปลี่ยนแปลง
                                        </button>
                                        <button type="reset" class="btn btn-secondary" onclick="resetForm()">
                                            <i class="bi bi-arrow-clockwise"></i>
                                            รีเซ็ตฟอร์ม
                                        </button>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="d-grid gap-2">
                                        <a href="view_contract.php?id=<?php echo $contract['contract_id']; ?>" class="btn btn-info">
                                            <i class="bi bi-eye"></i>
                                            ดูข้อมูลสัญญา
                                        </a>
                                        <a href="contracts.php" class="btn btn-outline-secondary">
                                            <i class="bi bi-arrow-left"></i>
                                            ยกเลิกการแก้ไข
                                        </a>
                                    </div>
                                </div>
                            </div>