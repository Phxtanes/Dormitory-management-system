
<?php 
/**
 * Backup System for Database and Files
 * Version: 1.0
 * Author: Auto-generated
 */

class BackupManager
{
    private $config;
    private $backupDir;
    private $logFile;

    public function __construct($config = [])
    {
        $this->config = array_merge([
            'db_host' => 'localhost',
            'db_name' => 'your_database',
            'db_user' => 'username',
            'db_pass' => 'password',
            'backup_dir' => './backups/',
            'max_backups' => 10,
            'compress' => true
        ], $config);

        $this->backupDir = rtrim($this->config['backup_dir'], '/') . '/';
        $this->logFile = $this->backupDir . 'backup.log';
        
        // สร้างโฟลเดอร์ backup หากไม่มี
        if (!is_dir($this->backupDir)) {
            mkdir($this->backupDir, 0755, true);
        }
    }

    /**
     * สำรองฐานข้อมูล MySQL
     */
    /* public function backupDatabase()
    {
        try {
            $timestamp = date('Y-m-d_H-i-s');
            $filename = "db_backup_{$timestamp}.sql";
            $filepath = $this->backupDir . $filename;

            // เชื่อมต่อฐานข้อมูล
            $pdo = new PDO(
                "mysql:host={$this->config['db_host']};dbname={$this->config['db_name']}",
                $this->config['db_user'],
                $this->config['db_pass'],
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );

            // ดึงรายการตาราง
            $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
            
            $sql = "-- Database Backup\n";
            $sql .= "-- Generated: " . date('Y-m-d H:i:s') . "\n\n";
            $sql .= "SET FOREIGN_KEY_CHECKS=0;\n\n";

            foreach ($tables as $table) {
                // ดึงโครงสร้างตาราง
                $createTable = $pdo->query("SHOW CREATE TABLE `$table`")->fetch();
                $sql .= "-- Structure for table `$table`\n";
                $sql .= "DROP TABLE IF EXISTS `$table`;\n";
                $sql .= $createTable[1] . ";\n\n";

                // ดึงข้อมูลในตาราง
                $rows = $pdo->query("SELECT * FROM `$table`")->fetchAll(PDO::FETCH_ASSOC);
                if (!empty($rows)) {
                    $sql .= "-- Data for table `$table`\n";
                    $sql .= "INSERT INTO `$table` VALUES\n";
                    
                    $values = [];
                    foreach ($rows as $row) {
                        $escaped = array_map([$pdo, 'quote'], $row);
                        $values[] = '(' . implode(',', $escaped) . ')';
                    }
                    $sql .= implode(",\n", $values) . ";\n\n";
                }
            }

            $sql .= "SET FOREIGN_KEY_CHECKS=1;\n";

            // บันทึกไฟล์
            file_put_contents($filepath, $sql);

            // บีบอัดไฟล์หากต้องการ
            if ($this->config['compress']) {
                $this->compressFile($filepath);
                unlink($filepath); // ลบไฟล์ต้นฉบับ
                $filename .= '.gz';
            }

            $this->log("Database backup completed: $filename");
            return ['success' => true, 'file' => $filename, 'size' => $this->formatBytes(filesize($this->backupDir . $filename))];

        } catch (Exception $e) {
            $this->log("Database backup failed: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    } */

    /**
     * สำรองไฟล์และโฟลเดอร์
     */
    public function backupFiles($sourcePath, $excludePaths = [])
    {
        try {
            $timestamp = date('Y-m-d_H-i-s');
            $filename = "files_backup_{$timestamp}.zip";
            $filepath = $this->backupDir . $filename;

            $zip = new ZipArchive();
            if ($zip->open($filepath, ZipArchive::CREATE) !== TRUE) {
                throw new Exception("Cannot create zip file: $filepath");
            }

            $this->addFilesToZip($zip, $sourcePath, $excludePaths);
            $zip->close();

            $this->log("Files backup completed: $filename");
            return ['success' => true, 'file' => $filename, 'size' => $this->formatBytes(filesize($filepath))];

        } catch (Exception $e) {
            $this->log("Files backup failed: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * สำรองแบบเต็ม (ฐานข้อมูล + ไฟล์)
     */
    public function fullBackup($sourcePath = './', $excludePaths = [])
    {
        $results = [];
        
        // สำรองฐานข้อมูล
        $results['database'] = $this->backupDatabase();
        
        // สำรองไฟล์
        $defaultExcludes = ['backups', 'cache', 'tmp', 'logs', '.git'];
        $excludePaths = array_merge($defaultExcludes, $excludePaths);
        $results['files'] = $this->backupFiles($sourcePath, $excludePaths);
        
        // ลบไฟล์สำรองเก่า
        $this->cleanOldBackups();
        
        return $results;
    }

    /**
     * เพิ่มไฟล์ลงใน ZIP
     */
    private function addFilesToZip($zip, $sourcePath, $excludePaths = [], $localPath = '')
    {
        $sourcePath = rtrim($sourcePath, '/') . '/';
        
        if (is_dir($sourcePath)) {
            $files = scandir($sourcePath);
            foreach ($files as $file) {
                if ($file === '.' || $file === '..') continue;
                
                $fullPath = $sourcePath . $file;
                $relativePath = $localPath . $file;
                
                // ตรวจสอบว่าอยู่ในรายการยกเว้นหรือไม่
                if ($this->shouldExclude($relativePath, $excludePaths)) continue;
                
                if (is_dir($fullPath)) {
                    $zip->addEmptyDir($relativePath);
                    $this->addFilesToZip($zip, $fullPath, $excludePaths, $relativePath . '/');
                } else {
                    $zip->addFile($fullPath, $relativePath);
                }
            }
        }
    }

    /**
     * ตรวจสอบว่าควรยกเว้นไฟล์/โฟลเดอร์นี้หรือไม่
     */
    private function shouldExclude($path, $excludePaths)
    {
        foreach ($excludePaths as $exclude) {
            if (strpos($path, $exclude) === 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * บีบอัดไฟล์ด้วย gzip
     */
    private function compressFile($filepath)
    {
        $gzFilepath = $filepath . '.gz';
        $fp = fopen($filepath, 'rb');
        $gz = gzopen($gzFilepath, 'wb9');
        
        while (!feof($fp)) {
            gzwrite($gz, fread($fp, 8192));
        }
        
        fclose($fp);
        gzclose($gz);
        
        return $gzFilepath;
    }

    /**
     * ลบไฟล์สำรองเก่า
     */
    private function cleanOldBackups()
    {
        $files = glob($this->backupDir . '*');
        $backupFiles = [];
        
        foreach ($files as $file) {
            if (is_file($file) && strpos(basename($file), 'backup_') !== false) {
                $backupFiles[] = ['file' => $file, 'time' => filemtime($file)];
            }
        }
        
        // เรียงตามเวลา (ใหม่ไปเก่า)
        usort($backupFiles, function($a, $b) {
            return $b['time'] - $a['time'];
        });
        
        // ลบไฟล์ที่เกินจำนวนที่กำหนด
        $filesToDelete = array_slice($backupFiles, $this->config['max_backups']);
        foreach ($filesToDelete as $fileData) {
            unlink($fileData['file']);
            $this->log("Deleted old backup: " . basename($fileData['file']));
        }
    }

    /**
     * บันทึก log
     */
    private function log($message)
    {
        $timestamp = date('Y-m-d H:i:s');
        $logEntry = "[$timestamp] $message\n";
        file_put_contents($this->logFile, $logEntry, FILE_APPEND);
    }

    /**
     * แปลงขนาดไฟล์
     */
    private function formatBytes($size, $precision = 2)
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $unit = 0;
        
        while ($size > 1024 && $unit < count($units) - 1) {
            $size /= 1024;
            $unit++;
        }
        
        return round($size, $precision) . ' ' . $units[$unit];
    }

    /**
     * ดึงรายการไฟล์สำรอง
     */
    public function getBackupList()
    {
        $files = glob($this->backupDir . '*backup_*');
        $backups = [];
        
        foreach ($files as $file) {
            if (is_file($file)) {
                $backups[] = [
                    'name' => basename($file),
                    'size' => $this->formatBytes(filesize($file)),
                    'date' => date('Y-m-d H:i:s', filemtime($file)),
                    'type' => strpos(basename($file), 'db_') === 0 ? 'Database' : 'Files'
                ];
            }
        }
        
        // เรียงตามเวลา (ใหม่ไปเก่า)
        usort($backups, function($a, $b) {
            return strtotime($b['date']) - strtotime($a['date']);
        });
        
        return $backups;
    }
}

// การใช้งาน
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    $config = [
        'db_host' => 'localhost',
        'db_name' => 'your_database_name',
        'db_user' => 'your_username',
        'db_pass' => 'your_password',
        'backup_dir' => './backups/',
        'max_backups' => 10,
        'compress' => true
    ];
    
    $backup = new BackupManager($config);
    
    switch ($_POST['action']) {
        case 'backup_db':
            echo json_encode($backup->backupDatabase());
            break;
            
        case 'backup_files':
            $sourcePath = $_POST['source_path'] ?? './';
            $excludePaths = $_POST['exclude_paths'] ?? [];
            echo json_encode($backup->backupFiles($sourcePath, $excludePaths));
            break;
            
        case 'full_backup':
            $sourcePath = $_POST['source_path'] ?? './';
            $excludePaths = $_POST['exclude_paths'] ?? [];
            echo json_encode($backup->fullBackup($sourcePath, $excludePaths));
            break;
            
        case 'list_backups':
            echo json_encode($backup->getBackupList());
            break;
            
        default:
            echo json_encode(['success' => false, 'error' => 'Invalid action']);
    }
    exit;
}
?>


<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Backup Management System</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background: #f5f5f5; }
        .container { max-width: 800px; margin: 0 auto; background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .btn { padding: 10px 20px; margin: 5px; border: none; border-radius: 4px; cursor: pointer; }
        .btn-primary { background: #007bff; color: white; }
        .btn-success { background: #28a745; color: white; }
        .btn-info { background: #17a2b8; color: white; }
        .btn:hover { opacity: 0.8; }
        .result { margin: 10px 0; padding: 10px; border-radius: 4px; }
        .success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .backup-list { margin-top: 20px; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { padding: 8px; text-align: left; border-bottom: 1px solid #ddd; }
        th { background-color: #f8f9fa; }
        .loading { display: none; color: #007bff; }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔄 ระบบสำรองข้อมูล</h1>
        
        <div class="backup-controls">
            <h3>สำรองข้อมูล</h3>
            <!-- <button class="btn btn-primary" onclick="backupDatabase()">สำรองฐานข้อมูล</button> -->
            <button class="btn btn-success" onclick="backupFiles()">สำรองไฟล์</button>
            <button class="btn btn-info" onclick="fullBackup()">สำรองแบบเต็ม</button>
            <span class="loading" id="loading">⏳ กำลังดำเนินการ...</span>
        </div>
        
        <div id="result"></div>
        
        <div class="backup-list">
            <h3>รายการไฟล์สำรอง <button class="btn btn-info" onclick="loadBackupList()">รีเฟรช</button></h3>
            <div id="backup-list-content">กำลังโหลด...</div>
        </div>
    </div>

    <script>
        function showLoading(show) {
            document.getElementById('loading').style.display = show ? 'inline' : 'none';
        }

        function showResult(result, isSuccess = true) {
            const div = document.getElementById('result');
            div.className = `result ${isSuccess ? 'success' : 'error'}`;
            div.innerHTML = result;
        }

        function backupDatabase() {
            showLoading(true);
            fetch('', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=backup_db'
            })
            .then(response => response.json())
            .then(data => {
                showLoading(false);
                if (data.success) {
                    showResult(`✅ สำรองฐานข้อมูลสำเร็จ!<br>ไฟล์: ${data.file}<br>ขนาด: ${data.size}`);
                    loadBackupList();
                } else {
                    showResult(`❌ เกิดข้อผิดพลาด: ${data.error}`, false);
                }
            })
            .catch(error => {
                showLoading(false);
                showResult(`❌ เกิดข้อผิดพลาด: ${error.message}`, false);
            });
        }

        function backupFiles() {
            showLoading(true);
            fetch('', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=backup_files&source_path=./'
            })
            .then(response => response.json())
            .then(data => {
                showLoading(false);
                if (data.success) {
                    showResult(`✅ สำรองไฟล์สำเร็จ!<br>ไฟล์: ${data.file}<br>ขนาด: ${data.size}`);
                    loadBackupList();
                } else {
                    showResult(`❌ เกิดข้อผิดพลาด: ${data.error}`, false);
                }
            })
            .catch(error => {
                showLoading(false);
                showResult(`❌ เกิดข้อผิดพลาด: ${error.message}`, false);
            });
        }

        function fullBackup() {
            showLoading(true);
            fetch('', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=full_backup&source_path=./'
            })
            .then(response => response.json())
            .then(data => {
                showLoading(false);
                let result = '✅ สำรองข้อมูลแบบเต็มเสร็จสิ้น!<br>';
                
                if (data.database && data.database.success) {
                    result += `📊 ฐานข้อมูล: ${data.database.file} (${data.database.size})<br>`;
                }
                if (data.files && data.files.success) {
                    result += `📁 ไฟล์: ${data.files.file} (${data.files.size})<br>`;
                }
                
                showResult(result);
                loadBackupList();
            })
            .catch(error => {
                showLoading(false);
                showResult(`❌ เกิดข้อผิดพลาด: ${error.message}`, false);
            });
        }

        function loadBackupList() {
            fetch('', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=list_backups'
            })
            .then(response => response.json())
            .then(data => {
                let html = '<table><tr><th>ชื่อไฟล์</th><th>ประเภท</th><th>ขนาด</th><th>วันที่</th></tr>';
                
                if (data.length === 0) {
                    html += '<tr><td colspan="4">ไม่มีไฟล์สำรอง</td></tr>';
                } else {
                    data.forEach(backup => {
                        html += `<tr>
                            <td>${backup.name}</td>
                            <td>${backup.type}</td>
                            <td>${backup.size}</td>
                            <td>${backup.date}</td>
                        </tr>`;
                    });
                }
                
                html += '</table>';
                document.getElementById('backup-list-content').innerHTML = html;
            })
            .catch(error => {
                document.getElementById('backup-list-content').innerHTML = 'เกิดข้อผิดพลาดในการโหลดรายการ';
            });
        }

        // โหลดรายการเมื่อเปิดหน้า
        loadBackupList();
    </script>
</body>
</html>