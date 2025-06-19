<?php
$page_title = "ประวัติการชำระเงิน";
require_once 'includes/header.php';

// ตรวจสอบการลบประวัติการชำระ
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    try {
        // ตรวจสอบสิทธิ์ในการลบ
        $stmt = $pdo->prepare("SELECT p.*, i.invoice_status FROM payments p JOIN invoices i ON p.invoice_id = i.invoice_id WHERE p.payment_id = ?");
        $stmt->execute([$_GET['delete']]);
        $payment = $stmt->fetch();
        
        if ($payment) {
            $pdo->beginTransaction();
            
            // ลบข้อมูลการชำระ
            $stmt = $pdo->prepare("DELETE FROM payments WHERE payment_id = ?");
            $stmt->execute([$_GET['delete']]);
            
            // เปลี่ยนสถานะใบแจ้งหนี้กลับเป็น pending
            $stmt = $pdo->prepare("UPDATE invoices SET invoice_status = 'pending', payment_date = NULL WHERE invoice_id = ?");
            $stmt->execute([$payment['invoice_id']]);
            
            $pdo->commit();
            $success_message = "ลบประวัติการชำระเงินเรียบร้อยแล้ว";
        } else {
            $error_message = "ไม่พบข้อมูลการชำระเงิน";
        }
    } catch(PDOException $e) {
        $pdo->rollBack();
        $error_message = "เกิดข้อผิดพลาด: " . $e->getMessage();
    }
}

// ดึงข้อมูลการชำระเงิน
$search = isset($_GET['search']) ? $_GET['search'] : '';
$method_filter = isset($_GET['method']) ? $_GET['method'] : '';
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : '';
$invoice_filter = isset($_GET['invoice_id']) ? $_GET['invoice_id'] : '';

$sql = "SELECT p.*, i.invoice_month, i.total_amount as invoice_amount,
        r.room_number, r.room_type, r.floor_number,
        t.first_name, t.last_name, t.phone,
        CONCAT('#INV-', LPAD(i.invoice_id, 6, '0')) as invoice_number
        FROM payments p
        JOIN invoices i ON p.invoice_id = i.invoice_id
        JOIN contracts c ON i.contract_id = c.contract_id
        JOIN rooms r ON c.room_id = r.room_id
        JOIN tenants t ON c.tenant_id = t.tenant_id
        WHERE 1=1";

$params = [];

if (!empty($search)) {
    $sql .= " AND (t.first_name LIKE ? OR t.last_name LIKE ? OR r.room_number LIKE ? OR p.payment_reference LIKE ?)";
    $search_term = "%$search%";
    $params = array_fill(0, 4, $search_term);
}

if (!empty($method_filter)) {
    $sql .= " AND p.payment_method = ?";
    $params[] = $method_filter;
}

if (!empty($date_from)) {
    $sql .= " AND p.payment_date >= ?";
    $params[] = $date_from;
}

if (!empty($date_to)) {
    $sql .= " AND p.payment_date <= ?";
    $params[] = $date_to;
}

if (!empty($invoice_filter)) {
    $sql .= " AND p.invoice_id = ?";
    $params[] = $invoice_filter;
}

$sql .= " ORDER BY p.payment_date DESC, p.created_at DESC";

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $payments = $stmt->fetchAll();
    
    // ดึงข้อมูลสถิติ
    $stats_sql = "SELECT 
        COUNT(*) as total_payments,
        SUM(payment_amount) as total_amount,
        AVG(payment_amount) as avg_amount,
        SUM(CASE WHEN payment_method = 'cash' THEN payment_amount ELSE 0 END) as cash_amount,
        SUM(CASE WHEN payment_method = 'bank_transfer' THEN payment_amount ELSE 0 END) as transfer_amount,
        SUM(CASE WHEN payment_method = 'mobile_banking' THEN payment_amount ELSE 0 END) as mobile_amount,
        SUM(CASE WHEN payment_method = 'other' THEN payment_amount ELSE 0 END) as other_amount
        FROM payments p";
    
    if (!empty($params)) {
        $stats_sql .= " JOIN invoices i ON p.invoice_id = i.invoice_id
                       JOIN contracts c ON i.contract_id = c.contract_id
                       JOIN rooms r ON c.room_id = r.room_id
                       JOIN tenants t ON c.tenant_id = t.tenant_id
                       WHERE " . str_replace("1=1 AND ", "", substr($sql, strpos($sql, "WHERE")));
    }
    
    $stmt = $pdo->prepare($stats_sql);
    $stmt->execute($params);
    $stats = $stmt->fetch();
    
    // ดึงข้อมูลรายได้รายเดือนล่าสุด 6 เดือน
    $monthly_sql = "SELECT 
        DATE_FORMAT(payment_date, '%Y-%m') as month,
        SUM(payment_amount) as total_amount,
        COUNT(*) as payment_count
        FROM payments 
        WHERE payment_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
        GROUP BY DATE_FORMAT(payment_date, '%Y-%m')
        ORDER BY month DESC
        LIMIT 6";
    
    $stmt = $pdo->query($monthly_sql);
    $monthly_data = $stmt->fetchAll();
    
} catch(PDOException $e) {
    $error_message = "เกิดข้อผิดพลาดในการดึงข้อมูล: " . $e->getMessage();
    $payments = [];
    $stats = [
        'total_payments' => 0,
        'total_amount' => 0,
        'avg_amount' => 0,
        'cash_amount' => 0,
        'transfer_amount' => 0,
        'mobile_amount' => 0,
        'other_amount' => 0
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
                    <i class="bi bi-cash-coin"></i>
                    ประวัติการชำระเงิน
                </h2>
                <div class="btn-group">
                    <a href="invoices.php" class="btn btn-outline-secondary">
                        <i class="bi bi-receipt"></i>
                        ใบแจ้งหนี้
                    </a>
                    <button type="button" class="btn btn-outline-primary" onclick="window.print()">
                        <i class="bi bi-printer"></i>
                        พิมพ์รายงาน
                    </button>
                    <button type="button" class="btn btn-primary" onclick="exportToExcel()">
                        <i class="bi bi-download"></i>
                        ส่งออก Excel
                    </button>
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

            <!-- สถิติการชำระเงิน -->
            <div class="row mb-4">
                <div class="col-lg-3 col-md-6 mb-3">
                    <div class="card text-white bg-primary h-100">
                        <div class="card-body text-center">
                            <i class="bi bi-cash-coin fs-2"></i>
                            <h4 class="mt-2"><?php echo number_format($stats['total_payments']); ?></h4>
                            <p class="mb-0">รายการชำระ</p>
                        </div>
                    </div>
                </div>
                <div class="col-lg-3 col-md-6 mb-3">
                    <div class="card text-white bg-success h-100">
                        <div class="card-body text-center">
                            <i class="bi bi-currency-dollar fs-2"></i>
                            <h5 class="mt-2"><?php echo formatCurrency($stats['total_amount']); ?></h5>
                            <p class="mb-0">ยอดรวมที่รับ</p>
                        </div>
                    </div>
                </div>
                <div class="col-lg-3 col-md-6 mb-3">
                    <div class="card text-white bg-info h-100">
                        <div class="card-body text-center">
                            <i class="bi bi-graph-up fs-2"></i>
                            <h5 class="mt-2"><?php echo formatCurrency($stats['avg_amount']); ?></h5>
                            <p class="mb-0">ยอดเฉลี่ย/รายการ</p>
                        </div>
                    </div>
                </div>
                <div class="col-lg-3 col-md-6 mb-3">
                    <div class="card text-white bg-warning h-100">
                        <div class="card-body text-center">
                            <i class="bi bi-calendar-month fs-2"></i>
                            <h5 class="mt-2"><?php echo formatCurrency(isset($monthly_data[0]) ? $monthly_data[0]['total_amount'] : 0); ?></h5>
                            <p class="mb-0">รายได้เดือนนี้</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- กราฟวิธีการชำระเงิน -->
            <div class="row mb-4">
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header">
                            <h6 class="mb-0">
                                <i class="bi bi-pie-chart"></i>
                                สัดส่วนวิธีการชำระเงิน
                            </h6>
                        </div>
                        <div class="card-body">
                            <canvas id="paymentMethodChart" width="400" height="200"></canvas>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header">
                            <h6 class="mb-0">
                                <i class="bi bi-bar-chart"></i>
                                รายได้ 6 เดือนย้อนหลัง
                            </h6>
                        </div>
                        <div class="card-body">
                            <canvas id="monthlyChart" width="400" height="200"></canvas>
                        </div>
                    </div>
                </div>
            </div>

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
                        <div class="col-md-3">
                            <label for="search" class="form-label">ค้นหา</label>
                            <input type="text" class="form-control" id="search" name="search" 
                                   value="<?php echo htmlspecialchars($search); ?>" 
                                   placeholder="ชื่อ, ห้อง, เลขที่อ้างอิง">
                        </div>
                        <div class="col-md-2">
                            <label for="method" class="form-label">วิธีการชำระ</label>
                            <select class="form-select" id="method" name="method">
                                <option value="">ทั้งหมด</option>
                                <option value="cash" <?php echo $method_filter == 'cash' ? 'selected' : ''; ?>>เงินสด</option>
                                <option value="bank_transfer" <?php echo $method_filter == 'bank_transfer' ? 'selected' : ''; ?>>โอนเงิน</option>
                                <option value="mobile_banking" <?php echo $method_filter == 'mobile_banking' ? 'selected' : ''; ?>>Mobile Banking</option>
                                <option value="other" <?php echo $method_filter == 'other' ? 'selected' : ''; ?>>อื่นๆ</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label for="date_from" class="form-label">วันที่เริ่มต้น</label>
                            <input type="date" class="form-control" id="date_from" name="date_from" 
                                   value="<?php echo htmlspecialchars($date_from); ?>">
                        </div>
                        <div class="col-md-2">
                            <label for="date_to" class="form-label">วันที่สิ้นสุด</label>
                            <input type="date" class="form-control" id="date_to" name="date_to" 
                                   value="<?php echo htmlspecialchars($date_to); ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">&nbsp;</label>
                            <div class="d-grid gap-2 d-md-flex">
                                <button type="submit" class="btn btn-primary flex-fill">
                                    <i class="bi bi-search"></i>
                                    ค้นหา
                                </button>
                                <a href="payments.php" class="btn btn-outline-secondary">
                                    <i class="bi bi-arrow-clockwise"></i>
                                </a>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <!-- สถิติวิธีการชำระ -->
            <div class="row mb-4">
                <div class="col-lg-3 col-md-6 mb-2">
                    <div class="card border-success">
                        <div class="card-body text-center">
                            <i class="bi bi-cash fs-4 text-success"></i>
                            <h6 class="mt-2">เงินสด</h6>
                            <h5 class="text-success"><?php echo formatCurrency($stats['cash_amount']); ?></h5>
                            <small class="text-muted">
                                <?php echo $stats['total_amount'] > 0 ? number_format(($stats['cash_amount'] / $stats['total_amount']) * 100, 1) : 0; ?>%
                            </small>
                        </div>
                    </div>
                </div>
                <div class="col-lg-3 col-md-6 mb-2">
                    <div class="card border-primary">
                        <div class="card-body text-center">
                            <i class="bi bi-bank fs-4 text-primary"></i>
                            <h6 class="mt-2">โอนเงิน</h6>
                            <h5 class="text-primary"><?php echo formatCurrency($stats['transfer_amount']); ?></h5>
                            <small class="text-muted">
                                <?php echo $stats['total_amount'] > 0 ? number_format(($stats['transfer_amount'] / $stats['total_amount']) * 100, 1) : 0; ?>%
                            </small>
                        </div>
                    </div>
                </div>
                <div class="col-lg-3 col-md-6 mb-2">
                    <div class="card border-info">
                        <div class="card-body text-center">
                            <i class="bi bi-phone fs-4 text-info"></i>
                            <h6 class="mt-2">Mobile Banking</h6>
                            <h5 class="text-info"><?php echo formatCurrency($stats['mobile_amount']); ?></h5>
                            <small class="text-muted">
                                <?php echo $stats['total_amount'] > 0 ? number_format(($stats['mobile_amount'] / $stats['total_amount']) * 100, 1) : 0; ?>%
                            </small>
                        </div>
                    </div>
                </div>
                <div class="col-lg-3 col-md-6 mb-2">
                    <div class="card border-secondary">
                        <div class="card-body text-center">
                            <i class="bi bi-three-dots fs-4 text-secondary"></i>
                            <h6 class="mt-2">อื่นๆ</h6>
                            <h5 class="text-secondary"><?php echo formatCurrency($stats['other_amount']); ?></h5>
                            <small class="text-muted">
                                <?php echo $stats['total_amount'] > 0 ? number_format(($stats['other_amount'] / $stats['total_amount']) * 100, 1) : 0; ?>%
                            </small>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ตารางแสดงข้อมูลการชำระเงิน -->
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">
                        <i class="bi bi-list"></i>
                        รายการการชำระเงิน
                    </h5>
                    <span class="badge bg-primary"><?php echo count($payments); ?> รายการ</span>
                </div>
                <div class="card-body">
                    <?php if (empty($payments)): ?>
                        <div class="text-center py-5">
                            <i class="bi bi-cash-coin display-1 text-muted"></i>
                            <h4 class="text-muted mt-3">ไม่พบข้อมูลการชำระเงิน</h4>
                            <p class="text-muted">
                                <?php if (!empty($search) || !empty($method_filter) || !empty($date_from) || !empty($date_to)): ?>
                                    ไม่มีข้อมูลที่ตรงกับเงื่อนไขการค้นหา
                                <?php else: ?>
                                    ยังไม่มีข้อมูลการชำระเงินในระบบ
                                <?php endif; ?>
                            </p>
                            <?php if (!empty($search) || !empty($method_filter) || !empty($date_from) || !empty($date_to)): ?>
                                <a href="payments.php" class="btn btn-secondary">
                                    <i class="bi bi-arrow-left"></i>
                                    ดูทั้งหมด
                                </a>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead class="table-light">
                                    <tr>
                                        <th style="width: 150px;">วันที่ชำระ</th>
                                        <th style="width: 150px;">เลขที่ใบแจ้งหนี้</th>
                                        <th style="width: 180px;">ผู้เช่า</th>
                                        <th style="width: 80px;">ห้อง</th>
                                        <th style="width: 100px;">เดือน</th>
                                        <th style="width: 120px;">จำนวนเงิน</th>
                                        <th style="width: 120px;">วิธีการชำระ</th>
                                        <th style="width: 150px;">เลขที่อ้างอิง</th>
                                        <th style="width: 200px;">หมายเหตุ</th>
                                        <th style="width: 100px;" class="text-center">จัดการ</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($payments as $payment): ?>
                                        <tr>
                                            <td>
                                                <div class="fw-bold"><?php echo formatDate($payment['payment_date']); ?></div>
                                                <small class="text-muted"><?php echo formatDateTime($payment['created_at']); ?></small>
                                            </td>
                                            <td>
                                                <span class="badge bg-info"><?php echo $payment['invoice_number']; ?></span>
                                                <br><small class="text-muted">
                                                    ยอดบิล: <?php echo formatCurrency($payment['invoice_amount']); ?>
                                                </small>
                                            </td>
                                            <td>
                                                <div class="d-flex align-items-center">
                                                    <div class="avatar bg-primary text-white rounded-circle me-2 flex-shrink-0" style="width: 32px; height: 32px; display: flex; align-items: center; justify-content: center; font-size: 0.8rem;">
                                                        <?php echo mb_substr($payment['first_name'], 0, 1, 'UTF-8'); ?>
                                                    </div>
                                                    <div class="min-w-0">
                                                        <div class="fw-bold text-truncate"><?php echo $payment['first_name'] . ' ' . $payment['last_name']; ?></div>
                                                        <small class="text-muted d-block">
                                                            <i class="bi bi-telephone"></i>
                                                            <?php echo $payment['phone']; ?>
                                                        </small>
                                                    </div>
                                                </div>
                                            </td>
                                            <td class="text-center">
                                                <span class="badge bg-secondary">ห้อง <?php echo $payment['room_number']; ?></span>
                                                <br><small class="text-muted">ชั้น <?php echo $payment['floor_number']; ?></small>
                                            </td>
                                            <td class="text-center">
                                                <div class="fw-bold"><?php echo thaiMonth(substr($payment['invoice_month'], 5, 2)); ?></div>
                                                <small class="text-muted"><?php echo substr($payment['invoice_month'], 0, 4); ?></small>
                                            </td>
                                            <td class="text-end">
                                                <div class="fw-bold text-success"><?php echo formatCurrency($payment['payment_amount']); ?></div>
                                                <?php if ($payment['payment_amount'] != $payment['invoice_amount']): ?>
                                                    <small class="text-warning">
                                                        <i class="bi bi-exclamation-triangle"></i>
                                                        ต่างจากบิล
                                                    </small>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php
                                                $method_class = '';
                                                $method_text = '';
                                                $method_icon = '';
                                                
                                                switch ($payment['payment_method']) {
                                                    case 'cash':
                                                        $method_class = 'bg-success';
                                                        $method_text = 'เงินสด';
                                                        $method_icon = 'bi-cash';
                                                        break;
                                                    case 'bank_transfer':
                                                        $method_class = 'bg-primary';
                                                        $method_text = 'โอนเงิน';
                                                        $method_icon = 'bi-bank';
                                                        break;
                                                    case 'mobile_banking':
                                                        $method_class = 'bg-info';
                                                        $method_text = 'Mobile Banking';
                                                        $method_icon = 'bi-phone';
                                                        break;
                                                    case 'other':
                                                        $method_class = 'bg-secondary';
                                                        $method_text = 'อื่นๆ';
                                                        $method_icon = 'bi-three-dots';
                                                        break;
                                                }
                                                ?>
                                                <span class="badge <?php echo $method_class; ?>">
                                                    <i class="<?php echo $method_icon; ?>"></i>
                                                    <?php echo $method_text; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php if ($payment['payment_reference']): ?>
                                                    <code class="small"><?php echo $payment['payment_reference']; ?></code>
                                                <?php else: ?>
                                                    <span class="text-muted">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($payment['notes']): ?>
                                                    <small><?php echo htmlspecialchars($payment['notes']); ?></small>
                                                <?php else: ?>
                                                    <span class="text-muted">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-center">
                                                <div class="btn-group-vertical btn-group-sm" role="group">
                                                    <a href="view_payment.php?id=<?php echo $payment['payment_id']; ?>" 
                                                       class="btn btn-outline-info btn-sm" 
                                                       data-bs-toggle="tooltip" 
                                                       title="ดูรายละเอียด">
                                                        <i class="bi bi-eye"></i>
                                                        ดูรายละเอียด
                                                    </a>
                                                    <a href="print_receipt.php?id=<?php echo $payment['payment_id']; ?>" 
                                                       class="btn btn-outline-secondary btn-sm" 
                                                       data-bs-toggle="tooltip" 
                                                       title="พิมพ์ใบเสร็จ"
                                                       target="_blank">
                                                        <i class="bi bi-printer"></i>
                                                        พิมพ์ใบเสร็จ
                                                    </a>
                                                    <a href="payments.php?delete=<?php echo $payment['payment_id']; ?>" 
                                                       class="btn btn-outline-danger btn-sm" 
                                                       data-bs-toggle="tooltip" 
                                                       title="ลบ"
                                                       onclick="return confirmDelete('คุณต้องการลบประวัติการชำระเงินจำนวน <?php echo formatCurrency($payment['payment_amount']); ?> หรือไม่?\n\nการลบจะทำให้:\n- ประวัติการชำระถูกลบ\n- ใบแจ้งหนี้กลับเป็นสถานะรอชำระ\n- ไม่สามารถยกเลิกการดำเนินการได้')">
                                                        <i class="bi bi-trash"></i>
                                                        ลบ
                                                    </a>
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
                                    <h6 class="text-muted mb-1">วิธีการยอดนิยม</h6>
                                    <h4 class="text-warning">
                                        <?php 
                                        $methods = ['cash' => 'เงินสด', 'bank_transfer' => 'โอนเงิน', 'mobile_banking' => 'Mobile Banking', 'other' => 'อื่นๆ'];
                                        $popular_method = '';
                                        $max_amount = 0;
                                        foreach (['cash_amount', 'transfer_amount', 'mobile_amount', 'other_amount'] as $i => $key) {
                                            if ($stats[$key] > $max_amount) {
                                                $max_amount = $stats[$key];
                                                $popular_method = array_values($methods)[$i];
                                            }
                                        }
                                        echo $popular_method ?: 'ไม่มีข้อมูล';
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

<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // กราฟวงกลมแสดงวิธีการชำระเงิน
    const methodCtx = document.getElementById('paymentMethodChart').getContext('2d');
    new Chart(methodCtx, {
        type: 'doughnut',
        data: {
            labels: ['เงินสด', 'โอนเงิน', 'Mobile Banking', 'อื่นๆ'],
            datasets: [{
                data: [
                    <?php echo $stats['cash_amount']; ?>,
                    <?php echo $stats['transfer_amount']; ?>,
                    <?php echo $stats['mobile_amount']; ?>,
                    <?php echo $stats['other_amount']; ?>
                ],
                backgroundColor: ['#28a745', '#007bff', '#17a2b8', '#6c757d'],
                borderWidth: 2,
                borderColor: '#fff'
            }]
        },
        options: {
            responsive: true,
            plugins: {
                legend: {
                    position: 'bottom'
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            const total = context.dataset.data.reduce((a, b) => a + b, 0);
                            const percentage = total > 0 ? ((context.parsed * 100) / total).toFixed(1) : 0;
                            const amount = new Intl.NumberFormat('th-TH').format(context.parsed);
                            return context.label + ': ' + amount + ' บาท (' + percentage + '%)';
                        }
                    }
                }
            }
        }
    });

    // กราฟรายได้รายเดือน
    const monthlyCtx = document.getElementById('monthlyChart').getContext('2d');
    new Chart(monthlyCtx, {
        type: 'bar',
        data: {
            labels: [
                <?php 
                $months = array_reverse($monthly_data);
                foreach ($months as $month) {
                    $monthNames = [
                        '01' => 'ม.ค.', '02' => 'ก.พ.', '03' => 'มี.ค.', '04' => 'เม.ย.',
                        '05' => 'พ.ค.', '06' => 'มิ.ย.', '07' => 'ก.ค.', '08' => 'ส.ค.',
                        '09' => 'ก.ย.', '10' => 'ต.ค.', '11' => 'พ.ย.', '12' => 'ธ.ค.'
                    ];
                    $parts = explode('-', $month['month']);
                    echo "'" . $monthNames[$parts[1]] . " " . $parts[0] . "',";
                }
                ?>
            ],
            datasets: [{
                label: 'รายได้ (บาท)',
                data: [
                    <?php 
                    foreach ($months as $month) {
                        echo $month['total_amount'] . ",";
                    }
                    ?>
                ],
                backgroundColor: '#007bff',
                borderColor: '#0056b3',
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            plugins: {
                legend: {
                    display: false
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        callback: function(value) {
                            return new Intl.NumberFormat('th-TH').format(value) + ' บาท';
                        }
                    }
                }
            },
            plugins: {
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            return 'รายได้: ' + new Intl.NumberFormat('th-TH').format(context.parsed.y) + ' บาท';
                        }
                    }
                }
            }
        }
    });
    
    // ฟังก์ชันส่งออก Excel
    window.exportToExcel = function() {
        const params = new URLSearchParams(window.location.search);
        params.set('export', 'excel');
        window.open('export_payments.php?' + params.toString(), '_blank');
    };
    
    // Enhanced confirm delete
    window.confirmDelete = function(message) {
        return confirm(message);
    };
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

.card:hover {
    transform: translateY(-2px);
    box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15) !important;
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

code {
    font-size: 0.8rem;
    background-color: #f8f9fa;
    padding: 0.1rem 0.3rem;
    border-radius: 0.25rem;
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
    
    .card-header {
        background-color: #f8f9fa !important;
        -webkit-print-color-adjust: exact;
    }
    
    .table {
        font-size: 12px;
    }
    
    .badge {
        border: 1px solid #000;
        -webkit-print-color-adjust: exact;
    }
    
    @page {
        margin: 1cm;
        size: A4 landscape;
    }
}
</style>

<?php include 'includes/footer.php'; ?>