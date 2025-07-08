<?php
$page_title = "จัดการเงินมัดจำ";
require_once 'includes/header.php';

$success_message = '';
$error_message = '';

// ตรวจสอบการอัพเดทสถานะ
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_deposit'])) {
    $deposit_id = $_POST['deposit_id'];
    $action = $_POST['action'];
    
    try {
        $pdo->beginTransaction();
        
        if ($action == 'refund') {
            $refund_amount = floatval($_POST['refund_amount']);
            $refund_method = $_POST['refund_method'];
            $deduction_amount = floatval($_POST['deduction_amount']);
            $deduction_reason = trim($_POST['deduction_reason']);
            
            // อัพเดทข้อมูลการคืนเงิน
            $stmt = $pdo->prepare("
                UPDATE deposits SET 
                    refund_amount = ?,
                    refund_date = CURDATE(),
                    refund_method = ?,
                    deduction_amount = ?,
                    deduction_reason = ?,
                    deposit_status = CASE 
                        WHEN (? + ?) >= deposit_amount THEN 'fully_refunded'
                        WHEN (? + ?) > 0 THEN 'partial_refund'
                        ELSE deposit_status 
                    END,
                    updated_by = ?
                WHERE deposit_id = ?
            ");
            $stmt->execute([
                $refund_amount, $refund_method, $deduction_amount, $deduction_reason,
                $refund_amount, $deduction_amount, $refund_amount, $deduction_amount,
                $_SESSION['user_id'], $deposit_id
            ]);
            
            $success_message = "บันทึกการคืนเงินมัดจำเรียบร้อยแล้ว";
            
        } elseif ($action == 'receive') {
            $payment_method = $_POST['payment_method'];
            $receipt_number = trim($_POST['receipt_number']);
            $bank_account = trim($_POST['bank_account']);
            $notes = trim($_POST['notes']);
            
            $stmt = $pdo->prepare("
                UPDATE deposits SET 
                    deposit_status = 'received',
                    payment_method = ?,
                    receipt_number = ?,
                    bank_account = ?,
                    notes = ?,
                    updated_by = ?
                WHERE deposit_id = ?
            ");
            $stmt->execute([
                $payment_method, $receipt_number, $bank_account, $notes,
                $_SESSION['user_id'], $deposit_id
            ]);
            
            // อัพเดทสถานะในสัญญา
            $stmt = $pdo->prepare("
                UPDATE contracts c
                JOIN deposits d ON c.contract_id = d.contract_id
                SET c.deposit_status = 'full'
                WHERE d.deposit_id = ?
            ");
            $stmt->execute([$deposit_id]);
            
            $success_message = "ยืนยันการรับเงินมัดจำเรียบร้อยแล้ว";
        }
        
        $pdo->commit();
        
    } catch(PDOException $e) {
        $pdo->rollBack();
        $error_message = "เกิดข้อผิดพลาด: " . $e->getMessage();
    }
}

// ดึงข้อมูลเงินมัดจำทั้งหมด
$search = isset($_GET['search']) ? $_GET['search'] : '';
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';

$sql = "SELECT * FROM deposit_summary WHERE 1=1";
$params = [];

if (!empty($search)) {
    $sql .= " AND (room_number LIKE ? OR tenant_name LIKE ? OR phone LIKE ?)";
    $search_term = "%$search%";
    $params = array_merge($params, [$search_term, $search_term, $search_term]);
}

if (!empty($status_filter)) {
    $sql .= " AND deposit_status = ?";
    $params[] = $status_filter;
}

$sql .= " ORDER BY deposit_date DESC";

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $deposits = $stmt->fetchAll();
    
    // สถิติ
    $stats_sql = "SELECT 
        COUNT(*) as total_deposits,
        SUM(deposit_amount) as total_amount,
        SUM(CASE WHEN deposit_status = 'received' THEN deposit_amount ELSE 0 END) as received_amount,
        SUM(refund_amount) as total_refunded,
        SUM(deduction_amount) as total_deducted,
        COUNT(CASE WHEN deposit_status = 'pending' THEN 1 END) as pending_count
    FROM deposits";
    
    $stmt = $pdo->query($stats_sql);
    $stats = $stmt->fetch();
    
} catch(PDOException $e) {
    $error_message = "เกิดข้อผิดพลาดในการดึงข้อมูล: " . $e->getMessage();
    $deposits = [];
    $stats = ['total_deposits' => 0, 'total_amount' => 0, 'received_amount' => 0, 'total_refunded' => 0, 'total_deducted' => 0, 'pending_count' => 0];
}

include 'includes/navbar.php';
?>

<div class="container-fluid mt-4">
    <div class="row">
        <div class="col-12">
            <!-- หัวข้อหน้า -->
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2 class="mb-0">
                    <i class="bi bi-piggy-bank"></i>
                    จัดการเงินมัดจำ
                </h2>
                <div class="btn-group">
                    <a href="contracts.php" class="btn btn-outline-secondary">
                        <i class="bi bi-file-text"></i>
                        ดูสัญญา
                    </a>
                    <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#addDepositModal">
                        <i class="bi bi-plus-circle"></i>
                        เพิ่มเงินมัดจำ
                    </button>
                </div>
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

            <!-- สถิติ -->
            <div class="row mb-4">
                <div class="col-md-2">
                    <div class="card bg-primary text-white">
                        <div class="card-body">
                            <div class="d-flex justify-content-between">
                                <div>
                                    <h6 class="card-title">ทั้งหมด</h6>
                                    <h4><?php echo number_format($stats['total_deposits']); ?></h4>
                                </div>
                                <div>
                                    <i class="bi bi-piggy-bank fs-1"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="card bg-success text-white">
                        <div class="card-body">
                            <div class="d-flex justify-content-between">
                                <div>
                                    <h6 class="card-title">ยอดรับแล้ว</h6>
                                    <h4><?php echo number_format($stats['received_amount']); ?></h4>
                                </div>
                                <div>
                                    <i class="bi bi-check-circle fs-1"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="card bg-info text-white">
                        <div class="card-body">
                            <div class="d-flex justify-content-between">
                                <div>
                                    <h6 class="card-title">คืนแล้ว</h6>
                                    <h4><?php echo number_format($stats['total_refunded']); ?></h4>
                                </div>
                                <div>
                                    <i class="bi bi-arrow-return-left fs-1"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="card bg-warning text-white">
                        <div class="card-body">
                            <div class="d-flex justify-content-between">
                                <div>
                                    <h6 class="card-title">หักแล้ว</h6>
                                    <h4><?php echo number_format($stats['total_deducted']); ?></h4>
                                </div>
                                <div>
                                    <i class="bi bi-dash-circle fs-1"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="card bg-danger text-white">
                        <div class="card-body">
                            <div class="d-flex justify-content-between">
                                <div>
                                    <h6 class="card-title">รอดำเนินการ</h6>
                                    <h4><?php echo number_format($stats['pending_count']); ?></h4>
                                </div>
                                <div>
                                    <i class="bi bi-hourglass-split fs-1"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="card bg-secondary text-white">
                        <div class="card-body">
                            <div class="d-flex justify-content-between">
                                <div>
                                    <h6 class="card-title">คงเหลือ</h6>
                                    <h4><?php echo number_format($stats['received_amount'] - $stats['total_refunded'] - $stats['total_deducted']); ?></h4>
                                </div>
                                <div>
                                    <i class="bi bi-wallet2 fs-1"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ฟิลเตอร์และค้นหา -->
            <div class="card mb-4">
                <div class="card-body">
                    <form method="GET" class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">ค้นหา</label>
                            <input type="text" class="form-control" name="search" 
                                   value="<?php echo htmlspecialchars($search); ?>" 
                                   placeholder="หมายเลขห้อง, ชื่อผู้เช่า, เบอร์โทร">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">สถานะ</label>
                            <select class="form-select" name="status">
                                <option value="">ทั้งหมด</option>
                                <option value="pending" <?php echo $status_filter == 'pending' ? 'selected' : ''; ?>>รอดำเนินการ</option>
                                <option value="received" <?php echo $status_filter == 'received' ? 'selected' : ''; ?>>รับแล้ว</option>
                                <option value="partial_refund" <?php echo $status_filter == 'partial_refund' ? 'selected' : ''; ?>>คืนบางส่วน</option>
                                <option value="fully_refunded" <?php echo $status_filter == 'fully_refunded' ? 'selected' : ''; ?>>คืนครบ</option>
                                <option value="forfeited" <?php echo $status_filter == 'forfeited' ? 'selected' : ''; ?>>ริบ</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">&nbsp;</label>
                            <div class="d-grid">
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-search"></i> ค้นหา
                                </button>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">&nbsp;</label>
                            <div class="d-grid">
                                <a href="deposits.php" class="btn btn-secondary">
                                    <i class="bi bi-x-circle"></i> ล้าง
                                </a>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <!-- ตารางข้อมูล -->
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title mb-0">รายการเงินมัดจำ (<?php echo count($deposits); ?> รายการ)</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead class="table-light">
                                <tr>
                                    <th>ห้อง</th>
                                    <th>ผู้เช่า</th>
                                    <th>จำนวนเงิน</th>
                                    <th>วันที่วาง</th>
                                    <th>วิธีชำระ</th>
                                    <th>สถานะ</th>
                                    <th>คืน/หัก</th>
                                    <th>คงเหลือ</th>
                                    <th>จัดการ</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($deposits)): ?>
                                    <tr>
                                        <td colspan="9" class="text-center py-4">
                                            <div class="text-muted">
                                                <i class="bi bi-piggy-bank fs-1 d-block mb-2"></i>
                                                ไม่มีข้อมูลเงินมัดจำ
                                            </div>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($deposits as $deposit): ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo htmlspecialchars($deposit['room_number']); ?></strong>
                                            </td>
                                            <td>
                                                <div>
                                                    <strong><?php echo htmlspecialchars($deposit['tenant_name']); ?></strong>
                                                    <br>
                                                    <small class="text-muted"><?php echo htmlspecialchars($deposit['phone']); ?></small>
                                                </div>
                                            </td>
                                            <td>
                                                <strong class="text-primary">
                                                    ฿<?php echo number_format($deposit['deposit_amount'], 2); ?>
                                                </strong>
                                            </td>
                                            <td>
                                                <?php echo date('d/m/Y', strtotime($deposit['deposit_date'])); ?>
                                            </td>
                                            <td>
                                                <?php
                                                $payment_methods = [
                                                    'cash' => 'เงินสด',
                                                    'bank_transfer' => 'โอนธนาคาร', 
                                                    'cheque' => 'เช็ค',
                                                    'credit_card' => 'บัตรเครดิต'
                                                ];
                                                echo $payment_methods[$deposit['payment_method']] ?? $deposit['payment_method'];
                                                ?>
                                            </td>
                                            <td>
                                                <?php
                                                $status_badges = [
                                                    'pending' => '<span class="badge bg-warning">รอดำเนินการ</span>',
                                                    'received' => '<span class="badge bg-success">รับแล้ว</span>',
                                                    'partial_refund' => '<span class="badge bg-info">คืนบางส่วน</span>',
                                                    'fully_refunded' => '<span class="badge bg-secondary">คืนครบ</span>',
                                                    'forfeited' => '<span class="badge bg-danger">ริบ</span>'
                                                ];
                                                echo $status_badges[$deposit['deposit_status']] ?? $deposit['deposit_status'];
                                                ?>
                                            </td>
                                            <td>
                                                <?php if ($deposit['refund_amount'] > 0 || $deposit['deduction_amount'] > 0): ?>
                                                    <div>
                                                        <?php if ($deposit['refund_amount'] > 0): ?>
                                                            <small class="text-success">
                                                                <i class="bi bi-arrow-return-left"></i>
                                                                ฿<?php echo number_format($deposit['refund_amount'], 2); ?>
                                                            </small>
                                                        <?php endif; ?>
                                                        <?php if ($deposit['deduction_amount'] > 0): ?>
                                                            <small class="text-warning d-block">
                                                                <i class="bi bi-dash-circle"></i>
                                                                ฿<?php echo number_format($deposit['deduction_amount'], 2); ?>
                                                            </small>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php else: ?>
                                                    <span class="text-muted">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <strong class="<?php echo $deposit['balance'] > 0 ? 'text-success' : 'text-muted'; ?>">
                                                    ฿<?php echo number_format($deposit['balance'], 2); ?>
                                                </strong>
                                            </td>
                                            <td>
                                                <div class="btn-group-vertical btn-group-sm">
                                                    <button class="btn btn-outline-primary btn-sm" 
                                                            onclick="viewDeposit(<?php echo $deposit['deposit_id']; ?>)"
                                                            title="ดูรายละเอียด">
                                                        <i class="bi bi-eye"></i>
                                                    </button>
                                                    <?php if ($deposit['deposit_status'] == 'pending'): ?>
                                                        <button class="btn btn-outline-success btn-sm" 
                                                                onclick="receiveDeposit(<?php echo $deposit['deposit_id']; ?>)"
                                                                title="ยืนยันรับเงิน">
                                                            <i class="bi bi-check-circle"></i>
                                                        </button>
                                                    <?php endif; ?>
                                                    <?php if (in_array($deposit['deposit_status'], ['received'])): ?>
                                                        <button class="btn btn-outline-warning btn-sm" 
                                                                onclick="refundDeposit(<?php echo $deposit['deposit_id']; ?>)"
                                                                title="คืนเงิน">
                                                            <i class="bi bi-arrow-return-left"></i>
                                                        </button>
                                                    <?php endif; ?>
                                                    <a href="deposit_documents.php?deposit_id=<?php echo $deposit['deposit_id']; ?>" 
                                                       class="btn btn-outline-info btn-sm" title="เอกสาร">
                                                        <i class="bi bi-file-earmark-text"></i>
                                                    </a>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal เพิ่มเงินมัดจำ -->
<div class="modal fade" id="addDepositModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">เพิ่มเงินมัดจำใหม่</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="add_deposit.php">
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6">
                            <label class="form-label">เลือกสัญญา</label>
                            <select class="form-select" name="contract_id" required>
                                <option value="">-- เลือกสัญญา --</option>
                                <?php
                                // ดึงสัญญาที่ยังไม่มีเงินมัดจำ
                                $contract_sql = "
                                    SELECT c.contract_id, r.room_number, CONCAT(t.first_name, ' ', t.last_name) as tenant_name
                                    FROM contracts c
                                    JOIN rooms r ON c.room_id = r.room_id
                                    JOIN tenants t ON c.tenant_id = t.tenant_id
                                    WHERE c.contract_status = 'active'
                                    AND NOT EXISTS (SELECT 1 FROM deposits d WHERE d.contract_id = c.contract_id)
                                    ORDER BY r.room_number
                                ";
                                $stmt = $pdo->query($contract_sql);
                                while ($contract = $stmt->fetch()):
                                ?>
                                    <option value="<?php echo $contract['contract_id']; ?>">
                                        ห้อง <?php echo $contract['room_number']; ?> - <?php echo $contract['tenant_name']; ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">จำนวนเงินมัดจำ</label>
                            <input type="number" class="form-control" name="deposit_amount" step="0.01" min="0" required>
                        </div>
                    </div>
                    <div class="row mt-3">
                        <div class="col-md-6">
                            <label class="form-label">วิธีการชำระ</label>
                            <select class="form-select" name="payment_method" required>
                                <option value="cash">เงินสด</option>
                                <option value="bank_transfer">โอนธนาคาร</option>
                                <option value="cheque">เช็ค</option>
                                <option value="credit_card">บัตรเครดิต</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">เลขที่ใบเสร็จ</label>
                            <input type="text" class="form-control" name="receipt_number" placeholder="ไม่จำเป็นต้องกรอก">
                        </div>
                    </div>
                    <div class="row mt-3">
                        <div class="col-md-6">
                            <label class="form-label">บัญชีธนาคาร (ถ้าโอน)</label>
                            <input type="text" class="form-control" name="bank_account" placeholder="เลขที่บัญชีที่โอนเข้า">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">วันที่วางเงิน</label>
                            <input type="date" class="form-control" name="deposit_date" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                    </div>
                    <div class="mt-3">
                        <label class="form-label">หมายเหตุ</label>
                        <textarea class="form-control" name="notes" rows="3" placeholder="หมายเหตุเพิ่มเติม"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ยกเลิก</button>
                    <button type="submit" name="add_deposit" class="btn btn-success">บันทึกเงินมัดจำ</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal ยืนยันรับเงิน -->
<div class="modal fade" id="receiveDepositModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">ยืนยันการรับเงินมัดจำ</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="deposit_id" id="receive_deposit_id">
                <input type="hidden" name="action" value="receive">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">วิธีการชำระ</label>
                        <select class="form-select" name="payment_method" required>
                            <option value="cash">เงินสด</option>
                            <option value="bank_transfer">โอนธนาคาร</option>
                            <option value="cheque">เช็ค</option>
                            <option value="credit_card">บัตรเครดิต</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">เลขที่ใบเสร็จ</label>
                        <input type="text" class="form-control" name="receipt_number" placeholder="ระบุเลขที่ใบเสร็จ">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">บัญชีธนาคาร (ถ้าโอน)</label>
                        <input type="text" class="form-control" name="bank_account" placeholder="เลขที่บัญชีที่โอนเข้า">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">หมายเหตุ</label>
                        <textarea class="form-control" name="notes" rows="3" placeholder="หมายเหตุเพิ่มเติม"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ยกเลิก</button>
                    <button type="submit" name="update_deposit" class="btn btn-success">ยืนยันรับเงิน</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal คืนเงิน -->
<div class="modal fade" id="refundDepositModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">คืนเงินมัดจำ</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="deposit_id" id="refund_deposit_id">
                <input type="hidden" name="action" value="refund">
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6">
                            <label class="form-label">จำนวนเงินที่คืน</label>
                            <input type="number" class="form-control" name="refund_amount" step="0.01" min="0" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">วิธีการคืนเงิน</label>
                            <select class="form-select" name="refund_method" required>
                                <option value="cash">เงินสด</option>
                                <option value="bank_transfer">โอนธนาคาร</option>
                                <option value="cheque">เช็ค</option>
                            </select>
                        </div>
                    </div>
                    <div class="row mt-3">
                        <div class="col-md-6">
                            <label class="form-label">จำนวนเงินที่หัก</label>
                            <input type="number" class="form-control" name="deduction_amount" step="0.01" min="0" value="0">
                        </div>
                        <div class="col-md-6">
                            <button type="button" class="btn btn-outline-info mt-4" onclick="openDamageCalculator()">
                                <i class="bi bi-calculator"></i> คำนวณค่าเสียหาย
                            </button>
                        </div>
                    </div>
                    <div class="mt-3">
                        <label class="form-label">เหตุผลการหักเงิน</label>
                        <textarea class="form-control" name="deduction_reason" rows="3" 
                                  placeholder="ระบุรายละเอียดค่าเสียหาย หรือค่าใช้จ่ายที่หัก"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ยกเลิก</button>
                    <button type="submit" name="update_deposit" class="btn btn-warning">บันทึกการคืนเงิน</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal ดูรายละเอียด -->
<div class="modal fade" id="viewDepositModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">รายละเอียดเงินมัดจำ</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="depositDetails">
                <!-- จะโหลดข้อมูลด้วย JavaScript -->
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ปิด</button>
            </div>
        </div>
    </div>
</div>

<script>
function receiveDeposit(depositId) {
    document.getElementById('receive_deposit_id').value = depositId;
    new bootstrap.Modal(document.getElementById('receiveDepositModal')).show();
}

function refundDeposit(depositId) {
    document.getElementById('refund_deposit_id').value = depositId;
    new bootstrap.Modal(document.getElementById('refundDepositModal')).show();
}

function viewDeposit(depositId) {
    // โหลดข้อมูลรายละเอียดด้วย AJAX
    fetch('ajax/get_deposit_details.php?id=' + depositId)
        .then(response => response.text())
        .then(data => {
            document.getElementById('depositDetails').innerHTML = data;
            new bootstrap.Modal(document.getElementById('viewDepositModal')).show();
        })
        .catch(error => {
            console.error('Error:', error);
            alert('เกิดข้อผิดพลาดในการโหลดข้อมูล');
        });
}

function openDamageCalculator() {
    // เปิดหน้าต่างคำนวณค่าเสียหาย (สามารถพัฒนาเป็น modal แยก)
    alert('ฟีเจอร์คำนวณค่าเสียหายจะพัฒนาในขั้นตอนถัดไป');
}
</script>

<?php include 'includes/footer.php'; ?>