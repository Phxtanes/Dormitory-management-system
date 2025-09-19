<?php
$page_title = "เพิ่มห้องพัก";
require_once 'includes/header.php';

$success_message = '';
$error_message = '';

// ตรวจสอบการส่งฟอร์ม
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
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
    
    // ตรวจสอบหมายเลขห้องซ้ำ
    if (empty($errors)) {
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM rooms WHERE room_number = ?");
            $stmt->execute([$room_number]);
            $count = $stmt->fetch()['count'];
            
            if ($count > 0) {
                $errors[] = "หมายเลขห้อง $room_number มีอยู่ในระบบแล้ว";
            }
        } catch(PDOException $e) {
            $errors[] = "เกิดข้อผิดพลาดในการตรวจสอบข้อมูล";
        }
    }
    
    // บันทึกข้อมูล
    if (empty($errors)) {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO rooms (room_number, room_type, monthly_rent, deposit, floor_number, room_description, room_status) 
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $room_number,
                $room_type,
                $monthly_rent,
                $deposit,
                $floor_number,
                $room_description,
                $room_status
            ]);
            
            $success_message = "เพิ่มห้องพัก $room_number เรียบร้อยแล้ว";
            
            // รีเซ็ตฟอร์ม
            $_POST = [];
            
        } catch(PDOException $e) {
            $error_message = "เกิดข้อผิดพลาด: " . $e->getMessage();
        }
    } else {
        $error_message = implode('<br>', $errors);
    }
}
?>

<?php include 'includes/navbar.php'; ?>

<div class="container-fluid mt-4">
    <div class="row justify-content-center">
        <div class="col-lg-8">
            <!-- หัวข้อหน้า -->
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2 class="mb-0">
                    <i class="bi bi-plus-circle"></i>
                    เพิ่มห้องพักใหม่
                </h2>
                <a href="rooms.php" class="btn btn-secondary">
                    <i class="bi bi-arrow-left"></i>
                    กลับไปรายการห้องพัก
                </a>
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

            <!-- ฟอร์มเพิ่มห้องพัก -->
            <div class="card">
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
                                       value="<?php echo isset($_POST['room_number']) ? htmlspecialchars($_POST['room_number']) : ''; ?>" 
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
                                    <option value="single" <?php echo (isset($_POST['room_type']) && $_POST['room_type'] == 'single') ? 'selected' : ''; ?>>
                                        ห้องเดี่ยว (Single)
                                    </option>
                                    <option value="double" <?php echo (isset($_POST['room_type']) && $_POST['room_type'] == 'double') ? 'selected' : ''; ?>>
                                        ห้องคู่ (Double)
                                    </option>
                                    <option value="triple" <?php echo (isset($_POST['room_type']) && $_POST['room_type'] == 'triple') ? 'selected' : ''; ?>>
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
                                       value="<?php echo isset($_POST['floor_number']) ? $_POST['floor_number'] : ''; ?>" 
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
                                       value="<?php echo isset($_POST['monthly_rent']) ? $_POST['monthly_rent'] : ''; ?>" 
                                       min="0" step="0.01" required>
                                <div class="invalid-feedback">
                                    กรุณากรอกค่าเช่าที่ถูกต้อง
                                </div>
                            </div>

                            <div class="col-md-4 mb-3">
                                <label for="deposit" class="form-label">
                                    เงินมัดจำ (บาท)
                                </label>
                                <input type="number" class="form-control" id="deposit" name="deposit" 
                                       value="<?php echo isset($_POST['deposit']) ? $_POST['deposit'] : ''; ?>" 
                                       min="0" step="0.01">
                                <small class="form-text text-muted">หากไม่มีให้ใส่ 0</small>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-12 mb-3">
                                <label for="room_status" class="form-label">
                                    สถานะห้อง
                                </label>
                                <select class="form-select" id="room_status" name="room_status">
                                    <option value="available" <?php echo (isset($_POST['room_status']) && $_POST['room_status'] == 'available') ? 'selected' : 'selected'; ?>>
                                        ว่าง (Available)
                                    </option>
                                    <option value="maintenance" <?php echo (isset($_POST['room_status']) && $_POST['room_status'] == 'maintenance') ? 'selected' : ''; ?>>
                                        ปรับปรุง (Maintenance)
                                    </option>
                                </select>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-12 mb-3">
                                <label for="room_description" class="form-label">
                                    คำอธิบายห้อง
                                </label>
                                <textarea class="form-control" id="room_description" name="room_description" 
                                          rows="3" placeholder="รายละเอียดเพิ่มเติมเกี่ยวกับห้อง เช่น สิ่งอำนวยความสะดวก วิว ฯลฯ"><?php echo isset($_POST['room_description']) ? htmlspecialchars($_POST['room_description']) : ''; ?></textarea>
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
                                        บันทึกข้อมูล
                                    </button>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <!-- ตัวอย่างการคำนวณ -->
            <div class="card mt-4">
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
                                <p class="text-muted mb-0" id="rent-display">0.00 บาท</p>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="border rounded p-3 h-100">
                                <i class="bi bi-piggy-bank text-success fs-2"></i>
                                <h6 class="mt-2">เงินมัดจำ</h6>
                                <p class="text-muted mb-0" id="deposit-display">0.00 บาท</p>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="border rounded p-3 h-100">
                                <i class="bi bi-cash-stack text-warning fs-2"></i>
                                <h6 class="mt-2">รวมเงินต้องจ่าย</h6>
                                <p class="text-muted mb-0" id="total-display">0.00 บาท</p>
                                <small class="text-muted">(เดือนแรก)</small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
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
    document.getElementById('monthly_rent').addEventListener('input', updateCalculation);
    document.getElementById('deposit').addEventListener('input', updateCalculation);
    
    // เรียกใช้ครั้งแรก
    updateCalculation();
    
    // ฟังก์ชันฟอร์แมตเงิน
    function formatCurrency(amount) {
        return new Intl.NumberFormat('th-TH', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        }).format(amount) + ' บาท';
    }
});
</script>
<?php include 'includes/footer.php'; ?>
