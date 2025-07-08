<?php
$page_title = "ตั้งค่าการอัพโหลดเอกสาร";
require_once 'includes/header.php';

$success_message = '';
$error_message = '';

// ตรวจสอบสิทธิ์ (เฉพาะ admin เท่านั้น)
if (!is_admin()) {
    header('Location: dashboard.php');
    exit;
}

// สร้างตาราง document_settings หากยังไม่มี
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS document_settings (
            setting_id INT AUTO_INCREMENT PRIMARY KEY,
            setting_key VARCHAR(100) NOT NULL UNIQUE,
            setting_value TEXT,
            setting_description TEXT,
            setting_type ENUM('text', 'number', 'boolean', 'select') DEFAULT 'text',
            setting_group VARCHAR(50) DEFAULT 'general',
            display_order INT DEFAULT 0,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            updated_by INT,
            FOREIGN KEY (updated_by) REFERENCES users(user_id),
            INDEX idx_setting_key (setting_key),
            INDEX idx_setting_group (setting_group)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    
    // เพิ่มการตั้งค่าเริ่มต้นสำหรับเอกสาร
    $default_settings = [
        // การตั้งค่าทั่วไป
        ['max_file_size_global', '20', 'ขนาดไฟล์สูงสุดโดยรวม (MB)', 'number', 'general', 1],
        ['max_files_per_upload', '10', 'จำนวนไฟล์สูงสุดต่อการอัพโหลด', 'number', 'general', 2],
        ['allowed_extensions_global', 'jpg,jpeg,png,pdf,doc,docx', 'นามสกุลไฟล์ที่อนุญาตโดยรวม', 'text', 'general', 3],
        ['upload_path', 'uploads/documents/', 'โฟลเดอร์สำหรับเก็บเอกสาร', 'text', 'general', 4],
        ['auto_delete_temp', '1', 'ลบไฟล์ชั่วคราวอัตโนมัติ', 'boolean', 'general', 5],
        ['temp_file_lifetime', '24', 'อายุไฟล์ชั่วคราว (ชั่วโมง)', 'number', 'general', 6],
        
        // การตั้งค่าความปลอดภัย
        ['scan_viruses', '0', 'สแกนไวรัสไฟล์ที่อัพโหลด', 'boolean', 'security', 7],
        ['block_executable', '1', 'บล็อกไฟล์ปฏิบัติการ', 'boolean', 'security', 8],
        ['check_file_content', '1', 'ตรวจสอบเนื้อหาในไฟล์', 'boolean', 'security', 9],
        ['watermark_pdfs', '0', 'ใส่ลายน้ำใน PDF', 'boolean', 'security', 10],
        ['encrypt_sensitive', '0', 'เข้ารหัสเอกสารสำคัญ', 'boolean', 'security', 11],
        
        // การตั้งค่าการแสดงผล
        ['thumbnail_size', '150', 'ขนาด thumbnail (px)', 'number', 'display', 12],
        ['generate_thumbnails', '1', 'สร้าง thumbnail อัตโนมัติ', 'boolean', 'display', 13],
        ['preview_quality', '85', 'คุณภาพ preview (1-100)', 'number', 'display', 14],
        ['items_per_page', '20', 'จำนวนรายการต่อหน้า', 'number', 'display', 15],
        ['default_view_mode', 'grid', 'โหมดแสดงผลเริ่มต้น', 'select', 'display', 16],
        
        // การตั้งค่าการสำรองข้อมูล
        ['auto_backup_documents', '1', 'สำรองเอกสารอัตโนมัติ', 'boolean', 'backup', 17],
        ['backup_frequency', 'daily', 'ความถี่การสำรองข้อมูล', 'select', 'backup', 18],
        ['backup_retention_days', '30', 'เก็บไฟล์สำรองข้อมูล (วัน)', 'number', 'backup', 19],
        ['compress_backups', '1', 'บีบอัดไฟล์สำรองข้อมูล', 'boolean', 'backup', 20],
        
        // การตั้งค่าการแจ้งเตือน
        ['notify_upload_success', '1', 'แจ้งเตือนเมื่ออัพโหลดสำเร็จ', 'boolean', 'notification', 21],
        ['notify_upload_fail', '1', 'แจ้งเตือนเมื่ออัพโหลดล้มเหลว', 'boolean', 'notification', 22],
        ['notify_document_expire', '1', 'แจ้งเตือนเอกสารหมดอายุ', 'boolean', 'notification', 23],
        ['document_expire_days', '30', 'แจ้งเตือนก่อนหมดอายุ (วัน)', 'number', 'notification', 24],
        
        // การตั้งค่าขั้นสูง
        ['enable_versioning', '1', 'เก็บประวัติการแก้ไขไฟล์', 'boolean', 'advanced', 25],
        ['max_versions_per_file', '5', 'จำนวน version สูงสุดต่อไฟล์', 'number', 'advanced', 26],
        ['ocr_processing', '0', 'ประมวลผล OCR สำหรับ PDF', 'boolean', 'advanced', 27],
        ['auto_categorize', '0', 'จัดหมวดหมู่อัตโนมัติ', 'boolean', 'advanced', 28]
    ];
    
    // ตรวจสอบและเพิ่มการตั้งค่าเริ่มต้น
    $check_stmt = $pdo->prepare("SELECT COUNT(*) FROM document_settings");
    $check_stmt->execute();
    $setting_count = $check_stmt->fetchColumn();
    
    if ($setting_count == 0) {
        $insert_stmt = $pdo->prepare("
            INSERT IGNORE INTO document_settings 
            (setting_key, setting_value, setting_description, setting_type, setting_group, display_order) 
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        foreach ($default_settings as $setting) {
            $insert_stmt->execute($setting);
        }
    }
} catch(PDOException $e) {
    $error_message = 'เกิดข้อผิดพลาดในการสร้างตาราง: ' . $e->getMessage();
}

// บันทึกการตั้งค่า
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['save_settings'])) {
    try {
        $pdo->beginTransaction();
        
        foreach ($_POST as $key => $value) {
            if ($key !== 'save_settings') {
                // ตรวจสอบประเภทข้อมูล
                $check_type = $pdo->prepare("SELECT setting_type FROM document_settings WHERE setting_key = ?");
                $check_type->execute([$key]);
                $setting_type = $check_type->fetchColumn();
                
                if ($setting_type) {
                    // แปลงค่าตามประเภท
                    if ($setting_type === 'boolean') {
                        $value = isset($_POST[$key]) ? '1' : '0';
                    } elseif ($setting_type === 'number') {
                        $value = is_numeric($value) ? $value : '0';
                    }
                    
                    // อัปเดตค่า
                    $update_stmt = $pdo->prepare("
                        UPDATE document_settings 
                        SET setting_value = ?, updated_by = ?, updated_at = NOW() 
                        WHERE setting_key = ?
                    ");
                    $update_stmt->execute([$value, $_SESSION['user_id'], $key]);
                }
            }
        }
        
        $pdo->commit();
        $success_message = 'บันทึกการตั้งค่าเรียบร้อยแล้ว';
        
    } catch(PDOException $e) {
        $pdo->rollback();
        $error_message = 'เกิดข้อผิดพลาดในการบันทึก: ' . $e->getMessage();
    }
}

// รีเซ็ตเป็นค่าเริ่มต้น
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['reset_defaults'])) {
    try {
        $pdo->exec("DROP TABLE IF EXISTS document_settings");
        header('Location: document_settings.php?reset=1');
        exit;
    } catch(PDOException $e) {
        $error_message = 'เกิดข้อผิดพลาดในการรีเซ็ต: ' . $e->getMessage();
    }
}

// ดึงการตั้งค่าปัจจุบัน
try {
    $stmt = $pdo->prepare("
        SELECT ds.*, u.full_name as updated_by_name 
        FROM document_settings ds
        LEFT JOIN users u ON ds.updated_by = u.user_id
        ORDER BY ds.setting_group, ds.display_order, ds.setting_key
    ");
    $stmt->execute();
    $all_settings = $stmt->fetchAll();
    
    // จัดกลุ่มการตั้งค่า
    $grouped_settings = [];
    foreach ($all_settings as $setting) {
        $grouped_settings[$setting['setting_group']][] = $setting;
    }
    
} catch(PDOException $e) {
    $error_message = 'เกิดข้อผิดพลาดในการดึงข้อมูล: ' . $e->getMessage();
    $grouped_settings = [];
}

include 'includes/navbar.php';
?>

<div class="container-fluid mt-4">
    <div class="row">
        <div class="col-12">
            <!-- หัวข้อหน้า -->
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2 class="mb-0">
                    <i class="bi bi-gear"></i>
                    ตั้งค่าการอัพโหลดเอกสาร
                </h2>
                <div class="btn-group">
                    <a href="all_documents.php" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left"></i>
                        กลับ
                    </a>
                    <button class="btn btn-success" onclick="exportSettings()">
                        <i class="bi bi-download"></i>
                        Export การตั้งค่า
                    </button>
                    <button class="btn btn-info" data-bs-toggle="modal" data-bs-target="#importModal">
                        <i class="bi bi-upload"></i>
                        Import การตั้งค่า
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

            <?php if (isset($_GET['reset'])): ?>
                <div class="alert alert-info alert-dismissible fade show" role="alert">
                    <i class="bi bi-info-circle"></i>
                    การตั้งค่าถูกรีเซ็ตเป็นค่าเริ่มต้นแล้ว
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <!-- ข้อมูลสถานะระบบ -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="card-title mb-0">
                        <i class="bi bi-info-circle"></i>
                        สถานะระบบ
                    </h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-3">
                            <div class="d-flex align-items-center">
                                <i class="bi bi-server text-primary fs-4 me-2"></i>
                                <div>
                                    <div class="small text-muted">PHP Max Upload</div>
                                    <strong><?php echo ini_get('upload_max_filesize'); ?></strong>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="d-flex align-items-center">
                                <i class="bi bi-memory text-success fs-4 me-2"></i>
                                <div>
                                    <div class="small text-muted">Memory Limit</div>
                                    <strong><?php echo ini_get('memory_limit'); ?></strong>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="d-flex align-items-center">
                                <i class="bi bi-hdd text-warning fs-4 me-2"></i>
                                <div>
                                    <div class="small text-muted">Disk Space</div>
                                    <strong>
                                        <?php 
                                        $bytes = disk_free_space(".");
                                        $si_prefix = array( 'B', 'KB', 'MB', 'GB', 'TB', 'EB', 'ZB', 'YB' );
                                        $base = 1024;
                                        $class = min((int)log($bytes , $base) , count($si_prefix) - 1);
                                        echo sprintf('%1.2f' , $bytes / pow($base,$class)) . ' ' . $si_prefix[$class];
                                        ?>
                                    </strong>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="d-flex align-items-center">
                                <i class="bi bi-folder text-info fs-4 me-2"></i>
                                <div>
                                    <div class="small text-muted">Upload Folder</div>
                                    <strong>
                                        <?php 
                                        $upload_path = 'uploads/documents/';
                                        echo is_writable($upload_path) ? 
                                            '<span class="text-success">เขียนได้</span>' : 
                                            '<span class="text-danger">เขียนไม่ได้</span>';
                                        ?>
                                    </strong>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ฟอร์มการตั้งค่า -->
            <form method="POST" id="settingsForm">
                <?php
                $group_titles = [
                    'general' => ['การตั้งค่าทั่วไป', 'bi-gear', 'primary'],
                    'security' => ['ความปลอดภัย', 'bi-shield-check', 'danger'],
                    'display' => ['การแสดงผล', 'bi-display', 'info'],
                    'backup' => ['การสำรองข้อมูล', 'bi-archive', 'warning'],
                    'notification' => ['การแจ้งเตือน', 'bi-bell', 'success'],
                    'advanced' => ['ขั้นสูง', 'bi-tools', 'secondary']
                ];
                
                foreach ($group_titles as $group_key => $group_info):
                    if (!isset($grouped_settings[$group_key])) continue;
                ?>
                
                <!-- กลุ่มการตั้งค่า -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="card-title mb-0">
                            <i class="bi <?php echo $group_info[1]; ?> text-<?php echo $group_info[2]; ?>"></i>
                            <?php echo $group_info[0]; ?>
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <?php foreach ($grouped_settings[$group_key] as $setting): ?>
                                <div class="col-md-6 mb-3">
                                    <label for="<?php echo $setting['setting_key']; ?>" class="form-label">
                                        <?php echo htmlspecialchars($setting['setting_description']); ?>
                                    </label>
                                    
                                    <?php if ($setting['setting_type'] === 'boolean'): ?>
                                        <div class="form-check form-switch">
                                            <input class="form-check-input" 
                                                   type="checkbox" 
                                                   id="<?php echo $setting['setting_key']; ?>" 
                                                   name="<?php echo $setting['setting_key']; ?>"
                                                   <?php echo $setting['setting_value'] ? 'checked' : ''; ?>>
                                            <label class="form-check-label" for="<?php echo $setting['setting_key']; ?>">
                                                เปิดใช้งาน
                                            </label>
                                        </div>
                                        
                                    <?php elseif ($setting['setting_type'] === 'select'): ?>
                                        <select class="form-select" 
                                                id="<?php echo $setting['setting_key']; ?>" 
                                                name="<?php echo $setting['setting_key']; ?>">
                                            <?php
                                            $options = [];
                                            if ($setting['setting_key'] === 'default_view_mode') {
                                                $options = ['grid' => 'ตาราง', 'list' => 'รายการ'];
                                            } elseif ($setting['setting_key'] === 'backup_frequency') {
                                                $options = [
                                                    'hourly' => 'ทุกชั่วโมง', 
                                                    'daily' => 'ทุกวัน', 
                                                    'weekly' => 'ทุกสัปดาห์',
                                                    'monthly' => 'ทุกเดือน'
                                                ];
                                            }
                                            
                                            foreach ($options as $value => $label):
                                            ?>
                                                <option value="<?php echo $value; ?>" 
                                                        <?php echo $setting['setting_value'] === $value ? 'selected' : ''; ?>>
                                                    <?php echo $label; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        
                                    <?php elseif ($setting['setting_type'] === 'number'): ?>
                                        <input type="number" 
                                               class="form-control" 
                                               id="<?php echo $setting['setting_key']; ?>" 
                                               name="<?php echo $setting['setting_key']; ?>"
                                               value="<?php echo htmlspecialchars($setting['setting_value']); ?>"
                                               min="0"
                                               <?php
                                               if (strpos($setting['setting_key'], 'max') !== false) echo 'max="1000"';
                                               if (strpos($setting['setting_key'], 'quality') !== false) echo 'max="100"';
                                               ?>>
                                        
                                    <?php else: ?>
                                        <input type="text" 
                                               class="form-control" 
                                               id="<?php echo $setting['setting_key']; ?>" 
                                               name="<?php echo $setting['setting_key']; ?>"
                                               value="<?php echo htmlspecialchars($setting['setting_value']); ?>">
                                    <?php endif; ?>
                                    
                                    <?php if ($setting['updated_by']): ?>
                                        <div class="form-text">
                                            <small class="text-muted">
                                                แก้ไขล่าสุดโดย: <?php echo htmlspecialchars($setting['updated_by_name']); ?> 
                                                เมื่อ <?php echo date('d/m/Y H:i', strtotime($setting['updated_at'])); ?>
                                            </small>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                
                <?php endforeach; ?>

                <!-- ปุ่มบันทึก -->
                <div class="card">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <button type="submit" name="save_settings" class="btn btn-primary btn-lg">
                                    <i class="bi bi-save"></i>
                                    บันทึกการตั้งค่า
                                </button>
                                <button type="button" class="btn btn-secondary btn-lg" onclick="resetForm()">
                                    <i class="bi bi-arrow-clockwise"></i>
                                    รีเซ็ต
                                </button>
                            </div>
                            <div>
                                <button type="button" class="btn btn-warning" data-bs-toggle="modal" data-bs-target="#resetModal">
                                    <i class="bi bi-exclamation-triangle"></i>
                                    รีเซ็ตเป็นค่าเริ่มต้น
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal รีเซ็ตค่าเริ่มต้น -->
<div class="modal fade" id="resetModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">ยืนยันการรีเซ็ต</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-warning">
                    <i class="bi bi-exclamation-triangle"></i>
                    <strong>คำเตือน!</strong> การรีเซ็ตจะเปลี่ยนการตั้งค่าทั้งหมดกลับเป็นค่าเริ่มต้น
                </div>
                <p>คุณแน่ใจหรือไม่ที่ต้องการรีเซ็ตการตั้งค่าทั้งหมด?</p>
                <p class="text-muted small">การดำเนินการนี้ไม่สามารถยกเลิกได้</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ยกเลิก</button>
                <form method="POST" style="display: inline;">
                    <button type="submit" name="reset_defaults" class="btn btn-danger">
                        <i class="bi bi-arrow-clockwise"></i>
                        รีเซ็ตเป็นค่าเริ่มต้น
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Modal Import การตั้งค่า -->
<div class="modal fade" id="importModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Import การตั้งค่า</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label for="importFile" class="form-label">เลือกไฟล์การตั้งค่า (.json)</label>
                    <input type="file" class="form-control" id="importFile" accept=".json">
                </div>
                <div class="alert alert-info">
                    <i class="bi bi-info-circle"></i>
                    รองรับไฟล์ JSON ที่ export จากระบบนี้เท่านั้น
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ยกเลิก</button>
                <button type="button" class="btn btn-primary" onclick="importSettings()">
                    <i class="bi bi-upload"></i>
                    Import
                </button>
            </div>
        </div>
    </div>
</div>

<script>
// ตรวจสอบการเปลี่ยนแปลง
let originalFormData = new FormData(document.getElementById('settingsForm'));
let hasChanges = false;

document.getElementById('settingsForm').addEventListener('input', function() {
    hasChanges = true;
});

window.addEventListener('beforeunload', function(e) {
    if (hasChanges) {
        e.preventDefault();
        e.returnValue = 'มีการเปลี่ยนแปลงที่ยังไม่ได้บันทึก คุณต้องการออกจากหน้านี้หรือไม่?';
    }
});

// รีเซ็ตฟอร์ม
function resetForm() {
    if (confirm('ต้องการยกเลิกการเปลี่ยนแปลงทั้งหมดหรือไม่?')) {
        location.reload();
    }
}

// Export การตั้งค่า
function exportSettings() {
    const form = document.getElementById('settingsForm');
    const formData = new FormData(form);
    const settings = {};
    
    // รวบรวมข้อมูลการตั้งค่า
    for (let [key, value] of formData.entries()) {
        if (key !== 'save_settings') {
            const input = document.querySelector(`[name="${key}"]`);
            if (input.type === 'checkbox') {
                settings[key] = input.checked ? '1' : '0';
            } else {
                settings[key] = value;
            }
        }
    }
    
    // เพิ่มข้อมูล metadata
    settings._metadata = {
        exported_at: new Date().toISOString(),
        exported_by: '<?php echo $_SESSION['full_name'] ?? 'Unknown'; ?>',
        version: '1.0'
    };
    
    // สร้างไฟล์ JSON
    const dataStr = JSON.stringify(settings, null, 2);
    const dataBlob = new Blob([dataStr], {type: 'application/json'});
    const url = URL.createObjectURL(dataBlob);
    
    // ดาวน์โหลด
    const link = document.createElement('a');
    link.href = url;
    link.download = 'document_settings_' + new Date().toISOString().slice(0,10) + '.json';
    link.click();
    
    URL.revokeObjectURL(url);
}

// Import การตั้งค่า
function importSettings() {
    const fileInput = document.getElementById('importFile');
    const file = fileInput.files[0];
    
    if (!file) {
        alert('กรุณาเลือกไฟล์');
        return;
    }
    
    if (file.type !== 'application/json') {
        alert('รองรับเฉพาะไฟล์ JSON เท่านั้น');
        return;
    }
    
    const reader = new FileReader();
    reader.onload = function(e) {
        try {
            const settings = JSON.parse(e.target.result);
            
            // ตรวจสอบ metadata
            if (!settings._metadata || !settings._metadata.version) {
                if (!confirm('ไฟล์นี้อาจไม่ใช่ไฟล์การตั้งค่าที่ถูกต้อง ต้องการดำเนินการต่อหรือไม่?')) {
                    return;
                }
            }
            
            // นำเข้าการตั้งค่า
            let importCount = 0;
            for (const [key, value] of Object.entries(settings)) {
                if (key === '_metadata') continue;
                
                const input = document.querySelector(`[name="${key}"]`);
                if (input) {
                    if (input.type === 'checkbox') {
                        input.checked = value === '1' || value === true;
                    } else {
                        input.value = value;
                    }
                    importCount++;
                }
            }
            
            alert(`นำเข้าการตั้งค่า ${importCount} รายการเรียบร้อยแล้ว\nกรุณาคลิก "บันทึกการตั้งค่า" เพื่อยืนยัน`);
            hasChanges = true;
            
            // ปิด modal
            bootstrap.Modal.getInstance(document.getElementById('importModal')).hide();
            
        } catch (error) {
            alert('ไฟล์ไม่ถูกต้องหรือเสียหาย: ' + error.message);
        }
    };
    
    reader.readAsText(file);
}

// ตรวจสอบค่าที่ป้อน
document.addEventListener('DOMContentLoaded', function() {
    // ตรวจสอบขนาดไฟล์
    const maxFileSizeInput = document.getElementById('max_file_size_global');
    if (maxFileSizeInput) {
        maxFileSizeInput.addEventListener('input', function() {
            const value = parseInt(this.value);
            const phpMaxUpload = '<?php echo ini_get('upload_max_filesize'); ?>';
            const phpMaxUploadMB = parseInt(phpMaxUpload);
            
            if (value > phpMaxUploadMB) {
                this.setCustomValidity(`ขนาดไฟล์สูงสุดไม่ควรเกิน PHP upload_max_filesize (${phpMaxUpload})`);
            } else {
                this.setCustomValidity('');
            }
        });
    }
    
    // ตรวจสอบนามสกุลไฟล์
    const allowedExtInput = document.getElementById('allowed_extensions_global');
    if (allowedExtInput) {
        allowedExtInput.addEventListener('input', function() {
            const value = this.value;
            const dangerousExt = ['exe', 'bat', 'cmd', 'com', 'pif', 'scr', 'vbs', 'js'];
            const extensions = value.toLowerCase().split(',').map(ext => ext.trim());
            
            for (const ext of extensions) {
                if (dangerousExt.includes(ext)) {
                    this.setCustomValidity(`นามสกุล .${ext} อาจไม่ปลอดภัย`);
                    return;
                }
            }
            this.setCustomValidity('');
        });
    }
    
    // ตรวจสอบ path
    const uploadPathInput = document.getElementById('upload_path');
    if (uploadPathInput) {
        uploadPathInput.addEventListener('input', function() {
            const value = this.value;
            if (!value.endsWith('/')) {
                this.value = value + '/';
            }
            
            // ตรวจสอบ path traversal
            if (value.includes('..') || value.includes('//')) {
                this.setCustomValidity('Path ไม่ปลอดภัย');
            } else {
                this.setCustomValidity('');
            }
        });
    }
});

// ฟังก์ชันบันทึกการตั้งค่า
document.getElementById('settingsForm').addEventListener('submit', function(e) {
    // ตรวจสอบค่าสำคัญ
    const maxFileSize = document.getElementById('max_file_size_global').value;
    const maxFiles = document.getElementById('max_files_per_upload').value;
    
    if (maxFileSize > 100) {
        if (!confirm('ขนาดไฟล์สูงสุดมากกว่า 100MB อาจทำให้เซิร์ฟเวอร์ช้า คุณแน่ใจหรือไม่?')) {
            e.preventDefault();
            return;
        }
    }
    
    if (maxFiles > 50) {
        if (!confirm('จำนวนไฟล์สูงสุดมากกว่า 50 ไฟล์ อาจทำให้เซิร์ฟเวอร์ช้า คุณแน่ใจหรือไม่?')) {
            e.preventDefault();
            return;
        }
    }
    
    hasChanges = false;
});

// Auto-hide alerts
setTimeout(function() {
    const alerts = document.querySelectorAll('.alert-dismissible');
    alerts.forEach(function(alert) {
        if (alert && !alert.querySelector('.btn-close').clicked) {
            setTimeout(() => {
                if (alert.parentNode) {
                    const bsAlert = new bootstrap.Alert(alert);
                    bsAlert.close();
                }
            }, 5000);
        }
    });
}, 1000);

// เพิ่มการตรวจสอบ real-time สำหรับการตั้งค่าที่เกี่ยวข้องกัน
function checkRelatedSettings() {
    const enableVersioning = document.getElementById('enable_versioning');
    const maxVersions = document.getElementById('max_versions_per_file');
    
    if (enableVersioning && maxVersions) {
        enableVersioning.addEventListener('change', function() {
            maxVersions.disabled = !this.checked;
            if (!this.checked) {
                maxVersions.value = '1';
            }
        });
        
        // เรียกใช้ครั้งแรก
        maxVersions.disabled = !enableVersioning.checked;
    }
    
    const autoBackup = document.getElementById('auto_backup_documents');
    const backupFreq = document.getElementById('backup_frequency');
    const backupRetention = document.getElementById('backup_retention_days');
    const compressBackups = document.getElementById('compress_backups');
    
    if (autoBackup) {
        autoBackup.addEventListener('change', function() {
            if (backupFreq) backupFreq.disabled = !this.checked;
            if (backupRetention) backupRetention.disabled = !this.checked;
            if (compressBackups) compressBackups.disabled = !this.checked;
        });
        
        // เรียกใช้ครั้งแรก
        const isEnabled = autoBackup.checked;
        if (backupFreq) backupFreq.disabled = !isEnabled;
        if (backupRetention) backupRetention.disabled = !isEnabled;
        if (compressBackups) compressBackups.disabled = !isEnabled;
    }
}

// เรียกใช้เมื่อโหลดหน้าเสร็จ
document.addEventListener('DOMContentLoaded', checkRelatedSettings);

// ฟังก์ชันทดสอบการตั้งค่า
function testSettings() {
    const testResults = [];
    
    // ทดสอบการอัพโหลด
    const maxFileSize = document.getElementById('max_file_size_global').value;
    const phpMaxUpload = <?php echo (int)ini_get('upload_max_filesize'); ?>;
    
    if (parseInt(maxFileSize) > phpMaxUpload) {
        testResults.push({
            type: 'warning',
            message: `ขนาดไฟล์สูงสุด (${maxFileSize}MB) เกิน PHP limit (${phpMaxUpload}MB)`
        });
    }
    
    // ทดสอบ upload path
    const uploadPath = document.getElementById('upload_path').value;
    fetch('check_path.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({path: uploadPath})
    })
    .then(response => response.json())
    .then(data => {
        if (!data.writable) {
            testResults.push({
                type: 'error',
                message: `โฟลเดอร์ ${uploadPath} ไม่สามารถเขียนได้`
            });
        }
        
        showTestResults(testResults);
    })
    .catch(error => {
        testResults.push({
            type: 'error',
            message: 'ไม่สามารถทดสอบการตั้งค่าได้'
        });
        showTestResults(testResults);
    });
}

function showTestResults(results) {
    let html = '<div class="alert alert-info"><h6>ผลการทดสอบ:</h6><ul class="mb-0">';
    
    if (results.length === 0) {
        html += '<li class="text-success">การตั้งค่าทั้งหมดถูกต้อง</li>';
    } else {
        results.forEach(result => {
            const className = result.type === 'error' ? 'text-danger' : 'text-warning';
            html += `<li class="${className}">${result.message}</li>`;
        });
    }
    
    html += '</ul></div>';
    
    // แสดงผลในพื้นที่ทดสอบ
    const testArea = document.getElementById('testResults');
    if (testArea) {
        testArea.innerHTML = html;
    } else {
        // สร้างพื้นที่ทดสอบใหม่
        const newDiv = document.createElement('div');
        newDiv.id = 'testResults';
        newDiv.innerHTML = html;
        document.querySelector('.card:last-child .card-body').appendChild(newDiv);
    }
}

// เพิ่มปุ่มทดสอบ
document.addEventListener('DOMContentLoaded', function() {
    const saveButton = document.querySelector('button[name="save_settings"]');
    if (saveButton) {
        const testButton = document.createElement('button');
        testButton.type = 'button';
        testButton.className = 'btn btn-info btn-lg ms-2';
        testButton.innerHTML = '<i class="bi bi-check-circle"></i> ทดสอบการตั้งค่า';
        testButton.onclick = testSettings;
        
        saveButton.parentNode.insertBefore(testButton, saveButton.nextSibling);
    }
});
</script>

<?php include 'includes/footer.php'; ?>

<?php
// ฟังก์ชันช่วยสำหรับตรวจสอบการตั้งค่า
function getDocumentSettings($key = null) {
    global $pdo;
    
    try {
        if ($key) {
            $stmt = $pdo->prepare("SELECT setting_value FROM document_settings WHERE setting_key = ?");
            $stmt->execute([$key]);
            return $stmt->fetchColumn();
        } else {
            $stmt = $pdo->prepare("SELECT setting_key, setting_value FROM document_settings");
            $stmt->execute();
            $settings = [];
            while ($row = $stmt->fetch()) {
                $settings[$row['setting_key']] = $row['setting_value'];
            }
            return $settings;
        }
    } catch(PDOException $e) {
        return null;
    }
}

// ฟังก์ชันตรวจสอบความปลอดภัยของไฟล์
function isFileSecure($filename, $file_content = null) {
    $dangerous_extensions = ['exe', 'bat', 'cmd', 'com', 'pif', 'scr', 'vbs', 'js', 'jar'];
    $file_extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    
    // ตรวจสอบนามสกุลไฟล์
    if (in_array($file_extension, $dangerous_extensions)) {
        return false;
    }
    
    // ตรวจสอบเนื้อหาไฟล์ (หากมี)
    if ($file_content) {
        $dangerous_patterns = [
            '<%', '<?php', '<script', 'javascript:', 'vbscript:'
        ];
        
        foreach ($dangerous_patterns as $pattern) {
            if (stripos($file_content, $pattern) !== false) {
                return false;
            }
        }
    }
    
    return true;
}

// ฟังก์ชันจัดการ path อัพโหลด
function createUploadPath($base_path) {
    $year = date('Y');
    $month = date('m');
    $upload_path = $base_path . $year . '/' . $month . '/';
    
    if (!file_exists($upload_path)) {
        mkdir($upload_path, 0755, true);
    }
    
    return $upload_path;
}

// ฟังก์ชันสร้าง thumbnail
function generateThumbnail($source_file, $destination_file, $size = 150) {
    $info = getimagesize($source_file);
    if (!$info) return false;
    
    $width = $info[0];
    $height = $info[1];
    $type = $info[2];
    
    // คำนวณขนาดใหม่
    $ratio = min($size / $width, $size / $height);
    $new_width = $width * $ratio;
    $new_height = $height * $ratio;
    
    // สร้างภาพใหม่
    $new_image = imagecreatetruecolor($new_width, $new_height);
    
    switch ($type) {
        case IMAGETYPE_JPEG:
            $source = imagecreatefromjpeg($source_file);
            break;
        case IMAGETYPE_PNG:
            $source = imagecreatefrompng($source_file);
            imagealphablending($new_image, false);
            imagesavealpha($new_image, true);
            break;
        case IMAGETYPE_GIF:
            $source = imagecreatefromgif($source_file);
            break;
        default:
            return false;
    }
    
    imagecopyresampled($new_image, $source, 0, 0, 0, 0, $new_width, $new_height, $width, $height);
    
    // บันทึกภาพ
    switch ($type) {
        case IMAGETYPE_JPEG:
            imagejpeg($new_image, $destination_file, 85);
            break;
        case IMAGETYPE_PNG:
            imagepng($new_image, $destination_file);
            break;
        case IMAGETYPE_GIF:
            imagegif($new_image, $destination_file);
            break;
    }
    
    imagedestroy($source);
    imagedestroy($new_image);
    
    return true;
}
?>