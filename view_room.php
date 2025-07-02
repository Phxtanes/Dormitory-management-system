<?php
$page_title = "รายละเอียดห้องพัก";
require_once 'includes/header.php';

// ตรวจสอบ ID ห้องพัก
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header('Location: rooms.php');
    exit;
}

$room_id = $_GET['id'];

// ดึงข้อมูลห้องพัก
try {
    $stmt = $pdo->prepare("
        SELECT r.*,
        (SELECT COUNT(*) FROM contracts c WHERE c.room_id = r.room_id AND c.contract_status = 'active') as has_tenant,
        (SELECT CONCAT(t.first_name, ' ', t.last_name) 
         FROM contracts c 
         JOIN tenants t ON c.tenant_id = t.tenant_id 
         WHERE c.room_id = r.room_id AND c.contract_status = 'active' 
         LIMIT 1) as tenant_name,
        (SELECT t.phone 
         FROM contracts c 
         JOIN tenants t ON c.tenant_id = t.tenant_id 
         WHERE c.room_id = r.room_id AND c.contract_status = 'active' 
         LIMIT 1) as tenant_phone,
        (SELECT t.email 
         FROM contracts c 
         JOIN tenants t ON c.tenant_id = t.tenant_id 
         WHERE c.room_id = r.room_id AND c.contract_status = 'active' 
         LIMIT 1) as tenant_email,
        (SELECT c.contract_start 
         FROM contracts c 
         WHERE c.room_id = r.room_id AND c.contract_status = 'active' 
         LIMIT 1) as contract_start,
        (SELECT c.contract_end 
         FROM contracts c 
         WHERE c.room_id = r.room_id AND c.contract_status = 'active' 
         LIMIT 1) as contract_end,
        (SELECT c.contract_id 
         FROM contracts c 
         WHERE c.room_id = r.room_id AND c.contract_status = 'active' 
         LIMIT 1) as active_contract_id
        FROM rooms r 
        WHERE r.room_id = ?
    ");
    $stmt->execute([$room_id]);
    $room = $stmt->fetch();
    
    if (!$room) {
        header('Location: rooms.php');
        exit;
    }
    
    // ดึงประวัติสัญญาเช่า
    $stmt = $pdo->prepare("
        SELECT c.*, t.first_name, t.last_name, t.phone 
        FROM contracts c 
        JOIN tenants t ON c.tenant_id = t.tenant_id 
        WHERE c.room_id = ? 
        ORDER BY c.created_at DESC
    ");
    $stmt->execute([$room_id]);
    $contract_history = $stmt->fetchAll();
    
    // ดึงข้อมูลการอ่านมิเตอร์ล่าสุด
    $stmt = $pdo->prepare("
        SELECT * FROM utility_readings 
        WHERE room_id = ? 
        ORDER BY reading_date DESC 
        LIMIT 5
    ");
    $stmt->execute([$room_id]);
    $utility_readings = $stmt->fetchAll();
    
    // ดึงข้อมูลใบแจ้งหนี้ล่าสุด (ถ้ามีสัญญาใช้งานอยู่)
    $recent_invoices = [];
    if ($room['active_contract_id']) {
        $stmt = $pdo->prepare("
            SELECT * FROM invoices 
            WHERE contract_id = ? 
            ORDER BY created_at DESC 
            LIMIT 5
        ");
        $stmt->execute([$room['active_contract_id']]);
        $recent_invoices = $stmt->fetchAll();
    }
    
} catch(PDOException $e) {
    $error_message = "เกิดข้อผิดพลาด: " . $e->getMessage();
}
?>

<style>
    body {
        background-color : #CCE5FF;
    }
</style>

<?php include 'includes/navbar.php'; ?>

<div class="container-fluid mt-4">
    <div class="row">
        <div class="col-12">
            <!-- หัวข้อหน้า -->
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2 class="mb-0">
                    <i class="bi bi-door-open"></i>
                    รายละเอียดห้องพัก <?php echo $room['room_number']; ?>
                </h2>
                <div class="btn-group">
                    <a href="rooms.php" class="btn btn-secondary">
                        <i class="bi bi-arrow-left"></i>
                        กลับ
                    </a>
                    <a href="edit_room.php?id=<?php echo $room['room_id']; ?>" class="btn btn-primary">
                        <i class="bi bi-pencil"></i>
                        แก้ไข
                    </a>
                    <?php if ($room['has_tenant'] == 0): ?>
                        <a href="rooms.php?delete=<?php echo $room['room_id']; ?>" 
                           class="btn btn-danger"
                           onclick="return confirmDelete('คุณต้องการลบห้อง <?php echo $room['room_number']; ?> หรือไม่?')">
                            <i class="bi bi-trash"></i>
                            ลบ
                        </a>
                    <?php endif; ?>
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
                <!-- ข้อมูลห้องพัก -->
                <div class="col-lg-6 mb-4">
                    <div class="card h-100">
                        <div class="card-header">
                            <h5 class="mb-0">
                                <i class="bi bi-info-circle"></i>
                                ข้อมูลห้องพัก
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="row mb-3">
                                <div class="col-sm-4">
                                    <strong>หมายเลขห้อง:</strong>
                                </div>
                                <div class="col-sm-8">
                                    <span class="badge bg-primary fs-6"><?php echo $room['room_number']; ?></span>
                                </div>
                            </div>
                            
                            <div class="row mb-3">
                                <div class="col-sm-4">
                                    <strong>ประเภทห้อง:</strong>
                                </div>
                                <div class="col-sm-8">
                                    <?php
                                    $type_text = '';
                                    $type_class = '';
                                    switch ($room['room_type']) {
                                        case 'single':
                                            $type_text = 'ห้องเดี่ยว';
                                            $type_class = 'bg-info';
                                            break;
                                        case 'double':
                                            $type_text = 'ห้องคู่';
                                            $type_class = 'bg-warning';
                                            break;
                                        case 'triple':
                                            $type_text = 'ห้องสาม';
                                            $type_class = 'bg-secondary';
                                            break;
                                    }
                                    ?>
                                    <span class="badge <?php echo $type_class; ?>"><?php echo $type_text; ?></span>
                                </div>
                            </div>
                            
                            <div class="row mb-3">
                                <div class="col-sm-4">
                                    <strong>ชั้น:</strong>
                                </div>
                                <div class="col-sm-8">
                                    ชั้น <?php echo $room['floor_number']; ?>
                                </div>
                            </div>
                            
                            <div class="row mb-3">
                                <div class="col-sm-4">
                                    <strong>ค่าเช่าต่อเดือน:</strong>
                                </div>
                                <div class="col-sm-8">
                                    <span class="text-success fw-bold fs-5"><?php echo formatCurrency($room['monthly_rent']); ?></span>
                                </div>
                            </div>
                            
                            <div class="row mb-3">
                                <div class="col-sm-4">
                                    <strong>เงินมัดจำ:</strong>
                                </div>
                                <div class="col-sm-8">
                                    <span class="text-warning fw-bold"><?php echo formatCurrency($room['deposit']); ?></span>
                                </div>
                            </div>
                            
                            <div class="row mb-3">
                                <div class="col-sm-4">
                                    <strong>สถานะห้อง:</strong>
                                </div>
                                <div class="col-sm-8">
                                    <?php
                                    if ($room['has_tenant'] > 0) {
                                        echo '<span class="badge bg-danger"><i class="bi bi-person-fill"></i> มีผู้เช่า</span>';
                                    } else {
                                        $status_class = $room['room_status'] == 'available' ? 'bg-success' : 'bg-warning';
                                        $status_text = $room['room_status'] == 'available' ? 'ว่าง' : 'ปรับปรุง';
                                        $status_icon = $room['room_status'] == 'available' ? 'bi-check-circle' : 'bi-tools';
                                        echo '<span class="badge ' . $status_class . '"><i class="' . $status_icon . '"></i> ' . $status_text . '</span>';
                                    }
                                    ?>
                                </div>
                            </div>
                            
                            <?php if ($room['room_description']): ?>
                                <div class="row mb-3">
                                    <div class="col-sm-4">
                                        <strong>คำอธิบาย:</strong>
                                    </div>
                                    <div class="col-sm-8">
                                        <?php echo nl2br(htmlspecialchars($room['room_description'])); ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                            
                            <div class="row">
                                <div class="col-sm-4">
                                    <strong>วันที่สร้าง:</strong>
                                </div>
                                <div class="col-sm-8">
                                    <?php echo formatDateTime($room['created_at']); ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ข้อมูลผู้เช่าปัจจุบัน -->
                <div class="col-lg-6 mb-4">
                    <div class="card h-100">
                        <div class="card-header">
                            <h5 class="mb-0">
                                <i class="bi bi-person"></i>
                                ผู้เช่าปัจจุบัน
                            </h5>
                        </div>
                        <div class="card-body">
                            <?php if ($room['has_tenant'] > 0): ?>
                                <div class="row mb-3">
                                    <div class="col-sm-4">
                                        <strong>ชื่อ-นามสกุล:</strong>
                                    </div>
                                    <div class="col-sm-8">
                                        <div class="d-flex align-items-center">
                                            <div class="avatar bg-primary text-white rounded-circle me-2" style="width: 30px; height: 30px; display: flex; align-items: center; justify-content: center; font-size: 0.9rem;">
                                                <?php echo mb_substr($room['tenant_name'], 0, 1, 'UTF-8'); ?>
                                            </div>
                                            <?php echo $room['tenant_name']; ?>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="row mb-3">
                                    <div class="col-sm-4">
                                        <strong>โทรศัพท์:</strong>
                                    </div>
                                    <div class="col-sm-8">
                                        <a href="tel:<?php echo $room['tenant_phone']; ?>" class="text-decoration-none">
                                            <i class="bi bi-telephone"></i>
                                            <?php echo $room['tenant_phone']; ?>
                                        </a>
                                    </div>
                                </div>
                                
                                <?php if ($room['tenant_email']): ?>
                                    <div class="row mb-3">
                                        <div class="col-sm-4">
                                            <strong>อีเมล:</strong>
                                        </div>
                                        <div class="col-sm-8">
                                            <a href="mailto:<?php echo $room['tenant_email']; ?>" class="text-decoration-none">
                                                <i class="bi bi-envelope"></i>
                                                <?php echo $room['tenant_email']; ?>
                                            </a>
                                        </div>
                                    </div>
                                <?php endif; ?>
                                
                                <div class="row mb-3">
                                    <div class="col-sm-4">
                                        <strong>วันที่เข้าพัก:</strong>
                                    </div>
                                    <div class="col-sm-8">
                                        <span class="text-success"><?php echo formatDate($room['contract_start']); ?></span>
                                    </div>
                                </div>
                                
                                <div class="row mb-3">
                                    <div class="col-sm-4">
                                        <strong>สิ้นสุดสัญญา:</strong>
                                    </div>
                                    <div class="col-sm-8">
                                        <?php
                                        $days_left = floor((strtotime($room['contract_end']) - time()) / (60*60*24));
                                        $text_class = $days_left <= 30 ? 'text-warning' : 'text-info';
                                        if ($days_left < 0) $text_class = 'text-danger';
                                        ?>
                                        <span class="<?php echo $text_class; ?>">
                                            <?php echo formatDate($room['contract_end']); ?>
                                            (<?php echo $days_left > 0 ? "เหลือ $days_left วัน" : "เกินกำหนด " . abs($days_left) . " วัน"; ?>)
                                        </span>
                                    </div>
                                </div>
                                
                                <div class="mt-4">
                                    <div class="d-grid gap-2 d-md-flex">
                                        <a href="view_tenant.php?id=<?php echo $room['active_contract_id']; ?>" class="btn btn-outline-info btn-sm">
                                            <i class="bi bi-person"></i>
                                            ดูข้อมูลผู้เช่า
                                        </a>
                                        <a href="view_contract.php?id=<?php echo $room['active_contract_id']; ?>" class="btn btn-outline-primary btn-sm">
                                            <i class="bi bi-file-earmark-text"></i>
                                            ดูสัญญา
                                        </a>
                                        <a href="invoices.php?contract_id=<?php echo $room['active_contract_id']; ?>" class="btn btn-outline-success btn-sm">
                                            <i class="bi bi-receipt"></i>
                                            ใบแจ้งหนี้
                                        </a>
                                    </div>
                                </div>
                            <?php else: ?>
                                <div class="text-center py-4">
                                    <i class="bi bi-person-plus display-4 text-muted"></i>
                                    <h6 class="text-muted mt-2">ไม่มีผู้เช่า</h6>
                                    <p class="text-muted">ห้องนี้ว่างอยู่</p>
                                    <a href="add_contract.php?room_id=<?php echo $room['room_id']; ?>" class="btn btn-primary">
                                        <i class="bi bi-file-earmark-plus"></i>
                                        สร้างสัญญาเช่า
                                    </a>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- การอ่านมิเตอร์ล่าสุด -->
            <div class="row">
                <div class="col-lg-6 mb-4">
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5 class="mb-0">
                                <i class="bi bi-speedometer2"></i>
                                การอ่านมิเตอร์ล่าสุด
                            </h5>
                            <a href="utility_readings.php?room_id=<?php echo $room['room_id']; ?>" class="btn btn-sm btn-outline-primary">
                                <i class="bi bi-plus"></i>
                                บันทึกมิเตอร์
                            </a>
                        </div>
                        <div class="card-body">
                            <?php if (empty($utility_readings)): ?>
                                <div class="text-center py-3">
                                    <i class="bi bi-speedometer2 display-4 text-muted"></i>
                                    <p class="text-muted mt-2">ยังไม่มีการบันทึกมิเตอร์</p>
                                </div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-sm">
                                        <thead>
                                            <tr>
                                                <th>เดือน</th>
                                                <th>น้ำ</th>
                                                <th>ไฟ</th>
                                                <th>วันที่บันทึก</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach (array_slice($utility_readings, 0, 3) as $reading): ?>
                                                <tr>
                                                    <td><?php echo $reading['reading_month']; ?></td>
                                                    <td>
                                                        <?php echo number_format($reading['water_current'], 2); ?>
                                                        <small class="text-muted">
                                                            (<?php echo number_format($reading['water_current'] - $reading['water_previous'], 2); ?>)
                                                        </small>
                                                    </td>
                                                    <td>
                                                        <?php echo number_format($reading['electric_current'], 2); ?>
                                                        <small class="text-muted">
                                                            (<?php echo number_format($reading['electric_current'] - $reading['electric_previous'], 2); ?>)
                                                        </small>
                                                    </td>
                                                    <td><?php echo formatDate($reading['reading_date']); ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <?php if (count($utility_readings) > 3): ?>
                                    <div class="text-center mt-2">
                                        <a href="utility_readings.php?room_id=<?php echo $room['room_id']; ?>" class="btn btn-sm btn-outline-secondary">
                                            ดูทั้งหมด (<?php echo count($utility_readings); ?> รายการ)
                                        </a>
                                    </div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- ใบแจ้งหนี้ล่าสุด -->
                <div class="col-lg-6 mb-4">
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5 class="mb-0">
                                <i class="bi bi-receipt"></i>
                                ใบแจ้งหนี้ล่าสุด
                            </h5>
                            <?php if ($room['active_contract_id']): ?>
                                <a href="invoices.php?contract_id=<?php echo $room['active_contract_id']; ?>" class="btn btn-sm btn-outline-primary">
                                    ดูทั้งหมด
                                </a>
                            <?php endif; ?>
                        </div>
                        <div class="card-body">
                            <?php if (empty($recent_invoices)): ?>
                                <div class="text-center py-3">
                                    <i class="bi bi-receipt display-4 text-muted"></i>
                                    <p class="text-muted mt-2">ยังไม่มีใบแจ้งหนี้</p>
                                </div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-sm">
                                        <thead>
                                            <tr>
                                                <th>เดือน</th>
                                                <th>จำนวนเงิน</th>
                                                <th>สถานะ</th>
                                                <th>กำหนดชำระ</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach (array_slice($recent_invoices, 0, 3) as $invoice): ?>
                                                <tr>
                                                    <td><?php echo $invoice['invoice_month']; ?></td>
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
                                                        }
                                                        ?>
                                                        <span class="badge <?php echo $status_class; ?>"><?php echo $status_text; ?></span>
                                                    </td>
                                                    <td><?php echo formatDate($invoice['due_date']); ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <?php if (count($recent_invoices) > 3): ?>
                                    <div class="text-center mt-2">
                                        <a href="invoices.php?contract_id=<?php echo $room['active_contract_id']; ?>" class="btn btn-sm btn-outline-secondary">
                                            ดูทั้งหมด (<?php echo count($recent_invoices); ?> รายการ)
                                        </a>
                                    </div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ประวัติสัญญาเช่า -->
            <div class="row">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">
                                <i class="bi bi-clock-history"></i>
                                ประวัติสัญญาเช่า
                            </h5>
                        </div>
                        <div class="card-body">
                            <?php if (empty($contract_history)): ?>
                                <div class="text-center py-3">
                                    <i class="bi bi-file-earmark display-4 text-muted"></i>
                                    <p class="text-muted mt-2">ยังไม่มีประวัติสัญญาเช่า</p>
                                </div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table">
                                        <thead>
                                            <tr>
                                                <th>ผู้เช่า</th>
                                                <th>วันที่เริ่มสัญญา</th>
                                                <th>วันที่สิ้นสุด</th>
                                                <th>ค่าเช่า/เดือน</th>
                                                <th>สถานะ</th>
                                                <th>จัดการ</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($contract_history as $contract): ?>
                                                <tr>
                                                    <td>
                                                        <div class="d-flex align-items-center">
                                                            <div class="avatar bg-secondary text-white rounded-circle me-2" style="width: 30px; height: 30px; display: flex; align-items: center; justify-content: center; font-size: 0.8rem;">
                                                                <?php echo mb_substr($contract['first_name'], 0, 1, 'UTF-8'); ?>
                                                            </div>
                                                            <div>
                                                                <?php echo $contract['first_name'] . ' ' . $contract['last_name']; ?>
                                                                <br><small class="text-muted"><?php echo $contract['phone']; ?></small>
                                                            </div>
                                                        </div>
                                                    </td>
                                                    <td><?php echo formatDate($contract['contract_start']); ?></td>
                                                    <td><?php echo formatDate($contract['contract_end']); ?></td>
                                                    <td><?php echo formatCurrency($contract['monthly_rent']); ?></td>
                                                    <td>
                                                        <?php
                                                        $status_class = '';
                                                        $status_text = '';
                                                        switch ($contract['contract_status']) {
                                                            case 'active':
                                                                $status_class = 'bg-success';
                                                                $status_text = 'ใช้งานอยู่';
                                                                break;
                                                            case 'expired':
                                                                $status_class = 'bg-secondary';
                                                                $status_text = 'หมดอายุ';
                                                                break;
                                                            case 'terminated':
                                                                $status_class = 'bg-danger';
                                                                $status_text = 'ยกเลิก';
                                                                break;
                                                        }
                                                        ?>
                                                        <span class="badge <?php echo $status_class; ?>"><?php echo $status_text; ?></span>
                                                    </td>
                                                    <td>
                                                        <a href="view_contract.php?id=<?php echo $contract['contract_id']; ?>" class="btn btn-sm btn-outline-info">
                                                            <i class="bi bi-eye"></i>
                                                            ดู
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
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.avatar {
    font-weight: 600;
}

.card {
    transition: all 0.3s ease-in-out;
}

.badge {
    font-size: 0.75rem;
}
</style>

<?php include 'includes/footer.php'; ?>