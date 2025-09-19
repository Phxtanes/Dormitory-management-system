<?php
$page_title = "เพิ่มผู้เช่าใหม่";
require_once 'includes/header.php';

$success_message = '';
$error_message = '';

// ตรวจสอบการส่งฟอร์ม
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $first_name = trim($_POST['first_name']);
    $last_name = trim($_POST['last_name']);
    $phone = trim($_POST['phone']);
    $email = trim($_POST['email']);
    $id_card = trim($_POST['id_card']);
    $address = trim($_POST['address']);
    $emergency_contact = trim($_POST['emergency_contact']);
    $emergency_phone = trim($_POST['emergency_phone']);
    $tenant_status = $_POST['tenant_status'];
    
    // ตรวจสอบข้อมูล
    $errors = [];
    
    if (empty($first_name)) {
        $errors[] = "กรุณากรอกชื่อ";
    }
    
    if (empty($last_name)) {
        $errors[] = "กรุณากรอกนามสกุล";
    }
    
    if (empty($phone)) {
        $errors[] = "กรุณากรอกหมายเลขโทรศัพท์";
    } elseif (!preg_match('/^[0-9-+\s()]+$/', $phone)) {
        $errors[] = "หมายเลขโทรศัพท์ไม่ถูกต้อง";
    }
    
    if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "รูปแบบอีเมลไม่ถูกต้อง";
    }
    
    if (empty($id_card)) {
        $errors[] = "กรุณากรอกเลขบัตรประชาชน";
    } elseif (!preg_match('/^[0-9]{13}$/', $id_card)) {
        $errors[] = "เลขบัตรประชาชนต้องเป็นตัวเลข 13 หลัก";
    }
    
    if (!empty($emergency_phone) && !preg_match('/^[0-9-+\s()]+$/', $emergency_phone)) {
        $errors[] = "หมายเลขโทรศัพท์ฉุกเฉินไม่ถูกต้อง";
    }
    
    // ตรวจสอบเลขบัตรประชาชนซ้ำ
    if (empty($errors)) {
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM tenants WHERE id_card = ?");
            $stmt->execute([$id_card]);
            $count = $stmt->fetch()['count'];
            
            if ($count > 0) {
                $errors[] = "เลขบัตรประชาชน $id_card มีอยู่ในระบบแล้ว";
            }
        } catch(PDOException $e) {
            $errors[] = "เกิดข้อผิดพลาดในการตรวจสอบข้อมูล";
        }
    }
    
    // ตรวจสอบหมายเลขโทรศัพท์ซ้ำ
    if (empty($errors)) {
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM tenants WHERE phone = ?");
            $stmt->execute([$phone]);
            $count = $stmt->fetch()['count'];
            
            if ($count > 0) {
                $errors[] = "หมายเลขโทรศัพท์ $phone มีอยู่ในระบบแล้ว";
            }
        } catch(PDOException $e) {
            $errors[] = "เกิดข้อผิดพลาดในการตรวจสอบข้อมูล";
        }
    }
    
    // บันทึกข้อมูล
    if (empty($errors)) {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO tenants (first_name, last_name, phone, email, id_card, address, emergency_contact, emergency_phone, tenant_status) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $first_name,
                $last_name,
                $phone,
                $email ?: null,
                $id_card,
                $address ?: null,
                $emergency_contact ?: null,
                $emergency_phone ?: null,
                $tenant_status
            ]);
            
            $tenant_id = $pdo->lastInsertId();
            $success_message = "เพิ่มข้อมูลผู้เช่า $first_name $last_name เรียบร้อยแล้ว";
            
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

<style>
    body {
        background-color :#E5FFCC;
    }
</style>

<?php include 'includes/navbar.php'; ?>

<div class="container-fluid mt-4">
    <div class="row justify-content-center">
        <div class="col-lg-8">
            <!-- หัวข้อหน้า -->
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2 class="mb-0">
                    <i class="bi bi-person-plus"></i>
                    เพิ่มผู้เช่าใหม่
                </h2>
                <a href="tenants.php" class="btn btn-secondary">
                    <i class="bi bi-arrow-left"></i>
                    กลับไปรายการผู้เช่า
                </a>
            </div>

            <!-- แสดงข้อความแจ้งเตือน -->
            <?php if (!empty($success_message)): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="bi bi-check-circle"></i>
                    <?php echo $success_message; ?>
                    <div class="mt-2">
                        <a href="tenants.php" class="btn btn-sm btn-outline-success me-2">
                            <i class="bi bi-list"></i>
                            ดูรายการผู้เช่า
                        </a>
                        <a href="add_contract.php?tenant_id=<?php echo isset($tenant_id) ? $tenant_id : ''; ?>" class="btn btn-sm btn-outline-primary">
                            <i class="bi bi-file-earmark-plus"></i>
                            สร้างสัญญาเช่า
                        </a>
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

            <!-- ฟอร์มเพิ่มผู้เช่า -->
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">
                        <i class="bi bi-person-fill"></i>
                        ข้อมูลส่วนตัว
                    </h5>
                </div>
                <div class="card-body">
                    <form method="POST" class="needs-validation" novalidate>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="first_name" class="form-label">
                                    ชื่อ <span class="text-danger">*</span>
                                </label>
                                <input type="text" class="form-control" id="first_name" name="first_name" 
                                       value="<?php echo isset($_POST['first_name']) ? htmlspecialchars($_POST['first_name']) : ''; ?>" 
                                       placeholder="ชื่อจริง" required>
                                <div class="invalid-feedback">
                                    กรุณากรอกชื่อ
                                </div>
                            </div>

                            <div class="col-md-6 mb-3">
                                <label for="last_name" class="form-label">
                                    นามสกุล <span class="text-danger">*</span>
                                </label>
                                <input type="text" class="form-control" id="last_name" name="last_name" 
                                       value="<?php echo isset($_POST['last_name']) ? htmlspecialchars($_POST['last_name']) : ''; ?>" 
                                       placeholder="นามสกุล" required>
                                <div class="invalid-feedback">
                                    กรุณากรอกนามสกุล
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="phone" class="form-label">
                                    หมายเลขโทรศัพท์ <span class="text-danger">*</span>
                                </label>
                                <input type="tel" class="form-control" id="phone" name="phone" 
                                       value="<?php echo isset($_POST['phone']) ? htmlspecialchars($_POST['phone']) : ''; ?>" 
                                       placeholder="0xx-xxx-xxxx" required>
                                <div class="invalid-feedback">
                                    กรุณากรอกหมายเลขโทรศัพท์
                                </div>
                            </div>

                            <div class="col-md-6 mb-3">
                                <label for="email" class="form-label">
                                    อีเมล
                                </label>
                                <input type="email" class="form-control" id="email" name="email" 
                                       value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>" 
                                       placeholder="example@email.com">
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-8 mb-3">
                                <label for="id_card" class="form-label">
                                    เลขบัตรประชาชน <span class="text-danger">*</span>
                                </label>
                                <input type="text" class="form-control" id="id_card" name="id_card" 
                                       value="<?php echo isset($_POST['id_card']) ? htmlspecialchars($_POST['id_card']) : ''; ?>" 
                                       placeholder="1234567890123" maxlength="13" pattern="[0-9]{13}" required>
                                <div class="invalid-feedback">
                                    กรุณากรอกเลขบัตรประชาชน 13 หลัก
                                </div>
                            </div>

                            <div class="col-md-4 mb-3">
                                <label for="tenant_status" class="form-label">
                                    สถานะ
                                </label>
                                <select class="form-select" id="tenant_status" name="tenant_status">
                                    <option value="active" <?php echo (isset($_POST['tenant_status']) && $_POST['tenant_status'] == 'active') ? 'selected' : 'selected'; ?>>
                                        ใช้งาน
                                    </option>
                                    <option value="inactive" <?php echo (isset($_POST['tenant_status']) && $_POST['tenant_status'] == 'inactive') ? 'selected' : ''; ?>>
                                        ไม่ใช้งาน
                                    </option>
                                </select>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-12 mb-3">
                                <label for="address" class="form-label">
                                    ที่อยู่
                                </label>
                                <textarea class="form-control" id="address" name="address" 
                                          rows="3" placeholder="ที่อยู่ปัจจุบัน"><?php echo isset($_POST['address']) ? htmlspecialchars($_POST['address']) : ''; ?></textarea>
                            </div>
                        </div>

                        <hr>

                        <!-- ข้อมูลผู้ติดต่อฉุกเฉิน -->
                        <h6 class="text-primary mb-3">
                            <i class="bi bi-telephone-plus"></i>
                            ข้อมูลผู้ติดต่อฉุกเฉิน
                        </h6>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="emergency_contact" class="form-label">
                                    ชื่อผู้ติดต่อฉุกเฉิน
                                </label>
                                <input type="text" class="form-control" id="emergency_contact" name="emergency_contact" 
                                       value="<?php echo isset($_POST['emergency_contact']) ? htmlspecialchars($_POST['emergency_contact']) : ''; ?>" 
                                       placeholder="ชื่อ-นามสกุล ผู้ติดต่อฉุกเฉิน">
                            </div>

                            <div class="col-md-6 mb-3">
                                <label for="emergency_phone" class="form-label">
                                    หมายเลขโทรศัพท์ฉุกเฉิน
                                </label>
                                <input type="tel" class="form-control" id="emergency_phone" name="emergency_phone" 
                                       value="<?php echo isset($_POST['emergency_phone']) ? htmlspecialchars($_POST['emergency_phone']) : ''; ?>" 
                                       placeholder="0xx-xxx-xxxx">
                            </div>
                        </div>

                        <hr>

                        <div class="row">
                            <div class="col-12">
                                <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                                    <a href="tenants.php" class="btn btn-secondary me-md-2">
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

            <!-- ข้อมูลเพิ่มเติม -->
            <div class="card mt-4">
                <div class="card-header">
                    <h6 class="mb-0">
                        <i class="bi bi-info-circle"></i>
                        ข้อมูลเพิ่มเติม
                    </h6>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-4">
                            <div class="text-center p-3 border rounded">
                                <i class="bi bi-shield-check text-success fs-2"></i>
                                <h6 class="mt-2">ความปลอดภัย</h6>
                                <small class="text-muted">ข้อมูลของคุณจะถูกเก็บอย่างปลอดภัย</small>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="text-center p-3 border rounded">
                                <i class="bi bi-person-check text-primary fs-2"></i>
                                <h6 class="mt-2">การตรวจสอบ</h6>
                                <small class="text-muted">ข้อมูลจะถูกตรวจสอบก่อนบันทึก</small>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="text-center p-3 border rounded">
                                <i class="bi bi-file-earmark-plus text-warning fs-2"></i>
                                <h6 class="mt-2">ขั้นตอนต่อไป</h6>
                                <small class="text-muted">สร้างสัญญาเช่าหลังจากเพิ่มผู้เช่า</small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- คำแนะนำการกรอกข้อมูล -->
            <div class="card mt-4">
                <div class="card-header">
                    <h6 class="mb-0">
                        <i class="bi bi-lightbulb"></i>
                        คำแนะนำการกรอกข้อมูล
                    </h6>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <h6 class="text-primary">ข้อมูลที่จำเป็น:</h6>
                            <ul class="list-unstyled">
                                <li><i class="bi bi-check text-success"></i> ชื่อ-นามสกุล</li>
                                <li><i class="bi bi-check text-success"></i> หมายเลขโทรศัพท์</li>
                                <li><i class="bi bi-check text-success"></i> เลขบัตรประชาชน 13 หลัก</li>
                            </ul>
                        </div>
                        <div class="col-md-6">
                            <h6 class="text-info">ข้อมูลเพิ่มเติม:</h6>
                            <ul class="list-unstyled">
                                <li><i class="bi bi-info text-info"></i> อีเมล</li>
                                <li><i class="bi bi-info text-info"></i> ที่อยู่</li>
                                <li><i class="bi bi-info text-info"></i> ผู้ติดต่อฉุกเฉิน</li>
                            </ul>
                        </div>
                    </div>
                    <div class="alert alert-info mt-3">
                        <i class="bi bi-info-circle"></i>
                        <strong>หมายเหตุ:</strong> เลขบัตรประชาชนและหมายเลขโทรศัพท์จะไม่สามารถซ้ำกับผู้เช่าคนอื่นได้
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
    
    // Format ID card input
    const idCardInput = document.getElementById('id_card');
    idCardInput.addEventListener('input', function(e) {
        // Remove non-digits
        let value = e.target.value.replace(/\D/g, '');
        
        // Limit to 13 digits
        if (value.length > 13) {
            value = value.substring(0, 13);
        }
        
        e.target.value = value;
    });
    
    // Format phone inputs
    const phoneInputs = document.querySelectorAll('input[type="tel"]');
    phoneInputs.forEach(input => {
        input.addEventListener('input', function(e) {
            let value = e.target.value.replace(/\D/g, '');
            
            // Format as xxx-xxx-xxxx
            if (value.length >= 6) {
                value = value.substring(0, 3) + '-' + value.substring(3, 6) + '-' + value.substring(6, 10);
            } else if (value.length >= 3) {
                value = value.substring(0, 3) + '-' + value.substring(3);
            }
            
            e.target.value = value;
        });
    });
    
    // Real-time validation feedback
    const requiredInputs = document.querySelectorAll('input[required]');
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
    
    // Email validation
    const emailInput = document.getElementById('email');
    emailInput.addEventListener('blur', function() {
        if (this.value !== '' && !this.value.match(/^[^\s@]+@[^\s@]+\.[^\s@]+$/)) {
            this.classList.add('is-invalid');
            this.classList.remove('is-valid');
        } else if (this.value !== '') {
            this.classList.remove('is-invalid');
            this.classList.add('is-valid');
        }
    });
    
    // ID card validation
    idCardInput.addEventListener('blur', function() {
        if (this.value.length !== 13) {
            this.classList.add('is-invalid');
            this.classList.remove('is-valid');
        } else {
            this.classList.remove('is-invalid');
            this.classList.add('is-valid');
        }
    });
});
</script>


<?php include 'includes/footer.php'; ?>