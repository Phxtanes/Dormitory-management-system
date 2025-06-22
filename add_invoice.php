<?php
$page_title = "สร้างใบแจ้งหนี้";
require_once 'includes/header.php';

$success_message = '';
$error_message = '';
$contract_id = '';
$contract_info = null;

// รับ contract_id จาก URL
if (isset($_GET['contract_id']) && is_numeric($_GET['contract_id'])) {
    $contract_id = $_GET['contract_id'];
    
    // ดึงข้อมูลสัญญา
    try {
        $stmt = $pdo->prepare("
            SELECT c.*, 
                   t.first_name, t.last_name, t.phone, t.email,
                   r.room_number, r.room_type, r.monthly_rent as room_monthly_rent
            FROM contracts c
            JOIN tenants t ON c.tenant_id = t.tenant_id
            JOIN rooms r ON c.room_id = r.room_id
            WHERE c.contract_id = ? AND c.contract_status = 'active'
        ");
        $stmt->execute([$contract_id]);
        $contract_info = $stmt->fetch();
        
        if (!$contract_info) {
            $error_message = "ไม่พบสัญญาที่ใช้งานอยู่";
        }
    } catch(PDOException $e) {
        $error_message = "เกิดข้อผิดพลาดในการดึงข้อมูลสัญญา: " . $e->getMessage();
    }
}

// ตรวจสอบการส่งฟอร์ม
if ($_SERVER['REQUEST_METHOD'] == 'POST' && $contract_info) {
    $invoice_month = trim($_POST['invoice_month']);
    $room_rent = floatval($_POST['room_rent']);
    $water_charge = floatval($_POST['water_charge']);
    $electric_charge = floatval($_POST['electric_charge']);
    $other_charges = floatval($_POST['other_charges']);
    $other_charges_description = trim($_POST['other_charges_description']);
    $discount = floatval($_POST['discount']);
    $due_date = $_POST['due_date'];
    
    // คำนวณยอดรวม
    $total_amount = $room_rent + $water_charge + $electric_charge + $other_charges - $discount;
    
    // ตรวจสอบข้อมูล
    $errors = [];
    
    if (empty($invoice_month)) {
        $errors[] = "กรุณาเลือกเดือนที่ออกใบแจ้งหนี้";
    }
    
    if ($room_rent <= 0) {
        $errors[] = "ค่าเช่าห้องต้องมากกว่า 0";
    }
    
    if ($water_charge < 0) {
        $errors[] = "ค่าน้ำต้องไม่น้อยกว่า 0";
    }
    
    if ($electric_charge < 0) {
        $errors[] = "ค่าไฟต้องไม่น้อยกว่า 0";
    }
    
    if ($other_charges < 0) {
        $errors[] = "ค่าใช้จ่ายอื่นๆ ต้องไม่น้อยกว่า 0";
    }
    
    if ($discount < 0) {
        $errors[] = "ส่วนลดต้องไม่น้อยกว่า 0";
    }
    
    if ($total_amount <= 0) {
        $errors[] = "ยอดรวมต้องมากกว่า 0";
    }
    
    if (empty($due_date)) {
        $errors[] = "กรุณาระบุวันที่ครบกำหนดชำระ";
    }
    
    // ตรวจสอบใบแจ้งหนี้ซ้ำ
    if (empty($errors)) {
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM invoices WHERE contract_id = ? AND invoice_month = ?");
            $stmt->execute([$contract_id, $invoice_month]);
            $result = $stmt->fetch();
            
            if ($result['count'] > 0) {
                $errors[] = "มีใบแจ้งหนี้สำหรับเดือนนี้แล้ว";
            }
        } catch(PDOException $e) {
            $errors[] = "เกิดข้อผิดพลาดในการตรวจสอบข้อมูล";
        }
    }
    
    // บันทึกข้อมูล
    if (empty($errors)) {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO invoices (
                    contract_id, invoice_month, room_rent, water_charge, 
                    electric_charge, other_charges, other_charges_description, 
                    discount, total_amount, due_date, invoice_status
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')
            ");
            
            $stmt->execute([
                $contract_id, $invoice_month, $room_rent, $water_charge,
                $electric_charge, $other_charges, $other_charges_description,
                $discount, $total_amount, $due_date
            ]);
            
            $invoice_id = $pdo->lastInsertId();
            $success_message = "สร้างใบแจ้งหนี้เรียบร้อยแล้ว";
            
            // รีไดเรกต์ไปหน้าดูใบแจ้งหนี้
            header("Location: view_invoice.php?id=" . $invoice_id . "&success=1");
            exit;
            
        } catch(PDOException $e) {
            $error_message = "เกิดข้อผิดพลาดในการบันทึกข้อมูล: " . $e->getMessage();
        }
    } else {
        $error_message = implode('<br>', $errors);
    }
}

// ค่าเริ่มต้น
$current_month = date('Y-m');
$default_due_date = date('Y-m-d', strtotime('+30 days'));
?>

<?php include 'includes/navbar.php'; ?>

<div class="container-fluid mt-4">
    <div class="row">
        <div class="col-12">
            <!-- หัวข้อหน้า -->
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2 class="mb-0">
                    <i class="bi bi-file-earmark-plus"></i>
                    สร้างใบแจ้งหนี้
                </h2>
                <div class="btn-group">
                    <a href="invoices.php" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left"></i>
                        กลับไปรายการใบแจ้งหนี้
                    </a>
                    <?php if ($contract_info): ?>
                    <a href="view_contract.php?id=<?php echo $contract_id; ?>" class="btn btn-outline-info">
                        <i class="bi bi-file-earmark-text"></i>
                        ดูสัญญา
                    </a>
                    <?php endif; ?>
                </div>
            </div>

            <!-- แสดงข้อความสถานะ -->
            <?php if ($success_message): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="bi bi-check-circle"></i>
                <?php echo $success_message; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

            <?php if ($error_message): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="bi bi-exclamation-triangle"></i>
                <?php echo $error_message; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

            <?php if (!$contract_info && empty($contract_id)): ?>
            <!-- เลือกสัญญา -->
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title mb-0">
                        <i class="bi bi-search"></i>
                        เลือกสัญญาเช่า
                    </h5>
                </div>
                <div class="card-body">
                    <p class="text-muted">กรุณาเลือกสัญญาเช่าที่ต้องการสร้างใบแจ้งหนี้</p>
                    
                    <?php
                    // ดึงรายการสัญญาที่ใช้งานอยู่
                    try {
                        $stmt = $pdo->prepare("
                            SELECT c.contract_id, c.monthly_rent,
                                   t.first_name, t.last_name,
                                   r.room_number, r.room_type
                            FROM contracts c
                            JOIN tenants t ON c.tenant_id = t.tenant_id
                            JOIN rooms r ON c.room_id = r.room_id
                            WHERE c.contract_status = 'active'
                            ORDER BY r.room_number
                        ");
                        $stmt->execute();
                        $active_contracts = $stmt->fetchAll();
                    } catch(PDOException $e) {
                        $active_contracts = [];
                    }
                    ?>
                    
                    <?php if (!empty($active_contracts)): ?>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead class="table-light">
                                <tr>
                                    <th>ห้อง</th>
                                    <th>ผู้เช่า</th>
                                    <th>ประเภทห้อง</th>
                                    <th>ค่าเช่า/เดือน</th>
                                    <th class="text-center">การจัดการ</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($active_contracts as $contract): ?>
                                <tr>
                                    <td>
                                        <span class="fw-bold"><?php echo $contract['room_number']; ?></span>
                                    </td>
                                    <td>
                                        <?php echo $contract['first_name'] . ' ' . $contract['last_name']; ?>
                                    </td>
                                    <td>
                                        <?php
                                        $room_types = [
                                            'single' => 'เดี่ยว',
                                            'double' => 'คู่',
                                            'triple' => 'สาม'
                                        ];
                                        echo $room_types[$contract['room_type']] ?? $contract['room_type'];
                                        ?>
                                    </td>
                                    <td>
                                        <?php echo formatCurrency($contract['monthly_rent']); ?>
                                    </td>
                                    <td class="text-center">
                                        <a href="add_invoice.php?contract_id=<?php echo $contract['contract_id']; ?>" 
                                           class="btn btn-primary btn-sm">
                                            <i class="bi bi-file-earmark-plus"></i>
                                            สร้างใบแจ้งหนี้
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php else: ?>
                    <div class="text-center py-4">
                        <i class="bi bi-exclamation-triangle display-4 text-muted"></i>
                        <h5 class="text-muted mt-3">ไม่มีสัญญาที่ใช้งานอยู่</h5>
                        <p class="text-muted">กรุณาสร้างสัญญาเช่าก่อนสร้างใบแจ้งหนี้</p>
                        <a href="add_contract.php" class="btn btn-primary">
                            <i class="bi bi-file-earmark-plus"></i>
                            สร้างสัญญาใหม่
                        </a>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php elseif ($contract_info): ?>
            <!-- ฟอร์มสร้างใบแจ้งหนี้ -->
            <div class="row">
                <!-- ข้อมูลสัญญา -->
                <div class="col-md-4">
                    <div class="card border-primary">
                        <div class="card-header bg-primary text-white">
                            <h6 class="card-title mb-0">
                                <i class="bi bi-file-earmark-text"></i>
                                ข้อมูลสัญญา
                            </h6>
                        </div>
                        <div class="card-body">
                            <div class="mb-3">
                                <small class="text-muted">รหัสสัญญา</small>
                                <div class="fw-bold">#<?php echo str_pad($contract_info['contract_id'], 6, '0', STR_PAD_LEFT); ?></div>
                            </div>
                            
                            <div class="mb-3">
                                <small class="text-muted">ห้อง</small>
                                <div class="fw-bold"><?php echo $contract_info['room_number']; ?></div>
                            </div>
                            
                            <div class="mb-3">
                                <small class="text-muted">ผู้เช่า</small>
                                <div class="fw-bold"><?php echo $contract_info['first_name'] . ' ' . $contract_info['last_name']; ?></div>
                                <small class="text-muted"><?php echo $contract_info['phone']; ?></small>
                            </div>
                            
                            <div class="mb-3">
                                <small class="text-muted">ประเภทห้อง</small>
                                <div>
                                    <?php
                                    $room_types = [
                                        'single' => 'เดี่ยว',
                                        'double' => 'คู่',
                                        'triple' => 'สาม'
                                    ];
                                    echo $room_types[$contract_info['room_type']] ?? $contract_info['room_type'];
                                    ?>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <small class="text-muted">ค่าเช่าตามสัญญา</small>
                                <div class="fw-bold text-primary"><?php echo formatCurrency($contract_info['monthly_rent']); ?></div>
                            </div>
                            
                            <div class="mb-0">
                                <small class="text-muted">ระยะเวลาสัญญา</small>
                                <div><?php echo formatDate($contract_info['contract_start']) . ' - ' . formatDate($contract_info['contract_end']); ?></div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ฟอร์มใบแจ้งหนี้ -->
                <div class="col-md-8">
                    <div class="card">
                        <div class="card-header">
                            <h6 class="card-title mb-0">
                                <i class="bi bi-receipt"></i>
                                รายละเอียดใบแจ้งหนี้
                            </h6>
                        </div>
                        <div class="card-body">
                            <form method="POST">
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label for="invoice_month" class="form-label">เดือนที่ออกใบแจ้งหนี้ <span class="text-danger">*</span></label>
                                        <input type="month" class="form-control" id="invoice_month" name="invoice_month" 
                                               value="<?php echo isset($_POST['invoice_month']) ? $_POST['invoice_month'] : $current_month; ?>" required>
                                    </div>
                                    
                                    <div class="col-md-6 mb-3">
                                        <label for="due_date" class="form-label">วันที่ครบกำหนดชำระ <span class="text-danger">*</span></label>
                                        <input type="date" class="form-control" id="due_date" name="due_date" 
                                               value="<?php echo isset($_POST['due_date']) ? $_POST['due_date'] : $default_due_date; ?>" required>
                                    </div>
                                </div>

                                <hr class="my-4">
                                <h6 class="text-primary mb-3">รายการค่าใช้จ่าย</h6>

                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label for="room_rent" class="form-label">ค่าเช่าห้อง <span class="text-danger">*</span></label>
                                        <div class="input-group">
                                            <input type="number" class="form-control" id="room_rent" name="room_rent" 
                                                   step="0.01" min="0" 
                                                   value="<?php echo isset($_POST['room_rent']) ? $_POST['room_rent'] : $contract_info['monthly_rent']; ?>" 
                                                   required>
                                            <span class="input-group-text">บาท</span>
                                        </div>
                                    </div>
                                    
                                    <div class="col-md-6 mb-3">
                                        <label for="water_charge" class="form-label">ค่าน้ำ</label>
                                        <div class="input-group">
                                            <input type="number" class="form-control" id="water_charge" name="water_charge" 
                                                   step="0.01" min="0" 
                                                   value="<?php echo isset($_POST['water_charge']) ? $_POST['water_charge'] : '0'; ?>">
                                            <span class="input-group-text">บาท</span>
                                        </div>
                                    </div>
                                </div>

                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label for="electric_charge" class="form-label">ค่าไฟ</label>
                                        <div class="input-group">
                                            <input type="number" class="form-control" id="electric_charge" name="electric_charge" 
                                                   step="0.01" min="0" 
                                                   value="<?php echo isset($_POST['electric_charge']) ? $_POST['electric_charge'] : '0'; ?>">
                                            <span class="input-group-text">บาท</span>
                                        </div>
                                    </div>
                                    
                                    <div class="col-md-6 mb-3">
                                        <label for="other_charges" class="form-label">ค่าใช้จ่ายอื่นๆ</label>
                                        <div class="input-group">
                                            <input type="number" class="form-control" id="other_charges" name="other_charges" 
                                                   step="0.01" min="0" 
                                                   value="<?php echo isset($_POST['other_charges']) ? $_POST['other_charges'] : '0'; ?>">
                                            <span class="input-group-text">บาท</span>
                                        </div>
                                    </div>
                                </div>

                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label for="other_charges_description" class="form-label">รายละเอียดค่าใช้จ่ายอื่นๆ</label>
                                        <textarea class="form-control" id="other_charges_description" name="other_charges_description" 
                                                  rows="2" placeholder="เช่น ค่าปรับ, ค่าบริการพิเศษ"><?php echo isset($_POST['other_charges_description']) ? htmlspecialchars($_POST['other_charges_description']) : ''; ?></textarea>
                                    </div>
                                    
                                    <div class="col-md-6 mb-3">
                                        <label for="discount" class="form-label">ส่วนลด</label>
                                        <div class="input-group">
                                            <input type="number" class="form-control" id="discount" name="discount" 
                                                   step="0.01" min="0" 
                                                   value="<?php echo isset($_POST['discount']) ? $_POST['discount'] : '0'; ?>">
                                            <span class="input-group-text">บาท</span>
                                        </div>
                                    </div>
                                </div>

                                <hr class="my-4">
                                
                                <!-- ยอดรวม -->
                                <div class="row">
                                    <div class="col-md-6 offset-md-6">
                                        <div class="card bg-light">
                                            <div class="card-body">
                                                <div class="d-flex justify-content-between mb-2">
                                                    <span>ค่าเช่าห้อง:</span>
                                                    <span id="display_room_rent">0.00 บาท</span>
                                                </div>
                                                <div class="d-flex justify-content-between mb-2">
                                                    <span>ค่าน้ำ:</span>
                                                    <span id="display_water_charge">0.00 บาท</span>
                                                </div>
                                                <div class="d-flex justify-content-between mb-2">
                                                    <span>ค่าไฟ:</span>
                                                    <span id="display_electric_charge">0.00 บาท</span>
                                                </div>
                                                <div class="d-flex justify-content-between mb-2">
                                                    <span>ค่าใช้จ่ายอื่นๆ:</span>
                                                    <span id="display_other_charges">0.00 บาท</span>
                                                </div>
                                                <div class="d-flex justify-content-between mb-2">
                                                    <span>ส่วนลด:</span>
                                                    <span id="display_discount" class="text-danger">0.00 บาท</span>
                                                </div>
                                                <hr>
                                                <div class="d-flex justify-content-between fw-bold text-primary fs-5">
                                                    <span>ยอดรวม:</span>
                                                    <span id="display_total">0.00 บาท</span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="row mt-4">
                                    <div class="col-12 text-end">
                                        <button type="button" class="btn btn-outline-secondary me-2" onclick="history.back()">
                                            <i class="bi bi-arrow-left"></i>
                                            ยกเลิก
                                        </button>
                                        <button type="submit" class="btn btn-primary">
                                            <i class="bi bi-receipt"></i>
                                            สร้างใบแจ้งหนี้
                                        </button>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
            <?php else: ?>
            <!-- ไม่พบสัญญา -->
            <div class="card border-danger">
                <div class="card-body text-center py-5">
                    <i class="bi bi-exclamation-triangle display-1 text-danger"></i>
                    <h4 class="text-danger mt-3">ไม่พบสัญญาที่ระบุ</h4>
                    <p class="text-muted">สัญญาอาจถูกยกเลิก หรือไม่มีอยู่ในระบบ</p>
                    <a href="add_invoice.php" class="btn btn-primary">
                        <i class="bi bi-arrow-left"></i>
                        เลือกสัญญาใหม่
                    </a>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
// ฟังก์ชันคำนวณยอดรวม
function calculateTotal() {
    const roomRent = parseFloat(document.getElementById('room_rent').value) || 0;
    const waterCharge = parseFloat(document.getElementById('water_charge').value) || 0;
    const electricCharge = parseFloat(document.getElementById('electric_charge').value) || 0;
    const otherCharges = parseFloat(document.getElementById('other_charges').value) || 0;
    const discount = parseFloat(document.getElementById('discount').value) || 0;
    
    const total = roomRent + waterCharge + electricCharge + otherCharges - discount;
    
    // อัพเดทการแสดงผล
    document.getElementById('display_room_rent').textContent = roomRent.toFixed(2) + ' บาท';
    document.getElementById('display_water_charge').textContent = waterCharge.toFixed(2) + ' บาท';
    document.getElementById('display_electric_charge').textContent = electricCharge.toFixed(2) + ' บาท';
    document.getElementById('display_other_charges').textContent = otherCharges.toFixed(2) + ' บาท';
    document.getElementById('display_discount').textContent = discount.toFixed(2) + ' บาท';
    document.getElementById('display_total').textContent = total.toFixed(2) + ' บาท';
}

// เพิ่ม event listener ให้กับ input fields
document.addEventListener('DOMContentLoaded', function() {
    const inputs = ['room_rent', 'water_charge', 'electric_charge', 'other_charges', 'discount'];
    inputs.forEach(function(inputId) {
        const element = document.getElementById(inputId);
        if (element) {
            element.addEventListener('input', calculateTotal);
            element.addEventListener('change', calculateTotal);
        }
    });
    
    // คำนวณยอดรวมครั้งแรก
    calculateTotal();
});

// ตรวจสอบก่อนส่งฟอร์ม
document.querySelector('form').addEventListener('submit', function(e) {
    const total = parseFloat(document.getElementById('display_total').textContent.replace(' บาท', ''));
    
    if (total <= 0) {
        e.preventDefault();
        alert('ยอดรวมต้องมากกว่า 0 บาท');
        return false;
    }
    
    return confirm('คุณต้องการสร้างใบแจ้งหนี้นี้หรือไม่?');
});
</script>

<?php include 'includes/footer.php'; ?>