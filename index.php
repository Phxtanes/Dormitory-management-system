<?php
$page_title = "หน้าแรก";
require_once 'includes/header.php';

// ตรวจสอบการล็อกอิน (สามารถเพิ่ม authentication logic ได้)
// if (!isset($_SESSION['user_id'])) {
//     header('Location: login.php');
//     exit;
// }

// ดึงข้อมูลสถิติต่างๆ
try {
    // จำนวนห้องพักทั้งหมด
    $stmt = $pdo->query("SELECT COUNT(*) as total_rooms FROM rooms");
    $total_rooms = $stmt->fetch()['total_rooms'];
    
    // จำนวนห้องที่ว่าง
    $stmt = $pdo->query("SELECT COUNT(*) as available_rooms FROM rooms WHERE room_status = 'available'");
    $available_rooms = $stmt->fetch()['available_rooms'];
    
    // จำนวนห้องที่มีผู้เช่า
    $occupied_rooms = $total_rooms - $available_rooms;
    
    // จำนวนผู้เช่าทั้งหมด
    $stmt = $pdo->query("SELECT COUNT(*) as total_tenants FROM tenants WHERE tenant_status = 'active'");
    $total_tenants = $stmt->fetch()['total_tenants'];
    
    // จำนวนสัญญาที่ใช้งานอยู่
    $stmt = $pdo->query("SELECT COUNT(*) as active_contracts FROM contracts WHERE contract_status = 'active'");
    $active_contracts = $stmt->fetch()['active_contracts'];
    
    // รายได้เดือนนี้
    $current_month = date('Y-m');
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(payment_amount), 0) as monthly_income FROM payments WHERE DATE_FORMAT(payment_date, '%Y-%m') = ?");
    $stmt->execute([$current_month]);
    $monthly_income = $stmt->fetch()['monthly_income'];
    
    // ใบแจ้งหนี้ค้างชำระ
    $stmt = $pdo->query("SELECT COUNT(*) as overdue_invoices FROM invoices WHERE invoice_status = 'overdue' OR (invoice_status = 'pending' AND due_date < CURDATE())");
    $overdue_invoices = $stmt->fetch()['overdue_invoices'];
    
    // สัญญาที่จะหมดอายุใน 30 วัน
    $stmt = $pdo->query("SELECT COUNT(*) as expiring_contracts FROM contracts WHERE contract_status = 'active' AND contract_end <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)");
    $expiring_contracts = $stmt->fetch()['expiring_contracts'];
    
    // รายการใบแจ้งหนี้ล่าสุด
    $stmt = $pdo->prepare("
        SELECT i.*, r.room_number, t.first_name, t.last_name 
        FROM invoices i 
        JOIN contracts c ON i.contract_id = c.contract_id 
        JOIN rooms r ON c.room_id = r.room_id 
        JOIN tenants t ON c.tenant_id = t.tenant_id 
        ORDER BY i.created_at DESC 
        LIMIT 5
    ");
    $stmt->execute();
    $recent_invoices = $stmt->fetchAll();
    
    // รายการผู้เช่าใหม่ล่าสุด
    $stmt = $pdo->prepare("
        SELECT t.*, r.room_number, c.contract_start 
        FROM tenants t 
        JOIN contracts c ON t.tenant_id = c.tenant_id 
        JOIN rooms r ON c.room_id = r.room_id 
        WHERE c.contract_status = 'active' 
        ORDER BY c.created_at DESC 
        LIMIT 5
    ");
    $stmt->execute();
    $recent_tenants = $stmt->fetchAll();
    
} catch(PDOException $e) {
    echo "Error: " . $e->getMessage();
}
?>

<?php include 'includes/navbar.php'; ?>

<div class="container-fluid mt-4">
    <div class="row">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2 class="mb-0">
                    <i class="bi bi-speedometer2"></i>
                    แดชบอร์ด
                </h2>
                <div class="text-muted">
                    <i class="bi bi-clock"></i>
                    <span id="current-datetime"><?php echo formatDateTime(date('Y-m-d H:i:s')); ?></span>
                </div>
            </div>
        </div>
    </div>

    <!-- สถิติรวม -->
    <div class="row mb-4">
        <div class="col-xl-3 col-md-6 mb-4">
            <div class="card border-left-primary shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">
                                ห้องพักทั้งหมด
                            </div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                <?php echo $total_rooms; ?> ห้อง
                            </div>
                        </div>
                        <div class="col-auto">
                            <i class="bi bi-building fs-2 text-primary"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6 mb-4">
            <div class="card border-left-success shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-success text-uppercase mb-1">
                                ห้องว่าง
                            </div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                <?php echo $available_rooms; ?> ห้อง
                            </div>
                        </div>
                        <div class="col-auto">
                            <i class="bi bi-door-open fs-2 text-success"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6 mb-4">
            <div class="card border-left-info shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-info text-uppercase mb-1">
                                ผู้เช่าทั้งหมด
                            </div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                <?php echo $total_tenants; ?> คน
                            </div>
                        </div>
                        <div class="col-auto">
                            <i class="bi bi-people fs-2 text-info"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6 mb-4">
            <div class="card border-left-warning shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">
                                รายได้เดือนนี้
                            </div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                <?php echo formatCurrency($monthly_income); ?>
                            </div>
                        </div>
                        <div class="col-auto">
                            <i class="bi bi-currency-dollar fs-2 text-warning"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- แจ้งเตือนและสถานะ -->
    <div class="row mb-4">
        <div class="col-md-4 mb-3">
            <div class="card text-white bg-danger">
                <div class="card-header d-flex align-items-center">
                    <i class="bi bi-exclamation-triangle me-2" style="color:#000;"></i>
                    <strong style="color:#000;">ใบแจ้งหนี้ค้างชำระ</strong>
                </div>
                <div class="card-body">
                    <h4 class="card-title"><?php echo $overdue_invoices; ?> รายการ</h4>
                    <p class="card-text">ต้องติดตามการชำระเงิน</p>
                    <a href="invoices.php?status=overdue" class="btn btn-outline-light">
                        ดูรายละเอียด
                    </a>
                </div>
            </div>
        </div>

        <div class="col-md-4 mb-3">
            <div class="card text-white bg-warning">
                <div class="card-header d-flex align-items-center">
                    <i class="bi bi-calendar-x me-2" style="color:#000;"></i>
                    <strong style="color:#000;">สัญญาใกล้หมดอายุ</strong>
                </div>
                <div class="card-body">
                    <h4 class="card-title"><?php echo $expiring_contracts; ?> สัญญา</h4>
                    <p class="card-text">ใน 30 วันข้างหน้า</p>
                    <a href="contracts.php?filter=expiring" class="btn btn-outline-light">
                        ดูรายละเอียด
                    </a>
                </div>
            </div>
        </div>

        <div class="col-md-4 mb-3">
            <div class="card text-white bg-success">
                <div class="card-header d-flex align-items-center">
                    <i class="bi bi-graph-up me-2" style="color:#000;"></i>
                    <strong style="color:#000;">อัตราการเข้าพัก</strong>
                </div>
                <div class="card-body">
                    <h4 class="card-title">
                        <?php echo $total_rooms > 0 ? round(($occupied_rooms / $total_rooms) * 100, 1) : 0; ?>%
                    </h4>
                    <p class="card-text"><?php echo $occupied_rooms; ?>/<?php echo $total_rooms; ?> ห้อง</p>
                    <a href="reports_occupancy.php" class="btn btn-outline-light">
                        ดูรายงาน
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- รายการล่าสุด -->
    <div class="row">
        <div class="col-lg-6 mb-4">
            <div class="card shadow">
                <div class="card-header py-3 d-flex justify-content-between align-items-center">
                    <h6 class="m-0 font-weight-bold text-primary">
                        <i class="bi bi-receipt"></i>
                        ใบแจ้งหนี้ล่าสุด
                    </h6>
                    <a href="invoices.php" class="btn btn-sm btn-outline-primary">ดูทั้งหมด</a>
                </div>
                <div class="card-body">
                    <?php if (empty($recent_invoices)): ?>
                        <div class="text-center text-muted py-3">
                            <i class="bi bi-inbox fs-1"></i>
                            <p class="mt-2">ไม่มีใบแจ้งหนี้</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>ห้อง</th>
                                        <th>ผู้เช่า</th>
                                        <th>จำนวนเงิน</th>
                                        <th>สถานะ</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recent_invoices as $invoice): ?>
                                        <tr>
                                            <td><?php echo $invoice['room_number']; ?></td>
                                            <td><?php echo $invoice['first_name'] . ' ' . $invoice['last_name']; ?></td>
                                            <td><?php echo formatCurrency($invoice['total_amount']); ?></td>
                                            <td>
                                                <?php
                                                $status_class = '';
                                                $status_text = '';
                                                switch ($invoice['invoice_status']) {
                                                    case 'paid':
                                                        $status_class = 'bg-success';
                                                        $status_text = 'ชำระแล้ว';
                                                        break;
                                                    case 'pending':
                                                        $status_class = 'bg-warning';
                                                        $status_text = 'รอชำระ';
                                                        break;
                                                    case 'overdue':
                                                        $status_class = 'bg-danger';
                                                        $status_text = 'เกินกำหนด';
                                                        break;
                                                    default:
                                                        $status_class = 'bg-secondary';
                                                        $status_text = $invoice['invoice_status'];
                                                }
                                                ?>
                                                <span class="badge <?php echo $status_class; ?>">
                                                    <?php echo $status_text; ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-lg-6 mb-4">
            <div class="card shadow">
                <div class="card-header py-3 d-flex justify-content-between align-items-center">
                    <h6 class="m-0 font-weight-bold text-primary">
                        <i class="bi bi-people"></i>
                        ผู้เช่าใหม่ล่าสุด
                    </h6>
                    <a href="tenants.php" class="btn btn-sm btn-outline-primary">ดูทั้งหมด</a>
                </div>
                <div class="card-body">
                    <?php if (empty($recent_tenants)): ?>
                        <div class="text-center text-muted py-3">
                            <i class="bi bi-person-plus fs-1"></i>
                            <p class="mt-2">ไม่มีผู้เช่าใหม่</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>ชื่อ-นามสกุล</th>
                                        <th>ห้อง</th>
                                        <th>วันที่เข้าพัก</th>
                                        <th>โทรศัพท์</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recent_tenants as $tenant): ?>
                                        <tr>
                                            <td><?php echo $tenant['first_name'] . ' ' . $tenant['last_name']; ?></td>
                                            <td><?php echo $tenant['room_number']; ?></td>
                                            <td><?php echo formatDate($tenant['contract_start']); ?></td>
                                            <td><?php echo $tenant['phone']; ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- เมนูด่วน -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="card shadow">
                <div class="card-header py-3">
                    <h6 class="m-0 font-weight-bold text-primary">
                        <i class="bi bi-lightning"></i>
                        เมนูด่วน
                    </h6>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-3 mb-3">
                            <a href="add_tenant.php" class="btn btn-outline-primary w-100 py-3">
                                <i class="bi bi-person-plus fs-4 d-block mb-2"></i>
                                เพิ่มผู้เช่าใหม่
                            </a>
                        </div>
                        <div class="col-md-3 mb-3">
                            <a href="utility_readings.php" class="btn btn-outline-success w-100 py-3">
                                <i class="bi bi-speedometer fs-4 d-block mb-2"></i>
                                บันทึกมิเตอร์
                            </a>
                        </div>
                        <div class="col-md-3 mb-3">
                            <a href="generate_bills.php" class="btn btn-outline-warning w-100 py-3">
                                <i class="bi bi-receipt-cutoff fs-4 d-block mb-2"></i>
                                สร้างใบแจ้งหนี้
                            </a>
                        </div>
                        <div class="col-md-3 mb-3">
                            <a href="payments.php" class="btn btn-outline-info w-100 py-3">
                                <i class="bi bi-cash-coin fs-4 d-block mb-2"></i>
                                บันทึกการชำระเงิน
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.border-left-primary {
    border-left: 0.25rem solid #4e73df !important;
}

.border-left-success {
    border-left: 0.25rem solid #1cc88a !important;
}

.border-left-info {
    border-left: 0.25rem solid #36b9cc !important;
}

.border-left-warning {
    border-left: 0.25rem solid #f6c23e !important;
}

.card {
    transition: all 0.3s ease-in-out;
}

.card:hover {
    transform: translateY(-2px);
    box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15) !important;
}

.btn {
    transition: all 0.2s ease-in-out;
}
</style>

<?php include 'includes/footer.php'; ?>
