-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Jun 22, 2025 at 10:00 AM
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
  `contract_status` enum('active','expired','terminated') DEFAULT 'active',
  `special_conditions` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `contracts`
--

INSERT INTO `contracts` (`contract_id`, `tenant_id`, `room_id`, `contract_start`, `contract_end`, `monthly_rent`, `deposit_paid`, `contract_status`, `special_conditions`, `created_at`) VALUES
(1, 3, 1, '2025-06-19', '2027-06-19', 5000.00, 10000.00, 'active', 'ชำระเงินทันทีหลังทำสัญญา', '2025-06-19 06:51:20');

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
(2, '102', 'double', 7000.00, 14000.00, 'available', 1, 'ห้องคู่ ชั้น 1 พร้อมเครื่องปรับอากาศ', '2025-06-18 08:18:47'),
(3, '201', 'single', 5500.00, 11000.00, 'available', 2, 'ห้องเดี่ยว ชั้น 2 วิวสวย', '2025-06-18 08:18:47'),
(6, '202', 'double', 7000.00, 150000.00, 'available', 2, '', '2025-06-19 06:46:44'),
(7, '301', 'single', 8000.00, 16000.00, 'available', 3, '', '2025-06-19 06:47:01'),
(8, '302', 'double', 8500.00, 17000.00, 'available', 3, '', '2025-06-19 06:47:14');

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
(4, 'ทดสอบ', '2', '024-587-8412', 'phutanestrisiri@gmail.com', '1234567891012', NULL, NULL, NULL, 'active', '2025-06-19 06:49:15'),
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
(1, 'admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'ผู้ดูแลระบบ', 'admin@dormitory.local', 'admin', 1, '2025-06-20 03:35:21', '2025-06-18 08:18:47'),
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
-- Indexes for dumped tables
--

--
-- Indexes for table `contracts`
--
ALTER TABLE `contracts`
  ADD PRIMARY KEY (`contract_id`),
  ADD KEY `idx_contracts_tenant` (`tenant_id`),
  ADD KEY `idx_contracts_room` (`room_id`);

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
  MODIFY `contract_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `invoices`
--
ALTER TABLE `invoices`
  MODIFY `invoice_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `notification_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `payments`
--
ALTER TABLE `payments`
  MODIFY `payment_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `rooms`
--
ALTER TABLE `rooms`
  MODIFY `room_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

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
  MODIFY `reading_id` int(11) NOT NULL AUTO_INCREMENT;

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
-- Constraints for table `utility_readings`
--
ALTER TABLE `utility_readings`
  ADD CONSTRAINT `utility_readings_ibfk_1` FOREIGN KEY (`room_id`) REFERENCES `rooms` (`room_id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
