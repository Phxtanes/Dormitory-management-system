<?php
$page_title = "จัดการผู้เช่า";
require_once 'includes/header.php';

// ตรวจสอบการลบผู้เช่า
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    try {
        // ตรวจสอบว่าผู้เช่ามีสัญญาที่ใช้งานอยู่หรือไม่
        $stmt = $pdo->prepare("SELECT COUNT(*) as contract_count FROM contracts WHERE tenant_id = ? AND contract_status = 'active'");
        $stmt->execute([$_GET['delete']]);
        $contract_count = $stmt->fetch()['contract_count'];
        
        if ($contract_count > 0) {
            $error_message = "ไม่สามารถลบผู้เช่านี้ได้ เนื่องจากมีสัญญาที่ใช้งานอยู่";
        } else {
            $stmt = $pdo->prepare("DELETE FROM tenants WHERE tenant_id = ?");
            $stmt->execute([$_GET['delete']]);
            $success_message = "ลบข้อมูลผู้เช่าเรียบร้อยแล้ว";
        }
    } catch(PDOException $e) {
        $error_message = "เกิดข้อผิดพลาด: " . $e->getMessage();
    }
}

// ดึงข้อมูลผู้เช่าทั้งหมด
$search = isset($_GET['search']) ? $_GET['search'] : '';
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';

$sql = "SELECT t.*, 
        (SELECT COUNT(*) FROM contracts c WHERE c.tenant_id = t.tenant_id AND c.contract_status = 'active') as has_active_contract,
        (SELECT r.room_number 
         FROM contracts c 
         JOIN rooms r ON c.room_id = r.room_id 
         WHERE c.tenant_id = t.tenant_id AND c.contract_status = 'active' 
         LIMIT 1) as current_room,
        (SELECT c.contract_start 
         FROM contracts c 
         WHERE c.tenant_id = t.tenant_id AND c.contract_status = 'active' 
         LIMIT 1) as contract_start_date
        FROM tenants t WHERE 1=1";

$params = [];

if (!empty($search)) {
    $sql .= " AND (t.first_name LIKE ? OR t.last_name LIKE ? OR t.phone LIKE ? OR t.email LIKE ? OR t.id_card LIKE ?)";
    $search_term = "%$search%";
    $params = array_fill(0, 5, $search_term);
}

if (!empty($status_filter)) {
    $sql .= " AND t.tenant_status = ?";
    $params[] = $status_filter;
}

$sql .= " ORDER BY t.created_at DESC";

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $tenants = $stmt->fetchAll();
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
                    <i class="bi bi-people"></i>
                    จัดการผู้เช่า
                </h2>
                <a href="add_tenant.php" class="btn btn-primary">
                    <i class="bi bi-person-plus"></i>
                    เพิ่มผู้เช่าใหม่
                </a>
            </div>

            <!-- แสดงข้อความแจ้งเตือน -->
            <?php if (isset($success_message)): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="bi bi-check-circle"></i>
                    <?php echo $success_message; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php if (isset($error_message)): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="bi bi-exclamation-triangle"></i>
                    <?php echo $error_message; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <!-- ฟอร์มค้นหาและกรอง -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0">
                        <i class="bi bi-funnel"></i>
                        ค้นหาและกรองข้อมูล
                    </h5>
                </div>
                <div class="card-body">
                    <form method="GET" class="row g-3">
                        <div class="col-md-8">
                            <label for="search" class="form-label">ค้นหา</label>
                            <input type="text" class="form-control" id="search" name="search" 
                                   value="<?php echo htmlspecialchars($search); ?>" 
                                   placeholder="ชื่อ นามสกุล โทรศัพท์ อีเมล หรือ เลขบัตรประชาชน">
                        </div>
                        <div class="col-md-2">
                            <label for="status" class="form-label">สถานะ</label>
                            <select class="form-select" id="status" name="status">
                                <option value="">ทั้งหมด</option>
                                <option value="active" <?php echo $status_filter == 'active' ? 'selected' : ''; ?>>ใช้งาน</option>
                                <option value="inactive" <?php echo $status_filter == 'inactive' ? 'selected' : ''; ?>>ไม่ใช้งาน</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">&nbsp;</label>
                            <div class="d-grid">
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-search"></i>
                                    ค้นหา
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <!-- สถิติผู้เช่า -->
            <div class="row mb-4">
                <div class="col-md-3">
                    <div class="card text-white bg-primary">
                        <div class="card-body text-center">
                            <i class="bi bi-people fs-2"></i>
                            <h4 class="mt-2"><?php echo count($tenants); ?></h4>
                            <p class="mb-0">ผู้เช่าทั้งหมด</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card text-white bg-success">
                        <div class="card-body text-center">
                            <i class="bi bi-person-check fs-2"></i>
                            <h4 class="mt-2"><?php echo count(array_filter($tenants, function($t) { return $t['tenant_status'] == 'active'; })); ?></h4>
                            <p class="mb-0">ใช้งานอยู่</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card text-white bg-info">
                        <div class="card-body text-center">
                            <i class="bi bi-house-fill fs-2"></i>
                            <h4 class="mt-2"><?php echo count(array_filter($tenants, function($t) { return $t['has_active_contract'] > 0; })); ?></h4>
                            <p class="mb-0">กำลังเช่าอยู่</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card text-white bg-secondary">
                        <div class="card-body text-center">
                            <i class="bi bi-person-x fs-2"></i>
                            <h4 class="mt-2"><?php echo count(array_filter($tenants, function($t) { return $t['tenant_status'] == 'inactive'; })); ?></h4>
                            <p class="mb-0">ไม่ใช้งาน</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ตารางแสดงข้อมูลผู้เช่า -->
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">
                        <i class="bi bi-list"></i>
                        รายการผู้เช่า
                    </h5>
                    <span class="badge bg-primary"><?php echo count($tenants); ?> คน</span>
                </div>
                <div class="card-body">
                    <?php if (empty($tenants)): ?>
                        <div class="text-center py-5">
                            <i class="bi bi-person-plus display-1 text-muted"></i>
                            <h4 class="text-muted mt-3">ไม่พบข้อมูลผู้เช่า</h4>
                            <p class="text-muted">ยังไม่มีข้อมูลผู้เช่า หรือไม่มีข้อมูลที่ตรงกับเงื่อนไขการค้นหา</p>
                            <a href="add_tenant.php" class="btn btn-primary">
                                <i class="bi bi-person-plus"></i>
                                เพิ่มผู้เช่าคนแรก
                            </a>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>ชื่อ-นามสกุล</th>
                                        <th>โทรศัพท์</th>
                                        <th>อีเมล</th>
                                        <th>เลขบัตรประชาชน</th>
                                        <th>ห้องปัจจุบัน</th>
                                        <th>วันที่เข้าพัก</th>
                                        <th>สถานะ</th>
                                        <th class="text-center">จัดการ</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($tenants as $tenant): ?>
                                        <tr>
                                            <td>
                                                <div class="d-flex align-items-center">
                                                    <div class="avatar bg-primary text-white rounded-circle me-3" style="width: 40px; height: 40px; display: flex; align-items: center; justify-content: center;">
                                                        <?php echo mb_substr($tenant['first_name'], 0, 1, 'UTF-8'); ?>
                                                    </div>
                                                    <div>
                                                        <strong><?php echo $tenant['first_name'] . ' ' . $tenant['last_name']; ?></strong>
                                                        <?php if ($tenant['has_active_contract'] > 0): ?>
                                                            <br><small class="text-success"><i class="bi bi-house-fill"></i> กำลังเช่าอยู่</small>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </td>
                                            <td>
                                                <a href="tel:<?php echo $tenant['phone']; ?>" class="text-decoration-none">
                                                    <i class="bi bi-telephone"></i>
                                                    <?php echo $tenant['phone']; ?>
                                                </a>
                                            </td>
                                            <td>
                                                <?php if ($tenant['email']): ?>
                                                    <a href="mailto:<?php echo $tenant['email']; ?>" class="text-decoration-none">
                                                        <i class="bi bi-envelope"></i>
                                                        <?php echo $tenant['email']; ?>
                                                    </a>
                                                <?php else: ?>
                                                    <span class="text-muted">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <small class="font-monospace"><?php echo $tenant['id_card']; ?></small>
                                            </td>
                                            <td>
                                                <?php if ($tenant['current_room']): ?>
                                                    <span class="badge bg-info">ห้อง <?php echo $tenant['current_room']; ?></span>
                                                <?php else: ?>
                                                    <span class="text-muted">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($tenant['contract_start_date']): ?>
                                                    <?php echo formatDate($tenant['contract_start_date']); ?>
                                                <?php else: ?>
                                                    <span class="text-muted">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php
                                                $status_class = $tenant['tenant_status'] == 'active' ? 'bg-success' : 'bg-secondary';
                                                $status_text = $tenant['tenant_status'] == 'active' ? 'ใช้งาน' : 'ไม่ใช้งาน';
                                                $status_icon = $tenant['tenant_status'] == 'active' ? 'bi-check-circle' : 'bi-x-circle';
                                                ?>
                                                <span class="badge <?php echo $status_class; ?>">
                                                    <i class="<?php echo $status_icon; ?>"></i>
                                                    <?php echo $status_text; ?>
                                                </span>
                                            </td>
                                            <td class="text-center">
                                                <div class="btn-group" role="group">
                                                    <a href="view_tenant.php?id=<?php echo $tenant['tenant_id']; ?>" 
                                                       class="btn btn-sm btn-outline-info" 
                                                       data-bs-toggle="tooltip" 
                                                       title="ดูรายละเอียด">
                                                        <i class="bi bi-eye"></i>
                                                    </a>
                                                    <a href="edit_tenant.php?id=<?php echo $tenant['tenant_id']; ?>" 
                                                       class="btn btn-sm btn-outline-primary" 
                                                       data-bs-toggle="tooltip" 
                                                       title="แก้ไข">
                                                        <i class="bi bi-pencil"></i>
                                                    </a>
                                                    <?php if ($tenant['has_active_contract'] == 0): ?>
                                                        <a href="add_contract.php?tenant_id=<?php echo $tenant['tenant_id']; ?>" 
                                                           class="btn btn-sm btn-outline-success" 
                                                           data-bs-toggle="tooltip" 
                                                           title="สร้างสัญญา">
                                                            <i class="bi bi-file-earmark-plus"></i>
                                                        </a>
                                                    <?php else: ?>
                                                        <a href="contracts.php?tenant_id=<?php echo $tenant['tenant_id']; ?>" 
                                                           class="btn btn-sm btn-outline-warning" 
                                                           data-bs-toggle="tooltip" 
                                                           title="ดูสัญญา">
                                                            <i class="bi bi-file-earmark-text"></i>
                                                        </a>
                                                    <?php endif; ?>
                                                    <?php if ($tenant['has_active_contract'] == 0): ?>
                                                        <a href="tenants.php?delete=<?php echo $tenant['tenant_id']; ?>" 
                                                           class="btn btn-sm btn-outline-danger" 
                                                           data-bs-toggle="tooltip" 
                                                           title="ลบ"
                                                           onclick="return confirmDelete('คุณต้องการลบข้อมูลผู้เช่า <?php echo $tenant['first_name'] . ' ' . $tenant['last_name']; ?> หรือไม่?')">
                                                            <i class="bi bi-trash"></i>
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
    </div>
</div>

<style>
.table th {
    white-space: nowrap;
}

.btn-group .btn {
    margin: 0 1px;
}

.badge {
    font-size: 0.75rem;
}

.avatar {
    font-weight: 600;
    font-size: 1.1rem;
}

.font-monospace {
    font-family: 'Courier New', monospace;
}
</style>

<?php include 'includes/footer.php'; ?>