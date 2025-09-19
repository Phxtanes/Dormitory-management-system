<?php
$page_title = "จัดการใบแจ้งหนี้";
require_once 'includes/header.php';

// ตรวจสอบการอัพเดทสถานะการชำระเงิน
if (isset($_POST['update_payment'])) {
    try {
        $invoice_id = $_POST['invoice_id'];
        $payment_amount = floatval($_POST['payment_amount']);
        $payment_method = $_POST['payment_method'];
        $payment_date = $_POST['payment_date'];
        $payment_reference = $_POST['payment_reference'] ?? '';
        $notes = $_POST['notes'] ?? '';
        
        // เริ่มต้น transaction
        $pdo->beginTransaction();
        
        // บันทึกการชำระเงิน
        $stmt = $pdo->prepare("INSERT INTO payments (invoice_id, payment_amount, payment_method, payment_date, payment_reference, notes) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$invoice_id, $payment_amount, $payment_method, $payment_date, $payment_reference, $notes]);
        
        // อัพเดทสถานะใบแจ้งหนี้
        $stmt = $pdo->prepare("UPDATE invoices SET invoice_status = 'paid', payment_date = ? WHERE invoice_id = ?");
        $stmt->execute([$payment_date, $invoice_id]);
        
        $pdo->commit();
        $success_message = "บันทึกการชำระเงินเรียบร้อยแล้ว";
        
    } catch(PDOException $e) {
        $pdo->rollBack();
        $error_message = "เกิดข้อผิดพลาด: " . $e->getMessage();
    }
}

// ตรวจสอบการลบใบแจ้งหนี้
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    try {
        // ตรวจสอบว่าใบแจ้งหนี้ยังไม่ได้ชำระ
        $stmt = $pdo->prepare("SELECT invoice_status FROM invoices WHERE invoice_id = ?");
        $stmt->execute([$_GET['delete']]);
        $invoice = $stmt->fetch();
        
        if ($invoice && $invoice['invoice_status'] == 'pending') {
            $stmt = $pdo->prepare("DELETE FROM invoices WHERE invoice_id = ?");
            $stmt->execute([$_GET['delete']]);
            $success_message = "ลบใบแจ้งหนี้เรียบร้อยแล้ว";
        } else {
            $error_message = "ไม่สามารถลบใบแจ้งหนี้ที่ชำระแล้วได้";
        }
    } catch(PDOException $e) {
        $error_message = "เกิดข้อผิดพลาด: " . $e->getMessage();
    }
}

// ดึงข้อมูลใบแจ้งหนี้
$search = isset($_GET['search']) ? $_GET['search'] : '';
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';
$month_filter = isset($_GET['month']) ? $_GET['month'] : '';
$contract_filter = isset($_GET['contract_id']) ? $_GET['contract_id'] : '';

$sql = "SELECT i.*, 
        r.room_number, r.room_type, r.floor_number,
        t.first_name, t.last_name, t.phone, t.email,
        c.contract_start, c.contract_end,
        DATEDIFF(CURDATE(), i.due_date) as days_overdue,
        (SELECT SUM(p.payment_amount) FROM payments p WHERE p.invoice_id = i.invoice_id) as total_paid
        FROM invoices i
        JOIN contracts c ON i.contract_id = c.contract_id
        JOIN rooms r ON c.room_id = r.room_id
        JOIN tenants t ON c.tenant_id = t.tenant_id
        WHERE 1=1";

$params = [];

if (!empty($search)) {
    $sql .= " AND (t.first_name LIKE ? OR t.last_name LIKE ? OR r.room_number LIKE ? OR t.phone LIKE ?)";
    $search_term = "%$search%";
    $params = array_fill(0, 4, $search_term);
}

if (!empty($status_filter)) {
    if ($status_filter == 'overdue') {
        $sql .= " AND (i.invoice_status = 'overdue' OR (i.invoice_status = 'pending' AND i.due_date < CURDATE()))";
    } else {
        $sql .= " AND i.invoice_status = ?";
        $params[] = $status_filter;
    }
}

if (!empty($month_filter)) {
    $sql .= " AND i.invoice_month = ?";
    $params[] = $month_filter;
}

if (!empty($contract_filter)) {
    $sql .= " AND i.contract_id = ?";
    $params[] = $contract_filter;
}

$sql .= " ORDER BY 
    CASE 
        WHEN i.invoice_status = 'overdue' OR (i.invoice_status = 'pending' AND i.due_date < CURDATE()) THEN 1
        WHEN i.invoice_status = 'pending' THEN 2
        WHEN i.invoice_status = 'paid' THEN 3
        WHEN i.invoice_status = 'cancelled' THEN 4
    END,
    i.due_date ASC";

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $invoices = $stmt->fetchAll();
    
    // ดึงข้อมูลสถิติ
    $stats_sql = "SELECT 
        COUNT(*) as total_invoices,
        SUM(CASE WHEN invoice_status = 'paid' THEN 1 ELSE 0 END) as paid_invoices,
        SUM(CASE WHEN invoice_status = 'pending' THEN 1 ELSE 0 END) as pending_invoices,
        SUM(CASE WHEN invoice_status = 'overdue' OR (invoice_status = 'pending' AND due_date < CURDATE()) THEN 1 ELSE 0 END) as overdue_invoices,
        SUM(CASE WHEN invoice_status = 'cancelled' THEN 1 ELSE 0 END) as cancelled_invoices,
        SUM(CASE WHEN invoice_status = 'paid' THEN total_amount ELSE 0 END) as total_revenue,
        SUM(CASE WHEN invoice_status IN ('pending', 'overdue') OR (invoice_status = 'pending' AND due_date < CURDATE()) THEN total_amount ELSE 0 END) as pending_amount
        FROM invoices i
        JOIN contracts c ON i.contract_id = c.contract_id
        WHERE c.contract_status = 'active'";
    
    $stats_stmt = $pdo->query($stats_sql);
    $stats = $stats_stmt->fetch();
    
} catch(PDOException $e) {
    $error_message = "เกิดข้อผิดพลาดในการดึงข้อมูล: " . $e->getMessage();
    $invoices = [];
    $stats = [
        'total_invoices' => 0,
        'paid_invoices' => 0,
        'pending_invoices' => 0,
        'overdue_invoices' => 0,
        'cancelled_invoices' => 0,
        'total_revenue' => 0,
        'pending_amount' => 0
    ];
}
?>

<?php include 'includes/navbar.php'; ?>

<div class="container-fluid mt-4">
    <div class="row">
        <div class="col-12">
            <!-- หัวข้อหน้า -->
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2 class="mb-0">
                    <i class="bi bi-receipt"></i>
                    จัดการใบแจ้งหนี้
                    <?php if ($status_filter == 'overdue'): ?>
                        <span class="badge bg-danger">ค้างชำระ</span>
                    <?php elseif ($status_filter): ?>
                        <span class="badge bg-info"><?php 
                            echo $status_filter == 'paid' ? 'ชำระแล้ว' : 
                                ($status_filter == 'pending' ? 'รอชำระ' : 'ยกเลิก'); 
                        ?></span>
                    <?php endif; ?>
                </h2>
                <div class="btn-group">
                    <a href="generate_bills.php" class="btn btn-primary">
                        <i class="bi bi-receipt-cutoff"></i>
                        สร้างใบแจ้งหนี้
                    </a>
                    <button type="button" class="btn btn-outline-secondary dropdown-toggle dropdown-toggle-split" data-bs-toggle="dropdown">
                        <span class="visually-hidden">Toggle Dropdown</span>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><a class="dropdown-item" href="reports_income.php">
                            <i class="bi bi-graph-up"></i> รายงานรายได้
                        </a></li>
                        <li><a class="dropdown-item" href="export_invoices.php">
                            <i class="bi bi-download"></i> ส่งออกข้อมูล
                        </a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="payments.php">
                            <i class="bi bi-cash-coin"></i> ประวัติการชำระ
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
                            <i class="bi bi-receipt fs-2"></i>
                            <h4 class="mt-2"><?php echo number_format($stats['total_invoices']); ?></h4>
                            <p class="mb-0">ใบแจ้งหนี้ทั้งหมด</p>
                        </div>
                    </div>
                </div>
                <div class="col-xl-2 col-lg-3 col-md-4 col-sm-6 mb-3">
                    <div class="card text-white bg-success h-100">
                        <div class="card-body text-center">
                            <i class="bi bi-check-circle fs-2"></i>
                            <h4 class="mt-2"><?php echo number_format($stats['paid_invoices']); ?></h4>
                            <p class="mb-0">ชำระแล้ว</p>
                        </div>
                    </div>
                </div>
                <div class="col-xl-2 col-lg-3 col-md-4 col-sm-6 mb-3">
                    <div class="card text-white bg-warning h-100">
                        <div class="card-body text-center">
                            <i class="bi bi-clock fs-2"></i>
                            <h4 class="mt-2"><?php echo number_format($stats['pending_invoices']); ?></h4>
                            <p class="mb-0">รอชำระ</p>
                        </div>
                    </div>
                </div>
                <div class="col-xl-2 col-lg-3 col-md-4 col-sm-6 mb-3">
                    <div class="card text-white bg-danger h-100">
                        <div class="card-body text-center">
                            <i class="bi bi-exclamation-triangle fs-2"></i>
                            <h4 class="mt-2"><?php echo number_format($stats['overdue_invoices']); ?></h4>
                            <p class="mb-0">ค้างชำระ</p>
                        </div>
                    </div>
                </div>
                <div class="col-xl-2 col-lg-3 col-md-4 col-sm-6 mb-3">
                    <div class="card text-white bg-info h-100">
                        <div class="card-body text-center">
                            <i class="bi bi-currency-dollar fs-2"></i>
                            <h5 class="mt-2"><?php echo formatCurrency($stats['total_revenue']); ?></h5>
                            <p class="mb-0">รายได้ที่ได้รับ</p>
                        </div>
                    </div>
                </div>
                <div class="col-xl-2 col-lg-3 col-md-4 col-sm-6 mb-3">
                    <div class="card text-white bg-dark h-100">
                        <div class="card-body text-center">
                            <i class="bi bi-hourglass fs-2"></i>
                            <h5 class="mt-2"><?php echo formatCurrency($stats['pending_amount']); ?></h5>
                            <p class="mb-0">ยอดค้างรับ</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- แจ้งเตือนใบแจ้งหนี้ค้างชำระ -->
            <?php if ($status_filter != 'overdue' && $stats['overdue_invoices'] > 0): ?>
                <div class="alert alert-danger d-flex align-items-center" role="alert">
                    <i class="bi bi-exclamation-triangle-fill fs-4 me-3"></i>
                    <div class="flex-grow-1">
                        <strong>แจ้งเตือน!</strong> มีใบแจ้งหนี้ <strong><?php echo $stats['overdue_invoices']; ?> ใบ</strong> 
                        ที่ค้างชำระ ยอดรวม <strong><?php echo formatCurrency($stats['pending_amount']); ?></strong>
                    </div>
                    <a href="invoices.php?status=overdue" class="btn btn-outline-danger">
                        <i class="bi bi-eye"></i>
                        ดูรายละเอียด
                    </a>
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
                        <div class="col-md-4">
                            <label for="search" class="form-label">ค้นหา</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-search"></i></span>
                                <input type="text" class="form-control" id="search" name="search" 
                                       value="<?php echo htmlspecialchars($search); ?>" 
                                       placeholder="ชื่อผู้เช่า หมายเลขห้อง หรือ โทรศัพท์">
                            </div>
                        </div>
                        <div class="col-md-2">
                            <label for="status" class="form-label">สถานะ</label>
                            <select class="form-select" id="status" name="status">
                                <option value="">ทั้งหมด</option>
                                <option value="pending" <?php echo $status_filter == 'pending' ? 'selected' : ''; ?>>รอชำระ</option>
                                <option value="paid" <?php echo $status_filter == 'paid' ? 'selected' : ''; ?>>ชำระแล้ว</option>
                                <option value="overdue" <?php echo $status_filter == 'overdue' ? 'selected' : ''; ?>>ค้างชำระ</option>
                                <option value="cancelled" <?php echo $status_filter == 'cancelled' ? 'selected' : ''; ?>>ยกเลิก</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label for="month" class="form-label">เดือน</label>
                            <input type="month" class="form-control" id="month" name="month" 
                                   value="<?php echo htmlspecialchars($month_filter); ?>">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">&nbsp;</label>
                            <div class="d-grid gap-2 d-md-flex">
                                <button type="submit" class="btn btn-primary flex-fill">
                                    <i class="bi bi-search"></i>
                                    ค้นหา
                                </button>
                                <a href="invoices.php" class="btn btn-outline-secondary">
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
                    <a href="invoices.php?status=pending" class="btn btn-outline-warning w-100 d-flex align-items-center">
                        <i class="bi bi-clock fs-5 me-2"></i>
                        <div class="text-start">
                            <div class="fw-bold">รอชำระ</div>
                            <small class="text-muted"><?php echo number_format($stats['pending_invoices']); ?> ใบ</small>
                        </div>
                    </a>
                </div>
                <div class="col-lg-3 col-md-6 mb-2">
                    <a href="invoices.php?status=overdue" class="btn btn-outline-danger w-100 d-flex align-items-center">
                        <i class="bi bi-exclamation-triangle fs-5 me-2"></i>
                        <div class="text-start">
                            <div class="fw-bold">ค้างชำระ</div>
                            <small class="text-muted"><?php echo number_format($stats['overdue_invoices']); ?> ใบ</small>
                        </div>
                    </a>
                </div>
                <div class="col-lg-3 col-md-6 mb-2">
                    <a href="invoices.php?status=paid" class="btn btn-outline-success w-100 d-flex align-items-center">
                        <i class="bi bi-check-circle fs-5 me-2"></i>
                        <div class="text-start">
                            <div class="fw-bold">ชำระแล้ว</div>
                            <small class="text-muted"><?php echo number_format($stats['paid_invoices']); ?> ใบ</small>
                        </div>
                    </a>
                </div>
                <div class="col-lg-3 col-md-6 mb-2">
                    <a href="generate_bills.php" class="btn btn-primary w-100 d-flex align-items-center">
                        <i class="bi bi-receipt-cutoff fs-5 me-2"></i>
                        <div class="text-start">
                            <div class="fw-bold">สร้างใบแจ้งหนี้</div>
                            <small class="text-white-50">สำหรับเดือนใหม่</small>
                        </div>
                    </a>
                </div>
            </div>

            <!-- ตารางแสดงข้อมูลใบแจ้งหนี้ -->
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">
                        <i class="bi bi-list"></i>
                        รายการใบแจ้งหนี้
                    </h5>
                    <div class="d-flex align-items-center gap-2">
                        <span class="badge bg-primary"><?php echo number_format(count($invoices)); ?> ใบ</span>
                        <div class="btn-group">
                            <button type="button" class="btn btn-sm btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown">
                                <i class="bi bi-three-dots"></i>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end">
                                <li><a class="dropdown-item" href="#" onclick="window.print()">
                                    <i class="bi bi-printer"></i> พิมพ์รายการ
                                </a></li>
                                <li><a class="dropdown-item" href="export_invoices.php?<?php echo http_build_query($_GET); ?>">
                                    <i class="bi bi-download"></i> ส่งออก Excel
                                </a></li>
                            </ul>
                        </div>
                    </div>
                </div>
                <div class="card-body">
                    <?php if (empty($invoices)): ?>
                        <div class="text-center py-5">
                            <i class="bi bi-receipt display-1 text-muted"></i>
                            <h4 class="text-muted mt-3">ไม่พบข้อมูลใบแจ้งหนี้</h4>
                            <p class="text-muted">
                                <?php if (!empty($search) || !empty($status_filter) || !empty($month_filter)): ?>
                                    ไม่มีข้อมูลที่ตรงกับเงื่อนไขการค้นหา
                                <?php else: ?>
                                    ยังไม่มีข้อมูลใบแจ้งหนี้ในระบบ
                                <?php endif; ?>
                            </p>
                            <div class="mt-3">
                                <?php if (!empty($search) || !empty($status_filter) || !empty($month_filter)): ?>
                                    <a href="invoices.php" class="btn btn-secondary me-2">
                                        <i class="bi bi-arrow-left"></i>
                                        ดูทั้งหมด
                                    </a>
                                <?php endif; ?>
                                <a href="generate_bills.php" class="btn btn-primary">
                                    <i class="bi bi-receipt-cutoff"></i>
                                    สร้างใบแจ้งหนี้
                                </a>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead class="table-light">
                                    <tr>
                                        <th style="width: 150px;">เลขที่ใบแจ้งหนี้</th>
                                        <th style="width: 180px;">ผู้เช่า</th>
                                        <th style="width: 80px;">ห้อง</th>
                                        <th style="width: 100px;">เดือน</th>
                                        <th style="width: 120px;">จำนวนเงิน</th>
                                        <th style="width: 100px;">กำหนดชำระ</th>
                                        <th style="width: 100px;">สถานะ</th>
                                        <th style="width: 100px;">วันที่เกิน</th>
                                        <th style="width: 200px;" class="text-center">จัดการ</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($invoices as $invoice): ?>
                                        <?php
                                        $is_overdue = ($invoice['invoice_status'] == 'overdue' || 
                                                      ($invoice['invoice_status'] == 'pending' && $invoice['days_overdue'] > 0));
                                        $row_class = '';
                                        if ($is_overdue) {
                                            $row_class = 'table-danger';
                                        } elseif ($invoice['invoice_status'] == 'pending' && $invoice['days_overdue'] > -7) {
                                            $row_class = 'table-warning';
                                        }
                                        ?>
                                        <tr class="<?php echo $row_class; ?>">
                                            <td>
                                                <div class="fw-bold">#INV-<?php echo str_pad($invoice['invoice_id'], 6, '0', STR_PAD_LEFT); ?></div>
                                                <small class="text-muted"><?php echo formatDateTime($invoice['created_at']); ?></small>
                                            </td>
                                            <td>
                                                <div class="d-flex align-items-center">
                                                    <div class="avatar bg-primary text-white rounded-circle me-2 flex-shrink-0" style="width: 32px; height: 32px; display: flex; align-items: center; justify-content: center; font-size: 0.8rem;">
                                                        <?php echo mb_substr($invoice['first_name'], 0, 1, 'UTF-8'); ?>
                                                    </div>
                                                    <div class="min-w-0">
                                                        <div class="fw-bold text-truncate"><?php echo $invoice['first_name'] . ' ' . $invoice['last_name']; ?></div>
                                                        <small class="text-muted d-block">
                                                            <i class="bi bi-telephone"></i>
                                                            <?php echo $invoice['phone']; ?>
                                                        </small>
                                                    </div>
                                                </div>
                                            </td>
                                            <td class="text-center">
                                                <span class="badge bg-info">ห้อง <?php echo $invoice['room_number']; ?></span>
                                                <br><small class="text-muted">ชั้น <?php echo $invoice['floor_number']; ?></small>
                                            </td>
                                            <td class="text-center">
                                                <div class="fw-bold"><?php echo thaiMonth(substr($invoice['invoice_month'], 5, 2)); ?></div>
                                                <small class="text-muted"><?php echo substr($invoice['invoice_month'], 0, 4); ?></small>
                                            </td>
                                            <td class="text-end">
                                                <div class="fw-bold"><?php echo formatCurrency($invoice['total_amount']); ?></div>
                                                <?php if ($invoice['discount'] > 0): ?>
                                                    <small class="text-success">ส่วนลด <?php echo formatCurrency($invoice['discount']); ?></small>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-center">
                                                <div class="fw-bold"><?php echo formatDate($invoice['due_date']); ?></div>
                                                <?php if ($is_overdue): ?>
                                                    <small class="text-danger">
                                                        <i class="bi bi-exclamation-triangle"></i>
                                                        เกินกำหนด
                                                    </small>
                                                <?php elseif ($invoice['invoice_status'] == 'pending' && $invoice['days_overdue'] > -7): ?>
                                                    <small class="text-warning">
                                                        <i class="bi bi-clock"></i>
                                                        ใกล้ครบกำหนด
                                                    </small>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-center">
                                                <?php
                                                $status_class = '';
                                                $status_text = '';
                                                $status_icon = '';
                                                
                                                switch ($invoice['invoice_status']) {
                                                    case 'paid':
                                                        $status_class = 'bg-success';
                                                        $status_text = 'ชำระแล้ว';
                                                        $status_icon = 'bi-check-circle';
                                                        break;
                                                    case 'pending':
                                                        if ($is_overdue) {
                                                            $status_class = 'bg-danger';
                                                            $status_text = 'ค้างชำระ';
                                                            $status_icon = 'bi-exclamation-triangle';
                                                        } else {
                                                            $status_class = 'bg-warning';
                                                            $status_text = 'รอชำระ';
                                                            $status_icon = 'bi-clock';
                                                        }
                                                        break;
                                                    case 'overdue':
                                                        $status_class = 'bg-danger';
                                                        $status_text = 'ค้างชำระ';
                                                        $status_icon = 'bi-exclamation-triangle';
                                                        break;
                                                    case 'cancelled':
                                                        $status_class = 'bg-secondary';
                                                        $status_text = 'ยกเลิก';
                                                        $status_icon = 'bi-x-circle';
                                                        break;
                                                }
                                                ?>
                                                <span class="badge <?php echo $status_class; ?>">
                                                    <i class="<?php echo $status_icon; ?>"></i>
                                                    <?php echo $status_text; ?>
                                                </span>
                                            </td>
                                            <td class="text-center">
                                                <?php if ($invoice['invoice_status'] == 'pending'): ?>
                                                    <?php if ($invoice['days_overdue'] > 0): ?>
                                                        <div class="fw-bold text-danger">
                                                            <?php echo $invoice['days_overdue']; ?> วัน
                                                        </div>
                                                        <small class="text-danger">เกินกำหนด</small>
                                                    <?php else: ?>
                                                        <div class="fw-bold text-<?php echo $invoice['days_overdue'] > -7 ? 'warning' : 'muted'; ?>">
                                                            <?php echo abs($invoice['days_overdue']); ?> วัน
                                                        </div>
                                                        <small class="text-muted">เหลือเวลา</small>
                                                    <?php endif; ?>
                                                <?php elseif ($invoice['invoice_status'] == 'paid'): ?>
                                                    <div class="text-success">
                                                        <i class="bi bi-check-circle"></i>
                                                        <div>ชำระแล้ว</div>
                                                        <small><?php echo formatDate($invoice['payment_date']); ?></small>
                                                    </div>
                                                <?php else: ?>
                                                    <span class="text-muted">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="btn-group-vertical btn-group-sm w-100" role="group">
                                                    <a href="view_invoice.php?id=<?php echo $invoice['invoice_id']; ?>" 
                                                       class="btn btn-outline-info btn-sm" 
                                                       data-bs-toggle="tooltip" 
                                                       title="ดูรายละเอียด">
                                                        <i class="bi bi-eye"></i>
                                                        ดูรายละเอียด
                                                    </a>
                                                    <?php if ($invoice['invoice_status'] == 'pending' || $is_overdue): ?>
                                                        <button type="button" 
                                                                class="btn btn-outline-success btn-sm" 
                                                                data-bs-toggle="modal" 
                                                                data-bs-target="#paymentModal"
                                                                data-invoice-id="<?php echo $invoice['invoice_id']; ?>"
                                                                data-tenant-name="<?php echo $invoice['first_name'] . ' ' . $invoice['last_name']; ?>"
                                                                data-room-number="<?php echo $invoice['room_number']; ?>"
                                                                data-amount="<?php echo $invoice['total_amount']; ?>"
                                                                data-month="<?php echo $invoice['invoice_month']; ?>"
                                                                title="บันทึกการชำระ">
                                                            <i class="bi bi-cash-coin"></i>
                                                            บันทึกการชำระ
                                                        </button>
                                                        <div class="btn-group" role="group">
                                                            <a href="edit_invoice.php?id=<?php echo $invoice['invoice_id']; ?>" 
                                                               class="btn btn-outline-warning btn-sm" 
                                                               data-bs-toggle="tooltip" 
                                                               title="แก้ไข">
                                                                <i class="bi bi-pencil"></i>
                                                                แก้ไข
                                                            </a>
                                                            <a href="print_invoice.php?id=<?php echo $invoice['invoice_id']; ?>" 
                                                               class="btn btn-outline-secondary btn-sm" 
                                                               data-bs-toggle="tooltip" 
                                                               title="พิมพ์"
                                                               target="_blank">
                                                                <i class="bi bi-printer"></i>
                                                                พิมพ์
                                                            </a>
                                                        </div>
                                                        <?php if ($invoice['invoice_status'] == 'pending'): ?>
                                                            <a href="invoices.php?delete=<?php echo $invoice['invoice_id']; ?>" 
                                                               class="btn btn-outline-danger btn-sm" 
                                                               data-bs-toggle="tooltip" 
                                                               title="ลบ"
                                                               onclick="return confirmDelete('คุณต้องการลบใบแจ้งหนี้เลขที่ #INV-<?php echo str_pad($invoice['invoice_id'], 6, '0', STR_PAD_LEFT); ?> หรือไม่?')">
                                                                <i class="bi bi-trash"></i>
                                                                ลบ
                                                            </a>
                                                        <?php endif; ?>
                                                    <?php else: ?>
                                                        <div class="btn-group" role="group">
                                                            <a href="print_invoice.php?id=<?php echo $invoice['invoice_id']; ?>" 
                                                               class="btn btn-outline-secondary btn-sm" 
                                                               target="_blank">
                                                                <i class="bi bi-printer"></i>
                                                                พิมพ์
                                                            </a>
                                                            <a href="payments.php?invoice_id=<?php echo $invoice['invoice_id']; ?>" 
                                                               class="btn btn-outline-info btn-sm">
                                                                <i class="bi bi-list"></i>
                                                                ประวัติการชำระ
                                                            </a>
                                                        </div>
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
                                        <h6 class="text-muted mb-1">จำนวนใบแจ้งหนี้</h6>
                                        <h4 class="text-primary"><?php echo number_format(count($invoices)); ?></h4>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="border-end">
                                        <h6 class="text-muted mb-1">ยอดรวมทั้งหมด</h6>
                                        <h4 class="text-info">
                                            <?php echo formatCurrency(array_sum(array_column($invoices, 'total_amount'))); ?>
                                        </h4>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="border-end">
                                        <h6 class="text-muted mb-1">ยอดที่ชำระแล้ว</h6>
                                        <h4 class="text-success">
                                            <?php 
                                            $paid_amount = array_sum(array_column(
                                                array_filter($invoices, function($i) { return $i['invoice_status'] == 'paid'; }), 
                                                'total_amount'
                                            ));
                                            echo formatCurrency($paid_amount); 
                                            ?>
                                        </h4>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <h6 class="text-muted mb-1">ยอดค้างชำระ</h6>
                                    <h4 class="text-danger">
                                        <?php 
                                        $pending_amount = array_sum(array_column(
                                            array_filter($invoices, function($i) { 
                                                return in_array($i['invoice_status'], ['pending', 'overdue']) || 
                                                       ($i['invoice_status'] == 'pending' && $i['days_overdue'] > 0); 
                                            }), 
                                            'total_amount'
                                        ));
                                        echo formatCurrency($pending_amount); 
                                        ?>
                                    </h4>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal บันทึกการชำระเงิน -->
<div class="modal fade" id="paymentModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bi bi-cash-coin"></i>
                        บันทึกการชำระเงิน
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle"></i>
                        <strong>หมายเหตุ:</strong> การบันทึกการชำระเงินจะเปลี่ยนสถานะใบแจ้งหนี้เป็น "ชำระแล้ว" ทันที
                    </div>
                    
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">ผู้เช่า:</label>
                            <div class="fw-bold" id="payment-tenant-name"></div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">ห้อง:</label>
                            <div class="fw-bold" id="payment-room-number"></div>
                        </div>
                    </div>
                    
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">เดือน:</label>
                            <div class="fw-bold" id="payment-month"></div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">ยอดที่ต้องชำระ:</label>
                            <div class="fw-bold text-primary" id="payment-amount-display"></div>
                        </div>
                    </div>
                    
                    <hr>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="payment_amount" class="form-label">จำนวนเงินที่รับ (บาท) <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" id="payment_amount" name="payment_amount" 
                                   step="0.01" min="0" required>
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <label for="payment_method" class="form-label">วิธีการชำระ <span class="text-danger">*</span></label>
                            <select class="form-select" id="payment_method" name="payment_method" required>
                                <option value="">เลือกวิธีการชำระ</option>
                                <option value="cash">เงินสด</option>
                                <option value="bank_transfer">โอนเงิน</option>
                                <option value="mobile_banking">Mobile Banking</option>
                                <option value="other">อื่นๆ</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="payment_date" class="form-label">วันที่ชำระ <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" id="payment_date" name="payment_date" 
                                   value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <label for="payment_reference" class="form-label">เลขที่อ้างอิง</label>
                            <input type="text" class="form-control" id="payment_reference" name="payment_reference" 
                                   placeholder="เลขที่ใบเสร็จ หรือ เลขที่ transaction">
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="notes" class="form-label">หมายเหตุ</label>
                        <textarea class="form-control" id="notes" name="notes" rows="2" 
                                  placeholder="หมายเหตุเพิ่มเติม (ถ้ามี)"></textarea>
                    </div>
                    
                    <input type="hidden" id="payment_invoice_id" name="invoice_id">
                    <input type="hidden" name="update_payment" value="1">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="bi bi-x"></i>
                        ยกเลิก
                    </button>
                    <button type="submit" class="btn btn-success">
                        <i class="bi bi-check-circle"></i>
                        บันทึกการชำระ
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Handle payment modal
    const paymentModal = document.getElementById('paymentModal');
    paymentModal.addEventListener('show.bs.modal', function (event) {
        const button = event.relatedTarget;
        const invoiceId = button.getAttribute('data-invoice-id');
        const tenantName = button.getAttribute('data-tenant-name');
        const roomNumber = button.getAttribute('data-room-number');
        const amount = parseFloat(button.getAttribute('data-amount'));
        const month = button.getAttribute('data-month');
        
        document.getElementById('payment_invoice_id').value = invoiceId;
        document.getElementById('payment-tenant-name').textContent = tenantName;
        document.getElementById('payment-room-number').textContent = 'ห้อง ' + roomNumber;
        document.getElementById('payment-amount-display').textContent = formatCurrency(amount);
        document.getElementById('payment_amount').value = amount;
        
        // Format month display
        const monthNames = [
            'มกราคม', 'กุมภาพันธ์', 'มีนาคม', 'เมษายน', 'พฤษภาคม', 'มิถุนายน',
            'กรกฎาคม', 'สิงหาคม', 'กันยายน', 'ตุลาคม', 'พฤศจิกายน', 'ธันวาคม'
        ];
        const [year, monthNum] = month.split('-');
        const monthName = monthNames[parseInt(monthNum) - 1];
        document.getElementById('payment-month').textContent = monthName + ' ' + year;
    });
    
    // Format currency function
    function formatCurrency(amount) {
        return new Intl.NumberFormat('th-TH', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        }).format(amount) + ' บาท';
    }
    
    // Enhanced confirm delete
    window.confirmDelete = function(message) {
        return confirm(message);
    };
    
    // Auto refresh for overdue status update
    if (window.location.search.includes('status=overdue') || window.location.search.includes('status=pending')) {
        setTimeout(function() {
            if (!document.hidden) {
                window.location.reload();
            }
        }, 300000); // 5 minutes
    }
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
    font-size: 0.8rem;
}

.card {
    transition: all 0.3s ease-in-out;
}

.table-danger {
    background-color: rgba(220, 53, 69, 0.1) !important;
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
    .btn, .dropdown, .alert, .modal {
        display: none !important;
    }
    
    .card {
        border: 1px solid #000 !important;
        box-shadow: none !important;
    }
}
</style>

<?php include 'includes/footer.php'; ?>