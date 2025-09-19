-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Jul 09, 2025 at 05:39 AM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.0.30

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `dormitory_management`
--

DELIMITER $$
--
-- Procedures
--
CREATE DEFINER=`root`@`localhost` PROCEDURE `CleanupOldLogs` (IN `days_to_keep` INT)   BEGIN
    DELETE FROM download_logs 
    WHERE download_date < DATE_SUB(CURDATE(), INTERVAL days_to_keep DAY);
    
    DELETE FROM deposit_audit_log 
    WHERE changed_at < DATE_SUB(CURDATE(), INTERVAL days_to_keep DAY);
    
    SELECT ROW_COUNT() as deleted_rows;
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `GetDepositsReadyForRefund` ()   BEGIN
    SELECT d.*, 
           CONCAT(t.first_name, ' ', t.last_name) as tenant_name,
           r.room_number,
           c.contract_end,
           DATEDIFF(CURDATE(), c.contract_end) as days_since_expiry
    FROM deposits d
    JOIN contracts c ON d.contract_id = c.contract_id
    JOIN rooms r ON c.room_id = r.room_id
    JOIN tenants t ON c.tenant_id = t.tenant_id
    WHERE d.deposit_status = 'received'
    AND c.contract_status IN ('expired', 'terminated')
    AND c.contract_end <= CURDATE()
    ORDER BY c.contract_end ASC;
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `GetMonthlyDepositStats` (IN `target_month` VARCHAR(7))   BEGIN
    SELECT 
        COUNT(*) as deposits_count,
        SUM(deposit_amount) as total_deposits,
        AVG(deposit_amount) as avg_deposit,
        SUM(refund_amount) as total_refunds,
        SUM(deduction_amount) as total_deductions,
        COUNT(CASE WHEN deposit_status = 'pending' THEN 1 END) as pending_count,
        COUNT(CASE WHEN deposit_status = 'received' THEN 1 END) as received_count
    FROM deposits 
    WHERE DATE_FORMAT(deposit_date, '%Y-%m') = target_month;
END$$

--
-- Functions
--
CREATE DEFINER=`root`@`localhost` FUNCTION `CalculateDepositBalance` (`deposit_amount` DECIMAL(10,2), `refund_amount` DECIMAL(10,2), `deduction_amount` DECIMAL(10,2)) RETURNS DECIMAL(10,2) DETERMINISTIC READS SQL DATA BEGIN
    RETURN deposit_amount - IFNULL(refund_amount, 0) - IFNULL(deduction_amount, 0);
END$$

DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `contracts`
--

CREATE TABLE `contracts` (
  `contract_id` int(11) NOT NULL,
  `tenant_id` int(11) DEFAULT NULL,
  `room_id` int(11) DEFAULT NULL,
  `contract_start` date NOT NULL,
  `contract_end` date NOT NULL,
  `monthly_rent` decimal(10,2) NOT NULL,
  `deposit_paid` decimal(10,2) NOT NULL,
  `deposit_status` enum('pending','partial','full') DEFAULT 'pending',
  `deposit_due_date` date DEFAULT NULL,
  `contract_status` enum('active','expired','terminated') DEFAULT 'active',
  `special_conditions` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `contracts`
--

INSERT INTO `contracts` (`contract_id`, `tenant_id`, `room_id`, `contract_start`, `contract_end`, `monthly_rent`, `deposit_paid`, `deposit_status`, `deposit_due_date`, `contract_status`, `special_conditions`, `created_at`) VALUES
(1, 3, 1, '2025-06-19', '2026-07-30', 5000.00, 10000.00, 'pending', NULL, 'active', 'ชำระเงินทันทีหลังทำสัญญา', '2025-06-19 06:51:20'),
(2, 4, 3, '2025-07-07', '2026-07-07', 5500.00, 11000.00, 'full', NULL, 'active', NULL, '2025-07-07 06:57:02'),
(3, 5, 7, '2025-07-07', '2026-07-07', 8000.00, 16000.00, 'pending', NULL, 'active', NULL, '2025-07-07 07:37:38');

-- --------------------------------------------------------

--
-- Table structure for table `contract_documents`
--

CREATE TABLE `contract_documents` (
  `document_id` int(11) NOT NULL,
  `contract_id` int(11) NOT NULL,
  `document_type` enum('contract','id_card','income_proof','guarantor_doc','other') NOT NULL,
  `original_filename` varchar(255) NOT NULL,
  `stored_filename` varchar(255) NOT NULL,
  `file_path` varchar(500) NOT NULL,
  `file_size` int(11) NOT NULL,
  `mime_type` varchar(100) NOT NULL,
  `upload_date` timestamp NOT NULL DEFAULT current_timestamp(),
  `uploaded_by` int(11) NOT NULL,
  `description` text DEFAULT NULL
) ;

-- --------------------------------------------------------

--
-- Table structure for table `damage_items`
--

CREATE TABLE `damage_items` (
  `damage_id` int(11) NOT NULL,
  `deposit_id` int(11) NOT NULL,
  `item_name` varchar(100) NOT NULL,
  `damage_description` text NOT NULL,
  `repair_cost` decimal(10,2) NOT NULL,
  `replacement_cost` decimal(10,2) DEFAULT 0.00,
  `actual_charge` decimal(10,2) NOT NULL,
  `damage_date` date DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `deposits`
--

CREATE TABLE `deposits` (
  `deposit_id` int(11) NOT NULL,
  `contract_id` int(11) NOT NULL,
  `deposit_amount` decimal(10,2) NOT NULL,
  `deposit_date` date NOT NULL,
  `payment_method` enum('cash','bank_transfer','cheque','credit_card') DEFAULT 'cash',
  `bank_account` varchar(50) DEFAULT NULL,
  `receipt_number` varchar(50) DEFAULT NULL,
  `refund_amount` decimal(10,2) DEFAULT 0.00,
  `refund_date` date DEFAULT NULL,
  `refund_method` enum('cash','bank_transfer','cheque') DEFAULT NULL,
  `deduction_amount` decimal(10,2) DEFAULT 0.00,
  `deduction_reason` text DEFAULT NULL,
  `deduction_details` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`deduction_details`)),
  `deposit_status` enum('pending','received','partial_refund','fully_refunded','forfeited') DEFAULT 'pending',
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ;

--
-- Dumping data for table `deposits`
--

INSERT INTO `deposits` (`deposit_id`, `contract_id`, `deposit_amount`, `deposit_date`, `payment_method`, `bank_account`, `receipt_number`, `refund_amount`, `refund_date`, `refund_method`, `deduction_amount`, `deduction_reason`, `deduction_details`, `deposit_status`, `notes`, `created_by`, `updated_by`, `created_at`, `updated_at`) VALUES
(1, 1, 10000.00, '2025-06-19', 'bank_transfer', NULL, NULL, 0.00, NULL, NULL, 0.00, NULL, NULL, 'received', 'โอนเงินจากบัญชี กสิกรไทย', 1, NULL, '2025-07-07 04:31:24', '2025-07-07 04:31:24'),
(2, 2, 11000.00, '2025-07-07', 'bank_transfer', NULL, NULL, 0.00, NULL, NULL, 0.00, NULL, NULL, 'received', NULL, 1, NULL, '2025-07-07 07:03:15', '2025-07-07 07:03:15');

--
-- Triggers `deposits`
--
DELIMITER $$
CREATE TRIGGER `deposits_audit_update` AFTER UPDATE ON `deposits` FOR EACH ROW BEGIN
    INSERT INTO deposit_audit_log (
        deposit_id, action, old_values, new_values, changed_by, ip_address
    ) VALUES (
        NEW.deposit_id,
        'update',
        JSON_OBJECT(
            'deposit_amount', OLD.deposit_amount,
            'deposit_status', OLD.deposit_status,
            'refund_amount', OLD.refund_amount,
            'deduction_amount', OLD.deduction_amount
        ),
        JSON_OBJECT(
            'deposit_amount', NEW.deposit_amount,
            'deposit_status', NEW.deposit_status,
            'refund_amount', NEW.refund_amount,
            'deduction_amount', NEW.deduction_amount
        ),
        NEW.updated_by,
        NULL -- IP Address จะต้องส่งมาจาก Application
    );
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `deposit_audit_log`
--

CREATE TABLE `deposit_audit_log` (
  `audit_id` int(11) NOT NULL,
  `deposit_id` int(11) NOT NULL,
  `action` enum('create','update','delete','receive','refund','forfeit') NOT NULL,
  `old_values` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`old_values`)),
  `new_values` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`new_values`)),
  `changed_by` int(11) NOT NULL,
  `changed_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Stand-in structure for view `deposit_statistics`
-- (See below for the actual view)
--
CREATE TABLE `deposit_statistics` (
`total_deposits` bigint(21)
,`total_amount` decimal(32,2)
,`received_amount` decimal(32,2)
,`total_refunded` decimal(32,2)
,`total_deducted` decimal(32,2)
,`pending_count` bigint(21)
,`received_count` bigint(21)
,`refunded_count` bigint(21)
,`average_deposit` decimal(14,6)
,`total_balance` decimal(34,2)
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `deposit_status_report`
-- (See below for the actual view)
--
CREATE TABLE `deposit_status_report` (
`deposit_status` enum('pending','received','partial_refund','fully_refunded','forfeited')
,`count` bigint(21)
,`total_amount` decimal(32,2)
,`avg_amount` decimal(14,6)
,`min_amount` decimal(10,2)
,`max_amount` decimal(10,2)
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `deposit_summary`
-- (See below for the actual view)
--
CREATE TABLE `deposit_summary` (
`deposit_id` int(11)
,`contract_id` int(11)
,`contract_start` date
,`contract_end` date
,`room_number` varchar(10)
,`tenant_name` varchar(101)
,`phone` varchar(15)
,`deposit_amount` decimal(10,2)
,`deposit_date` date
,`payment_method` enum('cash','bank_transfer','cheque','credit_card')
,`refund_amount` decimal(10,2)
,`refund_date` date
,`deduction_amount` decimal(10,2)
,`deposit_status` enum('pending','received','partial_refund','fully_refunded','forfeited')
,`balance` decimal(12,2)
,`notes` text
);

-- --------------------------------------------------------

--
-- Table structure for table `document_settings`
--

CREATE TABLE `document_settings` (
  `setting_id` int(11) NOT NULL,
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `setting_description` text DEFAULT NULL,
  `setting_type` enum('text','number','boolean','select') DEFAULT 'text',
  `setting_group` varchar(50) DEFAULT 'general',
  `display_order` int(11) DEFAULT 0,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `updated_by` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `document_settings`
--

INSERT INTO `document_settings` (`setting_id`, `setting_key`, `setting_value`, `setting_description`, `setting_type`, `setting_group`, `display_order`, `updated_at`, `updated_by`) VALUES
(1, 'max_file_size_global', '20', 'ขนาดไฟล์สูงสุดโดยรวม (MB)', 'number', 'general', 1, '2025-07-08 05:51:15', NULL),
(2, 'max_files_per_upload', '10', 'จำนวนไฟล์สูงสุดต่อการอัพโหลด', 'number', 'general', 2, '2025-07-08 05:51:15', NULL),
(3, 'allowed_extensions_global', 'jpg,jpeg,png,pdf,doc,docx', 'นามสกุลไฟล์ที่อนุญาตโดยรวม', 'text', 'general', 3, '2025-07-08 05:51:15', NULL),
(4, 'upload_path', 'uploads/documents/', 'โฟลเดอร์สำหรับเก็บเอกสาร', 'text', 'general', 4, '2025-07-08 05:51:15', NULL),
(5, 'auto_delete_temp', '1', 'ลบไฟล์ชั่วคราวอัตโนมัติ', 'boolean', 'general', 5, '2025-07-08 05:51:15', NULL),
(6, 'temp_file_lifetime', '24', 'อายุไฟล์ชั่วคราว (ชั่วโมง)', 'number', 'general', 6, '2025-07-08 05:51:15', NULL),
(7, 'scan_viruses', '0', 'สแกนไวรัสไฟล์ที่อัพโหลด', 'boolean', 'security', 7, '2025-07-08 05:51:15', NULL),
(8, 'block_executable', '1', 'บล็อกไฟล์ปฏิบัติการ', 'boolean', 'security', 8, '2025-07-08 05:51:15', NULL),
(9, 'check_file_content', '1', 'ตรวจสอบเนื้อหาในไฟล์', 'boolean', 'security', 9, '2025-07-08 05:51:15', NULL),
(10, 'watermark_pdfs', '0', 'ใส่ลายน้ำใน PDF', 'boolean', 'security', 10, '2025-07-08 05:51:15', NULL),
(11, 'encrypt_sensitive', '0', 'เข้ารหัสเอกสารสำคัญ', 'boolean', 'security', 11, '2025-07-08 05:51:15', NULL),
(12, 'thumbnail_size', '150', 'ขนาด thumbnail (px)', 'number', 'display', 12, '2025-07-08 05:51:15', NULL),
(13, 'generate_thumbnails', '1', 'สร้าง thumbnail อัตโนมัติ', 'boolean', 'display', 13, '2025-07-08 05:51:15', NULL),
(14, 'preview_quality', '85', 'คุณภาพ preview (1-100)', 'number', 'display', 14, '2025-07-08 05:51:15', NULL),
(15, 'items_per_page', '20', 'จำนวนรายการต่อหน้า', 'number', 'display', 15, '2025-07-08 05:51:15', NULL),
(16, 'default_view_mode', 'grid', 'โหมดแสดงผลเริ่มต้น', 'select', 'display', 16, '2025-07-08 05:51:15', NULL),
(17, 'auto_backup_documents', '1', 'สำรองเอกสารอัตโนมัติ', 'boolean', 'backup', 17, '2025-07-08 05:51:15', NULL),
(18, 'backup_frequency', 'daily', 'ความถี่การสำรองข้อมูล', 'select', 'backup', 18, '2025-07-08 05:51:15', NULL),
(19, 'backup_retention_days', '30', 'เก็บไฟล์สำรองข้อมูล (วัน)', 'number', 'backup', 19, '2025-07-08 05:51:15', NULL),
(20, 'compress_backups', '1', 'บีบอัดไฟล์สำรองข้อมูล', 'boolean', 'backup', 20, '2025-07-08 05:51:15', NULL),
(21, 'notify_upload_success', '1', 'แจ้งเตือนเมื่ออัพโหลดสำเร็จ', 'boolean', 'notification', 21, '2025-07-08 05:51:15', NULL),
(22, 'notify_upload_fail', '1', 'แจ้งเตือนเมื่ออัพโหลดล้มเหลว', 'boolean', 'notification', 22, '2025-07-08 05:51:15', NULL),
(23, 'notify_document_expire', '1', 'แจ้งเตือนเอกสารหมดอายุ', 'boolean', 'notification', 23, '2025-07-08 05:51:15', NULL),
(24, 'document_expire_days', '30', 'แจ้งเตือนก่อนหมดอายุ (วัน)', 'number', 'notification', 24, '2025-07-08 05:51:15', NULL),
(25, 'enable_versioning', '1', 'เก็บประวัติการแก้ไขไฟล์', 'boolean', 'advanced', 25, '2025-07-08 05:51:15', NULL),
(26, 'max_versions_per_file', '5', 'จำนวน version สูงสุดต่อไฟล์', 'number', 'advanced', 26, '2025-07-08 05:51:15', NULL),
(27, 'ocr_processing', '0', 'ประมวลผล OCR สำหรับ PDF', 'boolean', 'advanced', 27, '2025-07-08 05:51:15', NULL),
(28, 'auto_categorize', '0', 'จัดหมวดหมู่อัตโนมัติ', 'boolean', 'advanced', 28, '2025-07-08 05:51:15', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `document_types`
--

CREATE TABLE `document_types` (
  `type_id` int(11) NOT NULL,
  `type_code` varchar(50) NOT NULL,
  `type_name` varchar(100) NOT NULL,
  `type_description` text DEFAULT NULL,
  `is_required` tinyint(1) DEFAULT 0,
  `max_files` int(11) DEFAULT 5,
  `allowed_extensions` varchar(255) DEFAULT 'jpg,png,pdf',
  `max_file_size` int(11) DEFAULT 10,
  `is_active` tinyint(1) DEFAULT 1,
  `created_by` int(11) DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `document_types`
--

INSERT INTO `document_types` (`type_id`, `type_code`, `type_name`, `type_description`, `is_required`, `max_files`, `allowed_extensions`, `max_file_size`, `is_active`, `created_by`, `updated_by`, `created_at`, `updated_at`) VALUES
(1, 'contract', 'สัญญาเช่า', 'เอกสารสัญญาเช่าอย่างเป็นทางการ', 1, 3, 'pdf,jpg,png', 15, 1, NULL, NULL, '2025-07-08 05:40:55', '2025-07-08 05:40:55'),
(2, 'id_card', 'บัตรประชาชน', 'สำเนาบัตรประชาชนของผู้เช่าและผู้ค้ำประกัน', 1, 5, 'jpg,png,pdf', 10, 1, NULL, NULL, '2025-07-08 05:40:55', '2025-07-08 05:40:55'),
(3, 'income_proof', 'หลักฐานรายได้', 'หลักฐานแสดงรายได้ เช่น สลิปเงินเดือน ใบรับรองเงินเดือน', 1, 5, 'jpg,png,pdf', 10, 1, NULL, NULL, '2025-07-08 05:40:55', '2025-07-08 05:40:55'),
(4, 'guarantor_doc', 'เอกสารผู้ค้ำประกัน', 'เอกสารที่เกี่ยวข้องกับผู้ค้ำประกัน', 0, 10, 'jpg,png,pdf', 10, 1, NULL, NULL, '2025-07-08 05:40:55', '2025-07-08 05:40:55'),
(5, 'other', 'อื่นๆ', 'เอกสารอื่นๆ ที่เกี่ยวข้องกับการเช่า', 0, 20, 'jpg,png,pdf,doc,docx', 20, 1, NULL, NULL, '2025-07-08 05:40:55', '2025-07-08 05:40:55');

-- --------------------------------------------------------

--
-- Table structure for table `download_logs`
--

CREATE TABLE `download_logs` (
  `log_id` int(11) NOT NULL,
  `document_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `download_date` timestamp NOT NULL DEFAULT current_timestamp(),
  `ip_address` varchar(45) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `invoices`
--

CREATE TABLE `invoices` (
  `invoice_id` int(11) NOT NULL,
  `contract_id` int(11) DEFAULT NULL,
  `invoice_month` char(7) NOT NULL,
  `room_rent` decimal(10,2) NOT NULL,
  `water_charge` decimal(10,2) DEFAULT 0.00,
  `electric_charge` decimal(10,2) DEFAULT 0.00,
  `other_charges` decimal(10,2) DEFAULT 0.00,
  `other_charges_description` text DEFAULT NULL,
  `discount` decimal(10,2) DEFAULT 0.00,
  `total_amount` decimal(10,2) NOT NULL,
  `due_date` date NOT NULL,
  `invoice_status` enum('pending','paid','overdue','cancelled') DEFAULT 'pending',
  `payment_date` date DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `invoices`
--

INSERT INTO `invoices` (`invoice_id`, `contract_id`, `invoice_month`, `room_rent`, `water_charge`, `electric_charge`, `other_charges`, `other_charges_description`, `discount`, `total_amount`, `due_date`, `invoice_status`, `payment_date`, `created_at`) VALUES
(2, 1, '2025-06', 5000.00, 125.00, 1020.00, 0.00, NULL, 0.00, 6145.00, '2025-07-05', 'paid', '2025-07-02', '2025-07-02 03:25:47'),
(3, 2, '2025-07', 5500.00, 75.00, 1020.00, 0.00, NULL, 0.00, 6595.00, '2025-08-05', 'paid', '2025-07-07', '2025-07-07 07:02:30');

-- --------------------------------------------------------

--
-- Stand-in structure for view `monthly_deposit_report`
-- (See below for the actual view)
--
CREATE TABLE `monthly_deposit_report` (
`month` varchar(7)
,`deposits_count` bigint(21)
,`total_deposits` decimal(32,2)
,`total_refunds` decimal(32,2)
,`total_deductions` decimal(32,2)
,`net_balance` decimal(34,2)
);

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

CREATE TABLE `notifications` (
  `notification_id` int(11) NOT NULL,
  `tenant_id` int(11) DEFAULT NULL,
  `notification_type` enum('payment_due','payment_overdue','contract_expiring','maintenance','general') NOT NULL,
  `title` varchar(200) NOT NULL,
  `message` text NOT NULL,
  `is_read` tinyint(1) DEFAULT 0,
  `send_method` enum('system','email','sms') DEFAULT 'system',
  `scheduled_date` date DEFAULT NULL,
  `sent_date` date DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `notifications`
--

INSERT INTO `notifications` (`notification_id`, `tenant_id`, `notification_type`, `title`, `message`, `is_read`, `send_method`, `scheduled_date`, `sent_date`, `created_at`) VALUES
(12, 4, 'payment_due', 'แจ้งเตือนชำระค่าเช่า', 'เรียน {tenant_name}\r\n\r\nขอแจ้งให้ทราบว่า ค่าเช่าห้อง {room_number} ประจำเดือนนี้ ยังไม่ได้รับการชำระ\r\n\r\nกรุณาชำระภายในกำหนด เพื่อหลีกเลี่ยงค่าปรับ\r\n\r\nขอบคุณครับ/ค่ะ\r\n{dormitory_name}', 0, 'email', '2025-07-07', '2025-07-07', '2025-07-07 06:57:22'),
(13, 4, 'general', 'ประกาศทั่วไป', 'เรียน {tenant_name}\r\n\r\nขอแจ้งให้ทราบ [เนื้อหาประกาศ]\r\n\r\nขอบคุณครับ/ค่ะ\r\n{dormitory_name}', 0, 'email', '2025-07-07', '2025-07-07', '2025-07-07 07:28:21'),
(14, 4, 'payment_due', 'แจ้งเตือนชำระค่าเช่า', 'เรียน {tenant_name}\r\n\r\nขอแจ้งให้ทราบว่า ค่าเช่าห้อง {room_number} ประจำเดือนนี้ ยังไม่ได้รับการชำระ\r\n\r\nกรุณาชำระภายในกำหนด เพื่อหลีกเลี่ยงค่าปรับ\r\n\r\nขอบคุณครับ/ค่ะ\r\n{dormitory_name}', 0, 'email', '2025-07-08', '2025-07-08', '2025-07-08 05:47:34');

-- --------------------------------------------------------

--
-- Table structure for table `payments`
--

CREATE TABLE `payments` (
  `payment_id` int(11) NOT NULL,
  `invoice_id` int(11) DEFAULT NULL,
  `payment_amount` decimal(10,2) NOT NULL,
  `payment_method` enum('cash','bank_transfer','mobile_banking','other') NOT NULL,
  `payment_date` date NOT NULL,
  `payment_reference` varchar(100) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `payments`
--

INSERT INTO `payments` (`payment_id`, `invoice_id`, `payment_amount`, `payment_method`, `payment_date`, `payment_reference`, `notes`, `created_at`) VALUES
(1, 2, 6145.00, 'cash', '2025-07-02', '123456', '', '2025-07-02 03:26:04'),
(2, 3, 6595.00, 'cash', '2025-07-07', '', '', '2025-07-07 07:02:39'),
(3, 3, 6595.00, 'cash', '2025-07-07', '', '', '2025-07-07 07:02:43');

-- --------------------------------------------------------

--
-- Table structure for table `rooms`
--

CREATE TABLE `rooms` (
  `room_id` int(11) NOT NULL,
  `room_number` varchar(10) NOT NULL,
  `room_type` enum('single','double','triple') NOT NULL,
  `monthly_rent` decimal(10,2) NOT NULL,
  `deposit` decimal(10,2) NOT NULL,
  `room_status` enum('available','occupied','maintenance') DEFAULT 'available',
  `floor_number` int(11) DEFAULT NULL,
  `room_description` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `rooms`
--

INSERT INTO `rooms` (`room_id`, `room_number`, `room_type`, `monthly_rent`, `deposit`, `room_status`, `floor_number`, `room_description`, `created_at`) VALUES
(1, '101', 'single', 5000.00, 10000.00, 'occupied', 1, 'ห้องเดี่ยว ชั้น 1 พร้อมเครื่องปรับอากาศ', '2025-06-18 08:18:47'),
(2, '102', 'double', 7500.00, 14000.00, 'available', 1, 'ห้องคู่ ชั้น 1 พร้อมเครื่องปรับอากาศ', '2025-06-18 08:18:47'),
(3, '201', 'single', 5500.00, 11000.00, 'occupied', 2, 'ห้องเดี่ยว ชั้น 2 วิวสวย', '2025-06-18 08:18:47'),
(6, '202', 'double', 8000.00, 16000.00, 'available', 2, '', '2025-06-19 06:46:44'),
(7, '301', 'single', 8000.00, 16000.00, 'occupied', 3, '', '2025-06-19 06:47:01'),
(8, '302', 'double', 8500.00, 17000.00, 'available', 3, '', '2025-06-19 06:47:14'),
(9, '103', 'triple', 6000.00, 12000.00, 'available', 1, '', '2025-06-25 03:34:16'),
(10, '104', 'single', 5500.00, 11000.00, 'available', 1, '', '2025-06-25 03:34:36'),
(11, '105', 'double', 6000.00, 12000.00, 'available', 1, '', '2025-06-25 03:34:47'),
(12, '106', 'triple', 6500.00, 13000.00, 'available', 1, '', '2025-06-25 03:35:03');

-- --------------------------------------------------------

--
-- Table structure for table `system_settings`
--

CREATE TABLE `system_settings` (
  `setting_id` int(11) NOT NULL,
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `setting_description` text DEFAULT NULL,
  `setting_type` enum('text','number','boolean','email','url') DEFAULT 'text',
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `updated_by` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `system_settings`
--

INSERT INTO `system_settings` (`setting_id`, `setting_key`, `setting_value`, `setting_description`, `setting_type`, `updated_at`, `updated_by`) VALUES
(1, 'dormitory_name', 'หอพักตัวอย่าง', 'ชื่อหอพัก', 'text', '2025-07-02 04:18:58', NULL),
(2, 'dormitory_address', '', 'ที่อยู่หอพัก', 'text', '2025-07-02 04:18:58', NULL),
(3, 'dormitory_phone', '', 'เบอร์โทรศัพท์', 'text', '2025-07-02 04:18:58', NULL),
(4, 'dormitory_email', '', 'อีเมลติดต่อ', 'email', '2025-07-02 04:18:58', NULL),
(5, 'water_unit_price', '25.00', 'ราคาน้ำต่อหน่วย (บาท)', 'number', '2025-07-02 04:18:58', NULL),
(6, 'electric_unit_price', '8.00', 'ราคาไฟต่อหน่วย (บาท)', 'number', '2025-07-02 04:18:58', NULL),
(7, 'late_fee_per_day', '50.00', 'ค่าปรับล่าช้าต่อวัน (บาท)', 'number', '2025-07-02 04:18:58', NULL),
(8, 'payment_due_days', '7', 'จำนวนวันครบกำหนดชำระ', 'number', '2025-07-02 04:18:58', NULL),
(9, 'auto_backup', '1', 'สำรองข้อมูลอัตโนมัติ', 'boolean', '2025-07-02 04:18:58', NULL),
(10, 'notification_email', '1', 'แจ้งเตือนทางอีเมล', 'boolean', '2025-07-02 04:18:58', NULL),
(11, 'system_maintenance', '0', 'โหมดปิดปรุงระบบ', 'boolean', '2025-07-02 04:18:58', NULL),
(12, 'max_login_attempts', '5', 'จำนวนครั้งล็อกอินผิดสูงสุด', 'number', '2025-07-02 04:18:58', NULL),
(13, 'session_timeout', '30', 'หมดอายุ Session (นาที)', 'number', '2025-07-02 04:18:58', NULL),
(14, 'smtp_host', '', 'SMTP Server Host', 'text', '2025-07-07 03:47:58', NULL),
(15, 'smtp_port', '587', 'SMTP Server Port', 'text', '2025-07-07 03:47:58', NULL),
(16, 'smtp_username', '', 'SMTP Username', 'text', '2025-07-07 03:47:58', NULL),
(17, 'smtp_password', '', 'SMTP Password', 'text', '2025-07-07 03:47:58', NULL),
(18, 'smtp_encryption', 'tls', 'SMTP Encryption Type', 'text', '2025-07-07 03:47:58', NULL),
(19, 'email_from_name', 'หอพัก', 'ชื่อผู้ส่งอีเมล', 'text', '2025-07-07 03:47:58', NULL),
(20, 'email_from_address', '', 'อีเมลผู้ส่ง', 'text', '2025-07-07 03:47:58', NULL),
(21, 'auto_payment_reminder', '1', 'แจ้งเตือนชำระเงินอัตโนมัติ', 'text', '2025-07-07 03:47:58', NULL),
(22, 'auto_overdue_reminder', '1', 'แจ้งเตือนเงินค้างชำระอัตโนมัติ', 'text', '2025-07-07 03:47:58', NULL),
(23, 'auto_contract_expiry', '1', 'แจ้งเตือนสัญญาหมดอายุอัตโนมัติ', 'text', '2025-07-07 03:47:58', NULL),
(24, 'payment_reminder_days', '3', 'จำนวนวันก่อนครบกำหนดชำระ', 'text', '2025-07-07 03:47:58', NULL),
(25, 'contract_expiry_days', '30', 'จำนวนวันก่อนสัญญาหมดอายุ', 'text', '2025-07-07 03:47:58', NULL),
(26, 'max_file_upload_size', '10485760', 'ขนาดไฟล์สูงสุดที่อัพโหลดได้ (bytes)', 'number', '2025-07-07 04:42:31', NULL),
(27, 'allowed_file_types', 'jpg,jpeg,png,gif,pdf,webp', 'ประเภทไฟล์ที่อนุญาตให้อัพโหลด', 'text', '2025-07-07 04:42:31', NULL),
(28, 'deposits_auto_receive', '1', 'รับเงินมัดจำอัตโนมัติเมื่อเพิ่มใหม่', 'boolean', '2025-07-07 04:42:31', NULL),
(29, 'require_deposit_receipt', '0', 'บังคับให้มีเลขที่ใบเสร็จ', 'boolean', '2025-07-07 04:42:31', NULL),
(30, 'deposit_system_version', '1.0', 'เวอร์ชันระบบจัดการเงินมัดจำ', 'text', '2025-07-07 04:43:44', NULL),
(31, 'last_database_update', '2025-07-07 11:43:44', 'วันที่อัพเดทฐานข้อมูลล่าสุด', 'text', '2025-07-07 04:43:44', NULL),
(32, 'document_storage_path', 'uploads/contracts/', 'พาธสำหรับเก็บเอกสาร', 'text', '2025-07-07 04:43:44', NULL),
(33, 'enable_audit_log', '1', 'เปิดใช้งาน audit log', 'boolean', '2025-07-07 04:43:44', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `tenants`
--

CREATE TABLE `tenants` (
  `tenant_id` int(11) NOT NULL,
  `first_name` varchar(50) NOT NULL,
  `last_name` varchar(50) NOT NULL,
  `phone` varchar(15) NOT NULL,
  `email` varchar(100) DEFAULT NULL,
  `id_card` varchar(20) NOT NULL,
  `address` text DEFAULT NULL,
  `emergency_contact` varchar(100) DEFAULT NULL,
  `emergency_phone` varchar(15) DEFAULT NULL,
  `tenant_status` enum('active','inactive') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `tenants`
--

INSERT INTO `tenants` (`tenant_id`, `first_name`, `last_name`, `phone`, `email`, `id_card`, `address`, `emergency_contact`, `emergency_phone`, `tenant_status`, `created_at`) VALUES
(1, 'Phutanes', 'Trisiri', '094-418-6852', 'phutanestrisiri@gmail.com', '1145620245785', '107/232 หมู่บ้านกฤษณา ร่มเกล้า 12', 'ภรณิศ จ้อยใจสุข', '094-418-6852', 'active', '2025-06-19 04:38:45'),
(3, 'ทดสอบ', '1', '031-542-7854', 'phutanestrisiri@gmail.com', '1234567891011', '---', NULL, NULL, 'active', '2025-06-19 06:48:49'),
(4, 'ทดสอบ', '2', '024-587-8412', 'phutanestrisiri.1@gmail.com', '1234567891012', NULL, NULL, NULL, 'active', '2025-06-19 06:49:15'),
(5, 'ทดสอบ', '3', '075-894-4658', 'phutanestrisiri@gmail.com', '1234567891013', NULL, NULL, NULL, 'active', '2025-06-19 06:49:34'),
(6, 'นางสาว อรอุทัย', 'ใจรักเธอ', '092-341-4243', 'ahuautai@gmail.com', '1123533224898', 'ที่อยู่ของคุณอรอุทัยอยู่นี่', 'อรอุทัย ใจรักเธอ', '092-341-4243', 'active', '2025-06-21 05:56:35'),
(7, 'นาาย สมพงษ์', 'จงยินดี', '087-123-4219', 'sompongjub@gmail.com', '1539588810088', '--------', 'สมพงษ์', '038-488-8284', 'active', '2025-06-21 05:57:31'),
(8, 'นาง นพนภา', 'นาแจงกึม', '092-347-7382', 'nopnapa@hotmail.com', '8552796235458', '---------', 'นพนภา', '092-341-4243', 'active', '2025-06-21 05:58:36');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `user_id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `email` varchar(100) DEFAULT NULL,
  `user_role` enum('admin','staff') DEFAULT 'staff',
  `is_active` tinyint(1) DEFAULT 1,
  `last_login` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`user_id`, `username`, `password_hash`, `full_name`, `email`, `user_role`, `is_active`, `last_login`, `created_at`) VALUES
(1, 'admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'ผู้ดูแลระบบ', 'admin@dormitory.local', 'admin', 1, '2025-07-09 03:35:50', '2025-06-18 08:18:47'),
(2, 'staff01', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'เจ้าหน้าที่ 1', 'staff01@dormitory.local', 'staff', 1, NULL, '2025-06-18 08:18:47');

-- --------------------------------------------------------

--
-- Table structure for table `utility_readings`
--

CREATE TABLE `utility_readings` (
  `reading_id` int(11) NOT NULL,
  `room_id` int(11) DEFAULT NULL,
  `reading_month` char(7) NOT NULL,
  `water_previous` decimal(8,2) DEFAULT 0.00,
  `water_current` decimal(8,2) NOT NULL,
  `water_unit_price` decimal(6,2) DEFAULT 25.00,
  `electric_previous` decimal(8,2) DEFAULT 0.00,
  `electric_current` decimal(8,2) NOT NULL,
  `electric_unit_price` decimal(6,2) DEFAULT 8.50,
  `reading_date` date NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `utility_readings`
--

INSERT INTO `utility_readings` (`reading_id`, `room_id`, `reading_month`, `water_previous`, `water_current`, `water_unit_price`, `electric_previous`, `electric_current`, `electric_unit_price`, `reading_date`, `created_at`) VALUES
(1, 1, '2025-06', 0.00, 5.00, 25.00, 0.00, 120.00, 8.50, '2025-07-02', '2025-07-02 03:20:28'),
(2, 3, '2025-07', 0.00, 3.00, 25.00, 0.00, 120.00, 8.50, '2025-07-07', '2025-07-07 07:02:05');

-- --------------------------------------------------------

--
-- Structure for view `deposit_statistics`
--
DROP TABLE IF EXISTS `deposit_statistics`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `deposit_statistics`  AS SELECT count(0) AS `total_deposits`, sum(`deposits`.`deposit_amount`) AS `total_amount`, sum(case when `deposits`.`deposit_status` = 'received' then `deposits`.`deposit_amount` else 0 end) AS `received_amount`, sum(`deposits`.`refund_amount`) AS `total_refunded`, sum(`deposits`.`deduction_amount`) AS `total_deducted`, count(case when `deposits`.`deposit_status` = 'pending' then 1 end) AS `pending_count`, count(case when `deposits`.`deposit_status` = 'received' then 1 end) AS `received_count`, count(case when `deposits`.`deposit_status` in ('partial_refund','fully_refunded') then 1 end) AS `refunded_count`, avg(`deposits`.`deposit_amount`) AS `average_deposit`, sum(case when `deposits`.`deposit_status` = 'received' then `deposits`.`deposit_amount` else 0 end) - sum(`deposits`.`refund_amount`) - sum(`deposits`.`deduction_amount`) AS `total_balance` FROM `deposits` ;

-- --------------------------------------------------------

--
-- Structure for view `deposit_status_report`
--
DROP TABLE IF EXISTS `deposit_status_report`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `deposit_status_report`  AS SELECT `deposits`.`deposit_status` AS `deposit_status`, count(0) AS `count`, sum(`deposits`.`deposit_amount`) AS `total_amount`, avg(`deposits`.`deposit_amount`) AS `avg_amount`, min(`deposits`.`deposit_amount`) AS `min_amount`, max(`deposits`.`deposit_amount`) AS `max_amount` FROM `deposits` GROUP BY `deposits`.`deposit_status` ;

-- --------------------------------------------------------

--
-- Structure for view `deposit_summary`
--
DROP TABLE IF EXISTS `deposit_summary`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `deposit_summary`  AS SELECT `d`.`deposit_id` AS `deposit_id`, `d`.`contract_id` AS `contract_id`, `c`.`contract_start` AS `contract_start`, `c`.`contract_end` AS `contract_end`, `r`.`room_number` AS `room_number`, concat(`t`.`first_name`,' ',`t`.`last_name`) AS `tenant_name`, `t`.`phone` AS `phone`, `d`.`deposit_amount` AS `deposit_amount`, `d`.`deposit_date` AS `deposit_date`, `d`.`payment_method` AS `payment_method`, `d`.`refund_amount` AS `refund_amount`, `d`.`refund_date` AS `refund_date`, `d`.`deduction_amount` AS `deduction_amount`, `d`.`deposit_status` AS `deposit_status`, `d`.`deposit_amount`- `d`.`refund_amount` - `d`.`deduction_amount` AS `balance`, `d`.`notes` AS `notes` FROM (((`deposits` `d` join `contracts` `c` on(`d`.`contract_id` = `c`.`contract_id`)) join `rooms` `r` on(`c`.`room_id` = `r`.`room_id`)) join `tenants` `t` on(`c`.`tenant_id` = `t`.`tenant_id`)) ;

-- --------------------------------------------------------

--
-- Structure for view `monthly_deposit_report`
--
DROP TABLE IF EXISTS `monthly_deposit_report`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `monthly_deposit_report`  AS SELECT date_format(`deposits`.`deposit_date`,'%Y-%m') AS `month`, count(0) AS `deposits_count`, sum(`deposits`.`deposit_amount`) AS `total_deposits`, sum(`deposits`.`refund_amount`) AS `total_refunds`, sum(`deposits`.`deduction_amount`) AS `total_deductions`, sum(`deposits`.`deposit_amount` - ifnull(`deposits`.`refund_amount`,0) - ifnull(`deposits`.`deduction_amount`,0)) AS `net_balance` FROM `deposits` GROUP BY date_format(`deposits`.`deposit_date`,'%Y-%m') ORDER BY date_format(`deposits`.`deposit_date`,'%Y-%m') DESC ;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `contracts`
--
ALTER TABLE `contracts`
  ADD PRIMARY KEY (`contract_id`),
  ADD KEY `idx_contracts_tenant` (`tenant_id`),
  ADD KEY `idx_contracts_room` (`room_id`),
  ADD KEY `idx_contracts_deposit_status` (`deposit_status`);

--
-- Indexes for table `contract_documents`
--
ALTER TABLE `contract_documents`
  ADD PRIMARY KEY (`document_id`),
  ADD KEY `uploaded_by` (`uploaded_by`),
  ADD KEY `idx_documents_contract` (`contract_id`),
  ADD KEY `idx_documents_type` (`document_type`),
  ADD KEY `idx_documents_file_type` (`mime_type`);

--
-- Indexes for table `damage_items`
--
ALTER TABLE `damage_items`
  ADD PRIMARY KEY (`damage_id`),
  ADD KEY `deposit_id` (`deposit_id`);

--
-- Indexes for table `deposits`
--
ALTER TABLE `deposits`
  ADD PRIMARY KEY (`deposit_id`),
  ADD KEY `created_by` (`created_by`),
  ADD KEY `updated_by` (`updated_by`),
  ADD KEY `idx_deposits_contract` (`contract_id`),
  ADD KEY `idx_deposits_status` (`deposit_status`),
  ADD KEY `idx_deposits_date` (`deposit_date`),
  ADD KEY `idx_deposits_created_updated` (`created_at`,`updated_at`);

--
-- Indexes for table `deposit_audit_log`
--
ALTER TABLE `deposit_audit_log`
  ADD PRIMARY KEY (`audit_id`),
  ADD KEY `idx_audit_deposit` (`deposit_id`),
  ADD KEY `idx_audit_action` (`action`),
  ADD KEY `idx_audit_date` (`changed_at`),
  ADD KEY `idx_audit_user` (`changed_by`);

--
-- Indexes for table `document_settings`
--
ALTER TABLE `document_settings`
  ADD PRIMARY KEY (`setting_id`),
  ADD UNIQUE KEY `setting_key` (`setting_key`),
  ADD KEY `updated_by` (`updated_by`),
  ADD KEY `idx_setting_key` (`setting_key`),
  ADD KEY `idx_setting_group` (`setting_group`);

--
-- Indexes for table `document_types`
--
ALTER TABLE `document_types`
  ADD PRIMARY KEY (`type_id`),
  ADD UNIQUE KEY `type_code` (`type_code`),
  ADD KEY `created_by` (`created_by`),
  ADD KEY `updated_by` (`updated_by`),
  ADD KEY `idx_type_code` (`type_code`),
  ADD KEY `idx_is_active` (`is_active`),
  ADD KEY `idx_created_at` (`created_at`);

--
-- Indexes for table `download_logs`
--
ALTER TABLE `download_logs`
  ADD PRIMARY KEY (`log_id`),
  ADD KEY `idx_download_document` (`document_id`),
  ADD KEY `idx_download_user` (`user_id`),
  ADD KEY `idx_download_date` (`download_date`);

--
-- Indexes for table `invoices`
--
ALTER TABLE `invoices`
  ADD PRIMARY KEY (`invoice_id`),
  ADD UNIQUE KEY `unique_contract_month` (`contract_id`,`invoice_month`),
  ADD KEY `idx_invoices_contract` (`contract_id`),
  ADD KEY `idx_invoices_status` (`invoice_status`),
  ADD KEY `idx_invoices_due_date` (`due_date`);

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`notification_id`),
  ADD KEY `idx_notifications_tenant` (`tenant_id`);

--
-- Indexes for table `payments`
--
ALTER TABLE `payments`
  ADD PRIMARY KEY (`payment_id`),
  ADD KEY `idx_payments_invoice` (`invoice_id`);

--
-- Indexes for table `rooms`
--
ALTER TABLE `rooms`
  ADD PRIMARY KEY (`room_id`),
  ADD UNIQUE KEY `room_number` (`room_number`);

--
-- Indexes for table `system_settings`
--
ALTER TABLE `system_settings`
  ADD PRIMARY KEY (`setting_id`),
  ADD UNIQUE KEY `setting_key` (`setting_key`),
  ADD KEY `updated_by` (`updated_by`);

--
-- Indexes for table `tenants`
--
ALTER TABLE `tenants`
  ADD PRIMARY KEY (`tenant_id`),
  ADD UNIQUE KEY `id_card` (`id_card`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`user_id`),
  ADD UNIQUE KEY `username` (`username`);

--
-- Indexes for table `utility_readings`
--
ALTER TABLE `utility_readings`
  ADD PRIMARY KEY (`reading_id`),
  ADD UNIQUE KEY `unique_room_month` (`room_id`,`reading_month`),
  ADD KEY `idx_utility_room_month` (`room_id`,`reading_month`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `contracts`
--
ALTER TABLE `contracts`
  MODIFY `contract_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `contract_documents`
--
ALTER TABLE `contract_documents`
  MODIFY `document_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `damage_items`
--
ALTER TABLE `damage_items`
  MODIFY `damage_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `deposits`
--
ALTER TABLE `deposits`
  MODIFY `deposit_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `deposit_audit_log`
--
ALTER TABLE `deposit_audit_log`
  MODIFY `audit_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `document_settings`
--
ALTER TABLE `document_settings`
  MODIFY `setting_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=29;

--
-- AUTO_INCREMENT for table `document_types`
--
ALTER TABLE `document_types`
  MODIFY `type_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `download_logs`
--
ALTER TABLE `download_logs`
  MODIFY `log_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `invoices`
--
ALTER TABLE `invoices`
  MODIFY `invoice_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `notification_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=15;

--
-- AUTO_INCREMENT for table `payments`
--
ALTER TABLE `payments`
  MODIFY `payment_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `rooms`
--
ALTER TABLE `rooms`
  MODIFY `room_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `system_settings`
--
ALTER TABLE `system_settings`
  MODIFY `setting_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=35;

--
-- AUTO_INCREMENT for table `tenants`
--
ALTER TABLE `tenants`
  MODIFY `tenant_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `user_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `utility_readings`
--
ALTER TABLE `utility_readings`
  MODIFY `reading_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `contracts`
--
ALTER TABLE `contracts`
  ADD CONSTRAINT `contracts_ibfk_1` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`tenant_id`),
  ADD CONSTRAINT `contracts_ibfk_2` FOREIGN KEY (`room_id`) REFERENCES `rooms` (`room_id`);

--
-- Constraints for table `contract_documents`
--
ALTER TABLE `contract_documents`
  ADD CONSTRAINT `contract_documents_ibfk_1` FOREIGN KEY (`contract_id`) REFERENCES `contracts` (`contract_id`),
  ADD CONSTRAINT `contract_documents_ibfk_2` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`user_id`);

--
-- Constraints for table `damage_items`
--
ALTER TABLE `damage_items`
  ADD CONSTRAINT `damage_items_ibfk_1` FOREIGN KEY (`deposit_id`) REFERENCES `deposits` (`deposit_id`);

--
-- Constraints for table `deposits`
--
ALTER TABLE `deposits`
  ADD CONSTRAINT `deposits_ibfk_1` FOREIGN KEY (`contract_id`) REFERENCES `contracts` (`contract_id`),
  ADD CONSTRAINT `deposits_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`),
  ADD CONSTRAINT `deposits_ibfk_3` FOREIGN KEY (`updated_by`) REFERENCES `users` (`user_id`);

--
-- Constraints for table `deposit_audit_log`
--
ALTER TABLE `deposit_audit_log`
  ADD CONSTRAINT `deposit_audit_log_ibfk_1` FOREIGN KEY (`deposit_id`) REFERENCES `deposits` (`deposit_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `deposit_audit_log_ibfk_2` FOREIGN KEY (`changed_by`) REFERENCES `users` (`user_id`);

--
-- Constraints for table `document_settings`
--
ALTER TABLE `document_settings`
  ADD CONSTRAINT `document_settings_ibfk_1` FOREIGN KEY (`updated_by`) REFERENCES `users` (`user_id`);

--
-- Constraints for table `document_types`
--
ALTER TABLE `document_types`
  ADD CONSTRAINT `document_types_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `document_types_ibfk_2` FOREIGN KEY (`updated_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL;

--
-- Constraints for table `download_logs`
--
ALTER TABLE `download_logs`
  ADD CONSTRAINT `download_logs_ibfk_1` FOREIGN KEY (`document_id`) REFERENCES `contract_documents` (`document_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `download_logs_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

--
-- Constraints for table `invoices`
--
ALTER TABLE `invoices`
  ADD CONSTRAINT `invoices_ibfk_1` FOREIGN KEY (`contract_id`) REFERENCES `contracts` (`contract_id`);

--
-- Constraints for table `notifications`
--
ALTER TABLE `notifications`
  ADD CONSTRAINT `notifications_ibfk_1` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`tenant_id`);

--
-- Constraints for table `payments`
--
ALTER TABLE `payments`
  ADD CONSTRAINT `payments_ibfk_1` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`invoice_id`);

--
-- Constraints for table `system_settings`
--
ALTER TABLE `system_settings`
  ADD CONSTRAINT `system_settings_ibfk_1` FOREIGN KEY (`updated_by`) REFERENCES `users` (`user_id`);

--
-- Constraints for table `utility_readings`
--
ALTER TABLE `utility_readings`
  ADD CONSTRAINT `utility_readings_ibfk_1` FOREIGN KEY (`room_id`) REFERENCES `rooms` (`room_id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
