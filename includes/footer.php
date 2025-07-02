<!-- <footer class="bg-light mt-5 py-4">
        <div class="container">
            <div class="row">
                <div class="col-md-6">
                    <h5 class="text-primary">
                        <i class="bi bi-building"></i>
                        ระบบจัดการหอพัก
                    </h5>
                    <p class="text-muted">
                        ระบบจัดการหอพักครบวงจร สำหรับการจัดการห้องพัก ผู้เช่า การเงิน และรายงานต่างๆ
                    </p>
                </div>
                <div class="col-md-3">ห
                    <h6>เมนูหลัก</h6>
                    <ul class="list-unstyled">
                        <li><a href="index.php" class="text-decoration-none">หน้าแรก</a></li>
                        <li><a href="rooms.php" class="text-decoration-none">จัดการห้องพัก</a></li>
                        <li><a href="tenants.php" class="text-decoration-none">จัดการผู้เช่า</a></li>
                        <li><a href="invoices.php" class="text-decoration-none">ใบแจ้งหนี้</a></li>
                    </ul>
                </div>
                <div class="col-md-3">
                    <h6>ติดต่อเรา</h6>
                    <ul class="list-unstyled">
                        <li><i class="bi bi-telephone"></i> 02-xxx-xxxx</li>
                        <li><i class="bi bi-envelope"></i> info@dormitory.local</li>
                        <li><i class="bi bi-geo-alt"></i> กรุงเทพมหานคร</li>
                    </ul>
                </div>
            </div>
            <hr class="my-4">
            <div class="row align-items-center">
                <div class="col-md-6">
                    <p class="text-muted mb-0">
                        &copy; <?php echo date('Y'); ?> ระบบจัดการหอพัก. สงวนลิขสิทธิ์.
                    </p>
                </div>
                <div class="col-md-6 text-md-end">
                    <small class="text-muted">
                        เวอร์ชัน 1.0 | สร้างด้วย PHP & Bootstrap
                    </small>
                </div>
            </div>
        </div>
    </footer>

    <!-- Bootstrap JavaScript -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <!-- Custom JavaScript -->
    <script>
        // ฟังก์ชันสำหรับยืนยันการลบข้อมูล
        function confirmDelete(message = 'คุณต้องการลบข้อมูลนี้หรือไม่?') {
            return confirm(message);
        }
        
        // ฟังก์ชันสำหรับแสดงข้อความแจ้งเตือน
        function showAlert(message, type = 'success') {
            const alertDiv = document.createElement('div');
            alertDiv.className = `alert alert-${type} alert-dismissible fade show`;
            alertDiv.innerHTML = `
                ${message}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            `;
            
            const container = document.querySelector('.container-fluid, .container');
            if (container) {
                container.insertBefore(alertDiv, container.firstChild);
                
                // ซ่อนข้อความหลังจาก 5 วินาที
                setTimeout(() => {
                    if (alertDiv) {
                        alertDiv.remove();
                    }
                }, 5000);
            }
        }
        
        // ฟังก์ชันสำหรับฟอร์แมตตัวเลข
        function formatNumber(num) {
            return new Intl.NumberFormat('th-TH', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            }).format(num);
        }
        
        // ฟังก์ชันสำหรับฟอร์แมตเงิน
        function formatCurrency(amount) {
            return formatNumber(amount) + ' บาท';
        }
        
        // อัพเดทเวลาปัจจุบัน
        function updateDateTime() {
            const now = new Date();
            const options = {
                year: 'numeric',
                month: 'long',
                day: 'numeric',
                hour: '2-digit',
                minute: '2-digit',
                second: '2-digit',
                timeZone: 'Asia/Bangkok'
            };
            
            const dateTimeElement = document.getElementById('current-datetime');
            if (dateTimeElement) {
                dateTimeElement.textContent = now.toLocaleDateString('th-TH', options);
            }
        }
        
        // เรียกใช้ฟังก์ชันอัพเดทเวลาทุกวินาที
        setInterval(updateDateTime, 1000);
        updateDateTime(); // เรียกใช้ครั้งแรกทันที
        
        // เพิ่ม tooltip สำหรับปุ่มต่างๆ
        document.addEventListener('DOMContentLoaded', function() {
            var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
            var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
                return new bootstrap.Tooltip(tooltipTriggerEl);
            });
        });
    </script>
</body>
</html> 