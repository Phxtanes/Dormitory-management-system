<?php
require_once 'config.php';
require_once 'auth_check.php';

$doc_id = isset($_GET['doc_id']) ? intval($_GET['doc_id']) : 0;

if ($doc_id <= 0) {
    header('HTTP/1.0 404 Not Found');
    exit('Document not found');
}

try {
    // ดึงข้อมูลเอกสาร
    $stmt = $pdo->prepare("
        SELECT cd.*, c.contract_id
        FROM contract_documents cd
        JOIN contracts c ON cd.contract_id = c.contract_id
        WHERE cd.document_id = ?
    ");
    $stmt->execute([$doc_id]);
    $document = $stmt->fetch();
    
    if (!$document) {
        header('HTTP/1.0 404 Not Found');
        exit('Document not found');
    }
    
    $file_path = $document['file_path'];
    
    // ตรวจสอบว่าไฟล์มีอยู่จริง
    if (!file_exists($file_path)) {
        header('HTTP/1.0 404 Not Found');
        exit('File not found on server');
    }
    
    // กำหนด headers สำหรับดาวน์โหลด
    $filename = $document['original_filename'];
    $mime_type = $document['mime_type'];
    $file_size = filesize($file_path);
    
    // ล้าง output buffer
    if (ob_get_level()) {
        ob_end_clean();
    }
    
    // ตั้งค่า headers
    header('Content-Type: ' . $mime_type);
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . $file_size);
    header('Cache-Control: no-cache, must-revalidate');
    header('Expires: 0');
    
    // ส่งไฟล์
    readfile($file_path);
    
    // บันทึก log การดาวน์โหลด (ถ้าต้องการ)
    try {
        $log_stmt = $pdo->prepare("
            INSERT INTO download_logs (document_id, user_id, download_date, ip_address)
            VALUES (?, ?, NOW(), ?)
        ");
        $log_stmt->execute([
            $doc_id, 
            $_SESSION['user_id'], 
            $_SERVER['REMOTE_ADDR']
        ]);
    } catch(PDOException $e) {
        // ไม่ต้องหยุดการทำงานถ้า log ไม่สำเร็จ
        error_log("Download log failed: " . $e->getMessage());
    }
    
    exit;
    
} catch(PDOException $e) {
    header('HTTP/1.0 500 Internal Server Error');
    exit('Database error');
} catch(Exception $e) {
    header('HTTP/1.0 500 Internal Server Error');
    exit('Server error');
}
?>