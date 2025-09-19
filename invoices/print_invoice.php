<?php
require_once 'config.php';

// ตรวจสอบ ID ใบแจ้งหนี้
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    die('ไม่พบใบแจ้งหนี้ที่ระบุ');
}

$invoice_id = intval($_GET['id']);

try {
    // ดึงข้อมูลใบแจ้งหนี้พร้อมข้อมูลที่เกี่ยวข้อง
    $stmt = $pdo->prepare("
        SELECT i.*, 
               c.contract_start, c.contract_end, c.monthly_rent as contract_monthly_rent,
               c.special_conditions,
               t.first_name, t.last_name, t.phone, t.email, t.id_card, t.address,
               t.emergency_contact, t.emergency_phone,
               r.room_number, r.room_type, r.floor_number, r.room_description,
               DATEDIFF(CURDATE(), i.due_date) as days_overdue,
               CONCAT('#INV-', LPAD(i.invoice_id, 6, '0')) as invoice_number,
               CONCAT('#CON-', LPAD(c.contract_id, 6, '0')) as contract_number
        FROM invoices i
        JOIN contracts c ON i.contract_id = c.contract_id
        JOIN tenants t ON c.tenant_id = t.tenant_id
        JOIN rooms r ON c.room_id = r.room_id
        WHERE i.invoice_id = ?
    ");
    $stmt->execute([$invoice_id]);
    $invoice = $stmt->fetch();
    
    if (!$invoice) {
        die('ไม่พบใบแจ้งหนี้ที่ระบุ');
    }
    
    // ดึงข้อมูลการชำระเงิน
    $stmt = $pdo->prepare("
        SELECT p.*, 
               CASE 
                   WHEN p.payment_method = 'cash' THEN 'เงินสด'
                   WHEN p.payment_method = 'bank_transfer' THEN 'โอนเงิน'
                   WHEN p.payment_method = 'mobile_banking' THEN 'Mobile Banking'
                   WHEN p.payment_method = 'other' THEN 'อื่นๆ'
                   ELSE p.payment_method
               END as payment_method_text
        FROM payments p
        WHERE p.invoice_id = ?
        ORDER BY p.payment_date DESC, p.created_at DESC
    ");
    $stmt->execute([$invoice_id]);
    $payments = $stmt->fetchAll();
    
    // คำนวณยอดที่ชำระแล้ว
    $total_paid = array_sum(array_column($payments, 'payment_amount'));
    $remaining_amount = $invoice['total_amount'] - $total_paid;
    
} catch(PDOException $e) {
    die('เกิดข้อผิดพลาดในการดึงข้อมูล: ' . $e->getMessage());
}

// ตรวจสอบว่าต้องการ PDF หรือไม่
$output_pdf = isset($_GET['pdf']) && $_GET['pdf'] == '1';

if ($output_pdf) {
    // สำหรับ PDF - ต้องติดตั้ง TCPDF
    require_once 'vendor/tcpdf/tcpdf.php';
    
    // สร้าง PDF
    $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
    
    // ตั้งค่า PDF
    $pdf->SetCreator('ระบบจัดการหอพัก');
    $pdf->SetAuthor('ระบบจัดการหอพัก');
    $pdf->SetTitle('ใบแจ้งหนี้ ' . $invoice['invoice_number']);
    $pdf->SetSubject('ใบแจ้งหนี้');
    
    // ตั้งค่าฟอนต์ไทย
    $pdf->SetFont('thsarabunnew', '', 16);
    
    // เพิ่มหน้า
    $pdf->AddPage();
    
    // สร้างเนื้อหา HTML สำหรับ PDF
    $html = generateInvoiceHTML($invoice, $payments, $total_paid, $remaining_amount, true);
    
    // เขียน HTML ลง PDF
    $pdf->writeHTML($html, true, false, true, false, '');
    
    // ส่งออก PDF
    $pdf->Output('invoice_' . $invoice['invoice_number'] . '.pdf', 'D');
    
} else {
    // สำหรับการพิมพ์แบบ HTML
    $html = generateInvoiceHTML($invoice, $payments, $total_paid, $remaining_amount, false);
    echo $html;
}

function generateInvoiceHTML($invoice, $payments, $total_paid, $remaining_amount, $is_pdf = false) {
    ob_start();
    
    // กำหนดสถานะ
    $status_info = [
        'pending' => ['text' => 'รอชำระ', 'color' => '#ffc107'],
        'paid' => ['text' => 'ชำระแล้ว', 'color' => '#28a745'],
        'overdue' => ['text' => 'เกินกำหนด', 'color' => '#dc3545'],
        'cancelled' => ['text' => 'ยกเลิก', 'color' => '#6c757d']
    ];
    
    $current_status = $invoice['invoice_status'];
    if ($current_status == 'pending' && $invoice['days_overdue'] > 0) {
        $current_status = 'overdue';
    }
    
    ?>
    <!DOCTYPE html>
    <html lang="th">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>ใบแจ้งหนี้ <?php echo $invoice['invoice_number']; ?></title>
        <style>
            @import url('https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700&display=swap');
            
            * {
                margin: 0;
                padding: 0;
                box-sizing: border-box;
            }
            
            body {
                font-family: 'Sarabun', 'TH SarabunPSK', sans-serif;
                font-size: 14px;
                line-height: 1.6;
                color: #333;
                background: #fff;
                <?php if (!$is_pdf): ?>
                padding: 20px;
                <?php endif; ?>
            }
            
            .invoice-container {
                max-width: 800px;
                margin: 0 auto;
                background: #fff;
                <?php if (!$is_pdf): ?>
                box-shadow: 0 0 20px rgba(0,0,0,0.1);
                <?php endif; ?>
                border: 2px solid #dee2e6;
            }
            
            .invoice-header {
                background: linear-gradient(135deg, #007bff, #0056b3);
                color: white;
                padding: 30px;
                text-align: center;
                position: relative;
            }
            
            .invoice-header::after {
                content: '';
                position: absolute;
                bottom: -10px;
                left: 50%;
                transform: translateX(-50%);
                width: 0;
                height: 0;
                border-left: 20px solid transparent;
                border-right: 20px solid transparent;
                border-top: 10px solid #0056b3;
            }
            
            .company-name {
                font-size: 28px;
                font-weight: bold;
                margin-bottom: 5px;
                text-shadow: 2px 2px 4px rgba(0,0,0,0.3);
            }
            
            .company-subtitle {
                font-size: 16px;
                opacity: 0.9;
            }
            
            .invoice-info {
                display: flex;
                justify-content: space-between;
                padding: 30px;
                background: #f8f9fa;
                border-bottom: 3px solid #007bff;
            }
            
            .invoice-number {
                font-size: 24px;
                font-weight: bold;
                color: #007bff;
                margin-bottom: 10px;
            }
            
            .invoice-status {
                display: inline-block;
                padding: 8px 16px;
                border-radius: 20px;
                font-weight: bold;
                font-size: 14px;
                text-transform: uppercase;
                background: <?php echo $status_info[$current_status]['color']; ?>;
                color: white;
                box-shadow: 0 2px 4px rgba(0,0,0,0.2);
            }
            
            .invoice-details {
                padding: 30px;
            }
            
            .section-title {
                font-size: 18px;
                font-weight: bold;
                color: #007bff;
                margin-bottom: 15px;
                padding-bottom: 8px;
                border-bottom: 2px solid #007bff;
                position: relative;
            }
            
            .section-title::after {
                content: '';
                position: absolute;
                bottom: -2px;
                left: 0;
                width: 50px;
                height: 2px;
                background: #28a745;
            }
            
            .info-grid {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 30px;
                margin-bottom: 30px;
            }
            
            .info-item {
                margin-bottom: 12px;
            }
            
            .info-label {
                color: #666;
                font-size: 12px;
                text-transform: uppercase;
                letter-spacing: 0.5px;
                margin-bottom: 4px;
            }
            
            .info-value {
                font-weight: 600;
                font-size: 15px;
                color: #333;
            }
            
            .charges-table {
                width: 100%;
                border-collapse: collapse;
                margin: 20px 0;
                background: #fff;
                box-shadow: 0 2px 8px rgba(0,0,0,0.1);
                border-radius: 8px;
                overflow: hidden;
            }
            
            .charges-table th {
                background: linear-gradient(135deg, #007bff, #0056b3);
                color: white;
                padding: 15px;
                text-align: left;
                font-weight: bold;
                text-transform: uppercase;
                letter-spacing: 0.5px;
                font-size: 13px;
            }
            
            .charges-table td {
                padding: 15px;
                border-bottom: 1px solid #eee;
            }
            
            .charges-table tr:nth-child(even) {
                background: #f8f9fa;
            }
            
            .charges-table tr:hover {
                background: #e3f2fd;
                transition: background 0.3s ease;
            }
            
            .amount-cell {
                text-align: right;
                font-weight: bold;
                font-family: 'Courier New', monospace;
            }
            
            .total-row {
                background: linear-gradient(135deg, #28a745, #1e7e34) !important;
                color: white !important;
                font-weight: bold;
                font-size: 16px;
            }
            
            .total-row td {
                border: none !important;
                padding: 20px 15px;
            }
            
            .discount-row {
                background: linear-gradient(135deg, #28a745, #20c997) !important;
                color: white !important;
            }
            
            .payment-summary {
                background: linear-gradient(135deg, #17a2b8, #138496);
                color: white;
                padding: 25px;
                border-radius: 8px;
                margin: 20px 0;
                text-align: center;
            }
            
            .payment-amount {
                font-size: 28px;
                font-weight: bold;
                margin: 10px 0;
                text-shadow: 2px 2px 4px rgba(0,0,0,0.3);
            }
            
            .payment-history {
                margin-top: 30px;
            }
            
            .payment-item {
                background: #f8f9fa;
                border: 1px solid #dee2e6;
                border-radius: 8px;
                padding: 15px;
                margin-bottom: 10px;
                display: flex;
                justify-content: space-between;
                align-items: center;
            }
            
            .payment-date {
                font-weight: bold;
                color: #007bff;
            }
            
            .payment-amount-small {
                font-weight: bold;
                color: #28a745;
                font-family: 'Courier New', monospace;
            }
            
            .footer {
                background: #343a40;
                color: white;
                padding: 20px;
                text-align: center;
                font-size: 12px;
            }
            
            .watermark {
                position: absolute;
                top: 50%;
                left: 50%;
                transform: translate(-50%, -50%) rotate(-45deg);
                font-size: 80px;
                color: rgba(220, 53, 69, 0.1);
                font-weight: bold;
                z-index: -1;
                pointer-events: none;
            }
            
            <?php if (!$is_pdf): ?>
            @media print {
                body {
                    padding: 0;
                }
                
                .invoice-container {
                    box-shadow: none;
                    max-width: none;
                }
                
                .no-print {
                    display: none !important;
                }
            }
            
            @media (max-width: 768px) {
                .info-grid {
                    grid-template-columns: 1fr;
                    gap: 20px;
                }
                
                .invoice-info {
                    flex-direction: column;
                    gap: 20px;
                }
                
                .charges-table {
                    font-size: 12px;
                }
                
                .charges-table th,
                .charges-table td {
                    padding: 10px 8px;
                }
            }
            <?php endif; ?>
        </style>
    </head>
    <body>
        <div class="invoice-container">
            <!-- Header -->
            <div class="invoice-header">
                <div class="company-name">ระบบจัดการหอพัก</div>
                <div class="company-subtitle">Dormitory Management System</div>
            </div>
            
            <!-- Invoice Info -->
            <div class="invoice-info">
                <div>
                    <div class="invoice-number"><?php echo $invoice['invoice_number']; ?></div>
                    <div class="invoice-status"><?php echo $status_info[$current_status]['text']; ?></div>
                </div>
                <div style="text-align: right;">
                    <div class="info-item">
                        <div class="info-label">วันที่สร้าง</div>
                        <div class="info-value"><?php echo formatDate($invoice['created_at']); ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">กำหนดชำระ</div>
                        <div class="info-value" style="<?php echo $invoice['days_overdue'] > 0 ? 'color: #dc3545;' : ''; ?>">
                            <?php echo formatDate($invoice['due_date']); ?>
                            <?php if ($invoice['days_overdue'] > 0): ?>
                                <br><small style="color: #dc3545;">(เกิน <?php echo $invoice['days_overdue']; ?> วัน)</small>
                            <?php elseif ($invoice['days_overdue'] < 0): ?>
                                <br><small style="color: #ffc107;">(เหลือ <?php echo abs($invoice['days_overdue']); ?> วัน)</small>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Invoice Details -->
            <div class="invoice-details">
                <!-- ข้อมูลผู้เช่าและห้อง -->
                <div class="section-title">ข้อมูลผู้เช่าและห้องพัก</div>
                <div class="info-grid">
                    <div>
                        <div class="info-item">
                            <div class="info-label">ผู้เช่า</div>
                            <div class="info-value"><?php echo $invoice['first_name'] . ' ' . $invoice['last_name']; ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">หมายเลขโทรศัพท์</div>
                            <div class="info-value"><?php echo $invoice['phone']; ?></div>
                        </div>
                        <?php if ($invoice['email']): ?>
                        <div class="info-item">
                            <div class="info-label">อีเมล</div>
                            <div class="info-value"><?php echo $invoice['email']; ?></div>
                        </div>
                        <?php endif; ?>
                        <div class="info-item">
                            <div class="info-label">เลขบัตรประชาชน</div>
                            <div class="info-value"><?php echo $invoice['id_card']; ?></div>
                        </div>
                    </div>
                    <div>
                        <div class="info-item">
                            <div class="info-label">ห้องพัก</div>
                            <div class="info-value">ห้อง <?php echo $invoice['room_number']; ?> (ชั้น <?php echo $invoice['floor_number']; ?>)</div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">ประเภทห้อง</div>
                            <div class="info-value">
                                <?php
                                $room_types = ['single' => 'เดี่ยว', 'double' => 'คู่', 'triple' => 'สาม'];
                                echo $room_types[$invoice['room_type']] ?? $invoice['room_type'];
                                ?>
                            </div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">สัญญาเลขที่</div>
                            <div class="info-value"><?php echo $invoice['contract_number']; ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">เดือนที่เรียกเก็บ</div>
                            <div class="info-value">
                                <?php 
                                $month_year = explode('-', $invoice['invoice_month']);
                                echo thaiMonth($month_year[1]) . ' ' . ($month_year[0] + 543);
                                ?>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- รายการค่าใช้จ่าย -->
                <div class="section-title">รายการค่าใช้จ่าย</div>
                <table class="charges-table">
                    <thead>
                        <tr>
                            <th style="width: 60%;">รายการ</th>
                            <th style="width: 40%; text-align: right;">จำนวนเงิน (บาท)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>
                                <strong>ค่าเช่าห้อง</strong>
                                <div style="font-size: 12px; color: #666; margin-top: 5px;">
                                    เดือน <?php 
                                    $month_year = explode('-', $invoice['invoice_month']);
                                    echo thaiMonth($month_year[1]) . ' ' . ($month_year[0] + 543);
                                    ?>
                                </div>
                            </td>
                            <td class="amount-cell"><?php echo number_format($invoice['room_rent'], 2); ?></td>
                        </tr>
                        <?php if ($invoice['water_charge'] > 0): ?>
                        <tr>
                            <td>ค่าน้ำ</td>
                            <td class="amount-cell"><?php echo number_format($invoice['water_charge'], 2); ?></td>
                        </tr>
                        <?php endif; ?>
                        <?php if ($invoice['electric_charge'] > 0): ?>
                        <tr>
                            <td>ค่าไฟ</td>
                            <td class="amount-cell"><?php echo number_format($invoice['electric_charge'], 2); ?></td>
                        </tr>
                        <?php endif; ?>
                        <?php if ($invoice['other_charges'] > 0): ?>
                        <tr>
                            <td>
                                ค่าใช้จ่ายอื่นๆ
                                <?php if ($invoice['other_charges_description']): ?>
                                <div style="font-size: 12px; color: #666; margin-top: 5px;">
                                    <?php echo htmlspecialchars($invoice['other_charges_description']); ?>
                                </div>
                                <?php endif; ?>
                            </td>
                            <td class="amount-cell"><?php echo number_format($invoice['other_charges'], 2); ?></td>
                        </tr>
                        <?php endif; ?>
                        <?php if ($invoice['discount'] > 0): ?>
                        <tr class="discount-row">
                            <td><strong>ส่วนลด</strong></td>
                            <td class="amount-cell"><strong>-<?php echo number_format($invoice['discount'], 2); ?></strong></td>
                        </tr>
                        <?php endif; ?>
                        <tr class="total-row">
                            <td><strong>ยอดรวมทั้งสิ้น</strong></td>
                            <td class="amount-cell"><strong><?php echo number_format($invoice['total_amount'], 2); ?></strong></td>
                        </tr>
                    </tbody>
                </table>
                
                <!-- สรุปการชำระเงิน -->
                <?php if ($total_paid > 0 || $invoice['invoice_status'] == 'paid'): ?>
                <div class="payment-summary">
                    <div style="font-size: 18px; margin-bottom: 15px;">สรุปการชำระเงิน</div>
                    <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 20px; text-align: center;">
                        <div>
                            <div style="font-size: 14px; opacity: 0.9;">ยอดรวม</div>
                            <div class="payment-amount"><?php echo number_format($invoice['total_amount'], 2); ?></div>
                        </div>
                        <div>
                            <div style="font-size: 14px; opacity: 0.9;">ชำระแล้ว</div>
                            <div class="payment-amount" style="color: #90ff90;"><?php echo number_format($total_paid, 2); ?></div>
                        </div>
                        <div>
                            <div style="font-size: 14px; opacity: 0.9;">คงเหลือ</div>
                            <div class="payment-amount" style="color: <?php echo $remaining_amount > 0 ? '#ffb3b3' : '#90ff90'; ?>">
                                <?php echo number_format($remaining_amount, 2); ?>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- ประวัติการชำระเงิน -->
                <?php if (!empty($payments)): ?>
                <div class="payment-history">
                    <div class="section-title">ประวัติการชำระเงิน</div>
                    <?php foreach ($payments as $payment): ?>
                    <div class="payment-item">
                        <div>
                            <div class="payment-date"><?php echo formatDate($payment['payment_date']); ?></div>
                            <div style="font-size: 12px; color: #666;">
                                <?php echo $payment['payment_method_text']; ?>
                                <?php if ($payment['payment_reference']): ?>
                                    - อ้างอิง: <?php echo htmlspecialchars($payment['payment_reference']); ?>
                                <?php endif; ?>
                            </div>
                            <?php if ($payment['notes']): ?>
                            <div style="font-size: 12px; color: #666; margin-top: 5px;">
                                หมายเหตุ: <?php echo htmlspecialchars($payment['notes']); ?>
                            </div>
                            <?php endif; ?>
                        </div>
                        <div class="payment-amount-small">
                            <?php echo number_format($payment['payment_amount'], 2); ?> บาท
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
                
                <!-- ข้อมูลผู้ติดต่อฉุกเฉิน -->
                <?php if ($invoice['emergency_contact']): ?>
                <div style="margin-top: 30px; padding: 20px; background: #f8f9fa; border-radius: 8px; border-left: 4px solid #dc3545;">
                    <div class="section-title" style="border: none; margin-bottom: 10px; color: #dc3545;">ผู้ติดต่อฉุกเฉิน</div>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                        <div>
                            <div class="info-label">ชื่อผู้ติดต่อ</div>
                            <div class="info-value"><?php echo $invoice['emergency_contact']; ?></div>
                        </div>
                        <?php if ($invoice['emergency_phone']): ?>
                        <div>
                            <div class="info-label">หมายเลขโทรศัพท์</div>
                            <div class="info-value"><?php echo $invoice['emergency_phone']; ?></div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            
            <!-- Footer -->
            <div class="footer">
                <div style="margin-bottom: 10px;">
                    <strong>ระบบจัดการหอพัก</strong> | Dormitory Management System
                </div>
                <div style="font-size: 11px; opacity: 0.8;">
                    พิมพ์เมื่อ: <?php echo date('d/m/Y H:i:s'); ?> | 
                    ใบแจ้งหนี้เลขที่: <?php echo $invoice['invoice_number']; ?>
                </div>
            </div>
            
            <!-- Watermark สำหรับสถานะ -->
            <?php if ($current_status == 'paid'): ?>
            <div class="watermark" style="color: rgba(40, 167, 69, 0.1);">ชำระแล้ว</div>
            <?php elseif ($current_status == 'overdue'): ?>
            <div class="watermark" style="color: rgba(220, 53, 69, 0.1);">เกินกำหนด</div>
            <?php elseif ($current_status == 'cancelled'): ?>
            <div class="watermark" style="color: rgba(108, 117, 125, 0.1);">ยกเลิก</div>
            <?php endif; ?>
        </div>
        
        <?php if (!$is_pdf): ?>
        <!-- ปุ่มควบคุม -->
        <div class="no-print" style="text-align: center; margin: 20px 0;">
            <button onclick="window.print()" style="background: #007bff; color: white; border: none; padding: 12px 24px; border-radius: 5px; font-size: 16px; cursor: pointer; margin-right: 10px;">
                🖨️ พิมพ์ใบแจ้งหนี้
            </button>
            <a href="print_invoice.php?id=<?php echo $invoice['invoice_id']; ?>&pdf=1" 
               style="background: #dc3545; color: white; text-decoration: none; padding: 12px 24px; border-radius: 5px; font-size: 16px; margin-right: 10px;">
                📄 ดาวน์โหลด PDF
            </a>
            <a href="view_invoice.php?id=<?php echo $invoice['invoice_id']; ?>" 
               style="background: #6c757d; color: white; text-decoration: none; padding: 12px 24px; border-radius: 5px; font-size: 16px;">
                ← กลับไปดูใบแจ้งหนี้
            </a>
        </div>
        
        <script>
        // Auto print when page loads if print parameter is set
        if (window.location.search.includes('print=1')) {
            window.onload = function() {
                setTimeout(function() {
                    window.print();
                }, 500);
            };
        }
        
        // Print function
        function printInvoice() {
            window.print();
        }
        
        // Download PDF function
        function downloadPDF() {
            window.location.href = 'print_invoice.php?id=<?php echo $invoice['invoice_id']; ?>&pdf=1';
        }
        </script>
        <?php endif; ?>
    </body>
    </html>
    <?php
    
    return ob_get_clean();
}

// Helper function สำหรับแปลงตัวเลขเป็นคำไทย (สำหรับจำนวนเงิน)
function numberToThaiText($number) {
    $txtnum = array('', 'หนึ่ง', 'สอง', 'สาม', 'สี่', 'ห้า', 'หก', 'เจ็ด', 'แปด', 'เก้า');
    $txtdig = array('', 'สิบ', 'ร้อย', 'พัน', 'หมื่น', 'แสน', 'ล้าน');
    
    $number = number_format($number, 2, '.', '');
    list($integer, $fraction) = explode('.', $number);
    
    $baht = convertNumber($integer, $txtnum, $txtdig);
    $satang = convertNumber($fraction, $txtnum, $txtdig);
    
    $result = $baht . 'บาท';
    if ($fraction != '00') {
        $result .= $satang . 'สตางค์';
    } else {
        $result .= 'ถ้วน';
    }
    
    return $result;
}

function convertNumber($number, $txtnum, $txtdig) {
    $result = '';
    $number = str_pad($number, 7, '0', STR_PAD_LEFT);
    $len = strlen($number);
    
    for ($i = 0; $i < $len; $i++) {
        $digit = $number[$i];
        $position = $len - $i;
        
        if ($digit != '0') {
            if ($position == 2 && $digit == '1') {
                $result .= 'สิบ';
            } elseif ($position == 2 && $digit == '2') {
                $result .= 'ยี่สิบ';
            } elseif ($position == 1 && $digit == '1' && $i > 0) {
                $result .= 'เอ็ด';
            } else {
                $result .= $txtnum[$digit];
                if ($position > 1) {
                    $result .= $txtdig[$position - 1];
                }
            }
        }
    }
    
    return $result;
}
?>