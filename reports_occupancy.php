<?php
$page_title = "รายงานการเข้าพัก";
require_once 'includes/header.php';

// ตั้งค่าช่วงเวลาค้นหา
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-01');
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-t');
$room_type_filter = isset($_GET['room_type']) ? $_GET['room_type'] : '';
$floor_filter = isset($_GET['floor']) ? $_GET['floor'] : '';

try {
    // ดึงข้อมูลสถิติพื้นฐาน
    $basic_stats_sql = "SELECT 
        COUNT(*) as total_rooms,
        SUM(CASE WHEN EXISTS (SELECT 1 FROM contracts c WHERE c.room_id = r.room_id AND c.contract_status = 'active') THEN 1 ELSE 0 END) as occupied_rooms,
        SUM(CASE WHEN r.room_status = 'available' AND NOT EXISTS (SELECT 1 FROM contracts c WHERE c.room_id = r.room_id AND c.contract_status = 'active') THEN 1 ELSE 0 END) as available_rooms,
        SUM(CASE WHEN r.room_status = 'maintenance' THEN 1 ELSE 0 END) as maintenance_rooms,
        AVG(r.monthly_rent) as avg_rent
        FROM rooms r";
    
    $params = [];
    $where_conditions = [];
    
    if (!empty($room_type_filter)) {
        $where_conditions[] = "r.room_type = ?";
        $params[] = $room_type_filter;
    }
    
    if (!empty($floor_filter)) {
        $where_conditions[] = "r.floor_number = ?";
        $params[] = $floor_filter;
    }
    
    if (!empty($where_conditions)) {
        $basic_stats_sql .= " WHERE " . implode(" AND ", $where_conditions);
    }
    
    $stmt = $pdo->prepare($basic_stats_sql);
    $stmt->execute($params);
    $basic_stats = $stmt->fetch();
    
    // คำนวณอัตราการเข้าพัก
    $occupancy_rate = $basic_stats['total_rooms'] > 0 ? 
        ($basic_stats['occupied_rooms'] / $basic_stats['total_rooms']) * 100 : 0;
    
    // ดึงข้อมูลการเข้าพักตามประเภทห้อง
    $room_type_sql = "SELECT 
        r.room_type,
        COUNT(*) as total_rooms,
        SUM(CASE WHEN EXISTS (SELECT 1 FROM contracts c WHERE c.room_id = r.room_id AND c.contract_status = 'active') THEN 1 ELSE 0 END) as occupied_rooms,
        AVG(r.monthly_rent) as avg_rent,
        SUM(CASE WHEN EXISTS (SELECT 1 FROM contracts c WHERE c.room_id = r.room_id AND c.contract_status = 'active') THEN r.monthly_rent ELSE 0 END) as monthly_revenue
        FROM rooms r";
    
    if (!empty($where_conditions)) {
        $room_type_sql .= " WHERE " . implode(" AND ", $where_conditions);
    }
    
    $room_type_sql .= " GROUP BY r.room_type ORDER BY r.room_type";
    
    $stmt = $pdo->prepare($room_type_sql);
    $stmt->execute($params);
    $room_type_stats = $stmt->fetchAll();
    
    // ดึงข้อมูลการเข้าพักตามชั้น
    $floor_sql = "SELECT 
        r.floor_number,
        COUNT(*) as total_rooms,
        SUM(CASE WHEN EXISTS (SELECT 1 FROM contracts c WHERE c.room_id = r.room_id AND c.contract_status = 'active') THEN 1 ELSE 0 END) as occupied_rooms,
        SUM(CASE WHEN r.room_status = 'available' AND NOT EXISTS (SELECT 1 FROM contracts c WHERE c.room_id = r.room_id AND c.contract_status = 'active') THEN 1 ELSE 0 END) as available_rooms,
        SUM(CASE WHEN r.room_status = 'maintenance' THEN 1 ELSE 0 END) as maintenance_rooms
        FROM rooms r";
    
    if (!empty($where_conditions)) {
        $floor_sql .= " WHERE " . implode(" AND ", $where_conditions);
    }
    
    $floor_sql .= " GROUP BY r.floor_number ORDER BY r.floor_number";
    
    $stmt = $pdo->prepare($floor_sql);
    $stmt->execute($params);
    $floor_stats = $stmt->fetchAll();
    
    // ดึงข้อมูลสัญญาใหม่ในช่วงเวลาที่เลือก
    $new_contracts_sql = "SELECT 
        COUNT(*) as new_contracts,
        AVG(c.monthly_rent) as avg_new_rent,
        SUM(c.monthly_rent) as total_new_revenue
        FROM contracts c
        WHERE c.contract_start BETWEEN ? AND ? AND c.contract_status = 'active'";
    
    $stmt = $pdo->prepare($new_contracts_sql);
    $stmt->execute([$start_date, $end_date]);
    $new_contracts_stats = $stmt->fetch();
    
    // ดึงข้อมูลสัญญาที่สิ้นสุดในช่วงเวลาที่เลือก
    $ended_contracts_sql = "SELECT 
        COUNT(*) as ended_contracts,
        AVG(c.monthly_rent) as avg_ended_rent,
        SUM(c.monthly_rent) as total_lost_revenue
        FROM contracts c
        WHERE c.contract_end BETWEEN ? AND ? AND c.contract_status IN ('expired', 'terminated')";
    
    $stmt = $pdo->prepare($ended_contracts_sql);
    $stmt->execute([$start_date, $end_date]);
    $ended_contracts_stats = $stmt->fetch();
    
    // ดึงข้อมูลรายการห้องพักทั้งหมด
    $rooms_detail_sql = "SELECT 
        r.*,
        CASE WHEN EXISTS (SELECT 1 FROM contracts c WHERE c.room_id = r.room_id AND c.contract_status = 'active') THEN 'occupied' ELSE r.room_status END as actual_status,
        (SELECT CONCAT(t.first_name, ' ', t.last_name) 
         FROM contracts c 
         JOIN tenants t ON c.tenant_id = t.tenant_id 
         WHERE c.room_id = r.room_id AND c.contract_status = 'active' 
         LIMIT 1) as tenant_name,
        (SELECT c.contract_start 
         FROM contracts c 
         WHERE c.room_id = r.room_id AND c.contract_status = 'active' 
         LIMIT 1) as move_in_date,
        (SELECT c.contract_end 
         FROM contracts c 
         WHERE c.room_id = r.room_id AND c.contract_status = 'active' 
         LIMIT 1) as contract_end_date,
        (SELECT DATEDIFF(c.contract_end, CURDATE()) 
         FROM contracts c 
         WHERE c.room_id = r.room_id AND c.contract_status = 'active' 
         LIMIT 1) as days_until_end
        FROM rooms r";
    
    if (!empty($where_conditions)) {
        $rooms_detail_sql .= " WHERE " . implode(" AND ", $where_conditions);
    }
    
    $rooms_detail_sql .= " ORDER BY r.floor_number, CAST(SUBSTRING(r.room_number, 2) AS UNSIGNED)";
    
    $stmt = $pdo->prepare($rooms_detail_sql);
    $stmt->execute($params);
    $rooms_detail = $stmt->fetchAll();
    
    // ดึงข้อมูลรายได้รายเดือนย้อนหลัง 12 เดือน
    $monthly_revenue_sql = "SELECT 
        DATE_FORMAT(p.payment_date, '%Y-%m') as month,
        SUM(p.payment_amount) as total_revenue,
        COUNT(DISTINCT i.contract_id) as paying_tenants
        FROM payments p
        JOIN invoices i ON p.invoice_id = i.invoice_id
        WHERE p.payment_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
        GROUP BY DATE_FORMAT(p.payment_date, '%Y-%m')
        ORDER BY month DESC
        LIMIT 12";
    
    $stmt = $pdo->query($monthly_revenue_sql);
    $monthly_revenue = $stmt->fetchAll();
    
    // ดึงรายการชั้น
    $floors_sql = "SELECT DISTINCT floor_number FROM rooms ORDER BY floor_number";
    $stmt = $pdo->query($floors_sql);
    $available_floors = $stmt->fetchAll();
    
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
                    <i class="bi bi-graph-up"></i>
                    รายงานการเข้าพัก
                </h2>
                <div class="btn-group">
                    <button type="button" class="btn btn-outline-secondary" onclick="window.print()">
                        <i class="bi bi-printer"></i>
                        พิมพ์รายงาน
                    </button>
                    <button type="button" class="btn btn-outline-primary" onclick="exportToExcel()">
                        <i class="bi bi-download"></i>
                        ส่งออก Excel
                    </button>
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

            <!-- ฟอร์มเลือกช่วงเวลาและกรอง -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0">
                        <i class="bi bi-funnel"></i>
                        ตัวเลือกรายงาน
                    </h5>
                </div>
                <div class="card-body">
                    <form method="GET" class="row g-3">
                        <div class="col-md-3">
                            <label for="start_date" class="form-label">วันที่เริ่มต้น</label>
                            <input type="date" class="form-control" id="start_date" name="start_date" 
                                   value="<?php echo htmlspecialchars($start_date); ?>">
                        </div>
                        <div class="col-md-3">
                            <label for="end_date" class="form-label">วันที่สิ้นสุด</label>
                            <input type="date" class="form-control" id="end_date" name="end_date" 
                                   value="<?php echo htmlspecialchars($end_date); ?>">
                        </div>
                        <div class="col-md-2">
                            <label for="room_type" class="form-label">ประเภทห้อง</label>
                            <select class="form-select" id="room_type" name="room_type">
                                <option value="">ทุกประเภท</option>
                                <option value="single" <?php echo $room_type_filter == 'single' ? 'selected' : ''; ?>>ห้องเดี่ยว</option>
                                <option value="double" <?php echo $room_type_filter == 'double' ? 'selected' : ''; ?>>ห้องคู่</option>
                                <option value="triple" <?php echo $room_type_filter == 'triple' ? 'selected' : ''; ?>>ห้องสาม</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label for="floor" class="form-label">ชั้น</label>
                            <select class="form-select" id="floor" name="floor">
                                <option value="">ทุกชั้น</option>
                                <?php foreach ($available_floors as $floor): ?>
                                    <option value="<?php echo $floor['floor_number']; ?>" 
                                            <?php echo $floor_filter == $floor['floor_number'] ? 'selected' : ''; ?>>
                                        ชั้น <?php echo $floor['floor_number']; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">&nbsp;</label>
                            <div class="d-grid">
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-search"></i>
                                    สร้างรายงาน
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <!-- สถิติภาพรวม -->
            <div class="row mb-4">
                <div class="col-lg-3 col-md-6 mb-3">
                    <div class="card text-white bg-primary h-100">
                        <div class="card-body text-center">
                            <i class="bi bi-building fs-2"></i>
                            <h3 class="mt-2"><?php echo number_format($basic_stats['total_rooms']); ?></h3>
                            <p class="mb-0">ห้องพักทั้งหมด</p>
                        </div>
                    </div>
                </div>
                <div class="col-lg-3 col-md-6 mb-3">
                    <div class="card text-white bg-danger h-100">
                        <div class="card-body text-center">
                            <i class="bi bi-person-fill fs-2"></i>
                            <h3 class="mt-2"><?php echo number_format($basic_stats['occupied_rooms']); ?></h3>
                            <p class="mb-0">ห้องที่มีผู้เช่า</p>
                        </div>
                    </div>
                </div>
                <div class="col-lg-3 col-md-6 mb-3">
                    <div class="card text-white bg-success h-100">
                        <div class="card-body text-center">
                            <i class="bi bi-door-open fs-2"></i>
                            <h3 class="mt-2"><?php echo number_format($basic_stats['available_rooms']); ?></h3>
                            <p class="mb-0">ห้องว่าง</p>
                        </div>
                    </div>
                </div>
                <div class="col-lg-3 col-md-6 mb-3">
                    <div class="card text-white bg-info h-100">
                        <div class="card-body text-center">
                            <i class="bi bi-percent fs-2"></i>
                            <h3 class="mt-2"><?php echo number_format($occupancy_rate, 1); ?>%</h3>
                            <p class="mb-0">อัตราการเข้าพัก</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- กราฟแสดงอัตราการเข้าพัก -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0">
                        <i class="bi bi-pie-chart"></i>
                        สัดส่วนการเข้าพัก
                    </h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <canvas id="occupancyChart" width="400" height="200"></canvas>
                        </div>
                        <div class="col-md-6">
                            <div class="row text-center">
                                <div class="col-6 mb-3">
                                    <div class="border rounded p-3">
                                        <h4 class="text-danger"><?php echo number_format($basic_stats['occupied_rooms']); ?></h4>
                                        <p class="mb-0 text-muted">ห้องที่มีผู้เช่า</p>
                                        <small class="text-muted">
                                            <?php echo $basic_stats['total_rooms'] > 0 ? number_format(($basic_stats['occupied_rooms'] / $basic_stats['total_rooms']) * 100, 1) : 0; ?>%
                                        </small>
                                    </div>
                                </div>
                                <div class="col-6 mb-3">
                                    <div class="border rounded p-3">
                                        <h4 class="text-success"><?php echo number_format($basic_stats['available_rooms']); ?></h4>
                                        <p class="mb-0 text-muted">ห้องว่าง</p>
                                        <small class="text-muted">
                                            <?php echo $basic_stats['total_rooms'] > 0 ? number_format(($basic_stats['available_rooms'] / $basic_stats['total_rooms']) * 100, 1) : 0; ?>%
                                        </small>
                                    </div>
                                </div>
                                <div class="col-6 mb-3">
                                    <div class="border rounded p-3">
                                        <h4 class="text-warning"><?php echo number_format($basic_stats['maintenance_rooms']); ?></h4>
                                        <p class="mb-0 text-muted">ห้องปรับปรุง</p>
                                        <small class="text-muted">
                                            <?php echo $basic_stats['total_rooms'] > 0 ? number_format(($basic_stats['maintenance_rooms'] / $basic_stats['total_rooms']) * 100, 1) : 0; ?>%
                                        </small>
                                    </div>
                                </div>
                                <div class="col-6 mb-3">
                                    <div class="border rounded p-3">
                                        <h4 class="text-info"><?php echo formatCurrency($basic_stats['avg_rent']); ?></h4>
                                        <p class="mb-0 text-muted">ค่าเช่าเฉลี่ย</p>
                                        <small class="text-muted">ต่อห้อง/เดือน</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- สถิติตามประเภทห้อง -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0">
                        <i class="bi bi-bar-chart"></i>
                        การเข้าพักตามประเภทห้อง
                    </h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-striped">
                            <thead>
                                <tr>
                                    <th>ประเภทห้อง</th>
                                    <th class="text-center">ห้องทั้งหมด</th>
                                    <th class="text-center">ห้องที่มีผู้เช่า</th>
                                    <th class="text-center">อัตราการเข้าพัก</th>
                                    <th class="text-end">ค่าเช่าเฉลี่ย</th>
                                    <th class="text-end">รายได้/เดือน</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($room_type_stats as $type_stat): ?>
                                    <tr>
                                        <td>
                                            <strong>
                                                <?php
                                                switch ($type_stat['room_type']) {
                                                    case 'single': echo 'ห้องเดี่ยว'; break;
                                                    case 'double': echo 'ห้องคู่'; break;
                                                    case 'triple': echo 'ห้องสาม'; break;
                                                }
                                                ?>
                                            </strong>
                                        </td>
                                        <td class="text-center"><?php echo number_format($type_stat['total_rooms']); ?></td>
                                        <td class="text-center">
                                            <span class="badge bg-primary"><?php echo number_format($type_stat['occupied_rooms']); ?></span>
                                        </td>
                                        <td class="text-center">
                                            <?php 
                                            $type_occupancy = $type_stat['total_rooms'] > 0 ? 
                                                ($type_stat['occupied_rooms'] / $type_stat['total_rooms']) * 100 : 0;
                                            $badge_class = $type_occupancy >= 80 ? 'bg-success' : 
                                                          ($type_occupancy >= 60 ? 'bg-warning' : 'bg-danger');
                                            ?>
                                            <span class="badge <?php echo $badge_class; ?>">
                                                <?php echo number_format($type_occupancy, 1); ?>%
                                            </span>
                                        </td>
                                        <td class="text-end"><?php echo formatCurrency($type_stat['avg_rent']); ?></td>
                                        <td class="text-end">
                                            <strong><?php echo formatCurrency($type_stat['monthly_revenue']); ?></strong>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                                <tr class="table-light">
                                    <th>รวม</th>
                                    <th class="text-center"><?php echo number_format($basic_stats['total_rooms']); ?></th>
                                    <th class="text-center"><?php echo number_format($basic_stats['occupied_rooms']); ?></th>
                                    <th class="text-center">
                                        <span class="badge bg-info"><?php echo number_format($occupancy_rate, 1); ?>%</span>
                                    </th>
                                    <th class="text-end"><?php echo formatCurrency($basic_stats['avg_rent']); ?></th>
                                    <th class="text-end">
                                        <strong><?php echo formatCurrency(array_sum(array_column($room_type_stats, 'monthly_revenue'))); ?></strong>
                                    </th>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>

            <!-- สถิติตามชั้น -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0">
                        <i class="bi bi-building"></i>
                        การเข้าพักตามชั้น
                    </h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <?php foreach ($floor_stats as $floor_stat): ?>
                            <div class="col-lg-3 col-md-6 mb-3">
                                <div class="card h-100">
                                    <div class="card-header text-center bg-light">
                                        <h6 class="mb-0">ชั้น <?php echo $floor_stat['floor_number']; ?></h6>
                                    </div>
                                    <div class="card-body text-center">
                                        <div class="row">
                                            <div class="col-12 mb-2">
                                                <h5 class="text-primary"><?php echo $floor_stat['total_rooms']; ?></h5>
                                                <small class="text-muted">ห้องทั้งหมด</small>
                                            </div>
                                        </div>
                                        <div class="row text-center">
                                            <div class="col-4">
                                                <h6 class="text-danger"><?php echo $floor_stat['occupied_rooms']; ?></h6>
                                                <small class="text-muted">มีผู้เช่า</small>
                                            </div>
                                            <div class="col-4">
                                                <h6 class="text-success"><?php echo $floor_stat['available_rooms']; ?></h6>
                                                <small class="text-muted">ว่าง</small>
                                            </div>
                                            <div class="col-4">
                                                <h6 class="text-warning"><?php echo $floor_stat['maintenance_rooms']; ?></h6>
                                                <small class="text-muted">ปรับปรุง</small>
                                            </div>
                                        </div>
                                        <hr>
                                        <div class="progress" style="height: 8px;">
                                            <?php 
                                            $floor_occupancy = $floor_stat['total_rooms'] > 0 ? 
                                                ($floor_stat['occupied_rooms'] / $floor_stat['total_rooms']) * 100 : 0;
                                            ?>
                                            <div class="progress-bar" style="width: <?php echo $floor_occupancy; ?>%"></div>
                                        </div>
                                        <small class="text-muted mt-1 d-block">
                                            อัตราการเข้าพัก <?php echo number_format($floor_occupancy, 1); ?>%
                                        </small>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- สถิติสัญญาใหม่และสิ้นสุด -->
            <div class="row mb-4">
                <div class="col-md-6">
                    <div class="card h-100">
                        <div class="card-header bg-success text-white">
                            <h6 class="mb-0">
                                <i class="bi bi-plus-circle"></i>
                                สัญญาใหม่ (<?php echo formatDate($start_date) . ' - ' . formatDate($end_date); ?>)
                            </h6>
                        </div>
                        <div class="card-body">
                            <div class="row text-center">
                                <div class="col-4">
                                    <h4 class="text-success"><?php echo number_format($new_contracts_stats['new_contracts']); ?></h4>
                                    <p class="mb-0 text-muted">สัญญาใหม่</p>
                                </div>
                                <div class="col-4">
                                    <h5 class="text-info"><?php echo formatCurrency($new_contracts_stats['avg_new_rent']); ?></h5>
                                    <p class="mb-0 text-muted">ค่าเช่าเฉลี่ย</p>
                                </div>
                                <div class="col-4">
                                    <h5 class="text-primary"><?php echo formatCurrency($new_contracts_stats['total_new_revenue']); ?></h5>
                                    <p class="mb-0 text-muted">รายได้เพิ่ม/เดือน</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="card h-100">
                        <div class="card-header bg-danger text-white">
                            <h6 class="mb-0">
                                <i class="bi bi-dash-circle"></i>
                                สัญญาสิ้นสุด (<?php echo formatDate($start_date) . ' - ' . formatDate($end_date); ?>)
                            </h6>
                        </div>
                        <div class="card-body">
                            <div class="row text-center">
                                <div class="col-4">
                                    <h4 class="text-danger"><?php echo number_format($ended_contracts_stats['ended_contracts']); ?></h4>
                                    <p class="mb-0 text-muted">สัญญาสิ้นสุด</p>
                                </div>
                                <div class="col-4">
                                    <h5 class="text-info"><?php echo formatCurrency($ended_contracts_stats['avg_ended_rent']); ?></h5>
                                    <p class="mb-0 text-muted">ค่าเช่าเฉลี่ย</p>
                                </div>
                                <div class="col-4">
                                    <h5 class="text-warning"><?php echo formatCurrency($ended_contracts_stats['total_lost_revenue']); ?></h5>
                                    <p class="mb-0 text-muted">รายได้ที่สูญเสีย</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- กราฟรายได้รายเดือน -->
            <?php if (!empty($monthly_revenue)): ?>
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0">
                            <i class="bi bi-graph-up"></i>
                            แนวโน้มรายได้รายเดือน (12 เดือนย้อนหลัง)
                        </h5>
                    </div>
                    <div class="card-body">
                        <canvas id="revenueChart" width="400" height="150"></canvas>
                    </div>
                </div>
            <?php endif; ?>

            <!-- รายละเอียดห้องพัก -->
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">
                        <i class="bi bi-list-ul"></i>
                        รายละเอียดห้องพัก
                    </h5>
                    <div class="d-flex gap-3">
                        <span class="badge bg-danger"><i class="bi bi-circle-fill"></i> มีผู้เช่า</span>
                        <span class="badge bg-success"><i class="bi bi-circle-fill"></i> ว่าง</span>
                        <span class="badge bg-warning"><i class="bi bi-circle-fill"></i> ปรับปรุง</span>
                    </div>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover table-sm">
                            <thead>
                                <tr>
                                    <th>หมายเลขห้อง</th>
                                    <th>ประเภท</th>
                                    <th>ชั้น</th>
                                    <th>ค่าเช่า</th>
                                    <th>สถานะ</th>
                                    <th>ผู้เช่า</th>
                                    <th>วันที่เข้าพัก</th>
                                    <th>สิ้นสุดสัญญา</th>
                                    <th>วันที่เหลือ</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($rooms_detail as $room): ?>
                                    <tr>
                                        <td><strong><?php echo $room['room_number']; ?></strong></td>
                                        <td>
                                            <?php
                                            switch ($room['room_type']) {
                                                case 'single': echo 'เดี่ยว'; break;
                                                case 'double': echo 'คู่'; break;
                                                case 'triple': echo 'สาม'; break;
                                            }
                                            ?>
                                        </td>
                                        <td><?php echo $room['floor_number']; ?></td>
                                        <td><?php echo formatCurrency($room['monthly_rent']); ?></td>
                                        <td>
                                            <?php
                                            $status_class = '';
                                            $status_text = '';
                                            switch ($room['actual_status']) {
                                                case 'occupied':
                                                    $status_class = 'bg-danger';
                                                    $status_text = 'มีผู้เช่า';
                                                    break;
                                                case 'available':
                                                    $status_class = 'bg-success';
                                                    $status_text = 'ว่าง';
                                                    break;
                                                case 'maintenance':
                                                    $status_class = 'bg-warning';
                                                    $status_text = 'ปรับปรุง';
                                                    break;
                                            }
                                            ?>
                                            <span class="badge <?php echo $status_class; ?>">
                                                <?php echo $status_text; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($room['tenant_name']): ?>
                                                <small><?php echo $room['tenant_name']; ?></small>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($room['move_in_date']): ?>
                                                <small><?php echo formatDate($room['move_in_date']); ?></small>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($room['contract_end_date']): ?>
                                                <small><?php echo formatDate($room['contract_end_date']); ?></small>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($room['days_until_end'] !== null): ?>
                                                <?php if ($room['days_until_end'] > 0): ?>
                                                    <small class="<?php echo $room['days_until_end'] <= 30 ? 'text-warning' : 'text-muted'; ?>">
                                                        <?php echo $room['days_until_end']; ?> วัน
                                                    </small>
                                                <?php else: ?>
                                                    <small class="text-danger">หมดอายุแล้ว</small>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
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
</div>

<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // กราฟวงกลมแสดงสัดส่วนการเข้าพัก
    const occupancyCtx = document.getElementById('occupancyChart').getContext('2d');
    new Chart(occupancyCtx, {
        type: 'doughnut',
        data: {
            labels: ['มีผู้เช่า', 'ว่าง', 'ปรับปรุง'],
            datasets: [{
                data: [
                    <?php echo $basic_stats['occupied_rooms']; ?>,
                    <?php echo $basic_stats['available_rooms']; ?>,
                    <?php echo $basic_stats['maintenance_rooms']; ?>
                ],
                backgroundColor: ['#dc3545', '#28a745', '#ffc107'],
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
                            const percentage = ((context.parsed * 100) / total).toFixed(1);
                            return context.label + ': ' + context.parsed + ' ห้อง (' + percentage + '%)';
                        }
                    }
                }
            }
        }
    });

    <?php if (!empty($monthly_revenue)): ?>
    // กราฟแนวโน้มรายได้รายเดือน
    const revenueCtx = document.getElementById('revenueChart').getContext('2d');
    new Chart(revenueCtx, {
        type: 'line',
        data: {
            labels: [
                <?php 
                $months = array_reverse($monthly_revenue);
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
                        echo $month['total_revenue'] . ",";
                    }
                    ?>
                ],
                borderColor: '#007bff',
                backgroundColor: 'rgba(0, 123, 255, 0.1)',
                borderWidth: 2,
                fill: true,
                tension: 0.4
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
            interaction: {
                intersect: false,
                mode: 'index'
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
    
    // ฟังก์ชันส่งออก Excel
    window.exportToExcel = function() {
        // สร้าง URL สำหรับส่งออกข้อมูล
        const params = new URLSearchParams(window.location.search);
        params.set('export', 'excel');
        window.open('export_occupancy.php?' + params.toString(), '_blank');
    };
});
</script>

<style>
@media print {
    .btn, .card-header .btn-group {
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
        size: A4;
    }
    
    body {
        font-size: 12px;
    }
    
    h2, h5 {
        color: #000 !important;
    }
}

.progress {
    background-color: #e9ecef;
}

.progress-bar {
    background-color: #007bff;
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
}

.badge {
    font-size: 0.75rem;
}

canvas {
    max-height: 300px;
}

.border-end {
    border-right: 1px solid #dee2e6 !important;
}

@media (max-width: 768px) {
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
    
    .table-responsive {
        font-size: 0.875rem;
    }
}
</style>

<?php include 'includes/footer.php'; ?>