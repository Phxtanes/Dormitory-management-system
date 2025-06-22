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
        // สร้าง WHERE clause ใหม่สำหรับสถิติ
        $stats_where = "";
        $stats_params = [];
        
        if (!empty($search)) {
            $stats_where = " JOIN invoices i ON p.invoice_id = i.invoice_id
                            JOIN contracts c ON i.contract_id = c.contract_id
                            JOIN rooms r ON c.room_id = r.room_id
                            JOIN tenants t ON c.tenant_id = t.tenant_id
                            WHERE (t.first_name LIKE ? OR t.last_name LIKE ? OR r.room_number LIKE ? OR p.payment_reference LIKE ?)";
            $stats_params = array_fill(0, 4, $search_term);
        }
        
        if (!empty($method_filter)) {
            if (empty($stats_where)) {
                $stats_where = " WHERE p.payment_method = ?";
            } else {
                $stats_where .= " AND p.payment_method = ?";
            }
            $stats_params[] = $method_filter;
        }
        
        if (!empty($date_from)) {
            if (empty($stats_where)) {
                $stats_where = " WHERE p.payment_date >= ?";
            } else {
                $stats_where .= " AND p.payment_date >= ?";
            }
            $stats_params[] = $date_from;
        }
        
        if (!empty($date_to)) {
            if (empty($stats_where)) {
                $stats_where = " WHERE p.payment_date <= ?";
            } else {
                $stats_where .= " AND p.payment_date <= ?";
            }
            $stats_params[] = $date_to;
        }
        
        if (!empty($invoice_filter)) {
            if (empty($stats_where)) {
                $stats_where = " WHERE p.invoice_id = ?";
            } else {
                $stats_where .= " AND p.invoice_id = ?";
            }
            $stats_params[] = $invoice_filter;
        }
        
        $stats_sql .= $stats_where;
        
        $stmt = $pdo->prepare($stats_sql);
        $stmt->execute($stats_params);
    } else {
        $stmt = $pdo->prepare($stats_sql);
        $stmt->execute();
    }
    
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
}
?>

<?php include 'includes/navbar.php'?>

<!-- แสดงข้อความสถานะ -->
<?php if (isset($success_message)): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="bi bi-check-circle me-2"></i><?php echo $success_message; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if (isset($error_message)): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="bi bi-exclamation-triangle me-2"></i><?php echo $error_message; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<!-- หัวข้อหน้า -->
<div class="d-flex justify-content-between align-items-center mb-4 mt-4">
    <h2><i class="bi bi-receipt text-success me-2"></i><?php echo $page_title; ?></h2>
</div>


<!-- สถิติสรุป -->
<?php if (!empty($payments)): ?>
    <div class="row mb-4">
        <div class="col-lg-3 col-md-6 mb-3">
            <div class="card bg-primary text-white">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h6 class="card-title">ยอดรวมทั้งหมด</h6>
                            <h4 class="mb-0"><?php echo formatCurrency($stats['total_amount'] ?? 0); ?></h4>
                        </div>
                        <div class="align-self-center">
                            <i class="bi bi-currency-dollar fs-2"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-lg-3 col-md-6 mb-3">
            <div class="card bg-success text-white">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h6 class="card-title">จำนวนรายการ</h6>
                            <h4 class="mb-0"><?php echo number_format($stats['total_payments'] ?? 0); ?> รายการ</h4>
                        </div>
                        <div class="align-self-center">
                            <i class="bi bi-receipt fs-2"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-lg-3 col-md-6 mb-3">
            <div class="card bg-info text-white">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h6 class="card-title">ค่าเฉลี่ยต่อรายการ</h6>
                            <h4 class="mb-0"><?php echo formatCurrency($stats['avg_amount'] ?? 0); ?></h4>
                        </div>
                        <div class="align-self-center">
                            <i class="bi bi-calculator fs-2"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-lg-3 col-md-6 mb-3">
            <div class="card bg-warning text-white">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h6 class="card-title">เงินสด</h6>
                            <h4 class="mb-0"><?php echo formatCurrency($stats['cash_amount'] ?? 0); ?></h4>
                        </div>
                        <div class="align-self-center">
                            <i class="bi bi-cash fs-2"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

<!-- ฟอร์มค้นหาและกรอง -->
<div class="card mb-4">
    <div class="card-header">
        <h5 class="card-title mb-0"><i class="bi bi-funnel me-2"></i>ค้นหาและกรอง</h5>
    </div>
    <div class="card-body">
        <form method="GET" class="row g-3">
            <div class="col-md-3">
                <label class="form-label">ค้นหา</label>
                <input type="text" class="form-control" name="search" 
                       value="<?php echo htmlspecialchars($search); ?>" 
                       placeholder="ชื่อผู้เช่า, เลขห้อง, เลขที่อ้างอิง">
            </div>
            
            <div class="col-md-2">
                <label class="form-label">วิธีชำระ</label>
                <select class="form-select" name="method">
                    <option value="">ทั้งหมด</option>
                    <option value="cash" <?php echo $method_filter == 'cash' ? 'selected' : ''; ?>>เงินสด</option>
                    <option value="bank_transfer" <?php echo $method_filter == 'bank_transfer' ? 'selected' : ''; ?>>โอนเงิน</option>
                    <option value="mobile_banking" <?php echo $method_filter == 'mobile_banking' ? 'selected' : ''; ?>>แอปธนาคาร</option>
                    <option value="other" <?php echo $method_filter == 'other' ? 'selected' : ''; ?>>อื่นๆ</option>
                </select>
            </div>
            
            <div class="col-md-2">
                <label class="form-label">วันที่เริ่มต้น</label>
                <input type="date" class="form-control" name="date_from" 
                       value="<?php echo htmlspecialchars($date_from); ?>">
            </div>
            
            <div class="col-md-2">
                <label class="form-label">วันที่สิ้นสุด</label>
                <input type="date" class="form-control" name="date_to" 
                       value="<?php echo htmlspecialchars($date_to); ?>">
            </div>
            
            <div class="col-md-2">
                <label class="form-label">เลขที่ใบแจ้งหนี้</label>
                <input type="number" class="form-control" name="invoice_id" 
                       value="<?php echo htmlspecialchars($invoice_filter); ?>" 
                       placeholder="ใส่เลข ID">
            </div>
            
            <div class="col-md-1">
                <label class="form-label">&nbsp;</label>
                <div class="d-grid">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-search"></i>
                    </button>
                </div>
            </div>
        </form>
        
        <?php if (!empty($search) || !empty($method_filter) || !empty($date_from) || !empty($date_to) || !empty($invoice_filter)): ?>
            <div class="mt-3">
                <a href="payments.php" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-x-circle me-1"></i>ล้างตัวกรอง
                </a>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- ตารางแสดงข้อมูล -->
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="card-title mb-0">รายการชำระเงิน</h5>
        <small class="text-muted">ทั้งหมด <?php echo count($payments); ?> รายการ</small>
    </div>
    <div class="card-body">
        <?php if (empty($payments)): ?>
            <div class="text-center py-4">
                <i class="bi bi-inbox display-1 text-muted"></i>
                <h5 class="text-muted mt-3">ไม่พบข้อมูลการชำระเงิน</h5>
                <p class="text-muted">ลองปรับเปลี่ยนเงื่อนไขการค้นหา</p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead class="table-light">
                        <tr>
                            <th width="10%">วันที่ชำระ</th>
                            <th width="12%">ใบแจ้งหนี้</th>
                            <th width="15%">ผู้เช่า</th>
                            <th width="8%">ห้อง</th>
                            <th width="12%">ยอดชำระ</th>
                            <th width="10%">วิธีชำระ</th>
                            <th width="12%">เลขที่อ้างอิง</th>
                            <th width="15%">หมายเหตุ</th>
                            <th width="6%">จัดการ</th>
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
                                            <small class="text-muted"><?php echo $payment['phone']; ?></small>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <span class="badge bg-secondary"><?php echo $payment['room_number']; ?></span>
                                    <br><small class="text-muted">ชั้น <?php echo $payment['floor_number']; ?></small>
                                </td>
                                <td>
                                    <div class="fw-bold text-success"><?php echo formatCurrency($payment['payment_amount']); ?></div>
                                </td>
                                <td>
                                    <?php
                                    $method_labels = [
                                        'cash' => '<span class="badge bg-success">เงินสด</span>',
                                        'bank_transfer' => '<span class="badge bg-primary">โอนเงิน</span>',
                                        'mobile_banking' => '<span class="badge bg-info">แอปธนาคาร</span>',
                                        'other' => '<span class="badge bg-secondary">อื่นๆ</span>'
                                    ];
                                    echo $method_labels[$payment['payment_method']] ?? '<span class="badge bg-secondary">ไม่ระบุ</span>';
                                    ?>
                                </td>
                                <td>
                                    <span class="font-monospace"><?php echo $payment['payment_reference'] ?: '-'; ?></span>
                                </td>
                                <td>
                                    <small><?php echo $payment['notes'] ? htmlspecialchars($payment['notes']) : '-'; ?></small>
                                </td>
                                <td>
                                    <div class="btn-group" role="group">
                                        <a href="payments.php?delete=<?php echo $payment['payment_id']; ?>" 
                                           class="btn btn-outline-danger btn-sm" 
                                           data-bs-toggle="tooltip" 
                                           title="ลบ"
                                           onclick="return confirm('ต้องการลบประวัติการชำระนี้หรือไม่?')">
                                            <i class="bi bi-trash"></i>
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
                        <div class="border-end">
                            <h6 class="text-muted mb-1">จำนวนรายการ</h6>
                            <h5 class="text-primary"><?php echo number_format(count($payments)); ?></h5>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="border-end">
                            <h6 class="text-muted mb-1">ยอดรวม</h6>
                            <h5 class="text-success"><?php echo formatCurrency($stats['total_amount'] ?? 0); ?></h5>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="border-end">
                            <h6 class="text-muted mb-1">เงินสด</h6>
                            <h5 class="text-warning"><?php echo formatCurrency($stats['cash_amount'] ?? 0); ?></h5>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <h6 class="text-muted mb-1">โอน/แอป</h6>
                        <h5 class="text-info"><?php echo formatCurrency(($stats['transfer_amount'] ?? 0) + ($stats['mobile_amount'] ?? 0)); ?></h5>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- กราฟรายได้รายเดือน -->
<?php if (!empty($monthly_data)): ?>
    <div class="card mt-4">
        <div class="card-header">
            <h5 class="card-title mb-0"><i class="bi bi-bar-chart me-2"></i>รายได้รายเดือน (6 เดือนล่าสุด)</h5>
        </div>
        <div class="card-body">
            <canvas id="monthlyChart" height="100"></canvas>
        </div>
    </div>
<?php endif; ?>

<?php require_once 'includes/footer.php'; ?>

<!-- Chart.js และสคริปต์กราฟ -->
<?php if (!empty($monthly_data)): ?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/3.9.1/chart.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const ctx = document.getElementById('monthlyChart').getContext('2d');
    
    const monthlyData = <?php echo json_encode(array_reverse($monthly_data)); ?>;
    
    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: monthlyData.map(item => {
                const [year, month] = item.month.split('-');
                const monthNames = ['ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.',
                                   'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];
                return monthNames[parseInt(month) - 1] + ' ' + year;
            }),
            datasets: [{
                label: 'รายได้ (บาท)',
                data: monthlyData.map(item => parseFloat(item.total_amount || 0)),
                backgroundColor: 'rgba(54, 162, 235, 0.8)',
                borderColor: 'rgba(54, 162, 235, 1)',
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
            }
        }
    });
});
</script>
<?php endif; ?>

<script>
// เปิดใช้งาน tooltips
document.addEventListener('DOMContentLoaded', function() {
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });
});
</script>