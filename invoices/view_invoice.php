<?php
$page_title = "รายละเอียดใบแจ้งหนี้";
require_once 'includes/header.php';

$success_message = '';
$error_message = '';
$invoice = null;
$payments = [];

// ตรวจสอบ ID ใบแจ้งหนี้
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header('Location: invoices.php');
    exit;
}

$invoice_id = intval($_GET['id']);

// แสดงข้อความสำเร็จจาก URL parameter
if (isset($_GET['success']) && $_GET['success'] == '1') {
    $success_message = "สร้างใบแจ้งหนี้เรียบร้อยแล้ว";
}

try {
    // ดึงข้อมูลใบแจ้งหนี้พร้อมข้อมูลที่เกี่ยวข้อง
    $stmt = $pdo->prepare("
        SELECT i.*, 
               c.contract_start, c.contract_end, c.special_conditions,
               t.first_name, t.last_name, t.phone, t.email, t.id_card, t.address,
               t.emergency_contact, t.emergency_phone,
               r.room_number, r.room_type, r.floor_number, r.room_description,
               DATEDIFF(CURDATE(), i.due_date) as days_overdue,
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
    
    // ดึงข้อมูลการชำระเงิน
    $stmt = $pdo->prepare("
        SELECT p.*, 
               CASE 
                   WHEN p.payment_method = 'cash' THEN 'เงินสด'
                   WHEN p.payment_method = 'bank_transfer' THEN 'โอนเงิน'
                   WHEN p.payment_method = 'mobile_banking' THEN 'Mobile Banking'
                   WHEN p.payment_method = 'other' THEN 'อื่นๆ'
                   ELSE p.payment_method
               END as payment_method_text
        FROM payments p
        WHERE p.invoice_id = ?
        ORDER BY p.payment_date DESC, p.created_at DESC
    ");
    $stmt->execute([$invoice_id]);
    $payments = $stmt->fetchAll();
    
    // คำนวณยอดที่ชำระแล้ว
    $total_paid = array_sum(array_column($payments, 'payment_amount'));
    $remaining_amount = $invoice['total_amount'] - $total_paid;
    
    // กำหนดสถานะและคลาส
    $status_info = [
        'pending' => ['class' => 'warning', 'text' => 'รอชำระ', 'icon' => 'clock'],
        'paid' => ['class' => 'success', 'text' => 'ชำระแล้ว', 'icon' => 'check-circle'],
        'overdue' => ['class' => 'danger', 'text' => 'เกินกำหนด', 'icon' => 'exclamation-triangle'],
        'cancelled' => ['class' => 'secondary', 'text' => 'ยกเลิก', 'icon' => 'x-circle']
    ];
    
    $current_status = $invoice['invoice_status'];
    if ($current_status == 'pending' && $invoice['days_overdue'] > 0) {
        $current_status = 'overdue';
    }
    
} catch(PDOException $e) {
    $error_message = "เกิดข้อผิดพลาดในการดึงข้อมูล: " . $e->getMessage();
}

// ตรวจสอบการลบใบแจ้งหนี้
if (isset($_POST['delete_invoice']) && $invoice) {
    if ($invoice['invoice_status'] == 'pending' && empty($payments)) {
        try {
            $stmt = $pdo->prepare("DELETE FROM invoices WHERE invoice_id = ?");
            $stmt->execute([$invoice_id]);
            
            header('Location: invoices.php?message=deleted');
            exit;
            
        } catch(PDOException $e) {
            $error_message = "เกิดข้อผิดพลาดในการลบใบแจ้งหนี้: " . $e->getMessage();
        }
    } else {
        $error_message = "ไม่สามารถลบใบแจ้งหนี้ที่มีการชำระเงินแล้ว";
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
                    <i class="bi bi-receipt"></i>
                    ใบแจ้งหนี้ <?php echo $invoice ? $invoice['invoice_number'] : ''; ?>
                </h2>
                <div class="btn-group">
                    <a href="invoices.php" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left"></i>
                        กลับไปรายการใบแจ้งหนี้
                    </a>
                    <?php if ($invoice): ?>
                    <div class="btn-group">
                        <button type="button" class="btn btn-primary dropdown-toggle" data-bs-toggle="dropdown">
                            <i class="bi bi-gear"></i>
                            การจัดการ
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><a class="dropdown-item" href="print_invoice.php?id=<?php echo $invoice_id; ?>" target="_blank">
                                <i class="bi bi-printer"></i> พิมพ์ใบแจ้งหนี้
                            </a></li>
                            <?php if ($current_status != 'paid'): ?>
                            <li><a class="dropdown-item" href="edit_invoice.php?id=<?php echo $invoice_id; ?>">
                                <i class="bi bi-pencil"></i> แก้ไขใบแจ้งหนี้
                            </a></li>
                            <li><a class="dropdown-item" href="add_payment.php?invoice_id=<?php echo $invoice_id; ?>">
                                <i class="bi bi-cash-coin"></i> บันทึกการชำระ
                            </a></li>
                            <?php endif; ?>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="view_contract.php?id=<?php echo $invoice['contract_id']; ?>">
                                <i class="bi bi-file-earmark-text"></i> ดูสัญญาเช่า
                            </a></li>
                            <li><a class="dropdown-item" href="view_tenant.php?id=<?php echo $invoice['tenant_id']; ?>">
                                <i class="bi bi-person"></i> ข้อมูลผู้เช่า
                            </a></li>
                            <?php if ($invoice['invoice_status'] == 'pending' && empty($payments)): ?>
                            <li><hr class="dropdown-divider"></li>
                            <li>
                                <form method="POST" style="display: inline;">
                                    <button type="submit" name="delete_invoice" class="dropdown-item text-danger" 
                                            onclick="return confirm('คุณต้องการลบใบแจ้งหนี้นี้หรือไม่?\n\nการดำเนินการนี้ไม่สามารถยกเลิกได้')">
                                        <i class="bi bi-trash"></i> ลบใบแจ้งหนี้
                                    </button>
                                </form>
                            </li>
                            <?php endif; ?>
                        </ul>
                    </div>
                    <?php endif; ?>
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

            <?php if ($invoice): ?>
            <div class="row">
                <!-- ข้อมูลใบแจ้งหนี้หลัก -->
                <div class="col-lg-8">
                    <div class="card shadow-sm">
                        <div class="card-header bg-primary text-white">
                            <div class="d-flex justify-content-between align-items-center">
                                <h5 class="mb-0">
                                    <i class="bi bi-receipt"></i>
                                    ใบแจ้งหนี้ <?php echo $invoice['invoice_number']; ?>
                                </h5>
                                <span class="badge bg-<?php echo $status_info[$current_status]['class']; ?> fs-6">
                                    <i class="bi bi-<?php echo $status_info[$current_status]['icon']; ?>"></i>
                                    <?php echo $status_info[$current_status]['text']; ?>
                                </span>
                            </div>
                        </div>
                        <div class="card-body">
                            <!-- ข้อมูลผู้เช่าและห้อง -->
                            <div class="row mb-4">
                                <div class="col-md-6">
                                    <h6 class="text-primary mb-3">
                                        <i class="bi bi-person"></i>
                                        ข้อมูลผู้เช่า
                                    </h6>
                                    <div class="mb-2">
                                        <strong><?php echo $invoice['first_name'] . ' ' . $invoice['last_name']; ?></strong>
                                    </div>
                                    <div class="text-muted mb-1">
                                        <i class="bi bi-telephone"></i>
                                        <?php echo $invoice['phone']; ?>
                                    </div>
                                    <?php if ($invoice['email']): ?>
                                    <div class="text-muted mb-1">
                                        <i class="bi bi-envelope"></i>
                                        <?php echo $invoice['email']; ?>
                                    </div>
                                    <?php endif; ?>
                                    <div class="text-muted">
                                        <i class="bi bi-credit-card"></i>
                                        <?php echo $invoice['id_card']; ?>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <h6 class="text-primary mb-3">
                                        <i class="bi bi-door-open"></i>
                                        ข้อมูลห้องพัก
                                    </h6>
                                    <div class="mb-2">
                                        <strong>ห้อง <?php echo $invoice['room_number']; ?></strong>
                                        <span class="badge bg-info ms-2">ชั้น <?php echo $invoice['floor_number']; ?></span>
                                    </div>
                                    <div class="text-muted mb-1">
                                        ประเภท: <?php
                                        $room_types = [
                                            'single' => 'เดี่ยว',
                                            'double' => 'คู่', 
                                            'triple' => 'สาม'
                                        ];
                                        echo $room_types[$invoice['room_type']] ?? $invoice['room_type'];
                                        ?>
                                    </div>
                                    <div class="text-muted">
                                        สัญญาเลขที่: <?php echo $invoice['contract_number']; ?>
                                    </div>
                                </div>
                            </div>

                            <!-- รายละเอียดใบแจ้งหนี้ -->
                            <h6 class="text-primary mb-3">
                                <i class="bi bi-list-check"></i>
                                รายละเอียดค่าใช้จ่าย
                            </h6>
                            
                            <div class="table-responsive">
                                <table class="table table-bordered">
                                    <thead class="table-light">
                                        <tr>
                                            <th>รายการ</th>
                                            <th class="text-end">จำนวนเงิน</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr>
                                            <td>
                                                <strong>ค่าเช่าห้อง</strong>
                                                <div class="small text-muted">
                                                    เดือน <?php 
                                                    $month_year = explode('-', $invoice['invoice_month']);
                                                    echo thaiMonth($month_year[1]) . ' ' . ($month_year[0] + 543);
                                                    ?>
                                                </div>
                                            </td>
                                            <td class="text-end"><?php echo formatCurrency($invoice['room_rent']); ?></td>
                                        </tr>
                                        <?php if ($invoice['water_charge'] > 0): ?>
                                        <tr>
                                            <td>ค่าน้ำ</td>
                                            <td class="text-end"><?php echo formatCurrency($invoice['water_charge']); ?></td>
                                        </tr>
                                        <?php endif; ?>
                                        <?php if ($invoice['electric_charge'] > 0): ?>
                                        <tr>
                                            <td>ค่าไฟ</td>
                                            <td class="text-end"><?php echo formatCurrency($invoice['electric_charge']); ?></td>
                                        </tr>
                                        <?php endif; ?>
                                        <?php if ($invoice['other_charges'] > 0): ?>
                                        <tr>
                                            <td>
                                                ค่าใช้จ่ายอื่นๆ
                                                <?php if ($invoice['other_charges_description']): ?>
                                                <div class="small text-muted"><?php echo htmlspecialchars($invoice['other_charges_description']); ?></div>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-end"><?php echo formatCurrency($invoice['other_charges']); ?></td>
                                        </tr>
                                        <?php endif; ?>
                                        <?php if ($invoice['discount'] > 0): ?>
                                        <tr class="table-success">
                                            <td>ส่วนลด</td>
                                            <td class="text-end text-success">-<?php echo formatCurrency($invoice['discount']); ?></td>
                                        </tr>
                                        <?php endif; ?>
                                        <tr class="table-primary">
                                            <td><strong>ยอดรวมทั้งสิ้น</strong></td>
                                            <td class="text-end"><strong><?php echo formatCurrency($invoice['total_amount']); ?></strong></td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>

                            <!-- ข้อมูลวันที่ -->
                            <div class="row">
                                <div class="col-md-4">
                                    <div class="text-muted">
                                        <small>วันที่สร้าง:</small><br>
                                        <strong><?php echo formatDateTime($invoice['created_at']); ?></strong>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="text-muted">
                                        <small>กำหนดชำระ:</small><br>
                                        <strong class="<?php echo $invoice['days_overdue'] > 0 ? 'text-danger' : 'text-primary'; ?>">
                                            <?php echo formatDate($invoice['due_date']); ?>
                                        </strong>
                                        <?php if ($invoice['days_overdue'] > 0): ?>
                                        <span class="badge bg-danger ms-1">เกิน <?php echo $invoice['days_overdue']; ?> วัน</span>
                                        <?php elseif ($invoice['days_overdue'] < 0): ?>
                                        <span class="badge bg-warning text-dark ms-1">เหลือ <?php echo abs($invoice['days_overdue']); ?> วัน</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <?php if ($invoice['payment_date']): ?>
                                <div class="col-md-4">
                                    <div class="text-muted">
                                        <small>วันที่ชำระ:</small><br>
                                        <strong class="text-success"><?php echo formatDate($invoice['payment_date']); ?></strong>
                                    </div>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- แผงด้านข้าง -->
                <div class="col-lg-4">
                    <!-- สรุปการชำระเงิน -->
                    <div class="card shadow-sm mb-4">
                        <div class="card-header">
                            <h6 class="mb-0">
                                <i class="bi bi-calculator"></i>
                                สรุปการชำระเงิน
                            </h6>
                        </div>
                        <div class="card-body">
                            <div class="d-flex justify-content-between mb-2">
                                <span>ยอดรวม:</span>
                                <strong><?php echo formatCurrency($invoice['total_amount']); ?></strong>
                            </div>
                            <div class="d-flex justify-content-between mb-2">
                                <span>ชำระแล้ว:</span>
                                <span class="text-success"><?php echo formatCurrency($total_paid); ?></span>
                            </div>
                            <hr>
                            <div class="d-flex justify-content-between">
                                <span><strong>คงเหลือ:</strong></span>
                                <strong class="<?php echo $remaining_amount > 0 ? 'text-danger' : 'text-success'; ?>">
                                    <?php echo formatCurrency($remaining_amount); ?>
                                </strong>
                            </div>
                            
                            <?php if ($remaining_amount > 0 && $current_status != 'paid'): ?>
                            <div class="mt-3">
                                <a href="add_payment.php?invoice_id=<?php echo $invoice_id; ?>" class="btn btn-success w-100">
                                    <i class="bi bi-cash-coin"></i>
                                    บันทึกการชำระ
                                </a>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- การดำเนินการอื่นๆ -->
                    <div class="card shadow-sm mb-4">
                        <div class="card-header">
                            <h6 class="mb-0">
                                <i class="bi bi-tools"></i>
                                การดำเนินการ
                            </h6>
                        </div>
                        <div class="card-body">
                            <div class="d-grid gap-2">
                                <a href="print_invoice.php?id=<?php echo $invoice_id; ?>" target="_blank" class="btn btn-outline-primary">
                                    <i class="bi bi-printer"></i>
                                    พิมพ์ใบแจ้งหนี้
                                </a>
                                
                                <?php if ($current_status != 'paid'): ?>
                                <a href="edit_invoice.php?id=<?php echo $invoice_id; ?>" class="btn btn-outline-warning">
                                    <i class="bi bi-pencil"></i>
                                    แก้ไขใบแจ้งหนี้
                                </a>
                                <?php endif; ?>
                                
                                <a href="view_contract.php?id=<?php echo $invoice['contract_id']; ?>" class="btn btn-outline-info">
                                    <i class="bi bi-file-earmark-text"></i>
                                    ดูสัญญาเช่า
                                </a>
                                
                                <a href="view_tenant.php?id=<?php echo $invoice['tenant_id']; ?>" class="btn btn-outline-secondary">
                                    <i class="bi bi-person"></i>
                                    ข้อมูลผู้เช่า
                                </a>
                            </div>
                        </div>
                    </div>

                    <!-- ข้อมูลผู้ติดต่อฉุกเฉิน -->
                    <?php if ($invoice['emergency_contact']): ?>
                    <div class="card shadow-sm">
                        <div class="card-header">
                            <h6 class="mb-0">
                                <i class="bi bi-telephone-fill"></i>
                                ผู้ติดต่อฉุกเฉิน
                            </h6>
                        </div>
                        <div class="card-body">
                            <div class="mb-1">
                                <strong><?php echo $invoice['emergency_contact']; ?></strong>
                            </div>
                            <?php if ($invoice['emergency_phone']): ?>
                            <div class="text-muted">
                                <i class="bi bi-telephone"></i>
                                <?php echo $invoice['emergency_phone']; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- ประวัติการชำระเงิน -->
            <?php if (!empty($payments)): ?>
            <div class="row mt-4">
                <div class="col-12">
                    <div class="card shadow-sm">
                        <div class="card-header">
                            <h6 class="mb-0">
                                <i class="bi bi-clock-history"></i>
                                ประวัติการชำระเงิน
                            </h6>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead class="table-light">
                                        <tr>
                                            <th>วันที่ชำระ</th>
                                            <th>จำนวนเงิน</th>
                                            <th>วิธีการชำระ</th>
                                            <th>เลขที่อ้างอิง</th>
                                            <th>หมายเหตุ</th>
                                            <th>บันทึกเมื่อ</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($payments as $payment): ?>
                                        <tr>
                                            <td><?php echo formatDate($payment['payment_date']); ?></td>
                                            <td>
                                                <span class="badge bg-success">
                                                    <?php echo formatCurrency($payment['payment_amount']); ?>
                                                </span>
                                            </td>
                                            <td><?php echo $payment['payment_method_text']; ?></td>
                                            <td>
                                                <?php if ($payment['payment_reference']): ?>
                                                    <code><?php echo htmlspecialchars($payment['payment_reference']); ?></code>
                                                <?php else: ?>
                                                    <span class="text-muted">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($payment['notes']): ?>
                                                    <?php echo htmlspecialchars($payment['notes']); ?>
                                                <?php else: ?>
                                                    <span class="text-muted">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <small class="text-muted">
                                                    <?php echo formatDateTime($payment['created_at']); ?>
                                                </small>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <?php else: ?>
            <!-- ไม่พบใบแจ้งหนี้ -->
            <div class="card border-danger">
                <div class="card-body text-center py-5">
                    <i class="bi bi-exclamation-triangle display-1 text-danger"></i>
                    <h4 class="text-danger mt-3">ไม่พบใบแจ้งหนี้</h4>
                    <p class="text-muted">ใบแจ้งหนี้ที่ระบุไม่มีอยู่ในระบบ หรืออาจถูกลบไปแล้ว</p>
                    <a href="invoices.php" class="btn btn-primary">
                        <i class="bi bi-arrow-left"></i>
                        กลับไปรายการใบแจ้งหนี้
                    </a>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
// ฟังก์ชันพิมพ์หน้า
function printInvoice() {
    window.print();
}

// ตั้งค่าการพิมพ์
document.addEventListener('DOMContentLoaded', function() {
    // ซ่อนองค์ประกอบที่ไม่ต้องการเมื่อพิมพ์
    window.addEventListener('beforeprint', function() {
        document.querySelectorAll('.btn, .dropdown-menu, .alert').forEach(function(element) {
            element.style.display = 'none';
        });
        document.querySelector('body').classList.add('printing');
    });
    
    window.addEventListener('afterprint', function() {
        document.querySelectorAll('.btn, .dropdown-menu, .alert').forEach(function(element) {
            element.style.display = '';
        });
        document.querySelector('body').classList.remove('printing');
    });
    
    // Tooltips
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });
});

// ฟังก์ชันยืนยันการลบ
function confirmDelete() {
    return confirm('คุณต้องการลบใบแจ้งหนี้นี้หรือไม่?\n\nการดำเนินการนี้ไม่สามารถยกเลิกได้');
}
</script>

<style>
.card {
    transition: all 0.3s ease-in-out;
}

.card:hover {
    box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15) !important;
}

.badge {
    font-size: 0.8rem;
}

.table th {
    font-weight: 600;
    background-color: #f8f9fa;
}

.table-responsive {
    border-radius: 0.375rem;
    border: 1px solid #dee2e6;
}

/* Print styles */
@media print {
    .btn, .dropdown-menu, .alert, .card-header .btn-group {
        display: none !important;
    }
    
    .card {
        border: 1px solid #000 !important;
        box-shadow: none !important;
        break-inside: avoid;
    }
    
    .card-header {
        background-color: #f8f9fa !important;
        -webkit-print-color-adjust: exact;
    }
    
    .table {
        border: 1px solid #000 !important;
    }
    
    .table th,
    .table td {
        border: 1px solid #000 !important;
        padding: 8px !important;
    }
    
    .badge {
        border: 1px solid #000 !important;
        color: #000 !important;
        background-color: #fff !important;
    }
    
    .text-primary,
    .text-success,
    .text-danger,
    .text-warning {
        color: #000 !important;
    }
    
    h1, h2, h3, h4, h5, h6 {
        color: #000 !important;
    }
    
    .container-fluid {
        padding: 0 !important;
    }
    
    .row {
        margin: 0 !important;
    }
    
    .col-lg-4 {
        display: none !important;
    }
    
    .col-lg-8 {
        width: 100% !important;
        max-width: 100% !important;
    }
    
    /* เพิ่มข้อมูลสำคัญที่จำเป็นในการพิมพ์ */
    .print-only {
        display: block !important;
    }
    
    .no-print {
        display: none !important;
    }
}

/* Responsive improvements */
@media (max-width: 768px) {
    .card-body {
        padding: 1rem;
    }
    
    .btn-group {
        flex-direction: column;
    }
    
    .btn-group .btn {
        margin-bottom: 0.25rem;
    }
    
    .table-responsive {
        font-size: 0.875rem;
    }
    
    .d-grid {
        gap: 0.5rem;
    }
}

/* Status badge improvements */
.badge.fs-6 {
    font-size: 1rem !important;
    padding: 0.5rem 0.75rem;
}

/* Table improvements */
.table-bordered th,
.table-bordered td {
    border: 1px solid #dee2e6;
}

.table-primary {
    background-color: rgba(13, 110, 253, 0.1);
}

.table-success {
    background-color: rgba(25, 135, 84, 0.1);
}

/* Card header improvements */
.card-header.bg-primary {
    background-color: #0d6efd !important;
    border-bottom: 1px solid #0d6efd;
}

/* Badge positioning */
.badge.ms-1 {
    font-size: 0.75rem;
    vertical-align: top;
}

/* Button improvements */
.d-grid .btn {
    padding: 0.75rem 1rem;
    font-weight: 500;
}

/* Text formatting */
code {
    background-color: #f8f9fa;
    padding: 0.125rem 0.25rem;
    border-radius: 0.25rem;
    font-size: 0.875em;
}

/* Alert improvements */
.alert {
    border-radius: 0.5rem;
    border: none;
    box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
}

/* Progress indicator for payment status */
.payment-progress {
    height: 0.5rem;
    background-color: #e9ecef;
    border-radius: 0.25rem;
    overflow: hidden;
}

.payment-progress-bar {
    height: 100%;
    background-color: #28a745;
    transition: width 0.3s ease;
}

/* Timeline for payment history */
.payment-timeline {
    position: relative;
    padding-left: 2rem;
}

.payment-timeline::before {
    content: '';
    position: absolute;
    left: 0.75rem;
    top: 0;
    bottom: 0;
    width: 2px;
    background-color: #dee2e6;
}

.payment-timeline-item {
    position: relative;
    margin-bottom: 1.5rem;
}

.payment-timeline-item::before {
    content: '';
    position: absolute;
    left: -1.25rem;
    top: 0.5rem;
    width: 0.75rem;
    height: 0.75rem;
    background-color: #28a745;
    border-radius: 50%;
    border: 2px solid #fff;
    box-shadow: 0 0 0 2px #28a745;
}
</style>

<?php include 'includes/footer.php'; ?>