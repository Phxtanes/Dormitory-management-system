<?php
$page_title = "ดูข้อมูลสัญญาเช่า";
require_once 'includes/header.php';

// รับ contract_id จาก URL
$contract_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($contract_id <= 0) {
    header('Location: contracts.php');
    exit;
}

// ดึงข้อมูลสัญญาเช่า
try {
    $stmt = $pdo->prepare("
        SELECT 
            c.*,
            t.first_name, t.last_name, t.phone, t.email, t.id_card, t.address,
            t.emergency_contact, t.emergency_phone,
            r.room_number, r.room_type, r.floor_number, r.room_description,
            DATEDIFF(c.contract_end, CURDATE()) AS days_until_expiry,
            DATEDIFF(CURDATE(), c.contract_start) AS contract_days
        FROM contracts c
        LEFT JOIN tenants t ON c.tenant_id = t.tenant_id
        LEFT JOIN rooms r ON c.room_id = r.room_id
        WHERE c.contract_id = ?
    ");
    $stmt->execute([$contract_id]);
    $contract = $stmt->fetch();
    
    if (!$contract) {
        header('Location: contracts.php');
        exit;
    }
} catch(PDOException $e) {
    $error_message = "เกิดข้อผิดพลาด: " . $e->getMessage();
}

// ดึงข้อมูลใบแจ้งหนี้ของสัญญานี้
try {
    $stmt = $pdo->prepare("
        SELECT 
            i.*,
            CASE 
                WHEN i.invoice_status = 'paid' THEN 'ชำระแล้ว'
                WHEN i.invoice_status = 'pending' AND i.due_date < CURDATE() THEN 'เกินกำหนด'
                WHEN i.invoice_status = 'pending' THEN 'รอชำระ'
                WHEN i.invoice_status = 'cancelled' THEN 'ยกเลิก'
                ELSE i.invoice_status
            END as status_text,
            CASE 
                WHEN i.invoice_status = 'paid' THEN 'success'
                WHEN i.invoice_status = 'pending' AND i.due_date < CURDATE() THEN 'danger'
                WHEN i.invoice_status = 'pending' THEN 'warning'
                WHEN i.invoice_status = 'cancelled' THEN 'secondary'
                ELSE 'secondary'
            END as status_class
        FROM invoices i
        WHERE i.contract_id = ?
        ORDER BY i.invoice_month DESC
    ");
    $stmt->execute([$contract_id]);
    $invoices = $stmt->fetchAll();
} catch(PDOException $e) {
    $invoices = [];
}

// คำนวณสถิติการชำระเงิน
$total_invoices = count($invoices);
$paid_invoices = count(array_filter($invoices, function($inv) { return $inv['invoice_status'] == 'paid'; }));
$pending_invoices = count(array_filter($invoices, function($inv) { return $inv['invoice_status'] == 'pending'; }));
$overdue_invoices = count(array_filter($invoices, function($inv) { 
    return $inv['invoice_status'] == 'pending' && $inv['due_date'] < date('Y-m-d'); 
}));

$total_amount = array_sum(array_column($invoices, 'total_amount'));
$paid_amount = array_sum(array_map(function($inv) { 
    return $inv['invoice_status'] == 'paid' ? $inv['total_amount'] : 0; 
}, $invoices));
$pending_amount = $total_amount - $paid_amount;
?>


<?php include 'includes/navbar.php'; ?>

<div class="container-fluid">
    <!-- หัวข้อหน้า -->
    <div class="row mb-4 mt-3">
        <div class="col-md-8">
            <h2 class="mb-1">
                <i class="bi bi-file-earmark-text text-primary"></i>
                ข้อมูลสัญญาเช่า #<?php echo $contract['contract_id']; ?>
            </h2>
            <p class="text-muted mb-0">
                ดูรายละเอียดสัญญาเช่าและประวัติการชำระเงิน
            </p>
        </div>
        <div class="col-md-4 text-md-end">
            <div class="btn-group">
                <a href="contracts.php" class="btn btn-secondary">
                    <i class="bi bi-arrow-left"></i>
                    กลับ
                </a>
                <a href="edit_contract.php?id=<?php echo $contract['contract_id']; ?>" class="btn btn-warning">
                    <i class="bi bi-pencil-square"></i>
                    แก้ไข
                </a>
                <div class="btn-group">
                    <button type="button" class="btn btn-primary dropdown-toggle" data-bs-toggle="dropdown">
                        <i class="bi bi-printer"></i>
                        พิมพ์
                    </button>
                    <ul class="dropdown-menu">
                        <li><a class="dropdown-item" href="#" onclick="window.print()">
                            <i class="bi bi-file-text"></i> พิมพ์สัญญา
                        </a></li>
                        <li><a class="dropdown-item" href="generate_contract_pdf.php?id=<?php echo $contract['contract_id']; ?>">
                            <i class="bi bi-file-pdf"></i> ดาวน์โหลด PDF
                        </a></li>
                    </ul>
                </div>
            </div>
        </div>
    </div>

    <!-- แจ้งเตือนสถานะสัญญา -->
    <?php if ($contract['contract_status'] == 'active'): ?>
        <?php if ($contract['days_until_expiry'] <= 0): ?>
            <div class="alert alert-danger">
                <i class="bi bi-exclamation-triangle-fill"></i>
                <strong>สัญญาหมดอายุแล้ว!</strong> 
                สัญญานี้หมดอายุเมื่อ <?php echo formatDate($contract['contract_end']); ?>
            </div>
        <?php elseif ($contract['days_until_expiry'] <= 30): ?>
            <div class="alert alert-warning">
                <i class="bi bi-clock-fill"></i>
                <strong>สัญญาใกล้หมดอายุ!</strong> 
                เหลืออีก <?php echo $contract['days_until_expiry']; ?> วัน (หมดอายุ <?php echo formatDate($contract['contract_end']); ?>)
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <div class="row">
        <!-- ข้อมูลสัญญา -->
        <div class="col-lg-8">
            <!-- ข้อมูลผู้เช่า -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0">
                        <i class="bi bi-person-fill"></i>
                        ข้อมูลผู้เช่า
                    </h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="d-flex align-items-center mb-3">
                                <div class="avatar bg-primary text-white rounded-circle me-3" style="width: 60px; height: 60px; display: flex; align-items: center; justify-content: center; font-size: 1.5rem;">
                                    <?php echo mb_substr($contract['first_name'], 0, 1, 'UTF-8'); ?>
                                </div>
                                <div>
                                    <h5 class="mb-1"><?php echo $contract['first_name'] . ' ' . $contract['last_name']; ?></h5>
                                    <p class="text-muted mb-0">ผู้เช่า</p>
                                </div>
                            </div>
                            
                            <div class="row g-3">
                                <div class="col-12">
                                    <label class="form-label fw-bold">เลขบัตรประจำตัวประชาชน:</label>
                                    <p class="mb-0"><?php echo $contract['id_card']; ?></p>
                                </div>
                                <div class="col-12">
                                    <label class="form-label fw-bold">เบอร์โทรศัพท์:</label>
                                    <p class="mb-0">
                                        <i class="bi bi-telephone"></i>
                                        <a href="tel:<?php echo $contract['phone']; ?>" class="text-decoration-none">
                                            <?php echo $contract['phone']; ?>
                                        </a>
                                    </p>
                                </div>
                                <?php if ($contract['email']): ?>
                                <div class="col-12">
                                    <label class="form-label fw-bold">อีเมล:</label>
                                    <p class="mb-0">
                                        <i class="bi bi-envelope"></i>
                                        <a href="mailto:<?php echo $contract['email']; ?>" class="text-decoration-none">
                                            <?php echo $contract['email']; ?>
                                        </a>
                                    </p>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                            <?php if ($contract['address']): ?>
                            <div class="mb-3">
                                <label class="form-label fw-bold">ที่อยู่:</label>
                                <p class="mb-0"><?php echo nl2br(htmlspecialchars($contract['address'])); ?></p>
                            </div>
                            <?php endif; ?>
                            
                            <?php if ($contract['emergency_contact']): ?>
                            <div class="mb-3">
                                <label class="form-label fw-bold">ผู้ติดต่อฉุกเฉิน:</label>
                                <p class="mb-1"><?php echo $contract['emergency_contact']; ?></p>
                                <?php if ($contract['emergency_phone']): ?>
                                <p class="mb-0 text-muted">
                                    <i class="bi bi-telephone"></i>
                                    <a href="tel:<?php echo $contract['emergency_phone']; ?>" class="text-decoration-none">
                                        <?php echo $contract['emergency_phone']; ?>
                                    </a>
                                </p>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ข้อมูลห้องพัก -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0">
                        <i class="bi bi-door-open-fill"></i>
                        ข้อมูลห้องพัก
                    </h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="text-center mb-3">
                                <div class="badge bg-info fs-1 p-3 rounded-circle mb-2">
                                    <?php echo $contract['room_number']; ?>
                                </div>
                                <h5>ห้อง <?php echo $contract['room_number']; ?></h5>
                                <p class="text-muted mb-0">ชั้น <?php echo $contract['floor_number']; ?></p>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="row g-3">
                                <div class="col-12">
                                    <label class="form-label fw-bold">ประเภทห้อง:</label>
                                    <p class="mb-0">
                                        <?php 
                                        $room_types = [
                                            'single' => 'ห้องเดี่ยว',
                                            'double' => 'ห้องคู่', 
                                            'triple' => 'ห้องสามเตียง'
                                        ];
                                        echo $room_types[$contract['room_type']] ?? $contract['room_type']; 
                                        ?>
                                    </p>
                                </div>
                                <div class="col-12">
                                    <label class="form-label fw-bold">รายละเอียดห้อง:</label>
                                    <p class="mb-0"><?php echo $contract['room_description'] ?: 'ไม่มีรายละเอียด'; ?></p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ข้อมูลสัญญา -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0">
                        <i class="bi bi-file-earmark-text"></i>
                        รายละเอียดสัญญา
                    </h5>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label fw-bold">วันที่เริ่มสัญญา:</label>
                            <p class="mb-0"><?php echo formatDate($contract['contract_start']); ?></p>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-bold">วันที่สิ้นสุดสัญญา:</label>
                            <p class="mb-0"><?php echo formatDate($contract['contract_end']); ?></p>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-bold">ระยะเวลาสัญญา:</label>
                            <p class="mb-0"><?php echo ceil($contract['contract_days'] / 30); ?> เดือน</p>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-bold">สถานะสัญญา:</label>
                            <p class="mb-0">
                                <?php
                                $status_classes = [
                                    'active' => 'success',
                                    'expired' => 'danger',
                                    'terminated' => 'warning'
                                ];
                                $status_texts = [
                                    'active' => 'ใช้งาน',
                                    'expired' => 'หมดอายุ',
                                    'terminated' => 'ยกเลิก'
                                ];
                                $status_class = $status_classes[$contract['contract_status']] ?? 'secondary';
                                $status_text = $status_texts[$contract['contract_status']] ?? $contract['contract_status'];
                                ?>
                                <span class="badge bg-<?php echo $status_class; ?>"><?php echo $status_text; ?></span>
                            </p>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">ค่าเช่าต่อเดือน:</label>
                            <p class="mb-0 text-primary fw-bold fs-5"><?php echo formatCurrency($contract['monthly_rent']); ?></p>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">เงินมัดจำ:</label>
                            <p class="mb-0 text-success fw-bold fs-5"><?php echo formatCurrency($contract['deposit_paid']); ?></p>
                        </div>
                        <?php if ($contract['special_conditions']): ?>
                        <div class="col-12">
                            <label class="form-label fw-bold">เงื่อนไขพิเศษ:</label>
                            <div class="border rounded p-3 bg-light">
                                <?php echo nl2br(htmlspecialchars($contract['special_conditions'])); ?>
                            </div>
                        </div>
                        <?php endif; ?>
                        <div class="col-12">
                            <label class="form-label fw-bold">วันที่สร้างสัญญา:</label>
                            <p class="mb-0 text-muted"><?php echo formatDateTime($contract['created_at']); ?></p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- สถิติและข้อมูลการเงิน -->
        <div class="col-lg-4">
            <!-- สถิติการชำระเงิน -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0">
                        <i class="bi bi-graph-up"></i>
                        สถิติการชำระเงิน
                    </h5>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-6">
                            <div class="text-center">
                                <div class="text-primary fs-2 fw-bold"><?php echo $total_invoices; ?></div>
                                <small class="text-muted">ใบแจ้งหนี้ทั้งหมด</small>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="text-center">
                                <div class="text-success fs-2 fw-bold"><?php echo $paid_invoices; ?></div>
                                <small class="text-muted">ชำระแล้ว</small>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="text-center">
                                <div class="text-warning fs-2 fw-bold"><?php echo $pending_invoices; ?></div>
                                <small class="text-muted">รอชำระ</small>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="text-center">
                                <div class="text-danger fs-2 fw-bold"><?php echo $overdue_invoices; ?></div>
                                <small class="text-muted">เกินกำหนด</small>
                            </div>
                        </div>
                    </div>
                    
                    <?php if ($total_invoices > 0): ?>
                    <hr>
                    <div class="text-center">
                        <div class="progress mb-2" style="height: 20px;">
                            <div class="progress-bar bg-success" style="width: <?php echo ($paid_invoices / $total_invoices) * 100; ?>%"></div>
                        </div>
                        <small class="text-muted">
                            อัตราการชำระเงิน <?php echo number_format(($paid_invoices / $total_invoices) * 100, 1); ?>%
                        </small>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- สรุปยอดเงิน -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0">
                        <i class="bi bi-cash-stack"></i>
                        สรุปยอดเงิน
                    </h5>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label fw-bold">ยอดรวมทั้งหมด:</label>
                            <div class="fs-4 fw-bold text-primary"><?php echo formatCurrency($total_amount); ?></div>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-bold">ยอดที่ชำระแล้ว:</label>
                            <div class="fs-5 fw-bold text-success"><?php echo formatCurrency($paid_amount); ?></div>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-bold">ยอดค้างชำระ:</label>
                            <div class="fs-5 fw-bold text-danger"><?php echo formatCurrency($pending_amount); ?></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- การดำเนินการ -->
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">
                        <i class="bi bi-gear"></i>
                        การดำเนินการ
                    </h5>
                </div>
                <div class="card-body">
                    <div class="d-grid gap-2">
                        <a href="add_invoice.php?contract_id=<?php echo $contract['contract_id']; ?>" class="btn btn-primary">
                            <i class="bi bi-file-earmark-plus"></i>
                            สร้างใบแจ้งหนี้
                        </a>
                        <a href="edit_contract.php?id=<?php echo $contract['contract_id']; ?>" class="btn btn-warning">
                            <i class="bi bi-pencil-square"></i>
                            แก้ไขสัญญา
                        </a>
                        <?php if ($contract['contract_status'] == 'active'): ?>
                        <button type="button" class="btn btn-danger" onclick="terminateContract(<?php echo $contract['contract_id']; ?>)">
                            <i class="bi bi-x-circle"></i>
                            ยกเลิกสัญญา
                        </button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ประวัติการออกใบแจ้งหนี้ -->
    <div class="card">
        <div class="card-header">
            <h5 class="mb-0">
                <i class="bi bi-receipt"></i>
                ประวัติใบแจ้งหนี้
                <span class="badge bg-primary ms-2"><?php echo count($invoices); ?> รายการ</span>
            </h5>
        </div>
        <div class="card-body">
            <?php if (empty($invoices)): ?>
                <div class="text-center py-5">
                    <i class="bi bi-receipt display-1 text-muted"></i>
                    <h4 class="text-muted mt-3">ยังไม่มีใบแจ้งหนี้</h4>
                    <p class="text-muted">สัญญานี้ยังไม่มีประวัติการออกใบแจ้งหนี้</p>
                    <a href="add_invoice.php?contract_id=<?php echo $contract['contract_id']; ?>" class="btn btn-primary">
                        <i class="bi bi-file-earmark-plus"></i>
                        สร้างใบแจ้งหนี้แรก
                    </a>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead class="table-light">
                            <tr>
                                <th>เดือน</th>
                                <th>ค่าเช่า</th>
                                <th>ค่าน้ำ</th>
                                <th>ค่าไฟ</th>
                                <th>ค่าใช้จ่ายอื่นๆ</th>
                                <th>ส่วนลด</th>
                                <th>ยอดรวม</th>
                                <th>กำหนดชำระ</th>
                                <th>สถานะ</th>
                                <th class="text-center">จัดการ</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($invoices as $invoice): ?>
                            <tr>
                                <td>
                                    <?php 
                                    $month_year = explode('-', $invoice['invoice_month']);
                                    echo thaiMonth($month_year[1]) . ' ' . ($month_year[0] + 543);
                                    ?>
                                </td>
                                <td><?php echo formatCurrency($invoice['room_rent']); ?></td>
                                <td><?php echo formatCurrency($invoice['water_charge']); ?></td>
                                <td><?php echo formatCurrency($invoice['electric_charge']); ?></td>
                                <td><?php echo formatCurrency($invoice['other_charges']); ?></td>
                                <td><?php echo formatCurrency($invoice['discount']); ?></td>
                                <td class="fw-bold"><?php echo formatCurrency($invoice['total_amount']); ?></td>
                                <td><?php echo formatDate($invoice['due_date']); ?></td>
                                <td>
                                    <span class="badge bg-<?php echo $invoice['status_class']; ?>">
                                        <?php echo $invoice['status_text']; ?>
                                    </span>
                                </td>
                                <td class="text-center">
                                    <div class="btn-group btn-group-sm">
                                        <a href="view_invoice.php?id=<?php echo $invoice['invoice_id']; ?>" 
                                           class="btn btn-outline-primary" title="ดูใบแจ้งหนี้">
                                            <i class="bi bi-eye"></i>
                                        </a>
                                        <?php if ($invoice['invoice_status'] == 'pending'): ?>
                                        <a href="edit_invoice.php?id=<?php echo $invoice['invoice_id']; ?>" 
                                           class="btn btn-outline-warning" title="แก้ไข">
                                            <i class="bi bi-pencil"></i>
                                        </a>
                                        <a href="add_payment.php?invoice_id=<?php echo $invoice['invoice_id']; ?>" 
                                           class="btn btn-outline-success" title="บันทึกการชำระเงิน">
                                            <i class="bi bi-cash"></i>
                                        </a>
                                        <?php endif; ?>
                                    </div>
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

<script>
function terminateContract(contractId) {
    if (confirm('คุณต้องการยกเลิกสัญญานี้หรือไม่?\n\nการยกเลิกสัญญาจะทำให้:\n- สถานะสัญญาเปลี่ยนเป็น "ยกเลิก"\n- ห้องพักจะว่างและสามารถให้เช่าใหม่ได้\n- ไม่สามารถยกเลิกการดำเนินการนี้ได้')) {
        // ส่งไปยังหน้าจัดการยกเลิกสัญญา
        window.location.href = 'terminate_contract.php?id=' + contractId;
    }
}

// ฟังก์ชันสำหรับพิมพ์เอกสาร
function printContract() {
    window.print();
}

// ตั้งค่าการพิมพ์
document.addEventListener('DOMContentLoaded', function() {
    // ซ่อนปุ่มเมื่อพิมพ์
    window.addEventListener('beforeprint', function() {
        document.querySelectorAll('.btn, .dropdown-menu').forEach(function(element) {
            element.style.display = 'none';
        });
    });
    
    window.addEventListener('afterprint', function() {
        document.querySelectorAll('.btn, .dropdown-menu').forEach(function(element) {
            element.style.display = '';
        });
    });
});
</script>

<style>
@media print {
    .btn, .dropdown-menu, .card-header .btn-group {
        display: none !important;
    }
    
    .card {
        border: 1px solid #000 !important;
        box-shadow: none !important;
        page-break-inside: avoid;
        margin-bottom: 20px !important;
    }
    
    .card-header {
        background-color: #f8f9fa !important;
        -webkit-print-color-adjust: exact;
        color: #000 !important;
    }
    
    .table {
        font-size: 11px;
    }
    
    .badge {
        border: 1px solid #000;
        -webkit-print-color-adjust: exact;
    }
    
    @page {
        margin: 1cm;
        size: A4;
    }
    
    body {
        font-size: 12px;
        color: #000;
    }
    
    h2, h5, .fs-1, .fs-2, .fs-4, .fs-5 {
        color: #000 !important;
    }
    
    .text-primary, .text-success, .text-danger, .text-warning {
        color: #000 !important;
    }
    
    .progress {
        border: 1px solid #000;
    }
    
    .progress-bar {
        background-color: #000 !important;
    }
}

.avatar {
    font-weight: 600;
}

.progress {
    background-color: #e9ecef;
}

.card {
    transition: all 0.3s ease-in-out;
}

.card:hover {
    transform: translateY(-2px);
    box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15) !important;
}

.table th {
    background-color: #f8f9fa;
    font-weight: 600;
    white-space: nowrap;
}

.table td {
    vertical-align: middle;
}

.badge {
    font-size: 0.75rem;
}

.btn-group-sm .btn {
    padding: 0.125rem 0.25rem;
    font-size: 0.75rem;
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
    
    .card-body {
        padding: 1rem;
    }
    
    .row.g-3 > .col-12,
    .row.g-3 > .col-6,
    .row.g-3 > .col-md-3,
    .row.g-3 > .col-md-6 {
        margin-bottom: 1rem;
    }
}
</style>

<?php include 'includes/footer.php'; ?>