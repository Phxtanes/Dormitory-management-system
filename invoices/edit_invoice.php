<?php
$page_title = "แก้ไขใบแจ้งหนี้";
require_once 'includes/header.php';

$success_message = '';
$error_message = '';
$invoice = null;
$contract_info = null;
$has_payments = false;

// ตรวจสอบ ID ใบแจ้งหนี้
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header('Location: invoices.php');
    exit;
}

$invoice_id = intval($_GET['id']);

try {
    // ดึงข้อมูลใบแจ้งหนี้พร้อมข้อมูลที่เกี่ยวข้อง
    $stmt = $pdo->prepare("
        SELECT i.*, 
               c.contract_start, c.contract_end, c.monthly_rent as contract_monthly_rent,
               t.first_name, t.last_name, t.phone, t.email,
               r.room_number, r.room_type, r.monthly_rent as room_monthly_rent,
               CONCAT('#INV-', LPAD(i.invoice_id, 6, '0')) as invoice_number,
               CONCAT('#CON-', LPAD(c.contract_id, 6, '0')) as contract_number
        FROM invoices i
        JOIN contracts c ON i.contract_id = c.contract_id
        JOIN tenants t ON c.tenant_id = t.tenant_id
        JOIN rooms r ON c.room_id = r.room_id
        WHERE i.invoice_id = ?
    ");
    $stmt->execute([$invoice_id]);
    $invoice = $stmt->fetch();
    
    if (!$invoice) {
        header('Location: invoices.php');
        exit;
    }
    
    // ตรวจสอบว่าสามารถแก้ไขได้หรือไม่ (ต้องเป็นสถานะ pending และไม่มีการชำระเงิน)
    if ($invoice['invoice_status'] !== 'pending') {
        $error_message = "ไม่สามารถแก้ไขใบแจ้งหนี้ที่มีสถานะ " . $invoice['invoice_status'] . " ได้";
    }
    
    // ตรวจสอบว่ามีการชำระเงินแล้วหรือไม่
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM payments WHERE invoice_id = ?");
    $stmt->execute([$invoice_id]);
    $has_payments = $stmt->fetch()['count'] > 0;
    
    if ($has_payments) {
        $error_message = "ไม่สามารถแก้ไขใบแจ้งหนี้ที่มีการชำระเงินแล้ว";
    }
    
} catch(PDOException $e) {
    $error_message = "เกิดข้อผิดพลาดในการดึงข้อมูล: " . $e->getMessage();
}

// ตรวจสอบการส่งฟอร์ม
if ($_SERVER['REQUEST_METHOD'] == 'POST' && $invoice && !$has_payments && $invoice['invoice_status'] == 'pending') {
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
    
    // ตรวจสอบใบแจ้งหนี้ซ้ำ (ยกเว้นตัวเอง)
    if (empty($errors) && $invoice_month != $invoice['invoice_month']) {
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM invoices WHERE contract_id = ? AND invoice_month = ? AND invoice_id != ?");
            $stmt->execute([$invoice['contract_id'], $invoice_month, $invoice_id]);
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
                UPDATE invoices SET 
                    invoice_month = ?, 
                    room_rent = ?, 
                    water_charge = ?, 
                    electric_charge = ?, 
                    other_charges = ?, 
                    other_charges_description = ?, 
                    discount = ?, 
                    total_amount = ?, 
                    due_date = ?
                WHERE invoice_id = ?
            ");
            
            $stmt->execute([
                $invoice_month, $room_rent, $water_charge, $electric_charge,
                $other_charges, $other_charges_description, $discount, 
                $total_amount, $due_date, $invoice_id
            ]);
            
            $success_message = "แก้ไขใบแจ้งหนี้เรียบร้อยแล้ว";
            
            // อัพเดทข้อมูลในตัวแปร $invoice
            $invoice['invoice_month'] = $invoice_month;
            $invoice['room_rent'] = $room_rent;
            $invoice['water_charge'] = $water_charge;
            $invoice['electric_charge'] = $electric_charge;
            $invoice['other_charges'] = $other_charges;
            $invoice['other_charges_description'] = $other_charges_description;
            $invoice['discount'] = $discount;
            $invoice['total_amount'] = $total_amount;
            $invoice['due_date'] = $due_date;
            
        } catch(PDOException $e) {
            $error_message = "เกิดข้อผิดพลาดในการบันทึกข้อมูล: " . $e->getMessage();
        }
    } else {
        $error_message = implode('<br>', $errors);
    }
}
?>

<?php include 'includes/navbar.php'; ?>

<div class="container-fluid mt-4">
    <div class="row">
        <div class="col-12">
            <!-- หัวข้อหน้า -->
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2 class="mb-0">
                    <i class="bi bi-pencil-square"></i>
                    แก้ไขใบแจ้งหนี้ <?php echo $invoice ? $invoice['invoice_number'] : ''; ?>
                </h2>
                <div class="btn-group">
                    <a href="view_invoice.php?id=<?php echo $invoice_id; ?>" class="btn btn-info">
                        <i class="bi bi-eye"></i>
                        ดูใบแจ้งหนี้
                    </a>
                    <a href="invoices.php" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left"></i>
                        กลับไปรายการใบแจ้งหนี้
                    </a>
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

            <?php if ($invoice && $invoice['invoice_status'] == 'pending' && !$has_payments): ?>
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
                                <div class="fw-bold"><?php echo $invoice['contract_number']; ?></div>
                            </div>
                            
                            <div class="mb-3">
                                <small class="text-muted">ห้อง</small>
                                <div class="fw-bold"><?php echo $invoice['room_number']; ?></div>
                            </div>
                            
                            <div class="mb-3">
                                <small class="text-muted">ผู้เช่า</small>
                                <div class="fw-bold"><?php echo $invoice['first_name'] . ' ' . $invoice['last_name']; ?></div>
                                <small class="text-muted"><?php echo $invoice['phone']; ?></small>
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
                                    echo $room_types[$invoice['room_type']] ?? $invoice['room_type'];
                                    ?>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <small class="text-muted">ค่าเช่าตามสัญญา</small>
                                <div class="fw-bold text-primary"><?php echo formatCurrency($invoice['contract_monthly_rent']); ?></div>
                            </div>
                            
                            <div class="mb-0">
                                <small class="text-muted">ระยะเวลาสัญญา</small>
                                <div><?php echo formatDate($invoice['contract_start']) . ' - ' . formatDate($invoice['contract_end']); ?></div>
                            </div>
                        </div>
                    </div>

                    <!-- ข้อมูลใบแจ้งหนี้เดิม -->
                    <div class="card mt-4 border-info">
                        <div class="card-header bg-info text-white">
                            <h6 class="card-title mb-0">
                                <i class="bi bi-clock-history"></i>
                                ข้อมูลเดิม
                            </h6>
                        </div>
                        <div class="card-body">
                            <div class="mb-2">
                                <small class="text-muted">สร้างเมื่อ:</small>
                                <div><?php echo formatDateTime($invoice['created_at']); ?></div>
                            </div>
                            
                            <div class="mb-2">
                                <small class="text-muted">เดือนเดิม:</small>
                                <div>
                                    <?php 
                                    $month_year = explode('-', $invoice['invoice_month']);
                                    echo thaiMonth($month_year[1]) . ' ' . ($month_year[0] + 543);
                                    ?>
                                </div>
                            </div>
                            
                            <div class="mb-0">
                                <small class="text-muted">ยอดรวมเดิม:</small>
                                <div class="fw-bold"><?php echo formatCurrency($invoice['total_amount']); ?></div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ฟอร์มแก้ไขใบแจ้งหนี้ -->
                <div class="col-md-8">
                    <div class="card">
                        <div class="card-header">
                            <h6 class="card-title mb-0">
                                <i class="bi bi-pencil-square"></i>
                                แก้ไขรายละเอียดใบแจ้งหนี้
                            </h6>
                        </div>
                        <div class="card-body">
                            <form method="POST">
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label for="invoice_month" class="form-label">เดือนที่ออกใบแจ้งหนี้ <span class="text-danger">*</span></label>
                                        <input type="month" class="form-control" id="invoice_month" name="invoice_month" 
                                               value="<?php echo htmlspecialchars($invoice['invoice_month']); ?>" required>
                                    </div>
                                    
                                    <div class="col-md-6 mb-3">
                                        <label for="due_date" class="form-label">วันที่ครบกำหนดชำระ <span class="text-danger">*</span></label>
                                        <input type="date" class="form-control" id="due_date" name="due_date" 
                                               value="<?php echo htmlspecialchars($invoice['due_date']); ?>" required>
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
                                                   value="<?php echo htmlspecialchars($invoice['room_rent']); ?>" 
                                                   required>
                                            <span class="input-group-text">บาท</span>
                                        </div>
                                    </div>
                                    
                                    <div class="col-md-6 mb-3">
                                        <label for="water_charge" class="form-label">ค่าน้ำ</label>
                                        <div class="input-group">
                                            <input type="number" class="form-control" id="water_charge" name="water_charge" 
                                                   step="0.01" min="0" 
                                                   value="<?php echo htmlspecialchars($invoice['water_charge']); ?>">
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
                                                   value="<?php echo htmlspecialchars($invoice['electric_charge']); ?>">
                                            <span class="input-group-text">บาท</span>
                                        </div>
                                    </div>
                                    
                                    <div class="col-md-6 mb-3">
                                        <label for="other_charges" class="form-label">ค่าใช้จ่ายอื่นๆ</label>
                                        <div class="input-group">
                                            <input type="number" class="form-control" id="other_charges" name="other_charges" 
                                                   step="0.01" min="0" 
                                                   value="<?php echo htmlspecialchars($invoice['other_charges']); ?>">
                                            <span class="input-group-text">บาท</span>
                                        </div>
                                    </div>
                                </div>

                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label for="other_charges_description" class="form-label">รายละเอียดค่าใช้จ่ายอื่นๆ</label>
                                        <textarea class="form-control" id="other_charges_description" name="other_charges_description" 
                                                  rows="2" placeholder="เช่น ค่าปรับ, ค่าบริการพิเศษ"><?php echo htmlspecialchars($invoice['other_charges_description'] ?? ''); ?></textarea>
                                    </div>
                                    
                                    <div class="col-md-6 mb-3">
                                        <label for="discount" class="form-label">ส่วนลด</label>
                                        <div class="input-group">
                                            <input type="number" class="form-control" id="discount" name="discount" 
                                                   step="0.01" min="0" 
                                                   value="<?php echo htmlspecialchars($invoice['discount']); ?>">
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
                                                    <span id="display_room_rent"><?php echo formatCurrency($invoice['room_rent']); ?></span>
                                                </div>
                                                <div class="d-flex justify-content-between mb-2">
                                                    <span>ค่าน้ำ:</span>
                                                    <span id="display_water_charge"><?php echo formatCurrency($invoice['water_charge']); ?></span>
                                                </div>
                                                <div class="d-flex justify-content-between mb-2">
                                                    <span>ค่าไฟ:</span>
                                                    <span id="display_electric_charge"><?php echo formatCurrency($invoice['electric_charge']); ?></span>
                                                </div>
                                                <div class="d-flex justify-content-between mb-2">
                                                    <span>ค่าใช้จ่ายอื่นๆ:</span>
                                                    <span id="display_other_charges"><?php echo formatCurrency($invoice['other_charges']); ?></span>
                                                </div>
                                                <div class="d-flex justify-content-between mb-2">
                                                    <span>ส่วนลด:</span>
                                                    <span id="display_discount" class="text-danger"><?php echo formatCurrency($invoice['discount']); ?></span>
                                                </div>
                                                <hr>
                                                <div class="d-flex justify-content-between fw-bold text-primary fs-5">
                                                    <span>ยอดรวม:</span>
                                                    <span id="display_total"><?php echo formatCurrency($invoice['total_amount']); ?></span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="row mt-4">
                                    <div class="col-12 text-end">
                                        <a href="view_invoice.php?id=<?php echo $invoice_id; ?>" class="btn btn-outline-secondary me-2">
                                            <i class="bi bi-x-circle"></i>
                                            ยกเลิก
                                        </a>
                                        <button type="submit" class="btn btn-warning">
                                            <i class="bi bi-check-circle"></i>
                                            บันทึกการแก้ไข
                                        </button>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
            
            <?php else: ?>
            <!-- ไม่สามารถแก้ไขได้ -->
            <div class="card border-warning">
                <div class="card-body text-center py-5">
                    <i class="bi bi-exclamation-triangle display-1 text-warning"></i>
                    <h4 class="text-warning mt-3">ไม่สามารถแก้ไขใบแจ้งหนี้นี้ได้</h4>
                    
                    <?php if (!$invoice): ?>
                        <p class="text-muted">ไม่พบใบแจ้งหนี้ที่ระบุ</p>
                    <?php elseif ($invoice['invoice_status'] !== 'pending'): ?>
                        <p class="text-muted">ใบแจ้งหนี้มีสถานะ "<?php echo $invoice['invoice_status']; ?>" แล้ว ไม่สามารถแก้ไขได้</p>
                    <?php elseif ($has_payments): ?>
                        <p class="text-muted">ใบแจ้งหนี้นี้มีการชำระเงินแล้ว ไม่สามารถแก้ไขได้</p>
                    <?php endif; ?>
                    
                    <div class="mt-3">
                        <?php if ($invoice): ?>
                        <a href="view_invoice.php?id=<?php echo $invoice_id; ?>" class="btn btn-primary me-2">
                            <i class="bi bi-eye"></i>
                            ดูใบแจ้งหนี้
                        </a>
                        <?php endif; ?>
                        <a href="invoices.php" class="btn btn-secondary">
                            <i class="bi bi-arrow-left"></i>
                            กลับไปรายการใบแจ้งหนี้
                        </a>
                    </div>
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
    document.getElementById('display_room_rent').textContent = formatCurrency(roomRent);
    document.getElementById('display_water_charge').textContent = formatCurrency(waterCharge);
    document.getElementById('display_electric_charge').textContent = formatCurrency(electricCharge);
    document.getElementById('display_other_charges').textContent = formatCurrency(otherCharges);
    document.getElementById('display_discount').textContent = formatCurrency(discount);
    document.getElementById('display_total').textContent = formatCurrency(total);
}

// ฟังก์ชันจัดรูปแบบสกุลเงิน
function formatCurrency(amount) {
    return new Intl.NumberFormat('th-TH', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    }).format(amount) + ' บาท';
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
    const total = parseFloat(document.getElementById('display_total').textContent.replace(' บาท', '').replace(',', ''));
    
    if (total <= 0) {
        e.preventDefault();
        alert('ยอดรวมต้องมากกว่า 0 บาท');
        return false;
    }
    
    return confirm('คุณต้องการบันทึกการแก้ไขใบแจ้งหนี้นี้หรือไม่?');
});

// ตรวจสอบการเปลี่ยนแปลงข้อมูล
let originalData = {};
document.addEventListener('DOMContentLoaded', function() {
    // เก็บข้อมูลเดิม
    const inputs = document.querySelectorAll('input, textarea, select');
    inputs.forEach(function(input) {
        originalData[input.name] = input.value;
    });
});

// แจ้งเตือนเมื่อมีการเปลี่ยนแปลงข้อมูลและออกจากหน้า
window.addEventListener('beforeunload', function(e) {
    const inputs = document.querySelectorAll('input, textarea, select');
    let hasChanges = false;
    
    inputs.forEach(function(input) {
        if (originalData[input.name] !== input.value) {
            hasChanges = true;
        }
    });
    
    if (hasChanges) {
        e.preventDefault();
        e.returnValue = '';
    }
});

// ยกเลิกการแจ้งเตือนเมื่อส่งฟอร์ม
document.querySelector('form').addEventListener('submit', function() {
    window.removeEventListener('beforeunload', arguments.callee);
});
</script>

<?php include 'includes/footer.php'; ?>