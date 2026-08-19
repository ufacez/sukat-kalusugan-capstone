-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Aug 18, 2026 at 01:59 AM
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
-- Database: `sukat_kalusugan`
--

-- --------------------------------------------------------

--
-- Table structure for table `appointments`
--

CREATE TABLE `appointments` (
  `id` int(10) UNSIGNED NOT NULL,
  `child_id` int(10) UNSIGNED NOT NULL,
  `parent_id` int(10) UNSIGNED NOT NULL,
  `nutritionist_id` int(10) UNSIGNED NOT NULL,
  `scheduled_at` datetime NOT NULL,
  `status` enum('pending','confirmed','completed','cancelled') NOT NULL DEFAULT 'pending',
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `audit_logs`
--

CREATE TABLE `audit_logs` (
  `id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED DEFAULT NULL,
  `action` varchar(150) NOT NULL,
  `level` enum('info','warning','danger') NOT NULL DEFAULT 'info',
  `description` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `audit_logs`
--

INSERT INTO `audit_logs` (`id`, `user_id`, `action`, `level`, `description`, `ip_address`, `created_at`) VALUES
(1, 1, 'LOGIN', 'info', 'Staff login from ::1', '::1', '2026-07-06 12:25:46'),
(2, 1, 'LOGIN', 'info', 'Staff login from ::1', '::1', '2026-07-06 12:35:08'),
(3, 1, 'LOGOUT', 'info', 'Staff logout for admin@sukat.local', '::1', '2026-07-06 12:40:41'),
(4, 1, 'LOGIN', 'info', 'Staff login from ::1', '::1', '2026-07-06 13:15:48'),
(5, 1, 'LOGOUT', 'info', 'Staff logout for admin@sukat.local', '::1', '2026-07-06 13:16:22'),
(6, 1, 'LOGIN', 'info', 'Staff login from ::1', '::1', '2026-07-06 13:46:25'),
(7, 2, 'LOGIN', 'info', 'Staff login from ::1', '::1', '2026-07-07 06:06:43'),
(8, 2, 'LOGOUT', 'info', 'Staff logout for nutritionist@sukat.ph', '::1', '2026-07-07 06:07:00'),
(9, 1, 'LOGIN', 'info', 'Staff login from ::1', '::1', '2026-07-07 06:07:10'),
(10, 1, 'LOGOUT', 'info', 'Staff logout for admin@sukat.local', '::1', '2026-07-07 06:07:22'),
(11, 2, 'LOGIN', 'info', 'Staff login from ::1', '::1', '2026-07-07 06:09:49'),
(12, 2, 'LOGOUT', 'info', 'Staff logout for nutritionist@sukat.ph', '::1', '2026-07-07 06:10:27'),
(13, 2, 'LOGIN', 'info', 'Staff login from ::1', '::1', '2026-07-07 09:56:12'),
(14, 2, 'LOGOUT', 'info', 'Staff logout for nutritionist@sukat.ph', '::1', '2026-07-07 09:58:01'),
(15, 1, 'LOGIN', 'info', 'Staff login from ::1', '::1', '2026-07-07 09:58:10'),
(16, 1, 'LOGOUT', 'info', 'Staff logout for admin@sukat.local', '::1', '2026-07-07 09:58:21'),
(17, 2, 'LOGIN', 'info', 'Staff login from ::1', '::1', '2026-07-07 09:58:31'),
(18, 2, 'LOGOUT', 'info', 'Staff logout for nutritionist@sukat.ph', '::1', '2026-07-07 10:03:57'),
(19, 1, 'LOGIN', 'info', 'Staff login from ::1', '::1', '2026-07-07 10:04:06'),
(20, 1, 'LOGOUT', 'info', 'Staff logout for admin@sukat.local', '::1', '2026-07-07 10:04:13'),
(21, 2, 'LOGIN', 'info', 'Staff login from ::1', '::1', '2026-07-07 10:04:24'),
(22, 2, 'LOGOUT', 'info', 'Staff logout for nutritionist@sukat.ph', '::1', '2026-07-07 10:11:16'),
(23, 1, 'LOGIN', 'info', 'Staff login from ::1', '::1', '2026-07-07 10:11:24'),
(24, 1, 'LOGOUT', 'info', 'Staff logout for admin@sukat.local', '::1', '2026-07-07 10:11:34'),
(25, 2, 'LOGIN', 'info', 'Staff login from ::1', '::1', '2026-07-07 10:13:55'),
(26, NULL, 'LOGIN', 'info', 'Parent login for parent@sukat.local from ::1', '::1', '2026-07-31 14:26:03'),
(27, NULL, 'LOGOUT', 'info', 'Parent logout for parent@sukat.local', '::1', '2026-07-31 14:26:11'),
(28, NULL, 'LOGIN', 'info', 'Parent login for parent@sukat.local from ::1', '::1', '2026-07-31 14:26:29'),
(29, NULL, 'LOGOUT', 'info', 'Parent logout for parent@sukat.local', '::1', '2026-07-31 14:27:18'),
(30, 2, 'LOGIN', 'info', 'Staff login from ::1', '::1', '2026-07-31 14:30:41'),
(31, 2, 'LOGOUT', 'info', 'Staff logout for nutritionist@sukat.ph', '::1', '2026-07-31 14:31:03'),
(32, 1, 'LOGIN', 'info', 'Staff login from ::1', '::1', '2026-07-31 14:31:13'),
(33, 1, 'LOGOUT', 'info', 'Staff logout for admin@sukat.local', '::1', '2026-07-31 14:31:32'),
(34, 2, 'LOGIN', 'info', 'Staff login from ::1', '::1', '2026-08-16 11:08:25'),
(35, 1, 'LOGIN', 'info', 'Staff login from ::1', '::1', '2026-08-16 12:15:05'),
(36, 1, 'UPDATE_DEVICE', 'info', 'Updated device 1', '::1', '2026-08-16 12:35:27'),
(37, 1, 'LOGIN', 'info', 'Staff login from ::1', '::1', '2026-08-16 13:47:37'),
(38, 1, 'LOGOUT', 'info', 'Staff logout for admin@sukat.local', '::1', '2026-08-16 13:53:51'),
(39, 1, 'LOGIN', 'info', 'Staff login from ::1', '::1', '2026-08-16 13:54:13'),
(40, 1, 'LOGIN', 'info', 'Staff login from ::1', '::1', '2026-08-16 15:48:18'),
(41, 1, 'LOGOUT', 'info', 'Staff logout for admin@sukat.local', '::1', '2026-08-16 15:48:25'),
(42, 2, 'LOGIN', 'info', 'Staff login from ::1', '::1', '2026-08-16 15:48:29'),
(43, 2, 'LOGIN', 'info', 'Staff login from ::1', '::1', '2026-08-16 18:00:02'),
(44, 2, 'LOGIN', 'info', 'Staff login from ::1', '::1', '2026-08-16 23:24:19'),
(45, 2, 'LOGOUT', 'info', 'Staff logout for nutritionist@sukat.ph', '::1', '2026-08-17 00:12:38'),
(46, 2, 'LOGIN', 'info', 'Staff login from ::1', '::1', '2026-08-17 00:19:31'),
(47, 2, 'LOGOUT', 'info', 'Staff logout for nutritionist@sukat.ph', '::1', '2026-08-17 04:54:33');

-- --------------------------------------------------------

--
-- Table structure for table `barangays`
--

CREATE TABLE `barangays` (
  `id` int(10) UNSIGNED NOT NULL,
  `name` varchar(100) NOT NULL,
  `city_municipality` varchar(150) DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `barangays`
--

INSERT INTO `barangays` (`id`, `name`, `city_municipality`, `status`, `created_at`, `updated_at`) VALUES
(1, 'Bagong Silang', 'City of San Fernando, Pampanga', 'active', '2026-08-18 00:00:00', '2026-08-18 00:00:00');

-- --------------------------------------------------------

--
-- Table structure for table `children`
--

CREATE TABLE `children` (
  `id` int(10) UNSIGNED NOT NULL,
  `child_code` varchar(20) NOT NULL,
  `first_name` varchar(100) NOT NULL,
  `last_name` varchar(100) NOT NULL,
  `birthdate` date NOT NULL,
  `sex` enum('Male','Female') NOT NULL,
  `barangay_id` int(10) UNSIGNED DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `purok` varchar(150) DEFAULT NULL,
  `is_ip` tinyint(1) NOT NULL DEFAULT 0,
  `has_disability` tinyint(1) NOT NULL DEFAULT 0,
  `parent_id` int(10) UNSIGNED NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `children`
--

INSERT INTO `children` (`id`, `child_code`, `first_name`, `last_name`, `birthdate`, `sex`, `barangay_id`, `address`, `purok`, `is_ip`, `has_disability`, `parent_id`, `created_at`, `updated_at`) VALUES
(1, 'CHD-0001', 'Ean', 'Espiritu', '2023-02-01', 'Male', 1, '143 Purok 6 Brgy Dela Paz Norte City of San Fernando Pampanga', 'Purok 6', 0, 0, 1, '2026-07-07 10:10:38', '2026-07-07 10:10:38');

-- --------------------------------------------------------

--
-- Table structure for table `devices`
--

CREATE TABLE `devices` (
  `id` int(10) UNSIGNED NOT NULL,
  `device_code` varchar(50) NOT NULL,
  `location` varchar(150) DEFAULT NULL,
  `barangay_id` int(10) UNSIGNED DEFAULT NULL,
  `last_calibration_at` date DEFAULT NULL,
  `calibration_offset_height` decimal(6,2) DEFAULT 0.00,
  `calibration_offset_weight` decimal(6,3) DEFAULT 0.000,
  `status` enum('active','maintenance','offline') NOT NULL DEFAULT 'active',
  `last_seen_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `devices`
--

INSERT INTO `devices` (`id`, `device_code`, `location`, `last_calibration_at`, `calibration_offset_height`, `calibration_offset_weight`, `status`, `last_seen_at`, `created_at`, `updated_at`) VALUES
(1, 'ESP32-KIOSK-01', 'ESP32 Kiosk', NULL, 0.00, 0.000, 'active', '2026-08-17 05:32:16', '2026-08-16 10:58:37', '2026-08-17 05:32:16');

-- --------------------------------------------------------

--
-- Table structure for table `device_sensor_settings`
--

CREATE TABLE `device_sensor_settings` (
  `id` int(10) UNSIGNED NOT NULL,
  `device_code` varchar(50) COLLATE utf8mb4_general_ci NOT NULL,
  `hx711_calibration_factor` decimal(12,4) NOT NULL DEFAULT 0.0000,
  `hx711_tare_offset` decimal(10,3) NOT NULL DEFAULT 0.000,
  `tf_luna_offset_cm` decimal(6,2) NOT NULL DEFAULT 0.00,
  `tf_luna_scale_factor` decimal(8,4) NOT NULL DEFAULT 1.0000,
  `height_offset_cm` decimal(6,2) NOT NULL DEFAULT 0.00,
  `weight_offset_kg` decimal(8,4) NOT NULL DEFAULT 0.0000,
  `last_calibration_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `device_sensor_settings`
--

INSERT INTO `device_sensor_settings` (`id`, `device_code`, `hx711_calibration_factor`, `hx711_tare_offset`, `tf_luna_offset_cm`, `tf_luna_scale_factor`, `height_offset_cm`, `weight_offset_kg`, `last_calibration_at`, `created_at`, `updated_at`) VALUES
(1, 'ESP32-KIOSK-01', -19964.2500, 0.000, 0.00, 1.0000, 0.00, 0.0000, '2026-08-16 13:03:51', '2026-08-16 12:49:56', '2026-08-16 13:03:51');

-- --------------------------------------------------------

--
-- Table structure for table `measurements`
--

CREATE TABLE `measurements` (
  `id` int(10) UNSIGNED NOT NULL,
  `child_id` int(10) UNSIGNED NOT NULL,
  `height_cm` decimal(5,2) NOT NULL,
  `weight_kg` decimal(5,3) NOT NULL,
  `age_months` int(10) UNSIGNED NOT NULL,
  `measurement_date` date NOT NULL,
  `source_type` enum('kiosk','manual','mobile') NOT NULL DEFAULT 'kiosk',
  `waz` decimal(5,2) DEFAULT NULL,
  `haz` decimal(5,2) DEFAULT NULL,
  `whz` decimal(5,2) DEFAULT NULL,
  `nutritional_status` enum('Normal','Underweight','Severely Underweight','Stunted','Wasted','Overweight') DEFAULT NULL,
  `wfa_status` enum('SUW','MUW','Normal') DEFAULT NULL,
  `hfa_status` enum('SSt','MSt','Normal','Tall') DEFAULT NULL,
  `wfh_status` enum('SW(SAM)','MW(MAM)','Normal','OW','Ob') DEFAULT NULL,
  `device_id` int(10) UNSIGNED DEFAULT NULL,
  `recorded_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `measurements`
--

INSERT INTO `measurements` (`id`, `child_id`, `height_cm`, `weight_kg`, `age_months`, `measurement_date`, `source_type`, `waz`, `haz`, `whz`, `nutritional_status`, `wfa_status`, `hfa_status`, `wfh_status`, `device_id`, `recorded_by`, `created_at`) VALUES
(1, 1, 100.40, 3.910, 42, '2026-08-17', 'kiosk', -11.73, 0.14, -21.48, 'Severely Underweight', 'SUW', 'Normal', 'SW(SAM)', 1, NULL, '2026-08-17 04:19:07'),
(2, 1, 100.40, 3.890, 42, '2026-08-17', 'kiosk', -11.78, 0.14, -21.58, 'Severely Underweight', 'SUW', 'Normal', 'SW(SAM)', 1, NULL, '2026-08-17 04:35:39'),
(3, 1, 100.50, 3.890, 42, '2026-08-17', 'kiosk', -11.78, 0.16, -21.58, 'Severely Underweight', 'SUW', 'Normal', 'SW(SAM)', 1, NULL, '2026-08-17 04:36:55'),
(4, 1, 100.40, 3.890, 42, '2026-08-17', 'kiosk', -11.78, 0.14, -21.58, 'Severely Underweight', 'SUW', 'Normal', 'SW(SAM)', 1, NULL, '2026-08-17 04:37:20'),
(5, 1, 100.40, 3.880, 42, '2026-08-17', 'kiosk', -11.80, 0.14, -21.63, 'Severely Underweight', 'SUW', 'Normal', 'SW(SAM)', 1, NULL, '2026-08-17 04:37:46'),
(6, 1, 100.40, 3.840, 42, '2026-08-17', 'kiosk', -11.89, 0.14, -21.83, 'Severely Underweight', 'SUW', 'Normal', 'SW(SAM)', 1, NULL, '2026-08-17 04:40:59'),
(7, 1, 100.40, 3.870, 42, '2026-08-17', 'kiosk', -11.82, 0.14, -21.68, 'Severely Underweight', 'SUW', 'Normal', 'SW(SAM)', 1, NULL, '2026-08-17 04:51:54'),
(8, 1, 100.40, 4.340, 42, '2026-08-17', 'kiosk', -10.78, 0.14, -19.46, 'Severely Underweight', 'SUW', 'Normal', 'SW(SAM)', 1, NULL, '2026-08-17 04:52:28'),
(9, 1, 99.10, 3.560, 42, '2026-08-17', 'kiosk', -12.59, -0.19, -22.99, 'Severely Underweight', 'SUW', 'Normal', 'SW(SAM)', 1, NULL, '2026-08-17 05:21:31');

-- --------------------------------------------------------

--
-- Table structure for table `measurement_sessions`
--

CREATE TABLE `measurement_sessions` (
  `id` int(10) UNSIGNED NOT NULL,
  `device_id` int(10) UNSIGNED NOT NULL,
  `child_id` int(10) UNSIGNED NOT NULL,
  `status` enum('IDLE','START_REQUESTED','MEASURING','COMPLETE','ERROR','CANCELLED') NOT NULL DEFAULT 'IDLE',
  `command` varchar(20) NOT NULL DEFAULT 'START',
  `started_at` timestamp NULL DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `height_cm` decimal(6,2) DEFAULT NULL,
  `weight_kg` decimal(6,3) DEFAULT NULL,
  `measurement_id` int(10) UNSIGNED DEFAULT NULL,
  `error_message` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `measurement_sessions`
--

INSERT INTO `measurement_sessions` (`id`, `device_id`, `child_id`, `status`, `command`, `started_at`, `completed_at`, `expires_at`, `height_cm`, `weight_kg`, `measurement_id`, `error_message`, `created_at`, `updated_at`) VALUES
(1, 1, 1, 'ERROR', 'START', '2026-08-17 02:59:04', NULL, '2026-08-16 21:02:06', NULL, NULL, NULL, 'Measurement timed out before the ESP32 completed a result.', '2026-08-17 02:59:04', '2026-08-17 03:02:07'),
(2, 1, 1, 'ERROR', 'START', '2026-08-17 03:06:49', NULL, '2026-08-16 21:09:50', NULL, NULL, NULL, NULL, '2026-08-17 03:06:49', '2026-08-17 03:13:50'),
(3, 1, 1, 'ERROR', 'START', '2026-08-17 03:26:21', NULL, '2026-08-16 21:40:34', NULL, NULL, NULL, 'Measurement session expired.', '2026-08-17 03:26:21', '2026-08-17 03:59:07'),
(4, 1, 1, 'ERROR', 'START', '2026-08-17 04:02:18', NULL, '2026-08-16 22:05:19', NULL, NULL, NULL, 'Measurement session expired.', '2026-08-17 04:02:18', '2026-08-17 04:05:19'),
(5, 1, 1, 'ERROR', 'START', '2026-08-17 04:06:03', NULL, '2026-08-16 22:09:05', NULL, NULL, NULL, 'Weight must be between 2 kg and 80 kg for a valid child measurement.', '2026-08-17 04:06:03', '2026-08-17 04:06:15'),
(6, 1, 1, 'ERROR', 'START', '2026-08-17 04:10:00', NULL, '2026-08-16 22:13:02', NULL, NULL, NULL, 'Measurement session expired.', '2026-08-17 04:10:00', '2026-08-17 04:13:18'),
(7, 1, 1, 'ERROR', 'START', '2026-08-17 04:13:38', NULL, '2026-08-16 22:16:38', NULL, NULL, NULL, 'Measurement session expired.', '2026-08-17 04:13:38', '2026-08-17 04:16:39'),
(8, 1, 1, 'ERROR', 'START', '2026-08-17 04:16:58', NULL, '2026-08-16 22:19:59', NULL, NULL, NULL, 'Weight must be between 2 kg and 80 kg for a valid child measurement.', '2026-08-17 04:16:58', '2026-08-17 04:17:09'),
(9, 1, 1, 'COMPLETE', 'START', '2026-08-17 04:18:57', '2026-08-17 04:19:07', '2026-08-16 22:21:57', 100.40, 3.910, 1, NULL, '2026-08-17 04:18:57', '2026-08-17 04:19:07'),
(10, 1, 1, 'COMPLETE', 'START', '2026-08-17 04:35:27', '2026-08-17 04:35:39', '2026-08-16 22:38:29', 100.40, 3.890, 2, NULL, '2026-08-17 04:35:27', '2026-08-17 04:35:39'),
(11, 1, 1, 'COMPLETE', 'START', '2026-08-17 04:36:43', '2026-08-17 04:36:55', '2026-08-16 22:39:44', 100.50, 3.890, 3, NULL, '2026-08-17 04:36:43', '2026-08-17 04:36:55'),
(12, 1, 1, 'COMPLETE', 'START', '2026-08-17 04:37:08', '2026-08-17 04:37:20', '2026-08-16 22:40:09', 100.40, 3.890, 4, NULL, '2026-08-17 04:37:08', '2026-08-17 04:37:20'),
(13, 1, 1, 'COMPLETE', 'START', '2026-08-17 04:37:36', '2026-08-17 04:37:46', '2026-08-16 22:40:36', 100.40, 3.880, 5, NULL, '2026-08-17 04:37:36', '2026-08-17 04:37:46'),
(14, 1, 1, 'COMPLETE', 'START', '2026-08-17 04:40:46', '2026-08-17 04:40:59', '2026-08-16 22:43:48', 100.40, 3.840, 6, NULL, '2026-08-17 04:40:46', '2026-08-17 04:40:59'),
(15, 1, 1, 'COMPLETE', 'START', '2026-08-17 04:51:42', '2026-08-17 04:51:54', '2026-08-16 22:54:44', 100.40, 3.870, 7, NULL, '2026-08-17 04:51:42', '2026-08-17 04:51:54'),
(16, 1, 1, 'COMPLETE', 'START', '2026-08-17 04:52:16', '2026-08-17 04:52:28', '2026-08-16 22:55:17', 100.40, 4.340, 8, NULL, '2026-08-17 04:52:16', '2026-08-17 04:52:28'),
(17, 1, 1, 'ERROR', 'START', '2026-08-17 04:52:51', NULL, '2026-08-16 22:55:51', NULL, NULL, NULL, 'Weight must be between 2 kg and 80 kg for a valid child measurement.', '2026-08-17 04:52:51', '2026-08-17 04:53:02'),
(18, 1, 1, 'ERROR', 'START', '2026-08-17 05:01:50', NULL, '2026-08-16 23:04:51', NULL, NULL, NULL, 'Weight must be between 2 kg and 80 kg for a valid child measurement.', '2026-08-17 05:01:50', '2026-08-17 05:02:02'),
(19, 1, 1, 'ERROR', 'START', '2026-08-17 05:03:09', NULL, '2026-08-16 23:06:10', NULL, NULL, NULL, 'Weight must be between 2 kg and 80 kg for a valid child measurement.', '2026-08-17 05:03:09', '2026-08-17 05:03:20'),
(20, 1, 1, 'ERROR', 'START', '2026-08-17 05:13:32', NULL, '2026-08-16 23:16:34', NULL, NULL, NULL, 'Measurement session expired.', '2026-08-17 05:13:32', '2026-08-17 05:16:34'),
(21, 1, 1, 'ERROR', 'START', '2026-08-17 05:18:03', NULL, '2026-08-16 23:21:04', NULL, NULL, NULL, NULL, '2026-08-17 05:18:03', '2026-08-17 05:19:56'),
(22, 1, 1, 'ERROR', 'START', '2026-08-17 05:20:18', NULL, '2026-08-16 23:23:19', NULL, NULL, NULL, NULL, '2026-08-17 05:20:18', '2026-08-17 05:21:03'),
(23, 1, 1, 'COMPLETE', 'START', '2026-08-17 05:21:19', '2026-08-17 05:21:31', '2026-08-16 23:24:21', 99.10, 3.560, 9, NULL, '2026-08-17 05:21:19', '2026-08-17 05:21:31');

-- --------------------------------------------------------

--
-- Table structure for table `nutritionist_events`
--

CREATE TABLE `nutritionist_events` (
  `id` int(10) UNSIGNED NOT NULL,
  `event_type` enum('meeting','oplan_timbang') NOT NULL,
  `title` varchar(150) NOT NULL,
  `event_date` date NOT NULL,
  `event_time` time DEFAULT NULL,
  `location` varchar(150) DEFAULT NULL,
  `barangay_id` int(10) UNSIGNED DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `nutritionist_id` int(10) UNSIGNED NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `parents`
--

CREATE TABLE `parents` (
  `id` int(10) UNSIGNED NOT NULL,
  `name` varchar(150) NOT NULL,
  `email` varchar(150) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `parent_type` enum('Father','Mother','Guardian','Grandparent','Other') NOT NULL DEFAULT 'Guardian',
  `phone` varchar(30) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `barangay_id` int(10) UNSIGNED DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `parents`
--

INSERT INTO `parents` (`id`, `name`, `email`, `password_hash`, `parent_type`, `phone`, `address`, `barangay_id`, `status`, `created_at`, `updated_at`) VALUES
(1, 'John Doe', 'johndoe@gmail.com', '$2y$10$v79WKLLAb4U4VHiMEwSNW.5Al3urLiwD8a74xF4UJxLKDDr3k7wL2', 'Guardian', '0917910393', '143 Purok 6 Brgy Dela Paz Norte City of San Fernando Pampanga', 1, 'active', '2026-07-07 09:57:23', '2026-07-07 09:57:23'),
(2, 'Parent User', 'parent@sukat.local', '$2y$10$79K/UkdSI684IKAC/ekCM.irEzm206kvVE6o41d0hbChwNFelra7e', 'Guardian', NULL, 'All', NULL, 'active', '2026-07-31 14:25:13', '2026-07-31 14:25:13');

-- --------------------------------------------------------

--
-- Table structure for table `permissions`
--

CREATE TABLE `permissions` (
  `id` int(10) UNSIGNED NOT NULL,
  `code` varchar(100) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `permissions`
--

INSERT INTO `permissions` (`id`, `code`, `description`, `created_at`) VALUES
(1, 'dashboard.view', 'View the admin dashboard', '2026-07-06 13:19:50'),
(2, 'users.view', 'View staff accounts', '2026-07-06 13:19:50'),
(3, 'users.create', 'Create staff accounts', '2026-07-06 13:19:50'),
(4, 'users.update', 'Update staff accounts', '2026-07-06 13:19:50'),
(5, 'users.delete', 'Delete staff accounts', '2026-07-06 13:19:50'),
(6, 'audit_logs.view', 'View audit logs', '2026-07-06 13:19:50'),
(7, 'roles_permissions.view', 'View role policies', '2026-07-06 13:19:50'),
(8, 'roles_permissions.update', 'Update role policies', '2026-07-06 13:19:50'),
(9, 'sensors.view', 'View device calibration data', '2026-07-06 13:19:50'),
(10, 'sensors.update', 'Update device calibration data', '2026-07-06 13:19:50'),
(11, 'settings.view', 'View system settings', '2026-07-06 13:19:50'),
(12, 'settings.update', 'Update system settings', '2026-07-06 13:19:50'),
(13, 'parents.view', 'View parent accounts and linked children', '2026-07-31 14:29:23'),
(14, 'barangays.view', 'View barangay master list', '2026-08-18 00:00:00'),
(15, 'barangays.manage', 'Create, update, and deactivate barangays', '2026-08-18 00:00:00');

-- --------------------------------------------------------

--
-- Table structure for table `roles`
--

CREATE TABLE `roles` (
  `id` int(10) UNSIGNED NOT NULL,
  `name` varchar(50) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `roles`
--

INSERT INTO `roles` (`id`, `name`, `description`, `created_at`, `updated_at`) VALUES
(1, 'admin', 'System administrator', '2026-07-06 12:25:08', '2026-07-06 12:25:08'),
(2, 'nutritionist', 'Clinic nutritionist', '2026-07-06 13:19:50', '2026-07-06 13:19:50');

-- --------------------------------------------------------

--
-- Table structure for table `role_permissions`
--

CREATE TABLE `role_permissions` (
  `role_id` int(10) UNSIGNED NOT NULL,
  `permission_id` int(10) UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `role_permissions`
--

INSERT INTO `role_permissions` (`role_id`, `permission_id`) VALUES
(1, 1),
(1, 2),
(1, 3),
(1, 4),
(1, 5),
(1, 6),
(1, 7),
(1, 8),
(1, 9),
(1, 10),
(1, 11),
(1, 12),
(1, 13),
(1, 14),
(1, 15);

-- --------------------------------------------------------

--
-- Table structure for table `system_settings`
--

CREATE TABLE `system_settings` (
  `id` int(10) UNSIGNED NOT NULL,
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text NOT NULL,
  `value_type` enum('string','number','boolean','json') NOT NULL DEFAULT 'string',
  `description` varchar(255) DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `system_settings`
--

INSERT INTO `system_settings` (`id`, `setting_key`, `setting_value`, `value_type`, `description`, `updated_at`) VALUES
(1, 'app_name', 'Sukat Kalusugan', 'string', 'Displayed application name', '2026-07-06 13:19:50'),
(2, 'clinic_name', 'Barangay Nutrition Center', 'string', 'Primary clinic or office name', '2026-07-06 13:19:50'),
(3, 'support_email', 'support@sukat.local', 'string', 'System support contact', '2026-07-06 13:19:50'),
(4, 'sync_interval_minutes', '15', 'number', 'Telemetry and sync interval', '2026-07-06 13:19:50'),
(5, 'maintenance_mode', '0', 'boolean', 'Toggle read-only maintenance mode', '2026-07-06 13:19:50');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(10) UNSIGNED NOT NULL,
  `name` varchar(150) NOT NULL,
  `email` varchar(150) NOT NULL,
  `username` varchar(100) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `role_id` int(10) UNSIGNED NOT NULL,
  `barangay_id` int(10) UNSIGNED DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `last_login` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `name`, `email`, `username`, `password_hash`, `phone`, `role_id`, `barangay_id`, `status`, `last_login`, `created_at`, `updated_at`) VALUES
(1, 'System Administrator', 'admin@sukat.local', 'admin', '$2y$10$QeU7O5MRHmHPRIcCxGxluewFYWG9XlLAjQekBTU/bTNufGHqPNTmC', NULL, 1, NULL, 'active', '2026-08-16 15:48:18', '2026-07-06 12:25:08', '2026-08-16 15:48:18'),
(2, 'Nutritionist User', 'nutritionist@sukat.ph', 'nutritionist', '$2y$10$mLbAvfFAvfm63tCDmfN/0ezjmtXt6zv.e0r.SAUgdBJXIbV.I1BKy', NULL, 2, 1, 'active', '2026-08-17 00:19:31', '2026-07-07 06:05:41', '2026-08-17 00:19:31');

-- --------------------------------------------------------

--
-- Table structure for table `who_height_for_age`
--

CREATE TABLE `who_height_for_age` (
  `id` int(10) UNSIGNED NOT NULL,
  `sex` enum('Male','Female') NOT NULL,
  `age_months` int(10) UNSIGNED NOT NULL,
  `L` decimal(10,6) NOT NULL,
  `M` decimal(10,6) NOT NULL,
  `S` decimal(10,6) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `who_height_for_age`
--

INSERT INTO `who_height_for_age` (`id`, `sex`, `age_months`, `L`, `M`, `S`) VALUES
(1, 'Male', 0, 1.000000, 49.884200, 0.037950),
(2, 'Male', 1, 1.000000, 54.724400, 0.035570),
(3, 'Male', 2, 1.000000, 58.424900, 0.034240),
(4, 'Male', 3, 1.000000, 61.429200, 0.033280),
(5, 'Male', 4, 1.000000, 63.886000, 0.032570),
(6, 'Male', 5, 1.000000, 65.902600, 0.032040),
(7, 'Male', 6, 1.000000, 67.623600, 0.031650),
(8, 'Male', 7, 1.000000, 69.164500, 0.031390),
(9, 'Male', 8, 1.000000, 70.599400, 0.031240),
(10, 'Male', 9, 1.000000, 71.968700, 0.031170),
(11, 'Male', 10, 1.000000, 73.281200, 0.031180),
(12, 'Male', 11, 1.000000, 74.538800, 0.031250),
(13, 'Male', 12, 1.000000, 75.748800, 0.031370),
(14, 'Male', 13, 1.000000, 76.918600, 0.031540),
(15, 'Male', 14, 1.000000, 78.049700, 0.031740),
(16, 'Male', 15, 1.000000, 79.145800, 0.031970),
(17, 'Male', 16, 1.000000, 80.211300, 0.032220),
(18, 'Male', 17, 1.000000, 81.248700, 0.032500),
(19, 'Male', 18, 1.000000, 82.258700, 0.032790),
(20, 'Male', 19, 1.000000, 83.241800, 0.033100),
(21, 'Male', 20, 1.000000, 84.199600, 0.033420),
(22, 'Male', 21, 1.000000, 85.134800, 0.033760),
(23, 'Male', 22, 1.000000, 86.047700, 0.034100),
(24, 'Male', 23, 1.000000, 86.941000, 0.034450),
(25, 'Male', 24, 1.000000, 87.816100, 0.034790),
(26, 'Male', 25, 1.000000, 87.972000, 0.035420),
(27, 'Male', 26, 1.000000, 88.806500, 0.035760),
(28, 'Male', 27, 1.000000, 89.619700, 0.036100),
(29, 'Male', 28, 1.000000, 90.412000, 0.036420),
(30, 'Male', 29, 1.000000, 91.182800, 0.036740),
(31, 'Male', 30, 1.000000, 91.932700, 0.037040),
(32, 'Male', 31, 1.000000, 92.663100, 0.037330),
(33, 'Male', 32, 1.000000, 93.375300, 0.037610),
(34, 'Male', 33, 1.000000, 94.071100, 0.037870),
(35, 'Male', 34, 1.000000, 94.753200, 0.038120),
(36, 'Male', 35, 1.000000, 95.423600, 0.038360),
(37, 'Male', 36, 1.000000, 96.083500, 0.038580),
(38, 'Male', 37, 1.000000, 96.733700, 0.038790),
(39, 'Male', 38, 1.000000, 97.374900, 0.039000),
(40, 'Male', 39, 1.000000, 98.007300, 0.039190),
(41, 'Male', 40, 1.000000, 98.631000, 0.039370),
(42, 'Male', 41, 1.000000, 99.245900, 0.039540),
(43, 'Male', 42, 1.000000, 99.851500, 0.039710),
(44, 'Male', 43, 1.000000, 100.448500, 0.039860),
(45, 'Male', 44, 1.000000, 101.037400, 0.040020),
(46, 'Male', 45, 1.000000, 101.618600, 0.040160),
(47, 'Male', 46, 1.000000, 102.193300, 0.040310),
(48, 'Male', 47, 1.000000, 102.762500, 0.040450),
(49, 'Male', 48, 1.000000, 103.327300, 0.040590),
(50, 'Male', 49, 1.000000, 103.888600, 0.040730),
(51, 'Male', 50, 1.000000, 104.447300, 0.040860),
(52, 'Male', 51, 1.000000, 105.004100, 0.041000),
(53, 'Male', 52, 1.000000, 105.559600, 0.041130),
(54, 'Male', 53, 1.000000, 106.113800, 0.041260),
(55, 'Male', 54, 1.000000, 106.666800, 0.041390),
(56, 'Male', 55, 1.000000, 107.218800, 0.041520),
(57, 'Male', 56, 1.000000, 107.769700, 0.041650),
(58, 'Male', 57, 1.000000, 108.319800, 0.041770),
(59, 'Male', 58, 1.000000, 108.868900, 0.041900),
(60, 'Male', 59, 1.000000, 109.417000, 0.042020),
(61, 'Male', 60, 1.000000, 109.963800, 0.042140),
(62, 'Female', 0, 1.000000, 49.147700, 0.037900),
(63, 'Female', 1, 1.000000, 53.687200, 0.036400),
(64, 'Female', 2, 1.000000, 57.067300, 0.035680),
(65, 'Female', 3, 1.000000, 59.802900, 0.035200),
(66, 'Female', 4, 1.000000, 62.089900, 0.034860),
(67, 'Female', 5, 1.000000, 64.030100, 0.034630),
(68, 'Female', 6, 1.000000, 65.731100, 0.034480),
(69, 'Female', 7, 1.000000, 67.287300, 0.034410),
(70, 'Female', 8, 1.000000, 68.749800, 0.034400),
(71, 'Female', 9, 1.000000, 70.143500, 0.034440),
(72, 'Female', 10, 1.000000, 71.481800, 0.034520),
(73, 'Female', 11, 1.000000, 72.771000, 0.034640),
(74, 'Female', 12, 1.000000, 74.015000, 0.034790),
(75, 'Female', 13, 1.000000, 75.217600, 0.034960),
(76, 'Female', 14, 1.000000, 76.381700, 0.035140),
(77, 'Female', 15, 1.000000, 77.509900, 0.035340),
(78, 'Female', 16, 1.000000, 78.605500, 0.035550),
(79, 'Female', 17, 1.000000, 79.671000, 0.035760),
(80, 'Female', 18, 1.000000, 80.707900, 0.035980),
(81, 'Female', 19, 1.000000, 81.718200, 0.036200),
(82, 'Female', 20, 1.000000, 82.703600, 0.036430),
(83, 'Female', 21, 1.000000, 83.665400, 0.036660),
(84, 'Female', 22, 1.000000, 84.604000, 0.036880),
(85, 'Female', 23, 1.000000, 85.520200, 0.037110),
(86, 'Female', 24, 1.000000, 86.415300, 0.037340),
(87, 'Female', 25, 1.000000, 86.590400, 0.037860),
(88, 'Female', 26, 1.000000, 87.446200, 0.038080),
(89, 'Female', 27, 1.000000, 88.283000, 0.038300),
(90, 'Female', 28, 1.000000, 89.100400, 0.038510),
(91, 'Female', 29, 1.000000, 89.899100, 0.038720),
(92, 'Female', 30, 1.000000, 90.679700, 0.038930),
(93, 'Female', 31, 1.000000, 91.443000, 0.039130),
(94, 'Female', 32, 1.000000, 92.190600, 0.039330),
(95, 'Female', 33, 1.000000, 92.923900, 0.039520),
(96, 'Female', 34, 1.000000, 93.644400, 0.039710),
(97, 'Female', 35, 1.000000, 94.353300, 0.039890),
(98, 'Female', 36, 1.000000, 95.051500, 0.040060),
(99, 'Female', 37, 1.000000, 95.739900, 0.040240),
(100, 'Female', 38, 1.000000, 96.418700, 0.040410),
(101, 'Female', 39, 1.000000, 97.088500, 0.040570),
(102, 'Female', 40, 1.000000, 97.749300, 0.040730),
(103, 'Female', 41, 1.000000, 98.401500, 0.040890),
(104, 'Female', 42, 1.000000, 99.044800, 0.041050),
(105, 'Female', 43, 1.000000, 99.679500, 0.041200),
(106, 'Female', 44, 1.000000, 100.305800, 0.041350),
(107, 'Female', 45, 1.000000, 100.923800, 0.041500),
(108, 'Female', 46, 1.000000, 101.533700, 0.041640),
(109, 'Female', 47, 1.000000, 102.136000, 0.041790),
(110, 'Female', 48, 1.000000, 102.731200, 0.041930),
(111, 'Female', 49, 1.000000, 103.319700, 0.042060),
(112, 'Female', 50, 1.000000, 103.902100, 0.042200),
(113, 'Female', 51, 1.000000, 104.478600, 0.042330),
(114, 'Female', 52, 1.000000, 105.049400, 0.042460),
(115, 'Female', 53, 1.000000, 105.614800, 0.042590),
(116, 'Female', 54, 1.000000, 106.174800, 0.042720),
(117, 'Female', 55, 1.000000, 106.729500, 0.042850),
(118, 'Female', 56, 1.000000, 107.278800, 0.042980),
(119, 'Female', 57, 1.000000, 107.822700, 0.043100),
(120, 'Female', 58, 1.000000, 108.361300, 0.043220),
(121, 'Female', 59, 1.000000, 108.894800, 0.043340),
(122, 'Female', 60, 1.000000, 109.423300, 0.043470);

-- --------------------------------------------------------

--
-- Table structure for table `who_weight_for_age`
--

CREATE TABLE `who_weight_for_age` (
  `id` int(10) UNSIGNED NOT NULL,
  `sex` enum('Male','Female') NOT NULL,
  `age_months` int(10) UNSIGNED NOT NULL,
  `L` decimal(10,6) NOT NULL,
  `M` decimal(10,6) NOT NULL,
  `S` decimal(10,6) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `who_weight_for_age`
--

INSERT INTO `who_weight_for_age` (`id`, `sex`, `age_months`, `L`, `M`, `S`) VALUES
(1, 'Male', 0, 0.348700, 3.346400, 0.146020),
(2, 'Male', 1, 0.229700, 4.470900, 0.133950),
(3, 'Male', 2, 0.197000, 5.567500, 0.123850),
(4, 'Male', 3, 0.173800, 6.376200, 0.117270),
(5, 'Male', 4, 0.155300, 7.002300, 0.113160),
(6, 'Male', 5, 0.139500, 7.510500, 0.110800),
(7, 'Male', 6, 0.125700, 7.934000, 0.109580),
(8, 'Male', 7, 0.113400, 8.297000, 0.109020),
(9, 'Male', 8, 0.102100, 8.615100, 0.108820),
(10, 'Male', 9, 0.091700, 8.901400, 0.108810),
(11, 'Male', 10, 0.082000, 9.164900, 0.108910),
(12, 'Male', 11, 0.073000, 9.412200, 0.109060),
(13, 'Male', 12, 0.064400, 9.647900, 0.109250),
(14, 'Male', 13, 0.056300, 9.874900, 0.109490),
(15, 'Male', 14, 0.048700, 10.095300, 0.109760),
(16, 'Male', 15, 0.041300, 10.310800, 0.110070),
(17, 'Male', 16, 0.034300, 10.522800, 0.110410),
(18, 'Male', 17, 0.027500, 10.731900, 0.110790),
(19, 'Male', 18, 0.021100, 10.938500, 0.111190),
(20, 'Male', 19, 0.014800, 11.143000, 0.111640),
(21, 'Male', 20, 0.008700, 11.346200, 0.112110),
(22, 'Male', 21, 0.002900, 11.548600, 0.112610),
(23, 'Male', 22, -0.002800, 11.750400, 0.113140),
(24, 'Male', 23, -0.008300, 11.951400, 0.113690),
(25, 'Male', 24, -0.013700, 12.151500, 0.114260),
(26, 'Male', 25, -0.018900, 12.350200, 0.114850),
(27, 'Male', 26, -0.024000, 12.546600, 0.115440),
(28, 'Male', 27, -0.028900, 12.740100, 0.116040),
(29, 'Male', 28, -0.033700, 12.930300, 0.116640),
(30, 'Male', 29, -0.038500, 13.116900, 0.117230),
(31, 'Male', 30, -0.043100, 13.300000, 0.117810),
(32, 'Male', 31, -0.047600, 13.479800, 0.118390),
(33, 'Male', 32, -0.052000, 13.656700, 0.118960),
(34, 'Male', 33, -0.056400, 13.830900, 0.119530),
(35, 'Male', 34, -0.060600, 14.003100, 0.120080),
(36, 'Male', 35, -0.064800, 14.173600, 0.120620),
(37, 'Male', 36, -0.068900, 14.342900, 0.121160),
(38, 'Male', 37, -0.072900, 14.511300, 0.121680),
(39, 'Male', 38, -0.076900, 14.679100, 0.122200),
(40, 'Male', 39, -0.080800, 14.846600, 0.122710),
(41, 'Male', 40, -0.084600, 15.014000, 0.123220),
(42, 'Male', 41, -0.088300, 15.181300, 0.123730),
(43, 'Male', 42, -0.092000, 15.348600, 0.124250),
(44, 'Male', 43, -0.095700, 15.515800, 0.124780),
(45, 'Male', 44, -0.099300, 15.682800, 0.125310),
(46, 'Male', 45, -0.102800, 15.849700, 0.125860),
(47, 'Male', 46, -0.106300, 16.016300, 0.126430),
(48, 'Male', 47, -0.109700, 16.182700, 0.127000),
(49, 'Male', 48, -0.113100, 16.348900, 0.127590),
(50, 'Male', 49, -0.116500, 16.515000, 0.128190),
(51, 'Male', 50, -0.119800, 16.681100, 0.128800),
(52, 'Male', 51, -0.123000, 16.847100, 0.129430),
(53, 'Male', 52, -0.126200, 17.013200, 0.130050),
(54, 'Male', 53, -0.129400, 17.179200, 0.130690),
(55, 'Male', 54, -0.132500, 17.345200, 0.131330),
(56, 'Male', 55, -0.135600, 17.511100, 0.131970),
(57, 'Male', 56, -0.138700, 17.676800, 0.132610),
(58, 'Male', 57, -0.141700, 17.842200, 0.133250),
(59, 'Male', 58, -0.144700, 18.007300, 0.133890),
(60, 'Male', 59, -0.147700, 18.172200, 0.134530),
(61, 'Male', 60, -0.150600, 18.336600, 0.135170),
(62, 'Female', 0, 0.380900, 3.232200, 0.141710),
(63, 'Female', 1, 0.171400, 4.187300, 0.137240),
(64, 'Female', 2, 0.096200, 5.128200, 0.130000),
(65, 'Female', 3, 0.040200, 5.845800, 0.126190),
(66, 'Female', 4, -0.005000, 6.423700, 0.124020),
(67, 'Female', 5, -0.043000, 6.898500, 0.122740),
(68, 'Female', 6, -0.075600, 7.297000, 0.122040),
(69, 'Female', 7, -0.103900, 7.642200, 0.121780),
(70, 'Female', 8, -0.128800, 7.948700, 0.121810),
(71, 'Female', 9, -0.150700, 8.225400, 0.121990),
(72, 'Female', 10, -0.170000, 8.480000, 0.122230),
(73, 'Female', 11, -0.187200, 8.719200, 0.122470),
(74, 'Female', 12, -0.202400, 8.948100, 0.122680),
(75, 'Female', 13, -0.215800, 9.169900, 0.122830),
(76, 'Female', 14, -0.227800, 9.387000, 0.122940),
(77, 'Female', 15, -0.238400, 9.600800, 0.122990),
(78, 'Female', 16, -0.247800, 9.812400, 0.123030),
(79, 'Female', 17, -0.256200, 10.022600, 0.123060),
(80, 'Female', 18, -0.263700, 10.231500, 0.123090),
(81, 'Female', 19, -0.270300, 10.439300, 0.123150),
(82, 'Female', 20, -0.276200, 10.646400, 0.123230),
(83, 'Female', 21, -0.281500, 10.853400, 0.123350),
(84, 'Female', 22, -0.286200, 11.060800, 0.123500),
(85, 'Female', 23, -0.290300, 11.268800, 0.123690),
(86, 'Female', 24, -0.294100, 11.477500, 0.123900),
(87, 'Female', 25, -0.297500, 11.686400, 0.124140),
(88, 'Female', 26, -0.300500, 11.894700, 0.124410),
(89, 'Female', 27, -0.303200, 12.101500, 0.124720),
(90, 'Female', 28, -0.305700, 12.305900, 0.125060),
(91, 'Female', 29, -0.308000, 12.507300, 0.125450),
(92, 'Female', 30, -0.310100, 12.705500, 0.125870),
(93, 'Female', 31, -0.312000, 12.900600, 0.126330),
(94, 'Female', 32, -0.313800, 13.093000, 0.126830),
(95, 'Female', 33, -0.315500, 13.283700, 0.127370),
(96, 'Female', 34, -0.317100, 13.473100, 0.127940),
(97, 'Female', 35, -0.318600, 13.661800, 0.128550),
(98, 'Female', 36, -0.320100, 13.850300, 0.129190),
(99, 'Female', 37, -0.321600, 14.038500, 0.129880),
(100, 'Female', 38, -0.323000, 14.226500, 0.130590),
(101, 'Female', 39, -0.324300, 14.414000, 0.131350),
(102, 'Female', 40, -0.325700, 14.601000, 0.132130),
(103, 'Female', 41, -0.327000, 14.787300, 0.132930),
(104, 'Female', 42, -0.328300, 14.972700, 0.133760),
(105, 'Female', 43, -0.329600, 15.157300, 0.134600),
(106, 'Female', 44, -0.330900, 15.341000, 0.135450),
(107, 'Female', 45, -0.332200, 15.524000, 0.136300),
(108, 'Female', 46, -0.333500, 15.706400, 0.137160),
(109, 'Female', 47, -0.334800, 15.888200, 0.138000),
(110, 'Female', 48, -0.336100, 16.069700, 0.138840),
(111, 'Female', 49, -0.337400, 16.251100, 0.139680),
(112, 'Female', 50, -0.338700, 16.432200, 0.140510),
(113, 'Female', 51, -0.340000, 16.613300, 0.141320),
(114, 'Female', 52, -0.341400, 16.794200, 0.142130),
(115, 'Female', 53, -0.342700, 16.974800, 0.142930),
(116, 'Female', 54, -0.344000, 17.155100, 0.143710),
(117, 'Female', 55, -0.345300, 17.334700, 0.144480),
(118, 'Female', 56, -0.346600, 17.513600, 0.145250),
(119, 'Female', 57, -0.347900, 17.691600, 0.146000),
(120, 'Female', 58, -0.349200, 17.868600, 0.146750),
(121, 'Female', 59, -0.350500, 18.044500, 0.147480),
(122, 'Female', 60, -0.351800, 18.219300, 0.148210);

-- --------------------------------------------------------

--
-- Table structure for table `who_weight_for_height`
--

CREATE TABLE `who_weight_for_height` (
  `id` int(10) UNSIGNED NOT NULL,
  `sex` enum('Male','Female') NOT NULL,
  `height_cm` decimal(4,1) NOT NULL,
  `L` decimal(10,6) NOT NULL,
  `M` decimal(10,6) NOT NULL,
  `S` decimal(10,6) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `who_weight_for_height`
--

INSERT INTO `who_weight_for_height` (`id`, `sex`, `height_cm`, `L`, `M`, `S`) VALUES
(1, 'Male', 45.0, -0.352100, 2.441000, 0.091820),
(2, 'Male', 45.5, -0.352100, 2.524400, 0.091530),
(3, 'Male', 46.0, -0.352100, 2.607700, 0.091240),
(4, 'Male', 46.5, -0.352100, 2.691300, 0.090940),
(5, 'Male', 47.0, -0.352100, 2.775500, 0.090650),
(6, 'Male', 47.5, -0.352100, 2.860900, 0.090360),
(7, 'Male', 48.0, -0.352100, 2.948000, 0.090070),
(8, 'Male', 48.5, -0.352100, 3.037700, 0.089770),
(9, 'Male', 49.0, -0.352100, 3.130800, 0.089480),
(10, 'Male', 49.5, -0.352100, 3.227600, 0.089190),
(11, 'Male', 50.0, -0.352100, 3.327800, 0.088900),
(12, 'Male', 50.5, -0.352100, 3.431100, 0.088610),
(13, 'Male', 51.0, -0.352100, 3.537600, 0.088310),
(14, 'Male', 51.5, -0.352100, 3.647700, 0.088010),
(15, 'Male', 52.0, -0.352100, 3.762000, 0.087710),
(16, 'Male', 52.5, -0.352100, 3.881400, 0.087410),
(17, 'Male', 53.0, -0.352100, 4.006000, 0.087110),
(18, 'Male', 53.5, -0.352100, 4.135400, 0.086810),
(19, 'Male', 54.0, -0.352100, 4.269300, 0.086510),
(20, 'Male', 54.5, -0.352100, 4.406600, 0.086210),
(21, 'Male', 55.0, -0.352100, 4.546700, 0.085920),
(22, 'Male', 55.5, -0.352100, 4.689200, 0.085630),
(23, 'Male', 56.0, -0.352100, 4.833800, 0.085350),
(24, 'Male', 56.5, -0.352100, 4.979600, 0.085070),
(25, 'Male', 57.0, -0.352100, 5.125900, 0.084810),
(26, 'Male', 57.5, -0.352100, 5.272100, 0.084550),
(27, 'Male', 58.0, -0.352100, 5.418000, 0.084300),
(28, 'Male', 58.5, -0.352100, 5.563200, 0.084060),
(29, 'Male', 59.0, -0.352100, 5.707400, 0.083830),
(30, 'Male', 59.5, -0.352100, 5.850100, 0.083620),
(31, 'Male', 60.0, -0.352100, 5.990700, 0.083420),
(32, 'Male', 60.5, -0.352100, 6.128400, 0.083240),
(33, 'Male', 61.0, -0.352100, 6.263200, 0.083080),
(34, 'Male', 61.5, -0.352100, 6.395400, 0.082920),
(35, 'Male', 62.0, -0.352100, 6.525100, 0.082790),
(36, 'Male', 62.5, -0.352100, 6.652700, 0.082660),
(37, 'Male', 63.0, -0.352100, 6.778600, 0.082550),
(38, 'Male', 63.5, -0.352100, 6.902800, 0.082450),
(39, 'Male', 64.0, -0.352100, 7.025500, 0.082360),
(40, 'Male', 64.5, -0.352100, 7.146700, 0.082290),
(41, 'Male', 65.0, -0.352100, 7.432700, 0.082170),
(42, 'Male', 65.5, -0.352100, 7.550400, 0.082140),
(43, 'Male', 66.0, -0.352100, 7.667300, 0.082120),
(44, 'Male', 66.5, -0.352100, 7.783400, 0.082120),
(45, 'Male', 67.0, -0.352100, 7.898600, 0.082130),
(46, 'Male', 67.5, -0.352100, 8.013200, 0.082140),
(47, 'Male', 68.0, -0.352100, 8.127200, 0.082170),
(48, 'Male', 68.5, -0.352100, 8.241000, 0.082210),
(49, 'Male', 69.0, -0.352100, 8.354700, 0.082260),
(50, 'Male', 69.5, -0.352100, 8.468000, 0.082310),
(51, 'Male', 70.0, -0.352100, 8.580800, 0.082370),
(52, 'Male', 70.5, -0.352100, 8.692700, 0.082430),
(53, 'Male', 71.0, -0.352100, 8.803600, 0.082500),
(54, 'Male', 71.5, -0.352100, 8.913500, 0.082570),
(55, 'Male', 72.0, -0.352100, 9.022100, 0.082640),
(56, 'Male', 72.5, -0.352100, 9.129200, 0.082720),
(57, 'Male', 73.0, -0.352100, 9.234700, 0.082780),
(58, 'Male', 73.5, -0.352100, 9.339000, 0.082850),
(59, 'Male', 74.0, -0.352100, 9.442000, 0.082920),
(60, 'Male', 74.5, -0.352100, 9.543800, 0.082980),
(61, 'Male', 75.0, -0.352100, 9.644000, 0.083030),
(62, 'Male', 75.5, -0.352100, 9.742500, 0.083080),
(63, 'Male', 76.0, -0.352100, 9.839200, 0.083120),
(64, 'Male', 76.5, -0.352100, 9.934100, 0.083150),
(65, 'Male', 77.0, -0.352100, 10.027400, 0.083170),
(66, 'Male', 77.5, -0.352100, 10.119400, 0.083180),
(67, 'Male', 78.0, -0.352100, 10.210500, 0.083170),
(68, 'Male', 78.5, -0.352100, 10.301200, 0.083150),
(69, 'Male', 79.0, -0.352100, 10.392300, 0.083110),
(70, 'Male', 79.5, -0.352100, 10.484500, 0.083050),
(71, 'Male', 80.0, -0.352100, 10.578100, 0.082980),
(72, 'Male', 80.5, -0.352100, 10.673700, 0.082900),
(73, 'Male', 81.0, -0.352100, 10.771800, 0.082790),
(74, 'Male', 81.5, -0.352100, 10.872800, 0.082680),
(75, 'Male', 82.0, -0.352100, 10.977200, 0.082550),
(76, 'Male', 82.5, -0.352100, 11.085100, 0.082410),
(77, 'Male', 83.0, -0.352100, 11.196600, 0.082250),
(78, 'Male', 83.5, -0.352100, 11.311400, 0.082090),
(79, 'Male', 84.0, -0.352100, 11.429000, 0.081910),
(80, 'Male', 84.5, -0.352100, 11.549000, 0.081740),
(81, 'Male', 85.0, -0.352100, 11.670700, 0.081560),
(82, 'Male', 85.5, -0.352100, 11.793700, 0.081380),
(83, 'Male', 86.0, -0.352100, 11.917300, 0.081210),
(84, 'Male', 86.5, -0.352100, 12.041100, 0.081050),
(85, 'Male', 87.0, -0.352100, 12.164500, 0.080900),
(86, 'Male', 87.5, -0.352100, 12.287100, 0.080760),
(87, 'Male', 88.0, -0.352100, 12.408900, 0.080640),
(88, 'Male', 88.5, -0.352100, 12.529800, 0.080540),
(89, 'Male', 89.0, -0.352100, 12.649500, 0.080450),
(90, 'Male', 89.5, -0.352100, 12.768300, 0.080380),
(91, 'Male', 90.0, -0.352100, 12.886400, 0.080320),
(92, 'Male', 90.5, -0.352100, 13.003800, 0.080280),
(93, 'Male', 91.0, -0.352100, 13.120900, 0.080250),
(94, 'Male', 91.5, -0.352100, 13.237600, 0.080240),
(95, 'Male', 92.0, -0.352100, 13.354100, 0.080250),
(96, 'Male', 92.5, -0.352100, 13.470500, 0.080270),
(97, 'Male', 93.0, -0.352100, 13.587000, 0.080310),
(98, 'Male', 93.5, -0.352100, 13.704100, 0.080360),
(99, 'Male', 94.0, -0.352100, 13.821700, 0.080430),
(100, 'Male', 94.5, -0.352100, 13.940300, 0.080510),
(101, 'Male', 95.0, -0.352100, 14.060000, 0.080600),
(102, 'Male', 95.5, -0.352100, 14.181100, 0.080710),
(103, 'Male', 96.0, -0.352100, 14.303700, 0.080830),
(104, 'Male', 96.5, -0.352100, 14.428200, 0.080970),
(105, 'Male', 97.0, -0.352100, 14.554700, 0.081120),
(106, 'Male', 97.5, -0.352100, 14.683200, 0.081290),
(107, 'Male', 98.0, -0.352100, 14.814000, 0.081460),
(108, 'Male', 98.5, -0.352100, 14.946800, 0.081650),
(109, 'Male', 99.0, -0.352100, 15.081800, 0.081850),
(110, 'Male', 99.5, -0.352100, 15.218700, 0.082060),
(111, 'Male', 100.0, -0.352100, 15.357600, 0.082290),
(112, 'Male', 100.5, -0.352100, 15.498500, 0.082520),
(113, 'Male', 101.0, -0.352100, 15.641200, 0.082770),
(114, 'Male', 101.5, -0.352100, 15.785700, 0.083020),
(115, 'Male', 102.0, -0.352100, 15.932000, 0.083280),
(116, 'Male', 102.5, -0.352100, 16.080100, 0.083540),
(117, 'Male', 103.0, -0.352100, 16.229800, 0.083810),
(118, 'Male', 103.5, -0.352100, 16.381200, 0.084080),
(119, 'Male', 104.0, -0.352100, 16.534200, 0.084360),
(120, 'Male', 104.5, -0.352100, 16.688900, 0.084640),
(121, 'Male', 105.0, -0.352100, 16.845400, 0.084930),
(122, 'Male', 105.5, -0.352100, 17.003600, 0.085210),
(123, 'Male', 106.0, -0.352100, 17.163700, 0.085510),
(124, 'Male', 106.5, -0.352100, 17.325600, 0.085800),
(125, 'Male', 107.0, -0.352100, 17.489400, 0.086110),
(126, 'Male', 107.5, -0.352100, 17.655000, 0.086410),
(127, 'Male', 108.0, -0.352100, 17.822600, 0.086730),
(128, 'Male', 108.5, -0.352100, 17.992400, 0.087040),
(129, 'Male', 109.0, -0.352100, 18.164500, 0.087360),
(130, 'Male', 109.5, -0.352100, 18.339000, 0.087680),
(131, 'Male', 110.0, -0.352100, 18.515800, 0.088000),
(132, 'Male', 110.5, -0.352100, 18.694800, 0.088320),
(133, 'Male', 111.0, -0.352100, 18.875900, 0.088640),
(134, 'Male', 111.5, -0.352100, 19.059000, 0.088960),
(135, 'Male', 112.0, -0.352100, 19.243900, 0.089280),
(136, 'Male', 112.5, -0.352100, 19.430400, 0.089600),
(137, 'Male', 113.0, -0.352100, 19.618500, 0.089910),
(138, 'Male', 113.5, -0.352100, 19.808100, 0.090220),
(139, 'Male', 114.0, -0.352100, 19.999000, 0.090540),
(140, 'Male', 114.5, -0.352100, 20.191200, 0.090850),
(141, 'Male', 115.0, -0.352100, 20.384600, 0.091160),
(142, 'Male', 115.5, -0.352100, 20.578900, 0.091470),
(143, 'Male', 116.0, -0.352100, 20.774100, 0.091770),
(144, 'Male', 116.5, -0.352100, 20.970000, 0.092080),
(145, 'Male', 117.0, -0.352100, 21.166600, 0.092390),
(146, 'Male', 117.5, -0.352100, 21.363600, 0.092700),
(147, 'Male', 118.0, -0.352100, 21.561100, 0.093000),
(148, 'Male', 118.5, -0.352100, 21.758800, 0.093310),
(149, 'Male', 119.0, -0.352100, 21.956800, 0.093620),
(150, 'Male', 119.5, -0.352100, 22.154900, 0.093930),
(151, 'Male', 120.0, -0.352100, 22.353000, 0.094240),
(152, 'Female', 45.0, -0.383300, 2.460700, 0.090290),
(153, 'Female', 45.5, -0.383300, 2.545700, 0.090330),
(154, 'Female', 46.0, -0.383300, 2.630600, 0.090370),
(155, 'Female', 46.5, -0.383300, 2.715500, 0.090400),
(156, 'Female', 47.0, -0.383300, 2.800700, 0.090440),
(157, 'Female', 47.5, -0.383300, 2.886700, 0.090480),
(158, 'Female', 48.0, -0.383300, 2.974100, 0.090520),
(159, 'Female', 48.5, -0.383300, 3.063600, 0.090560),
(160, 'Female', 49.0, -0.383300, 3.156000, 0.090600),
(161, 'Female', 49.5, -0.383300, 3.252000, 0.090640),
(162, 'Female', 50.0, -0.383300, 3.351800, 0.090680),
(163, 'Female', 50.5, -0.383300, 3.455700, 0.090720),
(164, 'Female', 51.0, -0.383300, 3.563600, 0.090760),
(165, 'Female', 51.5, -0.383300, 3.675400, 0.090800),
(166, 'Female', 52.0, -0.383300, 3.791100, 0.090850),
(167, 'Female', 52.5, -0.383300, 3.910500, 0.090890),
(168, 'Female', 53.0, -0.383300, 4.033200, 0.090930),
(169, 'Female', 53.5, -0.383300, 4.159100, 0.090980),
(170, 'Female', 54.0, -0.383300, 4.287500, 0.091020),
(171, 'Female', 54.5, -0.383300, 4.417900, 0.091060),
(172, 'Female', 55.0, -0.383300, 4.549800, 0.091100),
(173, 'Female', 55.5, -0.383300, 4.682700, 0.091140),
(174, 'Female', 56.0, -0.383300, 4.816200, 0.091180),
(175, 'Female', 56.5, -0.383300, 4.950000, 0.091210),
(176, 'Female', 57.0, -0.383300, 5.083700, 0.091250),
(177, 'Female', 57.5, -0.383300, 5.217300, 0.091280),
(178, 'Female', 58.0, -0.383300, 5.350700, 0.091300),
(179, 'Female', 58.5, -0.383300, 5.483400, 0.091320),
(180, 'Female', 59.0, -0.383300, 5.615100, 0.091340),
(181, 'Female', 59.5, -0.383300, 5.745400, 0.091350),
(182, 'Female', 60.0, -0.383300, 5.874200, 0.091360),
(183, 'Female', 60.5, -0.383300, 6.001400, 0.091370),
(184, 'Female', 61.0, -0.383300, 6.127000, 0.091370),
(185, 'Female', 61.5, -0.383300, 6.251100, 0.091360),
(186, 'Female', 62.0, -0.383300, 6.373800, 0.091350),
(187, 'Female', 62.5, -0.383300, 6.494800, 0.091330),
(188, 'Female', 63.0, -0.383300, 6.614400, 0.091310),
(189, 'Female', 63.5, -0.383300, 6.732800, 0.091290),
(190, 'Female', 64.0, -0.383300, 6.850100, 0.091260),
(191, 'Female', 64.5, -0.383300, 6.966200, 0.091230),
(192, 'Female', 65.0, -0.383300, 7.240200, 0.091130),
(193, 'Female', 65.5, -0.383300, 7.352300, 0.091090),
(194, 'Female', 66.0, -0.383300, 7.463000, 0.091040),
(195, 'Female', 66.5, -0.383300, 7.572400, 0.090990),
(196, 'Female', 67.0, -0.383300, 7.680600, 0.090940),
(197, 'Female', 67.5, -0.383300, 7.787400, 0.090880),
(198, 'Female', 68.0, -0.383300, 7.893000, 0.090830),
(199, 'Female', 68.5, -0.383300, 7.997600, 0.090770),
(200, 'Female', 69.0, -0.383300, 8.101200, 0.090710),
(201, 'Female', 69.5, -0.383300, 8.203900, 0.090650),
(202, 'Female', 70.0, -0.383300, 8.305800, 0.090590),
(203, 'Female', 70.5, -0.383300, 8.407100, 0.090530),
(204, 'Female', 71.0, -0.383300, 8.507800, 0.090470),
(205, 'Female', 71.5, -0.383300, 8.607800, 0.090410),
(206, 'Female', 72.0, -0.383300, 8.707000, 0.090350),
(207, 'Female', 72.5, -0.383300, 8.805300, 0.090280),
(208, 'Female', 73.0, -0.383300, 8.902500, 0.090220),
(209, 'Female', 73.5, -0.383300, 8.998300, 0.090160),
(210, 'Female', 74.0, -0.383300, 9.092800, 0.090090),
(211, 'Female', 74.5, -0.383300, 9.186200, 0.090030),
(212, 'Female', 75.0, -0.383300, 9.278600, 0.089960),
(213, 'Female', 75.5, -0.383300, 9.370300, 0.089890),
(214, 'Female', 76.0, -0.383300, 9.461700, 0.089830),
(215, 'Female', 76.5, -0.383300, 9.553300, 0.089760),
(216, 'Female', 77.0, -0.383300, 9.645600, 0.089690),
(217, 'Female', 77.5, -0.383300, 9.739000, 0.089630),
(218, 'Female', 78.0, -0.383300, 9.833800, 0.089560),
(219, 'Female', 78.5, -0.383300, 9.930300, 0.089500),
(220, 'Female', 79.0, -0.383300, 10.028900, 0.089430),
(221, 'Female', 79.5, -0.383300, 10.129800, 0.089370),
(222, 'Female', 80.0, -0.383300, 10.233200, 0.089320),
(223, 'Female', 80.5, -0.383300, 10.339300, 0.089260),
(224, 'Female', 81.0, -0.383300, 10.447700, 0.089210),
(225, 'Female', 81.5, -0.383300, 10.558600, 0.089160),
(226, 'Female', 82.0, -0.383300, 10.671900, 0.089120),
(227, 'Female', 82.5, -0.383300, 10.787400, 0.089080),
(228, 'Female', 83.0, -0.383300, 10.905100, 0.089050),
(229, 'Female', 83.5, -0.383300, 11.024800, 0.089020),
(230, 'Female', 84.0, -0.383300, 11.146200, 0.088990),
(231, 'Female', 84.5, -0.383300, 11.269100, 0.088970),
(232, 'Female', 85.0, -0.383300, 11.393400, 0.088960),
(233, 'Female', 85.5, -0.383300, 11.518600, 0.088950),
(234, 'Female', 86.0, -0.383300, 11.644400, 0.088950),
(235, 'Female', 86.5, -0.383300, 11.770500, 0.088950),
(236, 'Female', 87.0, -0.383300, 11.896500, 0.088960),
(237, 'Female', 87.5, -0.383300, 12.022300, 0.088970),
(238, 'Female', 88.0, -0.383300, 12.147800, 0.088990),
(239, 'Female', 88.5, -0.383300, 12.272900, 0.089010),
(240, 'Female', 89.0, -0.383300, 12.397600, 0.089040),
(241, 'Female', 89.5, -0.383300, 12.522000, 0.089070),
(242, 'Female', 90.0, -0.383300, 12.646100, 0.089110),
(243, 'Female', 90.5, -0.383300, 12.770000, 0.089150),
(244, 'Female', 91.0, -0.383300, 12.893900, 0.089200),
(245, 'Female', 91.5, -0.383300, 13.017700, 0.089250),
(246, 'Female', 92.0, -0.383300, 13.141500, 0.089310),
(247, 'Female', 92.5, -0.383300, 13.265400, 0.089370),
(248, 'Female', 93.0, -0.383300, 13.389600, 0.089440),
(249, 'Female', 93.5, -0.383300, 13.514200, 0.089510),
(250, 'Female', 94.0, -0.383300, 13.639300, 0.089590),
(251, 'Female', 94.5, -0.383300, 13.765000, 0.089670),
(252, 'Female', 95.0, -0.383300, 13.891400, 0.089750),
(253, 'Female', 95.5, -0.383300, 14.018600, 0.089840),
(254, 'Female', 96.0, -0.383300, 14.146600, 0.089940),
(255, 'Female', 96.5, -0.383300, 14.275700, 0.090040),
(256, 'Female', 97.0, -0.383300, 14.405900, 0.090150),
(257, 'Female', 97.5, -0.383300, 14.537600, 0.090260),
(258, 'Female', 98.0, -0.383300, 14.671000, 0.090370),
(259, 'Female', 98.5, -0.383300, 14.806200, 0.090490),
(260, 'Female', 99.0, -0.383300, 14.943400, 0.090620),
(261, 'Female', 99.5, -0.383300, 15.082800, 0.090750),
(262, 'Female', 100.0, -0.383300, 15.224600, 0.090880),
(263, 'Female', 100.5, -0.383300, 15.368700, 0.091020),
(264, 'Female', 101.0, -0.383300, 15.515400, 0.091160),
(265, 'Female', 101.5, -0.383300, 15.664600, 0.091310),
(266, 'Female', 102.0, -0.383300, 15.816400, 0.091460),
(267, 'Female', 102.5, -0.383300, 15.970700, 0.091610),
(268, 'Female', 103.0, -0.383300, 16.127600, 0.091770),
(269, 'Female', 103.5, -0.383300, 16.287000, 0.091930),
(270, 'Female', 104.0, -0.383300, 16.448800, 0.092090),
(271, 'Female', 104.5, -0.383300, 16.613100, 0.092260),
(272, 'Female', 105.0, -0.383300, 16.780000, 0.092430),
(273, 'Female', 105.5, -0.383300, 16.949600, 0.092610),
(274, 'Female', 106.0, -0.383300, 17.122000, 0.092780),
(275, 'Female', 106.5, -0.383300, 17.297300, 0.092960),
(276, 'Female', 107.0, -0.383300, 17.475500, 0.093150),
(277, 'Female', 107.5, -0.383300, 17.656700, 0.093330),
(278, 'Female', 108.0, -0.383300, 17.840700, 0.093520),
(279, 'Female', 108.5, -0.383300, 18.027700, 0.093710),
(280, 'Female', 109.0, -0.383300, 18.217400, 0.093900),
(281, 'Female', 109.5, -0.383300, 18.409600, 0.094090),
(282, 'Female', 110.0, -0.383300, 18.604300, 0.094280),
(283, 'Female', 110.5, -0.383300, 18.801500, 0.094480),
(284, 'Female', 111.0, -0.383300, 19.000900, 0.094670),
(285, 'Female', 111.5, -0.383300, 19.202400, 0.094870),
(286, 'Female', 112.0, -0.383300, 19.406000, 0.095070),
(287, 'Female', 112.5, -0.383300, 19.611600, 0.095270),
(288, 'Female', 113.0, -0.383300, 19.819000, 0.095460),
(289, 'Female', 113.5, -0.383300, 20.028000, 0.095660),
(290, 'Female', 114.0, -0.383300, 20.238500, 0.095860),
(291, 'Female', 114.5, -0.383300, 20.450200, 0.096060),
(292, 'Female', 115.0, -0.383300, 20.662900, 0.096260),
(293, 'Female', 115.5, -0.383300, 20.876600, 0.096460),
(294, 'Female', 116.0, -0.383300, 21.090900, 0.096660),
(295, 'Female', 116.5, -0.383300, 21.305900, 0.096860),
(296, 'Female', 117.0, -0.383300, 21.521300, 0.097070),
(297, 'Female', 117.5, -0.383300, 21.737000, 0.097270),
(298, 'Female', 118.0, -0.383300, 21.952900, 0.097470),
(299, 'Female', 118.5, -0.383300, 22.169000, 0.097670),
(300, 'Female', 119.0, -0.383300, 22.385100, 0.097880),
(301, 'Female', 119.5, -0.383300, 22.601200, 0.098080),
(302, 'Female', 120.0, -0.383300, 22.817300, 0.098280);

--
-- Indexes for dumped tables
--

--
-- Indexes for table `appointments`
--
ALTER TABLE `appointments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_appt_child` (`child_id`),
  ADD KEY `fk_appt_parent` (`parent_id`),
  ADD KEY `fk_appt_user` (`nutritionist_id`),
  ADD KEY `idx_appt_schedule` (`scheduled_at`);

--
-- Indexes for table `audit_logs`
--
ALTER TABLE `audit_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_audit_user` (`user_id`),
  ADD KEY `idx_audit_created` (`created_at`);

--
-- Indexes for table `barangays`
--
ALTER TABLE `barangays`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_barangays_name` (`name`);

--
-- Indexes for table `children`
--
ALTER TABLE `children`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `child_code` (`child_code`),
  ADD KEY `idx_children_parent` (`parent_id`),
  ADD KEY `idx_children_barangay_id` (`barangay_id`);

--
-- Indexes for table `devices`
--
ALTER TABLE `devices`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_devices_barangay_id` (`barangay_id`),
  ADD UNIQUE KEY `device_code` (`device_code`);

--
-- Indexes for table `device_sensor_settings`
--
ALTER TABLE `device_sensor_settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_device_sensor_settings_device_code` (`device_code`),
  ADD KEY `idx_device_sensor_settings_device_code` (`device_code`);

--
-- Indexes for table `measurements`
--
ALTER TABLE `measurements`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_measurements_device` (`device_id`),
  ADD KEY `fk_measurements_user` (`recorded_by`),
  ADD KEY `idx_measurements_child` (`child_id`),
  ADD KEY `idx_measurements_date` (`measurement_date`),
  ADD KEY `idx_measurements_wfa_status` (`wfa_status`),
  ADD KEY `idx_measurements_hfa_status` (`hfa_status`),
  ADD KEY `idx_measurements_wfh_status` (`wfh_status`);

--
-- Indexes for table `measurement_sessions`
--
ALTER TABLE `measurement_sessions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_measurement_sessions_measurement` (`measurement_id`),
  ADD KEY `fk_measurement_sessions_child` (`child_id`),
  ADD KEY `idx_measurement_sessions_device_status` (`device_id`,`status`,`id`),
  ADD KEY `idx_measurement_sessions_device_created` (`device_id`,`created_at`);

--
-- Indexes for table `nutritionist_events`
--
ALTER TABLE `nutritionist_events`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_nutritionist_events_date` (`event_date`),
  ADD KEY `idx_nutritionist_events_type_date` (`event_type`,`event_date`),
  ADD KEY `idx_nutritionist_events_barangay_id` (`barangay_id`),
  ADD KEY `fk_nutritionist_events_user` (`nutritionist_id`);

--
-- Indexes for table `parents`
--
ALTER TABLE `parents`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_parents_barangay_id` (`barangay_id`),
  ADD UNIQUE KEY `email` (`email`);

--
-- Indexes for table `permissions`
--
ALTER TABLE `permissions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `code` (`code`);

--
-- Indexes for table `roles`
--
ALTER TABLE `roles`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `name` (`name`);

--
-- Indexes for table `role_permissions`
--
ALTER TABLE `role_permissions`
  ADD PRIMARY KEY (`role_id`,`permission_id`),
  ADD KEY `fk_rp_permission` (`permission_id`);

--
-- Indexes for table `system_settings`
--
ALTER TABLE `system_settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `setting_key` (`setting_key`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`),
  ADD UNIQUE KEY `username` (`username`),
  ADD KEY `idx_users_role` (`role_id`),
  ADD KEY `idx_users_barangay_id` (`barangay_id`);

--
-- Indexes for table `who_height_for_age`
--
ALTER TABLE `who_height_for_age`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_hfa` (`sex`,`age_months`);

--
-- Indexes for table `who_weight_for_age`
--
ALTER TABLE `who_weight_for_age`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_wfa` (`sex`,`age_months`);

--
-- Indexes for table `who_weight_for_height`
--
ALTER TABLE `who_weight_for_height`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_wfh` (`sex`,`height_cm`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `appointments`
--
ALTER TABLE `appointments`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `audit_logs`
--
ALTER TABLE `audit_logs`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=48;

--
-- AUTO_INCREMENT for table `barangays`
--
ALTER TABLE `barangays`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `children`
--
ALTER TABLE `children`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `devices`
--
ALTER TABLE `devices`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11128;

--
-- AUTO_INCREMENT for table `device_sensor_settings`
--
ALTER TABLE `device_sensor_settings`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `measurements`
--
ALTER TABLE `measurements`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `measurement_sessions`
--
ALTER TABLE `measurement_sessions`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=24;

--
-- AUTO_INCREMENT for table `nutritionist_events`
--
ALTER TABLE `nutritionist_events`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `parents`
--
ALTER TABLE `parents`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `permissions`
--
ALTER TABLE `permissions`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=16;

--
-- AUTO_INCREMENT for table `roles`
--
ALTER TABLE `roles`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `system_settings`
--
ALTER TABLE `system_settings`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `who_height_for_age`
--
ALTER TABLE `who_height_for_age`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=307;

--
-- AUTO_INCREMENT for table `who_weight_for_age`
--
ALTER TABLE `who_weight_for_age`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=307;

--
-- AUTO_INCREMENT for table `who_weight_for_height`
--
ALTER TABLE `who_weight_for_height`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=757;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `appointments`
--
ALTER TABLE `appointments`
  ADD CONSTRAINT `fk_appt_child` FOREIGN KEY (`child_id`) REFERENCES `children` (`id`),
  ADD CONSTRAINT `fk_appt_parent` FOREIGN KEY (`parent_id`) REFERENCES `parents` (`id`),
  ADD CONSTRAINT `fk_appt_user` FOREIGN KEY (`nutritionist_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `audit_logs`
--
ALTER TABLE `audit_logs`
  ADD CONSTRAINT `fk_audit_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `children`
--
ALTER TABLE `children`
  ADD CONSTRAINT `fk_children_barangay` FOREIGN KEY (`barangay_id`) REFERENCES `barangays` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_children_parent` FOREIGN KEY (`parent_id`) REFERENCES `parents` (`id`);

--
-- Constraints for table `devices`
--
ALTER TABLE `devices`
  ADD CONSTRAINT `fk_devices_barangay` FOREIGN KEY (`barangay_id`) REFERENCES `barangays` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `parents`
--
ALTER TABLE `parents`
  ADD CONSTRAINT `fk_parents_barangay` FOREIGN KEY (`barangay_id`) REFERENCES `barangays` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `measurements`
--
ALTER TABLE `measurements`
  ADD CONSTRAINT `fk_measurements_child` FOREIGN KEY (`child_id`) REFERENCES `children` (`id`),
  ADD CONSTRAINT `fk_measurements_device` FOREIGN KEY (`device_id`) REFERENCES `devices` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_measurements_user` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `measurement_sessions`
--
ALTER TABLE `measurement_sessions`
  ADD CONSTRAINT `fk_measurement_sessions_child` FOREIGN KEY (`child_id`) REFERENCES `children` (`id`),
  ADD CONSTRAINT `fk_measurement_sessions_device` FOREIGN KEY (`device_id`) REFERENCES `devices` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_measurement_sessions_measurement` FOREIGN KEY (`measurement_id`) REFERENCES `measurements` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `nutritionist_events`
--
ALTER TABLE `nutritionist_events`
  ADD CONSTRAINT `fk_nutritionist_events_barangay` FOREIGN KEY (`barangay_id`) REFERENCES `barangays` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_nutritionist_events_user` FOREIGN KEY (`nutritionist_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `role_permissions`
--
ALTER TABLE `role_permissions`
  ADD CONSTRAINT `fk_rp_permission` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_rp_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `users`
--
ALTER TABLE `users`
  ADD CONSTRAINT `fk_users_barangay` FOREIGN KEY (`barangay_id`) REFERENCES `barangays` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_users_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
