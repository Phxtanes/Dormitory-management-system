<?php
// includes/email_functions.php - ฟังก์ชันส่งอีเมลแบบไม่ใช้ PHPMailer
require_once 'config.php';

// ส่งอีเมลผ่าน Gmail SMTP (ไม่ใช้ PHPMailer)
function sendWithGmailSMTP($to_email, $subject, $html_content, $text_content, $gmail_settings) {
    try {
        $smtp_host = 'smtp.gmail.com';
        $smtp_port = 587;
        $username = $gmail_settings['smtp_username'] ?? '';
        $password = $gmail_settings['smtp_password'] ?? '';
        $from_email = $gmail_settings['email_from_address'] ?? $username;
        $from_name = $gmail_settings['email_from_name'] ?? 'หอพัก';
        
        if (empty($username) || empty($password)) {
            throw new Exception('กรุณาตั้งค่า Gmail SMTP username และ password');
        }
        
        // สร้าง socket connection
        $socket = fsockopen($smtp_host, $smtp_port, $errno, $errstr, 30);
        
        if (!$socket) {
            throw new Exception("Cannot connect to Gmail SMTP: $errstr ($errno)");
        }
        
        // อ่าน response
        $response = fgets($socket, 512);
        if (substr($response, 0, 3) != '220') {
            throw new Exception("SMTP Error: $response");
        }
        
        // EHLO
        fputs($socket, "EHLO localhost\r\n");
        $response = fgets($socket, 512);
        
        // STARTTLS
        fputs($socket, "STARTTLS\r\n");
        $response = fgets($socket, 512);
        if (substr($response, 0, 3) != '220') {
            throw new Exception("STARTTLS failed: $response");
        }
        
        // สลับเป็น TLS
        stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
        
        // EHLO อีกครั้งหลัง TLS
        fputs($socket, "EHLO localhost\r\n");
        $response = fgets($socket, 512);
        
        // LOGIN
        fputs($socket, "AUTH LOGIN\r\n");
        $response = fgets($socket, 512);
        
        fputs($socket, base64_encode($username) . "\r\n");
        $response = fgets($socket, 512);
        
        fputs($socket, base64_encode($password) . "\r\n");
        $response = fgets($socket, 512);
        if (substr($response, 0, 3) != '235') {
            throw new Exception("Authentication failed: $response");
        }
        
        // MAIL FROM
        fputs($socket, "MAIL FROM: <$from_email>\r\n");
        $response = fgets($socket, 512);
        
        // RCPT TO
        fputs($socket, "RCPT TO: <$to_email>\r\n");
        $response = fgets($socket, 512);
        
        // DATA
        fputs($socket, "DATA\r\n");
        $response = fgets($socket, 512);
        
        // Headers และ content
        $headers = "From: $from_name <$from_email>\r\n";
        $headers .= "To: $to_email\r\n";
        $headers .= "Subject: $subject\r\n";
        $headers .= "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
        $headers .= "Content-Transfer-Encoding: 8bit\r\n";
        $headers .= "\r\n";
        
        fputs($socket, $headers . $html_content . "\r\n.\r\n");
        $response = fgets($socket, 512);
        
        // QUIT
        fputs($socket, "QUIT\r\n");
        fclose($socket);
        
        return substr($response, 0, 3) == '250';
        
    } catch (Exception $e) {
        error_log("Gmail SMTP Error: " . $e->getMessage());
        return false;
    }
}

// อัพเดทฟังก์ชันหลัก sendNotificationEmail
function sendNotificationEmail($to_email, $tenant_name, $room_number, $subject, $message, $notification_type = 'general') {
    try {
        global $pdo;
        $settings = getSystemSettings();
        
        $dormitory_name = $settings['dormitory_name'] ?? 'หอพัก';
        $dormitory_email = $settings['email_from_address'] ?? $settings['dormitory_email'] ?? 'noreply@dormitory.local';
        $dormitory_phone = $settings['dormitory_phone'] ?? '';
        $dormitory_address = $settings['dormitory_address'] ?? '';
        $from_name = $settings['email_from_name'] ?? $dormitory_name;
        
        // แทนที่ตัวแปรในข้อความ
        $processed_message = str_replace([
            '{tenant_name}', '{room_number}', '{dormitory_name}',
            '{dormitory_phone}', '{dormitory_address}'
        ], [
            $tenant_name, $room_number, $dormitory_name,
            $dormitory_phone, $dormitory_address
        ], $message);
        
        $processed_subject = str_replace([
            '{tenant_name}', '{room_number}', '{dormitory_name}'
        ], [
            $tenant_name, $room_number, $dormitory_name
        ], $subject);
        
        // สร้าง HTML template
        $html_content = createEmailTemplate($processed_subject, $processed_message, $notification_type, $dormitory_name);
        
        // ลองส่งผ่าน Gmail SMTP ก่อน
        if (!empty($settings['smtp_username']) && !empty($settings['smtp_password'])) {
            $result = sendWithGmailSMTP($to_email, $processed_subject, $html_content, $processed_message, $settings);
            
            if ($result) {
                $log_message = date('Y-m-d H:i:s') . " - Email sent successfully via Gmail SMTP to: $to_email\n";
                error_log($log_message, 3, __DIR__ . '/../logs/email.log');
                return true;
            }
        }
        
        // ถ้า Gmail SMTP ไม่ได้ ลองใช้ mail() function
        return sendWithMailFunction($to_email, $processed_subject, $html_content, $processed_message, $dormitory_email, $from_name);
        
    } catch (Exception $e) {
        error_log("Email sending failed: " . $e->getMessage());
        return false;
    }
}

// ฟังก์ชันทดสอบการเชื่อมต่อ Gmail
function testGmailConnection($username, $password) {
    try {
        $socket = fsockopen('smtp.gmail.com', 587, $errno, $errstr, 10);
        
        if (!$socket) {
            return ['success' => false, 'message' => "ไม่สามารถเชื่อมต่อ Gmail SMTP: $errstr"];
        }
        
        fgets($socket, 512); // Welcome message
        
        fputs($socket, "EHLO localhost\r\n");
        fgets($socket, 512);
        
        fputs($socket, "STARTTLS\r\n");
        $response = fgets($socket, 512);
        
        if (substr($response, 0, 3) != '220') {
            fclose($socket);
            return ['success' => false, 'message' => 'ไม่สามารถเริ่ม TLS ได้'];
        }
        
        stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
        
        fputs($socket, "EHLO localhost\r\n");
        fgets($socket, 512);
        
        fputs($socket, "AUTH LOGIN\r\n");
        fgets($socket, 512);
        
        fputs($socket, base64_encode($username) . "\r\n");
        fgets($socket, 512);
        
        fputs($socket, base64_encode($password) . "\r\n");
        $response = fgets($socket, 512);
        
        fputs($socket, "QUIT\r\n");
        fclose($socket);
        
        if (substr($response, 0, 3) == '235') {
            return ['success' => true, 'message' => 'เชื่อมต่อ Gmail SMTP สำเร็จ'];
        } else {
            return ['success' => false, 'message' => 'ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง'];
        }
        
    } catch (Exception $e) {
        return ['success' => false, 'message' => 'เกิดข้อผิดพลาด: ' . $e->getMessage()];
    }
}

// ส่งอีเมลด้วย mail() function
function sendWithMailFunction($to_email, $subject, $html_content, $text_content, $from_email, $from_name) {
    try {
        // สร้าง headers สำหรับอีเมล HTML
        $headers = [
            'MIME-Version: 1.0',
            'Content-type: text/html; charset=UTF-8',
            'From: ' . $from_name . ' <' . $from_email . '>',
            'Reply-To: ' . $from_email,
            'X-Mailer: PHP/' . phpversion(),
            'X-Priority: 3',
            'Return-Path: ' . $from_email
        ];
        
        $header_string = implode("\r\n", $headers);
        
        // ส่งอีเมล
        $result = mail($to_email, $subject, $html_content, $header_string);
        
        // Log การส่งอีเมล
        $log_message = date('Y-m-d H:i:s') . " - Email " . ($result ? "sent successfully" : "failed") . " to: $to_email\n";
        error_log($log_message, 3, __DIR__ . '/../logs/email.log');
        
        return $result;
        
    } catch (Exception $e) {
        error_log("Mail function error: " . $e->getMessage());
        return false;
    }
}

// ฟังก์ชันสำหรับ SMTP (หากต้องการใช้ SMTP แต่ไม่มี PHPMailer)
function sendWithSMTP($to_email, $subject, $html_content, $text_content, $smtp_settings) {
    try {
        // ข้อมูล SMTP
        $smtp_host = $smtp_settings['smtp_host'] ?? 'localhost';
        $smtp_port = $smtp_settings['smtp_port'] ?? 25;
        $smtp_username = $smtp_settings['smtp_username'] ?? '';
        $smtp_password = $smtp_settings['smtp_password'] ?? '';
        $from_email = $smtp_settings['email_from_address'] ?? 'noreply@localhost';
        $from_name = $smtp_settings['email_from_name'] ?? 'System';
        
        // เตรียม message
        $message_body = "--boundary\r\n";
        $message_body .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $message_body .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
        $message_body .= $text_content . "\r\n";
        $message_body .= "--boundary\r\n";
        $message_body .= "Content-Type: text/html; charset=UTF-8\r\n";
        $message_body .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
        $message_body .= $html_content . "\r\n";
        $message_body .= "--boundary--\r\n";
        
        // Headers
        $headers = "From: $from_name <$from_email>\r\n";
        $headers .= "Reply-To: $from_email\r\n";
        $headers .= "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: multipart/alternative; boundary=\"boundary\"\r\n";
        
        // ใช้ mail() function พร้อม SMTP configuration
        // สำหรับ Windows XAMPP อาจต้องตั้งค่าใน php.ini
        return mail($to_email, $subject, $message_body, $headers);
        
    } catch (Exception $e) {
        error_log("SMTP error: " . $e->getMessage());
        return false;
    }
}

// สร้าง HTML template สำหรับอีเมล
function createEmailTemplate($subject, $message, $notification_type, $dormitory_name) {
    // กำหนดสีตามประเภทการแจ้งเตือน
    $colors = [
        'payment_due' => '#ffc107',
        'payment_overdue' => '#dc3545',
        'contract_expiring' => '#fd7e14',
        'maintenance' => '#6f42c1',
        'general' => '#0d6efd'
    ];
    
    $color = $colors[$notification_type] ?? '#0d6efd';
    
    $icons = [
        'payment_due' => '💰',
        'payment_overdue' => '⚠️',
        'contract_expiring' => '📅',
        'maintenance' => '🔧',
        'general' => '📢'
    ];
    
    $icon = $icons[$notification_type] ?? '📢';
    
    // ดึงการตั้งค่า template หากมี
    $settings = getSystemSettings();
    $header_template = $settings['email_template_header'] ?? '';
    $footer_template = $settings['email_template_footer'] ?? '';
    
    $html = '
    <!DOCTYPE html>
    <html lang="th">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>' . htmlspecialchars($subject) . '</title>
        <style>
            body {
                font-family: "Sarabun", "Kanit", Arial, sans-serif;
                line-height: 1.6;
                margin: 0;
                padding: 0;
                background-color: #f4f4f4;
                color: #333;
            }
            .container {
                max-width: 600px;
                margin: 0 auto;
                background-color: #ffffff;
                box-shadow: 0 0 10px rgba(0,0,0,0.1);
                border-radius: 8px;
                overflow: hidden;
            }
            .header {
                background: linear-gradient(135deg, ' . $color . ', ' . adjustBrightness($color, -20) . ');
                color: white;
                padding: 30px 20px;
                text-align: center;
            }
            .header h1 {
                margin: 0;
                font-size: 24px;
                font-weight: 600;
            }
            .icon {
                font-size: 48px;
                margin-bottom: 15px;
                display: block;
            }
            .content {
                padding: 30px;
            }
            .custom-header {
                background-color: #f8f9fa;
                border-left: 4px solid ' . $color . ';
                padding: 15px;
                margin-bottom: 20px;
                border-radius: 4px;
            }
            .message {
                background-color: #f8f9fa;
                border-left: 4px solid ' . $color . ';
                padding: 20px;
                margin: 20px 0;
                white-space: pre-line;
                border-radius: 4px;
                font-size: 16px;
            }
            .custom-footer {
                background-color: #f8f9fa;
                border-left: 4px solid ' . $color . ';
                padding: 15px;
                margin-top: 20px;
                border-radius: 4px;
            }
            .footer {
                background-color: #6c757d;
                color: white;
                padding: 20px;
                text-align: center;
                font-size: 14px;
            }
            .btn {
                display: inline-block;
                padding: 12px 24px;
                background-color: ' . $color . ';
                color: white;
                text-decoration: none;
                border-radius: 6px;
                margin: 15px 0;
                font-weight: 600;
            }
            .info-box {
                background-color: #e3f2fd;
                border: 1px solid #90caf9;
                padding: 15px;
                border-radius: 6px;
                margin: 15px 0;
            }
            @media (max-width: 600px) {
                .container {
                    margin: 0;
                    border-radius: 0;
                }
                .content {
                    padding: 20px;
                }
                .header {
                    padding: 20px;
                }
            }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="header">
                <span class="icon">' . $icon . '</span>
                <h1>' . htmlspecialchars($subject) . '</h1>
            </div>
            
            <div class="content">';
            
    // เพิ่ม Custom Header หากมี
    if (!empty($header_template)) {
        $processed_header = str_replace([
            '{dormitory_name}',
            '{tenant_name}',
            '{room_number}'
        ], [
            $dormitory_name,
            'ผู้เช่า', // placeholder
            'XXX' // placeholder
        ], $header_template);
        
        $html .= '<div class="custom-header">' . nl2br(htmlspecialchars($processed_header)) . '</div>';
    }
    
    $html .= '
                <div class="message">
                    ' . nl2br(htmlspecialchars($message)) . '
                </div>';
    
    // เพิ่ม Custom Footer หากมี
    if (!empty($footer_template)) {
        $processed_footer = str_replace([
            '{dormitory_name}',
            '{dormitory_phone}',
            '{dormitory_address}'
        ], [
            $dormitory_name,
            $settings['dormitory_phone'] ?? '',
            $settings['dormitory_address'] ?? ''
        ], $footer_template);
        
        $html .= '<div class="custom-footer">' . nl2br(htmlspecialchars($processed_footer)) . '</div>';
    }
    
    $html .= '
                <div class="info-box">
                    <strong>หมายเหตุ:</strong> หากมีข้อสงสัยกรุณาติดต่อเจ้าหน้าที่หอพัก
                </div>
            </div>
            
            <div class="footer">
                <p><strong>' . htmlspecialchars($dormitory_name) . '</strong></p>
                <p>อีเมลนี้ส่งโดยระบบอัตโนมัติ กรุณาอย่าตอบกลับ</p>
                <p>© ' . date('Y') . ' สงวนลิขสิทธิ์</p>
            </div>
        </div>
    </body>
    </html>';
    
    return $html;
}

// ฟังก์ชันปรับความสว่างของสี
function adjustBrightness($hex, $percent) {
    $hex = str_replace('#', '', $hex);
    $r = hexdec(substr($hex, 0, 2));
    $g = hexdec(substr($hex, 2, 2));
    $b = hexdec(substr($hex, 4, 2));
    
    $r = max(0, min(255, $r + ($r * $percent / 100)));
    $g = max(0, min(255, $g + ($g * $percent / 100)));
    $b = max(0, min(255, $b + ($b * $percent / 100)));
    
    return sprintf("#%02x%02x%02x", $r, $g, $b);
}

// ดึงข้อมูลการตั้งค่าระบบ
function getSystemSettings() {
    global $pdo;
    try {
        $stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings");
        $settings = [];
        while ($row = $stmt->fetch()) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }
        return $settings;
    } catch (PDOException $e) {
        return [];
    }
}

// ส่งอีเมลแจ้งเตือนการชำระเงิน
function sendPaymentReminder($invoice_id) {
    global $pdo;
    try {
        $stmt = $pdo->prepare("
            SELECT i.*, t.first_name, t.last_name, t.email, r.room_number,
                   DATE_FORMAT(i.due_date, '%d/%m/%Y') as formatted_due_date
            FROM invoices i
            JOIN contracts c ON i.contract_id = c.contract_id
            JOIN tenants t ON c.tenant_id = t.tenant_id
            JOIN rooms r ON c.room_id = r.room_id
            WHERE i.invoice_id = ? AND t.email IS NOT NULL AND t.email != ''
        ");
        $stmt->execute([$invoice_id]);
        $invoice = $stmt->fetch();
        
        if (!$invoice) {
            return false;
        }
        
        $tenant_name = $invoice['first_name'] . ' ' . $invoice['last_name'];
        $subject = "แจ้งเตือนชำระค่าเช่าห้อง " . $invoice['room_number'];
        $message = "เรียน {tenant_name}\n\n";
        $message .= "ขอแจ้งให้ทราบว่า ค่าเช่าห้อง {room_number} จำนวนเงิน " . formatCurrency($invoice['total_amount']) . "\n";
        $message .= "ครบกำหนดชำระวันที่ " . $invoice['formatted_due_date'] . "\n\n";
        $message .= "กรุณาชำระภายในกำหนด เพื่อหลีกเลี่ยงค่าปรับ\n\n";
        $message .= "ขอบคุณครับ/ค่ะ\n{dormitory_name}";
        
        $email_sent = sendNotificationEmail(
            $invoice['email'],
            $tenant_name,
            $invoice['room_number'],
            $subject,
            $message,
            'payment_due'
        );
        
        if ($email_sent) {
            // บันทึกการแจ้งเตือนในระบบ
            $stmt = $pdo->prepare("
                INSERT INTO notifications (tenant_id, notification_type, title, message, send_method, sent_date)
                SELECT c.tenant_id, 'payment_due', ?, ?, 'email', CURDATE()
                FROM contracts c WHERE c.contract_id = ?
            ");
            $stmt->execute([$subject, $message, $invoice['contract_id']]);
        }
        
        return $email_sent;
        
    } catch (PDOException $e) {
        error_log("Payment reminder error: " . $e->getMessage());
        return false;
    }
}

// ส่งอีเมลแจ้งเตือนสัญญาใกล้หมดอายุ
function sendContractExpiryReminder($contract_id, $days_before = 30) {
    global $pdo;
    try {
        $stmt = $pdo->prepare("
            SELECT c.*, t.first_name, t.last_name, t.email, r.room_number,
                   DATE_FORMAT(c.contract_end, '%d/%m/%Y') as formatted_end_date,
                   DATEDIFF(c.contract_end, CURDATE()) as days_remaining
            FROM contracts c
            JOIN tenants t ON c.tenant_id = t.tenant_id
            JOIN rooms r ON c.room_id = r.room_id
            WHERE c.contract_id = ? AND t.email IS NOT NULL AND t.email != ''
        ");
        $stmt->execute([$contract_id]);
        $contract = $stmt->fetch();
        
        if (!$contract) {
            return false;
        }
        
        $tenant_name = $contract['first_name'] . ' ' . $contract['last_name'];
        $subject = "แจ้งเตือนสัญญาเช่าใกล้หมดอายุ - ห้อง " . $contract['room_number'];
        $message = "เรียน {tenant_name}\n\n";
        $message .= "ขอแจ้งให้ทราบว่า สัญญาเช่าห้อง {room_number} ของท่าน\n";
        $message .= "จะหมดอายุในวันที่ " . $contract['formatted_end_date'] . "\n";
        $message .= "(อีก " . $contract['days_remaining'] . " วัน)\n\n";
        $message .= "หากท่านต้องการต่อสัญญา กรุณาติดต่อเจ้าหน้าที่\n\n";
        $message .= "ขอบคุณครับ/ค่ะ\n{dormitory_name}";
        
        return sendNotificationEmail(
            $contract['email'],
            $tenant_name,
            $contract['room_number'],
            $subject,
            $message,
            'contract_expiring'
        );
        
    } catch (PDOException $e) {
        error_log("Contract expiry reminder error: " . $e->getMessage());
        return false;
    }
}

// ส่งอีเมลยินดีต้อนรับผู้เช่าใหม่
function sendWelcomeEmail($tenant_id) {
    global $pdo;
    try {
        $stmt = $pdo->prepare("
            SELECT t.*, r.room_number, c.contract_start
            FROM tenants t
            JOIN contracts c ON t.tenant_id = c.tenant_id
            JOIN rooms r ON c.room_id = r.room_id
            WHERE t.tenant_id = ? AND c.contract_status = 'active'
            AND t.email IS NOT NULL AND t.email != ''
        ");
        $stmt->execute([$tenant_id]);
        $tenant = $stmt->fetch();
        
        if (!$tenant) {
            return false;
        }
        
        $tenant_name = $tenant['first_name'] . ' ' . $tenant['last_name'];
        $subject = "ยินดีต้อนรับสู่ {dormitory_name}";
        $message = "เรียน {tenant_name}\n\n";
        $message .= "ยินดีต้อนรับเข้าสู่ {dormitory_name}\n";
        $message .= "ห้องพักของท่าน: {room_number}\n";
        $message .= "วันที่เข้าพัก: " . formatDate($tenant['contract_start']) . "\n\n";
        $message .= "หากมีข้อสงสัยหรือต้องการความช่วยเหลือ กรุณาติดต่อเจ้าหน้าที่\n\n";
        $message .= "ขอบคุณที่เลือกพักกับเรา\n{dormitory_name}";
        
        return sendNotificationEmail(
            $tenant['email'],
            $tenant_name,
            $tenant['room_number'],
            $subject,
            $message,
            'general'
        );
        
    } catch (PDOException $e) {
        error_log("Welcome email error: " . $e->getMessage());
        return false;
    }
}

// ฟังก์ชันสำหรับส่งการแจ้งเตือนอัตโนมัติ (ใช้กับ cron job)
function sendAutomaticNotifications() {
    global $pdo;
    $results = [
        'payment_reminders' => 0,
        'overdue_reminders' => 0,
        'contract_expiry' => 0,
        'errors' => []
    ];
    
    // ดึงการตั้งค่า
    $settings = getSystemSettings();
    $auto_payment = ($settings['auto_payment_reminder'] ?? '0') == '1';
    $auto_overdue = ($settings['auto_overdue_reminder'] ?? '0') == '1';
    $auto_contract = ($settings['auto_contract_expiry'] ?? '0') == '1';
    
    try {
        // 1. แจ้งเตือนชำระเงิน
        if ($auto_payment) {
            $reminder_days = intval($settings['payment_reminder_days'] ?? 3);
            $stmt = $pdo->prepare("
                SELECT i.invoice_id FROM invoices i
                JOIN contracts c ON i.contract_id = c.contract_id
                JOIN tenants t ON c.tenant_id = t.tenant_id
                WHERE i.invoice_status = 'pending' 
                AND DATEDIFF(i.due_date, CURDATE()) = ?
                AND t.email IS NOT NULL AND t.email != ''
                AND NOT EXISTS (
                    SELECT 1 FROM notifications n 
                    WHERE n.tenant_id = t.tenant_id 
                    AND n.notification_type = 'payment_due'
                    AND DATE(n.created_at) = CURDATE()
                )
            ");
            $stmt->execute([$reminder_days]);
            while ($row = $stmt->fetch()) {
                if (sendPaymentReminder($row['invoice_id'])) {
                    $results['payment_reminders']++;
                }
            }
        }
        
        // 2. แจ้งเตือนเงินค้างชำระ
        if ($auto_overdue) {
            $stmt = $pdo->prepare("
                SELECT i.invoice_id FROM invoices i
                JOIN contracts c ON i.contract_id = c.contract_id
                JOIN tenants t ON c.tenant_id = t.tenant_id
                WHERE i.invoice_status = 'pending' 
                AND DATEDIFF(CURDATE(), i.due_date) = 1
                AND t.email IS NOT NULL AND t.email != ''
                AND NOT EXISTS (
                    SELECT 1 FROM notifications n 
                    WHERE n.tenant_id = t.tenant_id 
                    AND n.notification_type = 'payment_overdue'
                    AND DATE(n.created_at) = CURDATE()
                )
            ");
            $stmt->execute();
            while ($row = $stmt->fetch()) {
                if (sendPaymentReminder($row['invoice_id'])) {
                    $results['overdue_reminders']++;
                }
            }
        }
        
        // 3. แจ้งเตือนสัญญาใกล้หมดอายุ
        if ($auto_contract) {
            $expiry_days = intval($settings['contract_expiry_days'] ?? 30);
            $stmt = $pdo->prepare("
                SELECT c.contract_id FROM contracts c
                JOIN tenants t ON c.tenant_id = t.tenant_id
                WHERE c.contract_status = 'active' 
                AND DATEDIFF(c.contract_end, CURDATE()) = ?
                AND t.email IS NOT NULL AND t.email != ''
                AND NOT EXISTS (
                    SELECT 1 FROM notifications n 
                    WHERE n.tenant_id = t.tenant_id 
                    AND n.notification_type = 'contract_expiring'
                    AND DATE(n.created_at) = CURDATE()
                )
            ");
            $stmt->execute([$expiry_days]);
            while ($row = $stmt->fetch()) {
                if (sendContractExpiryReminder($row['contract_id'])) {
                    $results['contract_expiry']++;
                }
            }
        }
        
    } catch (PDOException $e) {
        $results['errors'][] = $e->getMessage();
    }
    
    return $results;
}

// สร้างโฟลเดอร์ logs หากยังไม่มี
function createLogDirectory() {
    $log_dir = __DIR__ . '/../logs';
    if (!file_exists($log_dir)) {
        mkdir($log_dir, 0755, true);
    }
}

// เรียกใช้ฟังก์ชันสร้างโฟลเดอร์ logs
createLogDirectory();

?>