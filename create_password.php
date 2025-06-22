<?php
// ไฟล์สำหรับสร้าง hash รหัสผ่าน
// ใช้ครั้งเดียวเพื่ออัพเดทรหัสผ่านในฐานข้อมูล

require_once 'config.php';

// รหัสผ่านที่ต้องการ hash
$passwords = [
    'admin' => 'password',    // รหัสผ่านสำหรับ admin
    'staff01' => 'password'   // รหัสผ่านสำหรับ staff01
];

try {
    foreach ($passwords as $username => $password) {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        
        $stmt = $pdo->prepare("UPDATE users SET password_hash = ? WHERE username = ?");
        $stmt->execute([$hash, $username]);
        
        echo "อัพเดทรหัสผ่านสำหรับ {$username} เรียบร้อยแล้ว<br>";
        echo "Hash: {$hash}<br><br>";
    }
    
    echo "<strong>เสร็จสิ้น!</strong> สามารถลบไฟล์นี้ออกได้แล้ว";
    
} catch(PDOException $e) {
    echo "Error: " . $e->getMessage();
}
?>