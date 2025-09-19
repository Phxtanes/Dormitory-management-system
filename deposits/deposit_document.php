<?php
$page_title = "จัดการเอกสารสัญญา";
require_once 'includes/header.php';

$success_message = '';
$error_message = '';

// รับ contract_id หรือ deposit_id
$contract_id = isset($_GET['contract_id']) ? intval($_GET['contract_id']) : 0;
$deposit_id = isset($_GET['deposit_id']) ? intval($_GET['deposit_id']) : 0;

// หาก deposit_id มี ให้หา contract_id
if ($deposit_id > 0 && $contract_id == 0) {
    try {
        $stmt = $pdo->prepare("SELECT contract_id FROM deposits WHERE deposit_id = ?");
        $stmt->execute([$deposit_id]);
        $result = $stmt->fetch();
        if ($result) {
            $contract_id = $result['contract_id'];
        }
    } catch(PDOException $e) {
        $error_message = "เกิดข้อผิดพลาด: " . $e->getMessage();
    }
}

if ($contract_id <= 0) {
    header('Location: deposits.php');
    exit;
}

// ตรวจสอบการอัพโหลดไฟล์
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['upload_document'])) {
    $document_type = $_POST['document_type'];
    $description = trim($_POST['description']);
    
    if (isset($_FILES['document_file']) && $_FILES['document_file']['error'] == UPLOAD_ERR_OK) {
        $file = $_FILES['document_file'];
        $original_filename = $file['name'];
        $file_size = $file['size'];
        $file_tmp = $file['tmp_name'];
        $mime_type = $file['type'];
        
        // ตรวจสอบประเภทไฟล์
        $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'application/pdf', 'image/webp'];
        $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'webp'];
        
        $file_extension = strtolower(pathinfo($original_filename, PATHINFO_EXTENSION));
        
        if (!in_array($mime_type, $allowed_types) || !in_array($file_extension, $allowed_extensions)) {
            $error_message = "ประเภทไฟล์ไม่ถูกต้อง อนุญาตเฉพาะ JPG, PNG, GIF, PDF, WEBP เท่านั้น";
        } elseif ($file_size > 10 * 1024 * 1024) { // 10MB
            $error_message = "ขนาดไฟล์ต้องไม่เกิน 10 MB";
        } else {
            // สร้างโฟลเดอร์หากไม่มี
            $upload_dir = 'uploads/contracts/';
            if (!file_exists($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }
            
            // สร้างชื่อไฟล์ใหม่
            $stored_filename = $contract_id . '_' . time() . '_' . uniqid() . '.' . $file_extension;
            $file_path = $upload_dir . $stored_filename;
            
            if (move_uploaded_file($file_tmp, $file_path)) {
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
                    
                    $success_message = "อัพโหลดเอกสารเรียบร้อยแล้ว";
                } catch(PDOException $e) {
                    $error_message = "เกิดข้อผิดพลาดในการบันทึกข้อมูล: " . $e->getMessage();
                    // ลบไฟล์ที่อัพโหลดแล้ว
                    if (file_exists($file_path)) {
                        unlink($file_path);
                    }
                }
            } else {
                $error_message = "เกิดข้อผิดพลาดในการอัพโหลดไฟล์";
            }
        }
    } else {
        $error_message = "กรุณาเลือกไฟล์ที่ต้องการอัพโหลด";
    }
}

// ตรวจสอบการลบไฟล์
if (isset($_GET['delete_doc']) && is_numeric($_GET['delete_doc'])) {
    $doc_id = $_GET['delete_doc'];
    
    try {
        // ดึงข้อมูลไฟล์ก่อนลบ
        $stmt = $pdo->prepare("SELECT file_path FROM contract_documents WHERE document_id = ? AND contract_id = ?");
        $stmt->execute([$doc_id, $contract_id]);
        $doc = $stmt->fetch();
        
        if ($doc) {
            // ลบไฟล์จากระบบ
            if (file_exists($doc['file_path'])) {
                unlink($doc['file_path']);
            }
            
            // ลบข้อมูลจากฐานข้อมูล
            $stmt = $pdo->prepare("DELETE FROM contract_documents WHERE document_id = ? AND contract_id = ?");
            $stmt->execute([$doc_id, $contract_id]);
            
            $success_message = "ลบเอกสารเรียบร้อยแล้ว";
        } else {
            $error_message = "ไม่พบเอกสารที่ต้องการลบ";
        }
    } catch(PDOException $e) {
        $error_message = "เกิดข้อผิดพลาดในการลบเอกสาร: " . $e->getMessage();
    }
}

// ดึงข้อมูลสัญญา
try {
    $stmt = $pdo->prepare("
        SELECT c.*, r.room_number, CONCAT(t.first_name, ' ', t.last_name) as tenant_name, t.phone
        FROM contracts c
        JOIN rooms r ON c.room_id = r.room_id
        JOIN tenants t ON c.tenant_id = t.tenant_id
        WHERE c.contract_id = ?
    ");
    $stmt->execute([$contract_id]);
    $contract = $stmt->fetch();
    
    if (!$contract) {
        header('Location: deposits.php');
        exit;
    }
} catch(PDOException $e) {
    $error_message = "เกิดข้อผิดพลาดในการดึงข้อมูลสัญญา: " . $e->getMessage();
}

// ดึงเอกสารทั้งหมด
try {
    $stmt = $pdo->prepare("
        SELECT cd.*, u.full_name as uploaded_by_name
        FROM contract_documents cd
        LEFT JOIN users u ON cd.uploaded_by = u.user_id
        WHERE cd.contract_id = ?
        ORDER BY cd.upload_date DESC
    ");
    $stmt->execute([$contract_id]);
    $documents = $stmt->fetchAll();
} catch(PDOException $e) {
    $error_message = "เกิดข้อผิดพลาดในการดึงข้อมูลเอกสาร: " . $e->getMessage();
    $documents = [];
}

include 'includes/navbar.php';
?>

<div class="container-fluid mt-4">
    <div class="row">
        <div class="col-12">
            <!-- หัวข้อหน้า -->
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2 class="mb-0">
                    <i class="bi bi-file-earmark-text"></i>
                    จัดการเอกสารสัญญา
                </h2>
                <div class="btn-group">
                    <a href="deposits.php" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left"></i>
                        กลับ
                    </a>
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#uploadModal">
                        <i class="bi bi-cloud-upload"></i>
                        อัพโหลดเอกสาร
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

            <!-- ข้อมูลสัญญา -->
            <?php if ($contract): ?>
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="card-title mb-0">ข้อมูลสัญญา</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-3">
                            <strong>หมายเลขสัญญา:</strong><br>
                            <span class="text-primary">#<?php echo $contract['contract_id']; ?></span>
                        </div>
                        <div class="col-md-3">
                            <strong>ห้อง:</strong><br>
                            <?php echo htmlspecialchars($contract['room_number']); ?>
                        </div>
                        <div class="col-md-3">
                            <strong>ผู้เช่า:</strong><br>
                            <?php echo htmlspecialchars($contract['tenant_name']); ?>
                        </div>
                        <div class="col-md-3">
                            <strong>เบอร์โทร:</strong><br>
                            <?php echo htmlspecialchars($contract['phone']); ?>
                        </div>
                    </div>
                    <div class="row mt-3">
                        <div class="col-md-3">
                            <strong>วันที่เริ่มสัญญา:</strong><br>
                            <?php echo date('d/m/Y', strtotime($contract['contract_start'])); ?>
                        </div>
                        <div class="col-md-3">
                            <strong>วันที่สิ้นสุด:</strong><br>
                            <?php echo date('d/m/Y', strtotime($contract['contract_end'])); ?>
                        </div>
                        <div class="col-md-3">
                            <strong>ค่าเช่า/เดือน:</strong><br>
                            ฿<?php echo number_format($contract['monthly_rent'], 2); ?>
                        </div>
                        <div class="col-md-3">
                            <strong>เงินมัดจำ:</strong><br>
                            ฿<?php echo number_format($contract['deposit_paid'], 2); ?>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- รายการเอกสาร -->
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title mb-0">เอกสารแนบ (<?php echo count($documents); ?> ไฟล์)</h5>
                </div>
                <div class="card-body">
                    <?php if (empty($documents)): ?>
                        <div class="text-center py-5">
                            <i class="bi bi-file-earmark-text fs-1 text-muted d-block mb-3"></i>
                            <h5 class="text-muted">ยังไม่มีเอกสารแนบ</h5>
                            <p class="text-muted">คลิกปุ่ม "อัพโหลดเอกสาร" เพื่อเริ่มต้น</p>
                        </div>
                    <?php else: ?>
                        <div class="row">
                            <?php foreach ($documents as $doc): ?>
                                <div class="col-md-6 col-lg-4 mb-4">
                                    <div class="card h-100">
                                        <div class="card-body">
                                            <!-- แสดงตัวอย่างไฟล์ -->
                                            <div class="text-center mb-3">
                                                <?php if (strpos($doc['mime_type'], 'image/') === 0): ?>
                                                    <img src="<?php echo htmlspecialchars($doc['file_path']); ?>" 
                                                         class="img-thumbnail" style="max-height: 150px; cursor: pointer;"
                                                         onclick="viewImage('<?php echo htmlspecialchars($doc['file_path']); ?>', '<?php echo htmlspecialchars($doc['original_filename']); ?>')">
                                                <?php else: ?>
                                                    <div class="bg-light p-4 rounded">
                                                        <i class="bi bi-file-earmark-pdf fs-1 text-danger"></i>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                            
                                            <!-- ข้อมูลไฟล์ -->
                                            <h6 class="card-title text-truncate" title="<?php echo htmlspecialchars($doc['original_filename']); ?>">
                                                <?php echo htmlspecialchars($doc['original_filename']); ?>
                                            </h6>
                                            
                                            <div class="small text-muted mb-2">
                                                <div>
                                                    <strong>ประเภท:</strong> 
                                                    <?php
                                                    $doc_types = [
                                                        'contract' => 'สัญญาเช่า',
                                                        'id_card' => 'บัตรประชาชน',
                                                        'income_proof' => 'หลักฐานรายได้',
                                                        'guarantor_doc' => 'เอกสารผู้ค้ำประกัน',
                                                        'other' => 'อื่นๆ'
                                                    ];
                                                    echo $doc_types[$doc['document_type']] ?? $doc['document_type'];
                                                    ?>
                                                </div>
                                                <div><strong>ขนาด:</strong> <?php echo formatFileSize($doc['file_size']); ?></div>
                                                <div><strong>อัพโหลดโดย:</strong> <?php echo htmlspecialchars($doc['uploaded_by_name'] ?? 'ไม่ทราบ'); ?></div>
                                                <div><strong>วันที่:</strong> <?php echo date('d/m/Y H:i', strtotime($doc['upload_date'])); ?></div>
                                            </div>
                                            
                                            <?php if (!empty($doc['description'])): ?>
                                                <p class="small text-muted mb-2">
                                                    <strong>หมายเหตุ:</strong> <?php echo htmlspecialchars($doc['description']); ?>
                                                </p>
                                            <?php endif; ?>
                                        </div>
                                        
                                        <div class="card-footer bg-transparent">
                                            <div class="btn-group w-100">
                                                <a href="<?php echo htmlspecialchars($doc['file_path']); ?>" 
                                                   target="_blank" class="btn btn-outline-primary btn-sm">
                                                    <i class="bi bi-eye"></i> ดู
                                                </a>
                                                <a href="download.php?doc_id=<?php echo $doc['document_id']; ?>" 
                                                   class="btn btn-outline-success btn-sm">
                                                    <i class="bi bi-download"></i> ดาวน์โหลด
                                                </a>
                                                <a href="?contract_id=<?php echo $contract_id; ?>&delete_doc=<?php echo $doc['document_id']; ?>" 
                                                   class="btn btn-outline-danger btn-sm"
                                                   onclick="return confirm('ต้องการลบเอกสารนี้หรือไม่?')">
                                                    <i class="bi bi-trash"></i> ลบ
                                                </a>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal อัพโหลดเอกสาร -->
<div class="modal fade" id="uploadModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">อัพโหลดเอกสาร</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" enctype="multipart/form-data">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">ประเภทเอกสาร</label>
                        <select class="form-select" name="document_type" required>
                            <option value="">-- เลือกประเภทเอกสาร --</option>
                            <option value="contract">สัญญาเช่า</option>
                            <option value="id_card">สำเนาบัตรประชาชน</option>
                            <option value="income_proof">หลักฐานรายได้</option>
                            <option value="guarantor_doc">เอกสารผู้ค้ำประกัน</option>
                            <option value="other">อื่นๆ</option>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">เลือกไฟล์</label>
                        <input type="file" class="form-control" name="document_file" 
                               accept=".jpg,.jpeg,.png,.gif,.pdf,.webp" required>
                        <div class="form-text">
                            ประเภทไฟล์ที่รองรับ: JPG, PNG, GIF, PDF, WEBP (ขนาดไม่เกิน 10 MB)
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">หมายเหตุ (ไม่จำเป็น)</label>
                        <textarea class="form-control" name="description" rows="3" 
                                  placeholder="รายละเอียดเพิ่มเติมเกี่ยวกับเอกสารนี้"></textarea>
                    </div>
                    
                    <!-- ตัวอย่างการใช้งาน -->
                    <div class="alert alert-info">
                        <h6><i class="bi bi-info-circle"></i> คำแนะนำ:</h6>
                        <ul class="mb-0 small">
                            <li><strong>สัญญาเช่า:</strong> ไฟล์สัญญาเช่าที่ลงนามแล้ว</li>
                            <li><strong>บัตรประชาชน:</strong> สำเนาบัตรประชาชนผู้เช่า</li>
                            <li><strong>หลักฐานรายได้:</strong> สลิปเงินเดือน, งบการเงิน</li>
                            <li><strong>เอกสารผู้ค้ำ:</strong> บัตรประชาชนและหลักฐานรายได้ผู้ค้ำประกัน</li>
                        </ul>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ยกเลิก</button>
                    <button type="submit" name="upload_document" class="btn btn-primary">
                        <i class="bi bi-cloud-upload"></i> อัพโหลด
                    </button>
                </div>
            </form>
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
function viewImage(imagePath, filename) {
    document.getElementById('modalImage').src = imagePath;
    document.getElementById('imageModalTitle').textContent = filename;
    new bootstrap.Modal(document.getElementById('imageModal')).show();
}

// ตรวจสอบขนาดไฟล์ก่อนอัพโหลด
document.querySelector('input[name="document_file"]').addEventListener('change', function(e) {
    const file = e.target.files[0];
    if (file) {
        const maxSize = 10 * 1024 * 1024; // 10 MB
        if (file.size > maxSize) {
            alert('ขนาดไฟล์เกิน 10 MB กรุณาเลือกไฟล์ที่เล็กกว่า');
            e.target.value = '';
            return;
        }
        
        // แสดงตัวอย่างรูปภาพ
        if (file.type.startsWith('image/')) {
            const reader = new FileReader();
            reader.onload = function(e) {
                // สร้าง preview image (ถ้าต้องการ)
                console.log('Image loaded:', e.target.result);
            };
            reader.readAsDataURL(file);
        }
    }
});
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