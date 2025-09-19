<?php
// ตรวจสอบว่าเริ่ม session แล้วหรือยัง
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// ตรวจสอบการล็อกอิน
if (!isset($_SESSION['user_id'])) {
    // ถ้ายังไม่ล็อกอิน ให้ redirect ไปหน้า login
    $current_url = $_SERVER['REQUEST_URI'];
    $redirect_url = 'login.php?redirect=' . urlencode($current_url);
    header('Location: ' . $redirect_url);
    exit;
}

?>

<style>
    body {
        padding-top: 76px; /* เพิ่ม padding เพื่อไม่ให้เนื้อหาถูกปิดด้วย fixed navbar */
    }
    
    .navbar {
        position: fixed !important;
        top: 0;
        width: 100%;
        z-index: 1030;
    }
    
    @media (max-width: 768px) {
        body {
            padding-top: 70px;
        }
        
        .navbar-collapse {
            background-color: #6c757d;
            margin-top: 10px;
            border-radius: 8px;
            padding: 10px;
        }
    }
</style>

<nav class="navbar navbar-expand-lg navbar-dark bg-secondary">
    <div class="container-fluid">
        <a class="navbar-brand" href="index.php">
            <i class="bi bi-building"></i>
            ระบบจัดการหอพัก
        </a>
        
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
            <span class="navbar-toggler-icon"></span>
        </button>
        
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav me-auto">
                <li class="nav-item">
                    <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'index.php' ? 'active' : ''; ?>" href="index.php">
                        <i class="bi bi-house"></i> หน้าแรก
                    </a>
                </li>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" id="roomsDropdown" role="button" data-bs-toggle="dropdown">
                        <i class="bi bi-door-open"></i> จัดการห้องพัก
                    </a>
                    <ul class="dropdown-menu">
                        <li><a class="dropdown-item" href="rooms.php">รายการห้องพัก</a></li>
                        <li><a class="dropdown-item" href="add_room.php">เพิ่มห้องพัก</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="room_status.php">สถานะห้องพัก</a></li>
                    </ul>
                </li>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" id="tenantsDropdown" role="button" data-bs-toggle="dropdown">
                        <i class="bi bi-people"></i> จัดการผู้เช่า
                    </a>
                    <ul class="dropdown-menu">
                        <li><a class="dropdown-item" href="tenants.php">รายการผู้เช่า</a></li>
                        <li><a class="dropdown-item" href="add_tenant.php">เพิ่มผู้เช่า</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="contracts.php">สัญญาเช่า</a></li>
                    </ul>
                </li>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" id="billsDropdown" role="button" data-bs-toggle="dropdown">
                        <i class="bi bi-receipt"></i> ค่าใช้จ่าย
                    </a>
                    <ul class="dropdown-menu">
                        <li><a class="dropdown-item" href="utility_readings.php">บันทึกมิเตอร์</a></li>
                        <li><a class="dropdown-item" href="invoices.php">ใบแจ้งหนี้</a></li>
                        <li><a class="dropdown-item" href="payments.php">ประวัติการชำระ</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="generate_bills.php">สร้างบิล</a></li>
                    </ul>
                </li>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" id="reportsDropdown" role="button" data-bs-toggle="dropdown">
                        <i class="bi bi-graph-up"></i> รายงาน
                    </a>
                    <ul class="dropdown-menu">
                        <li><a class="dropdown-item" href="reports_income.php">รายงานรายได้</a></li>
                        <li><a class="dropdown-item" href="reports_occupancy.php">รายงานการเข้าพัก</a></li>
                        <li><a class="dropdown-item" href="reports_overdue.php">รายงานค้างชำระ</a></li>
                    </ul>
                </li>

                
                <!-- เพิ่มเมนูนี้ใน navbar.php ใต้เมนู "ค่าใช้จ่าย" -->

                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" id="depositsDropdown" role="button" data-bs-toggle="dropdown">
                        <i class="bi bi-piggy-bank"></i> เงินมัดจำ
                    </a>
                    <ul class="dropdown-menu">
                        <li><a class="dropdown-item" href="deposits.php">
                            <i class="bi bi-list-check"></i> จัดการเงินมัดจำ
                        </a></li>
                        <li><a class="dropdown-item" href="add_deposit.php">
                            <i class="bi bi-plus-circle"></i> เพิ่มเงินมัดจำ
                        </a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="deposits.php?status=pending">
                            <i class="bi bi-hourglass-split"></i> รอดำเนินการ
                        </a></li>
                        <li><a class="dropdown-item" href="deposits.php?status=received">
                            <i class="bi bi-check-circle"></i> รับแล้ว
                        </a></li>
                        <li><a class="dropdown-item" href="deposits.php?status=fully_refunded">
                            <i class="bi bi-arrow-return-left"></i> คืนแล้ว
                        </a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="deposit_reports.php">
                            <i class="bi bi-graph-up"></i> รายงานเงินมัดจำ
                        </a></li>
                    </ul>
                </li>

                <!-- เพิ่มเมนูเอกสารใต้เมนู "จัดการผู้เช่า" -->
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" id="documentsDropdown" role="button" data-bs-toggle="dropdown">
                        <i class="bi bi-file-earmark-text"></i> เอกสาร
                    </a>
                    <ul class="dropdown-menu">
                        <li><a class="dropdown-item" href="all_documents.php">
                            <i class="bi bi-files"></i> เอกสารทั้งหมด
                        </a></li>
                        <!-- <li><a class="dropdown-item" href="contracts.php">
                            <i class="bi bi-file-text"></i> สัญญาเช่า
                        </a></li> -->
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="document_types.php">
                            <i class="bi bi-tags"></i> จัดการประเภทเอกสาร
                        </a></li>
                        <li><a class="dropdown-item" href="document_settings.php">
                            <i class="bi bi-gear"></i> ตั้งค่าการอัพโหลด
                        </a></li>
                    </ul>
                </li>
                
                <?php if (is_admin()): ?>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" id="adminDropdown" role="button" data-bs-toggle="dropdown">
                        <i class="bi bi-gear"></i> จัดการระบบ
                    </a>
                    <ul class="dropdown-menu">
                        <li><a class="dropdown-item" href="users.php">จัดการผู้ใช้</a></li>
                        <li><a class="dropdown-item" href="system_settings.php">ตั้งค่าระบบ</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="backup.php">สำรองข้อมูล</a></li>
                    </ul>
                </li>
                <?php endif; ?>

                <?php if (is_admin()): ?>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" id="adminDropdown" role="button" data-bs-toggle="dropdown">
                       <i class="bi bi-bell"></i> การแจ้งเตือน
                    </a>    
                    <ul class="dropdown-menu">
                        <li><a class="dropdown-item" href="send_notifications.php">ส่งการแจ้งเตือน</a></li>
                        <li><a class="dropdown-item" href="notification_history.php">ประวัติการแจ้งเตือน</a></li>
                        <li><a class="dropdown-item" href="auto_notifications.php"><i class="bi bi-robot me-2"></i>การแจ้งเตือนอัตโนมัติ</a></li>
                        <li><a class="dropdown-item" href="email_settings.php"><i class="bi bi-gear me-2"></i>ตั้งค่าอีเมล</a></li>
                    </ul>
                </li>
                <?php endif; ?>
            </ul>

            <!-- <div class="col-md-3 mb-3">
                <a href="send_notifications.php" class="btn btn-outline-info w-100 py-3">
                    <i class="bi bi-bell fs-4 d-block mb-2"></i>
                    ส่งการแจ้งเตือน
                </a>
            </div> -->
            
            <!-- User Dropdown -->
            <ul class="navbar-nav">
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle d-flex align-items-center" href="#" id="userDropdown" role="button" data-bs-toggle="dropdown">
                        <i class="bi bi-person-circle me-2"></i>
                        <span>
                            <?php echo htmlspecialchars($_SESSION['full_name'] ?? $_SESSION['username'] ?? 'ผู้ใช้งาน'); ?>
                            <small class="d-block text-muted" style="font-size: 0.75rem;">
                                <?php echo $_SESSION['user_role'] === 'admin' ? 'ผู้ดูแลระบบ' : 'เจ้าหน้าที่'; ?>
                            </small>
                        </span>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li>
                            <div class="dropdown-item-text">
                                <div class="fw-semibold"><?php echo htmlspecialchars($_SESSION['full_name'] ?? $_SESSION['username']); ?></div>
                                <small class="text-muted">@<?php echo htmlspecialchars($_SESSION['username']); ?></small>
                            </div>
                        </li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="profile.php">
                            <i class="bi bi-person"></i> โปรไฟล์
                        </a></li>
                        <li><a class="dropdown-item" href="settings.php">
                            <i class="bi bi-gear"></i> ตั้งค่า
                        </a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item text-danger" href="logout.php" onclick="return confirm('ต้องการออกจากระบบหรือไม่?')">
                            <i class="bi bi-box-arrow-right"></i> ออกจากระบบ
                        </a></li>
                    </ul>
                </li>
            </ul>
        </div>
    </div>
</nav>

<!-- Flash Messages -->
<?php if (isset($_SESSION['success_message'])): ?>
<div class="container-fluid mt-3">
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="bi bi-check-circle me-2"></i>
        <?php 
        echo htmlspecialchars($_SESSION['success_message']); 
        unset($_SESSION['success_message']);
        ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
</div>
<?php endif; ?>

<?php if (isset($_SESSION['error_message'])): ?>
<div class="container-fluid mt-3">
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="bi bi-exclamation-triangle me-2"></i>
        <?php 
        echo htmlspecialchars($_SESSION['error_message']); 
        unset($_SESSION['error_message']);
        ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
</div>
<?php endif; ?>

<?php if (isset($_SESSION['info_message'])): ?>
<div class="container-fluid mt-3">
    <div class="alert alert-info alert-dismissible fade show" role="alert">
        <i class="bi bi-info-circle me-2"></i>
        <?php 
        echo htmlspecialchars($_SESSION['info_message']); 
        unset($_SESSION['info_message']);
        ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
</div>
<?php endif; ?>

<script>
// Auto-hide alerts after 5 seconds
document.addEventListener('DOMContentLoaded', function() {
    const alerts = document.querySelectorAll('.alert:not(.alert-permanent)');
    alerts.forEach(function(alert) {
        setTimeout(function() {
            const bsAlert = new bootstrap.Alert(alert);
            bsAlert.close();
        }, 5000);
    });
});

// Set active nav item based on current page
document.addEventListener('DOMContentLoaded', function() {
    const currentPage = window.location.pathname.split('/').pop();
    const navLinks = document.querySelectorAll('.navbar-nav .nav-link:not(.dropdown-toggle)');
    
    navLinks.forEach(function(link) {
        const href = link.getAttribute('href');
        if (href && href.includes(currentPage)) {
            link.classList.add('active');
        }
    });
});
</script>