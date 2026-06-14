-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Jun 07, 2026 at 06:43 PM
-- Server version: 10.4.28-MariaDB
-- PHP Version: 8.2.4

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `yummy_soda`
--

-- --------------------------------------------------------

--
-- Table structure for table `auto_approve_rules`
--

CREATE TABLE `auto_approve_rules` (
  `rule_id` int(10) UNSIGNED NOT NULL,
  `min_threshold` decimal(10,2) NOT NULL DEFAULT 0.00,
  `max_threshold` decimal(10,2) NOT NULL,
  `is_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `label` varchar(60) NOT NULL DEFAULT '',
  `approved_count` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `last_run_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `auto_approve_rules`
--

INSERT INTO `auto_approve_rules` (`rule_id`, `min_threshold`, `max_threshold`, `is_enabled`, `label`, `approved_count`, `last_run_at`, `created_at`) VALUES
(1, 0.00, 500.00, 0, 'Up to ₱500', 0, '2026-06-07 23:54:58', '2026-06-07 21:56:04'),
(2, 500.00, 1000.00, 0, 'Up to ₱1,000', 0, '2026-06-07 23:55:15', '2026-06-07 21:56:05'),
(3, 1000.00, 2000.00, 0, 'Up to ₱2,000', 0, '2026-06-07 23:58:06', '2026-06-07 21:56:05'),
(4, 2000.00, 5000.00, 0, 'Up to ₱5,000', 0, '2026-06-07 23:58:46', '2026-06-07 21:56:05'),
(45, 5000.00, 999999.99, 0, '₱5,001 & above', 0, '2026-06-08 00:39:41', '2026-06-07 22:30:04');

-- --------------------------------------------------------

--
-- Table structure for table `cart_items`
--

CREATE TABLE `cart_items` (
  `user_id` int(10) UNSIGNED NOT NULL,
  `product_id` int(10) UNSIGNED NOT NULL,
  `qty` int(10) UNSIGNED NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `etl_runs`
--

CREATE TABLE `etl_runs` (
  `etl_run_id` bigint(20) NOT NULL,
  `started_at` datetime NOT NULL,
  `finished_at` datetime DEFAULT NULL,
  `status` enum('RUNNING','SUCCESS','FAILED') NOT NULL,
  `rows_inserted` bigint(20) NOT NULL DEFAULT 0,
  `error_message` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `messages`
--

CREATE TABLE `messages` (
  `message_id` int(10) UNSIGNED NOT NULL,
  `name` varchar(120) NOT NULL,
  `phone` varchar(40) NOT NULL,
  `comment` text NOT NULL DEFAULT '',
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `received_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `olap_dim_customer`
--

CREATE TABLE `olap_dim_customer` (
  `customer_key` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `full_name` varchar(120) NOT NULL,
  `email` varchar(120) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `olap_dim_date`
--

CREATE TABLE `olap_dim_date` (
  `date_key` int(11) NOT NULL,
  `full_date` date NOT NULL,
  `year` smallint(6) NOT NULL,
  `quarter` tinyint(4) NOT NULL,
  `month` tinyint(4) NOT NULL,
  `month_name` varchar(10) NOT NULL,
  `day` tinyint(4) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `olap_dim_payment_method`
--

CREATE TABLE `olap_dim_payment_method` (
  `payment_method_key` int(11) NOT NULL,
  `method` varchar(20) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `olap_dim_payment_method`
--

INSERT INTO `olap_dim_payment_method` (`payment_method_key`, `method`) VALUES
(3, 'CARD'),
(1, 'CASH'),
(2, 'GCASH');

-- --------------------------------------------------------

--
-- Table structure for table `olap_dim_product`
--

CREATE TABLE `olap_dim_product` (
  `product_key` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `sku` varchar(40) NOT NULL,
  `name` varchar(120) NOT NULL,
  `category` varchar(60) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `olap_dim_product`
--

INSERT INTO `olap_dim_product` (`product_key`, `product_id`, `sku`, `name`, `category`, `is_active`) VALUES
(1, 1, 'SODA-LIME', 'Lime Boost', 'Soda', 1),
(2, 2, 'SODA-STRAW', 'Strawberry Boost', 'Soda', 1),
(3, 3, 'SODA-ORANGE', 'Orange Boost', 'Soda', 1),
(16, 13, 'test_product1', 'test_product1', 'test_product1', 0);

-- --------------------------------------------------------

--
-- Table structure for table `olap_fact_sales`
--

CREATE TABLE `olap_fact_sales` (
  `sales_id` bigint(20) NOT NULL,
  `date_key` int(11) NOT NULL,
  `product_key` int(11) NOT NULL,
  `customer_key` int(11) NOT NULL,
  `payment_method_key` int(11) NOT NULL,
  `order_id` bigint(20) NOT NULL,
  `quantity` int(11) NOT NULL,
  `gross_amount` decimal(10,2) NOT NULL,
  `payment_status` varchar(10) NOT NULL,
  `order_status` varchar(10) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `orders`
--

CREATE TABLE `orders` (
  `order_id` bigint(20) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `customer_name` varchar(120) DEFAULT NULL,
  `customer_phone` varchar(40) DEFAULT NULL,
  `status` enum('PENDING','PAID','CANCELLED') NOT NULL DEFAULT 'PENDING',
  `ordered_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `order_items`
--

CREATE TABLE `order_items` (
  `order_item_id` bigint(20) NOT NULL,
  `order_id` bigint(20) NOT NULL,
  `product_id` int(11) NOT NULL,
  `quantity` int(11) NOT NULL,
  `unit_price` decimal(10,2) NOT NULL,
  `line_total` decimal(10,2) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `payments`
--

CREATE TABLE `payments` (
  `payment_id` bigint(20) NOT NULL,
  `order_id` bigint(20) NOT NULL,
  `method` enum('CASH','GCASH','CARD') NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `status` enum('UNPAID','PAID','FAILED') NOT NULL DEFAULT 'UNPAID',
  `paid_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `products`
--

CREATE TABLE `products` (
  `product_id` int(11) NOT NULL,
  `sku` varchar(40) NOT NULL,
  `name` varchar(120) NOT NULL,
  `category` varchar(60) NOT NULL,
  `price` decimal(10,2) NOT NULL,
  `stock_qty` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `image_path` varchar(255) NOT NULL DEFAULT '',
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `products`
--

INSERT INTO `products` (`product_id`, `sku`, `name`, `category`, `price`, `stock_qty`, `is_active`, `image_path`, `created_at`) VALUES
(1, 'SODA-LIME', 'Lime Boost', 'Soda', 49.00, 9812, 1, '', '2026-06-05 14:41:56'),
(2, 'SODA-STRAW', 'Strawberry Boost', 'Soda', 49.00, 0, 1, '', '2026-06-05 14:41:56'),
(3, 'SODA-ORANGE', 'Orange Boost', 'Soda', 49.00, 9381, 1, '', '2026-06-05 14:41:56'),
(13, 'test_product1', 'test_product1', 'test_product1', 10.00, 9, 0, 'product_6a2401eb0d140.png', '2026-06-06 19:16:08');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `user_id` int(11) NOT NULL,
  `full_name` varchar(120) NOT NULL,
  `email` varchar(120) NOT NULL,
  `phone` varchar(40) DEFAULT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role` enum('ADMIN','STAFF','CUSTOMER') NOT NULL DEFAULT 'STAFF',
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`user_id`, `full_name`, `email`, `phone`, `password_hash`, `role`, `created_at`) VALUES
(1, 'admin123', 'admin123@gmail.com', '0456', '$2y$10$kSN3T76qqwDekNMXn1B1Ve7FYXS96JMHDPpgEbKPGSR6EA1Uz4lNm', 'ADMIN', '2026-06-08 00:31:09');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `auto_approve_rules`
--
ALTER TABLE `auto_approve_rules`
  ADD PRIMARY KEY (`rule_id`),
  ADD UNIQUE KEY `uq_threshold` (`max_threshold`),
  ADD UNIQUE KEY `uq_max_threshold` (`max_threshold`);

--
-- Indexes for table `cart_items`
--
ALTER TABLE `cart_items`
  ADD PRIMARY KEY (`user_id`,`product_id`);

--
-- Indexes for table `etl_runs`
--
ALTER TABLE `etl_runs`
  ADD PRIMARY KEY (`etl_run_id`);

--
-- Indexes for table `messages`
--
ALTER TABLE `messages`
  ADD PRIMARY KEY (`message_id`);

--
-- Indexes for table `olap_dim_customer`
--
ALTER TABLE `olap_dim_customer`
  ADD PRIMARY KEY (`customer_key`),
  ADD UNIQUE KEY `user_id` (`user_id`);

--
-- Indexes for table `olap_dim_date`
--
ALTER TABLE `olap_dim_date`
  ADD PRIMARY KEY (`date_key`),
  ADD UNIQUE KEY `full_date` (`full_date`),
  ADD KEY `idx_dim_date_year_month` (`year`,`month`);

--
-- Indexes for table `olap_dim_payment_method`
--
ALTER TABLE `olap_dim_payment_method`
  ADD PRIMARY KEY (`payment_method_key`),
  ADD UNIQUE KEY `method` (`method`);

--
-- Indexes for table `olap_dim_product`
--
ALTER TABLE `olap_dim_product`
  ADD PRIMARY KEY (`product_key`),
  ADD UNIQUE KEY `product_id` (`product_id`);

--
-- Indexes for table `olap_fact_sales`
--
ALTER TABLE `olap_fact_sales`
  ADD PRIMARY KEY (`sales_id`),
  ADD KEY `idx_fact_date` (`date_key`),
  ADD KEY `idx_fact_product` (`product_key`),
  ADD KEY `idx_fact_method` (`payment_method_key`),
  ADD KEY `idx_fact_customer` (`customer_key`);

--
-- Indexes for table `orders`
--
ALTER TABLE `orders`
  ADD PRIMARY KEY (`order_id`),
  ADD KEY `idx_orders_status` (`status`),
  ADD KEY `idx_orders_ordered_at` (`ordered_at`),
  ADD KEY `idx_orders_user` (`user_id`);

--
-- Indexes for table `order_items`
--
ALTER TABLE `order_items`
  ADD PRIMARY KEY (`order_item_id`),
  ADD KEY `idx_items_order` (`order_id`),
  ADD KEY `idx_items_product` (`product_id`);

--
-- Indexes for table `payments`
--
ALTER TABLE `payments`
  ADD PRIMARY KEY (`payment_id`),
  ADD UNIQUE KEY `order_id` (`order_id`),
  ADD KEY `idx_payments_status` (`status`),
  ADD KEY `idx_payments_paid_at` (`paid_at`);

--
-- Indexes for table `products`
--
ALTER TABLE `products`
  ADD PRIMARY KEY (`product_id`),
  ADD UNIQUE KEY `sku` (`sku`),
  ADD KEY `idx_products_active` (`is_active`),
  ADD KEY `idx_products_category` (`category`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`user_id`),
  ADD UNIQUE KEY `email` (`email`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `auto_approve_rules`
--
ALTER TABLE `auto_approve_rules`
  MODIFY `rule_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=706;

--
-- AUTO_INCREMENT for table `etl_runs`
--
ALTER TABLE `etl_runs`
  MODIFY `etl_run_id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `messages`
--
ALTER TABLE `messages`
  MODIFY `message_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `olap_dim_customer`
--
ALTER TABLE `olap_dim_customer`
  MODIFY `customer_key` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `olap_dim_payment_method`
--
ALTER TABLE `olap_dim_payment_method`
  MODIFY `payment_method_key` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=47;

--
-- AUTO_INCREMENT for table `olap_dim_product`
--
ALTER TABLE `olap_dim_product`
  MODIFY `product_key` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=22;

--
-- AUTO_INCREMENT for table `olap_fact_sales`
--
ALTER TABLE `olap_fact_sales`
  MODIFY `sales_id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `orders`
--
ALTER TABLE `orders`
  MODIFY `order_id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `order_items`
--
ALTER TABLE `order_items`
  MODIFY `order_item_id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `payments`
--
ALTER TABLE `payments`
  MODIFY `payment_id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `products`
--
ALTER TABLE `products`
  MODIFY `product_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `user_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `olap_fact_sales`
--
ALTER TABLE `olap_fact_sales`
  ADD CONSTRAINT `fk_fact_customer` FOREIGN KEY (`customer_key`) REFERENCES `olap_dim_customer` (`customer_key`),
  ADD CONSTRAINT `fk_fact_date` FOREIGN KEY (`date_key`) REFERENCES `olap_dim_date` (`date_key`),
  ADD CONSTRAINT `fk_fact_method` FOREIGN KEY (`payment_method_key`) REFERENCES `olap_dim_payment_method` (`payment_method_key`),
  ADD CONSTRAINT `fk_fact_product` FOREIGN KEY (`product_key`) REFERENCES `olap_dim_product` (`product_key`);

--
-- Constraints for table `orders`
--
ALTER TABLE `orders`
  ADD CONSTRAINT `fk_orders_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `order_items`
--
ALTER TABLE `order_items`
  ADD CONSTRAINT `fk_items_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`order_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_items_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`) ON UPDATE CASCADE;

--
-- Constraints for table `payments`
--
ALTER TABLE `payments`
  ADD CONSTRAINT `fk_payments_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`order_id`) ON DELETE CASCADE ON UPDATE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
