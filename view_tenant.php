<?php
$page_title = "รายละเอียดผู้เช่า";
require_once 'includes/header.php';

// ตรวจสอบ ID ผู้เช่า
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header('Location: tenants.php');
    exit;
}

$tenant_id = intval($_GET['id']);

try {
    // ดึงข้อมูลผู้เช่า
    $stmt = $pdo->prepare("SELECT * FROM tenants WHERE tenant_id = ?");
    $stmt->execute([$tenant_id]);
    $tenant = $stmt->fetch();
    
    if (!$tenant) {
        header('Location: tenants.php');
        exit;
    }
    
    // ดึงข้อมูลสัญญาทั้งหมดของผู้เช่า
    $contracts_sql = "SELECT c.*, r.room_number, r.room_type, r.floor_number,
                      DATEDIFF(c.contract_end, CURDATE()) as days_until_end,
                      DATEDIFF(CURDATE(), c.contract_start) as days_since_start
                      FROM contracts c
                      JOIN rooms r ON c.room_id = r.room_id
                      WHERE c.tenant_id = ?
                      ORDER BY c.contract_start DESC";
    
    $stmt = $pdo->prepare($contracts_sql);
    $stmt->execute([$tenant_id]);
    $contracts = $stmt->fetchAll();
    
    // ดึงข้อมูลใบแจ้งหนี้ของผู้เช่า
    $invoices_sql = "SELECT i.*, r.room_number,
                     DATEDIFF(CURDATE(), i.due_date) as days_overdue,
                     (SELECT SUM(p.payment_amount) FROM payments p WHERE p.invoice_id = i.invoice_id) as total_paid
                     FROM invoices i
                     JOIN contracts c ON i.contract_id = c.contract_id
                     JOIN rooms r ON c.room_id = r.room_id
                     WHERE c.tenant_id = ?
                     ORDER BY i.invoice_month DESC
                     LIMIT 10";
    
    $stmt = $pdo->prepare($invoices_sql);
    $stmt->execute([$tenant_id]);
    $recent_invoices = $stmt->fetchAll();
    
    // สถิติการชำระเงิน
    $payment_stats_sql = "SELECT 
                         COUNT(*) as total_invoices,
                         SUM(CASE WHEN i.invoice_status = 'paid' THEN 1 ELSE 0 END) as paid_invoices,
                         SUM(CASE WHEN i.invoice_status = 'pending' THEN 1 ELSE 0 END) as pending_invoices,
                         SUM(CASE WHEN i.invoice_status = 'overdue' OR (i.invoice_status = 'pending' AND i.due_date < CURDATE()) THEN 1 ELSE 0 END) as overdue_invoices,
                         SUM(CASE WHEN i.invoice_status = 'paid' THEN i.total_amount ELSE 0 END) as total_paid_amount,
                         SUM(CASE WHEN i.invoice_status IN ('pending', 'overdue') OR (i.invoice_status = 'pending' AND i.due_date < CURDATE()) THEN i.total_amount ELSE 0 END) as outstanding_amount
                         FROM invoices i
                         JOIN contracts c ON i.contract_id = c.contract_id
                         WHERE c.tenant_id = ?";
    
    $stmt = $pdo->prepare($payment_stats_sql);
    $stmt->execute([$tenant_id]);
    $payment_stats = $stmt->fetch();
    
    // หาสัญญาปัจจุบัน
    $current_contract = null;
    foreach ($contracts as $contract) {
        if ($contract['contract_status'] == 'active') {
            $current_contract = $contract;
            break;
        }
    }
    
} catch(PDOException $e) {
    $error_message = "เกิดข้อผิดพลาดในการดึงข้อมูล: " . $e->getMessage();
}
?>

<?php include 'includes/navbar.php'; ?>

<div class="container-fluid mt-4">
    <div class="row">
        <div class="col-12">
            <!-- หัวข้อหน้า -->
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2 class="mb-0">
                    <i class="bi bi-person-circle"></i>
                    รายละเอียดผู้เช่า
                </h2>
                <div class="btn-group">
                    <a href="edit_tenant.php?id=<?php echo $tenant['tenant_id']; ?>" class="btn btn-primary">
                        <i class="bi bi-pencil"></i>
                        แก้ไขข้อมูล
                    </a>
                    <button type="button" class="btn btn-outline-secondary dropdown-toggle dropdown-toggle-split" data-bs-toggle="dropdown">
                        <span class="visually-hidden">Toggle Dropdown</span>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><a class="dropdown-item" href="invoices.php?tenant_id=<?php echo $tenant['tenant_id']; ?>">
                            <i class="bi bi-receipt"></i> ดูใบแจ้งหนี้
                        </a></li>
                        <li><a class="dropdown-item" href="payments.php?tenant_id=<?php echo $tenant['tenant_id']; ?>">
                            <i class="bi bi-cash-coin"></i> ประวัติการชำระ
                        </a></li>
                        <?php if (!$current_contract): ?>
                            <li><a class="dropdown-item" href="add_contract.php?tenant_id=<?php echo $tenant['tenant_id']; ?>">
                                <i class="bi bi-file-earmark-plus"></i> สร้างสัญญาใหม่
                            </a></li>
                        <?php endif; ?>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="tenants.php">
                            <i class="bi bi-arrow-left"></i> กลับไปรายการผู้เช่า
                        </a></li>
                    </ul>
                </div>
            </div>

            <!-- แสดงข้อความแจ้งเตือน -->
            <?php if (isset($error_message)): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="bi bi-exclamation-triangle"></i>
                    <?php echo $error_message; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <div class="row">
                <!-- ข้อมูลผู้เช่า -->
                <div class="col-lg-4 mb-4">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">
                                <i class="bi bi-person-fill"></i>
                                ข้อมูลส่วนตัว
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="text-center mb-4">
                                <div class="avatar bg-primary text-white rounded-circle mx-auto mb-3" style="width: 80px; height: 80px; display: flex; align-items: center; justify-content: center; font-size: 2rem; font-weight: 600;">
                                    <?php echo mb_substr($tenant['first_name'], 0, 1, 'UTF-8'); ?>
                                </div>
                                <h4 class="mb-1"><?php echo $tenant['first_name'] . ' ' . $tenant['last_name']; ?></h4>
                                <?php
                                $status_class = $tenant['tenant_status'] == 'active' ? 'bg-success' : 'bg-secondary';
                                $status_text = $tenant['tenant_status'] == 'active' ? 'ใช้งานอยู่' : 'ไม่ใช้งาน';
                                ?>
                                <span class="badge <?php echo $status_class; ?> fs-6">
                                    <i class="bi bi-<?php echo $tenant['tenant_status'] == 'active' ? 'check-circle' : 'x-circle'; ?>"></i>
                                    <?php echo $status_text; ?>
                                </span>
                            </div>

                            <div class="row g-3">
                                <div class="col-12">
                                    <div class="border rounded p-3">
                                        <small class="text-muted d-block">หมายเลขโทรศัพท์</small>
                                        <div class="fw-bold">
                                            <a href="tel:<?php echo $tenant['phone']; ?>" class="text-decoration-none">
                                                <i class="bi bi-telephone me-2"></i>
                                                <?php echo $tenant['phone']; ?>
                                            </a>
                                        </div>
                                    </div>
                                </div>
                                
                                <?php if ($tenant['email']): ?>
                                <div class="col-12">
                                    <div class="border rounded p-3">
                                        <small class="text-muted d-block">อีเมล</small>
                                        <div class="fw-bold">
                                            <a href="mailto:<?php echo $tenant['email']; ?>" class="text-decoration-none">
                                                <i class="bi bi-envelope me-2"></i>
                                                <?php echo $tenant['email']; ?>
                                            </a>
                                        </div>
                                    </div>
                                </div>
                                <?php endif; ?>
                                
                                <div class="col-12">
                                    <div class="border rounded p-3">
                                        <small class="text-muted d-block">เลขบัตรประชาชน</small>
                                        <div class="fw-bold font-monospace">
                                            <i class="bi bi-card-text me-2"></i>
                                            <?php echo $tenant['id_card']; ?>
                                        </div>
                                    </div>
                                </div>
                                
                                <?php if ($tenant['address']): ?>
                                <div class="col-12">
                                    <div class="border rounded p-3">
                                        <small class="text-muted d-block">ที่อยู่</small>
                                        <div class="text-break">
                                            <i class="bi bi-geo-alt me-2"></i>
                                            <?php echo nl2br(htmlspecialchars($tenant['address'])); ?>
                                        </div>
                                    </div>
                                </div>
                                <?php endif; ?>
                                
                                <div class="col-12">
                                    <div class="border rounded p-3">
                                        <small class="text-muted d-block">วันที่เข้าร่วมระบบ</small>
                                        <div class="fw-bold">
                                            <i class="bi bi-calendar-plus me-2"></i>
                                            <?php echo formatDateTime($tenant['created_at']); ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ข้อมูลผู้ติดต่อฉุกเฉิน -->
                    <?php if ($tenant['emergency_contact'] || $tenant['emergency_phone']): ?>
                        <div class="card mt-4">
                            <div class="card-header">
                                <h6 class="mb-0">
                                    <i class="bi bi-telephone-plus"></i>
                                    ข้อมูลผู้ติดต่อฉุกเฉิน
                                </h6>
                            </div>
                            <div class="card-body">
                                <?php if ($tenant['emergency_contact']): ?>
                                    <div class="mb-3">
                                        <small class="text-muted d-block">ชื่อผู้ติดต่อ</small>
                                        <div class="fw-bold"><?php echo $tenant['emergency_contact']; ?></div>
                                    </div>
                                <?php endif; ?>
                                
                                <?php if ($tenant['emergency_phone']): ?>
                                    <div>
                                        <small class="text-muted d-block">หมายเลขโทรศัพท์</small>
                                        <div class="fw-bold">
                                            <a href="tel:<?php echo $tenant['emergency_phone']; ?>" class="text-decoration-none">
                                                <i class="bi bi-telephone me-2"></i>
                                                <?php echo $tenant['emergency_phone']; ?>
                                            </a>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- ข้อมูลสัญญาและการเงิน -->
                <div class="col-lg-8">
                    <!-- สถานะปัจจุบัน -->
                    <div class="row mb-4">
                        <div class="col-md-6">
                            <div class="card h-100">
                                <div class="card-header bg-info text-white">
                                    <h6 class="mb-0">
                                        <i class="bi bi-house-fill"></i>
                                        สถานะการเช่าปัจจุบัน
                                    </h6>
                                </div>
                                <div class="card-body">
                                    <?php if ($current_contract): ?>
                                        <div class="text-center">
                                            <div class="mb-2">
                                                <span class="badge bg-primary fs-5">ห้อง <?php echo $current_contract['room_number']; ?></span>
                                            </div>
                                            <div class="mb-2">
                                                <small class="text-muted">ประเภท:</small>
                                                <div class="fw-bold">
                                                    <?php
                                                    switch ($current_contract['room_type']) {
                                                        case 'single': echo 'ห้องเดี่ยว'; break;
                                                        case 'double': echo 'ห้องคู่'; break;
                                                        case 'triple': echo 'ห้องสาม'; break;
                                                    }
                                                    ?>
                                                    (ชั้น <?php echo $current_contract['floor_number']; ?>)
                                                </div>
                                            </div>
                                            <div class="mb-2">
                                                <small class="text-muted">ค่าเช่า:</small>
                                                <div class="fw-bold text-success"><?php echo formatCurrency($current_contract['monthly_rent']); ?></div>
                                            </div>
                                            <div class="mb-2">
                                                <small class="text-muted">สิ้นสุดสัญญา:</small>
                                                <div class="fw-bold <?php echo $current_contract['days_until_end'] <= 30 ? 'text-warning' : ''; ?>">
                                                    <?php echo formatDate($current_contract['contract_end']); ?>
                                                    <?php if ($current_contract['days_until_end'] > 0): ?>
                                                        <br><small>(อีก <?php echo $current_contract['days_until_end']; ?> วัน)</small>
                                                    <?php elseif ($current_contract['days_until_end'] < 0): ?>
                                                        <br><small class="text-danger">(เกินกำหนดแล้ว <?php echo abs($current_contract['days_until_end']); ?> วัน)</small>
                                                    <?php else: ?>
                                                        <br><small class="text-warning">(หมดอายุวันนี้)</small>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            <div class="mt-3">
                                                <a href="view_contract.php?id=<?php echo $current_contract['contract_id']; ?>" class="btn btn-sm btn-outline-primary">
                                                    <i class="bi bi-eye"></i>
                                                    ดูรายละเอียดสัญญา
                                                </a>
                                            </div>
                                        </div>
                                    <?php else: ?>
                                        <div class="text-center text-muted">
                                            <i class="bi bi-house-x fs-1"></i>
                                            <p class="mt-2 mb-3">ไม่มีสัญญาใช้งานอยู่</p>
                                            <a href="add_contract.php?tenant_id=<?php echo $tenant['tenant_id']; ?>" class="btn btn-primary">
                                                <i class="bi bi-file-earmark-plus"></i>
                                                สร้างสัญญาใหม่
                                            </a>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <div class="card h-100">
                                <div class="card-header bg-success text-white">
                                    <h6 class="mb-0">
                                        <i class="bi bi-cash-coin"></i>
                                        สถิติการชำระเงิน
                                    </h6>
                                </div>
                                <div class="card-body">
                                    <div class="row text-center">
                                        <div class="col-6 mb-2">
                                            <h4 class="text-primary"><?php echo number_format($payment_stats['total_invoices']); ?></h4>
                                            <small class="text-muted">ใบแจ้งหนี้ทั้งหมด</small>
                                        </div>
                                        <div class="col-6 mb-2">
                                            <h4 class="text-success"><?php echo number_format($payment_stats['paid_invoices']); ?></h4>
                                            <small class="text-muted">ชำระแล้ว</small>
                                        </div>
                                        <div class="col-6 mb-2">
                                            <h4 class="text-warning"><?php echo number_format($payment_stats['pending_invoices']); ?></h4>
                                            <small class="text-muted">รอชำระ</small>
                                        </div>
                                        <div class="col-6 mb-2">
                                            <h4 class="text-danger"><?php echo number_format($payment_stats['overdue_invoices']); ?></h4>
                                            <small class="text-muted">ค้างชำระ</small>
                                        </div>
                                    </div>
                                    <hr>
                                    <div class="row text-center">
                                        <div class="col-12 mb-2">
                                            <h5 class="text-success"><?php echo formatCurrency($payment_stats['total_paid_amount']); ?></h5>
                                            <small class="text-muted">ยอดที่ชำระแล้ว</small>
                                        </div>
                                        <?php if ($payment_stats['outstanding_amount'] > 0): ?>
                                            <div class="col-12">
                                                <h5 class="text-danger"><?php echo formatCurrency($payment_stats['outstanding_amount']); ?></h5>
                                                <small class="text-muted">ยอดค้างชำระ</small>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ใบแจ้งหนี้ล่าสุด -->
                    <div class="card mb-4">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5 class="mb-0">
                                <i class="bi bi-receipt"></i>
                                ใบแจ้งหนี้ล่าสุด
                            </h5>
                            <a href="invoices.php?tenant_id=<?php echo $tenant['tenant_id']; ?>" class="btn btn-sm btn-outline-primary">
                                ดูทั้งหมด
                            </a>
                        </div>
                        <div class="card-body">
                            <?php if (empty($recent_invoices)): ?>
                                <div class="text-center text-muted py-3">
                                    <i class="bi bi-receipt fs-1"></i>
                                    <p class="mt-2">ยังไม่มีใบแจ้งหนี้</p>
                                </div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-hover">
                                        <thead>
                                            <tr>
                                                <th>เดือน</th>
                                                <th>ห้อง</th>
                                                <th class="text-end">จำนวนเงิน</th>
                                                <th class="text-center">กำหนดชำระ</th>
                                                <th class="text-center">สถานะ</th>
                                                <th class="text-center">จัดการ</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($recent_invoices as $invoice): ?>
                                                <tr>
                                                    <td>
                                                        <strong>
                                                            <?php echo thaiMonth(substr($invoice['invoice_month'], 5, 2)) . ' ' . substr($invoice['invoice_month'], 0, 4); ?>
                                                        </strong>
                                                    </td>
                                                    <td>
                                                        <span class="badge bg-info">ห้อง <?php echo $invoice['room_number']; ?></span>
                                                    </td>
                                                    <td class="text-end">
                                                        <strong><?php echo formatCurrency($invoice['total_amount']); ?></strong>
                                                    </td>
                                                    <td class="text-center">
                                                        <?php echo formatDate($invoice['due_date']); ?>
                                                        <?php if ($invoice['invoice_status'] == 'pending' && $invoice['days_overdue'] > 0): ?>
                                                            <br><small class="text-danger">เกิน <?php echo $invoice['days_overdue']; ?> วัน</small>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="text-center">
                                                        <?php
                                                        $status_class = '';
                                                        $status_text = '';
                                                        $is_overdue = ($invoice['invoice_status'] == 'overdue' || 
                                                                      ($invoice['invoice_status'] == 'pending' && $invoice['days_overdue'] > 0));
                                                        
                                                        if ($invoice['invoice_status'] == 'paid') {
                                                            $status_class = 'bg-success';
                                                            $status_text = 'ชำระแล้ว';
                                                        } elseif ($is_overdue) {
                                                            $status_class = 'bg-danger';
                                                            $status_text = 'ค้างชำระ';
                                                        } else {
                                                            $status_class = 'bg-warning';
                                                            $status_text = 'รอชำระ';
                                                        }
                                                        ?>
                                                        <span class="badge <?php echo $status_class; ?>">
                                                            <?php echo $status_text; ?>
                                                        </span>
                                                    </td>
                                                    <td class="text-center">
                                                        <a href="view_invoice.php?id=<?php echo $invoice['invoice_id']; ?>" 
                                                           class="btn btn-sm btn-outline-info" 
                                                           data-bs-toggle="tooltip" 
                                                           title="ดูรายละเอียด">
                                                            <i class="bi bi-eye"></i>
                                                        </a>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- ประวัติสัญญา -->
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">
                                <i class="bi bi-file-earmark-text"></i>
                                ประวัติสัญญาเช่า
                            </h5>
                        </div>
                        <div class="card-body">
                            <?php if (empty($contracts)): ?>
                                <div class="text-center text-muted py-3">
                                    <i class="bi bi-file-earmark-plus fs-1"></i>
                                    <p class="mt-2">ยังไม่มีสัญญาเช่า</p>
                                    <a href="add_contract.php?tenant_id=<?php echo $tenant['tenant_id']; ?>" class="btn btn-primary">
                                        <i class="bi bi-file-earmark-plus"></i>
                                        สร้างสัญญาแรก
                                    </a>
                                </div>
                            <?php else: ?>
                                <div class="timeline">
                                    <?php foreach ($contracts as $index => $contract): ?>
                                        <div class="timeline-item">
                                            <div class="timeline-marker">
                                                <?php if ($contract['contract_status'] == 'active'): ?>
                                                    <i class="bi bi-circle-fill text-success"></i>
                                                <?php elseif ($contract['contract_status'] == 'expired'): ?>
                                                    <i class="bi bi-circle-fill text-secondary"></i>
                                                <?php else: ?>
                                                    <i class="bi bi-circle-fill text-danger"></i>
                                                <?php endif; ?>
                                            </div>
                                            <div class="timeline-content">
                                                <div class="card">
                                                    <div class="card-body">
                                                        <div class="row">
                                                            <div class="col-md-8">
                                                                <h6 class="mb-2">
                                                                    ห้อง <?php echo $contract['room_number']; ?>
                                                                    <span class="badge bg-<?php 
                                                                        echo $contract['contract_status'] == 'active' ? 'success' : 
                                                                            ($contract['contract_status'] == 'expired' ? 'secondary' : 'danger'); 
                                                                    ?>">
                                                                        <?php 
                                                                        echo $contract['contract_status'] == 'active' ? 'ใช้งานอยู่' : 
                                                                            ($contract['contract_status'] == 'expired' ? 'หมดอายุ' : 'ยกเลิก'); 
                                                                        ?>
                                                                    </span>
                                                                </h6>
                                                                <div class="row">
                                                                    <div class="col-sm-6">
                                                                        <small class="text-muted">วันที่เริ่มสัญญา:</small>
                                                                        <div><?php echo formatDate($contract['contract_start']); ?></div>
                                                                    </div>
                                                                    <div class="col-sm-6">
                                                                        <small class="text-muted">วันที่สิ้นสุด:</small>
                                                                        <div><?php echo formatDate($contract['contract_end']); ?></div>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                            <div class="col-md-4 text-md-end">
                                                                <div class="mb-2">
                                                                    <small class="text-muted">ค่าเช่า/เดือน:</small>
                                                                    <div class="fw-bold text-success"><?php echo formatCurrency($contract['monthly_rent']); ?></div>
                                                                </div>
                                                                <div class="mb-2">
                                                                    <small class="text-muted">เงินมัดจำ:</small>
                                                                    <div class="fw-bold"><?php echo formatCurrency($contract['deposit_paid']); ?></div>
                                                                </div>
                                                                <?php if ($contract['contract_status'] == 'active'): ?>
                                                                    <div class="mt-2">
                                                                        <a href="view_contract.php?id=<?php echo $contract['contract_id']; ?>" 
                                                                           class="btn btn-sm btn-outline-primary">
                                                                            <i class="bi bi-eye"></i>
                                                                            ดูรายละเอียด
                                                                        </a>
                                                                    </div>
                                                                <?php endif; ?>
                                                            </div>
                                                        </div>
                                                        
                                                        <?php if ($contract['special_conditions']): ?>
                                                            <div class="mt-3">
                                                                <small class="text-muted">เงื่อนไขพิเศษ:</small>
                                                                <div class="text-muted"><?php echo nl2br(htmlspecialchars($contract['special_conditions'])); ?></div>
                                                            </div>
                                                        <?php endif; ?>
                                                        
                                                        <div class="mt-3">
                                                            <small class="text-muted">
                                                                <i class="bi bi-calendar"></i>
                                                                ระยะเวลา: <?php echo $contract['days_since_start']; ?> วัน
                                                                <?php if ($contract['contract_status'] == 'active' && $contract['days_until_end'] > 0): ?>
                                                                    | เหลืออีก: <?php echo $contract['days_until_end']; ?> วัน
                                                                <?php endif; ?>
                                                            </small>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.avatar {
    font-weight: 600;
}

.timeline {
    position: relative;
    padding-left: 30px;
}

.timeline-item {
    position: relative;
    margin-bottom: 30px;
}

.timeline-item:not(:last-child)::before {
    content: '';
    position: absolute;
    left: -22px;
    top: 20px;
    bottom: -30px;
    width: 2px;
    background-color: #dee2e6;
}

.timeline-marker {
    position: absolute;
    left: -26px;
    top: 8px;
    width: 12px;
    height: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    background-color: #fff;
    border-radius: 50%;
    font-size: 8px;
}

.timeline-content {
    margin-left: 20px;
}

.card {
    transition: all 0.3s ease-in-out;
}

.card:hover {
    transform: translateY(-2px);
    box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15) !important;
}

.border {
    transition: all 0.2s ease-in-out;
}

.border:hover {
    border-color: #0d6efd !important;
    background-color: #f8f9ff !important;
}

.font-monospace {
    font-family: 'Courier New', monospace;
}

.text-break {
    word-wrap: break-word;
    word-break: break-word;
}

@media (max-width: 768px) {
    .timeline {
        padding-left: 20px;
    }
    
    .timeline-marker {
        left: -16px;
    }
    
    .timeline-content {
        margin-left: 10px;
    }
    
    .timeline-item:not(:last-child)::before {
        left: -12px;
    }
}

@media print {
    .btn, .dropdown {
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
    
    .timeline-item:not(:last-child)::before {
        background-color: #000 !important;
    }
}
</style>

<?php include 'includes/footer.php'; ?>