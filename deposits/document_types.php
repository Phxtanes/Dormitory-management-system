<?php
$page_title = "จัดการประเภทเอกสาร";
require_once 'includes/header.php';

$success_message = '';
$error_message = '';

// ตรวจสอบการเพิ่มประเภทเอกสารใหม่
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_type'])) {
    $type_code = trim($_POST['type_code']);
    $type_name = trim($_POST['type_name']);
    $type_description = trim($_POST['type_description']);
    $is_required = isset($_POST['is_required']) ? 1 : 0;
    $max_files = intval($_POST['max_files']);
    $allowed_extensions = trim($_POST['allowed_extensions']);
    $max_file_size = intval($_POST['max_file_size']);
    
    // ตรวจสอบข้อมูล
    $errors = [];
    
    if (empty($type_code)) {
        $errors[] = "กรุณากรอกรหัสประเภท";
    } elseif (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $type_code)) {
        $errors[] = "รหัสประเภทต้องเริ่มต้นด้วยตัวอักษรและประกอบด้วยตัวอักษร ตัวเลข และ _ เท่านั้น";
    }
    
    if (empty($type_name)) {
        $errors[] = "กรุณากรอกชื่อประเภท";
    }
    
    if ($max_files <= 0) {
        $errors[] = "จำนวนไฟล์สูงสุดต้องมากกว่า 0";
    }
    
    if ($max_file_size <= 0) {
        $errors[] = "ขนาดไฟล์สูงสุดต้องมากกว่า 0";
    }
    
    // ตรวจสอบรหัสประเภทซ้ำ
    if (empty($errors)) {
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM document_types WHERE type_code = ?");
            $stmt->execute([$type_code]);
            $existing = $stmt->fetch()['count'];
            
            if ($existing > 0) {
                $errors[] = "รหัสประเภท '$type_code' มีอยู่ในระบบแล้ว";
            }
        } catch(PDOException $e) {
            $errors[] = "เกิดข้อผิดพลาดในการตรวจสอบข้อมูล";
        }
    }
    
    // บันทึกข้อมูล
    if (empty($errors)) {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO document_types 
                (type_code, type_name, type_description, is_required, max_files, allowed_extensions, max_file_size, created_by) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $type_code, $type_name, $type_description, $is_required, 
                $max_files, $allowed_extensions, $max_file_size, $_SESSION['user_id']
            ]);
            
            $success_message = "เพิ่มประเภทเอกสาร '$type_name' เรียบร้อยแล้ว";
            
            // รีเซ็ตฟอร์ม
            $_POST = [];
            
        } catch(PDOException $e) {
            $error_message = "เกิดข้อผิดพลาด: " . $e->getMessage();
        }
    } else {
        $error_message = implode('<br>', $errors);
    }
}

// ตรวจสอบการแก้ไข
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['edit_type'])) {
    $type_id = intval($_POST['type_id']);
    $type_name = trim($_POST['type_name']);
    $type_description = trim($_POST['type_description']);
    $is_required = isset($_POST['is_required']) ? 1 : 0;
    $max_files = intval($_POST['max_files']);
    $allowed_extensions = trim($_POST['allowed_extensions']);
    $max_file_size = intval($_POST['max_file_size']);
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    
    try {
        $stmt = $pdo->prepare("
            UPDATE document_types SET 
            type_name = ?, type_description = ?, is_required = ?, max_files = ?, 
            allowed_extensions = ?, max_file_size = ?, is_active = ?, updated_by = ?, updated_at = NOW()
            WHERE type_id = ?
        ");
        $stmt->execute([
            $type_name, $type_description, $is_required, $max_files,
            $allowed_extensions, $max_file_size, $is_active, $_SESSION['user_id'], $type_id
        ]);
        
        $success_message = "อัปเดตประเภทเอกสารเรียบร้อยแล้ว";
        
    } catch(PDOException $e) {
        $error_message = "เกิดข้อผิดพลาดในการแก้ไข: " . $e->getMessage();
    }
}

// ตรวจสอบการลบ
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $type_id = $_GET['delete'];
    
    try {
        // ตรวจสอบว่ามีการใช้งานหรือไม่
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM contract_documents WHERE document_type = (SELECT type_code FROM document_types WHERE type_id = ?)");
        $stmt->execute([$type_id]);
        $usage_count = $stmt->fetch()['count'];
        
        if ($usage_count > 0) {
            $error_message = "ไม่สามารถลบประเภทเอกสารนี้ได้ เนื่องจากมีการใช้งานอยู่ $usage_count รายการ";
        } else {
            $stmt = $pdo->prepare("DELETE FROM document_types WHERE type_id = ?");
            $stmt->execute([$type_id]);
            $success_message = "ลบประเภทเอกสารเรียบร้อยแล้ว";
        }
    } catch(PDOException $e) {
        $error_message = "เกิดข้อผิดพลาดในการลบ: " . $e->getMessage();
    }
}

// ดึงข้อมูลประเภทเอกสารทั้งหมด
try {
    $stmt = $pdo->prepare("
        SELECT dt.*, 
               creator.full_name as created_by_name,
               updater.full_name as updated_by_name,
               COUNT(cd.document_id) as usage_count
        FROM document_types dt
        LEFT JOIN users creator ON dt.created_by = creator.user_id
        LEFT JOIN users updater ON dt.updated_by = updater.user_id
        LEFT JOIN contract_documents cd ON dt.type_code = cd.document_type
        GROUP BY dt.type_id
        ORDER BY dt.is_active DESC, dt.type_name ASC
    ");
    $stmt->execute();
    $document_types = $stmt->fetchAll();
} catch(PDOException $e) {
    $error_message = "เกิดข้อผิดพลาดในการดึงข้อมูล: " . $e->getMessage();
    $document_types = [];
}

include 'includes/navbar.php';
?>

<div class="container-fluid mt-4">
    <div class="row">
        <div class="col-12">
            <!-- หัวข้อหน้า -->
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2 class="mb-0">
                    <i class="bi bi-tags"></i>
                    จัดการประเภทเอกสาร
                </h2>
                <div class="btn-group">
                    <a href="all_documents.php" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left"></i>
                        กลับ
                    </a>
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addTypeModal">
                        <i class="bi bi-plus-circle"></i>
                        เพิ่มประเภทเอกสาร
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

            <!-- ข้อมูลประเภทเอกสารเริ่มต้น -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="card-title mb-0">
                        <i class="bi bi-info-circle"></i>
                        ประเภทเอกสารเริ่มต้นในระบบ
                    </h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <h6>ประเภทพื้นฐาน:</h6>
                            <ul class="list-group list-group-flush">
                                <li class="list-group-item d-flex justify-content-between align-items-center">
                                    <span><strong>contract</strong> - สัญญาเช่า</span>
                                    <span class="badge bg-primary">พื้นฐาน</span>
                                </li>
                                <li class="list-group-item d-flex justify-content-between align-items-center">
                                    <span><strong>id_card</strong> - บัตรประชาชน</span>
                                    <span class="badge bg-info">พื้นฐาน</span>
                                </li>
                                <li class="list-group-item d-flex justify-content-between align-items-center">
                                    <span><strong>income_proof</strong> - หลักฐานรายได้</span>
                                    <span class="badge bg-success">พื้นฐาน</span>
                                </li>
                                <li class="list-group-item d-flex justify-content-between align-items-center">
                                    <span><strong>guarantor_doc</strong> - เอกสารผู้ค้ำประกัน</span>
                                    <span class="badge bg-warning">พื้นฐาน</span>
                                </li>
                                <li class="list-group-item d-flex justify-content-between align-items-center">
                                    <span><strong>other</strong> - อื่นๆ</span>
                                    <span class="badge bg-secondary">พื้นฐาน</span>
                                </li>
                            </ul>
                        </div>
                        <div class="col-md-6">
                            <div class="alert alert-info">
                                <h6><i class="bi bi-lightbulb"></i> คำแนะนำ:</h6>
                                <ul class="mb-0 small">
                                    <li>ประเภทเอกสารพื้นฐานไม่สามารถลบหรือแก้ไขรหัสได้</li>
                                    <li>สามารถเพิ่มประเภทเอกสารใหม่ตามความต้องการ</li>
                                    <li>รหัสประเภทควรใช้ภาษาอังกฤษและไม่มีช่องว่าง</li>
                                    <li>ตั้งค่าขนาดไฟล์และนามสกุลที่อนุญาตให้เหมาะสม</li>
                                    <li>ประเภทเอกสารที่ตั้งเป็น "จำเป็น" จะถูกตรวจสอบก่อนทำสัญญา</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- รายการประเภทเอกสาร -->
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title mb-0">
                        รายการประเภทเอกสาร (<?php echo count($document_types); ?> ประเภท)
                    </h5>
                </div>
                <div class="card-body">
                    <?php if (empty($document_types)): ?>
                        <div class="text-center py-5">
                            <i class="bi bi-tags fs-1 text-muted d-block mb-3"></i>
                            <h5 class="text-muted">ยังไม่มีประเภทเอกสารที่กำหนดเอง</h5>
                            <p class="text-muted">คลิกปุ่ม "เพิ่มประเภทเอกสาร" เพื่อเริ่มต้น</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead class="table-light">
                                    <tr>
                                        <th>รหัส</th>
                                        <th>ชื่อประเภท</th>
                                        <th>คำอธิบาย</th>
                                        <th class="text-center">จำเป็น</th>
                                        <th class="text-center">ไฟล์สูงสุด</th>
                                        <th class="text-center">ขนาดสูงสุด</th>
                                        <th class="text-center">การใช้งาน</th>
                                        <th class="text-center">สถานะ</th>
                                        <th class="text-center">จัดการ</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($document_types as $type): ?>
                                        <tr class="<?php echo $type['is_active'] ? '' : 'table-secondary'; ?>">
                                            <td>
                                                <code class="text-primary"><?php echo htmlspecialchars($type['type_code']); ?></code>
                                            </td>
                                            <td>
                                                <strong><?php echo htmlspecialchars($type['type_name']); ?></strong>
                                            </td>
                                            <td>
                                                <div style="max-width: 200px;">
                                                    <?php echo htmlspecialchars($type['type_description'] ?: '-'); ?>
                                                </div>
                                            </td>
                                            <td class="text-center">
                                                <?php if ($type['is_required']): ?>
                                                    <span class="badge bg-danger">จำเป็น</span>
                                                <?php else: ?>
                                                    <span class="badge bg-secondary">ไม่จำเป็น</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-center">
                                                <span class="badge bg-info"><?php echo number_format($type['max_files']); ?> ไฟล์</span>
                                            </td>
                                            <td class="text-center">
                                                <span class="badge bg-warning"><?php echo formatFileSize($type['max_file_size'] * 1024 * 1024); ?></span>
                                            </td>
                                            <td class="text-center">
                                                <?php if ($type['usage_count'] > 0): ?>
                                                    <span class="badge bg-success"><?php echo number_format($type['usage_count']); ?> ไฟล์</span>
                                                <?php else: ?>
                                                    <span class="text-muted">ไม่มี</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-center">
                                                <?php if ($type['is_active']): ?>
                                                    <span class="badge bg-success">ใช้งาน</span>
                                                <?php else: ?>
                                                    <span class="badge bg-secondary">ปิดใช้งาน</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-center">
                                                <div class="btn-group-vertical btn-group-sm">
                                                    <button class="btn btn-outline-primary btn-sm" 
                                                            onclick="editType(<?php echo htmlspecialchars(json_encode($type)); ?>)"
                                                            title="แก้ไข">
                                                        <i class="bi bi-pencil"></i>
                                                    </button>
                                                    <button class="btn btn-outline-info btn-sm" 
                                                            onclick="viewTypeDetails(<?php echo htmlspecialchars(json_encode($type)); ?>)"
                                                            title="ดูรายละเอียด">
                                                        <i class="bi bi-eye"></i>
                                                    </button>
                                                    <?php if ($type['usage_count'] == 0): ?>
                                                        <a href="?delete=<?php echo $type['type_id']; ?>" 
                                                           class="btn btn-outline-danger btn-sm" 
                                                           onclick="return confirm('ต้องการลบประเภทเอกสาร \'<?php echo htmlspecialchars($type['type_name']); ?>\' หรือไม่?')"
                                                           title="ลบ">
                                                            <i class="bi bi-trash"></i>
                                                        </a>
                                                    <?php else: ?>
                                                        <button class="btn btn-outline-secondary btn-sm" disabled title="ไม่สามารถลบได้เนื่องจากมีการใช้งาน">
                                                            <i class="bi bi-lock"></i>
                                                        </button>
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

<!-- Modal เพิ่มประเภทเอกสาร -->
<div class="modal fade" id="addTypeModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">เพิ่มประเภทเอกสารใหม่</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6">
                            <label class="form-label">รหัสประเภท <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="type_code" required
                                   pattern="[a-zA-Z_][a-zA-Z0-9_]*" 
                                   placeholder="เช่น bank_statement" 
                                   value="<?php echo htmlspecialchars($_POST['type_code'] ?? ''); ?>">
                            <div class="form-text">ใช้ภาษาอังกฤษ เริ่มต้นด้วยตัวอักษร ไม่มีช่องว่าง</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">ชื่อประเภท <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="type_name" required
                                   placeholder="เช่น หลักฐานการโอนเงิน" 
                                   value="<?php echo htmlspecialchars($_POST['type_name'] ?? ''); ?>">
                        </div>
                    </div>
                    
                    <div class="mt-3">
                        <label class="form-label">คำอธิบาย</label>
                        <textarea class="form-control" name="type_description" rows="3" 
                                  placeholder="อธิบายรายละเอียดของเอกสารประเภทนี้"><?php echo htmlspecialchars($_POST['type_description'] ?? ''); ?></textarea>
                    </div>
                    
                    <div class="row mt-3">
                        <div class="col-md-4">
                            <label class="form-label">จำนวนไฟล์สูงสุด <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" name="max_files" min="1" max="50" required
                                   value="<?php echo htmlspecialchars($_POST['max_files'] ?? '5'); ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">ขนาดไฟล์สูงสุด (MB) <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" name="max_file_size" min="1" max="100" required
                                   value="<?php echo htmlspecialchars($_POST['max_file_size'] ?? '10'); ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">นามสกุลที่อนุญาต</label>
                            <input type="text" class="form-control" name="allowed_extensions" 
                                   placeholder="jpg,png,pdf" 
                                   value="<?php echo htmlspecialchars($_POST['allowed_extensions'] ?? 'jpg,png,pdf'); ?>">
                            <div class="form-text">คั่นด้วยเครื่องหมาย ,</div>
                        </div>
                    </div>
                    
                    <div class="mt-3">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="is_required" id="is_required"
                                   <?php echo (!empty($_POST['is_required'])) ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="is_required">
                                <strong>เอกสารจำเป็น</strong>
                                <small class="text-muted d-block">ต้องมีเอกสารประเภทนี้ก่อนทำสัญญา</small>
                            </label>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ยกเลิก</button>
                    <button type="submit" name="add_type" class="btn btn-primary">
                        <i class="bi bi-save"></i> บันทึก
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal แก้ไขประเภทเอกสาร -->
<div class="modal fade" id="editTypeModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">แก้ไขประเภทเอกสาร</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="type_id" id="edit_type_id">
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6">
                            <label class="form-label">รหัสประเภท</label>
                            <input type="text" class="form-control" id="edit_type_code" readonly>
                            <div class="form-text">รหัสประเภทไม่สามารถแก้ไขได้</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">ชื่อประเภท <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="type_name" id="edit_type_name" required>
                        </div>
                    </div>
                    
                    <div class="mt-3">
                        <label class="form-label">คำอธิบาย</label>
                        <textarea class="form-control" name="type_description" id="edit_type_description" rows="3"></textarea>
                    </div>
                    
                    <div class="row mt-3">
                        <div class="col-md-4">
                            <label class="form-label">จำนวนไฟล์สูงสุด <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" name="max_files" id="edit_max_files" min="1" max="50" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">ขนาดไฟล์สูงสุด (MB) <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" name="max_file_size" id="edit_max_file_size" min="1" max="100" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">นามสกุลที่อนุญาต</label>
                            <input type="text" class="form-control" name="allowed_extensions" id="edit_allowed_extensions">
                        </div>
                    </div>
                    
                    <div class="mt-3">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="is_required" id="edit_is_required">
                                    <label class="form-check-label" for="edit_is_required">
                                        <strong>เอกสารจำเป็น</strong>
                                    </label>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="is_active" id="edit_is_active">
                                    <label class="form-check-label" for="edit_is_active">
                                        <strong>ใช้งาน</strong>
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ยกเลิก</button>
                    <button type="submit" name="edit_type" class="btn btn-warning">
                        <i class="bi bi-save"></i> อัปเดต
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal ดูรายละเอียด -->
<div class="modal fade" id="viewTypeModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">รายละเอียดประเภทเอกสาร</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="typeDetailsContent">
                <!-- จะโหลดข้อมูลด้วย JavaScript -->
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ปิด</button>
            </div>
        </div>
    </div>
</div>

<script>
function editType(typeData) {
    document.getElementById('edit_type_id').value = typeData.type_id;
    document.getElementById('edit_type_code').value = typeData.type_code;
    document.getElementById('edit_type_name').value = typeData.type_name;
    document.getElementById('edit_type_description').value = typeData.type_description || '';
    document.getElementById('edit_max_files').value = typeData.max_files;
    document.getElementById('edit_max_file_size').value = typeData.max_file_size;
    document.getElementById('edit_allowed_extensions').value = typeData.allowed_extensions || '';
    document.getElementById('edit_is_required').checked = typeData.is_required == 1;
    document.getElementById('edit_is_active').checked = typeData.is_active == 1;
    
    new bootstrap.Modal(document.getElementById('editTypeModal')).show();
}

function viewTypeDetails(typeData) {
    const content = document.getElementById('typeDetailsContent');
    
    content.innerHTML = `
        <div class="row">
            <div class="col-md-6">
                <table class="table table-sm">
                    <tr>
                        <td><strong>รหัสประเภท:</strong></td>
                        <td><code>${typeData.type_code}</code></td>
                    </tr>
                    <tr>
                        <td><strong>ชื่อประเภท:</strong></td>
                        <td>${typeData.type_name}</td>
                    </tr>
                    <tr>
                        <td><strong>คำอธิบาย:</strong></td>
                        <td>${typeData.type_description || '-'}</td>
                    </tr>
                    <tr>
                        <td><strong>เอกสารจำเป็น:</strong></td>
                        <td>
                            ${typeData.is_required == 1 ? 
                                '<span class="badge bg-danger">จำเป็น</span>' : 
                                '<span class="badge bg-secondary">ไม่จำเป็น</span>'}
                        </td>
                    </tr>
                    <tr>
                        <td><strong>สถานะ:</strong></td>
                        <td>
                            ${typeData.is_active == 1 ? 
                                '<span class="badge bg-success">ใช้งาน</span>' : 
                                '<span class="badge bg-secondary">ปิดใช้งาน</span>'}
                        </td>
                    </tr>
                </table>
            </div>
            <div class="col-md-6">
                <table class="table table-sm">
                    <tr>
                        <td><strong>จำนวนไฟล์สูงสุด:</strong></td>
                        <td><span class="badge bg-info">${typeData.max_files} ไฟล์</span></td>
                    </tr>
                    <tr>
                        <td><strong>ขนาดไฟล์สูงสุด:</strong></td>
                        <td><span class="badge bg-warning">${formatFileSize(typeData.max_file_size * 1024 * 1024)}</span></td>
                    </tr>
                    <tr>
                        <td><strong>นามสกุลที่อนุญาต:</strong></td>
                        <td><code>${typeData.allowed_extensions || 'ทั้งหมด'}</code></td>
                    </tr>
                    <tr>
                        <td><strong>การใช้งาน:</strong></td>
                        <td>
                            ${typeData.usage_count > 0 ? 
                                `<span class="badge bg-success">${typeData.usage_count} ไฟล์</span>` : 
                                '<span class="text-muted">ไม่มีการใช้งาน</span>'}
                        </td>
                    </tr>
                </table>
            </div>
        </div>
        
        ${typeData.created_by_name ? `
            <hr>
            <div class="row">
                <div class="col-md-6">
                    <small class="text-muted">
                        <strong>สร้างโดย:</strong> ${typeData.created_by_name}<br>
                        <strong>วันที่สร้าง:</strong> ${new Date(typeData.created_at).toLocaleDateString('th-TH')}
                    </small>
                </div>
                <div class="col-md-6">
                    ${typeData.updated_by_name ? `
                        <small class="text-muted">
                            <strong>แก้ไขล่าสุดโดย:</strong> ${typeData.updated_by_name}<br>
                            <strong>วันที่แก้ไข:</strong> ${new Date(typeData.updated_at).toLocaleDateString('th-TH')}
                        </small>
                    ` : ''}
                </div>
            </div>
        ` : ''}
    `;
    
    new bootstrap.Modal(document.getElementById('viewTypeModal')).show();
}

function formatFileSize(bytes) {
    if (bytes === 0) return '0 B';
    const k = 1024;
    const sizes = ['B', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
}

// ตรวจสอบรหัสประเภทก่อนส่งฟอร์ม
document.addEventListener('DOMContentLoaded', function() {
    const typeCodeInput = document.querySelector('input[name="type_code"]');
    if (typeCodeInput) {
        typeCodeInput.addEventListener('input', function(e) {
            const value = e.target.value;
            const isValid = /^[a-zA-Z_][a-zA-Z0-9_]*$/.test(value);
            
            if (value && !isValid) {
                e.target.setCustomValidity('รหัสประเภทต้องเริ่มต้นด้วยตัวอักษรและประกอบด้วยตัวอักษร ตัวเลข และ _ เท่านั้น');
            } else {
                e.target.setCustomValidity('');
            }
        });
    }
});

// ฟังก์ชันสำหรับรีเฟรชหน้าหลังจากปิด modal
document.getElementById('addTypeModal').addEventListener('hidden.bs.modal', function () {
    // ล้างฟอร์มเมื่อปิด modal
    this.querySelector('form').reset();
});

// ฟังก์ชันสำหรับซ่อน alert อัตโนมัติ
setTimeout(function() {
    const alerts = document.querySelectorAll('.alert-dismissible');
    alerts.forEach(function(alert) {
        if (alert) {
            const bsAlert = new bootstrap.Alert(alert);
            setTimeout(() => bsAlert.close(), 5000);
        }
    });
}, 1000);
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

<?php
// สร้างตาราง document_types หากยังไม่มี
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS document_types (
            type_id INT PRIMARY KEY AUTO_INCREMENT,
            type_code VARCHAR(50) NOT NULL UNIQUE,
            type_name VARCHAR(100) NOT NULL,
            type_description TEXT,
            is_required TINYINT(1) DEFAULT 0,
            max_files INT DEFAULT 5,
            allowed_extensions VARCHAR(255) DEFAULT 'jpg,png,pdf',
            max_file_size INT DEFAULT 10,
            is_active TINYINT(1) DEFAULT 1,
            created_by INT,
            updated_by INT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            
            FOREIGN KEY (created_by) REFERENCES users(user_id) ON DELETE SET NULL,
            FOREIGN KEY (updated_by) REFERENCES users(user_id) ON DELETE SET NULL,
            
            INDEX idx_type_code (type_code),
            INDEX idx_is_active (is_active),
            INDEX idx_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    
    // เพิ่มข้อมูลประเภทเอกสารเริ่มต้น (หากยังไม่มี)
    $default_types = [
        [
            'type_code' => 'contract',
            'type_name' => 'สัญญาเช่า',
            'type_description' => 'เอกสารสัญญาเช่าอย่างเป็นทางการ',
            'is_required' => 1,
            'max_files' => 3,
            'allowed_extensions' => 'pdf,jpg,png',
            'max_file_size' => 15
        ],
        [
            'type_code' => 'id_card',
            'type_name' => 'บัตรประชาชน',
            'type_description' => 'สำเนาบัตรประชาชนของผู้เช่าและผู้ค้ำประกัน',
            'is_required' => 1,
            'max_files' => 5,
            'allowed_extensions' => 'jpg,png,pdf',
            'max_file_size' => 10
        ],
        [
            'type_code' => 'income_proof',
            'type_name' => 'หลักฐานรายได้',
            'type_description' => 'หลักฐานแสดงรายได้ เช่น สลิปเงินเดือน ใบรับรองเงินเดือน',
            'is_required' => 1,
            'max_files' => 5,
            'allowed_extensions' => 'jpg,png,pdf',
            'max_file_size' => 10
        ],
        [
            'type_code' => 'guarantor_doc',
            'type_name' => 'เอกสารผู้ค้ำประกัน',
            'type_description' => 'เอกสารที่เกี่ยวข้องกับผู้ค้ำประกัน',
            'is_required' => 0,
            'max_files' => 10,
            'allowed_extensions' => 'jpg,png,pdf',
            'max_file_size' => 10
        ],
        [
            'type_code' => 'other',
            'type_name' => 'อื่นๆ',
            'type_description' => 'เอกสารอื่นๆ ที่เกี่ยวข้องกับการเช่า',
            'is_required' => 0,
            'max_files' => 20,
            'allowed_extensions' => 'jpg,png,pdf,doc,docx',
            'max_file_size' => 20
        ]
    ];
    
    // ตรวจสอบและเพิ่มข้อมูลเริ่มต้น
    foreach ($default_types as $type) {
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM document_types WHERE type_code = ?");
        $stmt->execute([$type['type_code']]);
        
        if ($stmt->fetch()['count'] == 0) {
            $stmt = $pdo->prepare("
                INSERT INTO document_types 
                (type_code, type_name, type_description, is_required, max_files, allowed_extensions, max_file_size) 
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $type['type_code'],
                $type['type_name'],
                $type['type_description'],
                $type['is_required'],
                $type['max_files'],
                $type['allowed_extensions'],
                $type['max_file_size']
            ]);
        }
    }
    
} catch(PDOException $e) {
    // ตารางมีอยู่แล้วหรือเกิดข้อผิดพลาดอื่น
    error_log("Database error in document_types.php: " . $e->getMessage());
}
?>