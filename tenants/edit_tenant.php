<?php
$page_title = "แก้ไขข้อมูลผู้เช่า";
require_once 'includes/header.php';

$success_message = '';
$error_message = '';

// ตรวจสอบ ID ผู้เช่า
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header('Location: tenants.php');
    exit;
}

$tenant_id = intval($_GET['id']);

// ดึงข้อมูลผู้เช่าเดิม
try {
    $stmt = $pdo->prepare("SELECT * FROM tenants WHERE tenant_id = ?");
    $stmt->execute([$tenant_id]);
    $tenant = $stmt->fetch();
    
    if (!$tenant) {
        header('Location: tenants.php');
        exit;
    }
} catch(PDOException $e) {
    $error_message = "เกิดข้อผิดพลาดในการดึงข้อมูล: " . $e->getMessage();
}

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
    
    // ตรวจสอบเลขบัตรประชาชนซ้ำ (ยกเว้นของตัวเอง)
    if (empty($errors)) {
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM tenants WHERE id_card = ? AND tenant_id != ?");
            $stmt->execute([$id_card, $tenant_id]);
            $count = $stmt->fetch()['count'];
            
            if ($count > 0) {
                $errors[] = "เลขบัตรประชาชน $id_card มีอยู่ในระบบแล้ว";
            }
        } catch(PDOException $e) {
            $errors[] = "เกิดข้อผิดพลาดในการตรวจสอบข้อมูล";
        }
    }
    
    // ตรวจสอบหมายเลขโทรศัพท์ซ้ำ (ยกเว้นของตัวเอง)
    if (empty($errors)) {
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM tenants WHERE phone = ? AND tenant_id != ?");
            $stmt->execute([$phone, $tenant_id]);
            $count = $stmt->fetch()['count'];
            
            if ($count > 0) {
                $errors[] = "หมายเลขโทรศัพท์ $phone มีอยู่ในระบบแล้ว";
            }
        } catch(PDOException $e) {
            $errors[] = "เกิดข้อผิดพลาดในการตรวจสอบข้อมูล";
        }
    }
    
    // ตรวจสอบการเปลี่ยนแปลงสถานะ
    if ($tenant['tenant_status'] == 'active' && $tenant_status == 'inactive') {
        // ตรวจสอบว่ามีสัญญาใช้งานอยู่หรือไม่
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM contracts WHERE tenant_id = ? AND contract_status = 'active'");
            $stmt->execute([$tenant_id]);
            $active_contracts = $stmt->fetch()['count'];
            
            if ($active_contracts > 0) {
                $errors[] = "ไม่สามารถเปลี่ยนสถานะเป็น 'ไม่ใช้งาน' ได้ เนื่องจากมีสัญญาที่ใช้งานอยู่";
            }
        } catch(PDOException $e) {
            $errors[] = "เกิดข้อผิดพลาดในการตรวจสอบสัญญา";
        }
    }
    
    // บันทึกข้อมูล
    if (empty($errors)) {
        try {
            $stmt = $pdo->prepare("
                UPDATE tenants SET 
                first_name = ?, last_name = ?, phone = ?, email = ?, id_card = ?, 
                address = ?, emergency_contact = ?, emergency_phone = ?, tenant_status = ?
                WHERE tenant_id = ?
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
                $tenant_status,
                $tenant_id
            ]);
            
            $success_message = "อัพเดทข้อมูลผู้เช่า $first_name $last_name เรียบร้อยแล้ว";
            
            // อัพเดทข้อมูลใน $tenant array สำหรับการแสดงผล
            $tenant['first_name'] = $first_name;
            $tenant['last_name'] = $last_name;
            $tenant['phone'] = $phone;
            $tenant['email'] = $email;
            $tenant['id_card'] = $id_card;
            $tenant['address'] = $address;
            $tenant['emergency_contact'] = $emergency_contact;
            $tenant['emergency_phone'] = $emergency_phone;
            $tenant['tenant_status'] = $tenant_status;
            
        } catch(PDOException $e) {
            $error_message = "เกิดข้อผิดพลาด: " . $e->getMessage();
        }
    } else {
        $error_message = implode('<br>', $errors);
    }
}

// ตรวจสอบว่ามีสัญญาใช้งานอยู่หรือไม่
$has_active_contract = false;
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM contracts WHERE tenant_id = ? AND contract_status = 'active'");
    $stmt->execute([$tenant_id]);
    $has_active_contract = $stmt->fetch()['count'] > 0;
} catch(PDOException $e) {
    // ไม่ต้องทำอะไร ใช้ค่าเริ่มต้น false
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
                    <i class="bi bi-person-gear"></i>
                    แก้ไขข้อมูลผู้เช่า
                </h2>
                <div class="btn-group">
                    <a href="view_tenant.php?id=<?php echo $tenant['tenant_id']; ?>" class="btn btn-info">
                        <i class="bi bi-eye"></i>
                        ดูรายละเอียด
                    </a>
                    <a href="tenants.php" class="btn btn-secondary">
                        <i class="bi bi-arrow-left"></i>
                        กลับไปรายการผู้เช่า
                    </a>
                </div>
            </div>

            <!-- แสดงข้อความแจ้งเตือน -->
            <?php if (!empty($success_message)): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="bi bi-check-circle"></i>
                    <?php echo $success_message; ?>
                    <div class="mt-2">
                        <a href="view_tenant.php?id=<?php echo $tenant['tenant_id']; ?>" class="btn btn-sm btn-outline-success me-2">
                            <i class="bi bi-eye"></i>
                            ดูรายละเอียด
                        </a>
                        <a href="tenants.php" class="btn btn-sm btn-outline-primary">
                            <i class="bi bi-list"></i>
                            กลับไปรายการผู้เช่า
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

            <!-- แสดงสถานะปัจจุบัน -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0">
                        <i class="bi bi-info-circle"></i>
                        ข้อมูลปัจจุบัน
                    </h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="d-flex align-items-center">
                                <div class="avatar bg-primary text-white rounded-circle me-3" style="width: 50px; height: 50px; display: flex; align-items: center; justify-content: center; font-size: 1.2rem; font-weight: 600;">
                                    <?php echo mb_substr($tenant['first_name'], 0, 1, 'UTF-8'); ?>
                                </div>
                                <div>
                                    <h5 class="mb-1"><?php echo $tenant['first_name'] . ' ' . $tenant['last_name']; ?></h5>
                                    <div class="text-muted"><?php echo $tenant['phone']; ?></div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6 text-md-end">
                            <div class="mb-2">
                                <?php
                                $status_class = $tenant['tenant_status'] == 'active' ? 'bg-success' : 'bg-secondary';
                                $status_text = $tenant['tenant_status'] == 'active' ? 'ใช้งานอยู่' : 'ไม่ใช้งาน';
                                ?>
                                <span class="badge <?php echo $status_class; ?> fs-6">
                                    <i class="bi bi-<?php echo $tenant['tenant_status'] == 'active' ? 'check-circle' : 'x-circle'; ?>"></i>
                                    <?php echo $status_text; ?>
                                </span>
                            </div>
                            <?php if ($has_active_contract): ?>
                                <div class="alert alert-info mb-0 py-2">
                                    <i class="bi bi-house-fill"></i>
                                    <small>มีสัญญาใช้งานอยู่</small>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ฟอร์มแก้ไขข้อมูล -->
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">
                        <i class="bi bi-person-fill"></i>
                        แก้ไขข้อมูลส่วนตัว
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
                                       value="<?php echo htmlspecialchars($tenant['first_name']); ?>" 
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
                                       value="<?php echo htmlspecialchars($tenant['last_name']); ?>" 
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
                                       value="<?php echo htmlspecialchars($tenant['phone']); ?>" 
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
                                       value="<?php echo htmlspecialchars($tenant['email'] ?? ''); ?>" 
                                       placeholder="example@email.com">
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-8 mb-3">
                                <label for="id_card" class="form-label">
                                    เลขบัตรประชาชน <span class="text-danger">*</span>
                                </label>
                                <input type="text" class="form-control" id="id_card" name="id_card" 
                                       value="<?php echo htmlspecialchars($tenant['id_card']); ?>" 
                                       placeholder="1234567890123" maxlength="13" pattern="[0-9]{13}" required>
                                <div class="invalid-feedback">
                                    กรุณากรอกเลขบัตรประชาชน 13 หลัก
                                </div>
                            </div>

                            <div class="col-md-4 mb-3">
                                <label for="tenant_status" class="form-label">
                                    สถานะ <span class="text-danger">*</span>
                                </label>
                                <select class="form-select" id="tenant_status" name="tenant_status" required>
                                    <option value="active" <?php echo $tenant['tenant_status'] == 'active' ? 'selected' : ''; ?>>
                                        ใช้งาน
                                    </option>
                                    <option value="inactive" 
                                            <?php echo $tenant['tenant_status'] == 'inactive' ? 'selected' : ''; ?>
                                            <?php echo $has_active_contract ? 'disabled' : ''; ?>>
                                        ไม่ใช้งาน
                                        <?php echo $has_active_contract ? ' (ไม่สามารถเลือกได้ เนื่องจากมีสัญญาใช้งานอยู่)' : ''; ?>
                                    </option>
                                </select>
                                <?php if ($has_active_contract): ?>
                                    <div class="form-text text-warning">
                                        <i class="bi bi-exclamation-triangle"></i>
                                        ไม่สามารถเปลี่ยนเป็น 'ไม่ใช้งาน' ได้ เนื่องจากมีสัญญาใช้งานอยู่
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-12 mb-3">
                                <label for="address" class="form-label">
                                    ที่อยู่
                                </label>
                                <textarea class="form-control" id="address" name="address" 
                                          rows="3" placeholder="ที่อยู่ปัจจุบัน"><?php echo htmlspecialchars($tenant['address'] ?? ''); ?></textarea>
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
                                       value="<?php echo htmlspecialchars($tenant['emergency_contact'] ?? ''); ?>" 
                                       placeholder="ชื่อ-นามสกุล ผู้ติดต่อฉุกเฉิน">
                            </div>

                            <div class="col-md-6 mb-3">
                                <label for="emergency_phone" class="form-label">
                                    หมายเลขโทรศัพท์ฉุกเฉิน
                                </label>
                                <input type="tel" class="form-control" id="emergency_phone" name="emergency_phone" 
                                       value="<?php echo htmlspecialchars($tenant['emergency_phone'] ?? ''); ?>" 
                                       placeholder="0xx-xxx-xxxx">
                            </div>
                        </div>

                        <hr>

                        <!-- สรุปการเปลี่ยนแปลง -->
                        <div class="card bg-light mb-4" id="changes-summary" style="display: none;">
                            <div class="card-header">
                                <h6 class="mb-0">
                                    <i class="bi bi-pencil-square"></i>
                                    สรุปการเปลี่ยนแปลง
                                </h6>
                            </div>
                            <div class="card-body">
                                <div id="changes-list"></div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-12">
                                <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                                    <a href="view_tenant.php?id=<?php echo $tenant['tenant_id']; ?>" class="btn btn-secondary me-md-2">
                                        <i class="bi bi-x-circle"></i>
                                        ยกเลิก
                                    </a>
                                    <button type="submit" class="btn btn-primary" id="save-button" disabled>
                                        <i class="bi bi-check-circle"></i>
                                        บันทึกการเปลี่ยนแปลง
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
                        หมายเหตุการแก้ไข
                    </h6>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-4">
                            <div class="text-center p-3 border rounded">
                                <i class="bi bi-shield-check text-success fs-2"></i>
                                <h6 class="mt-2">ความปลอดภัย</h6>
                                <small class="text-muted">ข้อมูลจะถูกตรวจสอบก่อนบันทึก</small>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="text-center p-3 border rounded">
                                <i class="bi bi-person-check text-primary fs-2"></i>
                                <h6 class="mt-2">การตรวจสอบ</h6>
                                <small class="text-muted">ตรวจสอบข้อมูลซ้ำก่อนบันทึก</small>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="text-center p-3 border rounded">
                                <i class="bi bi-file-earmark-text text-warning fs-2"></i>
                                <h6 class="mt-2">สัญญา</h6>
                                <small class="text-muted">การเปลี่ยนแปลงจะไม่กระทบสัญญา</small>
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
    // ข้อมูลเดิม
    const originalData = {
        first_name: <?php echo json_encode($tenant['first_name']); ?>,
        last_name: <?php echo json_encode($tenant['last_name']); ?>,
        phone: <?php echo json_encode($tenant['phone']); ?>,
        email: <?php echo json_encode($tenant['email'] ?? ''); ?>,
        id_card: <?php echo json_encode($tenant['id_card']); ?>,
        address: <?php echo json_encode($tenant['address'] ?? ''); ?>,
        emergency_contact: <?php echo json_encode($tenant['emergency_contact'] ?? ''); ?>,
        emergency_phone: <?php echo json_encode($tenant['emergency_phone'] ?? ''); ?>,
        tenant_status: <?php echo json_encode($tenant['tenant_status']); ?>
    };
    
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
    
    // ฟังก์ชันตรวจสอบการเปลี่ยนแปลง
    function checkForChanges() {
        const currentData = {
            first_name: document.getElementById('first_name').value.trim(),
            last_name: document.getElementById('last_name').value.trim(),
            phone: document.getElementById('phone').value.trim(),
            email: document.getElementById('email').value.trim(),
            id_card: document.getElementById('id_card').value.trim(),
            address: document.getElementById('address').value.trim(),
            emergency_contact: document.getElementById('emergency_contact').value.trim(),
            emergency_phone: document.getElementById('emergency_phone').value.trim(),
            tenant_status: document.getElementById('tenant_status').value
        };
        
        const changes = [];
        const fieldLabels = {
            first_name: 'ชื่อ',
            last_name: 'นามสกุล',
            phone: 'โทรศัพท์',
            email: 'อีเมล',
            id_card: 'เลขบัตรประชาชน',
            address: 'ที่อยู่',
            emergency_contact: 'ผู้ติดต่อฉุกเฉิน',
            emergency_phone: 'โทรศัพท์ฉุกเฉิน',
            tenant_status: 'สถานะ'
        };
        
        for (const field in originalData) {
            if (currentData[field] !== originalData[field]) {
                let oldValue = originalData[field] || '-';
                let newValue = currentData[field] || '-';
                
                // แปลงค่าสถานะ
                if (field === 'tenant_status') {
                    oldValue = oldValue === 'active' ? 'ใช้งาน' : 'ไม่ใช้งาน';
                    newValue = newValue === 'active' ? 'ใช้งาน' : 'ไม่ใช้งาน';
                }
                
                changes.push({
                    field: fieldLabels[field],
                    old: oldValue,
                    new: newValue
                });
            }
        }
        
        const hasChanges = changes.length > 0;
        const saveButton = document.getElementById('save-button');
        const changesSummary = document.getElementById('changes-summary');
        const changesList = document.getElementById('changes-list');
        
        saveButton.disabled = !hasChanges;
        
        if (hasChanges) {
            changesSummary.style.display = 'block';
            let changesHtml = '<div class="row">';
            
            changes.forEach((change, index) => {
                changesHtml += `
                    <div class="col-md-6 mb-2">
                        <div class="border rounded p-2 bg-white">
                            <strong>${change.field}:</strong><br>
                            <span class="text-muted">เดิม: ${change.old}</span><br>
                            <span class="text-primary">ใหม่: ${change.new}</span>
                        </div>
                    </div>
                `;
            });
            
            changesHtml += '</div>';
            changesList.innerHTML = changesHtml;
        } else {
            changesSummary.style.display = 'none';
        }
        
        return hasChanges;
    }
    
    // Format ID card input
    const idCardInput = document.getElementById('id_card');
    idCardInput.addEventListener('input', function(e) {
        let value = e.target.value.replace(/\D/g, '');
        if (value.length > 13) {
            value = value.substring(0, 13);
        }
        e.target.value = value;
        checkForChanges();
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
            checkForChanges();
        });
    });
    
    // เพิ่ม event listener สำหรับทุก input
    const formInputs = document.querySelectorAll('input, select, textarea');
    formInputs.forEach(input => {
        input.addEventListener('input', checkForChanges);
        input.addEventListener('change', checkForChanges);
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
    
    // ยืนยันการบันทึก
    document.querySelector('form').addEventListener('submit', function(e) {
        if (!checkForChanges()) {
            e.preventDefault();
            alert('ไม่มีการเปลี่ยนแปลงข้อมูล');
            return false;
        }
        
        if (!confirm('คุณต้องการบันทึกการเปลี่ยนแปลงหรือไม่?')) {
            e.preventDefault();
            return false;
        }
    });
    
    // ตรวจสอบการออกจากหน้าโดยไม่บันทึก
    let formChanged = false;
    formInputs.forEach(input => {
        input.addEventListener('input', () => formChanged = checkForChanges());
        input.addEventListener('change', () => formChanged = checkForChanges());
    });
    
    window.addEventListener('beforeunload', function(e) {
        if (formChanged) {
            e.preventDefault();
            e.returnValue = '';
            return 'คุณมีการเปลี่ยนแปลงข้อมูลที่ยังไม่ได้บันทึก คุณต้องการออกจากหน้านี้หรือไม่?';
        }
    });
    
    // ยกเลิกการตรวจสอบเมื่อส่งฟอร์ม
    document.querySelector('form').addEventListener('submit', function() {
        formChanged = false;
    });
    
    // เรียกใช้ครั้งแรก
    checkForChanges();
});
</script>

<style>
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

#changes-summary {
    border: 1px solid #0d6efd !important;
    background-color: #f0f7ff !important;
}

.border {
    transition: all 0.2s ease-in-out;
}

.border:hover {
    border-color: #0d6efd !important;
    background-color: #f8f9ff !important;
}

@media (max-width: 768px) {
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
    .btn, .alert, #changes-summary {
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

/* Animation สำหรับการแสดง changes summary */
#changes-summary {
    animation: slideDown 0.3s ease-in-out;
}

@keyframes slideDown {
    from {
        opacity: 0;
        transform: translateY(-20px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

/* สไตล์สำหรับปุ่มที่ disabled */
#save-button:disabled {
    opacity: 0.6;
    cursor: not-allowed;
}

#save-button:not(:disabled) {
    animation: pulse 2s infinite;
}

@keyframes pulse {
    0% {
        box-shadow: 0 0 0 0 rgba(13, 110, 253, 0.7);
    }
    70% {
        box-shadow: 0 0 0 10px rgba(13, 110, 253, 0);
    }
    100% {
        box-shadow: 0 0 0 0 rgba(13, 110, 253, 0);
    }
}
</style>

<?php include 'includes/footer.php'; ?>