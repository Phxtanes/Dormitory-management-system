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
               (SELECT COUNT(*) FROM invoices i WHERE i.contract_id = c.contract_id AND i.invoice_month = ?) as has_invoice,
               (SELECT * FROM utility_readings ur WHERE ur.room_id = c.room_id AND ur.reading_month = ?) as has_utility
        FROM contracts c
        JOIN rooms r ON c.room_id = r.room_id
        JOIN tenants t ON c.tenant_id = t.tenant_id
        WHERE c.contract_status = 'active'
          AND c.contract_start <= ?
          AND c.contract_end >= ?
        ORDER BY r.room_number
    ";
    
    $month_start = $generate_month . '-01';
    $month_end = date('Y-m-t', strtotime($month_start));
    
    $stmt = $pdo->prepare($contracts_sql);
    $stmt->execute([$generate_month, $generate_month, $month_end, $month_start]);
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
        ORDER BY r.room_number
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
}
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
                            <div class="text-muted">
                                <small>
                                    <i class="bi bi-info-circle"></i>
                                    ใบแจ้งหนี้จะมีกำหนดชำระวันที่ 5 ของเดือนถัดไป
                                </small>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <!-- สถิติ -->
            <div class="row mb-4">
                <div class="col-lg-3 col-md-6 mb-3">
                    <div class="card text-white bg-primary h-100">
                        <div class="card-body text-center">
                            <i class="bi bi-file-earmark-text fs-2"></i>
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
                            <div>
                                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="toggleSelectAll()">
                                    <i class="bi bi-check-all"></i>
                                    เลือก/ยกเลิกทั้งหมด
                                </button>
                                <button type="submit" class="btn btn-sm btn-success" onclick="return confirmGenerate()">
                                    <i class="bi bi-receipt-cutoff"></i>
                                    สร้างใบแจ้งหนี้
                                </button>
                            </div>
                        </div>
                        <div class="card-body">
                            <?php if (empty($contracts)): ?>
                                <div class="text-center py-5">
                                    <i class="bi bi-file-earmark-plus display-1 text-muted"></i>
                                    <h4 class="text-muted mt-3">ไม่พบสัญญาที่ใช้งานอยู่</h4>
                                    <p class="text-muted">ไม่มีสัญญาที่ใช้งานอยู่สำหรับเดือนที่เลือก</p>
                                </div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-hover">
                                        <thead class="table-light">
                                            <tr>
                                                <th style="width: 50px;">
                                                    <input type="checkbox" class="form-check-input" id="selectAll" onchange="toggleAll()">
                                                </th>
                                                <th>ผู้เช่า</th>
                                                <th>ห้อง</th>
                                                <th class="text-end">ค่าเช่า</th>
                                                <th class="text-end">ค่าน้ำ</th>
                                                <th class="text-end">ค่าไฟ</th>
                                                <th class="text-end">รวม</th>
                                                <th class="text-center">สถานะ</th>
                                                <th class="text-center">ข้อมูลมิเตอร์</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($contracts as $contract): ?>
                                                <?php
                                                $utility = isset($utility_data[$contract['room_number']]) ? $utility_data[$contract['room_number']] : null;
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
                                                                <small class="text-muted">
                                                                    <i class="bi bi-telephone"></i>
                                                                    <?php echo $contract['phone']; ?>
                                                                </small>
                                                            </div>
                                                        </div>
                                                    </td>
                                                    <td>
                                                        <span class="badge bg-info">ห้อง <?php echo $contract['room_number']; ?></span>
                                                        <br><small class="text-muted">
                                                            ชั้น <?php echo $contract['floor_number']; ?> - 
                                                            <?php
                                                            switch ($contract['room_type']) {
                                                                case 'single': echo 'เดี่ยว'; break;
                                                                case 'double': echo 'คู่'; break;
                                                                case 'triple': echo 'สาม'; break;
                                                            }
                                                            ?>
                                                        </small>
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
                                                                ยังไม่สร้าง
                                                            </span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="text-center">
                                                        <?php if ($utility): ?>
                                                            <span class="badge bg-success">
                                                                <i class="bi bi-check-circle"></i>
                                                                มีข้อมูล
                                                            </span>
                                                            <br><small class="text-muted">
                                                                บันทึก: <?php echo formatDate($utility['reading_date']); ?>
                                                            </small>
                                                        <?php else: ?>
                                                            <span class="badge bg-danger">
                                                                <i class="bi bi-x-circle"></i>
                                                                ไม่มีข้อมูล
                                                            </span>
                                                            <br><a href="utility_readings.php" class="btn btn-xs btn-outline-primary mt-1">
                                                                <i class="bi bi-plus"></i>
                                                                บันทึก
                                                            </a>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                        <tfoot>
                                            <tr class="table-light">
                                                <th colspan="3">รวมที่เลือก</th>
                                                <th class="text-end" id="totalRent">0.00 บาท</th>
                                                <th class="text-end" id="totalWater">0.00 บาท</th>
                                                <th class="text-end" id="totalElectric">0.00 บาท</th>
                                                <th class="text-end" id="grandTotal">0.00 บาท</th>
                                                <th colspan="2"></th>
                                            </tr>
                                        </tfoot>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </form>
            <?php else: ?>
                <!-- แสดงเมื่อสร้างใบแจ้งหนี้ครบแล้ว -->
                <div class="card">
                    <div class="card-body text-center py-5">
                        <i class="bi bi-check-circle-fill display-1 text-success"></i>
                        <h3 class="text-success mt-3">สร้างใบแจ้งหนี้ครบถ้วนแล้ว</h3>
                        <p class="text-muted">
                            ใบแจ้งหนี้สำหรับเดือน <?php echo thaiMonth(substr($generate_month, 5, 2)) . ' ' . substr($generate_month, 0, 4); ?> 
                            ได้ถูกสร้างครบถ้วนทั้งหมด <?php echo $total_contracts; ?> ใบแล้ว
                        </p>
                        <div class="mt-4">
                            <a href="invoices.php?month=<?php echo $generate_month; ?>" class="btn btn-primary me-2">
                                <i class="bi bi-eye"></i>
                                ดูใบแจ้งหนี้
                            </a>
                            <a href="generate_bills.php?month=<?php echo date('Y-m', strtotime($generate_month . ' +1 month')); ?>" class="btn btn-outline-secondary">
                                <i class="bi bi-arrow-right"></i>
                                สร้างใบแจ้งหนี้เดือนถัดไป
                            </a>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- ข้อมูลสรุป -->
            <?php if (!empty($contracts)): ?>
                <div class="row mt-4">
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header">
                                <h6 class="mb-0">
                                    <i class="bi bi-info-circle"></i>
                                    ข้อมูลสำคัญ
                                </h6>
                            </div>
                            <div class="card-body">
                                <ul class="list-unstyled mb-0">
                                    <li class="mb-2">
                                        <i class="bi bi-calendar text-primary"></i>
                                        เดือนที่สร้างใบแจ้งหนี้: <strong><?php echo thaiMonth(substr($generate_month, 5, 2)) . ' ' . substr($generate_month, 0, 4); ?></strong>
                                    </li>
                                    <li class="mb-2">
                                        <i class="bi bi-clock text-warning"></i>
                                        กำหนดชำระ: <strong>วันที่ 5 <?php echo thaiMonth(substr(date('Y-m', strtotime($generate_month . ' +1 month')), 5, 2)) . ' ' . date('Y', strtotime($generate_month . ' +1 month')); ?></strong>
                                    </li>
                                    <li class="mb-2">
                                        <i class="bi bi-file-earmark-text text-info"></i>
                                        สัญญาที่ใช้งาน: <strong><?php echo $total_contracts; ?> สัญญา</strong>
                                    </li>
                                    <li class="mb-2">
                                        <i class="bi bi-speedometer text-success"></i>
                                        มีข้อมูลมิเตอร์: <strong><?php echo $contracts_with_utility; ?> ห้อง</strong>
                                    </li>
                                </ul>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header">
                                <h6 class="mb-0">
                                    <i class="bi bi-lightbulb"></i>
                                    คำแนะนำ
                                </h6>
                            </div>
                            <div class="card-body">
                                <ul class="list-unstyled mb-0">
                                    <li class="mb-2">
                                        <i class="bi bi-check text-success"></i>
                                        ตรวจสอบข้อมูลมิเตอร์ก่อนสร้างใบแจ้งหนี้
                                    </li>
                                    <li class="mb-2">
                                        <i class="bi bi-check text-success"></i>
                                        สามารถสร้างใบแจ้งหนี้ทีละหลายรายการได้
                                    </li>
                                    <li class="mb-2">
                                        <i class="bi bi-check text-success"></i>
                                        ระบบจะข้ามสัญญาที่มีใบแจ้งหนี้แล้ว
                                    </li>
                                    <li class="mb-2">
                                        <i class="bi bi-check text-success"></i>
                                        สามารถแก้ไขใบแจ้งหนี้ได้หลังจากสร้างแล้ว
                                    </li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- ข้อมูลมิเตอร์ที่บันทึกแล้ว -->
            <?php if (!empty($utilities)): ?>
                <div class="card mt-4">
                    <div class="card-header">
                        <h6 class="mb-0">
                            <i class="bi bi-speedometer"></i>
                            ข้อมูลมิเตอร์ประจำเดือน <?php echo thaiMonth(substr($generate_month, 5, 2)) . ' ' . substr($generate_month, 0, 4); ?>
                        </h6>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>ห้อง</th>
                                        <th class="text-center">น้ำ (หน่วย)</th>
                                        <th class="text-center">ไฟ (หน่วย)</th>
                                        <th class="text-end">ค่าน้ำ</th>
                                        <th class="text-end">ค่าไฟ</th>
                                        <th class="text-end">รวม</th>
                                        <th class="text-center">วันที่บันทึก</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($utilities as $utility): ?>
                                        <tr>
                                            <td><strong>ห้อง <?php echo $utility['room_number']; ?></strong></td>
                                            <td class="text-center"><?php echo number_format($utility['water_usage'], 2); ?></td>
                                            <td class="text-center"><?php echo number_format($utility['electric_usage'], 2); ?></td>
                                            <td class="text-end"><?php echo formatCurrency($utility['water_cost']); ?></td>
                                            <td class="text-end"><?php echo formatCurrency($utility['electric_cost']); ?></td>
                                            <td class="text-end"><strong><?php echo formatCurrency($utility['water_cost'] + $utility['electric_cost']); ?></strong></td>
                                            <td class="text-center"><small><?php echo formatDate($utility['reading_date']); ?></small></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                                <tfoot>
                                    <tr class="table-light">
                                        <th>รวม</th>
                                        <th class="text-center"><?php echo number_format(array_sum(array_column($utilities, 'water_usage')), 2); ?></th>
                                        <th class="text-center"><?php echo number_format(array_sum(array_column($utilities, 'electric_usage')), 2); ?></th>
                                        <th class="text-end"><?php echo formatCurrency(array_sum(array_column($utilities, 'water_cost'))); ?></th>
                                        <th class="text-end"><?php echo formatCurrency(array_sum(array_column($utilities, 'electric_cost'))); ?></th>
                                        <th class="text-end"><strong><?php echo formatCurrency(array_sum(array_map(function($u) { return $u['water_cost'] + $u['electric_cost']; }, $utilities))); ?></strong></th>
                                        <th></th>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    updateTotals();
    
    // เพิ่ม event listener สำหรับ checkbox
    document.querySelectorAll('.contract-checkbox').forEach(function(checkbox) {
        checkbox.addEventListener('change', updateTotals);
    });
});

function toggleSelectAll() {
    const selectAllCheckbox = document.getElementById('selectAll');
    const isChecked = selectAllCheckbox.checked;
    
    document.querySelectorAll('.contract-checkbox').forEach(function(checkbox) {
        checkbox.checked = !isChecked;
    });
    
    selectAllCheckbox.checked = !isChecked;
    updateTotals();
}

function toggleAll() {
    const selectAllCheckbox = document.getElementById('selectAll');
    const isChecked = selectAllCheckbox.checked;
    
    document.querySelectorAll('.contract-checkbox').forEach(function(checkbox) {
        checkbox.checked = isChecked;
    });
    
    updateTotals();
}

function updateTotals() {
    let totalRent = 0;
    let totalWater = 0;
    let totalElectric = 0;
    let selectedCount = 0;
    
    document.querySelectorAll('.contract-checkbox:checked').forEach(function(checkbox) {
        const row = checkbox.closest('tr');
        const rentText = row.cells[3].textContent.replace(/[^\d.]/g, '');
        const waterText = row.cells[4].textContent.replace(/[^\d.]/g, '');
        const electricText = row.cells[5].textContent.replace(/[^\d.]/g, '');
        
        totalRent += parseFloat(rentText) || 0;
        totalWater += parseFloat(waterText) || 0;
        totalElectric += parseFloat(electricText) || 0;
        selectedCount++;
    });
    
    document.getElementById('totalRent').textContent = formatCurrency(totalRent);
    document.getElementById('totalWater').textContent = formatCurrency(totalWater);
    document.getElementById('totalElectric').textContent = formatCurrency(totalElectric);
    document.getElementById('grandTotal').textContent = formatCurrency(totalRent + totalWater + totalElectric);
    
    // อัพเดทข้อความในปุ่ม
    const submitButton = document.querySelector('button[type="submit"]');
    if (submitButton) {
        if (selectedCount > 0) {
            submitButton.innerHTML = `<i class="bi bi-receipt-cutoff"></i> สร้างใบแจ้งหนี้ (${selectedCount} รายการ)`;
            submitButton.disabled = false;
        } else {
            submitButton.innerHTML = `<i class="bi bi-receipt-cutoff"></i> สร้างใบแจ้งหนี้`;
            submitButton.disabled = true;
        }
    }
}

function confirmGenerate() {
    const selectedCount = document.querySelectorAll('.contract-checkbox:checked').length;
    
    if (selectedCount === 0) {
        alert('กรุณาเลือกสัญญาที่ต้องการสร้างใบแจ้งหนี้');
        return false;
    }
    
    const month = document.querySelector('input[name="generate_month"]').value;
    const monthNames = {
        '01': 'มกราคม', '02': 'กุมภาพันธ์', '03': 'มีนาคม', '04': 'เมษายน',
        '05': 'พฤษภาคม', '06': 'มิถุนายน', '07': 'กรกฎาคม', '08': 'สิงหาคม',
        '09': 'กันยายน', '10': 'ตุลาคม', '11': 'พฤศจิกายน', '12': 'ธันวาคม'
    };
    
    const [year, monthNum] = month.split('-');
    const monthName = monthNames[monthNum];
    
    return confirm(`คุณต้องการสร้างใบแจ้งหนี้สำหรับเดือน${monthName} ${year} จำนวน ${selectedCount} รายการ หรือไม่?\n\nการดำเนินการนี้ไม่สามารถยกเลิกได้`);
}

function formatCurrency(amount) {
    return new Intl.NumberFormat('th-TH', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    }).format(amount) + ' บาท';
}
</script>

<style>
.table th {
    white-space: nowrap;
    vertical-align: middle;
}

.table td {
    vertical-align: middle;
}

.avatar {
    font-weight: 600;
    font-size: 0.8rem;
}

.badge {
    font-size: 0.75rem;
}

.btn-xs {
    padding: 0.125rem 0.25rem;
    font-size: 0.75rem;
    border-radius: 0.2rem;
}

.card {
    transition: all 0.3s ease-in-out;
}

.card:hover {
    transform: translateY(-2px);
    box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15) !important;
}

.table-success {
    background-color: rgba(25, 135, 84, 0.1) !important;
}

.form-check-input:checked {
    background-color: #0d6efd;
    border-color: #0d6efd;
}

@media (max-width: 768px) {
    .table-responsive {
        font-size: 0.875rem;
    }
    
    .btn-group {
        flex-direction: column;
        width: 100%;
    }
    
    .btn-group .btn {
        margin-bottom: 0.25rem;
    }
}

@media print {
    .btn, .form-check-input, .alert {
        display: none !important;
    }
    
    .card {
        border: 1px solid #000 !important;
        box-shadow: none !important;
    }
    
    .table {
        font-size: 12px;
    }
    
    @page {
        margin: 1cm;
        size: A4 landscape;
    }
}
</style>

<?php include 'includes/footer.php'; ?>