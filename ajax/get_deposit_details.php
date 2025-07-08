<?php
require_once '../config.php';
require_once '../auth_check.php';

$deposit_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($deposit_id <= 0) {
    echo '<div class="alert alert-danger">ไม่พบข้อมูลเงินมัดจำ</div>';
    exit;
}

try {
    // ดึงข้อมูลเงินมัดจำพร้อมรายละเอียด
    $stmt = $pdo->prepare("
        SELECT d.*, 
               c.contract_start, c.contract_end, c.monthly_rent, c.special_conditions,
               r.room_number, r.room_type, r.floor_number,
               CONCAT(t.first_name, ' ', t.last_name) as tenant_name, 
               t.phone, t.email, t.id_card, t.address,
               creator.full_name as created_by_name,
               updater.full_name as updated_by_name
        FROM deposits d
        JOIN contracts c ON d.contract_id = c.contract_id
        JOIN rooms r ON c.room_id = r.room_id
        JOIN tenants t ON c.tenant_id = t.tenant_id
        LEFT JOIN users creator ON d.created_by = creator.user_id
        LEFT JOIN users updater ON d.updated_by = updater.user_id
        WHERE d.deposit_id = ?
    ");
    $stmt->execute([$deposit_id]);
    $deposit = $stmt->fetch();
    
    if (!$deposit) {
        echo '<div class="alert alert-danger">ไม่พบข้อมูลเงินมัดจำ</div>';
        exit;
    }
    
    // ดึงรายการค่าเสียหาย (ถ้ามี)
    $damage_stmt = $pdo->prepare("
        SELECT * FROM damage_items 
        WHERE deposit_id = ? 
        ORDER BY created_at DESC
    ");
    $damage_stmt->execute([$deposit_id]);
    $damages = $damage_stmt->fetchAll();
    
    // ดึงประวัติการเปลี่ยนแปลง (ถ้ามี audit log)
    // สำหรับการพัฒนาในอนาคต
    
    // คำนวณยอดคงเหลือ
    $balance = $deposit['deposit_amount'] - $deposit['refund_amount'] - $deposit['deduction_amount'];
    
    // กำหนดสีสถานะ
    $status_colors = [
        'pending' => 'warning',
        'received' => 'success', 
        'partial_refund' => 'info',
        'fully_refunded' => 'secondary',
        'forfeited' => 'danger'
    ];
    
    $status_labels = [
        'pending' => 'รอดำเนินการ',
        'received' => 'รับแล้ว',
        'partial_refund' => 'คืนบางส่วน',
        'fully_refunded' => 'คืนครบ',
        'forfeited' => 'ริบ'
    ];
    
    $payment_methods = [
        'cash' => 'เงินสด',
        'bank_transfer' => 'โอนธนาคาร',
        'cheque' => 'เช็ค',
        'credit_card' => 'บัตรเครดิต'
    ];
    
?>

<div class="row">
    <!-- ข้อมูลพื้นฐาน -->
    <div class="col-md-6">
        <h6 class="text-primary mb-3">
            <i class="bi bi-info-circle"></i> ข้อมูลเงินมัดจำ
        </h6>
        
        <table class="table table-sm">
            <tr>
                <td><strong>รหัสเงินมัดจำ:</strong></td>
                <td><?php echo $deposit['deposit_id']; ?></td>
            </tr>
            <tr>
                <td><strong>ห้อง:</strong></td>
                <td><?php echo htmlspecialchars($deposit['room_number']); ?></td>
            </tr>
            <tr>
                <td><strong>ผู้เช่า:</strong></td>
                <td><?php echo htmlspecialchars($deposit['tenant_name']); ?></td>
            </tr>
            <tr>
                <td><strong>เบอร์โทร:</strong></td>
                <td><?php echo htmlspecialchars($deposit['phone']); ?></td>
            </tr>
            <tr>
                <td><strong>จำนวนเงิน:</strong></td>
                <td><strong class="text-primary">฿<?php echo number_format($deposit['deposit_amount'], 2); ?></strong></td>
            </tr>
            <tr>
                <td><strong>วันที่วาง:</strong></td>
                <td><?php echo date('d/m/Y', strtotime($deposit['deposit_date'])); ?></td>
            </tr>
            <tr>
                <td><strong>วิธีชำระ:</strong></td>
                <td><?php echo $payment_methods[$deposit['payment_method']] ?? $deposit['payment_method']; ?></td>
            </tr>
            <tr>
                <td><strong>สถานะ:</strong></td>
                <td>
                    <span class="badge bg-<?php echo $status_colors[$deposit['deposit_status']]; ?>">
                        <?php echo $status_labels[$deposit['deposit_status']]; ?>
                    </span>
                </td>
            </tr>
        </table>
        
        <?php if (!empty($deposit['receipt_number'])): ?>
            <div class="mb-2">
                <small class="text-muted">เลขที่ใบเสร็จ:</small>
                <strong><?php echo htmlspecialchars($deposit['receipt_number']); ?></strong>
            </div>
        <?php endif; ?>
        
        <?php if (!empty($deposit['bank_account'])): ?>
            <div class="mb-2">
                <small class="text-muted">บัญชีธนาคาร:</small>
                <strong><?php echo htmlspecialchars($deposit['bank_account']); ?></strong>
            </div>
        <?php endif; ?>
    </div>
    
    <!-- ข้อมูลการคืนเงิน/หักเงิน -->
    <div class="col-md-6">
        <h6 class="text-primary mb-3">
            <i class="bi bi-calculator"></i> การคืนเงิน/หักเงิน
        </h6>
        
        <table class="table table-sm">
            <tr>
                <td><strong>จำนวนเงินที่คืน:</strong></td>
                <td>
                    <?php if ($deposit['refund_amount'] > 0): ?>
                        <span class="text-success">฿<?php echo number_format($deposit['refund_amount'], 2); ?></span>
                    <?php else: ?>
                        <span class="text-muted">-</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php if ($deposit['refund_amount'] > 0 && $deposit['refund_date']): ?>
            <tr>
                <td><strong>วันที่คืน:</strong></td>
                <td><?php echo date('d/m/Y', strtotime($deposit['refund_date'])); ?></td>
            </tr>
            <tr>
                <td><strong>วิธีคืน:</strong></td>
                <td><?php echo $payment_methods[$deposit['refund_method']] ?? $deposit['refund_method']; ?></td>
            </tr>
            <?php endif; ?>
            <tr>
                <td><strong>จำนวนเงินที่หัก:</strong></td>
                <td>
                    <?php if ($deposit['deduction_amount'] > 0): ?>
                        <span class="text-warning">฿<?php echo number_format($deposit['deduction_amount'], 2); ?></span>
                    <?php else: ?>
                        <span class="text-muted">-</span>
                    <?php endif; ?>
                </td>
            </tr>
            <tr class="table-info">
                <td><strong>คงเหลือ:</strong></td>
                <td>
                    <strong class="<?php echo $balance > 0 ? 'text-success' : 'text-muted'; ?>">
                        ฿<?php echo number_format($balance, 2); ?>
                    </strong>
                </td>
            </tr>
        </table>
        
        <?php if (!empty($deposit['deduction_reason'])): ?>
            <div class="alert alert-warning">
                <h6><i class="bi bi-exclamation-triangle"></i> เหตุผลการหักเงิน:</h6>
                <p class="mb-0"><?php echo nl2br(htmlspecialchars($deposit['deduction_reason'])); ?></p>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- รายการค่าเสียหาย -->
<?php if (!empty($damages)): ?>
<hr>
<h6 class="text-primary mb-3">
    <i class="bi bi-exclamation-triangle"></i> รายการค่าเสียหาย
</h6>
<div class="table-responsive">
    <table class="table table-sm table-striped">
        <thead class="table-light">
            <tr>
                <th>รายการ</th>
                <th>คำอธิบาย</th>
                <th>ค่าซ่อม</th>
                <th>ค่าเปลี่ยน</th>
                <th>จำนวนที่คิด</th>
                <th>วันที่</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($damages as $damage): ?>
            <tr>
                <td><?php echo htmlspecialchars($damage['item_name']); ?></td>
                <td><?php echo htmlspecialchars($damage['damage_description']); ?></td>
                <td>฿<?php echo number_format($damage['repair_cost'], 2); ?></td>
                <td>฿<?php echo number_format($damage['replacement_cost'], 2); ?></td>
                <td><strong>฿<?php echo number_format($damage['actual_charge'], 2); ?></strong></td>
                <td><?php echo $damage['damage_date'] ? date('d/m/Y', strtotime($damage['damage_date'])) : '-'; ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
        <tfoot class="table-info">
            <tr>
                <th colspan="4">รวมค่าเสียหาย</th>
                <th>฿<?php echo number_format(array_sum(array_column($damages, 'actual_charge')), 2); ?></th>
                <th></th>
            </tr>
        </tfoot>
    </table>
</div>
<?php endif; ?>

<!-- ข้อมูลเพิ่มเติม -->
<hr>
<div class="row">
    <div class="col-md-6">
        <h6 class="text-primary mb-3">
            <i class="bi bi-file-text"></i> ข้อมูลสัญญา
        </h6>
        <table class="table table-sm">
            <tr>
                <td><strong>รหัสสัญญา:</strong></td>
                <td>#<?php echo $deposit['contract_id']; ?></td>
            </tr>
            <tr>
                <td><strong>ระยะเวลา:</strong></td>
                <td>
                    <?php echo date('d/m/Y', strtotime($deposit['contract_start'])); ?> - 
                    <?php echo date('d/m/Y', strtotime($deposit['contract_end'])); ?>
                </td>
            </tr>
            <tr>
                <td><strong>ค่าเช่า/เดือน:</strong></td>
                <td>฿<?php echo number_format($deposit['monthly_rent'], 2); ?></td>
            </tr>
        </table>
        
        <?php if (!empty($deposit['special_conditions'])): ?>
            <div class="alert alert-info">
                <h6><i class="bi bi-info-circle"></i> เงื่อนไขพิเศษ:</h6>
                <p class="mb-0"><?php echo nl2br(htmlspecialchars($deposit['special_conditions'])); ?></p>
            </div>
        <?php endif; ?>
    </div>
    
    <div class="col-md-6">
        <h6 class="text-primary mb-3">
            <i class="bi bi-person"></i> ข้อมูลผู้เช่า
        </h6>
        <table class="table table-sm">
            <tr>
                <td><strong>เลขบัตรประชาชน:</strong></td>
                <td><?php echo htmlspecialchars($deposit['id_card']); ?></td>
            </tr>
            <tr>
                <td><strong>อีเมล:</strong></td>
                <td><?php echo htmlspecialchars($deposit['email'] ?: '-'); ?></td>
            </tr>
            <tr>
                <td><strong>ที่อยู่:</strong></td>
                <td><?php echo htmlspecialchars($deposit['address'] ?: '-'); ?></td>
            </tr>
        </table>
    </div>
</div>

<!-- หมายเหตุ -->
<?php if (!empty($deposit['notes'])): ?>
<hr>
<h6 class="text-primary mb-3">
    <i class="bi bi-chat-text"></i> หมายเหตุ
</h6>
<div class="alert alert-light">
    <?php echo nl2br(htmlspecialchars($deposit['notes'])); ?>
</div>
<?php endif; ?>

<!-- ข้อมูลระบบ -->
<hr>
<div class="row">
    <div class="col-md-6">
        <small class="text-muted">
            <strong>สร้างโดย:</strong> <?php echo htmlspecialchars($deposit['created_by_name'] ?? 'ไม่ทราบ'); ?><br>
            <strong>วันที่สร้าง:</strong> <?php echo date('d/m/Y H:i:s', strtotime($deposit['created_at'])); ?>
        </small>
    </div>
    <div class="col-md-6">
        <?php if ($deposit['updated_by']): ?>
        <small class="text-muted">
            <strong>แก้ไขล่าสุดโดย:</strong> <?php echo htmlspecialchars($deposit['updated_by_name'] ?? 'ไม่ทราบ'); ?><br>
            <strong>วันที่แก้ไข:</strong> <?php echo date('d/m/Y H:i:s', strtotime($deposit['updated_at'])); ?>
        </small>
        <?php endif; ?>
    </div>
</div>

<?php

} catch(PDOException $e) {
    echo '<div class="alert alert-danger">เกิดข้อผิดพลาดในการดึงข้อมูล: ' . htmlspecialchars($e->getMessage()) . '</div>';
}
?>