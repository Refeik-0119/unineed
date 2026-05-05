-- phpMyAdmin SQL Dump
-- version 4.9.11
-- https://www.phpmyadmin.net/
--
-- Host: db5018930086.hosting-data.io
-- Generation Time: May 05, 2026 at 12:32 PM
-- Server version: 8.0.36
-- PHP Version: 7.4.33

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET AUTOCOMMIT = 0;
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `dbs14922247`
--
CREATE DATABASE IF NOT EXISTS `dbs14922247` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
USE `dbs14922247`;

-- --------------------------------------------------------

--
-- Table structure for table `cart`
--

CREATE TABLE `cart` (
  `id` int NOT NULL,
  `user_id` int NOT NULL,
  `product_id` int NOT NULL,
  `variant_id` int DEFAULT NULL,
  `quantity` int NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `inventory_movements`
--

CREATE TABLE `inventory_movements` (
  `id` int NOT NULL,
  `product_id` int NOT NULL,
  `variant_id` int DEFAULT NULL,
  `variant_type` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `variant_value` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `price_at_movement` decimal(10,2) DEFAULT NULL,
  `quantity_change` int NOT NULL,
  `previous_quantity` int NOT NULL,
  `new_quantity` int NOT NULL,
  `movement_type` varchar(32) COLLATE utf8mb4_general_ci NOT NULL,
  `reason` text COLLATE utf8mb4_general_ci,
  `created_by` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `invoices`
--

CREATE TABLE `invoices` (
  `invoice_id` int NOT NULL,
  `order_id` int NOT NULL,
  `invoice_number` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `payment_status` varchar(50) COLLATE utf8mb4_general_ci DEFAULT 'unpaid',
  `amount_paid` decimal(10,2) NOT NULL DEFAULT '0.00',
  `balance_due` decimal(10,2) NOT NULL DEFAULT '0.00',
  `down_payment_due` decimal(10,2) NOT NULL DEFAULT '0.00',
  `remaining_balance` decimal(10,2) NOT NULL DEFAULT '0.00',
  `initial_down_payment` decimal(10,2) NOT NULL DEFAULT '0.00',
  `payment_date` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `invoices`
--

INSERT INTO `invoices` (`invoice_id`, `order_id`, `invoice_number`, `payment_status`, `amount_paid`, `balance_due`, `down_payment_due`, `remaining_balance`, `initial_down_payment`, `payment_date`, `created_at`) VALUES
(2, 2, 'INV-20260416-000002-855619', 'downpayment_paid', '185.00', '200.00', '0.00', '123.00', '77.00', '2026-04-16 14:19:08', '2026-04-16 06:16:01');

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

CREATE TABLE `notifications` (
  `notification_id` int NOT NULL,
  `user_id` int NOT NULL,
  `order_id` int DEFAULT NULL,
  `message` text COLLATE utf8mb4_general_ci NOT NULL,
  `type` varchar(50) COLLATE utf8mb4_general_ci DEFAULT 'general',
  `is_read` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `orders`
--

CREATE TABLE `orders` (
  `order_id` int NOT NULL,
  `user_id` int NOT NULL,
  `total_amount` decimal(10,2) NOT NULL DEFAULT '0.00',
  `payment_method` varchar(50) COLLATE utf8mb4_general_ci DEFAULT 'Cash',
  `receipt_proof` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `status` varchar(50) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `order_status` varchar(50) COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'pending',
  `received_at` datetime DEFAULT NULL,
  `due_date` datetime DEFAULT NULL,
  `cancellation_reason` text COLLATE utf8mb4_general_ci,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `orders`
--

INSERT INTO `orders` (`order_id`, `user_id`, `total_amount`, `payment_method`, `receipt_proof`, `status`, `order_status`, `received_at`, `due_date`, `cancellation_reason`, `created_at`, `updated_at`) VALUES
(2, 40, '385.00', 'cash_on_pickup', NULL, NULL, 'partial_payment', NULL, '2026-04-23 14:16:01', NULL, '2026-04-16 06:16:01', '2026-04-16 06:16:24');

-- --------------------------------------------------------

--
-- Table structure for table `order_items`
--

CREATE TABLE `order_items` (
  `order_item_id` int NOT NULL,
  `order_id` int NOT NULL,
  `product_id` int NOT NULL,
  `variant_id` int DEFAULT NULL,
  `quantity` int NOT NULL,
  `price` decimal(10,2) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `order_items`
--

INSERT INTO `order_items` (`order_item_id`, `order_id`, `product_id`, `variant_id`, `quantity`, `price`) VALUES
(2, 2, 12, 151, 1, '385.00');

-- --------------------------------------------------------

--
-- Table structure for table `password_resets`
--

CREATE TABLE `password_resets` (
  `id` int NOT NULL,
  `user_id` int NOT NULL,
  `token_hash` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `expires_at` datetime NOT NULL,
  `otp_hash` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `password_resets`
--

INSERT INTO `password_resets` (`id`, `user_id`, `token_hash`, `created_at`, `expires_at`, `otp_hash`) VALUES
(2, 21, '', '2026-04-12 06:14:43', '2026-04-12 14:29:43', 'ce79338ee1d29fcac67e852c33189339038d334103d61f81b569f65858df9a53');

-- --------------------------------------------------------

--
-- Table structure for table `payment_history`
--

CREATE TABLE `payment_history` (
  `history_id` int NOT NULL,
  `order_id` int NOT NULL,
  `invoice_id` int DEFAULT NULL,
  `payment_type` varchar(50) COLLATE utf8mb4_general_ci NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `previous_amount_paid` decimal(10,2) NOT NULL DEFAULT '0.00',
  `new_amount_paid` decimal(10,2) NOT NULL,
  `previous_balance` decimal(10,2) DEFAULT NULL,
  `new_balance` decimal(10,2) DEFAULT NULL,
  `payment_status_before` varchar(50) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `payment_status_after` varchar(50) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `recorded_by` varchar(50) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `change_timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `notes` text COLLATE utf8mb4_general_ci
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `payment_history`
--

INSERT INTO `payment_history` (`history_id`, `order_id`, `invoice_id`, `payment_type`, `amount`, `previous_amount_paid`, `new_amount_paid`, `previous_balance`, `new_balance`, `payment_status_before`, `payment_status_after`, `recorded_by`, `change_timestamp`, `notes`) VALUES
(1, 2, 2, 'downpayment', '77.00', '0.00', '77.00', '385.00', '308.00', 'unpaid', 'downpayment_paid', '1', '2026-04-16 06:16:24', ''),
(2, 2, 2, 'additional_payment', '108.00', '77.00', '185.00', '308.00', '200.00', 'downpayment_paid', 'downpayment_paid', '1', '2026-04-16 06:19:08', '');

-- --------------------------------------------------------

--
-- Table structure for table `products`
--

CREATE TABLE `products` (
  `product_id` int NOT NULL,
  `product_name` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  `description` text COLLATE utf8mb4_general_ci,
  `category` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `price` decimal(10,2) NOT NULL DEFAULT '0.00',
  `stock_quantity` int NOT NULL DEFAULT '0',
  `image_path` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `image_url` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `primary_color` varchar(7) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `secondary_color` varchar(7) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `accent_color` varchar(7) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `status` varchar(20) COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'available',
  `requires_down_payment` tinyint(1) NOT NULL DEFAULT '0',
  `is_preorder` tinyint(1) NOT NULL DEFAULT '0',
  `size_guide` text COLLATE utf8mb4_general_ci,
  `size_guide_enabled` tinyint(1) DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `products`
--

INSERT INTO `products` (`product_id`, `product_name`, `description`, `category`, `price`, `stock_quantity`, `image_path`, `image_url`, `primary_color`, `secondary_color`, `accent_color`, `status`, `requires_down_payment`, `is_preorder`, `size_guide`, `size_guide_enabled`, `created_at`, `updated_at`) VALUES
(12, 'Pants', 'durable, low-maintenance trousers designed for daily wear, commonly made from wrinkle-resistant cotton blends, twill, or polyester', 'School Uniform', '375.00', 0, '/assets/uploads/products/69b8f407a27d0.png', NULL, '#2E4412', '#F6C500', '#F78C56', 'available', 1, 1, 'Size,XS,S,M,L,XL,2XL,3XL\r\nWaist,26-28,28-30,30-32,32-34,34-36,36-38,38-40\r\nLength,30, 31, 31,32,32,32,33\r\n\r\n                                ', 0, '2026-03-17 06:26:15', '2026-03-17 06:46:57'),
(13, 'ID LACE', 'enhance campus security, enables access to school facilities, and serves as an official proof of enrollment', 'ID Accessories', '70.00', 105, '/assets/uploads/products/69b8f5cc1f7b9.jpg', NULL, '#2E4412', '#F6C500', '#F78C56', 'available', 0, 0, '                                ', 0, '2026-03-17 06:33:48', '2026-04-11 06:50:41'),
(14, 'Polo', 'a durable, comfortable, and smart-casual uniform essential made from breathable cotton or polyester-cotton pique blends', 'School Uniform', '325.00', 0, '/assets/uploads/products/69b8fc213eae2.jpg', NULL, '#2E4412', '#F6C500', '#F78C56', 'available', 1, 1, 'Size,S,M,L,XL,2XL,3XL\r\nLength,26,27,28,29,30,31\r\nChest,38,40,42,44,46,48\r\nShoulder,16,17,18,19,20,21\r\nSleeve Open,3.5,4,4,4.5,4.5,5\r\nSleeve Length,24,25,26,27,28,28.5\r\n                                ', 0, '2026-03-17 07:00:49', '2026-03-17 07:01:24');

-- --------------------------------------------------------

--
-- Table structure for table `product_variants`
--

CREATE TABLE `product_variants` (
  `variant_id` int NOT NULL,
  `product_id` int NOT NULL,
  `variant_type` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `variant_value` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `price` decimal(10,2) NOT NULL DEFAULT '0.00',
  `stock_quantity` int NOT NULL DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `product_variants`
--

INSERT INTO `product_variants` (`variant_id`, `product_id`, `variant_type`, `variant_value`, `price`, `stock_quantity`) VALUES
(150, 12, 'SIZE', '2XLarge', '375.00', 0),
(151, 12, 'SIZE', '3XLarge', '385.00', 0),
(152, 12, 'SIZE', 'Large', '365.00', 0),
(153, 12, 'SIZE', 'Medium', '360.00', 0),
(154, 12, 'SIZE', 'Small', '355.00', 0),
(155, 12, 'SIZE', 'XLarge', '370.00', 0),
(156, 12, 'SIZE', 'XSmall', '350.00', 0),
(161, 13, 'Department', 'ACT', '70.00', 10),
(162, 13, 'Department', 'BSIS', '70.00', 0),
(163, 13, 'Department', 'BSOM', '70.00', 10),
(164, 13, 'Department', 'BSAIS', '70.00', 9),
(165, 13, 'Department', 'BTVTED', '70.00', 10),
(166, 13, 'Department', 'DHRMT', '70.00', 9),
(167, 13, 'Department', 'BSCA', '70.00', 10),
(168, 13, 'Department', 'HB', '70.00', 9),
(169, 13, 'Department', 'SMAW', '70.00', 10),
(170, 13, 'Department', 'EIM', '70.00', 8),
(171, 13, 'Department', 'CCS', '70.00', 10),
(172, 13, 'Department', 'BKKP', '70.00', 10),
(187, 14, 'SIZE', '2XLarge', '325.00', 0),
(188, 14, 'SIZE', '3XLarge', '330.00', 0),
(189, 14, 'SIZE', 'Large', '310.00', 0),
(190, 14, 'SIZE', 'Medium', '305.00', 0),
(191, 14, 'SIZE', 'Small', '295.00', 0),
(192, 14, 'SIZE', 'XLarrge', '315.00', 0),
(193, 14, 'SIZE', 'XSmall', '280.00', 0);

-- --------------------------------------------------------

--
-- Table structure for table `settings`
--

CREATE TABLE `settings` (
  `id` int NOT NULL,
  `setting_key` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `setting_value` text COLLATE utf8mb4_general_ci
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `settings`
--

INSERT INTO `settings` (`id`, `setting_key`, `setting_value`) VALUES
(1, 'archive_student_criteria', '[{\"date\":\"2026-04-16\",\"year_level\":\"4\",\"course\":\"Bachelor of Science in Information Systems (BSIS)\"}]');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `user_id` int NOT NULL,
  `email` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  `password` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  `user_type` varchar(20) COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'student',
  `full_name` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `phone` varchar(20) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `student_id` varchar(50) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `course` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `year_level` varchar(50) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `section` varchar(50) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `status` enum('active','archived') COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'active',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`user_id`, `email`, `password`, `user_type`, `full_name`, `phone`, `student_id`, `course`, `year_level`, `section`, `status`, `created_at`, `updated_at`) VALUES
(1, 'admin1', '$2y$10$soWXAC.aQTvrcWoYyNfNdukdYN0Yv3ulG21xKyI0GtUppBbVECu9O', 'admin', 'Administrator', '09942173698', 'admin', NULL, NULL, NULL, 'active', '2026-03-04 01:48:12', '2026-04-06 12:18:52'),
(24, 'superadmin', '$2y$10$idoAwbSUcS8/OhLuQjnCFuYfuEscyVE01ULQ597YfktV6sVgePKWm', 'superadmin', 'Super Admin', '09933447697', '', '', '', '', 'active', '2026-04-06 03:18:08', '2026-04-06 12:03:28'),
(38, 'pldnltlvr@gmail.com', '$2y$10$1bqg9DLhi3cqxVd5M51okOZ4UVs0qz3AygoG6ztBA7XLUxQpSklaC', 'student', 'Paul Daniel Talavera', '09602556947', 'MA-22013935', 'Bachelor of Science in Information Systems (BSIS)', '4', 'C', 'archived', '2026-04-12 06:18:19', '2026-04-16 07:29:23'),
(40, 'refeiksanillav@gmail.com', '$2y$10$jsvsujPZbOdkIwEtR86fIeGqSxsEBVPqjBoIM1PTTh0OJ3Iu1zYEC', 'student', 'Kiefer Vallinas', '09942173697', 'MA-22013938', 'Bachelor of Science in Information Systems (BSIS)', '4', 'C', 'archived', '2026-04-15 13:57:55', '2026-04-16 07:29:23'),
(44, 'robert.gonzales0804@gmail.com', '$2y$10$GPkE4CNp8ewh1N72Px2tCeG0FpxcmbN9SWWNLId3.uVPQJ6CDPOMS', 'student', 'Roberto Gonzales', '09948544831', 'MA-22015002', 'Bachelor of Science in Information Systems (BSIS)', '3', 'B', 'active', '2026-04-16 06:07:22', NULL),
(45, 'JUAN@gmail.com', '$2y$10$TubB6vzE33nB0q0xyW1LWOh2kBKHrgWl5VcRWbO8OXn9Aocd3HkK6', 'student', 'juan dela cruz', '09349545345', 'MA-22019846', 'Associate in Computer Technology', '2', 'D', 'active', '2026-04-16 06:09:05', NULL),
(46, 'patrick@gmail.com', '$2y$10$lslHOHiXwG1/KUr2Uv2DOuOfmIV83txhUDMDRVZ0ABmqNeDqtafY2', 'student', 'patrick', '09234456657', 'MA-22013456', 'Bachelor of Science in Customs Administration (BSCA)', '4', 'B', 'active', '2026-04-16 06:10:35', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `variant_types`
--

CREATE TABLE `variant_types` (
  `variant_type_id` int NOT NULL,
  `name` varchar(100) COLLATE utf8mb4_general_ci NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `wishlist`
--

CREATE TABLE `wishlist` (
  `id` int NOT NULL,
  `user_id` int NOT NULL,
  `product_id` int NOT NULL,
  `variant_id` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `cart`
--
ALTER TABLE `cart`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `product_id` (`product_id`),
  ADD KEY `variant_id` (`variant_id`);

--
-- Indexes for table `inventory_movements`
--
ALTER TABLE `inventory_movements`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `invoices`
--
ALTER TABLE `invoices`
  ADD PRIMARY KEY (`invoice_id`),
  ADD UNIQUE KEY `invoice_number` (`invoice_number`),
  ADD KEY `order_id` (`order_id`);

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`notification_id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `order_id` (`order_id`);

--
-- Indexes for table `orders`
--
ALTER TABLE `orders`
  ADD PRIMARY KEY (`order_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `order_items`
--
ALTER TABLE `order_items`
  ADD PRIMARY KEY (`order_item_id`),
  ADD KEY `order_id` (`order_id`),
  ADD KEY `product_id` (`product_id`),
  ADD KEY `variant_id` (`variant_id`);

--
-- Indexes for table `password_resets`
--
ALTER TABLE `password_resets`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `token_hash` (`token_hash`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `payment_history`
--
ALTER TABLE `payment_history`
  ADD PRIMARY KEY (`history_id`),
  ADD KEY `idx_order_id` (`order_id`),
  ADD KEY `idx_timestamp` (`change_timestamp`),
  ADD KEY `idx_invoice_id` (`invoice_id`);

--
-- Indexes for table `products`
--
ALTER TABLE `products`
  ADD PRIMARY KEY (`product_id`);

--
-- Indexes for table `product_variants`
--
ALTER TABLE `product_variants`
  ADD PRIMARY KEY (`variant_id`),
  ADD UNIQUE KEY `unq_prod_type_val` (`product_id`,`variant_type`,`variant_value`);

--
-- Indexes for table `settings`
--
ALTER TABLE `settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `setting_key` (`setting_key`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`user_id`),
  ADD UNIQUE KEY `email` (`email`);

--
-- Indexes for table `variant_types`
--
ALTER TABLE `variant_types`
  ADD PRIMARY KEY (`variant_type_id`),
  ADD UNIQUE KEY `name` (`name`);

--
-- Indexes for table `wishlist`
--
ALTER TABLE `wishlist`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `product_id` (`product_id`),
  ADD KEY `variant_id` (`variant_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `cart`
--
ALTER TABLE `cart`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `inventory_movements`
--
ALTER TABLE `inventory_movements`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `invoices`
--
ALTER TABLE `invoices`
  MODIFY `invoice_id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `notification_id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `orders`
--
ALTER TABLE `orders`
  MODIFY `order_id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `order_items`
--
ALTER TABLE `order_items`
  MODIFY `order_item_id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `password_resets`
--
ALTER TABLE `password_resets`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `payment_history`
--
ALTER TABLE `payment_history`
  MODIFY `history_id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `products`
--
ALTER TABLE `products`
  MODIFY `product_id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=15;

--
-- AUTO_INCREMENT for table `product_variants`
--
ALTER TABLE `product_variants`
  MODIFY `variant_id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=194;

--
-- AUTO_INCREMENT for table `settings`
--
ALTER TABLE `settings`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `user_id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=49;

--
-- AUTO_INCREMENT for table `variant_types`
--
ALTER TABLE `variant_types`
  MODIFY `variant_type_id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `wishlist`
--
ALTER TABLE `wishlist`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `cart`
--
ALTER TABLE `cart`
  ADD CONSTRAINT `cart_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `cart_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `cart_ibfk_3` FOREIGN KEY (`variant_id`) REFERENCES `product_variants` (`variant_id`) ON DELETE SET NULL;

--
-- Constraints for table `invoices`
--
ALTER TABLE `invoices`
  ADD CONSTRAINT `invoices_ibfk_1` FOREIGN KEY (`order_id`) REFERENCES `orders` (`order_id`) ON DELETE CASCADE;

--
-- Constraints for table `notifications`
--
ALTER TABLE `notifications`
  ADD CONSTRAINT `notifications_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `notifications_ibfk_2` FOREIGN KEY (`order_id`) REFERENCES `orders` (`order_id`) ON DELETE SET NULL;

--
-- Constraints for table `orders`
--
ALTER TABLE `orders`
  ADD CONSTRAINT `orders_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

--
-- Constraints for table `order_items`
--
ALTER TABLE `order_items`
  ADD CONSTRAINT `order_items_ibfk_1` FOREIGN KEY (`order_id`) REFERENCES `orders` (`order_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `order_items_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `order_items_ibfk_3` FOREIGN KEY (`variant_id`) REFERENCES `product_variants` (`variant_id`) ON DELETE SET NULL;

--
-- Constraints for table `password_resets`
--
ALTER TABLE `password_resets`
  ADD CONSTRAINT `password_resets_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

--
-- Constraints for table `payment_history`
--
ALTER TABLE `payment_history`
  ADD CONSTRAINT `payment_history_ibfk_1` FOREIGN KEY (`order_id`) REFERENCES `orders` (`order_id`),
  ADD CONSTRAINT `payment_history_ibfk_2` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`invoice_id`);

--
-- Constraints for table `product_variants`
--
ALTER TABLE `product_variants`
  ADD CONSTRAINT `product_variants_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`) ON DELETE CASCADE;

--
-- Constraints for table `wishlist`
--
ALTER TABLE `wishlist`
  ADD CONSTRAINT `wishlist_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `wishlist_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `wishlist_ibfk_3` FOREIGN KEY (`variant_id`) REFERENCES `product_variants` (`variant_id`) ON DELETE SET NULL;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
