<?php
$page_title = "สร้างใบแจ้งหนี้";
require_once 'includes/header.php';

// ตรวจสอบการสร้างใบแจ้งหนี้
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['generate_bills'])) {
    $generate_month = $_POST['generate_month'];
    $selected_contracts = isset($_POST['contracts']) ? $_POST['contracts'] : [];
    
    if (empty($selected_contracts)) {
        $error_message = "กรุณาเลือกสัญญาที่ต้องการสร้างใบแจ้งหนี้";
    } else {
        try {
            $pdo->beginTransaction();
            $created_count = 0;
            $skipped_count = 0;
            $error_contracts = [];
            
            foreach ($selected_contracts as $contract_id) {
                // ตรวจสอบว่ามีใบแจ้งหนี้สำหรับเดือนนี้แล้วหรือไม่
                $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM invoices WHERE contract_id = ? AND invoice_month = ?");
                $stmt->execute([$contract_id, $generate_month]);
                $exists = $stmt->fetch()['count'];
                
                if ($exists > 0) {
                    $skipped_count++;
                    continue;
                }
                
                // ดึงข้อมูลสัญญา
                $stmt = $pdo->prepare("
                    SELECT c.*, r.room_number, t.first_name, t.last_name
                    FROM contracts c
                    JOIN rooms r ON c.room_id = r.room_id
                    JOIN tenants t ON c.tenant_id = t.tenant_id
                    WHERE c.contract_id = ? AND c.contract_status = 'active'
                ");
                $stmt->execute([$contract_id]);
                $contract = $stmt->fetch();
                
                if (!$contract) {
                    $error_contracts[] = "สัญญา ID: $contract_id - ไม่พบข้อมูลหรือสัญญาไม่ได้ใช้งาน";
                    continue;
                }
                
                // ดึงข้อมูลค่าน้ำค่าไฟ
                $stmt = $pdo->prepare("SELECT * FROM utility_readings WHERE room_id = ? AND reading_month = ?");
                $stmt->execute([$contract['room_id'], $generate_month]);
                $utility = $stmt->fetch();
                
                $water_charge = 0;
                $electric_charge = 0;
                
                if ($utility) {
                    $water_usage = $utility['water_current'] - $utility['water_previous'];
                    $electric_usage = $utility['electric_current'] - $utility['electric_previous'];
                    $water_charge = $water_usage * $utility['water_unit_price'];
                    $electric_charge = $electric_usage * $utility['electric_unit_price'];
                }
                
                // คำนวณยอดรวม
                $room_rent = $contract['monthly_rent'];
                $other_charges = 0; // สามารถเพิ่มค่าใช้จ่ายอื่นๆ ได้
                $discount = 0; // สามารถเพิ่มส่วนลดได้
                $total_amount = $room_rent + $water_charge + $electric_charge + $other_charges - $discount;
                
                // กำหนดวันครบกำหนดชำระ (วันที่ 5 ของเดือนถัดไป)
                $due_date = date('Y-m-05', strtotime($generate_month . '-01 +1 month'));
                
                // สร้างใบแจ้งหนี้
                $stmt = $pdo->prepare("
                    INSERT INTO invoices (
                        contract_id, invoice_month, room_rent, water_charge, electric_charge, 
                        other_charges, discount, total_amount, due_date, invoice_status
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')
                ");
                
                $stmt->execute([
                    $contract_id, $generate_month, $room_rent, $water_charge, $electric_charge,
                    $other_charges, $discount, $total_amount, $due_date
                ]);
                
                $created_count++;
            }
            
            $pdo->commit();
            
            $success_message = "สร้างใบแจ้งหนี้เรียบร้อยแล้ว จำนวน $created_count ใบ";
            if ($skipped_count > 0) {
                $success_message .= " (ข้าม $skipped_count ใบที่มีอยู่แล้ว)";
            }
            if (!empty($error_contracts)) {
                $error_message = "มีข้อผิดพลาดบางรายการ:<br>" . implode('<br>', $error_contracts);
            }
            
        } catch(PDOException $e) {
            $pdo->rollBack();
            $error_message = "เกิดข้อผิดพลาด: " . $e->getMessage();
        }
    }
}

// ดึงข้อมูลสัญญาที่ใช้งานอยู่
$generate_month = isset($_GET['month']) ? $_GET['month'] : date('Y-m');

try {
    // ดึงสัญญาที่ใช้งานอยู่
    $contracts_sql = "
        SELECT c.*, r.room_number, r.room_type, r.floor_number,
               t.first_name, t.last_name, t.phone,
               (SELECT COUNT(*) FROM invoices i WHERE i.contract_id = c.contract_id AND i.invoice_month = ?) as has_invoice
        FROM contracts c
        JOIN rooms r ON c.room_id = r.room_id
        JOIN tenants t ON c.tenant_id = t.tenant_id
        WHERE c.contract_status = 'active'
          AND c.contract_start <= ?
          AND (c.contract_end IS NULL OR c.contract_end >= ?)
        ORDER BY CAST(SUBSTRING(r.room_number FROM '[0-9]+') AS UNSIGNED), r.room_number
    ";
    
    $month_start = $generate_month . '-01';
    $month_end = date('Y-m-t', strtotime($month_start));
    
    $stmt = $pdo->prepare($contracts_sql);
    $stmt->execute([$generate_month, $month_end, $month_start]);
    $contracts = $stmt->fetchAll();
    
    // ดึงข้อมูลมิเตอร์สำหรับเดือนที่เลือก
    $utility_sql = "
        SELECT ur.*, r.room_number,
               (ur.water_current - ur.water_previous) as water_usage,
               (ur.electric_current - ur.electric_previous) as electric_usage,
               (ur.water_current - ur.water_previous) * ur.water_unit_price as water_cost,
               (ur.electric_current - ur.electric_previous) * ur.electric_unit_price as electric_cost
        FROM utility_readings ur
        JOIN rooms r ON ur.room_id = r.room_id
        WHERE ur.reading_month = ?
        ORDER BY CAST(SUBSTRING(r.room_number FROM '[0-9]+') AS UNSIGNED), r.room_number
    ";
    
    $stmt = $pdo->prepare($utility_sql);
    $stmt->execute([$generate_month]);
    $utilities = $stmt->fetchAll();
    
    // จัดเก็บข้อมูลมิเตอร์ในรูปแบบ associative array
    $utility_data = [];
    foreach ($utilities as $utility) {
        $utility_data[$utility['room_number']] = $utility;
    }
    
    // สถิติ
    $total_contracts = count($contracts);
    $contracts_with_invoices = count(array_filter($contracts, function($c) { return $c['has_invoice'] > 0; }));
    $contracts_without_invoices = $total_contracts - $contracts_with_invoices;
    $contracts_with_utility = count(array_filter($contracts, function($c) use ($utility_data) {
        return isset($utility_data[$c['room_number']]);
    }));
    
} catch(PDOException $e) {
    $error_message = "เกิดข้อผิดพลาดในการดึงข้อมูล: " . $e->getMessage();
    $contracts = [];
    $utility_data = [];
    $total_contracts = 0;
    $contracts_with_invoices = 0;
    $contracts_without_invoices = 0;
    $contracts_with_utility = 0;
}

// ใช้ฟังก์ชัน thaiMonth() และ formatCurrency() จาก config.php แทน
?>

<?php include 'includes/navbar.php'; ?>

<div class="container-fluid mt-4">
    <div class="row">
        <div class="col-12">
            <!-- หัวข้อหน้า -->
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2 class="mb-0">
                    <i class="bi bi-receipt-cutoff"></i>
                    สร้างใบแจ้งหนี้
                </h2>
                <div class="btn-group">
                    <a href="invoices.php" class="btn btn-outline-secondary">
                        <i class="bi bi-receipt"></i>
                        ดูใบแจ้งหนี้
                    </a>
                    <a href="utility_readings.php" class="btn btn-outline-primary">
                        <i class="bi bi-speedometer"></i>
                        บันทึกมิเตอร์
                    </a>
                </div>
            </div>

            <!-- แสดงข้อความแจ้งเตือน -->
            <?php if (isset($success_message)): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="bi bi-check-circle"></i>
                    <?php echo $success_message; ?>
                    <div class="mt-2">
                        <a href="invoices.php?month=<?php echo $generate_month; ?>" class="btn btn-sm btn-outline-success">
                            <i class="bi bi-eye"></i>
                            ดูใบแจ้งหนี้ที่สร้าง
                        </a>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php if (isset($error_message)): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="bi bi-exclamation-triangle"></i>
                    <?php echo $error_message; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <!-- เลือกเดือน -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0">
                        <i class="bi bi-calendar"></i>
                        เลือกเดือนที่ต้องการสร้างใบแจ้งหนี้
                    </h5>
                </div>
                <div class="card-body">
                    <form method="GET" class="row g-3 align-items-end">
                        <div class="col-md-4">
                            <label for="month" class="form-label">เดือน</label>
                            <input type="month" class="form-control" id="month" name="month" 
                                   value="<?php echo htmlspecialchars($generate_month); ?>">
                        </div>
                        <div class="col-md-4">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-search"></i>
                                โหลดข้อมูล
                            </button>
                        </div>
                        <div class="col-md-4 text-end">
                            <small class="text-muted">
                                เลือกเดือนที่ต้องการสร้างใบแจ้งหนี้
                            </small>
                        </div>
                    </form>
                </div>
            </div>

            <!-- สถิติ -->
            <div class="row mb-4">
                <div class="col-lg-3 col-md-6 mb-3">
                    <div class="card text-white bg-primary h-100">
                        <div class="card-body text-center">
                            <i class="bi bi-file-text fs-2"></i>
                            <h4 class="mt-2"><?php echo $total_contracts; ?></h4>
                            <p class="mb-0">สัญญาทั้งหมด</p>
                        </div>
                    </div>
                </div>
                <div class="col-lg-3 col-md-6 mb-3">
                    <div class="card text-white bg-success h-100">
                        <div class="card-body text-center">
                            <i class="bi bi-check-circle fs-2"></i>
                            <h4 class="mt-2"><?php echo $contracts_with_invoices; ?></h4>
                            <p class="mb-0">สร้างแล้ว</p>
                        </div>
                    </div>
                </div>
                <div class="col-lg-3 col-md-6 mb-3">
                    <div class="card text-white bg-warning h-100">
                        <div class="card-body text-center">
                            <i class="bi bi-clock fs-2"></i>
                            <h4 class="mt-2"><?php echo $contracts_without_invoices; ?></h4>
                            <p class="mb-0">ยังไม่สร้าง</p>
                        </div>
                    </div>
                </div>
                <div class="col-lg-3 col-md-6 mb-3">
                    <div class="card text-white bg-info h-100">
                        <div class="card-body text-center">
                            <i class="bi bi-speedometer fs-2"></i>
                            <h4 class="mt-2"><?php echo $contracts_with_utility; ?></h4>
                            <p class="mb-0">มีข้อมูลมิเตอร์</p>
                        </div>
                    </div>
                </div>
            </div>

            <?php if ($contracts_without_invoices > 0): ?>
                <!-- ฟอร์มสร้างใบแจ้งหนี้ -->
                <form method="POST" id="generateForm">
                    <input type="hidden" name="generate_month" value="<?php echo $generate_month; ?>">
                    <input type="hidden" name="generate_bills" value="1">
                    
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5 class="mb-0">
                                <i class="bi bi-list-check"></i>
                                เลือกสัญญาที่ต้องการสร้างใบแจ้งหนี้
                                <span class="badge bg-primary"><?php echo thaiMonth(substr($generate_month, 5, 2)) . ' ' . substr($generate_month, 0, 4); ?></span>
                            </h5>
                            <div class="btn-group" role="group">
                                <button type="button" class="btn btn-sm btn-outline-primary" onclick="selectAll()">
                                    <i class="bi bi-check-all"></i>
                                    เลือกทั้งหมด
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="selectNone()">
                                    <i class="bi bi-x-square"></i>
                                    ยกเลิกทั้งหมด
                                </button>
                            </div>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th width="50">#</th>
                                            <th>ผู้เช่า</th>
                                            <th width="100">ห้อง</th>
                                            <th width="120" class="text-end">ค่าห้อง</th>
                                            <th width="120" class="text-end">ค่าน้ำ</th>
                                            <th width="120" class="text-end">ค่าไฟ</th>
                                            <th width="120" class="text-end">รวม (ประมาณ)</th>
                                            <th width="100" class="text-center">สถานะ</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($contracts)): ?>
                                            <tr>
                                                <td colspan="8" class="text-center py-4">
                                                    <i class="bi bi-inbox fs-1 text-muted"></i>
                                                    <p class="text-muted mt-2 mb-0">ไม่พบข้อมูลสัญญาในเดือนที่เลือก</p>
                                                </td>
                                            </tr>
                                        <?php else: ?>
                                            <?php foreach ($contracts as $contract): 
                                                $utility = isset($utility_data[$contract['room_number']]) ? 
                                                           $utility_data[$contract['room_number']] : null;
                                                $water_charge = $utility ? $utility['water_cost'] : 0;
                                                $electric_charge = $utility ? $utility['electric_cost'] : 0;
                                                $total_estimate = $contract['monthly_rent'] + $water_charge + $electric_charge;
                                                $has_invoice = $contract['has_invoice'] > 0;
                                                ?>
                                                <tr class="<?php echo $has_invoice ? 'table-success' : ''; ?>">
                                                    <td>
                                                        <?php if (!$has_invoice): ?>
                                                            <input type="checkbox" class="form-check-input contract-checkbox" 
                                                                   name="contracts[]" value="<?php echo $contract['contract_id']; ?>">
                                                        <?php else: ?>
                                                            <i class="bi bi-check-circle text-success"></i>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <div class="d-flex align-items-center">
                                                            <div class="avatar bg-primary text-white rounded-circle me-2 flex-shrink-0" style="width: 32px; height: 32px; display: flex; align-items: center; justify-content: center; font-size: 0.8rem;">
                                                                <?php echo mb_substr($contract['first_name'], 0, 1, 'UTF-8'); ?>
                                                            </div>
                                                            <div>
                                                                <div class="fw-bold"><?php echo $contract['first_name'] . ' ' . $contract['last_name']; ?></div>
                                                                <small class="text-muted"><?php echo $contract['phone']; ?></small>
                                                            </div>
                                                        </div>
                                                    </td>
                                                    <td>
                                                        <span class="badge bg-info"><?php echo $contract['room_number']; ?></span>
                                                        <div><small class="text-muted">ชั้น <?php echo $contract['floor_number']; ?></small></div>
                                                    </td>
                                                    <td class="text-end">
                                                        <strong><?php echo formatCurrency($contract['monthly_rent']); ?></strong>
                                                    </td>
                                                    <td class="text-end">
                                                        <?php if ($utility): ?>
                                                            <div><?php echo formatCurrency($water_charge); ?></div>
                                                            <small class="text-muted"><?php echo number_format($utility['water_usage'], 2); ?> หน่วย</small>
                                                        <?php else: ?>
                                                            <span class="text-muted">-</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="text-end">
                                                        <?php if ($utility): ?>
                                                            <div><?php echo formatCurrency($electric_charge); ?></div>
                                                            <small class="text-muted"><?php echo number_format($utility['electric_usage'], 2); ?> หน่วย</small>
                                                        <?php else: ?>
                                                            <span class="text-muted">-</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="text-end">
                                                        <strong class="text-primary"><?php echo formatCurrency($total_estimate); ?></strong>
                                                    </td>
                                                    <td class="text-center">
                                                        <?php if ($has_invoice): ?>
                                                            <span class="badge bg-success">
                                                                <i class="bi bi-check-circle"></i>
                                                                สร้างแล้ว
                                                            </span>
                                                        <?php else: ?>
                                                            <span class="badge bg-warning">
                                                                <i class="bi bi-clock"></i>
                                                                รอสร้าง
                                                            </span>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        
                        <?php if ($contracts_without_invoices > 0): ?>
                            <div class="card-footer">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <span class="text-muted">เลือกแล้ว: </span>
                                        <strong id="selectedCount">0</strong>
                                        <span class="text-muted"> จาก <?php echo $contracts_without_invoices; ?> รายการ</span>
                                    </div>
                                    <button type="submit" class="btn btn-success" id="generateBtn" disabled>
                                        <i class="bi bi-plus-circle"></i>
                                        สร้างใบแจ้งหนี้
                                    </button>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </form>
            <?php elseif ($total_contracts > 0): ?>
                <div class="card">
                    <div class="card-body text-center py-5">
                        <i class="bi bi-check-circle text-success" style="font-size: 4rem;"></i>
                        <h4 class="mt-3 text-success">สร้างใบแจ้งหนี้ครบถ้วนแล้ว</h4>
                        <p class="text-muted">
                            ได้สร้างใบแจ้งหนี้สำหรับเดือน <?php echo thaiMonth(substr($generate_month, 5, 2)) . ' ' . substr($generate_month, 0, 4); ?> 
                            ครบทุกสัญญาแล้ว
                        </p>
                        <a href="invoices.php?month=<?php echo $generate_month; ?>" class="btn btn-primary">
                            <i class="bi bi-eye"></i>
                            ดูใบแจ้งหนี้
                        </a>
                    </div>
                </div>
            <?php else: ?>
                <div class="card">
                    <div class="card-body text-center py-5">
                        <i class="bi bi-inbox text-muted" style="font-size: 4rem;"></i>
                        <h4 class="mt-3 text-muted">ไม่มีข้อมูลสัญญา</h4>
                        <p class="text-muted">ไม่พบสัญญาที่ใช้งานอยู่ในเดือนที่เลือก</p>
                        <a href="contracts.php" class="btn btn-primary">
                            <i class="bi bi-plus-circle"></i>
                            เพิ่มสัญญาใหม่
                        </a>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
// JavaScript สำหรับจัดการ checkbox
function selectAll() {
    const checkboxes = document.querySelectorAll('.contract-checkbox');
    checkboxes.forEach(checkbox => {
        if (!checkbox.disabled) {
            checkbox.checked = true;
        }
    });
    updateSelectedCount();
}

function selectNone() {
    const checkboxes = document.querySelectorAll('.contract-checkbox');
    checkboxes.forEach(checkbox => {
        checkbox.checked = false;
    });
    updateSelectedCount();
}

function updateSelectedCount() {
    const checkboxes = document.querySelectorAll('.contract-checkbox:checked');
    const count = checkboxes.length;
    document.getElementById('selectedCount').textContent = count;
    document.getElementById('generateBtn').disabled = count === 0;
}

// อัพเดทจำนวนที่เลือกเมื่อ checkbox เปลี่ยนแปลง
document.addEventListener('DOMContentLoaded', function() {
    const checkboxes = document.querySelectorAll('.contract-checkbox');
    checkboxes.forEach(checkbox => {
        checkbox.addEventListener('change', updateSelectedCount);
    });
    
    // ยืนยันก่อนสร้างใบแจ้งหนี้
    document.getElementById('generateForm').addEventListener('submit', function(e) {
        const selectedCount = document.querySelectorAll('.contract-checkbox:checked').length;
        if (selectedCount === 0) {
            e.preventDefault();
            alert('กรุณาเลือกสัญญาที่ต้องการสร้างใบแจ้งหนี้');
            return false;
        }
        
        if (!confirm(`คุณต้องการสร้างใบแจ้งหนี้ ${selectedCount} รายการใช่หรือไม่?`)) {
            e.preventDefault();
            return false;
        }
    });
    
    updateSelectedCount();
});
</script>

<?php require_once 'includes/footer.php'; ?>