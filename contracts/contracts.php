<?php
$page_title = "จัดการสัญญาเช่า";
require_once 'includes/header.php';

// ตรวจสอบการยกเลิกสัญญา
if (isset($_GET['terminate']) && is_numeric($_GET['terminate'])) {
    try {
        // ตรวจสอบว่าสัญญายังใช้งานอยู่หรือไม่
        $stmt = $pdo->prepare("SELECT contract_status FROM contracts WHERE contract_id = ?");
        $stmt->execute([$_GET['terminate']]);
        $contract = $stmt->fetch();
        
        if ($contract && $contract['contract_status'] == 'active') {
            $stmt = $pdo->prepare("UPDATE contracts SET contract_status = 'terminated' WHERE contract_id = ?");
            $stmt->execute([$_GET['terminate']]);
            
            // อัพเดทสถานะห้องให้ว่าง
            $stmt = $pdo->prepare("
                UPDATE rooms r 
                JOIN contracts c ON r.room_id = c.room_id 
                SET r.room_status = 'available' 
                WHERE c.contract_id = ?
            ");
            $stmt->execute([$_GET['terminate']]);
            
            $success_message = "ยกเลิกสัญญาเช่าเรียบร้อยแล้ว";
        } else {
            $error_message = "ไม่สามารถยกเลิกสัญญานี้ได้";
        }
    } catch(PDOException $e) {
        $error_message = "เกิดข้อผิดพลาด: " . $e->getMessage();
    }
}

// ตรวจสอบการต่อสัญญา
if (isset($_POST['extend_contract'])) {
    try {
        $contract_id = $_POST['contract_id'];
        $new_end_date = $_POST['new_end_date'];
        $new_rent = $_POST['new_rent'];
        
        $stmt = $pdo->prepare("UPDATE contracts SET contract_end = ?, monthly_rent = ? WHERE contract_id = ?");
        $stmt->execute([$new_end_date, $new_rent, $contract_id]);
        
        $success_message = "ต่อสัญญาเรียบร้อยแล้ว";
    } catch(PDOException $e) {
        $error_message = "เกิดข้อผิดพลาดในการต่อสัญญา: " . $e->getMessage();
    }
}

// ดึงข้อมูลสัญญาเช่าทั้งหมด
$search = isset($_GET['search']) ? $_GET['search'] : '';
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';
$tenant_filter = isset($_GET['tenant_id']) ? $_GET['tenant_id'] : '';
$expiring_filter = isset($_GET['filter']) && $_GET['filter'] == 'expiring' ? true : false;

$sql = "SELECT c.*, 
        r.room_number, r.room_type, r.floor_number,
        t.first_name, t.last_name, t.phone, t.email, t.id_card,
        DATEDIFF(c.contract_end, CURDATE()) as days_until_expiry,
        (SELECT COUNT(*) FROM invoices i WHERE i.contract_id = c.contract_id AND i.invoice_status IN ('pending', 'overdue')) as pending_invoices
        FROM contracts c
        JOIN rooms r ON c.room_id = r.room_id
        JOIN tenants t ON c.tenant_id = t.tenant_id
        WHERE 1=1";

$params = [];

if (!empty($search)) {
    $sql .= " AND (t.first_name LIKE ? OR t.last_name LIKE ? OR r.room_number LIKE ? OR t.phone LIKE ? OR t.id_card LIKE ?)";
    $search_term = "%$search%";
    $params = array_fill(0, 5, $search_term);
}

if (!empty($status_filter)) {
    $sql .= " AND c.contract_status = ?";
    $params[] = $status_filter;
}

if (!empty($tenant_filter)) {
    $sql .= " AND c.tenant_id = ?";
    $params[] = $tenant_filter;
}

if ($expiring_filter) {
    $sql .= " AND c.contract_status = 'active' AND c.contract_end <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)";
}

$sql .= " ORDER BY 
    CASE 
        WHEN c.contract_status = 'active' AND c.contract_end <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) THEN 1
        WHEN c.contract_status = 'active' THEN 2
        WHEN c.contract_status = 'expired' THEN 3
        WHEN c.contract_status = 'terminated' THEN 4
    END,
    c.contract_end ASC";

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $contracts = $stmt->fetchAll();
    
    // ดึงข้อมูลสถิติ
    $stats_sql = "SELECT 
        COUNT(*) as total_contracts,
        SUM(CASE WHEN contract_status = 'active' THEN 1 ELSE 0 END) as active_contracts,
        SUM(CASE WHEN contract_status = 'expired' THEN 1 ELSE 0 END) as expired_contracts,
        SUM(CASE WHEN contract_status = 'terminated' THEN 1 ELSE 0 END) as terminated_contracts,
        SUM(CASE WHEN contract_status = 'active' AND contract_end <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) as expiring_soon,
        SUM(CASE WHEN contract_status = 'active' AND contract_end < CURDATE() THEN 1 ELSE 0 END) as overdue_contracts,
        AVG(monthly_rent) as avg_rent
        FROM contracts";
    
    $stats_stmt = $pdo->query($stats_sql);
    $stats = $stats_stmt->fetch();
    
    // ดึงข้อมูลรายได้จากสัญญาที่ใช้งานอยู่
    $income_sql = "SELECT SUM(monthly_rent) as total_monthly_income FROM contracts WHERE contract_status = 'active'";
    $income_stmt = $pdo->query($income_sql);
    $income_data = $income_stmt->fetch();
    
} catch(PDOException $e) {
    $error_message = "เกิดข้อผิดพลาดในการดึงข้อมูล: " . $e->getMessage();
    $contracts = [];
    $stats = [
        'total_contracts' => 0,
        'active_contracts' => 0,
        'expired_contracts' => 0,
        'terminated_contracts' => 0,
        'expiring_soon' => 0,
        'overdue_contracts' => 0,
        'avg_rent' => 0
    ];
    $income_data = ['total_monthly_income' => 0];
}
?>

<?php include 'includes/navbar.php'; ?>

<div class="container-fluid mt-4">
    <div class="row">
        <div class="col-12">
            <!-- หัวข้อหน้า -->
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2 class="mb-0">
                    <i class="bi bi-file-earmark-text"></i>
                    จัดการสัญญาเช่า
                </h2>
                <div class="btn-group">
                    <a href="add_contract.php" class="btn btn-primary">
                        <i class="bi bi-file-earmark-plus"></i>
                        สร้างสัญญาใหม่
                    </a>
                    <button type="button" class="btn btn-outline-secondary dropdown-toggle dropdown-toggle-split" data-bs-toggle="dropdown">
                        <span class="visually-hidden">Toggle Dropdown</span>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><a class="dropdown-item" href="reports_contracts.php">
                            <i class="bi bi-graph-up"></i> รายงานสัญญา
                        </a></li>
                        <li><a class="dropdown-item" href="export_contracts.php">
                            <i class="bi bi-download"></i> ส่งออกข้อมูล
                        </a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="contract_templates.php">
                            <i class="bi bi-file-earmark"></i> แม่แบบสัญญา
                        </a></li>
                    </ul>
                </div>
            </div>

            <!-- แสดงข้อความแจ้งเตือน -->
            <?php if (isset($success_message)): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="bi bi-check-circle"></i>
                    <?php echo $success_message; ?>
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

            <!-- สถิติภาพรวม -->
            <div class="row mb-4">
                <div class="col-xl-2 col-lg-3 col-md-4 col-sm-6 mb-3">
                    <div class="card text-white bg-primary h-100">
                        <div class="card-body text-center">
                            <i class="bi bi-file-earmark-text fs-2"></i>
                            <h4 class="mt-2"><?php echo number_format($stats['total_contracts']); ?></h4>
                            <p class="mb-0">สัญญาทั้งหมด</p>
                        </div>
                    </div>
                </div>
                <div class="col-xl-2 col-lg-3 col-md-4 col-sm-6 mb-3">
                    <div class="card text-white bg-success h-100">
                        <div class="card-body text-center">
                            <i class="bi bi-check-circle fs-2"></i>
                            <h4 class="mt-2"><?php echo number_format($stats['active_contracts']); ?></h4>
                            <p class="mb-0">ใช้งานอยู่</p>
                        </div>
                    </div>
                </div>
                <div class="col-xl-2 col-lg-3 col-md-4 col-sm-6 mb-3">
                    <div class="card text-white bg-warning h-100">
                        <div class="card-body text-center">
                            <i class="bi bi-clock fs-2"></i>
                            <h4 class="mt-2"><?php echo number_format($stats['expiring_soon']); ?></h4>
                            <p class="mb-0">ใกล้หมดอายุ</p>
                        </div>
                    </div>
                </div>
                <div class="col-xl-2 col-lg-3 col-md-4 col-sm-6 mb-3">
                    <div class="card text-white bg-secondary h-100">
                        <div class="card-body text-center">
                            <i class="bi bi-x-circle fs-2"></i>
                            <h4 class="mt-2"><?php echo number_format($stats['expired_contracts']); ?></h4>
                            <p class="mb-0">หมดอายุ</p>
                        </div>
                    </div>
                </div>
                <div class="col-xl-2 col-lg-3 col-md-4 col-sm-6 mb-3">
                    <div class="card text-white bg-danger h-100">
                        <div class="card-body text-center">
                            <i class="bi bi-ban fs-2"></i>
                            <h4 class="mt-2"><?php echo number_format($stats['terminated_contracts']); ?></h4>
                            <p class="mb-0">ยกเลิก</p>
                        </div>
                    </div>
                </div>
                <div class="col-xl-2 col-lg-3 col-md-4 col-sm-6 mb-3">
                    <div class="card text-white bg-info h-100">
                        <div class="card-body text-center">
                            <i class="bi bi-currency-dollar fs-2"></i>
                            <h4 class="mt-2"><?php echo formatCurrency($income_data['total_monthly_income']); ?></h4>
                            <p class="mb-0">รายได้/เดือน</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- แจ้งเตือนสัญญาเกินกำหนด -->
            <?php if ($stats['overdue_contracts'] > 0): ?>
                <div class="alert alert-danger d-flex align-items-center" role="alert">
                    <i class="bi bi-exclamation-triangle-fill fs-4 me-3"></i>
                    <div>
                        <strong>แจ้งเตือน!</strong> มีสัญญา <strong><?php echo $stats['overdue_contracts']; ?> สัญญา</strong> 
                        ที่หมดอายุแล้วแต่ยังไม่ได้ดำเนินการ
                    </div>
                </div>
            <?php endif; ?>

            <!-- ฟอร์มค้นหาและกรอง -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0">
                        <i class="bi bi-funnel"></i>
                        ค้นหาและกรองข้อมูล
                    </h5>
                </div>
                <div class="card-body">
                    <form method="GET" class="row g-3">
                        <div class="col-md-6">
                            <label for="search" class="form-label">ค้นหา</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-search"></i></span>
                                <input type="text" class="form-control" id="search" name="search" 
                                       value="<?php echo htmlspecialchars($search); ?>" 
                                       placeholder="ชื่อผู้เช่า หมายเลขห้อง โทรศัพท์ หรือ เลขบัตรประชาชน">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <label for="status" class="form-label">สถานะสัญญา</label>
                            <select class="form-select" id="status" name="status">
                                <option value="">ทั้งหมด</option>
                                <option value="active" <?php echo $status_filter == 'active' ? 'selected' : ''; ?>>ใช้งานอยู่</option>
                                <option value="expired" <?php echo $status_filter == 'expired' ? 'selected' : ''; ?>>หมดอายุ</option>
                                <option value="terminated" <?php echo $status_filter == 'terminated' ? 'selected' : ''; ?>>ยกเลิก</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">&nbsp;</label>
                            <div class="d-grid gap-2 d-md-flex">
                                <button type="submit" class="btn btn-primary flex-fill">
                                    <i class="bi bi-search"></i>
                                    ค้นหา
                                </button>
                                <a href="contracts.php" class="btn btn-outline-secondary">
                                    <i class="bi bi-arrow-clockwise"></i>
                                </a>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <!-- เมนูด่วน -->
            <div class="row mb-4">
                <div class="col-lg-3 col-md-6 mb-2">
                    <a href="contracts.php?status=active" class="btn btn-outline-success w-100 d-flex align-items-center">
                        <i class="bi bi-check-circle fs-5 me-2"></i>
                        <div class="text-start">
                            <div class="fw-bold">สัญญาที่ใช้งานอยู่</div>
                            <small class="text-muted"><?php echo number_format($stats['active_contracts']); ?> สัญญา</small>
                        </div>
                    </a>
                </div>
                <div class="col-lg-3 col-md-6 mb-2">
                    <a href="contracts.php?filter=expiring" class="btn btn-outline-warning w-100 d-flex align-items-center">
                        <i class="bi bi-clock fs-5 me-2"></i>
                        <div class="text-start">
                            <div class="fw-bold">สัญญาใกล้หมดอายุ</div>
                            <small class="text-muted"><?php echo number_format($stats['expiring_soon']); ?> สัญญา</small>
                        </div>
                    </a>
                </div>
                <div class="col-lg-3 col-md-6 mb-2">
                    <a href="contracts.php?status=expired" class="btn btn-outline-secondary w-100 d-flex align-items-center">
                        <i class="bi bi-x-circle fs-5 me-2"></i>
                        <div class="text-start">
                            <div class="fw-bold">สัญญาหมดอายุ</div>
                            <small class="text-muted"><?php echo number_format($stats['expired_contracts']); ?> สัญญา</small>
                        </div>
                    </a>
                </div>
                <div class="col-lg-3 col-md-6 mb-2">
                    <a href="add_contract.php" class="btn btn-primary w-100 d-flex align-items-center">
                        <i class="bi bi-file-earmark-plus fs-5 me-2"></i>
                        <div class="text-start">
                            <div class="fw-bold">สร้างสัญญาใหม่</div>
                            <small class="text-white-50">เพิ่มสัญญาเช่า</small>
                        </div>
                    </a>
                </div>
            </div>

            <!-- ตารางแสดงข้อมูลสัญญาเช่า -->
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">
                        <i class="bi bi-list"></i>
                        รายการสัญญาเช่า
                        <?php if ($expiring_filter): ?>
                            <span class="badge bg-warning">ใกล้หมดอายุ</span>
                        <?php elseif ($status_filter): ?>
                            <span class="badge bg-info"><?php 
                                echo $status_filter == 'active' ? 'ใช้งานอยู่' : 
                                    ($status_filter == 'expired' ? 'หมดอายุ' : 'ยกเลิก'); 
                            ?></span>
                        <?php endif; ?>
                    </h5>
                    <div class="d-flex align-items-center gap-2">
                        <span class="badge bg-primary"><?php echo number_format(count($contracts)); ?> สัญญา</span>
                        <div class="btn-group">
                            <button type="button" class="btn btn-sm btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown">
                                <i class="bi bi-three-dots"></i>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end">
                                <li><a class="dropdown-item" href="#" onclick="window.print()">
                                    <i class="bi bi-printer"></i> พิมพ์รายการ
                                </a></li>
                                <li><a class="dropdown-item" href="export_contracts.php?<?php echo http_build_query($_GET); ?>">
                                    <i class="bi bi-download"></i> ส่งออก Excel
                                </a></li>
                            </ul>
                        </div>
                    </div>
                </div>
                <div class="card-body">
                    <?php if (empty($contracts)): ?>
                        <div class="text-center py-5">
                            <i class="bi bi-file-earmark-plus display-1 text-muted"></i>
                            <h4 class="text-muted mt-3">ไม่พบข้อมูลสัญญาเช่า</h4>
                            <p class="text-muted">
                                <?php if (!empty($search) || !empty($status_filter)): ?>
                                    ไม่มีข้อมูลที่ตรงกับเงื่อนไขการค้นหา
                                <?php else: ?>
                                    ยังไม่มีข้อมูลสัญญาเช่าในระบบ
                                <?php endif; ?>
                            </p>
                            <div class="mt-3">
                                <?php if (!empty($search) || !empty($status_filter)): ?>
                                    <a href="contracts.php" class="btn btn-secondary me-2">
                                        <i class="bi bi-arrow-left"></i>
                                        ดูทั้งหมด
                                    </a>
                                <?php endif; ?>
                                <a href="add_contract.php" class="btn btn-primary">
                                    <i class="bi bi-file-earmark-plus"></i>
                                    สร้างสัญญาแรก
                                </a>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead class="table-light">
                                    <tr>
                                        <th style="width: 200px;">ผู้เช่า</th>
                                        <th style="width: 100px;">ห้อง</th>
                                        <th style="width: 120px;">วันที่เริ่มสัญญา</th>
                                        <th style="width: 120px;">วันที่สิ้นสุด</th>
                                        <th style="width: 120px;">ค่าเช่า/เดือน</th>
                                        <th style="width: 120px;">เงินมัดจำ</th>
                                        <th style="width: 100px;">สถานะ</th>
                                        <th style="width: 100px;">วันที่เหลือ</th>
                                        <th style="width: 180px;" class="text-center">จัดการ</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($contracts as $contract): ?>
                                        <tr class="<?php echo ($contract['contract_status'] == 'active' && $contract['days_until_expiry'] <= 7 && $contract['days_until_expiry'] > 0) ? 'table-warning' : ''; ?>">
                                            <td>
                                                <div class="d-flex align-items-center">
                                                    <div class="avatar bg-primary text-white rounded-circle me-3 flex-shrink-0" style="width: 40px; height: 40px; display: flex; align-items: center; justify-content: center;">
                                                        <?php echo mb_substr($contract['first_name'], 0, 1, 'UTF-8'); ?>
                                                    </div>
                                                    <div class="min-w-0">
                                                        <div class="fw-bold text-truncate"><?php echo $contract['first_name'] . ' ' . $contract['last_name']; ?></div>
                                                        <small class="text-muted d-block">
                                                            <i class="bi bi-telephone"></i>
                                                            <?php echo $contract['phone']; ?>
                                                        </small>
                                                        <?php if ($contract['pending_invoices'] > 0): ?>
                                                            <small class="text-danger">
                                                                <i class="bi bi-exclamation-circle"></i>
                                                                ค้างชำระ <?php echo $contract['pending_invoices']; ?> บิล
                                                            </small>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="text-center">
                                                    <span class="badge bg-info fs-6">ห้อง <?php echo $contract['room_number']; ?></span>
                                                    <br><small class="text-muted">
                                                        ชั้น <?php echo $contract['floor_number']; ?>
                                                    </small>
                                                    <br><small class="text-muted">
                                                        <?php
                                                        switch ($contract['room_type']) {
                                                            case 'single': echo 'เดี่ยว'; break;
                                                            case 'double': echo 'คู่'; break;
                                                            case 'triple': echo 'สาม'; break;
                                                        }
                                                        ?>
                                                    </small>
                                                </div>
                                            </td>
                                            <td class="text-center">
                                                <div class="fw-bold"><?php echo formatDate($contract['contract_start']); ?></div>
                                                <small class="text-muted">
                                                    <?php echo floor((strtotime($contract['contract_end']) - strtotime($contract['contract_start'])) / (60*60*24*30)); ?> เดือน
                                                </small>
                                            </td>
                                            <td class="text-center">
                                                <div class="fw-bold"><?php echo formatDate($contract['contract_end']); ?></div>
                                                <?php if ($contract['contract_status'] == 'active' && $contract['days_until_expiry'] <= 30): ?>
                                                    <small class="text-warning">
                                                        <i class="bi bi-exclamation-triangle"></i>
                                                        ใกล้หมดอายุ
                                                    </small>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-end">
                                                <div class="fw-bold"><?php echo formatCurrency($contract['monthly_rent']); ?></div>
                                            </td>
                                            <td class="text-end">
                                                <div><?php echo formatCurrency($contract['deposit_paid']); ?></div>
                                            </td>
                                            <td class="text-center">
                                                <?php
                                                $status_class = '';
                                                $status_text = '';
                                                $status_icon = '';
                                                
                                                switch ($contract['contract_status']) {
                                                    case 'active':
                                                        $status_class = 'bg-success';
                                                        $status_text = 'ใช้งานอยู่';
                                                        $status_icon = 'bi-check-circle';
                                                        break;
                                                    case 'expired':
                                                        $status_class = 'bg-secondary';
                                                        $status_text = 'หมดอายุ';
                                                        $status_icon = 'bi-x-circle';
                                                        break;
                                                    case 'terminated':
                                                        $status_class = 'bg-danger';
                                                        $status_text = 'ยกเลิก';
                                                        $status_icon = 'bi-ban';
                                                        break;
                                                }
                                                ?>
                                                <span class="badge <?php echo $status_class; ?>">
                                                    <i class="<?php echo $status_icon; ?>"></i>
                                                    <?php echo $status_text; ?>
                                                </span>
                                            </td>
                                            <td class="text-center">
                                                <?php if ($contract['contract_status'] == 'active'): ?>
                                                    <?php if ($contract['days_until_expiry'] > 0): ?>
                                                        <div class="fw-bold <?php echo $contract['days_until_expiry'] <= 30 ? 'text-warning' : 'text-muted'; ?>">
                                                            <?php echo $contract['days_until_expiry']; ?> วัน
                                                        </div>
                                                        <?php if ($contract['days_until_expiry'] <= 7): ?>
                                                            <small class="text-danger">
                                                                <i class="bi bi-exclamation-triangle"></i>
                                                                เร่งด่วน
                                                            </small>
                                                        <?php endif; ?>
                                                    <?php else: ?>
                                                        <span class="text-danger fw-bold">หมดอายุแล้ว</span>
                                                        <br><small class="text-danger">
                                                            <?php echo abs($contract['days_until_expiry']); ?> วันแล้ว
                                                        </small>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    <span class="text-muted">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="btn-group-vertical btn-group-sm w-100" role="group">
                                                    <a href="view_contract.php?id=<?php echo $contract['contract_id']; ?>" 
                                                       class="btn btn-outline-info btn-sm" 
                                                       data-bs-toggle="tooltip" 
                                                       title="ดูรายละเอียด">
                                                        <i class="bi bi-eye"></i>
                                                        ดูรายละเอียด
                                                    </a>
                                                    <?php if ($contract['contract_status'] == 'active'): ?>
                                                        <div class="btn-group" role="group">
                                                            <a href="edit_contract.php?id=<?php echo $contract['contract_id']; ?>" 
                                                               class="btn btn-outline-primary btn-sm" 
                                                               data-bs-toggle="tooltip" 
                                                               title="แก้ไข">
                                                                <i class="bi bi-pencil"></i>
                                                                แก้ไข
                                                            </a>
                                                            <button type="button" 
                                                                    class="btn btn-outline-warning btn-sm" 
                                                                    data-bs-toggle="modal" 
                                                                    data-bs-target="#extendModal"
                                                                    data-contract-id="<?php echo $contract['contract_id']; ?>"
                                                                    data-tenant-name="<?php echo $contract['first_name'] . ' ' . $contract['last_name']; ?>"
                                                                    data-room-number="<?php echo $contract['room_number']; ?>"
                                                                    data-current-end="<?php echo $contract['contract_end']; ?>"
                                                                    data-current-rent="<?php echo $contract['monthly_rent']; ?>"
                                                                    title="ต่อสัญญา">
                                                                <i class="bi bi-arrow-right-circle"></i>
                                                                ต่อสัญญา
                                                            </button>
                                                        </div>
                                                        <div class="btn-group" role="group">
                                                            <a href="invoices.php?contract_id=<?php echo $contract['contract_id']; ?>" 
                                                               class="btn btn-outline-success btn-sm" 
                                                               data-bs-toggle="tooltip" 
                                                               title="ใบแจ้งหนี้">
                                                                <i class="bi bi-receipt"></i>
                                                                ใบแจ้งหนี้
                                                            </a>
                                                            <a href="contracts.php?terminate=<?php echo $contract['contract_id']; ?>" 
                                                               class="btn btn-outline-danger btn-sm" 
                                                               data-bs-toggle="tooltip" 
                                                               title="ยกเลิกสัญญา"
                                                               onclick="return confirmDelete('คุณต้องการยกเลิกสัญญาของ <?php echo $contract['first_name'] . ' ' . $contract['last_name']; ?> หรือไม่?\n\nการยกเลิกสัญญาจะทำให้:\n- สัญญาถูกยกเลิก\n- ห้องกลับมาว่าง\n- ไม่สามารถยกเลิกการดำเนินการได้')">
                                                                <i class="bi bi-x-circle"></i>
                                                                ยกเลิก
                                                            </a>
                                                        </div>
                                                    <?php else: ?>
                                                        <small class="text-muted text-center">ไม่สามารถแก้ไขได้</small>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        
                        <!-- สรุปข้อมูลท้ายตาราง -->
                        <div class="border-top pt-3 mt-3">
                            <div class="row text-center">
                                <div class="col-md-3">
                                    <div class="border-end">
                                        <h6 class="text-muted mb-1">จำนวนสัญญาที่แสดง</h6>
                                        <h4 class="text-primary"><?php echo number_format(count($contracts)); ?></h4>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="border-end">
                                        <h6 class="text-muted mb-1">รายได้รวม/เดือน</h6>
                                        <h4 class="text-success">
                                            <?php 
                                            $total_rent = array_sum(array_column(
                                                array_filter($contracts, function($c) { return $c['contract_status'] == 'active'; }), 
                                                'monthly_rent'
                                            ));
                                            echo formatCurrency($total_rent); 
                                            ?>
                                        </h4>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="border-end">
                                        <h6 class="text-muted mb-1">ค่าเช่าเฉลี่ย</h6>
                                        <h4 class="text-info">
                                            <?php 
                                            $active_contracts = array_filter($contracts, function($c) { return $c['contract_status'] == 'active'; });
                                            $avg_rent = count($active_contracts) > 0 ? array_sum(array_column($active_contracts, 'monthly_rent')) / count($active_contracts) : 0;
                                            echo formatCurrency($avg_rent); 
                                            ?>
                                        </h4>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <h6 class="text-muted mb-1">อัตราการใช้งาน</h6>
                                    <h4 class="text-warning">
                                        <?php 
                                        $active_count = count(array_filter($contracts, function($c) { return $c['contract_status'] == 'active'; }));
                                        $total_count = count($contracts);
                                        echo $total_count > 0 ? round(($active_count / $total_count) * 100, 1) . '%' : '0%'; 
                                        ?>
                                    </h4>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- สัญญาที่ใกล้หมดอายุ -->
            <?php if (!$expiring_filter && $stats['expiring_soon'] > 0): ?>
                <div class="card mt-4">
                    <div class="card-header bg-warning text-dark d-flex align-items-center">
                        <i class="bi bi-exclamation-triangle fs-4 me-3"></i>
                        <div class="flex-grow-1">
                            <h5 class="mb-0">แจ้งเตือน: สัญญาที่ใกล้หมดอายุ</h5>
                            <small>สัญญาที่จะหมดอายุภายใน 30 วันข้างหน้า</small>
                        </div>
                        <a href="contracts.php?filter=expiring" class="btn btn-dark">
                            <i class="bi bi-list"></i>
                            ดูรายละเอียด
                        </a>
                    </div>
                    <div class="card-body">
                        <div class="row align-items-center">
                            <div class="col-md-8">
                                <div class="row">
                                    <div class="col-6">
                                        <div class="d-flex align-items-center">
                                            <i class="bi bi-file-earmark-text fs-1 text-warning me-3"></i>
                                            <div>
                                                <h2 class="mb-0 text-warning"><?php echo $stats['expiring_soon']; ?></h2>
                                                <p class="mb-0">สัญญาใกล้หมดอายุ</p>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-6">
                                        <ul class="list-unstyled mb-0">
                                            <li><i class="bi bi-check text-success me-2"></i>ติดต่อผู้เช่าเพื่อหารือการต่อสัญญา</li>
                                            <li><i class="bi bi-check text-success me-2"></i>เตรียมเอกสารสัญญาใหม่</li>
                                            <li><i class="bi bi-check text-success me-2"></i>ตรวจสอบสภาพห้องพัก</li>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4 text-center">
                                <div class="bg-light rounded p-3">
                                    <h6 class="text-muted mb-2">การดำเนินการที่แนะนำ</h6>
                                    <div class="d-grid gap-2">
                                        <a href="contracts.php?filter=expiring" class="btn btn-warning btn-sm">
                                            <i class="bi bi-eye"></i> ดูสัญญาใกล้หมดอายุ
                                        </a>
                                        <a href="notifications.php?type=contract_expiring" class="btn btn-outline-secondary btn-sm">
                                            <i class="bi bi-bell"></i> ส่งการแจ้งเตือน
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Modal ต่อสัญญา -->
<div class="modal fade" id="extendModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bi bi-arrow-right-circle"></i>
                        ต่อสัญญาเช่า
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle"></i>
                        <strong>หมายเหตุ:</strong> การต่อสัญญาจะเป็นการขยายระยะเวลาสัญญาปัจจุบัน
                    </div>
                    
                    <div class="row mb-3">
                        <div class="col-6">
                            <label class="form-label">ผู้เช่า:</label>
                            <div class="fw-bold" id="extend-tenant-name"></div>
                        </div>
                        <div class="col-6">
                            <label class="form-label">ห้อง:</label>
                            <div class="fw-bold" id="extend-room-number"></div>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">วันสิ้นสุดปัจจุบัน:</label>
                        <div class="fw-bold text-muted" id="extend-current-end"></div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="new_end_date" class="form-label">วันสิ้นสุดใหม่ <span class="text-danger">*</span></label>
                        <input type="date" class="form-control" id="new_end_date" name="new_end_date" required>
                    </div>
                    
                    <div class="mb-3">
                        <label for="new_rent" class="form-label">ค่าเช่าต่อเดือน (บาท) <span class="text-danger">*</span></label>
                        <input type="number" class="form-control" id="new_rent" name="new_rent" step="0.01" min="0" required>
                        <div class="form-text">สามารถปรับค่าเช่าได้หากต้องการ</div>
                    </div>
                    
                    <input type="hidden" id="extend_contract_id" name="contract_id">
                    <input type="hidden" name="extend_contract" value="1">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="bi bi-x"></i>
                        ยกเลิก
                    </button>
                    <button type="submit" class="btn btn-success">
                        <i class="bi bi-check-circle"></i>
                        ต่อสัญญา
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Handle extend contract modal
    const extendModal = document.getElementById('extendModal');
    extendModal.addEventListener('show.bs.modal', function (event) {
        const button = event.relatedTarget;
        const contractId = button.getAttribute('data-contract-id');
        const tenantName = button.getAttribute('data-tenant-name');
        const roomNumber = button.getAttribute('data-room-number');
        const currentEnd = button.getAttribute('data-current-end');
        const currentRent = button.getAttribute('data-current-rent');
        
        document.getElementById('extend_contract_id').value = contractId;
        document.getElementById('extend-tenant-name').textContent = tenantName;
        document.getElementById('extend-room-number').textContent = 'ห้อง ' + roomNumber;
        document.getElementById('extend-current-end').textContent = formatDateThai(currentEnd);
        document.getElementById('new_rent').value = currentRent;
        
        // Set minimum date to current end date + 1 day
        const minDate = new Date(currentEnd);
        minDate.setDate(minDate.getDate() + 1);
        document.getElementById('new_end_date').min = minDate.toISOString().split('T')[0];
        
        // Set default new end date to 1 year after current end
        const defaultDate = new Date(currentEnd);
        defaultDate.setFullYear(defaultDate.getFullYear() + 1);
        document.getElementById('new_end_date').value = defaultDate.toISOString().split('T')[0];
    });
    
    // Format date to Thai format
    function formatDateThai(dateStr) {
        const months = [
            'มกราคม', 'กุมภาพันธ์', 'มีนาคม', 'เมษายน', 'พฤษภาคม', 'มิถุนายน',
            'กรกฎาคม', 'สิงหาคม', 'กันยายน', 'ตุลาคม', 'พฤศจิกายน', 'ธันวาคม'
        ];
        
        const date = new Date(dateStr);
        const day = date.getDate();
        const month = months[date.getMonth()];
        const year = date.getFullYear() + 543;
        
        return `${day} ${month} ${year}`;
    }
    
    // Enhanced confirm delete with more details
    window.confirmDelete = function(message) {
        return confirm(message);
    };
    
    // Auto refresh page every 5 minutes to update days until expiry
    setTimeout(function() {
        if (!document.hidden) {
            window.location.reload();
        }
    }, 300000); // 5 minutes
});
</script>

<style>
.table th {
    white-space: nowrap;
    vertical-align: middle;
}

.table td {
    vertical-align: middle;
}

.btn-group-vertical .btn {
    margin-bottom: 2px;
}

.btn-group-vertical .btn:last-child {
    margin-bottom: 0;
}

.badge {
    font-size: 0.75rem;
}

.avatar {
    font-weight: 600;
    font-size: 1.1rem;
}

.card {
    transition: all 0.3s ease-in-out;
}

.table-warning {
    background-color: rgba(255, 193, 7, 0.1) !important;
}

.min-w-0 {
    min-width: 0;
}

.text-truncate {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.border-end {
    border-right: 1px solid #dee2e6 !important;
}

@media (max-width: 768px) {
    .btn-group-vertical {
        width: 100%;
    }
    
    .btn-group-vertical .btn {
        width: 100%;
        margin-bottom: 1px;
    }
    
    .border-end {
        border-right: none !important;
        border-bottom: 1px solid #dee2e6 !important;
        margin-bottom: 1rem;
        padding-bottom: 1rem;
    }
    
    .border-end:last-child {
        border-bottom: none !important;
        margin-bottom: 0;
        padding-bottom: 0;
    }
}

@media print {
    .btn, .dropdown, .alert {
        display: none !important;
    }
    
    .card {
        border: 1px solid #000 !important;
        box-shadow: none !important;
    }
}
</style>

<?php include 'includes/footer.php'; ?>