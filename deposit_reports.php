<?php
$page_title = "รายงานเงินมัดจำ";
require_once 'includes/header.php';

$success_message = '';
$error_message = '';

// กำหนดค่าเริ่มต้น
$report_type = isset($_GET['type']) ? $_GET['type'] : 'monthly';
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-01'); // วันแรกของเดือน
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-t'); // วันสุดท้ายของเดือน
$export_format = isset($_GET['export']) ? $_GET['export'] : '';

try {
    // สถิติภาพรวม
    $overview_sql = "
        SELECT 
            COUNT(*) as total_deposits,
            SUM(deposit_amount) as total_amount,
            SUM(CASE WHEN deposit_status = 'received' THEN deposit_amount ELSE 0 END) as received_amount,
            SUM(refund_amount) as total_refunded,
            SUM(deduction_amount) as total_deducted,
            COUNT(CASE WHEN deposit_status = 'pending' THEN 1 END) as pending_count,
            COUNT(CASE WHEN deposit_status = 'received' THEN 1 END) as received_count,
            COUNT(CASE WHEN deposit_status IN ('partial_refund', 'fully_refunded') THEN 1 END) as refunded_count,
            AVG(deposit_amount) as average_deposit
        FROM deposits 
        WHERE deposit_date BETWEEN ? AND ?
    ";
    $stmt = $pdo->prepare($overview_sql);
    $stmt->execute([$start_date, $end_date]);
    $overview = $stmt->fetch();
    
    // ข้อมูลตามประเภทรายงาน
    if ($report_type == 'monthly') {
        // รายงานรายเดือน
        $report_sql = "
            SELECT 
                DATE_FORMAT(deposit_date, '%Y-%m') as period,
                DATE_FORMAT(deposit_date, '%M %Y') as period_name,
                COUNT(*) as deposits_count,
                SUM(deposit_amount) as total_deposits,
                SUM(refund_amount) as total_refunds,
                SUM(deduction_amount) as total_deductions,
                SUM(deposit_amount - IFNULL(refund_amount, 0) - IFNULL(deduction_amount, 0)) as net_balance,
                AVG(deposit_amount) as avg_deposit
            FROM deposits 
            WHERE deposit_date BETWEEN ? AND ?
            GROUP BY DATE_FORMAT(deposit_date, '%Y-%m')
            ORDER BY period DESC
        ";
    } elseif ($report_type == 'status') {
        // รายงานตามสถานะ
        $report_sql = "
            SELECT 
                deposit_status as period,
                CASE deposit_status
                    WHEN 'pending' THEN 'รอดำเนินการ'
                    WHEN 'received' THEN 'รับแล้ว'
                    WHEN 'partial_refund' THEN 'คืนบางส่วน'
                    WHEN 'fully_refunded' THEN 'คืนครบ'
                    WHEN 'forfeited' THEN 'ริบ'
                    ELSE deposit_status
                END as period_name,
                COUNT(*) as deposits_count,
                SUM(deposit_amount) as total_deposits,
                SUM(refund_amount) as total_refunds,
                SUM(deduction_amount) as total_deductions,
                SUM(deposit_amount - IFNULL(refund_amount, 0) - IFNULL(deduction_amount, 0)) as net_balance,
                AVG(deposit_amount) as avg_deposit
            FROM deposits 
            WHERE deposit_date BETWEEN ? AND ?
            GROUP BY deposit_status
            ORDER BY deposits_count DESC
        ";
    } elseif ($report_type == 'room_type') {
        // รายงานตามประเภทห้อง
        $report_sql = "
            SELECT 
                r.room_type as period,
                CASE r.room_type
                    WHEN 'single' THEN 'ห้องเดี่ยว'
                    WHEN 'double' THEN 'ห้องคู่'
                    WHEN 'triple' THEN 'ห้องสาม'
                    ELSE r.room_type
                END as period_name,
                COUNT(*) as deposits_count,
                SUM(d.deposit_amount) as total_deposits,
                SUM(d.refund_amount) as total_refunds,
                SUM(d.deduction_amount) as total_deductions,
                SUM(d.deposit_amount - IFNULL(d.refund_amount, 0) - IFNULL(d.deduction_amount, 0)) as net_balance,
                AVG(d.deposit_amount) as avg_deposit
            FROM deposits d
            JOIN contracts c ON d.contract_id = c.contract_id
            JOIN rooms r ON c.room_id = r.room_id
            WHERE d.deposit_date BETWEEN ? AND ?
            GROUP BY r.room_type
            ORDER BY deposits_count DESC
        ";
    } else {
        // รายงานรายวัน
        $report_sql = "
            SELECT 
                DATE(deposit_date) as period,
                DATE_FORMAT(deposit_date, '%d/%m/%Y') as period_name,
                COUNT(*) as deposits_count,
                SUM(deposit_amount) as total_deposits,
                SUM(refund_amount) as total_refunds,
                SUM(deduction_amount) as total_deductions,
                SUM(deposit_amount - IFNULL(refund_amount, 0) - IFNULL(deduction_amount, 0)) as net_balance,
                AVG(deposit_amount) as avg_deposit
            FROM deposits 
            WHERE deposit_date BETWEEN ? AND ?
            GROUP BY DATE(deposit_date)
            ORDER BY period DESC
        ";
    }
    
    $stmt = $pdo->prepare($report_sql);
    $stmt->execute([$start_date, $end_date]);
    $report_data = $stmt->fetchAll();
    
    // ข้อมูลรายละเอียด
    $detail_sql = "
        SELECT d.*, 
               CONCAT(t.first_name, ' ', t.last_name) as tenant_name,
               r.room_number, r.room_type,
               c.contract_start, c.contract_end,
               (d.deposit_amount - IFNULL(d.refund_amount, 0) - IFNULL(d.deduction_amount, 0)) as balance
        FROM deposits d
        JOIN contracts c ON d.contract_id = c.contract_id
        JOIN rooms r ON c.room_id = r.room_id
        JOIN tenants t ON c.tenant_id = t.tenant_id
        WHERE d.deposit_date BETWEEN ? AND ?
        ORDER BY d.deposit_date DESC
    ";
    $stmt = $pdo->prepare($detail_sql);
    $stmt->execute([$start_date, $end_date]);
    $detail_data = $stmt->fetchAll();
    
    // ข้อมูลกราฟ
    $chart_sql = "
        SELECT 
            DATE_FORMAT(deposit_date, '%Y-%m') as month,
            SUM(deposit_amount) as deposits,
            SUM(refund_amount) as refunds,
            SUM(deduction_amount) as deductions
        FROM deposits 
        WHERE deposit_date >= DATE_SUB(?, INTERVAL 12 MONTH)
        GROUP BY DATE_FORMAT(deposit_date, '%Y-%m')
        ORDER BY month ASC
    ";
    $stmt = $pdo->prepare($chart_sql);
    $stmt->execute([$end_date]);
    $chart_data = $stmt->fetchAll();
    
} catch(PDOException $e) {
    $error_message = "เกิดข้อผิดพลาดในการดึงข้อมูล: " . $e->getMessage();
    $overview = [];
    $report_data = [];
    $detail_data = [];
    $chart_data = [];
}

// ส่งออกข้อมูล
if ($export_format == 'excel') {
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="deposit_report_' . date('Y-m-d') . '.xls"');
    
    echo "<table border='1'>";
    echo "<tr><th>วันที่</th><th>ห้อง</th><th>ผู้เช่า</th><th>เงินมัดจำ</th><th>คืนเงิน</th><th>หักเงิน</th><th>คงเหลือ</th><th>สถานะ</th></tr>";
    
    foreach ($detail_data as $row) {
        echo "<tr>";
        echo "<td>" . date('d/m/Y', strtotime($row['deposit_date'])) . "</td>";
        echo "<td>" . $row['room_number'] . "</td>";
        echo "<td>" . $row['tenant_name'] . "</td>";
        echo "<td>" . number_format($row['deposit_amount'], 2) . "</td>";
        echo "<td>" . number_format($row['refund_amount'], 2) . "</td>";
        echo "<td>" . number_format($row['deduction_amount'], 2) . "</td>";
        echo "<td>" . number_format($row['balance'], 2) . "</td>";
        echo "<td>" . $row['deposit_status'] . "</td>";
        echo "</tr>";
    }
    echo "</table>";
    exit;
}

include 'includes/navbar.php';
?>

<div class="container-fluid mt-4">
    <div class="row">
        <div class="col-12">
            <!-- หัวข้อหน้า -->
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2 class="mb-0">
                    <i class="bi bi-graph-up"></i>
                    รายงานเงินมัดจำ
                </h2>
                <div class="btn-group">
                    <a href="deposits.php" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left"></i>
                        กลับ
                    </a>
                    <button class="btn btn-success" onclick="exportExcel()">
                        <i class="bi bi-file-excel"></i>
                        ส่งออก Excel
                    </button>
                    <button class="btn btn-primary" onclick="printReport()">
                        <i class="bi bi-printer"></i>
                        พิมพ์รายงาน
                    </button>
                </div>
            </div>

            <!-- แสดงข้อความแจ้งเตือน -->
            <?php if (!empty($error_message)): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="bi bi-exclamation-triangle"></i>
                    <?php echo $error_message; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <!-- ฟิลเตอร์รายงาน -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="card-title mb-0">
                        <i class="bi bi-filter"></i>
                        ตัวเลือกรายงาน
                    </h5>
                </div>
                <div class="card-body">
                    <form method="GET" class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label">ประเภทรายงาน</label>
                            <select class="form-select" name="type">
                                <option value="monthly" <?php echo $report_type == 'monthly' ? 'selected' : ''; ?>>รายเดือน</option>
                                <option value="daily" <?php echo $report_type == 'daily' ? 'selected' : ''; ?>>รายวัน</option>
                                <option value="status" <?php echo $report_type == 'status' ? 'selected' : ''; ?>>ตามสถานะ</option>
                                <option value="room_type" <?php echo $report_type == 'room_type' ? 'selected' : ''; ?>>ตามประเภทห้อง</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">วันที่เริ่มต้น</label>
                            <input type="date" class="form-control" name="start_date" value="<?php echo $start_date; ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">วันที่สิ้นสุด</label>
                            <input type="date" class="form-control" name="end_date" value="<?php echo $end_date; ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">&nbsp;</label>
                            <div class="d-grid">
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-search"></i> สร้างรายงาน
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <!-- สถิติภาพรวม -->
            <div class="row mb-4">
                <div class="col-md-2">
                    <div class="card bg-primary text-white">
                        <div class="card-body text-center">
                            <h4><?php echo number_format($overview['total_deposits'] ?? 0); ?></h4>
                            <small>จำนวนทั้งหมด</small>
                        </div>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="card bg-success text-white">
                        <div class="card-body text-center">
                            <h4>฿<?php echo number_format($overview['received_amount'] ?? 0); ?></h4>
                            <small>ยอดรับแล้ว</small>
                        </div>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="card bg-info text-white">
                        <div class="card-body text-center">
                            <h4>฿<?php echo number_format($overview['total_refunded'] ?? 0); ?></h4>
                            <small>ยอดคืนแล้ว</small>
                        </div>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="card bg-warning text-white">
                        <div class="card-body text-center">
                            <h4>฿<?php echo number_format($overview['total_deducted'] ?? 0); ?></h4>
                            <small>ยอดหักแล้ว</small>
                        </div>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="card bg-secondary text-white">
                        <div class="card-body text-center">
                            <h4>฿<?php echo number_format(($overview['received_amount'] ?? 0) - ($overview['total_refunded'] ?? 0) - ($overview['total_deducted'] ?? 0)); ?></h4>
                            <small>ยอดคงเหลือ</small>
                        </div>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="card bg-dark text-white">
                        <div class="card-body text-center">
                            <h4>฿<?php echo number_format($overview['average_deposit'] ?? 0); ?></h4>
                            <small>เฉลี่ยต่อรายการ</small>
                        </div>
                    </div>
                </div>
            </div>

            <!-- กราฟแสดงแนวโน้ม -->
            <div class="row mb-4">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="card-title mb-0">
                                <i class="bi bi-bar-chart"></i>
                                แนวโน้มเงินมัดจำ 12 เดือนย้อนหลัง
                            </h5>
                        </div>
                        <div class="card-body">
                            <canvas id="depositChart" height="100"></canvas>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ตารางสรุปข้อมูล -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="card-title mb-0">
                        <i class="bi bi-table"></i>
                        สรุปข้อมูล<?php 
                        echo $report_type == 'monthly' ? 'รายเดือน' : 
                             ($report_type == 'daily' ? 'รายวัน' : 
                              ($report_type == 'status' ? 'ตามสถานะ' : 'ตามประเภทห้อง')); 
                        ?>
                    </h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead class="table-light">
                                <tr>
                                    <th>ช่วงเวลา/หมวดหมู่</th>
                                    <th class="text-end">จำนวน</th>
                                    <th class="text-end">ยอดเงินมัดจำ</th>
                                    <th class="text-end">ยอดคืนเงิน</th>
                                    <th class="text-end">ยอดหักเงิน</th>
                                    <th class="text-end">ยอดสุทธิ</th>
                                    <th class="text-end">เฉลี่ย</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($report_data)): ?>
                                    <tr>
                                        <td colspan="7" class="text-center py-4">
                                            <div class="text-muted">
                                                <i class="bi bi-inbox fs-1 d-block mb-2"></i>
                                                ไม่มีข้อมูลในช่วงเวลาที่เลือก
                                            </div>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php 
                                    $total_count = 0;
                                    $total_deposits = 0;
                                    $total_refunds = 0;
                                    $total_deductions = 0;
                                    $total_net = 0;
                                    ?>
                                    <?php foreach ($report_data as $row): ?>
                                        <?php
                                        $total_count += $row['deposits_count'];
                                        $total_deposits += $row['total_deposits'];
                                        $total_refunds += $row['total_refunds'];
                                        $total_deductions += $row['total_deductions'];
                                        $total_net += $row['net_balance'];
                                        ?>
                                        <tr>
                                            <td><strong><?php echo htmlspecialchars($row['period_name']); ?></strong></td>
                                            <td class="text-end"><?php echo number_format($row['deposits_count']); ?></td>
                                            <td class="text-end text-primary">฿<?php echo number_format($row['total_deposits'], 2); ?></td>
                                            <td class="text-end text-info">฿<?php echo number_format($row['total_refunds'], 2); ?></td>
                                            <td class="text-end text-warning">฿<?php echo number_format($row['total_deductions'], 2); ?></td>
                                            <td class="text-end text-success">฿<?php echo number_format($row['net_balance'], 2); ?></td>
                                            <td class="text-end">฿<?php echo number_format($row['avg_deposit'], 2); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <!-- แถวรวม -->
                                    <tr class="table-warning">
                                        <td><strong>รวมทั้งหมด</strong></td>
                                        <td class="text-end"><strong><?php echo number_format($total_count); ?></strong></td>
                                        <td class="text-end"><strong>฿<?php echo number_format($total_deposits, 2); ?></strong></td>
                                        <td class="text-end"><strong>฿<?php echo number_format($total_refunds, 2); ?></strong></td>
                                        <td class="text-end"><strong>฿<?php echo number_format($total_deductions, 2); ?></strong></td>
                                        <td class="text-end"><strong>฿<?php echo number_format($total_net, 2); ?></strong></td>
                                        <td class="text-end"><strong>฿<?php echo $total_count > 0 ? number_format($total_deposits / $total_count, 2) : '0.00'; ?></strong></td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- ตารางรายละเอียด -->
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title mb-0">
                        <i class="bi bi-list-ul"></i>
                        รายละเอียดเงินมัดจำ (<?php echo count($detail_data); ?> รายการ)
                    </h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-sm table-hover">
                            <thead class="table-light">
                                <tr>
                                    <th>วันที่</th>
                                    <th>ห้อง</th>
                                    <th>ผู้เช่า</th>
                                    <th class="text-end">เงินมัดจำ</th>
                                    <th class="text-end">คืนเงิน</th>
                                    <th class="text-end">หักเงิน</th>
                                    <th class="text-end">คงเหลือ</th>
                                    <th>สถานะ</th>
                                    <th>วิธีชำระ</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($detail_data)): ?>
                                    <tr>
                                        <td colspan="9" class="text-center py-4">
                                            <div class="text-muted">ไม่มีข้อมูลรายละเอียด</div>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($detail_data as $row): ?>
                                        <tr>
                                            <td><?php echo date('d/m/Y', strtotime($row['deposit_date'])); ?></td>
                                            <td><?php echo htmlspecialchars($row['room_number']); ?></td>
                                            <td>
                                                <div><?php echo htmlspecialchars($row['tenant_name']); ?></div>
                                                <small class="text-muted"><?php echo ucfirst($row['room_type']); ?></small>
                                            </td>
                                            <td class="text-end text-primary">฿<?php echo number_format($row['deposit_amount'], 2); ?></td>
                                            <td class="text-end text-info">฿<?php echo number_format($row['refund_amount'], 2); ?></td>
                                            <td class="text-end text-warning">฿<?php echo number_format($row['deduction_amount'], 2); ?></td>
                                            <td class="text-end text-success">฿<?php echo number_format($row['balance'], 2); ?></td>
                                            <td>
                                                <?php
                                                $status_badges = [
                                                    'pending' => '<span class="badge bg-warning">รอดำเนินการ</span>',
                                                    'received' => '<span class="badge bg-success">รับแล้ว</span>',
                                                    'partial_refund' => '<span class="badge bg-info">คืนบางส่วน</span>',
                                                    'fully_refunded' => '<span class="badge bg-secondary">คืนครบ</span>',
                                                    'forfeited' => '<span class="badge bg-danger">ริบ</span>'
                                                ];
                                                echo $status_badges[$row['deposit_status']] ?? $row['deposit_status'];
                                                ?>
                                            </td>
                                            <td>
                                                <?php
                                                $payment_methods = [
                                                    'cash' => 'เงินสด',
                                                    'bank_transfer' => 'โอนธนาคาร',
                                                    'cheque' => 'เช็ค',
                                                    'credit_card' => 'บัตรเครดิต'
                                                ];
                                                echo $payment_methods[$row['payment_method']] ?? $row['payment_method'];
                                                ?>
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

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
// สร้างกราฟ
const ctx = document.getElementById('depositChart').getContext('2d');
const chartData = <?php echo json_encode($chart_data); ?>;

const labels = chartData.map(item => item.month);
const deposits = chartData.map(item => parseFloat(item.deposits) || 0);
const refunds = chartData.map(item => parseFloat(item.refunds) || 0);
const deductions = chartData.map(item => parseFloat(item.deductions) || 0);

new Chart(ctx, {
    type: 'line',
    data: {
        labels: labels,
        datasets: [{
            label: 'เงินมัดจำ',
            data: deposits,
            borderColor: 'rgb(54, 162, 235)',
            backgroundColor: 'rgba(54, 162, 235, 0.2)',
            tension: 0.1
        }, {
            label: 'คืนเงิน',
            data: refunds,
            borderColor: 'rgb(75, 192, 192)',
            backgroundColor: 'rgba(75, 192, 192, 0.2)',
            tension: 0.1
        }, {
            label: 'หักเงิน',
            data: deductions,
            borderColor: 'rgb(255, 205, 86)',
            backgroundColor: 'rgba(255, 205, 86, 0.2)',
            tension: 0.1
        }]
    },
    options: {
        responsive: true,
        plugins: {
            title: {
                display: false
            },
            legend: {
                position: 'top',
            }
        },
        scales: {
            y: {
                beginAtZero: true,
                ticks: {
                    callback: function(value) {
                        return '฿' + value.toLocaleString();
                    }
                }
            }
        }
    }
});

function exportExcel() {
    const currentUrl = new URL(window.location);
    currentUrl.searchParams.set('export', 'excel');
    window.location.href = currentUrl.toString();
}

function printReport() {
    window.print();
}

// CSS สำหรับการพิมพ์
const style = document.createElement('style');
style.textContent = `
    @media print {
        .btn, .navbar, .breadcrumb, .alert { display: none !important; }
        .card { border: none !important; box-shadow: none !important; }
        .table { font-size: 12px; }
        .container-fluid { margin: 0 !important; padding: 0 !important; }
    }
`;
document.head.appendChild(style);
</script>

<?php include 'includes/footer.php'; ?>