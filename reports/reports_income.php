<?php
$page_title = "รายงานรายได้";
require_once 'includes/header.php';

// ตั้งค่าค่าเริ่มต้นสำหรับการกรอง
$year_filter = isset($_GET['year']) ? $_GET['year'] : date('Y');
$month_filter = isset($_GET['month']) ? $_GET['month'] : '';
$type_filter = isset($_GET['type']) ? $_GET['type'] : 'all';
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : '';
$export = isset($_GET['export']) ? $_GET['export'] : '';

try {
    // สร้าง WHERE clause สำหรับการกรอง
    $where_conditions = [];
    $params = [];
    
    // กรองตามปี
    if (!empty($year_filter)) {
        $where_conditions[] = "YEAR(p.payment_date) = ?";
        $params[] = $year_filter;
    }
    
    // กรองตามเดือน
    if (!empty($month_filter)) {
        $where_conditions[] = "MONTH(p.payment_date) = ?";
        $params[] = $month_filter;
    }
    
    // กรองตามช่วงวันที่
    if (!empty($date_from)) {
        $where_conditions[] = "p.payment_date >= ?";
        $params[] = $date_from;
    }
    
    if (!empty($date_to)) {
        $where_conditions[] = "p.payment_date <= ?";
        $params[] = $date_to;
    }
    
    // กรองตามประเภทรายได้
    if ($type_filter != 'all') {
        switch ($type_filter) {
            case 'rent':
                $where_conditions[] = "i.room_rent > 0";
                break;
            case 'utilities':
                $where_conditions[] = "(i.water_charge > 0 OR i.electric_charge > 0)";
                break;
            case 'other':
                $where_conditions[] = "i.other_charges > 0";
                break;
        }
    }
    
    $where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';
    
    // ดึงข้อมูลรายได้รายวัน
    $daily_sql = "SELECT 
        p.payment_date,
        COUNT(p.payment_id) as payment_count,
        SUM(p.payment_amount) as daily_income,
        SUM(CASE WHEN p.payment_method = 'cash' THEN p.payment_amount ELSE 0 END) as cash_income,
        SUM(CASE WHEN p.payment_method = 'bank_transfer' THEN p.payment_amount ELSE 0 END) as transfer_income,
        SUM(CASE WHEN p.payment_method = 'mobile_banking' THEN p.payment_amount ELSE 0 END) as mobile_income,
        SUM(CASE WHEN p.payment_method = 'other' THEN p.payment_amount ELSE 0 END) as other_income
        FROM payments p
        JOIN invoices i ON p.invoice_id = i.invoice_id
        $where_clause
        GROUP BY p.payment_date
        ORDER BY p.payment_date DESC";
    
    $stmt = $pdo->prepare($daily_sql);
    $stmt->execute($params);
    $daily_income = $stmt->fetchAll();
    
    // ดึงข้อมูลรายได้รายเดือน
    $monthly_sql = "SELECT 
        DATE_FORMAT(p.payment_date, '%Y-%m') as month,
        DATE_FORMAT(p.payment_date, '%Y') as year,
        DATE_FORMAT(p.payment_date, '%m') as month_num,
        COUNT(p.payment_id) as payment_count,
        SUM(p.payment_amount) as monthly_income,
        AVG(p.payment_amount) as avg_payment
        FROM payments p
        JOIN invoices i ON p.invoice_id = i.invoice_id
        $where_clause
        GROUP BY DATE_FORMAT(p.payment_date, '%Y-%m')
        ORDER BY month DESC";
    
    $stmt = $pdo->prepare($monthly_sql);
    $stmt->execute($params);
    $monthly_income = $stmt->fetchAll();
    
    // ดึงข้อมูลรายได้ตามประเภท
    $type_sql = "SELECT 
        'ค่าเช่าห้อง' as income_type,
        SUM(CASE WHEN i.room_rent > 0 THEN p.payment_amount * (i.room_rent / i.total_amount) ELSE 0 END) as amount,
        COUNT(CASE WHEN i.room_rent > 0 THEN p.payment_id END) as payment_count
        FROM payments p
        JOIN invoices i ON p.invoice_id = i.invoice_id
        $where_clause
        UNION ALL
        SELECT 
        'ค่าน้ำ' as income_type,
        SUM(CASE WHEN i.water_charge > 0 THEN p.payment_amount * (i.water_charge / i.total_amount) ELSE 0 END) as amount,
        COUNT(CASE WHEN i.water_charge > 0 THEN p.payment_id END) as payment_count
        FROM payments p
        JOIN invoices i ON p.invoice_id = i.invoice_id
        $where_clause
        UNION ALL
        SELECT 
        'ค่าไฟ' as income_type,
        SUM(CASE WHEN i.electric_charge > 0 THEN p.payment_amount * (i.electric_charge / i.total_amount) ELSE 0 END) as amount,
        COUNT(CASE WHEN i.electric_charge > 0 THEN p.payment_id END) as payment_count
        FROM payments p
        JOIN invoices i ON p.invoice_id = i.invoice_id
        $where_clause
        UNION ALL
        SELECT 
        'อื่นๆ' as income_type,
        SUM(CASE WHEN i.other_charges > 0 THEN p.payment_amount * (i.other_charges / i.total_amount) ELSE 0 END) as amount,
        COUNT(CASE WHEN i.other_charges > 0 THEN p.payment_id END) as payment_count
        FROM payments p
        JOIN invoices i ON p.invoice_id = i.invoice_id
        $where_clause";
    
    $stmt = $pdo->prepare($type_sql);
    $stmt->execute(array_merge($params, $params, $params, $params));
    $income_by_type = $stmt->fetchAll();
    
    // ดึงข้อมูลรายได้ตามวิธีการชำระ
    $method_sql = "SELECT 
        CASE 
            WHEN p.payment_method = 'cash' THEN 'เงินสด'
            WHEN p.payment_method = 'bank_transfer' THEN 'โอนเงิน'
            WHEN p.payment_method = 'mobile_banking' THEN 'Mobile Banking'
            WHEN p.payment_method = 'other' THEN 'อื่นๆ'
            ELSE p.payment_method
        END as payment_method_text,
        p.payment_method,
        COUNT(p.payment_id) as payment_count,
        SUM(p.payment_amount) as total_amount,
        AVG(p.payment_amount) as avg_amount
        FROM payments p
        JOIN invoices i ON p.invoice_id = i.invoice_id
        $where_clause
        GROUP BY p.payment_method
        ORDER BY total_amount DESC";
    
    $stmt = $pdo->prepare($method_sql);
    $stmt->execute($params);
    $income_by_method = $stmt->fetchAll();
    
    // ดึงข้อมูลสถิติรวม
    $summary_sql = "SELECT 
        COUNT(p.payment_id) as total_payments,
        SUM(p.payment_amount) as total_income,
        AVG(p.payment_amount) as avg_payment,
        MIN(p.payment_amount) as min_payment,
        MAX(p.payment_amount) as max_payment,
        COUNT(DISTINCT DATE_FORMAT(p.payment_date, '%Y-%m')) as months_count,
        COUNT(DISTINCT p.payment_date) as days_count
        FROM payments p
        JOIN invoices i ON p.invoice_id = i.invoice_id
        $where_clause";
    
    $stmt = $pdo->prepare($summary_sql);
    $stmt->execute($params);
    $summary = $stmt->fetch();
    
    // ดึงข้อมูลรายได้ตามห้อง/ผู้เช่า
    $room_sql = "SELECT 
        r.room_number,
        r.room_type,
        CONCAT(t.first_name, ' ', t.last_name) as tenant_name,
        COUNT(p.payment_id) as payment_count,
        SUM(p.payment_amount) as total_income,
        MAX(p.payment_date) as last_payment_date
        FROM payments p
        JOIN invoices i ON p.invoice_id = i.invoice_id
        JOIN contracts c ON i.contract_id = c.contract_id
        JOIN rooms r ON c.room_id = r.room_id
        JOIN tenants t ON c.tenant_id = t.tenant_id
        $where_clause
        GROUP BY r.room_id, c.tenant_id
        ORDER BY total_income DESC";
    
    $stmt = $pdo->prepare($room_sql);
    $stmt->execute($params);
    $income_by_room = $stmt->fetchAll();
    
} catch(PDOException $e) {
    $error_message = "เกิดข้อผิดพลาดในการดึงข้อมูล: " . $e->getMessage();
    $daily_income = [];
    $monthly_income = [];
    $income_by_type = [];
    $income_by_method = [];
    $income_by_room = [];
    $summary = [
        'total_payments' => 0,
        'total_income' => 0,
        'avg_payment' => 0,
        'min_payment' => 0,
        'max_payment' => 0,
        'months_count' => 0,
        'days_count' => 0
    ];
}

// ฟังก์ชันส่งออกข้อมูล Excel
if ($export == 'excel') {
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="income_report_' . date('Y-m-d') . '.xls"');
    
    echo "<table border='1'>";
    echo "<tr><th colspan='6'>รายงานรายได้</th></tr>";
    echo "<tr><th>วันที่</th><th>จำนวนการชำระ</th><th>รายได้รวม</th><th>เงินสด</th><th>โอนเงิน</th><th>Mobile Banking</th></tr>";
    
    foreach ($daily_income as $day) {
        echo "<tr>";
        echo "<td>" . date('d/m/Y', strtotime($day['payment_date'])) . "</td>";
        echo "<td>" . number_format($day['payment_count']) . "</td>";
        echo "<td>" . number_format($day['daily_income'], 2) . "</td>";
        echo "<td>" . number_format($day['cash_income'], 2) . "</td>";
        echo "<td>" . number_format($day['transfer_income'], 2) . "</td>";
        echo "<td>" . number_format($day['mobile_income'], 2) . "</td>";
        echo "</tr>";
    }
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
            <i class="bi bi-graph-up"></i>
            รายงานรายได้
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
                    <label class="form-label">ปี</label>
                    <select name="year" class="form-select">
                        <option value="">ทุกปี</option>
                        <?php 
                        $current_year = date('Y');
                        for ($i = $current_year; $i >= $current_year - 5; $i--): 
                        ?>
                            <option value="<?php echo $i; ?>" <?php echo ($year_filter == $i) ? 'selected' : ''; ?>>
                                <?php echo $i + 543; ?>
                            </option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">เดือน</label>
                    <select name="month" class="form-select">
                        <option value="">ทุกเดือน</option>
                        <?php 
                        $months = [
                            '1' => 'ม.ค.', '2' => 'ก.พ.', '3' => 'มี.ค.', '4' => 'เม.ย.',
                            '5' => 'พ.ค.', '6' => 'มิ.ย.', '7' => 'ก.ค.', '8' => 'ส.ค.',
                            '9' => 'ก.ย.', '10' => 'ต.ค.', '11' => 'พ.ย.', '12' => 'ธ.ค.'
                        ];
                        foreach ($months as $num => $name): 
                        ?>
                            <option value="<?php echo $num; ?>" <?php echo ($month_filter == $num) ? 'selected' : ''; ?>>
                                <?php echo $name; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">ประเภทรายได้</label>
                    <select name="type" class="form-select">
                        <option value="all" <?php echo ($type_filter == 'all') ? 'selected' : ''; ?>>ทั้งหมด</option>
                        <option value="rent" <?php echo ($type_filter == 'rent') ? 'selected' : ''; ?>>ค่าเช่า</option>
                        <option value="utilities" <?php echo ($type_filter == 'utilities') ? 'selected' : ''; ?>>ค่าสาธารณูปโภค</option>
                        <option value="other" <?php echo ($type_filter == 'other') ? 'selected' : ''; ?>>อื่นๆ</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">วันที่เริ่ม</label>
                    <input type="date" name="date_from" class="form-control" value="<?php echo $date_from; ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label">วันที่สิ้นสุด</label>
                    <input type="date" name="date_to" class="form-control" value="<?php echo $date_to; ?>">
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
        <div class="col-xl-3 col-md-6 mb-3">
            <div class="card border-left-primary shadow h-100">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">
                                รายได้รวม
                            </div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                <?php echo number_format($summary['total_income'], 2); ?> บาท
                            </div>
                        </div>
                        <div class="col-auto">
                            <i class="bi bi-currency-dollar fs-2 text-primary"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6 mb-3">
            <div class="card border-left-success shadow h-100">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-success text-uppercase mb-1">
                                จำนวนการชำระ
                            </div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                <?php echo number_format($summary['total_payments']); ?> รายการ
                            </div>
                        </div>
                        <div class="col-auto">
                            <i class="bi bi-receipt fs-2 text-success"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6 mb-3">
            <div class="card border-left-info shadow h-100">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-info text-uppercase mb-1">
                                ค่าเฉลี่ยต่อครั้ง
                            </div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                <?php echo number_format($summary['avg_payment'], 2); ?> บาท
                            </div>
                        </div>
                        <div class="col-auto">
                            <i class="bi bi-calculator fs-2 text-info"></i>
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
                                วันที่มีรายได้
                            </div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                <?php echo number_format($summary['days_count']); ?> วัน
                            </div>
                        </div>
                        <div class="col-auto">
                            <i class="bi bi-calendar-check fs-2 text-warning"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <!-- กราฟรายได้รายเดือน -->
        <div class="col-xl-8 mb-4">
            <div class="card shadow">
                <div class="card-header">
                    <h6 class="m-0 font-weight-bold text-primary">
                        <i class="bi bi-bar-chart"></i>
                        รายได้รายเดือน
                    </h6>
                </div>
                <div class="card-body">
                    <div class="chart-area">
                        <canvas id="monthlyIncomeChart" width="400" height="200"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <!-- รายได้ตามประเภท -->
        <div class="col-xl-4 mb-4">
            <div class="card shadow">
                <div class="card-header">
                    <h6 class="m-0 font-weight-bold text-primary">
                        <i class="bi bi-pie-chart"></i>
                        รายได้ตามประเภท
                    </h6>
                </div>
                <div class="card-body">
                    <div class="chart-pie">
                        <canvas id="incomeTypeChart" width="400" height="300"></canvas>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <!-- รายได้ตามวิธีการชำระ -->
        <div class="col-xl-6 mb-4">
            <div class="card shadow">
                <div class="card-header">
                    <h6 class="m-0 font-weight-bold text-primary">
                        <i class="bi bi-credit-card"></i>
                        รายได้ตามวิธีการชำระ
                    </h6>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>วิธีการชำระ</th>
                                    <th class="text-center">จำนวน</th>
                                    <th class="text-end">ยอดรวม</th>
                                    <th class="text-end">ค่าเฉลี่ย</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($income_by_method as $method): ?>
                                    <tr>
                                        <td>
                                            <i class="bi bi-<?php 
                                                echo $method['payment_method'] == 'cash' ? 'cash' : 
                                                    ($method['payment_method'] == 'bank_transfer' ? 'bank' : 
                                                    ($method['payment_method'] == 'mobile_banking' ? 'phone' : 'credit-card')); 
                                            ?>"></i>
                                            <?php echo $method['payment_method_text']; ?>
                                        </td>
                                        <td class="text-center">
                                            <span class="badge bg-info"><?php echo number_format($method['payment_count']); ?></span>
                                        </td>
                                        <td class="text-end fw-bold">
                                            <?php echo number_format($method['total_amount'], 2); ?> บาท
                                        </td>
                                        <td class="text-end">
                                            <?php echo number_format($method['avg_amount'], 2); ?> บาท
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- รายได้ตามห้อง -->
        <div class="col-xl-6 mb-4">
            <div class="card shadow">
                <div class="card-header">
                    <h6 class="m-0 font-weight-bold text-primary">
                        <i class="bi bi-building"></i>
                        รายได้ตามห้อง (Top 10)
                    </h6>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>ห้อง</th>
                                    <th>ผู้เช่า</th>
                                    <th class="text-center">จำนวน</th>
                                    <th class="text-end">ยอดรวม</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $top_rooms = array_slice($income_by_room, 0, 10);
                                foreach ($top_rooms as $room): 
                                ?>
                                    <tr>
                                        <td>
                                            <strong>ห้อง <?php echo $room['room_number']; ?></strong>
                                            <small class="text-muted d-block"><?php echo $room['room_type']; ?></small>
                                        </td>
                                        <td>
                                            <?php echo $room['tenant_name']; ?>
                                            <small class="text-muted d-block">
                                                ชำระล่าสุด: <?php echo date('d/m/Y', strtotime($room['last_payment_date'])); ?>
                                            </small>
                                        </td>
                                        <td class="text-center">
                                            <span class="badge bg-primary"><?php echo number_format($room['payment_count']); ?></span>
                                        </td>
                                        <td class="text-end fw-bold">
                                            <?php echo number_format($room['total_income'], 2); ?> บาท
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

    <!-- รายได้รายวัน -->
    <div class="card shadow mb-4">
        <div class="card-header">
            <h6 class="m-0 font-weight-bold text-primary">
                <i class="bi bi-calendar-day"></i>
                รายได้รายวัน
            </h6>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-striped table-hover">
                    <thead class="table-dark">
                        <tr>
                            <th>วันที่</th>
                            <th class="text-center">จำนวนการชำระ</th>
                            <th class="text-end">รายได้รวม</th>
                            <th class="text-end">เงินสด</th>
                            <th class="text-end">โอนเงิน</th>
                            <th class="text-end">Mobile Banking</th>
                            <th class="text-end">อื่นๆ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($daily_income)): ?>
                            <tr>
                                <td colspan="7" class="text-center text-muted py-4">
                                    <i class="bi bi-inbox fs-1 d-block mb-2"></i>
                                    ไม่พบข้อมูลรายได้ในช่วงที่เลือก
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php 
                            $total_daily = 0;
                            $total_cash = 0;
                            $total_transfer = 0;
                            $total_mobile = 0;
                            $total_other = 0;
                            $total_payments = 0;
                            
                            foreach ($daily_income as $day): 
                                $total_daily += $day['daily_income'];
                                $total_cash += $day['cash_income'];
                                $total_transfer += $day['transfer_income'];
                                $total_mobile += $day['mobile_income'];
                                $total_other += $day['other_income'];
                                $total_payments += $day['payment_count'];
                            ?>
                                <tr>
                                    <td>
                                        <strong><?php echo date('d/m/Y', strtotime($day['payment_date'])); ?></strong>
                                        <small class="text-muted d-block">
                                            <?php 
                                            $day_name = ['อาทิตย์', 'จันทร์', 'อังคาร', 'พุธ', 'พฤหัสบดี', 'ศุกร์', 'เสาร์'];
                                            echo $day_name[date('w', strtotime($day['payment_date']))];
                                            ?>
                                        </small>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge bg-info"><?php echo number_format($day['payment_count']); ?></span>
                                    </td>
                                    <td class="text-end fw-bold text-success">
                                        <?php echo number_format($day['daily_income'], 2); ?> บาท
                                    </td>
                                    <td class="text-end">
                                        <?php echo number_format($day['cash_income'], 2); ?>
                                    </td>
                                    <td class="text-end">
                                        <?php echo number_format($day['transfer_income'], 2); ?>
                                    </td>
                                    <td class="text-end">
                                        <?php echo number_format($day['mobile_income'], 2); ?>
                                    </td>
                                    <td class="text-end">
                                        <?php echo number_format($day['other_income'], 2); ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            
                            <!-- แถวสรุปรวม -->
                            <tr class="table-warning fw-bold">
                                <td>รวมทั้งหมด</td>
                                <td class="text-center">
                                    <span class="badge bg-dark"><?php echo number_format($total_payments); ?></span>
                                </td>
                                <td class="text-end">
                                    <?php echo number_format($total_daily, 2); ?> บาท
                                </td>
                                <td class="text-end">
                                    <?php echo number_format($total_cash, 2); ?>
                                </td>
                                <td class="text-end">
                                    <?php echo number_format($total_transfer, 2); ?>
                                </td>
                                <td class="text-end">
                                    <?php echo number_format($total_mobile, 2); ?>
                                </td>
                                <td class="text-end">
                                    <?php echo number_format($total_other, 2); ?>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- เพิ่ม CSS สำหรับ Print -->
<style>
@media print {
    .btn-group, .card-header .btn, .navbar, .no-print {
        display: none !important;
    }
    
    .card {
        border: 1px solid #000 !important;
        box-shadow: none !important;
    }
    
    .table {
        font-size: 12px;
    }
    
    .text-primary, .text-success, .text-info, .text-warning {
        color: #000 !important;
    }
}

.border-left-primary {
    border-left: 4px solid #4e73df !important;
}

.border-left-success {
    border-left: 4px solid #1cc88a !important;
}

.border-left-info {
    border-left: 4px solid #36b9cc !important;
}

.border-left-warning {
    border-left: 4px solid #f6c23e !important;
}
</style>

<!-- เพิ่ม Chart.js -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/3.9.1/chart.min.js"></script>

<script>
// กราฟรายได้รายเดือน
<?php if (!empty($monthly_income)): ?>
    const monthlyChart = new Chart(document.getElementById('monthlyIncomeChart'), {
        type: 'line',
        data: {
            labels: [
                <?php 
                foreach (array_reverse($monthly_income) as $month) {
                    $monthNames = [
                        '01' => 'ม.ค.', '02' => 'ก.พ.', '03' => 'มี.ค.', '04' => 'เม.ย.',
                        '05' => 'พ.ค.', '06' => 'มิ.ย.', '07' => 'ก.ค.', '08' => 'ส.ค.',
                        '09' => 'ก.ย.', '10' => 'ต.ค.', '11' => 'พ.ย.', '12' => 'ธ.ค.'
                    ];
                    $parts = explode('-', $month['month']);
                    echo "'" . $monthNames[$parts[1]] . " " . ($parts[0] + 543) . "',";
                }
                ?>
            ],
            datasets: [{
                label: 'รายได้ (บาท)',
                data: [
                    <?php 
                    foreach (array_reverse($monthly_income) as $month) {
                        echo $month['monthly_income'] . ",";
                    }
                    ?>
                ],
                borderColor: '#4e73df',
                backgroundColor: 'rgba(78, 115, 223, 0.1)',
                borderWidth: 3,
                fill: true,
                tension: 0.4,
                pointBackgroundColor: '#4e73df',
                pointBorderColor: '#fff',
                pointBorderWidth: 2,
                pointRadius: 6
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
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
                    },
                    grid: {
                        color: 'rgba(0,0,0,0.1)'
                    }
                },
                x: {
                    grid: {
                        display: false
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
<?php endif; ?>

// กราฟรายได้ตามประเภท
<?php if (!empty($income_by_type)): ?>
    const typeChart = new Chart(document.getElementById('incomeTypeChart'), {
        type: 'doughnut',
        data: {
            labels: [
                <?php 
                foreach ($income_by_type as $type) {
                    if ($type['amount'] > 0) {
                        echo "'" . $type['income_type'] . "',";
                    }
                }
                ?>
            ],
            datasets: [{
                data: [
                    <?php 
                    foreach ($income_by_type as $type) {
                        if ($type['amount'] > 0) {
                            echo $type['amount'] . ",";
                        }
                    }
                    ?>
                ],
                backgroundColor: [
                    '#4e73df',
                    '#1cc88a', 
                    '#36b9cc',
                    '#f6c23e'
                ],
                borderColor: '#fff',
                borderWidth: 2
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: {
                        padding: 20,
                        usePointStyle: true
                    }
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            const label = context.label || '';
                            const value = new Intl.NumberFormat('th-TH').format(context.parsed);
                            const total = context.dataset.data.reduce((a, b) => a + b, 0);
                            const percentage = ((context.parsed / total) * 100).toFixed(1);
                            return label + ': ' + value + ' บาท (' + percentage + '%)';
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

// ฟังก์ชันรีเฟรชข้อมูล
function refreshData() {
    location.reload();
}

// ตั้งค่าให้รีเฟรชทุก 5 นาที
setInterval(refreshData, 300000);

// เปลี่ยนสีของแถวตามยอดรายได้
document.addEventListener('DOMContentLoaded', function() {
    const rows = document.querySelectorAll('tbody tr:not(.table-warning)');
    rows.forEach(function(row) {
        const incomeCell = row.querySelector('td:nth-child(3)');
        if (incomeCell) {
            const income = parseFloat(incomeCell.textContent.replace(/[^0-9.-]+/g, ''));
            if (income > 10000) {
                row.classList.add('table-success');
            } else if (income > 5000) {
                row.classList.add('table-info');
            }
        }
    });
});

// เพิ่มการแสดง tooltip สำหรับข้อมูลเพิ่มเติม
document.addEventListener('DOMContentLoaded', function() {
    const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    const tooltipList = tooltipTriggerList.map(function(tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });
});
</script>

<?php require_once 'includes/footer.php'; ?>