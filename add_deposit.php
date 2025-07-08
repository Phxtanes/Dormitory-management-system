<?php
$page_title = "เพิ่มเงินมัดจำ";
require_once 'includes/header.php';

$success_message = '';
$error_message = '';

// ตรวจสอบการส่งฟอร์ม
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_deposit'])) {
    $contract_id = intval($_POST['contract_id']);
    $deposit_amount = floatval($_POST['deposit_amount']);
    $deposit_date = $_POST['deposit_date'];
    $payment_method = $_POST['payment_method'];
    $receipt_number = trim($_POST['receipt_number']);
    $bank_account = trim($_POST['bank_account']);
    $notes = trim($_POST['notes']);
    $auto_receive = isset($_POST['auto_receive']) ? true : false;
    
    // ตรวจสอบข้อมูล
    $errors = [];
    
    if ($contract_id <= 0) {
        $errors[] = "กรุณาเลือกสัญญา";
    }
    
    if ($deposit_amount <= 0) {
        $errors[] = "จำนวนเงินมัดจำต้องมากกว่า 0";
    }
    
    if (empty($deposit_date)) {
        $errors[] = "กรุณาระบุวันที่วางเงิน";
    }
    
    if (empty($payment_method)) {
        $errors[] = "กรุณาเลือกวิธีการชำระ";
    }
    
    // ตรวจสอบว่าสัญญานี้มีเงินมัดจำแล้วหรือไม่
    if (empty($errors)) {
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM deposits WHERE contract_id = ?");
            $stmt->execute([$contract_id]);
            $existing = $stmt->fetch()['count'];
            
            if ($existing > 0) {
                $errors[] = "สัญญานี้มีข้อมูลเงินมัดจำแล้ว";
            }
        } catch(PDOException $e) {
            $errors[] = "เกิดข้อผิดพลาดในการตรวจสอบข้อมูล";
        }
    }
    
    // ตรวจสอบว่าสัญญามีอยู่จริง
    if (empty($errors)) {
        try {
            $stmt = $pdo->prepare("SELECT contract_id, deposit_paid FROM contracts WHERE contract_id = ? AND contract_status = 'active'");
            $stmt->execute([$contract_id]);
            $contract = $stmt->fetch();
            
            if (!$contract) {
                $errors[] = "ไม่พบสัญญาที่ระบุ หรือสัญญาไม่ใช้งานอยู่";
            }
        } catch(PDOException $e) {
            $errors[] = "เกิดข้อผิดพลาดในการตรวจสอบสัญญา";
        }
    }
    
    // บันทึกข้อมูล
    if (empty($errors)) {
        try {
            $pdo->beginTransaction();
            
            // กำหนดสถานะ
            $deposit_status = $auto_receive ? 'received' : 'pending';
            
            // เพิ่มเงินมัดจำ
            $stmt = $pdo->prepare("
                INSERT INTO deposits 
                (contract_id, deposit_amount, deposit_date, payment_method, receipt_number, bank_account, deposit_status, notes, created_by) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $contract_id, $deposit_amount, $deposit_date, $payment_method, 
                $receipt_number ?: null, $bank_account ?: null, $deposit_status, $notes ?: null, $_SESSION['user_id']
            ]);
            
            $deposit_id = $pdo->lastInsertId();
            
            // อัพเดทสถานะในสัญญา
            if ($auto_receive) {
                $deposit_status_contract = ($deposit_amount >= $contract['deposit_paid']) ? 'full' : 'partial';
                $stmt = $pdo->prepare("UPDATE contracts SET deposit_status = ? WHERE contract_id = ?");
                $stmt->execute([$deposit_status_contract, $contract_id]);
            }
            
            $pdo->commit();
            
            $success_message = "เพิ่มเงินมัดจำเรียบร้อยแล้ว";
            
            // Redirect ไปหน้าจัดการเอกสาร
            header("Location: deposit_documents.php?deposit_id=$deposit_id");
            exit;
            
        } catch(PDOException $e) {
            $pdo->rollBack();
            $error_message = "เกิดข้อผิดพลาด: " . $e->getMessage();
        }
    } else {
        $error_message = implode('<br>', $errors);
    }
}

// ดึงรายการสัญญาที่ยังไม่มีเงินมัดจำ
try {
    $contracts_sql = "
        SELECT c.contract_id, c.deposit_paid, c.monthly_rent, c.contract_start, c.contract_end,
               r.room_number, r.room_type, r.floor_number,
               CONCAT(t.first_name, ' ', t.last_name) as tenant_name, t.phone, t.email
        FROM contracts c
        JOIN rooms r ON c.room_id = r.room_id
        JOIN tenants t ON c.tenant_id = t.tenant_id
        WHERE c.contract_status = 'active'
        AND NOT EXISTS (SELECT 1 FROM deposits d WHERE d.contract_id = c.contract_id)
        ORDER BY r.room_number
    ";
    $stmt = $pdo->query($contracts_sql);
    $available_contracts = $stmt->fetchAll();
} catch(PDOException $e) {
    $error_message = "เกิดข้อผิดพลาดในการดึงข้อมูลสัญญา: " . $e->getMessage();
    $available_contracts = [];
}

include 'includes/navbar.php';
?>

<div class="container mt-4">
    <div class="row justify-content-center">
        <div class="col-md-10">
            <!-- หัวข้อหน้า -->
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2 class="mb-0">
                    <i class="bi bi-piggy-bank-fill"></i>
                    เพิ่มเงินมัดจำใหม่
                </h2>
                <a href="deposits.php" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left"></i>
                    กลับ
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

            <!-- ฟอร์มเพิ่มเงินมัดจำ -->
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title mb-0">
                        <i class="bi bi-plus-circle"></i>
                        ข้อมูลเงินมัดจำ
                    </h5>
                </div>
                <div class="card-body">
                    <?php if (empty($available_contracts)): ?>
                        <div class="text-center py-5">
                            <i class="bi bi-inbox fs-1 text-muted d-block mb-3"></i>
                            <h5 class="text-muted">ไม่มีสัญญาที่สามารถเพิ่มเงินมัดจำได้</h5>
                            <p class="text-muted">สัญญาทั้งหมดมีข้อมูลเงินมัดจำแล้ว หรือไม่มีสัญญาที่ใช้งานอยู่</p>
                            <a href="contracts.php" class="btn btn-primary">
                                <i class="bi bi-file-text"></i>
                                ดูสัญญาทั้งหมด
                            </a>
                        </div>
                    <?php else: ?>
                        <form method="POST">
                            <div class="row">
                                <!-- เลือกสัญญา -->
                                <div class="col-md-6">
                                    <label class="form-label">
                                        <i class="bi bi-file-text"></i>
                                        เลือกสัญญา <span class="text-danger">*</span>
                                    </label>
                                    <select class="form-select" name="contract_id" id="contractSelect" required onchange="updateContractInfo()">
                                        <option value="">-- เลือกสัญญา --</option>
                                        <?php foreach ($available_contracts as $contract): ?>
                                            <option value="<?php echo $contract['contract_id']; ?>" 
                                                    data-deposit="<?php echo $contract['deposit_paid']; ?>"
                                                    data-rent="<?php echo $contract['monthly_rent']; ?>"
                                                    data-tenant="<?php echo htmlspecialchars($contract['tenant_name']); ?>"
                                                    data-phone="<?php echo htmlspecialchars($contract['phone']); ?>"
                                                    data-room-type="<?php echo htmlspecialchars($contract['room_type']); ?>"
                                                    data-start="<?php echo $contract['contract_start']; ?>"
                                                    data-end="<?php echo $contract['contract_end']; ?>">
                                                ห้อง <?php echo $contract['room_number']; ?> - <?php echo htmlspecialchars($contract['tenant_name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <!-- จำนวนเงินมัดจำ -->
                                <div class="col-md-6">
                                    <label class="form-label">
                                        <i class="bi bi-currency-exchange"></i>
                                        จำนวนเงินมัดจำ (บาท) <span class="text-danger">*</span>
                                    </label>
                                    <input type="number" class="form-control" name="deposit_amount" id="depositAmount" 
                                           step="0.01" min="0" required placeholder="0.00">
                                </div>
                            </div>

                            <!-- แสดงข้อมูลสัญญาที่เลือก -->
                            <div id="contractInfo" class="mt-4" style="display: none;">
                                <div class="card bg-light">
                                    <div class="card-body">
                                        <h6 class="card-title text-primary">
                                            <i class="bi bi-info-circle"></i>
                                            ข้อมูลสัญญาที่เลือก
                                        </h6>
                                        <div class="row">
                                            <div class="col-md-3">
                                                <small class="text-muted">ผู้เช่า:</small>
                                                <div id="tenantName" class="fw-bold"></div>
                                            </div>
                                            <div class="col-md-3">
                                                <small class="text-muted">เบอร์โทร:</small>
                                                <div id="tenantPhone"></div>
                                            </div>
                                            <div class="col-md-3">
                                                <small class="text-muted">ค่าเช่า/เดือน:</small>
                                                <div id="monthlyRent" class="text-success fw-bold"></div>
                                            </div>
                                            <div class="col-md-3">
                                                <small class="text-muted">ระยะเวลาสัญญา:</small>
                                                <div id="contractPeriod" class="small"></div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="row mt-4">
                                <!-- วันที่วางเงิน -->
                                <div class="col-md-6">
                                    <label class="form-label">
                                        <i class="bi bi-calendar"></i>
                                        วันที่วางเงิน <span class="text-danger">*</span>
                                    </label>
                                    <input type="date" class="form-control" name="deposit_date" 
                                           value="<?php echo date('Y-m-d'); ?>" required>
                                </div>
                                
                                <!-- วิธีการชำระ -->
                                <div class="col-md-6">
                                    <label class="form-label">
                                        <i class="bi bi-credit-card"></i>
                                        วิธีการชำระ <span class="text-danger">*</span>
                                    </label>
                                    <select class="form-select" name="payment_method" required>
                                        <option value="">-- เลือกวิธีการชำระ --</option>
                                        <option value="cash">เงินสด</option>
                                        <option value="bank_transfer">โอนธนาคาร</option>
                                        <option value="cheque">เช็ค</option>
                                        <option value="credit_card">บัตรเครดิต</option>
                                    </select>
                                </div>
                            </div>

                            <div class="row mt-3">
                                <!-- เลขที่ใบเสร็จ -->
                                <div class="col-md-6">
                                    <label class="form-label">
                                        <i class="bi bi-receipt"></i>
                                        เลขที่ใบเสร็จ / Reference
                                    </label>
                                    <input type="text" class="form-control" name="receipt_number" 
                                           placeholder="เลขที่ใบเสร็จ หรือ เลขอ้างอิง">
                                </div>
                                
                                <!-- บัญชีธนาคาร -->
                                <div class="col-md-6">
                                    <label class="form-label">
                                        <i class="bi bi-bank"></i>
                                        บัญชีธนาคาร (ถ้าโอน)
                                    </label>
                                    <input type="text" class="form-control" name="bank_account" 
                                           placeholder="เลขที่บัญชีที่โอนเข้า">
                                </div>
                            </div>

                            <!-- หมายเหตุ -->
                            <div class="mt-3">
                                <label class="form-label">
                                    <i class="bi bi-chat-text"></i>
                                    หมายเหตุ
                                </label>
                                <textarea class="form-control" name="notes" rows="3" 
                                          placeholder="หมายเหตุเพิ่มเติม เช่น สภาพการชำระเงิน, ข้อสังเกต"></textarea>
                            </div>

                            <!-- ตัวเลือกเพิ่มเติม -->
                            <div class="mt-4">
                                <div class="card border-success">
                                    <div class="card-header bg-success text-white">
                                        <h6 class="mb-0">
                                            <i class="bi bi-gear"></i>
                                            ตัวเลือกเพิ่มเติม
                                        </h6>
                                    </div>
                                    <div class="card-body">
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" name="auto_receive" id="autoReceive" checked>
                                            <label class="form-check-label" for="autoReceive">
                                                <strong>ยืนยันรับเงินทันที</strong>
                                                <small class="text-muted d-block">
                                                    เมื่อติดเครื่องหมายนี้ ระบบจะตั้งสถานะเป็น "รับแล้ว" ทันที 
                                                    หากไม่ติดเครื่องหมาย จะตั้งเป็น "รอดำเนินการ" และต้องยืนยันรับเงินภายหลัง
                                                </small>
                                            </label>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- ปุ่มดำเนินการ -->
                            <div class="mt-4 d-flex justify-content-between">
                                <a href="deposits.php" class="btn btn-secondary">
                                    <i class="bi bi-x-circle"></i>
                                    ยกเลิก
                                </a>
                                <button type="submit" name="add_deposit" class="btn btn-success">
                                    <i class="bi bi-save"></i>
                                    บันทึกเงินมัดจำ
                                </button>
                            </div>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function updateContractInfo() {
    const select = document.getElementById('contractSelect');
    const contractInfo = document.getElementById('contractInfo');
    const depositAmount = document.getElementById('depositAmount');
    
    if (select.value) {
        const option = select.options[select.selectedIndex];
        
        // แสดงข้อมูลสัญญา
        document.getElementById('tenantName').textContent = option.getAttribute('data-tenant');
        document.getElementById('tenantPhone').textContent = option.getAttribute('data-phone');
        document.getElementById('monthlyRent').textContent = '฿' + parseFloat(option.getAttribute('data-rent')).toLocaleString();
        
        const startDate = new Date(option.getAttribute('data-start')).toLocaleDateString('th-TH');
        const endDate = new Date(option.getAttribute('data-end')).toLocaleDateString('th-TH');
        document.getElementById('contractPeriod').textContent = `${startDate} - ${endDate}`;
        
        // ตั้งค่าเงินมัดจำเริ่มต้น
        const suggestedDeposit = parseFloat(option.getAttribute('data-deposit'));
        depositAmount.value = suggestedDeposit.toFixed(2);
        
        contractInfo.style.display = 'block';
    } else {
        contractInfo.style.display = 'none';
        depositAmount.value = '';
    }
}

// ตรวจสอบข้อมูลก่อนส่งฟอร์ม
document.querySelector('form').addEventListener('submit', function(e) {
    const contractId = document.getElementById('contractSelect').value;
    const depositAmount = parseFloat(document.getElementById('depositAmount').value);
    
    if (!contractId) {
        e.preventDefault();
        alert('กรุณาเลือกสัญญา');
        return;
    }
    
    if (depositAmount <= 0) {
        e.preventDefault();
        alert('จำนวนเงินมัดจำต้องมากกว่า 0');
        return;
    }
    
    if (!confirm('ต้องการบันทึกข้อมูลเงินมัดจำนี้หรือไม่?')) {
        e.preventDefault();
    }
});
</script>

<?php include 'includes/footer.php'; ?>