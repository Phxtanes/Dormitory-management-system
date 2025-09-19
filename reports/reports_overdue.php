<?php
$page_title = "รายงานค้างชำระ";
require_once 'includes/header.php';

// ตั้งค่าค่าเริ่มต้นสำหรับการกรอง
$overdue_days = isset($_GET['overdue_days']) ? $_GET['overdue_days'] : '7';
$room_filter = isset($_GET['room_id']) ? $_GET['room_id'] : '';
$tenant_filter = isset($_GET['tenant_id']) ? $_GET['tenant_id'] : '';
$amount_min = isset($_GET['amount_min']) ? $_GET['amount_min'] : '';
$amount_max = isset($_GET['amount_max']) ? $_GET['amount_max'] : '';
$sort_by = isset($_GET['sort_by']) ? $_GET['sort_by'] : 'overdue_days';
$sort_order = isset($_GET['sort_order']) ? $_GET['sort_order'] : 'DESC';
$export = isset($_GET['export']) ? $_GET['export'] : '';

try {
    // สร้าง WHERE clause สำหรับการกรอง
    $where_conditions = ["i.invoice_status IN ('pending', 'overdue')", "i.due_date < CURDATE()"];
    $params = [];
    
    // กรองตามจำนวนวันค้างชำระ
    if (!empty($overdue_days) && $overdue_days != 'all') {
        $where_conditions[] = "DATEDIFF(CURDATE(), i.due_date) >= ?";
        $params[] = $overdue_days;
    }
    
    // กรองตามห้อง
    if (!empty($room_filter)) {
        $where_conditions[] = "r.room_id = ?";
        $params[] = $room_filter;
    }
    
    // กรองตามผู้เช่า
    if (!empty($tenant_filter)) {
        $where_conditions[] = "t.tenant_id = ?";
        $params[] = $tenant_filter;
    }
    
    // กรองตามยอดเงิน
    if (!empty($amount_min)) {
        $where_conditions[] = "i.total_amount >= ?";
        $params[] = $amount_min;
    }
    
    if (!empty($amount_max)) {
        $where_conditions[] = "i.total_amount <= ?";
        $params[] = $amount_max;
    }
    
    $where_clause = 'WHERE ' . implode(' AND ', $where_conditions);
    
    // กำหนดการเรียงลำดับ
    $valid_sorts = ['overdue_days', 'total_amount', 'due_date', 'room_number', 'tenant_name'];
    $sort_field = in_array($sort_by, $valid_sorts) ? $sort_by : 'overdue_days';
    $order_direction = ($sort_order == 'ASC') ? 'ASC' : 'DESC';
    
    switch($sort_field) {
        case 'overdue_days':
            $order_clause = "ORDER BY overdue_days $order_direction";
            break;
        case 'total_amount':
            $order_clause = "ORDER BY i.total_amount $order_direction";
            break;
        case 'due_date':
            $order_clause = "ORDER BY i.due_date $order_direction";
            break;
        case 'room_number':
            $order_clause = "ORDER BY CAST(r.room_number AS UNSIGNED) $order_direction";
            break;
        case 'tenant_name':
            $order_clause = "ORDER BY t.first_name $order_direction, t.last_name $order_direction";
            break;
        default:
            $order_clause = "ORDER BY overdue_days DESC";
            break;
    }
    
    // ดึงข้อมูลใบแจ้งหนี้ค้างชำระ
    $overdue_sql = "SELECT 
        i.invoice_id,
        i.invoice_month,
        i.total_amount,
        i.due_date,
        i.created_at,
        DATEDIFF(CURDATE(), i.due_date) as overdue_days,
        r.room_id,
        r.room_number,
        r.room_type,
        r.floor_number,
        t.tenant_id,
        CONCAT(t.first_name, ' ', t.last_name) as tenant_name,
        t.phone,
        t.email,
        c.contract_id,
        c.monthly_rent,
        c.contract_status,
        CONCAT('#INV-', LPAD(i.invoice_id, 6, '0')) as invoice_number,
        COALESCE(SUM(p.payment_amount), 0) as paid_amount,
        (i.total_amount - COALESCE(SUM(p.payment_amount), 0)) as remaining_amount
        FROM invoices i
        JOIN contracts c ON i.contract_id = c.contract_id
        JOIN rooms r ON c.room_id = r.room_id
        JOIN tenants t ON c.tenant_id = t.tenant_id
        LEFT JOIN payments p ON i.invoice_id = p.invoice_id
        $where_clause
        GROUP BY i.invoice_id
        $order_clause";
    
    $stmt = $pdo->prepare($overdue_sql);
    $stmt->execute($params);
    $overdue_invoices = $stmt->fetchAll();
    
    // ดึงข้อมูลสถิติรวม
    $summary_sql = "SELECT 
        COUNT(DISTINCT i.invoice_id) as total_overdue,
        SUM(i.total_amount - COALESCE(p.total_paid, 0)) as total_overdue_amount,
        AVG(DATEDIFF(CURDATE(), i.due_date)) as avg_overdue_days,
        MAX(DATEDIFF(CURDATE(), i.due_date)) as max_overdue_days,
        COUNT(DISTINCT c.tenant_id) as affected_tenants,
        COUNT(DISTINCT r.room_id) as affected_rooms
        FROM invoices i
        JOIN contracts c ON i.contract_id = c.contract_id
        JOIN rooms r ON c.room_id = r.room_id
        LEFT JOIN (
            SELECT invoice_id, SUM(payment_amount) as total_paid 
            FROM payments 
            GROUP BY invoice_id
        ) p ON i.invoice_id = p.invoice_id
        $where_clause";
    
    $stmt = $pdo->prepare($summary_sql);
    $stmt->execute($params);
    $summary = $stmt->fetch();
    
    // ดึงข้อมูลค้างชำระตามช่วงวัน
    $range_sql = "SELECT 
        CASE 
            WHEN DATEDIFF(CURDATE(), i.due_date) <= 7 THEN '1-7 วัน'
            WHEN DATEDIFF(CURDATE(), i.due_date) <= 30 THEN '8-30 วัน'
            WHEN DATEDIFF(CURDATE(), i.due_date) <= 60 THEN '31-60 วัน'
            WHEN DATEDIFF(CURDATE(), i.due_date) <= 90 THEN '61-90 วัน'
            ELSE 'มากกว่า 90 วัน'
        END as overdue_range,
        COUNT(i.invoice_id) as invoice_count,
        SUM(i.total_amount - COALESCE(p.total_paid, 0)) as range_amount
        FROM invoices i
        JOIN contracts c ON i.contract_id = c.contract_id
        LEFT JOIN (
            SELECT invoice_id, SUM(payment_amount) as total_paid 
            FROM payments 
            GROUP BY invoice_id
        ) p ON i.invoice_id = p.invoice_id
        WHERE i.invoice_status IN ('pending', 'overdue') AND i.due_date < CURDATE()
        GROUP BY 
        CASE 
            WHEN DATEDIFF(CURDATE(), i.due_date) <= 7 THEN '1-7 วัน'
            WHEN DATEDIFF(CURDATE(), i.due_date) <= 30 THEN '8-30 วัน'
            WHEN DATEDIFF(CURDATE(), i.due_date) <= 60 THEN '31-60 วัน'
            WHEN DATEDIFF(CURDATE(), i.due_date) <= 90 THEN '61-90 วัน'
            ELSE 'มากกว่า 90 วัน'
        END
        ORDER BY MIN(DATEDIFF(CURDATE(), i.due_date))";
    
    $stmt = $pdo->query($range_sql);
    $overdue_ranges = $stmt->fetchAll();
    
    // ดึงข้อมูลผู้เช่าที่ค้างชำระมากที่สุด
    $top_tenants_sql = "SELECT 
        t.tenant_id,
        CONCAT(t.first_name, ' ', t.last_name) as tenant_name,
        t.phone,
        t.email,
        COUNT(i.invoice_id) as overdue_count,
        SUM(i.total_amount - COALESCE(p.total_paid, 0)) as total_overdue_amount,
        MAX(DATEDIFF(CURDATE(), i.due_date)) as max_overdue_days,
        GROUP_CONCAT(DISTINCT r.room_number ORDER BY r.room_number) as rooms
        FROM invoices i
        JOIN contracts c ON i.contract_id = c.contract_id
        JOIN rooms r ON c.room_id = r.room_id
        JOIN tenants t ON c.tenant_id = t.tenant_id
        LEFT JOIN (
            SELECT invoice_id, SUM(payment_amount) as total_paid 
            FROM payments 
            GROUP BY invoice_id
        ) p ON i.invoice_id = p.invoice_id
        WHERE i.invoice_status IN ('pending', 'overdue') AND i.due_date < CURDATE()
        GROUP BY t.tenant_id
        ORDER BY total_overdue_amount DESC
        LIMIT 10";
    
    $stmt = $pdo->query($top_tenants_sql);
    $top_overdue_tenants = $stmt->fetchAll();
    
    // ดึงข้อมูลห้องสำหรับ dropdown
    $rooms_sql = "SELECT DISTINCT r.room_id, r.room_number, r.room_type 
                  FROM rooms r 
                  JOIN contracts c ON r.room_id = c.room_id 
                  WHERE c.contract_status = 'active' 
                  ORDER BY CAST(r.room_number AS UNSIGNED)";
    $stmt = $pdo->query($rooms_sql);
    $available_rooms = $stmt->fetchAll();
    
    // ดึงข้อมูลผู้เช่าสำหรับ dropdown
    $tenants_sql = "SELECT DISTINCT t.tenant_id, CONCAT(t.first_name, ' ', t.last_name) as tenant_name
                    FROM tenants t 
                    JOIN contracts c ON t.tenant_id = c.tenant_id 
                    WHERE c.contract_status = 'active' 
                    ORDER BY t.first_name, t.last_name";
    $stmt = $pdo->query($tenants_sql);
    $available_tenants = $stmt->fetchAll();
    
} catch(PDOException $e) {
    $error_message = "เกิดข้อผิดพลาดในการดึงข้อมูล: " . $e->getMessage();
    $overdue_invoices = [];
    $overdue_ranges = [];
    $top_overdue_tenants = [];
    $available_rooms = [];
    $available_tenants = [];
    $summary = [
        'total_overdue' => 0,
        'total_overdue_amount' => 0,
        'avg_overdue_days' => 0,
        'max_overdue_days' => 0,
        'affected_tenants' => 0,
        'affected_rooms' => 0
    ];
}

// ฟังก์ชันส่งออกข้อมูล Excel
if ($export == 'excel') {
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="overdue_report_' . date('Y-m-d') . '.xls"');
    
    echo "<table border='1'>";
    echo "<tr><th colspan='9'>รายงานค้างชำระ - " . date('d/m/Y') . "</th></tr>";
    echo "<tr><th>เลขที่ใบแจ้งหนี้</th><th>ห้อง</th><th>ผู้เช่า</th><th>เดือน</th><th>ยอดรวม</th><th>ยอดค้าง</th><th>วันที่ครบกำหนด</th><th>ค้างชำระ (วัน)</th><th>เบอร์โทร</th></tr>";
    
    foreach ($overdue_invoices as $invoice) {
        echo "<tr>";
        echo "<td>" . $invoice['invoice_number'] . "</td>";
        echo "<td>ห้อง " . $invoice['room_number'] . "</td>";
        echo "<td>" . $invoice['tenant_name'] . "</td>";
        echo "<td>" . $invoice['invoice_month'] . "</td>";
        echo "<td>" . number_format($invoice['total_amount'], 2) . "</td>";
        echo "<td>" . number_format($invoice['remaining_amount'], 2) . "</td>";
        echo "<td>" . date('d/m/Y', strtotime($invoice['due_date'])) . "</td>";
        echo "<td>" . $invoice['overdue_days'] . "</td>";
        echo "<td>" . $invoice['phone'] . "</td>";
        echo "</tr>";
    }
    
    echo "<tr><td colspan='4'><strong>รวมทั้งหมด</strong></td>";
    echo "<td><strong>" . number_format(array_sum(array_column($overdue_invoices, 'total_amount')), 2) . "</strong></td>";
    echo "<td><strong>" . number_format(array_sum(array_column($overdue_invoices, 'remaining_amount')), 2) . "</strong></td>";
    echo "<td colspan='3'></td></tr>";
    echo "</table>";
    exit;
}
?>

<?php include 'includes/navbar.php'; ?>

<div class="container-fluid mt-4">
    <?php if (isset($success_message)): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <i class="bi bi-check-circle"></i> <?php echo $success_message; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if (isset($error_message)): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <i class="bi bi-exclamation-triangle"></i> <?php echo $error_message; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- หัวข้อหน้า -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2 class="mb-0">
            <i class="bi bi-exclamation-triangle text-danger"></i>
            รายงานค้างชำระ
        </h2>
        <div class="btn-group">
            <button type="button" class="btn btn-success" onclick="exportToExcel()">
                <i class="bi bi-file-earmark-excel"></i>
                ส่งออก Excel
            </button>
            <button type="button" class="btn btn-info" onclick="window.print()">
                <i class="bi bi-printer"></i>
                พิมพ์รายงาน
            </button>
            <button type="button" class="btn btn-warning" onclick="sendReminders()">
                <i class="bi bi-bell"></i>
                ส่งแจ้งเตือน
            </button>
        </div>
    </div>

    <!-- ฟอร์มกรองข้อมูล -->
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="card-title mb-0">
                <i class="bi bi-funnel"></i>
                กรองข้อมูล
            </h5>
        </div>
        <div class="card-body">
            <form method="GET" class="row g-3">
                <div class="col-md-2">
                    <label class="form-label">ค้างชำระ</label>
                    <select name="overdue_days" class="form-select">
                        <option value="all" <?php echo ($overdue_days == 'all') ? 'selected' : ''; ?>>ทั้งหมด</option>
                        <option value="1" <?php echo ($overdue_days == '1') ? 'selected' : ''; ?>>1+ วัน</option>
                        <option value="7" <?php echo ($overdue_days == '7') ? 'selected' : ''; ?>>7+ วัน</option>
                        <option value="30" <?php echo ($overdue_days == '30') ? 'selected' : ''; ?>>30+ วัน</option>
                        <option value="60" <?php echo ($overdue_days == '60') ? 'selected' : ''; ?>>60+ วัน</option>
                        <option value="90" <?php echo ($overdue_days == '90') ? 'selected' : ''; ?>>90+ วัน</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">ห้อง</label>
                    <select name="room_id" class="form-select">
                        <option value="">ทุกห้อง</option>
                        <?php foreach ($available_rooms as $room): ?>
                            <option value="<?php echo $room['room_id']; ?>" <?php echo ($room_filter == $room['room_id']) ? 'selected' : ''; ?>>
                                ห้อง <?php echo $room['room_number']; ?> (<?php echo $room['room_type']; ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">ผู้เช่า</label>
                    <select name="tenant_id" class="form-select">
                        <option value="">ทุกคน</option>
                        <?php foreach ($available_tenants as $tenant): ?>
                            <option value="<?php echo $tenant['tenant_id']; ?>" <?php echo ($tenant_filter == $tenant['tenant_id']) ? 'selected' : ''; ?>>
                                <?php echo $tenant['tenant_name']; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">ยอดขั้นต่ำ</label>
                    <input type="number" name="amount_min" class="form-control" placeholder="0" value="<?php echo $amount_min; ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label">ยอดสูงสุด</label>
                    <input type="number" name="amount_max" class="form-control" placeholder="999999" value="<?php echo $amount_max; ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label">&nbsp;</label>
                    <div class="d-grid">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-search"></i> ค้นหา
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- สถิติรวม -->
    <div class="row mb-4">
        <div class="col-xl-2 col-md-6 mb-3">
            <div class="card border-left-danger shadow h-100">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-danger text-uppercase mb-1">
                                ใบแจ้งหนี้ค้าง
                            </div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                <?php echo number_format($summary['total_overdue']); ?> รายการ
                            </div>
                        </div>
                        <div class="col-auto">
                            <i class="bi bi-receipt fs-2 text-danger"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6 mb-3">
            <div class="card border-left-warning shadow h-100">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">
                                ยอดค้างรวม
                            </div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                <?php echo number_format($summary['total_overdue_amount'], 2); ?> บาท
                            </div>
                        </div>
                        <div class="col-auto">
                            <i class="bi bi-currency-dollar fs-2 text-warning"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-2 col-md-6 mb-3">
            <div class="card border-left-info shadow h-100">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-info text-uppercase mb-1">
                                ผู้เช่าที่ค้าง
                            </div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                <?php echo number_format($summary['affected_tenants']); ?> คน
                            </div>
                        </div>
                        <div class="col-auto">
                            <i class="bi bi-people fs-2 text-info"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-2 col-md-6 mb-3">
            <div class="card border-left-primary shadow h-100">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">
                                ห้องที่เกี่ยวข้อง
                            </div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                <?php echo number_format($summary['affected_rooms']); ?> ห้อง
                            </div>
                        </div>
                        <div class="col-auto">
                            <i class="bi bi-building fs-2 text-primary"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6 mb-3">
            <div class="card border-left-secondary shadow h-100">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-secondary text-uppercase mb-1">
                                ค้างเฉลี่ย / สูงสุด
                            </div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                <?php echo number_format($summary['avg_overdue_days'], 1); ?> / <?php echo number_format($summary['max_overdue_days']); ?> วัน
                            </div>
                        </div>
                        <div class="col-auto">
                            <i class="bi bi-clock fs-2 text-secondary"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <!-- กราฟค้างชำระตามช่วงวัน -->
        <div class="col-xl-6 mb-4">
            <div class="card shadow">
                <div class="card-header">
                    <h6 class="m-0 font-weight-bold text-primary">
                        <i class="bi bi-bar-chart"></i>
                        การค้างชำระตามช่วงเวลา
                    </h6>
                </div>
                <div class="card-body">
                    <div class="chart-area">
                        <canvas id="overdueRangeChart" width="400" height="200"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <!-- ผู้เช่าที่ค้างมากที่สุด -->
        <div class="col-xl-6 mb-4">
            <div class="card shadow">
                <div class="card-header">
                    <h6 class="m-0 font-weight-bold text-primary">
                        <i class="bi bi-person-x"></i>
                        ผู้เช่าที่ค้างชำระมากที่สุด (Top 5)
                    </h6>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>ผู้เช่า</th>
                                    <th class="text-center">จำนวนใบแจ้งหนี้</th>
                                    <th class="text-end">ยอดค้าง</th>
                                    <th class="text-center">ค้างนานสุด</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $top_5_tenants = array_slice($top_overdue_tenants, 0, 5);
                                foreach ($top_5_tenants as $index => $tenant): 
                                ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo $tenant['tenant_name']; ?></strong>
                                            <small class="text-muted d-block">
                                                ห้อง: <?php echo $tenant['rooms']; ?>
                                            </small>
                                            <small class="text-muted">
                                                <i class="bi bi-telephone"></i> <?php echo $tenant['phone']; ?>
                                            </small>
                                        </td>
                                        <td class="text-center">
                                            <span class="badge bg-danger"><?php echo $tenant['overdue_count']; ?></span>
                                        </td>
                                        <td class="text-end fw-bold text-danger">
                                            <?php echo number_format($tenant['total_overdue_amount'], 2); ?> บาท
                                        </td>
                                        <td class="text-center">
                                            <span class="badge bg-warning"><?php echo $tenant['max_overdue_days']; ?> วัน</span>
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

    <!-- ตารางรายละเอียดค้างชำระ -->
    <div class="card shadow mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h6 class="m-0 font-weight-bold text-primary">
                <i class="bi bi-list"></i>
                รายละเอียดค้างชำระ
                <span class="badge bg-danger ms-2"><?php echo count($overdue_invoices); ?> รายการ</span>
            </h6>
            <div class="btn-group btn-group-sm">
                <a href="?<?php echo http_build_query(array_merge($_GET, ['sort_by' => 'overdue_days', 'sort_order' => 'DESC'])); ?>" 
                   class="btn btn-outline-primary <?php echo ($sort_by == 'overdue_days') ? 'active' : ''; ?>">
                    เรียงตามวันค้าง
                </a>
                <a href="?<?php echo http_build_query(array_merge($_GET, ['sort_by' => 'total_amount', 'sort_order' => 'DESC'])); ?>" 
                   class="btn btn-outline-primary <?php echo ($sort_by == 'total_amount') ? 'active' : ''; ?>">
                    เรียงตามยอดเงิน
                </a>
            </div>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-striped table-hover">
                    <thead class="table-dark">
                        <tr>
                            <th>ใบแจ้งหนี้</th>
                            <th>ห้อง</th>
                            <th>ผู้เช่า</th>
                            <th>เดือน</th>
                            <th class="text-end">ยอดรวม</th>
                            <th class="text-end">ชำระแล้ว</th>
                            <th class="text-end">ยอดค้าง</th>
                            <th class="text-center">ครบกำหนด</th>
                            <th class="text-center">ค้างชำระ</th>
                            <th class="text-center">การติดต่อ</th>
                            <th class="text-center">จัดการ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($overdue_invoices)): ?>
                            <tr>
                                <td colspan="11" class="text-center text-muted py-4">
                                    <i class="bi bi-check-circle fs-1 text-success d-block mb-2"></i>
                                    <h5>ไม่มีรายการค้างชำระ</h5>
                                    <p>ยินดีด้วย! ไม่มีใบแจ้งหนี้ค้างชำระในช่วงที่เลือก</p>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php 
                            $total_overdue_sum = 0;
                            $total_remaining_sum = 0;
                            
                            foreach ($overdue_invoices as $invoice): 
                                $total_overdue_sum += $invoice['total_amount'];
                                $total_remaining_sum += $invoice['remaining_amount'];
                                
                                // กำหนดสีตามระดับความร้อนแรง
                                $urgency_class = '';
                                $urgency_badge = '';
                                if ($invoice['overdue_days'] >= 90) {
                                    $urgency_class = 'table-danger';
                                    $urgency_badge = 'bg-danger';
                                } elseif ($invoice['overdue_days'] >= 60) {
                                    $urgency_class = 'table-warning';
                                    $urgency_badge = 'bg-warning';
                                } elseif ($invoice['overdue_days'] >= 30) {
                                    $urgency_class = '';
                                    $urgency_badge = 'bg-warning';
                                } else {
                                    $urgency_badge = 'bg-info';
                                }
                            ?>
                                <tr class="<?php echo $urgency_class; ?>">
                                    <td>
                                        <strong><?php echo $invoice['invoice_number']; ?></strong>
                                        <small class="text-muted d-block">
                                            <i class="bi bi-calendar"></i> 
                                            สร้าง: <?php echo date('d/m/Y', strtotime($invoice['created_at'])); ?>
                                        </small>
                                    </td>
                                    <td>
                                        <strong>ห้อง <?php echo $invoice['room_number']; ?></strong>
                                        <small class="text-muted d-block">
                                            <?php echo ucfirst($invoice['room_type']); ?> 
                                            <?php if ($invoice['floor_number']): ?>
                                                • ชั้น <?php echo $invoice['floor_number']; ?>
                                            <?php endif; ?>
                                        </small>
                                    </td>
                                    <td>
                                        <strong><?php echo $invoice['tenant_name']; ?></strong>
                                        <small class="text-muted d-block">
                                            <i class="bi bi-telephone"></i> <?php echo $invoice['phone']; ?>
                                        </small>
                                        <?php if ($invoice['email']): ?>
                                            <small class="text-muted d-block">
                                                <i class="bi bi-envelope"></i> <?php echo $invoice['email']; ?>
                                            </small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php 
                                        $month_year = explode('-', $invoice['invoice_month']);
                                        $thai_months = [
                                            '01' => 'ม.ค.', '02' => 'ก.พ.', '03' => 'มี.ค.', '04' => 'เม.ย.',
                                            '05' => 'พ.ค.', '06' => 'มิ.ย.', '07' => 'ก.ค.', '08' => 'ส.ค.',
                                            '09' => 'ก.ย.', '10' => 'ต.ค.', '11' => 'พ.ย.', '12' => 'ธ.ค.'
                                        ];
                                        echo $thai_months[$month_year[1]] . ' ' . ($month_year[0] + 543);
                                        ?>
                                    </td>
                                    <td class="text-end">
                                        <strong><?php echo number_format($invoice['total_amount'], 2); ?></strong>
                                        <small class="text-muted d-block">บาท</small>
                                    </td>
                                    <td class="text-end">
                                        <?php if ($invoice['paid_amount'] > 0): ?>
                                            <span class="text-success">
                                                <?php echo number_format($invoice['paid_amount'], 2); ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="text-muted">0.00</span>
                                        <?php endif; ?>
                                        <small class="text-muted d-block">บาท</small>
                                    </td>
                                    <td class="text-end">
                                        <strong class="text-danger">
                                            <?php echo number_format($invoice['remaining_amount'], 2); ?>
                                        </strong>
                                        <small class="text-muted d-block">บาท</small>
                                    </td>
                                    <td class="text-center">
                                        <strong><?php echo date('d/m/Y', strtotime($invoice['due_date'])); ?></strong>
                                        <small class="text-muted d-block">
                                            <?php 
                                            $due_day = date('w', strtotime($invoice['due_date']));
                                            $day_names = ['อา.', 'จ.', 'อ.', 'พ.', 'พฤ.', 'ศ.', 'ส.'];
                                            echo $day_names[$due_day];
                                            ?>
                                        </small>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge <?php echo $urgency_badge; ?> fs-6">
                                            <?php echo $invoice['overdue_days']; ?> วัน
                                        </span>
                                        <?php if ($invoice['overdue_days'] >= 90): ?>
                                            <small class="text-danger d-block mt-1">
                                                <i class="bi bi-exclamation-triangle"></i> วิกฤต
                                            </small>
                                        <?php elseif ($invoice['overdue_days'] >= 60): ?>
                                            <small class="text-warning d-block mt-1">
                                                <i class="bi bi-exclamation-circle"></i> ร้ายแรง
                                            </small>
                                        <?php elseif ($invoice['overdue_days'] >= 30): ?>
                                            <small class="text-warning d-block mt-1">
                                                <i class="bi bi-clock"></i> ต้องติดตาม
                                            </small>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <div class="btn-group btn-group-sm">
                                            <a href="tel:<?php echo $invoice['phone']; ?>" 
                                               class="btn btn-outline-success btn-sm" 
                                               title="โทร">
                                                <i class="bi bi-telephone"></i>
                                            </a>
                                            <?php if ($invoice['email']): ?>
                                                <a href="mailto:<?php echo $invoice['email']; ?>?subject=แจ้งเตือนค้างชำระ&body=เรียนคุณ<?php echo $invoice['tenant_name']; ?>%0A%0Aใบแจ้งหนี้ <?php echo $invoice['invoice_number']; ?> ยอด <?php echo number_format($invoice['remaining_amount'], 2); ?> บาท ค้างชำระ <?php echo $invoice['overdue_days']; ?> วันแล้ว" 
                                                   class="btn btn-outline-info btn-sm" 
                                                   title="ส่งอีเมล">
                                                    <i class="bi bi-envelope"></i>
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td class="text-center">
                                        <div class="btn-group btn-group-sm">
                                            <a href="view_invoice.php?id=<?php echo $invoice['invoice_id']; ?>" 
                                               class="btn btn-outline-primary btn-sm" 
                                               title="ดูใบแจ้งหนี้">
                                                <i class="bi bi-eye"></i>
                                            </a>
                                            <button type="button" 
                                                    class="btn btn-outline-success btn-sm" 
                                                    onclick="recordPayment(<?php echo $invoice['invoice_id']; ?>, '<?php echo $invoice['invoice_number']; ?>', <?php echo $invoice['remaining_amount']; ?>)"
                                                    title="บันทึกการชำระ">
                                                <i class="bi bi-cash"></i>
                                            </button>
                                            <a href="generate_notice.php?invoice_id=<?php echo $invoice['invoice_id']; ?>" 
                                               class="btn btn-outline-warning btn-sm" 
                                               title="พิมพ์หนังสือเตือน">
                                                <i class="bi bi-file-earmark-text"></i>
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            
                            <!-- แถวสรุปรวม -->
                            <tr class="table-dark fw-bold">
                                <td colspan="4">รวมทั้งหมด (<?php echo count($overdue_invoices); ?> รายการ)</td>
                                <td class="text-end">
                                    <?php echo number_format($total_overdue_sum, 2); ?> บาท
                                </td>
                                <td class="text-end">
                                    <?php echo number_format($total_overdue_sum - $total_remaining_sum, 2); ?> บาท
                                </td>
                                <td class="text-end">
                                    <?php echo number_format($total_remaining_sum, 2); ?> บาท
                                </td>
                                <td colspan="4"></td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Modal สำหรับบันทึกการชำระเงิน -->
<div class="modal fade" id="paymentModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-cash"></i>
                    บันทึกการชำระเงิน
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="paymentForm" method="POST" action="invoices.php">
                <div class="modal-body">
                    <input type="hidden" id="payment_invoice_id" name="invoice_id">
                    <input type="hidden" name="update_payment" value="1">
                    
                    <div class="mb-3">
                        <label class="form-label">ใบแจ้งหนี้</label>
                        <input type="text" id="payment_invoice_number" class="form-control" readonly>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <label class="form-label">จำนวนเงิน</label>
                            <input type="number" id="payment_amount" name="payment_amount" class="form-control" step="0.01" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">วันที่ชำระ</label>
                            <input type="date" name="payment_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">วิธีการชำระ</label>
                        <select name="payment_method" class="form-select" required>
                            <option value="">เลือกวิธีการชำระ</option>
                            <option value="cash">เงินสด</option>
                            <option value="bank_transfer">โอนเงิน</option>
                            <option value="mobile_banking">Mobile Banking</option>
                            <option value="other">อื่นๆ</option>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">หมายเลขอ้างอิง</label>
                        <input type="text" name="payment_reference" class="form-control" placeholder="หมายเลขอ้างอิง (ถ้ามี)">
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">หมายเหตุ</label>
                        <textarea name="notes" class="form-control" rows="3" placeholder="หมายเหตุเพิ่มเติม"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ยกเลิก</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check"></i> บันทึกการชำระ
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- เพิ่ม CSS สำหรับ Print -->
<style>
@media print {
    .btn-group, .card-header .btn, .navbar, .no-print, .modal {
        display: none !important;
    }
    
    .card {
        border: 1px solid #000 !important;
        box-shadow: none !important;
    }
    
    .table {
        font-size: 11px;
    }
    
    .text-primary, .text-success, .text-info, .text-warning, .text-danger {
        color: #000 !important;
    }
    
    .badge {
        border: 1px solid #000 !important;
        color: #000 !important;
    }
}

.border-left-danger {
    border-left: 4px solid #e74a3b !important;
}

.border-left-warning {
    border-left: 4px solid #f6c23e !important;
}

.border-left-info {
    border-left: 4px solid #36b9cc !important;
}

.border-left-primary {
    border-left: 4px solid #4e73df !important;
}

.border-left-secondary {
    border-left: 4px solid #858796 !important;
}

.table-hover tbody tr:hover {
    background-color: rgba(0, 0, 0, 0.075);
}

.urgency-critical {
    animation: blink 1s infinite;
}

@keyframes blink {
    0%, 50% { opacity: 1; }
    51%, 100% { opacity: 0.5; }
}
</style>

<!-- เพิ่ม Chart.js -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/3.9.1/chart.min.js"></script>

<script>
// กราฟค้างชำระตามช่วงวัน
<?php if (!empty($overdue_ranges)): ?>
    const overdueChart = new Chart(document.getElementById('overdueRangeChart'), {
        type: 'bar',
        data: {
            labels: [
                <?php 
                foreach ($overdue_ranges as $range) {
                    echo "'" . $range['overdue_range'] . "',";
                }
                ?>
            ],
            datasets: [{
                label: 'จำนวนใบแจ้งหนี้',
                data: [
                    <?php 
                    foreach ($overdue_ranges as $range) {
                        echo $range['invoice_count'] . ",";
                    }
                    ?>
                ],
                backgroundColor: [
                    '#36b9cc',
                    '#f6c23e',
                    '#fd7e14',
                    '#e74a3b',
                    '#6f42c1'
                ],
                borderColor: '#fff',
                borderWidth: 1
            },
            {
                label: 'ยอดเงิน (บาท)',
                data: [
                    <?php 
                    foreach ($overdue_ranges as $range) {
                        echo $range['range_amount'] . ",";
                    }
                    ?>
                ],
                type: 'line',
                borderColor: '#e74a3b',
                backgroundColor: 'rgba(231, 74, 59, 0.1)',
                yAxisID: 'y1',
                tension: 0.4
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'top'
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    position: 'left',
                    title: {
                        display: true,
                        text: 'จำนวนใบแจ้งหนี้'
                    }
                },
                y1: {
                    type: 'linear',
                    display: true,
                    position: 'right',
                    title: {
                        display: true,
                        text: 'ยอดเงิน (บาท)'
                    },
                    grid: {
                        drawOnChartArea: false,
                    },
                    ticks: {
                        callback: function(value) {
                            return new Intl.NumberFormat('th-TH').format(value);
                        }
                    }
                }
            },
            plugins: {
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            if (context.datasetIndex === 0) {
                                return 'จำนวน: ' + context.parsed.y + ' รายการ';
                            } else {
                                return 'ยอดเงิน: ' + new Intl.NumberFormat('th-TH').format(context.parsed.y) + ' บาท';
                            }
                        }
                    }
                }
            }
        }
    });
<?php endif; ?>

// ฟังก์ชันส่งออก Excel
function exportToExcel() {
    const params = new URLSearchParams(window.location.search);
    params.set('export', 'excel');
    window.open('?' + params.toString(), '_blank');
}

// ฟังก์ชันบันทึกการชำระเงิน
function recordPayment(invoiceId, invoiceNumber, remainingAmount) {
    document.getElementById('payment_invoice_id').value = invoiceId;
    document.getElementById('payment_invoice_number').value = invoiceNumber;
    document.getElementById('payment_amount').value = remainingAmount.toFixed(2);
    
    const modal = new bootstrap.Modal(document.getElementById('paymentModal'));
    modal.show();
}

// ฟังก์ชันส่งแจ้งเตือน
function sendReminders() {
    if (confirm('ต้องการส่งแจ้งเตือนให้ผู้เช่าที่ค้างชำระทั้งหมดหรือไม่?')) {
        // สร้าง URL สำหรับส่งแจ้งเตือน
        const params = new URLSearchParams(window.location.search);
        params.set('send_reminders', '1');
        window.location.href = 'send_notifications.php?' + params.toString();
    }
}

// ฟังก์ชันไฮไลท์แถวที่มีความเร่งด่วนสูง
document.addEventListener('DOMContentLoaded', function() {
    const urgentRows = document.querySelectorAll('.table-danger');
    urgentRows.forEach(function(row) {
        const overdueCell = row.querySelector('.badge.bg-danger');
        if (overdueCell && parseInt(overdueCell.textContent) >= 90) {
            overdueCell.classList.add('urgency-critical');
        }
    });
});

// อัพเดทข้อมูลอัตโนมัติทุก 10 นาที
setInterval(function() {
    location.reload();
}, 600000);

// เพิ่ม tooltip
document.addEventListener('DOMContentLoaded', function() {
    const tooltipTriggerList = [].slice.call(document.querySelectorAll('[title]'));
    const tooltipList = tooltipTriggerList.map(function(tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });
});

// ฟังก์ชันกรองแบบ real-time
function filterTable() {
    // ฟีเจอร์สำหรับกรองข้อมูลแบบ real-time สามารถเพิ่มได้ในอนาคต
}
</script>

<?php require_once 'includes/footer.php'; ?>