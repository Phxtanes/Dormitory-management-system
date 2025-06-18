-- ฐานข้อมูลระบบจัดการหอพัก
CREATE DATABASE dormitory_management CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE dormitory_management;

-- ตารางห้องพัก
CREATE TABLE rooms (
    room_id INT PRIMARY KEY AUTO_INCREMENT,
    room_number VARCHAR(10) NOT NULL UNIQUE,
    room_type ENUM('single', 'double', 'triple') NOT NULL,
    monthly_rent DECIMAL(10,2) NOT NULL,
    deposit DECIMAL(10,2) NOT NULL,
    room_status ENUM('available', 'occupied', 'maintenance') DEFAULT 'available',
    floor_number INT,
    room_description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ตารางผู้เช่า
CREATE TABLE tenants (
    tenant_id INT PRIMARY KEY AUTO_INCREMENT,
    first_name VARCHAR(50) NOT NULL,
    last_name VARCHAR(50) NOT NULL,
    phone VARCHAR(15) NOT NULL,
    email VARCHAR(100),
    id_card VARCHAR(20) UNIQUE NOT NULL,
    address TEXT,
    emergency_contact VARCHAR(100),
    emergency_phone VARCHAR(15),
    tenant_status ENUM('active', 'inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ตารางสัญญาเช่า
CREATE TABLE contracts (
    contract_id INT PRIMARY KEY AUTO_INCREMENT,
    tenant_id INT,
    room_id INT,
    contract_start DATE NOT NULL,
    contract_end DATE NOT NULL,
    monthly_rent DECIMAL(10,2) NOT NULL,
    deposit_paid DECIMAL(10,2) NOT NULL,
    contract_status ENUM('active', 'expired', 'terminated') DEFAULT 'active',
    special_conditions TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(tenant_id),
    FOREIGN KEY (room_id) REFERENCES rooms(room_id)
);

-- ตารางบันทึกค่าน้ำค่าไฟ
CREATE TABLE utility_readings (
    reading_id INT PRIMARY KEY AUTO_INCREMENT,
    room_id INT,
    reading_month CHAR(7) NOT NULL, -- YYYY-MM
    water_previous DECIMAL(8,2) DEFAULT 0,
    water_current DECIMAL(8,2) NOT NULL,
    water_unit_price DECIMAL(6,2) DEFAULT 25.00,
    electric_previous DECIMAL(8,2) DEFAULT 0,
    electric_current DECIMAL(8,2) NOT NULL,
    electric_unit_price DECIMAL(6,2) DEFAULT 8.50,
    reading_date DATE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (room_id) REFERENCES rooms(room_id),
    UNIQUE KEY unique_room_month (room_id, reading_month)
);

-- ตารางใบแจ้งหนี้
CREATE TABLE invoices (
    invoice_id INT PRIMARY KEY AUTO_INCREMENT,
    contract_id INT,
    invoice_month CHAR(7) NOT NULL, -- YYYY-MM
    room_rent DECIMAL(10,2) NOT NULL,
    water_charge DECIMAL(10,2) DEFAULT 0,
    electric_charge DECIMAL(10,2) DEFAULT 0,
    other_charges DECIMAL(10,2) DEFAULT 0,
    other_charges_description TEXT,
    discount DECIMAL(10,2) DEFAULT 0,
    total_amount DECIMAL(10,2) NOT NULL,
    due_date DATE NOT NULL,
    invoice_status ENUM('pending', 'paid', 'overdue', 'cancelled') DEFAULT 'pending',
    payment_date DATE NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (contract_id) REFERENCES contracts(contract_id),
    UNIQUE KEY unique_contract_month (contract_id, invoice_month)
);

-- ตารางประวัติการชำระเงิน
CREATE TABLE payments (
    payment_id INT PRIMARY KEY AUTO_INCREMENT,
    invoice_id INT,
    payment_amount DECIMAL(10,2) NOT NULL,
    payment_method ENUM('cash', 'bank_transfer', 'mobile_banking', 'other') NOT NULL,
    payment_date DATE NOT NULL,
    payment_reference VARCHAR(100),
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (invoice_id) REFERENCES invoices(invoice_id)
);

-- ตารางการแจ้งเตือน
CREATE TABLE notifications (
    notification_id INT PRIMARY KEY AUTO_INCREMENT,
    tenant_id INT,
    notification_type ENUM('payment_due', 'payment_overdue', 'contract_expiring', 'maintenance', 'general') NOT NULL,
    title VARCHAR(200) NOT NULL,
    message TEXT NOT NULL,
    is_read BOOLEAN DEFAULT FALSE,
    send_method ENUM('system', 'email', 'sms') DEFAULT 'system',
    scheduled_date DATE,
    sent_date DATE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(tenant_id)
);

-- ตารางผู้ดูแลระบบ
CREATE TABLE users (
    user_id INT PRIMARY KEY AUTO_INCREMENT,
    username VARCHAR(50) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    email VARCHAR(100),
    user_role ENUM('admin', 'staff') DEFAULT 'staff',
    is_active BOOLEAN DEFAULT TRUE,
    last_login TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- สร้างดัชนี
CREATE INDEX idx_contracts_tenant ON contracts(tenant_id);
CREATE INDEX idx_contracts_room ON contracts(room_id);
CREATE INDEX idx_invoices_contract ON invoices(contract_id);
CREATE INDEX idx_invoices_status ON invoices(invoice_status);
CREATE INDEX idx_invoices_due_date ON invoices(due_date);
CREATE INDEX idx_payments_invoice ON payments(invoice_id);
CREATE INDEX idx_notifications_tenant ON notifications(tenant_id);
CREATE INDEX idx_utility_room_month ON utility_readings(room_id, reading_month);

-- เพิ่มข้อมูลตัวอย่าง
INSERT INTO rooms (room_number, room_type, monthly_rent, deposit, floor_number, room_description) VALUES
('101', 'single', 5000.00, 10000.00, 1, 'ห้องเดี่ยว ชั้น 1 พร้อมเครื่องปรับอากาศ'),
('102', 'double', 7000.00, 14000.00, 1, 'ห้องคู่ ชั้น 1 พร้อมเครื่องปรับอากาศ'),
('201', 'single', 5500.00, 11000.00, 2, 'ห้องเดี่ยว ชั้น 2 วิวสวย'),
('202', 'double', 7500.00, 15000.00, 2, 'ห้องคู่ ชั้น 2 วิวสวย'),
('301', 'triple', 9000.00, 18000.00, 3, 'ห้องสามเตียง ชั้น 3');

INSERT INTO users (username, password_hash, full_name, email, user_role) VALUES
('admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'ผู้ดูแลระบบ', 'admin@dormitory.local', 'admin'),
('staff01', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'เจ้าหน้าที่ 1', 'staff01@dormitory.local', 'staff');