<?php
// test_email.php - ไฟล์ทดสอบการส่งอีเมล
require_once 'config.php';
require_once 'includes/email_functions.php';

// ป้องกันการเรียกใช้จาก browser โดยไม่ต้องการ
if (!isset($_GET['test'])) {
    die('เพิ่ม ?test=1 ใน URL เพื่อทดสอบ');
}

echo "<h2>ทดสอบการส่งอีเมล</h2>";

// ข้อมูลทดสอบ
$test_email = "your-test-email@gmail.com"; // เปลี่ยนเป็นอีเมลของคุณ
$tenant_name = "ผู้เช่าทดสอบ";
$room_number = "101";
$subject = "ทดสอบการส่งอีเมลจากระบบหอพัก";
$message = "สวัสดี {tenant_name}\n\nนี่คือข้อความทดสอบจากระบบจัดการหอพัก\n\nห้องของคุณ: {room_number}\n\nระบบทำงานปกติ\n\nขอบคุณ\n{dormitory_name}";

echo "<p>กำลังส่งอีเมลทดสอบไปยัง: <strong>$test_email</strong></p>";

// ทดสอบการส่งอีเมล
$result = sendNotificationEmail(
    $test_email,
    $tenant_name,
    $room_number,
    $subject,
    $message,
    'general'
);

if ($result) {
    echo "<div style='color: green; padding: 10px; background: #d4edda; border: 1px solid #c3e6cb; border-radius: 4px;'>";
    echo "<strong>✅ สำเร็จ!</strong> ส่งอีเมลทดสอบเรียบร้อยแล้ว<br>";
    echo "กรุณาตรวจสอบกล่องจดหมายของคุณ (รวมทั้ง Spam/Junk folder)";
    echo "</div>";
} else {
    echo "<div style='color: red; padding: 10px; background: #f8d7da; border: 1px solid #f5c6cb; border-radius: 4px;'>";
    echo "<strong>❌ ล้มเหลว!</strong> ไม่สามารถส่งอีเมลได้<br>";
    echo "กรุณาตรวจสอบการตั้งค่า SMTP ในหน้า email_settings.php";
    echo "</div>";
}

echo "<hr>";
echo "<h3>ข้อมูลการตั้งค่าปัจจุบัน:</h3>";

$settings = getSystemSettings();
echo "<ul>";
echo "<li><strong>SMTP Host:</strong> " . ($settings['smtp_host'] ?? 'ไม่ได้ตั้งค่า') . "</li>";
echo "<li><strong>SMTP Port:</strong> " . ($settings['smtp_port'] ?? 'ไม่ได้ตั้งค่า') . "</li>";
echo "<li><strong>SMTP Username:</strong> " . ($settings['smtp_username'] ?? 'ไม่ได้ตั้งค่า') . "</li>";
echo "<li><strong>From Name:</strong> " . ($settings['email_from_name'] ?? 'ไม่ได้ตั้งค่า') . "</li>";
echo "<li><strong>From Email:</strong> " . ($settings['email_from_address'] ?? 'ไม่ได้ตั้งค่า') . "</li>";
echo "</ul>";

echo "<p><a href='email_settings.php'>ไปที่หน้าตั้งค่าอีเมล</a></p>";

// ตรวจสอบว่า mail() function พร้อมใช้งานหรือไม่
if (function_exists('mail')) {
    echo "<p style='color: green;'>✅ PHP mail() function พร้อมใช้งาน</p>";
} else {
    echo "<p style='color: red;'>❌ PHP mail() function ไม่พร้อมใช้งาน</p>";
}

// ตรวจสอบการตั้งค่า PHP
echo "<h3>การตั้งค่า PHP Mail:</h3>";
echo "<ul>";
echo "<li><strong>SMTP:</strong> " . ini_get('SMTP') . "</li>";
echo "<li><strong>smtp_port:</strong> " . ini_get('smtp_port') . "</li>";
echo "<li><strong>sendmail_from:</strong> " . ini_get('sendmail_from') . "</li>";
echo "</ul>";
?>