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
            </ul>
            
            <ul class="navbar-nav">
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" id="userDropdown" role="button" data-bs-toggle="dropdown">
                        <i class="bi bi-person-circle"></i>
                        <?php echo isset($_SESSION['user_name']) ? $_SESSION['user_name'] : 'ผู้ใช้งาน'; ?>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><a class="dropdown-item" href="profile.php">
                            <i class="bi bi-person"></i> ข้อมูลส่วนตัว
                        </a></li>
                        <li><a class="dropdown-item" href="settings.php">
                            <i class="bi bi-gear"></i> ตั้งค่า
                        </a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="logout.php">
                            <i class="bi bi-box-arrow-right"></i> ออกจากระบบ
                        </a></li>
                    </ul>
                </li>
            </ul>
        </div>
    </div>
</nav>