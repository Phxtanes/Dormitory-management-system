<?php
$page_title = "จัดการเอกสารทั้งหมด";
require_once 'includes/header.php';

$success_message = '';
$error_message = '';

// ตรวจสอบการลบเอกสาร
if (isset($_GET['delete_doc']) && is_numeric($_GET['delete_doc'])) {
    $doc_id = $_GET['delete_doc'];
    
    try {
        // ดึงข้อมูลไฟล์ก่อนลบ
        $stmt = $pdo->prepare("SELECT file_path FROM contract_documents WHERE document_id = ?");
        $stmt->execute([$doc_id]);
        $doc = $stmt->fetch();
        
        if ($doc) {
            // ลบไฟล์จากระบบ
            if (file_exists($doc['file_path'])) {
                unlink($doc['file_path']);
            }
            
            // ลบข้อมูลจากฐานข้อมูล
            $stmt = $pdo->prepare("DELETE FROM contract_documents WHERE document_id = ?");
            $stmt->execute([$doc_id]);
            
            $success_message = "ลบเอกสารเรียบร้อยแล้ว";
        } else {
            $error_message = "ไม่พบเอกสารที่ต้องการลบ";
        }
    } catch(PDOException $e) {
        $error_message = "เกิดข้อผิดพลาดในการลบเอกสาร: " . $e->getMessage();
    }
}

// ตรวจสอบการอัพโหลดเอกสารจากหน้านี้
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['bulk_upload'])) {
    $contract_id = $_POST['contract_id'];
    $document_type = $_POST['document_type'];
    $description = trim($_POST['description']);
    
    $uploaded_count = 0;
    $failed_count = 0;
    $error_details = [];
    
    if (isset($_FILES['document_files'])) {
        foreach ($_FILES['document_files']['tmp_name'] as $key => $tmp_name) {
            if ($_FILES['document_files']['error'][$key] == UPLOAD_ERR_OK) {
                $original_filename = $_FILES['document_files']['name'][$key];
                $file_size = $_FILES['document_files']['size'][$key];
                $mime_type = $_FILES['document_files']['type'][$key];
                
                // ตรวจสอบประเภทไฟล์
                $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'application/pdf', 'image/webp'];
                $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'webp'];
                
                $file_extension = strtolower(pathinfo($original_filename, PATHINFO_EXTENSION));
                
                if (in_array($mime_type, $allowed_types) && in_array($file_extension, $allowed_extensions) && $file_size <= 10 * 1024 * 1024) {
                    // สร้างชื่อไฟล์ใหม่
                    $stored_filename = $contract_id . '_' . time() . '_' . uniqid() . '.' . $file_extension;
                    $upload_dir = 'uploads/contracts/';
                    $file_path = $upload_dir . $stored_filename;
                    
                    if (move_uploaded_file($tmp_name, $file_path)) {
                        try {
                            $stmt = $pdo->prepare("
                                INSERT INTO contract_documents 
                                (contract_id, document_type, original_filename, stored_filename, file_path, file_size, mime_type, uploaded_by, description) 
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                            ");
                            $stmt->execute([
                                $contract_id, $document_type, $original_filename, $stored_filename,
                                $file_path, $file_size, $mime_type, $_SESSION['user_id'], $description
                            ]);
                            $uploaded_count++;
                        } catch(PDOException $e) {
                            $failed_count++;
                            $error_details[] = "ไฟล์ $original_filename: เกิดข้อผิดพลาดในการบันทึกข้อมูล";
                            if (file_exists($file_path)) {
                                unlink($file_path);
                            }
                        }
                    } else {
                        $failed_count++;
                        $error_details[] = "ไฟล์ $original_filename: ไม่สามารถอัพโหลดได้";
                    }
                } else {
                    $failed_count++;
                    $error_details[] = "ไฟล์ $original_filename: ประเภทหรือขนาดไฟล์ไม่ถูกต้อง";
                }
            }
        }
    }
    
    if ($uploaded_count > 0) {
        $success_message = "อัพโหลดเอกสารสำเร็จ $uploaded_count ไฟล์";
        if ($failed_count > 0) {
            $success_message .= " (ไม่สำเร็จ $failed_count ไฟล์)";
        }
    }
    
    if ($failed_count > 0 && $uploaded_count == 0) {
        $error_message = "ไม่สามารถอัพโหลดเอกสารได้: " . implode(', ', $error_details);
    }
}

// กำหนดตัวแปรสำหรับการค้นหาและกรอง
$search = isset($_GET['search']) ? $_GET['search'] : '';
$document_type_filter = isset($_GET['document_type']) ? $_GET['document_type'] : '';
$contract_filter = isset($_GET['contract_id']) ? $_GET['contract_id'] : '';
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : '';
$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
$per_page = 20;
$offset = ($page - 1) * $per_page;

// สร้าง SQL สำหรับค้นหาและกรอง
$sql = "SELECT cd.*, 
               c.contract_id, 
               r.room_number,
               CONCAT(t.first_name, ' ', t.last_name) as tenant_name,
               u.full_name as uploaded_by_name
        FROM contract_documents cd
        JOIN contracts c ON cd.contract_id = c.contract_id
        JOIN rooms r ON c.room_id = r.room_id
        JOIN tenants t ON c.tenant_id = t.tenant_id
        LEFT JOIN users u ON cd.uploaded_by = u.user_id
        WHERE 1=1";

$count_sql = "SELECT COUNT(*) as total
              FROM contract_documents cd
              JOIN contracts c ON cd.contract_id = c.contract_id
              JOIN rooms r ON c.room_id = r.room_id
              JOIN tenants t ON c.tenant_id = t.tenant_id
              WHERE 1=1";

$params = [];

if (!empty($search)) {
    $search_condition = " AND (cd.original_filename LIKE ? OR cd.description LIKE ? OR r.room_number LIKE ? OR t.first_name LIKE ? OR t.last_name LIKE ?)";
    $sql .= $search_condition;
    $count_sql .= $search_condition;
    $search_term = "%$search%";
    $params = array_merge($params, [$search_term, $search_term, $search_term, $search_term, $search_term]);
}

if (!empty($document_type_filter)) {
    $type_condition = " AND cd.document_type = ?";
    $sql .= $type_condition;
    $count_sql .= $type_condition;
    $params[] = $document_type_filter;
}

if (!empty($contract_filter)) {
    $contract_condition = " AND cd.contract_id = ?";
    $sql .= $contract_condition;
    $count_sql .= $contract_condition;
    $params[] = $contract_filter;
}

if (!empty($date_from)) {
    $date_condition = " AND DATE(cd.upload_date) >= ?";
    $sql .= $date_condition;
    $count_sql .= $date_condition;
    $params[] = $date_from;
}

if (!empty($date_to)) {
    $date_condition = " AND DATE(cd.upload_date) <= ?";
    $sql .= $date_condition;
    $count_sql .= $date_condition;
    $params[] = $date_to;
}

$sql .= " ORDER BY cd.upload_date DESC LIMIT $per_page OFFSET $offset";

try {
    // นับจำนวนรายการทั้งหมด
    $count_stmt = $pdo->prepare($count_sql);
    $count_stmt->execute($params);
    $total_records = $count_stmt->fetch()['total'];
    $total_pages = ceil($total_records / $per_page);
    
    // ดึงข้อมูลเอกสาร
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $documents = $stmt->fetchAll();
    
    // ดึงสถิติ
    $stats_sql = "SELECT 
        COUNT(*) as total_documents,
        COUNT(DISTINCT cd.contract_id) as total_contracts,
        SUM(cd.file_size) as total_size,
        cd.document_type,
        COUNT(*) as type_count
    FROM contract_documents cd
    GROUP BY cd.document_type";
    
    $stats_stmt = $pdo->query($stats_sql);
    $stats_by_type = $stats_stmt->fetchAll();
    
    $overall_stats_sql = "SELECT 
        COUNT(*) as total_documents,
        COUNT(DISTINCT contract_id) as total_contracts,
        SUM(file_size) as total_size
    FROM contract_documents";
    
    $overall_stmt = $pdo->query($overall_stats_sql);
    $overall_stats = $overall_stmt->fetch();
    
    // ดึงรายการสัญญาสำหรับ dropdown
    $contracts_sql = "SELECT c.contract_id, r.room_number, CONCAT(t.first_name, ' ', t.last_name) as tenant_name
                      FROM contracts c
                      JOIN rooms r ON c.room_id = r.room_id
                      JOIN tenants t ON c.tenant_id = t.tenant_id
                      WHERE c.contract_status = 'active'
                      ORDER BY r.room_number";
    $contracts_stmt = $pdo->query($contracts_sql);
    $contracts_list = $contracts_stmt->fetchAll();
    
} catch(PDOException $e) {
    $error_message = "เกิดข้อผิดพลาดในการดึงข้อมูล: " . $e->getMessage();
    $documents = [];
    $total_records = 0;
    $total_pages = 0;
    $stats_by_type = [];
    $overall_stats = ['total_documents' => 0, 'total_contracts' => 0, 'total_size' => 0];
    $contracts_list = [];
}

include 'includes/navbar.php';
?>

<div class="container-fluid mt-4">
    <div class="row">
        <div class="col-12">
            <!-- หัวข้อหน้า -->
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2 class="mb-0">
                    <i class="bi bi-files"></i>
                    จัดการเอกสารทั้งหมด
                </h2>
                <div class="btn-group">
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#bulkUploadModal">
                        <i class="bi bi-cloud-upload"></i>
                        อัพโหลดหลายไฟล์
                    </button>
                    <button class="btn btn-info" onclick="showStats()">
                        <i class="bi bi-graph-up"></i>
                        สถิติ
                    </button>
                    <button class="btn btn-success" onclick="exportDocumentList()">
                        <i class="bi bi-file-excel"></i>
                        ส่งออกรายการ
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

            <!-- สถิติภาพรวม -->
            <div class="row mb-4">
                <div class="col-md-3">
                    <div class="card bg-primary text-white">
                        <div class="card-body text-center">
                            <h4><?php echo number_format($overall_stats['total_documents']); ?></h4>
                            <small>เอกสารทั้งหมด</small>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card bg-success text-white">
                        <div class="card-body text-center">
                            <h4><?php echo number_format($overall_stats['total_contracts']); ?></h4>
                            <small>สัญญาที่มีเอกสาร</small>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card bg-info text-white">
                        <div class="card-body text-center">
                            <h4><?php echo formatFileSize($overall_stats['total_size'] ?? 0); ?></h4>
                            <small>ขนาดรวม</small>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card bg-warning text-white">
                        <div class="card-body text-center">
                            <h4><?php echo count($stats_by_type); ?></h4>
                            <small>ประเภทเอกสาร</small>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ฟิลเตอร์และค้นหา -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="card-title mb-0">
                        <i class="bi bi-funnel"></i>
                        ค้นหาและกรองข้อมูล
                    </h5>
                </div>
                <div class="card-body">
                    <form method="GET" class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label">ค้นหา</label>
                            <input type="text" class="form-control" name="search" 
                                   value="<?php echo htmlspecialchars($search); ?>" 
                                   placeholder="ชื่อไฟล์, ห้อง, ผู้เช่า">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">ประเภทเอกสาร</label>
                            <select class="form-select" name="document_type">
                                <option value="">ทั้งหมด</option>
                                <option value="contract" <?php echo $document_type_filter == 'contract' ? 'selected' : ''; ?>>สัญญาเช่า</option>
                                <option value="id_card" <?php echo $document_type_filter == 'id_card' ? 'selected' : ''; ?>>บัตรประชาชน</option>
                                <option value="income_proof" <?php echo $document_type_filter == 'income_proof' ? 'selected' : ''; ?>>หลักฐานรายได้</option>
                                <option value="guarantor_doc" <?php echo $document_type_filter == 'guarantor_doc' ? 'selected' : ''; ?>>เอกสารผู้ค้ำ</option>
                                <option value="other" <?php echo $document_type_filter == 'other' ? 'selected' : ''; ?>>อื่นๆ</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">สัญญา</label>
                            <select class="form-select" name="contract_id">
                                <option value="">ทั้งหมด</option>
                                <?php foreach ($contracts_list as $contract): ?>
                                    <option value="<?php echo $contract['contract_id']; ?>" 
                                            <?php echo $contract_filter == $contract['contract_id'] ? 'selected' : ''; ?>>
                                        ห้อง <?php echo $contract['room_number']; ?> - <?php echo htmlspecialchars($contract['tenant_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">วันที่อัพโหลด (จาก)</label>
                            <input type="date" class="form-control" name="date_from" value="<?php echo $date_from; ?>">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">วันที่อัพโหลด (ถึง)</label>
                            <input type="date" class="form-control" name="date_to" value="<?php echo $date_to; ?>">
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
                </div>
            </div>

            <!-- รายการเอกสาร -->
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0">
                        รายการเอกสาร (<?php echo number_format($total_records); ?> รายการ)
                    </h5>
                    <div class="btn-group btn-group-sm">
                        <button class="btn btn-outline-secondary" onclick="changeView('grid')" id="gridViewBtn">
                            <i class="bi bi-grid-3x3-gap"></i>
                        </button>
                        <button class="btn btn-outline-secondary active" onclick="changeView('list')" id="listViewBtn">
                            <i class="bi bi-list"></i>
                        </button>
                    </div>
                </div>
                <div class="card-body">
                    <?php if (empty($documents)): ?>
                        <div class="text-center py-5">
                            <i class="bi bi-file-earmark-text fs-1 text-muted d-block mb-3"></i>
                            <h5 class="text-muted">ไม่พบเอกสาร</h5>
                            <p class="text-muted">ลองปรับเปลี่ยนเงื่อนไขการค้นหา</p>
                        </div>
                    <?php else: ?>
                        <!-- มุมมองแบบตาราง -->
                        <div id="listView">
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead class="table-light">
                                        <tr>
                                            <th width="5%">#</th>
                                            <th width="15%">ไฟล์</th>
                                            <th width="15%">ประเภท</th>
                                            <th width="15%">สัญญา</th>
                                            <th width="15%">ผู้เช่า</th>
                                            <th width="10%">ขนาด</th>
                                            <th width="15%">อัพโหลดโดย</th>
                                            <th width="10%">จัดการ</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($documents as $index => $doc): ?>
                                            <tr>
                                                <td><?php echo $offset + $index + 1; ?></td>
                                                <td>
                                                    <div class="d-flex align-items-center">
                                                        <div class="me-2">
                                                            <?php if (strpos($doc['mime_type'], 'image/') === 0): ?>
                                                                <i class="bi bi-image text-success fs-4"></i>
                                                            <?php else: ?>
                                                                <i class="bi bi-file-earmark-pdf text-danger fs-4"></i>
                                                            <?php endif; ?>
                                                        </div>
                                                        <div>
                                                            <div class="fw-bold text-truncate" style="max-width: 150px;" 
                                                                 title="<?php echo htmlspecialchars($doc['original_filename']); ?>">
                                                                <?php echo htmlspecialchars($doc['original_filename']); ?>
                                                            </div>
                                                            <small class="text-muted">
                                                                <?php echo date('d/m/Y H:i', strtotime($doc['upload_date'])); ?>
                                                            </small>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td>
                                                    <?php
                                                    $doc_types = [
                                                        'contract' => '<span class="badge bg-primary">สัญญาเช่า</span>',
                                                        'id_card' => '<span class="badge bg-info">บัตรประชาชน</span>',
                                                        'income_proof' => '<span class="badge bg-success">หลักฐานรายได้</span>',
                                                        'guarantor_doc' => '<span class="badge bg-warning">เอกสารผู้ค้ำ</span>',
                                                        'other' => '<span class="badge bg-secondary">อื่นๆ</span>'
                                                    ];
                                                    echo $doc_types[$doc['document_type']] ?? '<span class="badge bg-light text-dark">' . $doc['document_type'] . '</span>';
                                                    ?>
                                                </td>
                                                <td>
                                                    <div>
                                                        <strong>ห้อง <?php echo htmlspecialchars($doc['room_number']); ?></strong>
                                                        <br>
                                                        <small class="text-muted">#<?php echo $doc['contract_id']; ?></small>
                                                    </div>
                                                </td>
                                                <td><?php echo htmlspecialchars($doc['tenant_name']); ?></td>
                                                <td><?php echo formatFileSize($doc['file_size']); ?></td>
                                                <td>
                                                    <div>
                                                        <?php echo htmlspecialchars($doc['uploaded_by_name'] ?? 'ไม่ทราบ'); ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div class="btn-group-vertical btn-group-sm">
                                                        <a href="<?php echo htmlspecialchars($doc['file_path']); ?>" 
                                                           target="_blank" class="btn btn-outline-primary btn-sm" title="ดู">
                                                            <i class="bi bi-eye"></i>
                                                        </a>
                                                        <a href="download.php?doc_id=<?php echo $doc['document_id']; ?>" 
                                                           class="btn btn-outline-success btn-sm" title="ดาวน์โหลด">
                                                            <i class="bi bi-download"></i>
                                                        </a>
                                                        <a href="deposit_documents.php?contract_id=<?php echo $doc['contract_id']; ?>" 
                                                           class="btn btn-outline-info btn-sm" title="จัดการ">
                                                            <i class="bi bi-gear"></i>
                                                        </a>
                                                        <a href="?delete_doc=<?php echo $doc['document_id']; ?>&<?php echo http_build_query($_GET); ?>" 
                                                           class="btn btn-outline-danger btn-sm" title="ลบ"
                                                           onclick="return confirm('ต้องการลบเอกสารนี้หรือไม่?')">
                                                            <i class="bi bi-trash"></i>
                                                        </a>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <!-- มุมมองแบบ Grid -->
                        <div id="gridView" style="display: none;">
                            <div class="row">
                                <?php foreach ($documents as $doc): ?>
                                    <div class="col-md-6 col-lg-4 col-xl-3 mb-4">
                                        <div class="card h-100">
                                            <div class="card-body">
                                                <!-- แสดงตัวอย่างไฟล์ -->
                                                <div class="text-center mb-3">
                                                    <?php if (strpos($doc['mime_type'], 'image/') === 0): ?>
                                                        <img src="<?php echo htmlspecialchars($doc['file_path']); ?>" 
                                                             class="img-thumbnail" style="max-height: 120px; cursor: pointer;"
                                                             onclick="viewImage('<?php echo htmlspecialchars($doc['file_path']); ?>', '<?php echo htmlspecialchars($doc['original_filename']); ?>')">
                                                    <?php else: ?>
                                                        <div class="bg-light p-3 rounded">
                                                            <i class="bi bi-file-earmark-pdf fs-1 text-danger"></i>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                                
                                                <h6 class="card-title text-truncate" title="<?php echo htmlspecialchars($doc['original_filename']); ?>">
                                                    <?php echo htmlspecialchars($doc['original_filename']); ?>
                                                </h6>
                                                
                                                <div class="small text-muted mb-2">
                                                    <div><strong>ห้อง:</strong> <?php echo htmlspecialchars($doc['room_number']); ?></div>
                                                    <div><strong>ผู้เช่า:</strong> <?php echo htmlspecialchars($doc['tenant_name']); ?></div>
                                                    <div><strong>ขนาด:</strong> <?php echo formatFileSize($doc['file_size']); ?></div>
                                                    <div><strong>อัพโหลด:</strong> <?php echo date('d/m/Y', strtotime($doc['upload_date'])); ?></div>
                                                </div>
                                                
                                                <?php
                                                $doc_types = [
                                                    'contract' => '<span class="badge bg-primary">สัญญาเช่า</span>',
                                                    'id_card' => '<span class="badge bg-info">บัตรประชาชน</span>',
                                                    'income_proof' => '<span class="badge bg-success">หลักฐานรายได้</span>',
                                                    'guarantor_doc' => '<span class="badge bg-warning">เอกสารผู้ค้ำ</span>',
                                                    'other' => '<span class="badge bg-secondary">อื่นๆ</span>'
                                                ];
                                                echo $doc_types[$doc['document_type']] ?? '<span class="badge bg-light text-dark">' . $doc['document_type'] . '</span>';
                                                ?>
                                            </div>
                                            
                                            <div class="card-footer bg-transparent">
                                                <div class="btn-group w-100">
                                                    <a href="<?php echo htmlspecialchars($doc['file_path']); ?>" 
                                                       target="_blank" class="btn btn-outline-primary btn-sm">
                                                        <i class="bi bi-eye"></i>
                                                    </a>
                                                    <a href="download.php?doc_id=<?php echo $doc['document_id']; ?>" 
                                                       class="btn btn-outline-success btn-sm">
                                                        <i class="bi bi-download"></i>
                                                    </a>
                                                    <a href="deposit_documents.php?contract_id=<?php echo $doc['contract_id']; ?>" 
                                                       class="btn btn-outline-info btn-sm">
                                                        <i class="bi bi-gear"></i>
                                                    </a>
                                                    <a href="?delete_doc=<?php echo $doc['document_id']; ?>&<?php echo http_build_query($_GET); ?>" 
                                                       class="btn btn-outline-danger btn-sm"
                                                       onclick="return confirm('ต้องการลบเอกสารนี้หรือไม่?')">
                                                        <i class="bi bi-trash"></i>
                                                    </a>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <!-- Pagination -->
                        <?php if ($total_pages > 1): ?>
                            <div class="d-flex justify-content-between align-items-center mt-4">
                                <div>
                                    แสดง <?php echo $offset + 1; ?> ถึง <?php echo min($offset + $per_page, $total_records); ?> 
                                    จาก <?php echo number_format($total_records); ?> รายการ
                                </div>
                                <nav>
                                    <ul class="pagination mb-0">
                                        <?php if ($page > 1): ?>
                                            <li class="page-item">
                                                <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>">
                                                    <i class="bi bi-chevron-left"></i>
                                                </a>
                                            </li>
                                        <?php endif; ?>

                                        <?php
                                        $start_page = max(1, $page - 2);
                                        $end_page = min($total_pages, $page + 2);
                                        
                                        for ($i = $start_page; $i <= $end_page; $i++):
                                        ?>
                                            <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                                                <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>">
                                                    <?php echo $i; ?>
                                                </a>
                                            </li>
                                        <?php endfor; ?>

                                        <?php if ($page < $total_pages): ?>
                                            <li class="page-item">
                                                <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>">
                                                    <i class="bi bi-chevron-right"></i>
                                                </a>
                                            </li>
                                        <?php endif; ?>
                                    </ul>
                                </nav>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal อัพโหลดหลายไฟล์ -->
<div class="modal fade" id="bulkUploadModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">อัพโหลดหลายไฟล์พร้อมกัน</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" enctype="multipart/form-data">
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6">
                            <label class="form-label">เลือกสัญญา <span class="text-danger">*</span></label>
                            <select class="form-select" name="contract_id" required>
                                <option value="">-- เลือกสัญญา --</option>
                                <?php foreach ($contracts_list as $contract): ?>
                                    <option value="<?php echo $contract['contract_id']; ?>">
                                        ห้อง <?php echo $contract['room_number']; ?> - <?php echo htmlspecialchars($contract['tenant_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">ประเภทเอกสาร <span class="text-danger">*</span></label>
                            <select class="form-select" name="document_type" required>
                                <option value="">-- เลือกประเภทเอกสาร --</option>
                                <option value="contract">สัญญาเช่า</option>
                                <option value="id_card">สำเนาบัตรประชาชน</option>
                                <option value="income_proof">หลักฐานรายได้</option>
                                <option value="guarantor_doc">เอกสารผู้ค้ำประกัน</option>
                                <option value="other">อื่นๆ</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="mt-3">
                        <label class="form-label">เลือกไฟล์ <span class="text-danger">*</span></label>
                        <input type="file" class="form-control" name="document_files[]" multiple
                               accept=".jpg,.jpeg,.png,.gif,.pdf,.webp" required>
                        <div class="form-text">
                            สามารถเลือกหลายไฟล์พร้อมกัน ประเภทที่รองรับ: JPG, PNG, GIF, PDF, WEBP (ขนาดไม่เกิน 10 MB ต่อไฟล์)
                        </div>
                    </div>
                    
                    <div class="mt-3">
                        <label class="form-label">หมายเหตุ</label>
                        <textarea class="form-control" name="description" rows="3" 
                                  placeholder="หมายเหตุสำหรับไฟล์ทั้งหมด (ไม่จำเป็น)"></textarea>
                    </div>

                    <!-- แสดงรายการไฟล์ที่เลือก -->
                    <div class="mt-3" id="selectedFiles" style="display: none;">
                        <h6>ไฟล์ที่เลือก:</h6>
                        <div id="filesList" class="border rounded p-2 bg-light"></div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ยกเลิก</button>
                    <button type="submit" name="bulk_upload" class="btn btn-primary">
                        <i class="bi bi-cloud-upload"></i> อัพโหลดทั้งหมด
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal แสดงสถิติ -->
<div class="modal fade" id="statsModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">สถิติเอกสาร</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row">
                    <div class="col-md-6">
                        <h6>สถิติตามประเภทเอกสาร</h6>
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>ประเภท</th>
                                        <th class="text-end">จำนวน</th>
                                        <th class="text-end">เปอร์เซ็นต์</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $type_labels = [
                                        'contract' => 'สัญญาเช่า',
                                        'id_card' => 'บัตรประชาชน',
                                        'income_proof' => 'หลักฐานรายได้',
                                        'guarantor_doc' => 'เอกสารผู้ค้ำ',
                                        'other' => 'อื่นๆ'
                                    ];
                                    foreach ($stats_by_type as $stat):
                                        $percentage = $overall_stats['total_documents'] > 0 ? 
                                                     ($stat['type_count'] / $overall_stats['total_documents']) * 100 : 0;
                                    ?>
                                        <tr>
                                            <td><?php echo $type_labels[$stat['document_type']] ?? $stat['document_type']; ?></td>
                                            <td class="text-end"><?php echo number_format($stat['type_count']); ?></td>
                                            <td class="text-end"><?php echo number_format($percentage, 1); ?>%</td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <h6>สถิติเพิ่มเติม</h6>
                        <ul class="list-group list-group-flush">
                            <li class="list-group-item d-flex justify-content-between">
                                <span>เอกสารทั้งหมด</span>
                                <strong><?php echo number_format($overall_stats['total_documents']); ?> ไฟล์</strong>
                            </li>
                            <li class="list-group-item d-flex justify-content-between">
                                <span>สัญญาที่มีเอกสาร</span>
                                <strong><?php echo number_format($overall_stats['total_contracts']); ?> สัญญา</strong>
                            </li>
                            <li class="list-group-item d-flex justify-content-between">
                                <span>ขนาดรวมทั้งหมด</span>
                                <strong><?php echo formatFileSize($overall_stats['total_size'] ?? 0); ?></strong>
                            </li>
                            <li class="list-group-item d-flex justify-content-between">
                                <span>ขนาดเฉลี่ยต่อไฟล์</span>
                                <strong>
                                    <?php 
                                    echo $overall_stats['total_documents'] > 0 ? 
                                         formatFileSize(($overall_stats['total_size'] ?? 0) / $overall_stats['total_documents']) : 
                                         '0 B';
                                    ?>
                                </strong>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ปิด</button>
            </div>
        </div>
    </div>
</div>

<!-- Modal ดูรูปภาพ -->
<div class="modal fade" id="imageModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="imageModalTitle">ดูรูปภาพ</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-center">
                <img id="modalImage" src="" class="img-fluid" style="max-height: 80vh;">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ปิด</button>
            </div>
        </div>
    </div>
</div>

<script>
// แสดง/ซ่อนมุมมอง
function changeView(viewType) {
    const listView = document.getElementById('listView');
    const gridView = document.getElementById('gridView');
    const listBtn = document.getElementById('listViewBtn');
    const gridBtn = document.getElementById('gridViewBtn');
    
    if (viewType === 'grid') {
        listView.style.display = 'none';
        gridView.style.display = 'block';
        listBtn.classList.remove('active');
        gridBtn.classList.add('active');
    } else {
        listView.style.display = 'block';
        gridView.style.display = 'none';
        listBtn.classList.add('active');
        gridBtn.classList.remove('active');
    }
    
    // บันทึกการตั้งค่าใน localStorage
    localStorage.setItem('documentViewType', viewType);
}

// โหลดการตั้งค่ามุมมองจาก localStorage
document.addEventListener('DOMContentLoaded', function() {
    const savedViewType = localStorage.getItem('documentViewType') || 'list';
    changeView(savedViewType);
});

// แสดงสถิติ
function showStats() {
    new bootstrap.Modal(document.getElementById('statsModal')).show();
}

// ดูรูปภาพ
function viewImage(imagePath, filename) {
    document.getElementById('modalImage').src = imagePath;
    document.getElementById('imageModalTitle').textContent = filename;
    new bootstrap.Modal(document.getElementById('imageModal')).show();
}

// ส่งออกรายการเอกสาร
function exportDocumentList() {
    const currentUrl = new URL(window.location);
    currentUrl.searchParams.set('export', 'excel');
    window.location.href = currentUrl.toString();
}

// จัดการการเลือกไฟล์หลายไฟล์
document.querySelector('input[name="document_files[]"]').addEventListener('change', function(e) {
    const files = e.target.files;
    const filesList = document.getElementById('filesList');
    const selectedFiles = document.getElementById('selectedFiles');
    
    if (files.length > 0) {
        selectedFiles.style.display = 'block';
        filesList.innerHTML = '';
        
        let totalSize = 0;
        let validFiles = 0;
        
        for (let i = 0; i < files.length; i++) {
            const file = files[i];
            const fileSize = file.size;
            totalSize += fileSize;
            
            const isValidSize = fileSize <= 10 * 1024 * 1024; // 10MB
            const isValidType = ['image/jpeg', 'image/png', 'image/gif', 'application/pdf', 'image/webp'].includes(file.type);
            
            if (isValidSize && isValidType) {
                validFiles++;
            }
            
            const fileItem = document.createElement('div');
            fileItem.className = 'mb-1 p-1 ' + (isValidSize && isValidType ? 'text-success' : 'text-danger');
            fileItem.innerHTML = `
                <i class="bi bi-${isValidSize && isValidType ? 'check' : 'x'}-circle"></i>
                ${file.name} (${formatFileSize(fileSize)})
                ${!isValidSize ? ' - ขนาดเกิน 10MB' : ''}
                ${!isValidType ? ' - ประเภทไฟล์ไม่รองรับ' : ''}
            `;
            filesList.appendChild(fileItem);
        }
        
        const summary = document.createElement('div');
        summary.className = 'mt-2 fw-bold';
        summary.innerHTML = `รวม: ${files.length} ไฟล์ (ใช้ได้ ${validFiles} ไฟล์) | ขนาดรวม: ${formatFileSize(totalSize)}`;
        filesList.appendChild(summary);
        
    } else {
        selectedFiles.style.display = 'none';
    }
});

// ฟังก์ชันช่วยสำหรับแสดงขนาดไฟล์
function formatFileSize(bytes) {
    if (bytes === 0) return '0 B';
    const k = 1024;
    const sizes = ['B', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
}

// เคลียร์ฟิลเตอร์
function clearFilters() {
    window.location.href = 'all_documents.php';
}
</script>

<?php include 'includes/footer.php'; ?>

<?php
// ฟังก์ชันช่วยสำหรับแสดงขนาดไฟล์
function formatFileSize($bytes, $precision = 2) {
    $units = array('B', 'KB', 'MB', 'GB', 'TB');
    
    for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
        $bytes /= 1024;
    }
    
    return round($bytes, $precision) . ' ' . $units[$i];
}
?>