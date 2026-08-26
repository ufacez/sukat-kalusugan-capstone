-- ============================================================
-- SUKAT KALUSUGAN CLEAN BASELINE DATABASE
-- Generated from the supplied local database dump.
--
-- Operational/test data removed:
--   appointments, audit logs, children, devices, login attempts,
--   measurements, sessions, nutritionist events, parents,
--   password-reset tokens, and other user-generated records.
--
-- Kept:
--   schema, roles/permissions, system settings, WHO reference data,
--   exactly ONE admin account, and ONE initial barangay (id=1).
--
-- Barangay IDs are AUTO_INCREMENT. The next barangay will automatically
-- receive id=2, then 3, 4, and so on.
-- Child records inherit barangay_id from their selected parent in PHP;
-- children do not store address/purok fields.
-- ============================================================

-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Aug 24, 2026 at 09:54 AM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.0.30

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;


-- ============================================================
-- CLEAN RESET SECTION
-- This file is intended to replace the existing database contents.
-- It removes the current tables before recreating the clean baseline.
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS `appointments`;
DROP TABLE IF EXISTS `audit_logs`;
DROP TABLE IF EXISTS `barangays`;
DROP TABLE IF EXISTS `children`;
DROP TABLE IF EXISTS `devices`;
DROP TABLE IF EXISTS `kiosk_sensor_readings`;
DROP TABLE IF EXISTS `login_attempts`;
DROP TABLE IF EXISTS `measurements`;
DROP TABLE IF EXISTS `measurement_sessions`;
DROP TABLE IF EXISTS `nutritionist_events`;
DROP TABLE IF EXISTS `parents`;
DROP TABLE IF EXISTS `password_reset_tokens`;
DROP TABLE IF EXISTS `permissions`;
DROP TABLE IF EXISTS `roles`;
DROP TABLE IF EXISTS `role_permissions`;
DROP TABLE IF EXISTS `system_settings`;
DROP TABLE IF EXISTS `users`;
DROP TABLE IF EXISTS `who_height_for_age`;
DROP TABLE IF EXISTS `who_weight_for_age`;
DROP TABLE IF EXISTS `who_weight_for_height`;
DROP TABLE IF EXISTS `who_weight_for_length`;

SET FOREIGN_KEY_CHECKS = 1;


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

--
-- Dumping data for table `appointments`
--


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


-- --------------------------------------------------------

--
-- Table structure for table `barangays`
--

CREATE TABLE `barangays` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `city_municipality` varchar(150) DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_barangays_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `barangays`
--

INSERT INTO `barangays` (`id`, `name`, `city_municipality`, `status`, `created_at`, `updated_at`) VALUES
(1, 'Barangay 1', 'City of San Fernando', 'active', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP);


-- --------------------------------------------------------

--
-- Table structure for table `children`
--

CREATE TABLE `children` (
  `id` int(10) UNSIGNED NOT NULL,
  `child_code` varchar(20) NOT NULL,
  `first_name` varchar(100) NOT NULL,
  `middle_name` varchar(60) DEFAULT NULL,
  `last_name` varchar(100) NOT NULL,
  `birthdate` date NOT NULL,
  `sex` enum('Male','Female') NOT NULL,
  `barangay_id` int(10) UNSIGNED DEFAULT NULL,
  `is_ip` tinyint(1) NOT NULL DEFAULT 0,
  `has_disability` tinyint(1) NOT NULL DEFAULT 0,
  `parent_id` int(10) UNSIGNED NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `children`
--


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
  `hx711_calibration_factor` decimal(12,4) NOT NULL DEFAULT -20892.5000,
  `mounting_height_cm` decimal(6,2) NOT NULL DEFAULT 182.88,
  `status` enum('active','maintenance','offline') NOT NULL DEFAULT 'active',
  `last_seen_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `devices`
--


-- --------------------------------------------------------

--
-- Table structure for table `kiosk_sensor_readings`
--

CREATE TABLE `kiosk_sensor_readings` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `device_code` varchar(128) NOT NULL,
  `recorded_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `height_cm` decimal(6,2) DEFAULT NULL,
  `weight_kg` decimal(6,3) DEFAULT NULL,
  `raw_payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`raw_payload`)),
  `source_ip` varchar(45) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `login_attempts`
--

CREATE TABLE `login_attempts` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `identifier` varchar(150) NOT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `success` tinyint(1) NOT NULL DEFAULT 0,
  `attempted_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `login_attempts`
--


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
  `nutritional_status` enum('Normal','Underweight','Severely Underweight','Stunted','Severely Stunted','Wasted','Severely Wasted','Moderately Wasted','Overweight','Obese') DEFAULT NULL,
  `wfa_status` enum('SUW','UW','Normal','OW') DEFAULT NULL,
  `hfa_status` enum('SSt','St','Normal','T') DEFAULT NULL,
  `wfh_status` enum('SW','MW','Normal','OW','Ob') DEFAULT NULL,
  `is_flagged` tinyint(1) NOT NULL DEFAULT 0,
  `flag_reason` varchar(150) DEFAULT NULL,
  `data_quality_flag` tinyint(1) NOT NULL DEFAULT 0,
  `device_id` int(10) UNSIGNED DEFAULT NULL,
  `recorded_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `measurements`
--


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

--
-- Dumping data for table `nutritionist_events`
--


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


-- --------------------------------------------------------

--
-- Table structure for table `password_reset_tokens`
--

CREATE TABLE `password_reset_tokens` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `account_type` enum('staff','parent') NOT NULL,
  `account_id` int(10) UNSIGNED NOT NULL,
  `token_hash` char(64) NOT NULL,
  `expires_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `used_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `ip_address` varchar(45) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `password_reset_tokens`
--


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
  `barangay_id` int(10) UNSIGNED DEFAULT NULL,
  `role_id` int(10) UNSIGNED NOT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `last_login` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--


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


-- --------------------------------------------------------

--
-- Table structure for table `who_weight_for_length`
--

CREATE TABLE `who_weight_for_length` (
  `id` int(10) UNSIGNED NOT NULL,
  `sex` enum('Male','Female') NOT NULL,
  `height_cm` decimal(4,1) NOT NULL,
  `L` decimal(10,6) NOT NULL,
  `M` decimal(10,6) NOT NULL,
  `S` decimal(10,6) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `who_weight_for_length`
--


-- ============================================================
-- CLEAN BASELINE DATA
-- ============================================================
-- These are required reference/configuration records, not test
-- or transactional data.

INSERT INTO `roles` (`id`, `name`, `description`, `created_at`, `updated_at`) VALUES
(1, 'admin', 'System administrator', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
(2, 'nutritionist', 'Clinic nutritionist', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP);

INSERT INTO `permissions` (`id`, `code`, `description`, `created_at`) VALUES
(1, 'dashboard.view', 'View the admin dashboard', CURRENT_TIMESTAMP),
(2, 'users.view', 'View staff accounts', CURRENT_TIMESTAMP),
(3, 'users.create', 'Create staff accounts', CURRENT_TIMESTAMP),
(4, 'users.update', 'Update staff accounts', CURRENT_TIMESTAMP),
(5, 'users.delete', 'Delete staff accounts', CURRENT_TIMESTAMP),
(6, 'audit_logs.view', 'View audit logs', CURRENT_TIMESTAMP),
(7, 'roles_permissions.view', 'View role policies', CURRENT_TIMESTAMP),
(8, 'roles_permissions.update', 'Update role policies', CURRENT_TIMESTAMP),
(9, 'sensors.view', 'View device calibration data', CURRENT_TIMESTAMP),
(10, 'sensors.update', 'Update device calibration data', CURRENT_TIMESTAMP),
(11, 'settings.view', 'View system settings', CURRENT_TIMESTAMP),
(12, 'settings.update', 'Update system settings', CURRENT_TIMESTAMP),
(13, 'parents.view', 'View parent accounts and linked children', CURRENT_TIMESTAMP),
(14, 'barangays.view', 'View barangay master list', CURRENT_TIMESTAMP),
(15, 'barangays.manage', 'Create, update, and deactivate barangays', CURRENT_TIMESTAMP),
(16, 'parents.create', 'Create parent accounts', CURRENT_TIMESTAMP),
(17, 'parents.update', 'Update parent accounts', CURRENT_TIMESTAMP),
(18, 'parents.delete', 'Delete parent accounts', CURRENT_TIMESTAMP);

INSERT INTO `role_permissions` (`role_id`, `permission_id`) VALUES
(1, 1),(1, 2),(1, 3),(1, 4),(1, 5),(1, 6),(1, 7),(1, 8),(1, 9),
(1, 10),(1, 11),(1, 12),(1, 13),(1, 14),(1, 15),(1, 16),(1, 17),(1, 18),
(2, 14);

INSERT INTO `system_settings`
(`id`, `setting_key`, `setting_value`, `value_type`, `description`, `updated_at`) VALUES
(1, 'app_name', 'Sukat Kalusugan', 'string', 'Displayed application name', CURRENT_TIMESTAMP),
(2, 'clinic_name', 'Barangay Nutrition Center', 'string', 'Primary clinic or office name', CURRENT_TIMESTAMP),
(3, 'support_email', 'support@sukat.local', 'string', 'System support contact', CURRENT_TIMESTAMP),
(4, 'sync_interval_minutes', '15', 'number', 'Telemetry and sync interval', CURRENT_TIMESTAMP),
(5, 'maintenance_mode', '0', 'boolean', 'Toggle read-only maintenance mode', CURRENT_TIMESTAMP);

-- One and only one application user.
-- This preserves the existing local admin password hash.
INSERT INTO `users`
(`id`, `name`, `email`, `username`, `password_hash`, `phone`,
 `barangay_id`, `role_id`, `status`, `last_login`, `created_at`, `updated_at`) VALUES
(1, 'System Administrator', 'admin@sukat.local', 'admin',
 '$2y$10$QeU7O5MRHmHPRIcCxGxluewFYWG9XlLAjQekBTU/bTNufGHqPNTmC',
 NULL, NULL, 1, 'active', NULL, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP);

-- WHO Child Growth Standards are required by the application's
-- nutritional-status calculations. They are reference data, not
-- test children/measurements/accounts, so they are intentionally
-- retained from the supplied database dump.

INSERT INTO `who_height_for_age` (`id`, `sex`, `age_months`, `L`, `M`, `S`) VALUES
(1, 'Male', 0, 1.000000, 49.884200, 0.037950),
(2, 'Male', 1, 1.000000, 54.724306, 0.035568),
(3, 'Male', 2, 1.000000, 58.424838, 0.034235),
(4, 'Male', 3, 1.000000, 61.429144, 0.033281),
(5, 'Male', 4, 1.000000, 63.885900, 0.032575),
(6, 'Male', 5, 1.000000, 65.902563, 0.032038),
(7, 'Male', 6, 1.000000, 67.623587, 0.031654),
(8, 'Male', 7, 1.000000, 69.164531, 0.031390),
(9, 'Male', 8, 1.000000, 70.599400, 0.031240),
(10, 'Male', 9, 1.000000, 71.968644, 0.031170),
(11, 'Male', 10, 1.000000, 73.281125, 0.031180),
(12, 'Male', 11, 1.000000, 74.538806, 0.031250),
(13, 'Male', 12, 1.000000, 75.748850, 0.031372),
(14, 'Male', 13, 1.000000, 76.918588, 0.031540),
(15, 'Male', 14, 1.000000, 78.049675, 0.031741),
(16, 'Male', 15, 1.000000, 79.145769, 0.031966),
(17, 'Male', 16, 1.000000, 80.211300, 0.032220),
(18, 'Male', 17, 1.000000, 81.248744, 0.032494),
(19, 'Male', 18, 1.000000, 82.258700, 0.032789),
(20, 'Male', 19, 1.000000, 83.241769, 0.033103),
(21, 'Male', 20, 1.000000, 84.199625, 0.033418),
(22, 'Male', 21, 1.000000, 85.134781, 0.033754),
(23, 'Male', 22, 1.000000, 86.047763, 0.034096),
(24, 'Male', 23, 1.000000, 86.941012, 0.034451),
(25, 'Male', 24, 1.000000, 87.466050, 0.034935),
(26, 'Male', 25, 1.000000, 87.971969, 0.035419),
(27, 'Male', 26, 1.000000, 88.806525, 0.035764),
(28, 'Male', 27, 1.000000, 89.619750, 0.036098),
(29, 'Male', 28, 1.000000, 90.412000, 0.036422),
(30, 'Male', 29, 1.000000, 91.182788, 0.036737),
(31, 'Male', 30, 1.000000, 91.932738, 0.037041),
(32, 'Male', 31, 1.000000, 92.663131, 0.037326),
(33, 'Male', 32, 1.000000, 93.375300, 0.037610),
(34, 'Male', 33, 1.000000, 94.071088, 0.037874),
(35, 'Male', 34, 1.000000, 94.753125, 0.038119),
(36, 'Male', 35, 1.000000, 95.423612, 0.038360),
(37, 'Male', 36, 1.000000, 96.083525, 0.038580),
(38, 'Male', 37, 1.000000, 96.733775, 0.038792),
(39, 'Male', 38, 1.000000, 97.374863, 0.038996),
(40, 'Male', 39, 1.000000, 98.007294, 0.039190),
(41, 'Male', 40, 1.000000, 98.631050, 0.039370),
(42, 'Male', 41, 1.000000, 99.245850, 0.039540),
(43, 'Male', 42, 1.000000, 99.851525, 0.039704),
(44, 'Male', 43, 1.000000, 100.448544, 0.039860),
(45, 'Male', 44, 1.000000, 101.037400, 0.040020),
(46, 'Male', 45, 1.000000, 101.618662, 0.040167),
(47, 'Male', 46, 1.000000, 102.193337, 0.040310),
(48, 'Male', 47, 1.000000, 102.762462, 0.040450),
(49, 'Male', 48, 1.000000, 103.327300, 0.040590),
(50, 'Male', 49, 1.000000, 103.888650, 0.040730),
(51, 'Male', 50, 1.000000, 104.447313, 0.040860),
(52, 'Male', 51, 1.000000, 105.004119, 0.041000),
(53, 'Male', 52, 1.000000, 105.559550, 0.041130),
(54, 'Male', 53, 1.000000, 106.113812, 0.041262),
(55, 'Male', 54, 1.000000, 106.666812, 0.041390),
(56, 'Male', 55, 1.000000, 107.218738, 0.041520),
(57, 'Male', 56, 1.000000, 107.769750, 0.041650),
(58, 'Male', 57, 1.000000, 108.319769, 0.041770),
(59, 'Male', 58, 1.000000, 108.868850, 0.041900),
(60, 'Male', 59, 1.000000, 109.416925, 0.042020),
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

INSERT INTO `who_weight_for_age` (`id`, `sex`, `age_months`, `L`, `M`, `S`) VALUES
(1, 'Male', 0, 0.348700, 3.346400, 0.146020),
(2, 'Male', 1, 0.229731, 4.470919, 0.133951),
(3, 'Male', 2, 0.197012, 5.567562, 0.123854),
(4, 'Male', 3, 0.173812, 6.376219, 0.117267),
(5, 'Male', 4, 0.155250, 7.002300, 0.113157),
(6, 'Male', 5, 0.139506, 7.510531, 0.110799),
(7, 'Male', 6, 0.125750, 7.934063, 0.109578),
(8, 'Male', 7, 0.113375, 8.296994, 0.109019),
(9, 'Male', 8, 0.102100, 8.615100, 0.108820),
(10, 'Male', 9, 0.091719, 8.901338, 0.108810),
(11, 'Male', 10, 0.081988, 9.164912, 0.108904),
(12, 'Male', 11, 0.072956, 9.412119, 0.109058),
(13, 'Male', 12, 0.064425, 9.647875, 0.109253),
(14, 'Male', 13, 0.056363, 9.874919, 0.109487),
(15, 'Male', 14, 0.048662, 10.095287, 0.109761),
(16, 'Male', 15, 0.041287, 10.310837, 0.110076),
(17, 'Male', 16, 0.034300, 10.522800, 0.110410),
(18, 'Male', 17, 0.027512, 10.731875, 0.110784),
(19, 'Male', 18, 0.021025, 10.938462, 0.111197),
(20, 'Male', 19, 0.014837, 11.142994, 0.111636),
(21, 'Male', 20, 0.008750, 11.346150, 0.112115),
(22, 'Male', 21, 0.002863, 11.548637, 0.112614),
(23, 'Male', 22, -0.002825, 11.750325, 0.113142),
(24, 'Male', 23, -0.008313, 11.951412, 0.113691),
(25, 'Male', 24, -0.013650, 12.151500, 0.114260),
(26, 'Male', 25, -0.018888, 12.350194, 0.114849),
(27, 'Male', 26, -0.023975, 12.546600, 0.115444),
(28, 'Male', 27, -0.028881, 12.740119, 0.116036),
(29, 'Male', 28, -0.033750, 12.930350, 0.116635),
(30, 'Male', 29, -0.038469, 13.116894, 0.117224),
(31, 'Male', 30, -0.043112, 13.300038, 0.117813),
(32, 'Male', 31, -0.047613, 13.479819, 0.118391),
(33, 'Male', 32, -0.052000, 13.656700, 0.118960),
(34, 'Male', 33, -0.056388, 13.830950, 0.119529),
(35, 'Male', 34, -0.060675, 14.003100, 0.120079),
(36, 'Male', 35, -0.064831, 14.173650, 0.120626),
(37, 'Male', 36, -0.068875, 14.342900, 0.121158),
(38, 'Male', 37, -0.072919, 14.511250, 0.121684),
(39, 'Male', 38, -0.076863, 14.679037, 0.122202),
(40, 'Male', 39, -0.080806, 14.846544, 0.122711),
(41, 'Male', 40, -0.084550, 15.013950, 0.123220),
(42, 'Male', 41, -0.088294, 15.181256, 0.123729),
(43, 'Male', 42, -0.092037, 15.348562, 0.124247),
(44, 'Male', 43, -0.095681, 15.515769, 0.124776),
(45, 'Male', 44, -0.099250, 15.682875, 0.125315),
(46, 'Male', 45, -0.102769, 15.849681, 0.125864),
(47, 'Male', 46, -0.106313, 16.016288, 0.126422),
(48, 'Male', 47, -0.109756, 16.182694, 0.127001),
(49, 'Male', 48, -0.113100, 16.348900, 0.127590),
(50, 'Male', 49, -0.116444, 16.515006, 0.128189),
(51, 'Male', 50, -0.119788, 16.681112, 0.128808),
(52, 'Male', 51, -0.123031, 16.847119, 0.129426),
(53, 'Male', 52, -0.126275, 17.013150, 0.130055),
(54, 'Male', 53, -0.129419, 17.179231, 0.130684),
(55, 'Male', 54, -0.132562, 17.345237, 0.131323),
(56, 'Male', 55, -0.135606, 17.511044, 0.131962),
(57, 'Male', 56, -0.138750, 17.676750, 0.132610),
(58, 'Male', 57, -0.141694, 17.842163, 0.133249),
(59, 'Male', 58, -0.144737, 18.007325, 0.133898),
(60, 'Male', 59, -0.147681, 18.172187, 0.134536),
(61, 'Male', 60, -0.150625, 18.336550, 0.135175),
(62, 'Female', 0, 0.380900, 3.232200, 0.141710),
(63, 'Female', 1, 0.171387, 4.187306, 0.137240),
(64, 'Female', 2, 0.096162, 5.128175, 0.130001),
(65, 'Female', 3, 0.040200, 5.845831, 0.126192),
(66, 'Female', 4, -0.004975, 6.423700, 0.124022),
(67, 'Female', 5, -0.043006, 6.898544, 0.122734),
(68, 'Female', 6, -0.075525, 7.297025, 0.122044),
(69, 'Female', 7, -0.103950, 7.642263, 0.121780),
(70, 'Female', 8, -0.128800, 7.948650, 0.121805),
(71, 'Female', 9, -0.150663, 8.225356, 0.121989),
(72, 'Female', 10, -0.170025, 8.479938, 0.122224),
(73, 'Female', 11, -0.187188, 8.719256, 0.122468),
(74, 'Female', 12, -0.202325, 8.948050, 0.122673),
(75, 'Female', 13, -0.215844, 9.169950, 0.122830),
(76, 'Female', 14, -0.227750, 9.386988, 0.122940),
(77, 'Female', 15, -0.238369, 9.600737, 0.122990),
(78, 'Female', 16, -0.247800, 9.812400, 0.123030),
(79, 'Female', 17, -0.256231, 10.022619, 0.123054),
(80, 'Female', 18, -0.263675, 10.231537, 0.123090),
(81, 'Female', 19, -0.270294, 10.439325, 0.123150),
(82, 'Female', 20, -0.276250, 10.646400, 0.123237),
(83, 'Female', 21, -0.281438, 10.853375, 0.123352),
(84, 'Female', 22, -0.286162, 11.060750, 0.123506),
(85, 'Female', 23, -0.290312, 11.268831, 0.123690),
(86, 'Female', 24, -0.294100, 11.477500, 0.123895),
(87, 'Female', 25, -0.297494, 11.686369, 0.124139),
(88, 'Female', 26, -0.300538, 11.894750, 0.124410),
(89, 'Female', 27, -0.303281, 12.101544, 0.124718),
(90, 'Female', 28, -0.305725, 12.305875, 0.125062),
(91, 'Female', 29, -0.308000, 12.507269, 0.125447),
(92, 'Female', 30, -0.310100, 12.705500, 0.125871),
(93, 'Female', 31, -0.312000, 12.900544, 0.126331),
(94, 'Female', 32, -0.313800, 13.093000, 0.126830),
(95, 'Female', 33, -0.315500, 13.283656, 0.127369),
(96, 'Female', 34, -0.317100, 13.473125, 0.127938),
(97, 'Female', 35, -0.318631, 13.661838, 0.128546),
(98, 'Female', 36, -0.320100, 13.850250, 0.129195),
(99, 'Female', 37, -0.321600, 14.038462, 0.129874),
(100, 'Female', 38, -0.322963, 14.226475, 0.130593),
(101, 'Female', 39, -0.324306, 14.413988, 0.131342),
(102, 'Female', 40, -0.325700, 14.601000, 0.132125),
(103, 'Female', 41, -0.327000, 14.787319, 0.132938),
(104, 'Female', 42, -0.328338, 14.972687, 0.133761),
(105, 'Female', 43, -0.329600, 15.157275, 0.134604),
(106, 'Female', 44, -0.330925, 15.341000, 0.135447),
(107, 'Female', 45, -0.332200, 15.524025, 0.136301),
(108, 'Female', 46, -0.333512, 15.706350, 0.137154),
(109, 'Female', 47, -0.334800, 15.888219, 0.138001),
(110, 'Female', 48, -0.336100, 16.069700, 0.138840),
(111, 'Female', 49, -0.337400, 16.251081, 0.139683),
(112, 'Female', 50, -0.338700, 16.432250, 0.140506),
(113, 'Female', 51, -0.340031, 16.613275, 0.141326),
(114, 'Female', 52, -0.341375, 16.794200, 0.142132),
(115, 'Female', 53, -0.342700, 16.974806, 0.142926),
(116, 'Female', 54, -0.344000, 17.155088, 0.143709),
(117, 'Female', 55, -0.345306, 17.334763, 0.144482),
(118, 'Female', 56, -0.346650, 17.513650, 0.145250),
(119, 'Female', 57, -0.347900, 17.691631, 0.145999),
(120, 'Female', 58, -0.349237, 17.868575, 0.146748),
(121, 'Female', 59, -0.350500, 18.044512, 0.147486),
(122, 'Female', 60, -0.351800, 18.219325, 0.148215);

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
(302, 'Female', 120.0, -0.383300, 22.817300, 0.098280),
(909, 'Male', 45.1, -0.352100, 2.457700, 0.091760),
(910, 'Male', 45.2, -0.352100, 2.474400, 0.091700),
(911, 'Male', 45.3, -0.352100, 2.491100, 0.091640),
(912, 'Male', 45.4, -0.352100, 2.507800, 0.091590),
(913, 'Male', 45.6, -0.352100, 2.541100, 0.091470),
(914, 'Male', 45.7, -0.352100, 2.557800, 0.091410),
(915, 'Male', 45.8, -0.352100, 2.574400, 0.091350),
(916, 'Male', 45.9, -0.352100, 2.591100, 0.091290),
(917, 'Male', 46.1, -0.352100, 2.624400, 0.091180),
(918, 'Male', 46.2, -0.352100, 2.641100, 0.091120),
(919, 'Male', 46.3, -0.352100, 2.657800, 0.091060),
(920, 'Male', 46.4, -0.352100, 2.674500, 0.091000),
(921, 'Male', 46.6, -0.352100, 2.708100, 0.090880),
(922, 'Male', 46.7, -0.352100, 2.724900, 0.090830),
(923, 'Male', 46.8, -0.352100, 2.741700, 0.090770),
(924, 'Male', 46.9, -0.352100, 2.758600, 0.090710),
(925, 'Male', 47.1, -0.352100, 2.792500, 0.090590),
(926, 'Male', 47.2, -0.352100, 2.809500, 0.090530),
(927, 'Male', 47.3, -0.352100, 2.826600, 0.090470),
(928, 'Male', 47.4, -0.352100, 2.843700, 0.090420),
(929, 'Male', 47.6, -0.352100, 2.878200, 0.090300),
(930, 'Male', 47.7, -0.352100, 2.895500, 0.090240),
(931, 'Male', 47.8, -0.352100, 2.912900, 0.090180),
(932, 'Male', 47.9, -0.352100, 2.930400, 0.090120),
(933, 'Male', 48.1, -0.352100, 2.965700, 0.090010),
(934, 'Male', 48.2, -0.352100, 2.983500, 0.089950),
(935, 'Male', 48.3, -0.352100, 3.001400, 0.089890),
(936, 'Male', 48.4, -0.352100, 3.019500, 0.089830),
(937, 'Male', 48.6, -0.352100, 3.056000, 0.089720),
(938, 'Male', 48.7, -0.352100, 3.074500, 0.089660),
(939, 'Male', 48.8, -0.352100, 3.093100, 0.089600),
(940, 'Male', 48.9, -0.352100, 3.111900, 0.089540),
(941, 'Male', 49.1, -0.352100, 3.149900, 0.089430),
(942, 'Male', 49.2, -0.352100, 3.169100, 0.089370),
(943, 'Male', 49.3, -0.352100, 3.188400, 0.089310),
(944, 'Male', 49.4, -0.352100, 3.207900, 0.089250),
(945, 'Male', 49.6, -0.352100, 3.247300, 0.089130),
(946, 'Male', 49.7, -0.352100, 3.267200, 0.089080),
(947, 'Male', 49.8, -0.352100, 3.287300, 0.089020),
(948, 'Male', 49.9, -0.352100, 3.307500, 0.088960),
(949, 'Male', 50.1, -0.352100, 3.348200, 0.088840),
(950, 'Male', 50.2, -0.352100, 3.368800, 0.088780),
(951, 'Male', 50.3, -0.352100, 3.389400, 0.088720),
(952, 'Male', 50.4, -0.352100, 3.410200, 0.088670),
(953, 'Male', 50.6, -0.352100, 3.452200, 0.088550),
(954, 'Male', 50.7, -0.352100, 3.473300, 0.088490),
(955, 'Male', 50.8, -0.352100, 3.494600, 0.088430),
(956, 'Male', 50.9, -0.352100, 3.516100, 0.088370),
(957, 'Male', 51.1, -0.352100, 3.559300, 0.088250),
(958, 'Male', 51.2, -0.352100, 3.581200, 0.088190),
(959, 'Male', 51.3, -0.352100, 3.603200, 0.088130),
(960, 'Male', 51.4, -0.352100, 3.625400, 0.088070),
(961, 'Male', 51.6, -0.352100, 3.670200, 0.087950),
(962, 'Male', 51.7, -0.352100, 3.692900, 0.087890),
(963, 'Male', 51.8, -0.352100, 3.715700, 0.087830),
(964, 'Male', 51.9, -0.352100, 3.738800, 0.087770),
(965, 'Male', 52.1, -0.352100, 3.785500, 0.087650),
(966, 'Male', 52.2, -0.352100, 3.809200, 0.087590),
(967, 'Male', 52.3, -0.352100, 3.833000, 0.087530),
(968, 'Male', 52.4, -0.352100, 3.857100, 0.087470),
(969, 'Male', 52.6, -0.352100, 3.905900, 0.087350),
(970, 'Male', 52.7, -0.352100, 3.930600, 0.087290),
(971, 'Male', 52.8, -0.352100, 3.955500, 0.087230),
(972, 'Male', 52.9, -0.352100, 3.980600, 0.087170),
(973, 'Male', 53.1, -0.352100, 4.031500, 0.087050),
(974, 'Male', 53.2, -0.352100, 4.057200, 0.086990),
(975, 'Male', 53.3, -0.352100, 4.083100, 0.086930),
(976, 'Male', 53.4, -0.352100, 4.109200, 0.086870),
(977, 'Male', 53.6, -0.352100, 4.161900, 0.086750),
(978, 'Male', 53.7, -0.352100, 4.188500, 0.086690),
(979, 'Male', 53.8, -0.352100, 4.215300, 0.086630),
(980, 'Male', 53.9, -0.352100, 4.242200, 0.086570),
(981, 'Male', 54.1, -0.352100, 4.296500, 0.086450),
(982, 'Male', 54.2, -0.352100, 4.323900, 0.086390),
(983, 'Male', 54.3, -0.352100, 4.351300, 0.086330),
(984, 'Male', 54.4, -0.352100, 4.378900, 0.086270),
(985, 'Male', 54.6, -0.352100, 4.434400, 0.086150),
(986, 'Male', 54.7, -0.352100, 4.462300, 0.086100),
(987, 'Male', 54.8, -0.352100, 4.490300, 0.086040),
(988, 'Male', 54.9, -0.352100, 4.518500, 0.085980),
(989, 'Male', 55.1, -0.352100, 4.575000, 0.085860),
(990, 'Male', 55.2, -0.352100, 4.603400, 0.085800),
(991, 'Male', 55.3, -0.352100, 4.631900, 0.085750),
(992, 'Male', 55.4, -0.352100, 4.660500, 0.085690),
(993, 'Male', 55.6, -0.352100, 4.718000, 0.085580),
(994, 'Male', 55.7, -0.352100, 4.746900, 0.085520),
(995, 'Male', 55.8, -0.352100, 4.775800, 0.085460),
(996, 'Male', 55.9, -0.352100, 4.804800, 0.085410),
(997, 'Male', 56.1, -0.352100, 4.862900, 0.085290),
(998, 'Male', 56.2, -0.352100, 4.892000, 0.085240),
(999, 'Male', 56.3, -0.352100, 4.921200, 0.085180),
(1000, 'Male', 56.4, -0.352100, 4.950400, 0.085130),
(1001, 'Male', 56.6, -0.352100, 5.008800, 0.085020),
(1002, 'Male', 56.7, -0.352100, 5.038100, 0.084970),
(1003, 'Male', 56.8, -0.352100, 5.067300, 0.084910),
(1004, 'Male', 56.9, -0.352100, 5.096600, 0.084860),
(1005, 'Male', 57.1, -0.352100, 5.155100, 0.084750),
(1006, 'Male', 57.2, -0.352100, 5.184400, 0.084700),
(1007, 'Male', 57.3, -0.352100, 5.213700, 0.084650),
(1008, 'Male', 57.4, -0.352100, 5.242900, 0.084600),
(1009, 'Male', 57.6, -0.352100, 5.301400, 0.084490),
(1010, 'Male', 57.7, -0.352100, 5.330600, 0.084440),
(1011, 'Male', 57.8, -0.352100, 5.359800, 0.084390),
(1012, 'Male', 57.9, -0.352100, 5.388900, 0.084340),
(1013, 'Male', 58.1, -0.352100, 5.447100, 0.084250),
(1014, 'Male', 58.2, -0.352100, 5.476200, 0.084200),
(1015, 'Male', 58.3, -0.352100, 5.505300, 0.084150),
(1016, 'Male', 58.4, -0.352100, 5.534300, 0.084100),
(1017, 'Male', 58.6, -0.352100, 5.592200, 0.084010),
(1018, 'Male', 58.7, -0.352100, 5.621000, 0.083970),
(1019, 'Male', 58.8, -0.352100, 5.649900, 0.083920),
(1020, 'Male', 58.9, -0.352100, 5.678700, 0.083880),
(1021, 'Male', 59.1, -0.352100, 5.736100, 0.083790),
(1022, 'Male', 59.2, -0.352100, 5.764700, 0.083750),
(1023, 'Male', 59.3, -0.352100, 5.793300, 0.083700),
(1024, 'Male', 59.4, -0.352100, 5.821700, 0.083660),
(1025, 'Male', 59.6, -0.352100, 5.878400, 0.083580),
(1026, 'Male', 59.7, -0.352100, 5.906700, 0.083540),
(1027, 'Male', 59.8, -0.352100, 5.934800, 0.083500),
(1028, 'Male', 59.9, -0.352100, 5.962800, 0.083460),
(1029, 'Male', 60.1, -0.352100, 6.018500, 0.083390),
(1030, 'Male', 60.2, -0.352100, 6.046100, 0.083350),
(1031, 'Male', 60.3, -0.352100, 6.073700, 0.083310),
(1032, 'Male', 60.4, -0.352100, 6.101100, 0.083280),
(1033, 'Male', 60.6, -0.352100, 6.155600, 0.083210),
(1034, 'Male', 60.7, -0.352100, 6.182700, 0.083170),
(1035, 'Male', 60.8, -0.352100, 6.209600, 0.083140),
(1036, 'Male', 60.9, -0.352100, 6.236500, 0.083110),
(1037, 'Male', 61.1, -0.352100, 6.289900, 0.083040),
(1038, 'Male', 61.2, -0.352100, 6.316400, 0.083010),
(1039, 'Male', 61.3, -0.352100, 6.342800, 0.082980),
(1040, 'Male', 61.4, -0.352100, 6.369200, 0.082950),
(1041, 'Male', 61.6, -0.352100, 6.421500, 0.082900),
(1042, 'Male', 61.7, -0.352100, 6.447500, 0.082870),
(1043, 'Male', 61.8, -0.352100, 6.473500, 0.082840),
(1044, 'Male', 61.9, -0.352100, 6.499300, 0.082810),
(1045, 'Male', 62.1, -0.352100, 6.550800, 0.082760),
(1046, 'Male', 62.2, -0.352100, 6.576400, 0.082730),
(1047, 'Male', 62.3, -0.352100, 6.601900, 0.082710),
(1048, 'Male', 62.4, -0.352100, 6.627300, 0.082680),
(1049, 'Male', 62.6, -0.352100, 6.678000, 0.082640),
(1050, 'Male', 62.7, -0.352100, 6.703300, 0.082610),
(1051, 'Male', 62.8, -0.352100, 6.728400, 0.082590),
(1052, 'Male', 62.9, -0.352100, 6.753500, 0.082570),
(1053, 'Male', 63.1, -0.352100, 6.803500, 0.082530),
(1054, 'Male', 63.2, -0.352100, 6.828500, 0.082510),
(1055, 'Male', 63.3, -0.352100, 6.853300, 0.082490),
(1056, 'Male', 63.4, -0.352100, 6.878100, 0.082470),
(1057, 'Male', 63.6, -0.352100, 6.927500, 0.082430),
(1058, 'Male', 63.7, -0.352100, 6.952100, 0.082410),
(1059, 'Male', 63.8, -0.352100, 6.976600, 0.082400),
(1060, 'Male', 63.9, -0.352100, 7.001100, 0.082380),
(1061, 'Male', 64.1, -0.352100, 7.049900, 0.082350),
(1062, 'Male', 64.2, -0.352100, 7.074200, 0.082330),
(1063, 'Male', 64.3, -0.352100, 7.098400, 0.082320),
(1064, 'Male', 64.4, -0.352100, 7.122600, 0.082300),
(1065, 'Male', 64.6, -0.352100, 7.170800, 0.082280),
(1066, 'Male', 64.7, -0.352100, 7.194800, 0.082270),
(1067, 'Male', 64.8, -0.352100, 7.218800, 0.082250),
(1068, 'Male', 64.9, -0.352100, 7.242700, 0.082240),
(1069, 'Male', 65.1, -0.352100, 7.456300, 0.082160),
(1070, 'Male', 65.2, -0.352100, 7.479900, 0.082160),
(1071, 'Male', 65.3, -0.352100, 7.503400, 0.082150),
(1072, 'Male', 65.4, -0.352100, 7.526900, 0.082140),
(1073, 'Male', 65.6, -0.352100, 7.573800, 0.082140),
(1074, 'Male', 65.7, -0.352100, 7.597300, 0.082130),
(1075, 'Male', 65.8, -0.352100, 7.620600, 0.082130),
(1076, 'Male', 65.9, -0.352100, 7.644000, 0.082130),
(1077, 'Male', 66.1, -0.352100, 7.690600, 0.082120),
(1078, 'Male', 66.2, -0.352100, 7.713800, 0.082120),
(1079, 'Male', 66.3, -0.352100, 7.737000, 0.082120),
(1080, 'Male', 66.4, -0.352100, 7.760200, 0.082120),
(1081, 'Male', 66.6, -0.352100, 7.806500, 0.082120),
(1082, 'Male', 66.7, -0.352100, 7.829600, 0.082120),
(1083, 'Male', 66.8, -0.352100, 7.852600, 0.082120),
(1084, 'Male', 66.9, -0.352100, 7.875700, 0.082120),
(1085, 'Male', 67.1, -0.352100, 7.921600, 0.082130),
(1086, 'Male', 67.2, -0.352100, 7.944500, 0.082130),
(1087, 'Male', 67.3, -0.352100, 7.967400, 0.082140),
(1088, 'Male', 67.4, -0.352100, 7.990300, 0.082140),
(1089, 'Male', 67.6, -0.352100, 8.036000, 0.082150),
(1090, 'Male', 67.7, -0.352100, 8.058800, 0.082150),
(1091, 'Male', 67.8, -0.352100, 8.081600, 0.082160),
(1092, 'Male', 67.9, -0.352100, 8.104400, 0.082170),
(1093, 'Male', 68.1, -0.352100, 8.150000, 0.082180),
(1094, 'Male', 68.2, -0.352100, 8.172700, 0.082190),
(1095, 'Male', 68.3, -0.352100, 8.195500, 0.082190),
(1096, 'Male', 68.4, -0.352100, 8.218300, 0.082200),
(1097, 'Male', 68.6, -0.352100, 8.263800, 0.082220),
(1098, 'Male', 68.7, -0.352100, 8.286500, 0.082230),
(1099, 'Male', 68.8, -0.352100, 8.309200, 0.082240),
(1100, 'Male', 68.9, -0.352100, 8.332000, 0.082250),
(1101, 'Male', 69.1, -0.352100, 8.377400, 0.082270),
(1102, 'Male', 69.2, -0.352100, 8.400100, 0.082280),
(1103, 'Male', 69.3, -0.352100, 8.422700, 0.082290),
(1104, 'Male', 69.4, -0.352100, 8.445400, 0.082300),
(1105, 'Male', 69.6, -0.352100, 8.490600, 0.082320),
(1106, 'Male', 69.7, -0.352100, 8.513200, 0.082330),
(1107, 'Male', 69.8, -0.352100, 8.535800, 0.082350),
(1108, 'Male', 69.9, -0.352100, 8.558300, 0.082360),
(1109, 'Male', 70.1, -0.352100, 8.603200, 0.082380),
(1110, 'Male', 70.2, -0.352100, 8.625700, 0.082400),
(1111, 'Male', 70.3, -0.352100, 8.648000, 0.082410),
(1112, 'Male', 70.4, -0.352100, 8.670400, 0.082420),
(1113, 'Male', 70.6, -0.352100, 8.715000, 0.082450),
(1114, 'Male', 70.7, -0.352100, 8.737200, 0.082460),
(1115, 'Male', 70.8, -0.352100, 8.759400, 0.082480),
(1116, 'Male', 70.9, -0.352100, 8.781500, 0.082490),
(1117, 'Male', 71.1, -0.352100, 8.825700, 0.082520),
(1118, 'Male', 71.2, -0.352100, 8.847700, 0.082530),
(1119, 'Male', 71.3, -0.352100, 8.869700, 0.082540),
(1120, 'Male', 71.4, -0.352100, 8.891600, 0.082560),
(1121, 'Male', 71.6, -0.352100, 8.935300, 0.082590),
(1122, 'Male', 71.7, -0.352100, 8.957100, 0.082600),
(1123, 'Male', 71.8, -0.352100, 8.978800, 0.082620),
(1124, 'Male', 71.9, -0.352100, 9.000500, 0.082630),
(1125, 'Male', 72.1, -0.352100, 9.043600, 0.082660),
(1126, 'Male', 72.2, -0.352100, 9.065100, 0.082670),
(1127, 'Male', 72.3, -0.352100, 9.086500, 0.082690),
(1128, 'Male', 72.4, -0.352100, 9.107900, 0.082700),
(1129, 'Male', 72.6, -0.352100, 9.150400, 0.082730),
(1130, 'Male', 72.7, -0.352100, 9.171600, 0.082740),
(1131, 'Male', 72.8, -0.352100, 9.192700, 0.082760),
(1132, 'Male', 72.9, -0.352100, 9.213700, 0.082770),
(1133, 'Male', 73.1, -0.352100, 9.255700, 0.082800),
(1134, 'Male', 73.2, -0.352100, 9.276600, 0.082810),
(1135, 'Male', 73.3, -0.352100, 9.297400, 0.082830),
(1136, 'Male', 73.4, -0.352100, 9.318200, 0.082840),
(1137, 'Male', 73.6, -0.352100, 9.359700, 0.082870),
(1138, 'Male', 73.7, -0.352100, 9.380300, 0.082880),
(1139, 'Male', 73.8, -0.352100, 9.401000, 0.082890),
(1140, 'Male', 73.9, -0.352100, 9.421500, 0.082900),
(1141, 'Male', 74.1, -0.352100, 9.462500, 0.082930),
(1142, 'Male', 74.2, -0.352100, 9.482900, 0.082940),
(1143, 'Male', 74.3, -0.352100, 9.503200, 0.082950),
(1144, 'Male', 74.4, -0.352100, 9.523500, 0.082970),
(1145, 'Male', 74.6, -0.352100, 9.563900, 0.082990),
(1146, 'Male', 74.7, -0.352100, 9.584100, 0.083000),
(1147, 'Male', 74.8, -0.352100, 9.604100, 0.083010),
(1148, 'Male', 74.9, -0.352100, 9.624100, 0.083020),
(1149, 'Male', 75.1, -0.352100, 9.663900, 0.083050),
(1150, 'Male', 75.2, -0.352100, 9.683600, 0.083060),
(1151, 'Male', 75.3, -0.352100, 9.703300, 0.083070),
(1152, 'Male', 75.4, -0.352100, 9.723000, 0.083070),
(1153, 'Male', 75.6, -0.352100, 9.762000, 0.083090),
(1154, 'Male', 75.7, -0.352100, 9.781400, 0.083100),
(1155, 'Male', 75.8, -0.352100, 9.800700, 0.083110),
(1156, 'Male', 75.9, -0.352100, 9.820000, 0.083120),
(1157, 'Male', 76.1, -0.352100, 9.858300, 0.083130),
(1158, 'Male', 76.2, -0.352100, 9.877300, 0.083140),
(1159, 'Male', 76.3, -0.352100, 9.896300, 0.083140),
(1160, 'Male', 76.4, -0.352100, 9.915200, 0.083150),
(1161, 'Male', 76.6, -0.352100, 9.952800, 0.083160),
(1162, 'Male', 76.7, -0.352100, 9.971600, 0.083160),
(1163, 'Male', 76.8, -0.352100, 9.990200, 0.083170),
(1164, 'Male', 76.9, -0.352100, 10.008800, 0.083170),
(1165, 'Male', 77.1, -0.352100, 10.045900, 0.083180),
(1166, 'Male', 77.2, -0.352100, 10.064300, 0.083180),
(1167, 'Male', 77.3, -0.352100, 10.082700, 0.083180),
(1168, 'Male', 77.4, -0.352100, 10.101100, 0.083180),
(1169, 'Male', 77.6, -0.352100, 10.137700, 0.083180),
(1170, 'Male', 77.7, -0.352100, 10.155900, 0.083180),
(1171, 'Male', 77.8, -0.352100, 10.174100, 0.083180),
(1172, 'Male', 77.9, -0.352100, 10.192300, 0.083170),
(1173, 'Male', 78.1, -0.352100, 10.228600, 0.083170),
(1174, 'Male', 78.2, -0.352100, 10.246800, 0.083160),
(1175, 'Male', 78.3, -0.352100, 10.264900, 0.083160),
(1176, 'Male', 78.4, -0.352100, 10.283100, 0.083150),
(1177, 'Male', 78.6, -0.352100, 10.319400, 0.083140),
(1178, 'Male', 78.7, -0.352100, 10.337600, 0.083130),
(1179, 'Male', 78.8, -0.352100, 10.355800, 0.083130),
(1180, 'Male', 78.9, -0.352100, 10.374100, 0.083120),
(1181, 'Male', 79.1, -0.352100, 10.410700, 0.083100),
(1182, 'Male', 79.2, -0.352100, 10.429100, 0.083090),
(1183, 'Male', 79.3, -0.352100, 10.447500, 0.083080),
(1184, 'Male', 79.4, -0.352100, 10.466000, 0.083070),
(1185, 'Male', 79.6, -0.352100, 10.503100, 0.083040),
(1186, 'Male', 79.7, -0.352100, 10.521700, 0.083030),
(1187, 'Male', 79.8, -0.352100, 10.540500, 0.083010),
(1188, 'Male', 79.9, -0.352100, 10.559200, 0.083000),
(1189, 'Male', 80.1, -0.352100, 10.597000, 0.082970),
(1190, 'Male', 80.2, -0.352100, 10.616100, 0.082950),
(1191, 'Male', 80.3, -0.352100, 10.635200, 0.082930),
(1192, 'Male', 80.4, -0.352100, 10.654400, 0.082910),
(1193, 'Male', 80.6, -0.352100, 10.693100, 0.082880),
(1194, 'Male', 80.7, -0.352100, 10.712600, 0.082860),
(1195, 'Male', 80.8, -0.352100, 10.732200, 0.082840),
(1196, 'Male', 80.9, -0.352100, 10.752000, 0.082820),
(1197, 'Male', 81.1, -0.352100, 10.791800, 0.082770),
(1198, 'Male', 81.2, -0.352100, 10.811900, 0.082750),
(1199, 'Male', 81.3, -0.352100, 10.832100, 0.082730),
(1200, 'Male', 81.4, -0.352100, 10.852400, 0.082700),
(1201, 'Male', 81.6, -0.352100, 10.893400, 0.082650),
(1202, 'Male', 81.7, -0.352100, 10.914200, 0.082630),
(1203, 'Male', 81.8, -0.352100, 10.935000, 0.082600),
(1204, 'Male', 81.9, -0.352100, 10.956000, 0.082580),
(1205, 'Male', 82.1, -0.352100, 10.998500, 0.082520),
(1206, 'Male', 82.2, -0.352100, 11.019900, 0.082490),
(1207, 'Male', 82.3, -0.352100, 11.041500, 0.082460),
(1208, 'Male', 82.4, -0.352100, 11.063200, 0.082440),
(1209, 'Male', 82.6, -0.352100, 11.107100, 0.082380),
(1210, 'Male', 82.7, -0.352100, 11.129300, 0.082350),
(1211, 'Male', 82.8, -0.352100, 11.151600, 0.082310),
(1212, 'Male', 82.9, -0.352100, 11.174000, 0.082280),
(1213, 'Male', 83.1, -0.352100, 11.219300, 0.082220),
(1214, 'Male', 83.2, -0.352100, 11.242200, 0.082190),
(1215, 'Male', 83.3, -0.352100, 11.265100, 0.082150),
(1216, 'Male', 83.4, -0.352100, 11.288200, 0.082120),
(1217, 'Male', 83.6, -0.352100, 11.334700, 0.082050),
(1218, 'Male', 83.7, -0.352100, 11.358100, 0.082020),
(1219, 'Male', 83.8, -0.352100, 11.381700, 0.081980),
(1220, 'Male', 83.9, -0.352100, 11.405300, 0.081950),
(1221, 'Male', 84.1, -0.352100, 11.452900, 0.081880),
(1222, 'Male', 84.2, -0.352100, 11.476800, 0.081840),
(1223, 'Male', 84.3, -0.352100, 11.500700, 0.081810),
(1224, 'Male', 84.4, -0.352100, 11.524800, 0.081770),
(1225, 'Male', 84.6, -0.352100, 11.573200, 0.081700),
(1226, 'Male', 84.7, -0.352100, 11.597500, 0.081660),
(1227, 'Male', 84.8, -0.352100, 11.621800, 0.081630),
(1228, 'Male', 84.9, -0.352100, 11.646200, 0.081590),
(1229, 'Male', 85.1, -0.352100, 11.695200, 0.081520),
(1230, 'Male', 85.2, -0.352100, 11.719800, 0.081480),
(1231, 'Male', 85.3, -0.352100, 11.744400, 0.081450),
(1232, 'Male', 85.4, -0.352100, 11.769000, 0.081410),
(1233, 'Male', 85.6, -0.352100, 11.818400, 0.081340),
(1234, 'Male', 85.7, -0.352100, 11.843100, 0.081310),
(1235, 'Male', 85.8, -0.352100, 11.867800, 0.081280),
(1236, 'Male', 85.9, -0.352100, 11.892600, 0.081240),
(1237, 'Male', 86.1, -0.352100, 11.942100, 0.081180),
(1238, 'Male', 86.2, -0.352100, 11.966800, 0.081140),
(1239, 'Male', 86.3, -0.352100, 11.991600, 0.081110),
(1240, 'Male', 86.4, -0.352100, 12.016300, 0.081080),
(1241, 'Male', 86.6, -0.352100, 12.065800, 0.081020),
(1242, 'Male', 86.7, -0.352100, 12.090500, 0.080990),
(1243, 'Male', 86.8, -0.352100, 12.115200, 0.080960),
(1244, 'Male', 86.9, -0.352100, 12.139800, 0.080930),
(1245, 'Male', 87.1, -0.352100, 12.189100, 0.080870),
(1246, 'Male', 87.2, -0.352100, 12.213600, 0.080840),
(1247, 'Male', 87.3, -0.352100, 12.238200, 0.080820),
(1248, 'Male', 87.4, -0.352100, 12.262700, 0.080790),
(1249, 'Male', 87.6, -0.352100, 12.311600, 0.080740),
(1250, 'Male', 87.7, -0.352100, 12.336000, 0.080710),
(1251, 'Male', 87.8, -0.352100, 12.360300, 0.080690),
(1252, 'Male', 87.9, -0.352100, 12.384600, 0.080670),
(1253, 'Male', 88.1, -0.352100, 12.433200, 0.080620),
(1254, 'Male', 88.2, -0.352100, 12.457400, 0.080600),
(1255, 'Male', 88.3, -0.352100, 12.481500, 0.080580),
(1256, 'Male', 88.4, -0.352100, 12.505700, 0.080560),
(1257, 'Male', 88.6, -0.352100, 12.553800, 0.080520),
(1258, 'Male', 88.7, -0.352100, 12.577800, 0.080500),
(1259, 'Male', 88.8, -0.352100, 12.601700, 0.080480),
(1260, 'Male', 88.9, -0.352100, 12.625700, 0.080470),
(1261, 'Male', 89.1, -0.352100, 12.673400, 0.080440),
(1262, 'Male', 89.2, -0.352100, 12.697200, 0.080420),
(1263, 'Male', 89.3, -0.352100, 12.720900, 0.080410),
(1264, 'Male', 89.4, -0.352100, 12.744600, 0.080390),
(1265, 'Male', 89.6, -0.352100, 12.792000, 0.080370),
(1266, 'Male', 89.7, -0.352100, 12.815600, 0.080350),
(1267, 'Male', 89.8, -0.352100, 12.839200, 0.080340),
(1268, 'Male', 89.9, -0.352100, 12.862800, 0.080330),
(1269, 'Male', 90.1, -0.352100, 12.909900, 0.080310),
(1270, 'Male', 90.2, -0.352100, 12.933400, 0.080300),
(1271, 'Male', 90.3, -0.352100, 12.956900, 0.080300),
(1272, 'Male', 90.4, -0.352100, 12.980400, 0.080290),
(1273, 'Male', 90.6, -0.352100, 13.027300, 0.080270),
(1274, 'Male', 90.7, -0.352100, 13.050700, 0.080270),
(1275, 'Male', 90.8, -0.352100, 13.074200, 0.080260),
(1276, 'Male', 90.9, -0.352100, 13.097600, 0.080260),
(1277, 'Male', 91.1, -0.352100, 13.144300, 0.080250),
(1278, 'Male', 91.2, -0.352100, 13.167700, 0.080250),
(1279, 'Male', 91.3, -0.352100, 13.191000, 0.080250),
(1280, 'Male', 91.4, -0.352100, 13.214300, 0.080250),
(1281, 'Male', 91.6, -0.352100, 13.260900, 0.080240),
(1282, 'Male', 91.7, -0.352100, 13.284200, 0.080240),
(1283, 'Male', 91.8, -0.352100, 13.307500, 0.080250),
(1284, 'Male', 91.9, -0.352100, 13.330800, 0.080250),
(1285, 'Male', 92.1, -0.352100, 13.377300, 0.080250),
(1286, 'Male', 92.2, -0.352100, 13.400600, 0.080260),
(1287, 'Male', 92.3, -0.352100, 13.423900, 0.080260),
(1288, 'Male', 92.4, -0.352100, 13.447200, 0.080270),
(1289, 'Male', 92.6, -0.352100, 13.493700, 0.080280),
(1290, 'Male', 92.7, -0.352100, 13.517100, 0.080280),
(1291, 'Male', 92.8, -0.352100, 13.540400, 0.080290),
(1292, 'Male', 92.9, -0.352100, 13.563700, 0.080300),
(1293, 'Male', 93.1, -0.352100, 13.610400, 0.080320),
(1294, 'Male', 93.2, -0.352100, 13.633800, 0.080330),
(1295, 'Male', 93.3, -0.352100, 13.657200, 0.080340),
(1296, 'Male', 93.4, -0.352100, 13.680600, 0.080350),
(1297, 'Male', 93.6, -0.352100, 13.727500, 0.080370),
(1298, 'Male', 93.7, -0.352100, 13.751000, 0.080380),
(1299, 'Male', 93.8, -0.352100, 13.774600, 0.080400),
(1300, 'Male', 93.9, -0.352100, 13.798100, 0.080410),
(1301, 'Male', 94.1, -0.352100, 13.845400, 0.080440),
(1302, 'Male', 94.2, -0.352100, 13.869100, 0.080460),
(1303, 'Male', 94.3, -0.352100, 13.892800, 0.080470),
(1304, 'Male', 94.4, -0.352100, 13.916500, 0.080490),
(1305, 'Male', 94.6, -0.352100, 13.964200, 0.080520),
(1306, 'Male', 94.7, -0.352100, 13.988100, 0.080540),
(1307, 'Male', 94.8, -0.352100, 14.012000, 0.080560),
(1308, 'Male', 94.9, -0.352100, 14.036000, 0.080580),
(1309, 'Male', 95.1, -0.352100, 14.084100, 0.080620),
(1310, 'Male', 95.2, -0.352100, 14.108300, 0.080640),
(1311, 'Male', 95.3, -0.352100, 14.132500, 0.080670),
(1312, 'Male', 95.4, -0.352100, 14.156700, 0.080690),
(1313, 'Male', 95.6, -0.352100, 14.205500, 0.080730),
(1314, 'Male', 95.7, -0.352100, 14.229900, 0.080760),
(1315, 'Male', 95.8, -0.352100, 14.254400, 0.080780),
(1316, 'Male', 95.9, -0.352100, 14.279000, 0.080810),
(1317, 'Male', 96.1, -0.352100, 14.328400, 0.080860),
(1318, 'Male', 96.2, -0.352100, 14.353300, 0.080890),
(1319, 'Male', 96.3, -0.352100, 14.378200, 0.080920),
(1320, 'Male', 96.4, -0.352100, 14.403100, 0.080940),
(1321, 'Male', 96.6, -0.352100, 14.453300, 0.081000),
(1322, 'Male', 96.7, -0.352100, 14.478500, 0.081030),
(1323, 'Male', 96.8, -0.352100, 14.503800, 0.081060),
(1324, 'Male', 96.9, -0.352100, 14.529200, 0.081090),
(1325, 'Male', 97.1, -0.352100, 14.580200, 0.081160),
(1326, 'Male', 97.2, -0.352100, 14.605800, 0.081190),
(1327, 'Male', 97.3, -0.352100, 14.631600, 0.081220),
(1328, 'Male', 97.4, -0.352100, 14.657400, 0.081250),
(1329, 'Male', 97.6, -0.352100, 14.709200, 0.081320),
(1330, 'Male', 97.7, -0.352100, 14.735300, 0.081360),
(1331, 'Male', 97.8, -0.352100, 14.761400, 0.081390),
(1332, 'Male', 97.9, -0.352100, 14.787700, 0.081430),
(1333, 'Male', 98.1, -0.352100, 14.840400, 0.081500),
(1334, 'Male', 98.2, -0.352100, 14.866900, 0.081540),
(1335, 'Male', 98.3, -0.352100, 14.893400, 0.081570),
(1336, 'Male', 98.4, -0.352100, 14.920100, 0.081610),
(1337, 'Male', 98.6, -0.352100, 14.973600, 0.081690),
(1338, 'Male', 98.7, -0.352100, 15.000500, 0.081730),
(1339, 'Male', 98.8, -0.352100, 15.027500, 0.081770),
(1340, 'Male', 98.9, -0.352100, 15.054600, 0.081810),
(1341, 'Male', 99.1, -0.352100, 15.109000, 0.081890),
(1342, 'Male', 99.2, -0.352100, 15.136300, 0.081940),
(1343, 'Male', 99.3, -0.352100, 15.163700, 0.081980),
(1344, 'Male', 99.4, -0.352100, 15.191200, 0.082020),
(1345, 'Male', 99.6, -0.352100, 15.246300, 0.082110),
(1346, 'Male', 99.7, -0.352100, 15.274000, 0.082150),
(1347, 'Male', 99.8, -0.352100, 15.301800, 0.082200),
(1348, 'Male', 99.9, -0.352100, 15.329700, 0.082240),
(1349, 'Male', 100.1, -0.352100, 15.385600, 0.082330),
(1350, 'Male', 100.2, -0.352100, 15.413700, 0.082380),
(1351, 'Male', 100.3, -0.352100, 15.441900, 0.082430),
(1352, 'Male', 100.4, -0.352100, 15.470100, 0.082470),
(1353, 'Male', 100.6, -0.352100, 15.526800, 0.082570),
(1354, 'Male', 100.7, -0.352100, 15.555300, 0.082620),
(1355, 'Male', 100.8, -0.352100, 15.583800, 0.082670),
(1356, 'Male', 100.9, -0.352100, 15.612500, 0.082720),
(1357, 'Male', 101.1, -0.352100, 15.669900, 0.082810),
(1358, 'Male', 101.2, -0.352100, 15.698700, 0.082870),
(1359, 'Male', 101.3, -0.352100, 15.727600, 0.082920),
(1360, 'Male', 101.4, -0.352100, 15.756600, 0.082970),
(1361, 'Male', 101.6, -0.352100, 15.814800, 0.083070),
(1362, 'Male', 101.7, -0.352100, 15.844000, 0.083120),
(1363, 'Male', 101.8, -0.352100, 15.873200, 0.083170),
(1364, 'Male', 101.9, -0.352100, 15.902600, 0.083220),
(1365, 'Male', 102.1, -0.352100, 15.961500, 0.083330),
(1366, 'Male', 102.2, -0.352100, 15.991000, 0.083380),
(1367, 'Male', 102.3, -0.352100, 16.020600, 0.083430),
(1368, 'Male', 102.4, -0.352100, 16.050300, 0.083490),
(1369, 'Male', 102.6, -0.352100, 16.109900, 0.083590),
(1370, 'Male', 102.7, -0.352100, 16.139800, 0.083650),
(1371, 'Male', 102.8, -0.352100, 16.169700, 0.083700),
(1372, 'Male', 102.9, -0.352100, 16.199700, 0.083760),
(1373, 'Male', 103.1, -0.352100, 16.260000, 0.083860),
(1374, 'Male', 103.2, -0.352100, 16.290200, 0.083920),
(1375, 'Male', 103.3, -0.352100, 16.320400, 0.083970),
(1376, 'Male', 103.4, -0.352100, 16.350800, 0.084030),
(1377, 'Male', 103.6, -0.352100, 16.411700, 0.084140),
(1378, 'Male', 103.7, -0.352100, 16.442200, 0.084190),
(1379, 'Male', 103.8, -0.352100, 16.472800, 0.084250),
(1380, 'Male', 103.9, -0.352100, 16.503500, 0.084310),
(1381, 'Male', 104.1, -0.352100, 16.565000, 0.084420),
(1382, 'Male', 104.2, -0.352100, 16.595900, 0.084470),
(1383, 'Male', 104.3, -0.352100, 16.626800, 0.084530),
(1384, 'Male', 104.4, -0.352100, 16.657900, 0.084580),
(1385, 'Male', 104.6, -0.352100, 16.720100, 0.084700),
(1386, 'Male', 104.7, -0.352100, 16.751300, 0.084750),
(1387, 'Male', 104.8, -0.352100, 16.782600, 0.084810),
(1388, 'Male', 104.9, -0.352100, 16.813900, 0.084870),
(1389, 'Male', 105.1, -0.352100, 16.876900, 0.084980),
(1390, 'Male', 105.2, -0.352100, 16.908400, 0.085040),
(1391, 'Male', 105.3, -0.352100, 16.940100, 0.085100),
(1392, 'Male', 105.4, -0.352100, 16.971800, 0.085160),
(1393, 'Male', 105.6, -0.352100, 17.035500, 0.085270),
(1394, 'Male', 105.7, -0.352100, 17.067400, 0.085330),
(1395, 'Male', 105.8, -0.352100, 17.099500, 0.085390),
(1396, 'Male', 105.9, -0.352100, 17.131600, 0.085450),
(1397, 'Male', 106.1, -0.352100, 17.196000, 0.085570),
(1398, 'Male', 106.2, -0.352100, 17.228300, 0.085620),
(1399, 'Male', 106.3, -0.352100, 17.260700, 0.085680),
(1400, 'Male', 106.4, -0.352100, 17.293100, 0.085740),
(1401, 'Male', 106.6, -0.352100, 17.358200, 0.085860),
(1402, 'Male', 106.7, -0.352100, 17.390900, 0.085920),
(1403, 'Male', 106.8, -0.352100, 17.423700, 0.085990),
(1404, 'Male', 106.9, -0.352100, 17.456500, 0.086050),
(1405, 'Male', 107.1, -0.352100, 17.522400, 0.086170),
(1406, 'Male', 107.2, -0.352100, 17.555400, 0.086230),
(1407, 'Male', 107.3, -0.352100, 17.588500, 0.086290),
(1408, 'Male', 107.4, -0.352100, 17.621700, 0.086350),
(1409, 'Male', 107.6, -0.352100, 17.688400, 0.086480),
(1410, 'Male', 107.7, -0.352100, 17.721800, 0.086540),
(1411, 'Male', 107.8, -0.352100, 17.755300, 0.086600),
(1412, 'Male', 107.9, -0.352100, 17.788900, 0.086660),
(1413, 'Male', 108.1, -0.352100, 17.856400, 0.086790),
(1414, 'Male', 108.2, -0.352100, 17.890300, 0.086850),
(1415, 'Male', 108.3, -0.352100, 17.924200, 0.086910),
(1416, 'Male', 108.4, -0.352100, 17.958300, 0.086980),
(1417, 'Male', 108.6, -0.352100, 18.026700, 0.087100),
(1418, 'Male', 108.7, -0.352100, 18.061000, 0.087170),
(1419, 'Male', 108.8, -0.352100, 18.095400, 0.087230),
(1420, 'Male', 108.9, -0.352100, 18.129900, 0.087300),
(1421, 'Male', 109.1, -0.352100, 18.199200, 0.087420),
(1422, 'Male', 109.2, -0.352100, 18.234000, 0.087490),
(1423, 'Male', 109.3, -0.352100, 18.268900, 0.087550),
(1424, 'Male', 109.4, -0.352100, 18.303900, 0.087620),
(1425, 'Male', 109.6, -0.352100, 18.374200, 0.087740),
(1426, 'Male', 109.7, -0.352100, 18.409400, 0.087810),
(1427, 'Male', 109.8, -0.352100, 18.444800, 0.087870),
(1428, 'Male', 109.9, -0.352100, 18.480200, 0.087940),
(1429, 'Female', 45.1, -0.383300, 2.477700, 0.090300),
(1430, 'Female', 45.2, -0.383300, 2.494700, 0.090300),
(1431, 'Female', 45.3, -0.383300, 2.511700, 0.090310),
(1432, 'Female', 45.4, -0.383300, 2.528700, 0.090320),
(1433, 'Female', 45.6, -0.383300, 2.562700, 0.090330),
(1434, 'Female', 45.7, -0.383300, 2.579700, 0.090340),
(1435, 'Female', 45.8, -0.383300, 2.596700, 0.090350),
(1436, 'Female', 45.9, -0.383300, 2.613700, 0.090360),
(1437, 'Female', 46.1, -0.383300, 2.647600, 0.090370),
(1438, 'Female', 46.2, -0.383300, 2.664600, 0.090380),
(1439, 'Female', 46.3, -0.383300, 2.681600, 0.090390),
(1440, 'Female', 46.4, -0.383300, 2.698600, 0.090400),
(1441, 'Female', 46.6, -0.383300, 2.732600, 0.090410),
(1442, 'Female', 46.7, -0.383300, 2.749600, 0.090420),
(1443, 'Female', 46.8, -0.383300, 2.766600, 0.090430),
(1444, 'Female', 46.9, -0.383300, 2.783700, 0.090440),
(1445, 'Female', 47.1, -0.383300, 2.817900, 0.090450),
(1446, 'Female', 47.2, -0.383300, 2.835000, 0.090460),
(1447, 'Female', 47.3, -0.383300, 2.852200, 0.090470),
(1448, 'Female', 47.4, -0.383300, 2.869400, 0.090470),
(1449, 'Female', 47.6, -0.383300, 2.904100, 0.090490),
(1450, 'Female', 47.7, -0.383300, 2.921500, 0.090500),
(1451, 'Female', 47.8, -0.383300, 2.939000, 0.090500),
(1452, 'Female', 47.9, -0.383300, 2.956500, 0.090510),
(1453, 'Female', 48.1, -0.383300, 2.991800, 0.090530),
(1454, 'Female', 48.2, -0.383300, 3.009600, 0.090540),
(1455, 'Female', 48.3, -0.383300, 3.027500, 0.090540),
(1456, 'Female', 48.4, -0.383300, 3.045500, 0.090550),
(1457, 'Female', 48.6, -0.383300, 3.081800, 0.090570),
(1458, 'Female', 48.7, -0.383300, 3.100100, 0.090570),
(1459, 'Female', 48.8, -0.383300, 3.118600, 0.090580),
(1460, 'Female', 48.9, -0.383300, 3.137200, 0.090590),
(1461, 'Female', 49.1, -0.383300, 3.174900, 0.090610),
(1462, 'Female', 49.2, -0.383300, 3.193900, 0.090610),
(1463, 'Female', 49.3, -0.383300, 3.213100, 0.090620),
(1464, 'Female', 49.4, -0.383300, 3.232500, 0.090630),
(1465, 'Female', 49.6, -0.383300, 3.271700, 0.090650),
(1466, 'Female', 49.7, -0.383300, 3.291500, 0.090650),
(1467, 'Female', 49.8, -0.383300, 3.311400, 0.090660),
(1468, 'Female', 49.9, -0.383300, 3.331600, 0.090670),
(1469, 'Female', 50.1, -0.383300, 3.372300, 0.090690),
(1470, 'Female', 50.2, -0.383300, 3.392900, 0.090690),
(1471, 'Female', 50.3, -0.383300, 3.413600, 0.090700),
(1472, 'Female', 50.4, -0.383300, 3.434600, 0.090710),
(1473, 'Female', 50.6, -0.383300, 3.476900, 0.090730),
(1474, 'Female', 50.7, -0.383300, 3.498300, 0.090740),
(1475, 'Female', 50.8, -0.383300, 3.519900, 0.090740),
(1476, 'Female', 50.9, -0.383300, 3.541700, 0.090750),
(1477, 'Female', 51.1, -0.383300, 3.585600, 0.090770),
(1478, 'Female', 51.2, -0.383300, 3.607800, 0.090780),
(1479, 'Female', 51.3, -0.383300, 3.630200, 0.090790),
(1480, 'Female', 51.4, -0.383300, 3.652700, 0.090800),
(1481, 'Female', 51.6, -0.383300, 3.698200, 0.090810),
(1482, 'Female', 51.7, -0.383300, 3.721200, 0.090820),
(1483, 'Female', 51.8, -0.383300, 3.744400, 0.090830),
(1484, 'Female', 51.9, -0.383300, 3.767700, 0.090840),
(1485, 'Female', 52.1, -0.383300, 3.814700, 0.090860),
(1486, 'Female', 52.2, -0.383300, 3.838500, 0.090860),
(1487, 'Female', 52.3, -0.383300, 3.862300, 0.090870),
(1488, 'Female', 52.4, -0.383300, 3.886300, 0.090880),
(1489, 'Female', 52.6, -0.383300, 3.934800, 0.090900),
(1490, 'Female', 52.7, -0.383300, 3.959200, 0.090910),
(1491, 'Female', 52.8, -0.383300, 3.983700, 0.090920),
(1492, 'Female', 52.9, -0.383300, 4.008400, 0.090930),
(1493, 'Female', 53.1, -0.383300, 4.058100, 0.090940),
(1494, 'Female', 53.2, -0.383300, 4.083200, 0.090950),
(1495, 'Female', 53.3, -0.383300, 4.108400, 0.090960),
(1496, 'Female', 53.4, -0.383300, 4.133700, 0.090970),
(1497, 'Female', 53.6, -0.383300, 4.184600, 0.090990),
(1498, 'Female', 53.7, -0.383300, 4.210200, 0.090990),
(1499, 'Female', 53.8, -0.383300, 4.235900, 0.091000),
(1500, 'Female', 53.9, -0.383300, 4.261700, 0.091010),
(1501, 'Female', 54.1, -0.383300, 4.313500, 0.091030),
(1502, 'Female', 54.2, -0.383300, 4.339500, 0.091040),
(1503, 'Female', 54.3, -0.383300, 4.365500, 0.091050),
(1504, 'Female', 54.4, -0.383300, 4.391700, 0.091050),
(1505, 'Female', 54.6, -0.383300, 4.444200, 0.091070),
(1506, 'Female', 54.7, -0.383300, 4.470500, 0.091080),
(1507, 'Female', 54.8, -0.383300, 4.496900, 0.091090),
(1508, 'Female', 54.9, -0.383300, 4.523300, 0.091090),
(1509, 'Female', 55.1, -0.383300, 4.576300, 0.091110),
(1510, 'Female', 55.2, -0.383300, 4.602900, 0.091120),
(1511, 'Female', 55.3, -0.383300, 4.629500, 0.091130),
(1512, 'Female', 55.4, -0.383300, 4.656100, 0.091130),
(1513, 'Female', 55.6, -0.383300, 4.709400, 0.091150),
(1514, 'Female', 55.7, -0.383300, 4.736100, 0.091160),
(1515, 'Female', 55.8, -0.383300, 4.762800, 0.091160),
(1516, 'Female', 55.9, -0.383300, 4.789500, 0.091170),
(1517, 'Female', 56.1, -0.383300, 4.843000, 0.091190),
(1518, 'Female', 56.2, -0.383300, 4.869700, 0.091190),
(1519, 'Female', 56.3, -0.383300, 4.896400, 0.091200),
(1520, 'Female', 56.4, -0.383300, 4.923200, 0.091210),
(1521, 'Female', 56.6, -0.383300, 4.976700, 0.091220),
(1522, 'Female', 56.7, -0.383300, 5.003400, 0.091230),
(1523, 'Female', 56.8, -0.383300, 5.030200, 0.091230),
(1524, 'Female', 56.9, -0.383300, 5.056900, 0.091240),
(1525, 'Female', 57.1, -0.383300, 5.110400, 0.091250),
(1526, 'Female', 57.2, -0.383300, 5.137100, 0.091260),
(1527, 'Female', 57.3, -0.383300, 5.163900, 0.091260),
(1528, 'Female', 57.4, -0.383300, 5.190600, 0.091270),
(1529, 'Female', 57.6, -0.383300, 5.244000, 0.091280),
(1530, 'Female', 57.7, -0.383300, 5.270700, 0.091290),
(1531, 'Female', 57.8, -0.383300, 5.297400, 0.091290),
(1532, 'Female', 57.9, -0.383300, 5.324000, 0.091300),
(1533, 'Female', 58.1, -0.383300, 5.377300, 0.091310),
(1534, 'Female', 58.2, -0.383300, 5.403900, 0.091310),
(1535, 'Female', 58.3, -0.383300, 5.430400, 0.091310),
(1536, 'Female', 58.4, -0.383300, 5.456900, 0.091320),
(1537, 'Female', 58.6, -0.383300, 5.509800, 0.091330),
(1538, 'Female', 58.7, -0.383300, 5.536200, 0.091330),
(1539, 'Female', 58.8, -0.383300, 5.562500, 0.091330),
(1540, 'Female', 58.9, -0.383300, 5.588800, 0.091340),
(1541, 'Female', 59.1, -0.383300, 5.641300, 0.091340),
(1542, 'Female', 59.2, -0.383300, 5.667400, 0.091350),
(1543, 'Female', 59.3, -0.383300, 5.693500, 0.091350),
(1544, 'Female', 59.4, -0.383300, 5.719500, 0.091350),
(1545, 'Female', 59.6, -0.383300, 5.771300, 0.091360),
(1546, 'Female', 59.7, -0.383300, 5.797100, 0.091360),
(1547, 'Female', 59.8, -0.383300, 5.822900, 0.091360),
(1548, 'Female', 59.9, -0.383300, 5.848500, 0.091360),
(1549, 'Female', 60.1, -0.383300, 5.899700, 0.091360),
(1550, 'Female', 60.2, -0.383300, 5.925200, 0.091370),
(1551, 'Female', 60.3, -0.383300, 5.950700, 0.091370),
(1552, 'Female', 60.4, -0.383300, 5.976100, 0.091370),
(1553, 'Female', 60.6, -0.383300, 6.026600, 0.091370),
(1554, 'Female', 60.7, -0.383300, 6.051800, 0.091370),
(1555, 'Female', 60.8, -0.383300, 6.076900, 0.091370),
(1556, 'Female', 60.9, -0.383300, 6.102000, 0.091370),
(1557, 'Female', 61.1, -0.383300, 6.151900, 0.091370),
(1558, 'Female', 61.2, -0.383300, 6.176800, 0.091360),
(1559, 'Female', 61.3, -0.383300, 6.201700, 0.091360),
(1560, 'Female', 61.4, -0.383300, 6.226400, 0.091360),
(1561, 'Female', 61.6, -0.383300, 6.275800, 0.091360),
(1562, 'Female', 61.7, -0.383300, 6.300400, 0.091360),
(1563, 'Female', 61.8, -0.383300, 6.324900, 0.091350),
(1564, 'Female', 61.9, -0.383300, 6.349400, 0.091350),
(1565, 'Female', 62.1, -0.383300, 6.398100, 0.091350),
(1566, 'Female', 62.2, -0.383300, 6.422400, 0.091340),
(1567, 'Female', 62.3, -0.383300, 6.446600, 0.091340),
(1568, 'Female', 62.4, -0.383300, 6.470800, 0.091340),
(1569, 'Female', 62.6, -0.383300, 6.518900, 0.091330),
(1570, 'Female', 62.7, -0.383300, 6.542900, 0.091330),
(1571, 'Female', 62.8, -0.383300, 6.566800, 0.091320);

INSERT INTO `who_weight_for_length` (`id`, `sex`, `height_cm`, `L`, `M`, `S`) VALUES
(1, 'Male', 45.0, -0.352100, 2.441000, 0.091820),
(2, 'Male', 45.1, -0.352100, 2.457700, 0.091760),
(3, 'Male', 45.2, -0.352100, 2.474400, 0.091700),
(4, 'Male', 45.3, -0.352100, 2.491100, 0.091640),
(5, 'Male', 45.4, -0.352100, 2.507800, 0.091590),
(6, 'Male', 45.5, -0.352100, 2.524400, 0.091530),
(7, 'Male', 45.6, -0.352100, 2.541100, 0.091470),
(8, 'Male', 45.7, -0.352100, 2.557800, 0.091410),
(9, 'Male', 45.8, -0.352100, 2.574400, 0.091350),
(10, 'Male', 45.9, -0.352100, 2.591100, 0.091290),
(11, 'Male', 46.0, -0.352100, 2.607700, 0.091240),
(12, 'Male', 46.1, -0.352100, 2.624400, 0.091180),
(13, 'Male', 46.2, -0.352100, 2.641100, 0.091120),
(14, 'Male', 46.3, -0.352100, 2.657800, 0.091060),
(15, 'Male', 46.4, -0.352100, 2.674500, 0.091000),
(16, 'Male', 46.5, -0.352100, 2.691300, 0.090940),
(17, 'Male', 46.6, -0.352100, 2.708100, 0.090880),
(18, 'Male', 46.7, -0.352100, 2.724900, 0.090830),
(19, 'Male', 46.8, -0.352100, 2.741700, 0.090770),
(20, 'Male', 46.9, -0.352100, 2.758600, 0.090710),
(21, 'Male', 47.0, -0.352100, 2.775500, 0.090650),
(22, 'Male', 47.1, -0.352100, 2.792500, 0.090590),
(23, 'Male', 47.2, -0.352100, 2.809500, 0.090530),
(24, 'Male', 47.3, -0.352100, 2.826600, 0.090470),
(25, 'Male', 47.4, -0.352100, 2.843700, 0.090420),
(26, 'Male', 47.5, -0.352100, 2.860900, 0.090360),
(27, 'Male', 47.6, -0.352100, 2.878200, 0.090300),
(28, 'Male', 47.7, -0.352100, 2.895500, 0.090240),
(29, 'Male', 47.8, -0.352100, 2.912900, 0.090180),
(30, 'Male', 47.9, -0.352100, 2.930400, 0.090120),
(31, 'Male', 48.0, -0.352100, 2.948000, 0.090070),
(32, 'Male', 48.1, -0.352100, 2.965700, 0.090010),
(33, 'Male', 48.2, -0.352100, 2.983500, 0.089950),
(34, 'Male', 48.3, -0.352100, 3.001400, 0.089890),
(35, 'Male', 48.4, -0.352100, 3.019500, 0.089830),
(36, 'Male', 48.5, -0.352100, 3.037700, 0.089770),
(37, 'Male', 48.6, -0.352100, 3.056000, 0.089720),
(38, 'Male', 48.7, -0.352100, 3.074500, 0.089660),
(39, 'Male', 48.8, -0.352100, 3.093100, 0.089600),
(40, 'Male', 48.9, -0.352100, 3.111900, 0.089540),
(41, 'Male', 49.0, -0.352100, 3.130800, 0.089480),
(42, 'Male', 49.1, -0.352100, 3.149900, 0.089430),
(43, 'Male', 49.2, -0.352100, 3.169100, 0.089370),
(44, 'Male', 49.3, -0.352100, 3.188400, 0.089310),
(45, 'Male', 49.4, -0.352100, 3.207900, 0.089250),
(46, 'Male', 49.5, -0.352100, 3.227600, 0.089190),
(47, 'Male', 49.6, -0.352100, 3.247300, 0.089130),
(48, 'Male', 49.7, -0.352100, 3.267200, 0.089080),
(49, 'Male', 49.8, -0.352100, 3.287300, 0.089020),
(50, 'Male', 49.9, -0.352100, 3.307500, 0.088960),
(51, 'Male', 50.0, -0.352100, 3.327800, 0.088900),
(52, 'Male', 50.1, -0.352100, 3.348200, 0.088840),
(53, 'Male', 50.2, -0.352100, 3.368800, 0.088780),
(54, 'Male', 50.3, -0.352100, 3.389400, 0.088720),
(55, 'Male', 50.4, -0.352100, 3.410200, 0.088670),
(56, 'Male', 50.5, -0.352100, 3.431100, 0.088610),
(57, 'Male', 50.6, -0.352100, 3.452200, 0.088550),
(58, 'Male', 50.7, -0.352100, 3.473300, 0.088490),
(59, 'Male', 50.8, -0.352100, 3.494600, 0.088430),
(60, 'Male', 50.9, -0.352100, 3.516100, 0.088370),
(61, 'Male', 51.0, -0.352100, 3.537600, 0.088310),
(62, 'Male', 51.1, -0.352100, 3.559300, 0.088250),
(63, 'Male', 51.2, -0.352100, 3.581200, 0.088190),
(64, 'Male', 51.3, -0.352100, 3.603200, 0.088130),
(65, 'Male', 51.4, -0.352100, 3.625400, 0.088070),
(66, 'Male', 51.5, -0.352100, 3.647700, 0.088010),
(67, 'Male', 51.6, -0.352100, 3.670200, 0.087950),
(68, 'Male', 51.7, -0.352100, 3.692900, 0.087890),
(69, 'Male', 51.8, -0.352100, 3.715700, 0.087830),
(70, 'Male', 51.9, -0.352100, 3.738800, 0.087770),
(71, 'Male', 52.0, -0.352100, 3.762000, 0.087710),
(72, 'Male', 52.1, -0.352100, 3.785500, 0.087650),
(73, 'Male', 52.2, -0.352100, 3.809200, 0.087590),
(74, 'Male', 52.3, -0.352100, 3.833000, 0.087530),
(75, 'Male', 52.4, -0.352100, 3.857100, 0.087470),
(76, 'Male', 52.5, -0.352100, 3.881400, 0.087410),
(77, 'Male', 52.6, -0.352100, 3.905900, 0.087350),
(78, 'Male', 52.7, -0.352100, 3.930600, 0.087290),
(79, 'Male', 52.8, -0.352100, 3.955500, 0.087230),
(80, 'Male', 52.9, -0.352100, 3.980600, 0.087170),
(81, 'Male', 53.0, -0.352100, 4.006000, 0.087110),
(82, 'Male', 53.1, -0.352100, 4.031500, 0.087050),
(83, 'Male', 53.2, -0.352100, 4.057200, 0.086990),
(84, 'Male', 53.3, -0.352100, 4.083100, 0.086930),
(85, 'Male', 53.4, -0.352100, 4.109200, 0.086870),
(86, 'Male', 53.5, -0.352100, 4.135400, 0.086810),
(87, 'Male', 53.6, -0.352100, 4.161900, 0.086750),
(88, 'Male', 53.7, -0.352100, 4.188500, 0.086690),
(89, 'Male', 53.8, -0.352100, 4.215300, 0.086630),
(90, 'Male', 53.9, -0.352100, 4.242200, 0.086570),
(91, 'Male', 54.0, -0.352100, 4.269300, 0.086510),
(92, 'Male', 54.1, -0.352100, 4.296500, 0.086450),
(93, 'Male', 54.2, -0.352100, 4.323900, 0.086390),
(94, 'Male', 54.3, -0.352100, 4.351300, 0.086330),
(95, 'Male', 54.4, -0.352100, 4.378900, 0.086270),
(96, 'Male', 54.5, -0.352100, 4.406600, 0.086210),
(97, 'Male', 54.6, -0.352100, 4.434400, 0.086150),
(98, 'Male', 54.7, -0.352100, 4.462300, 0.086100),
(99, 'Male', 54.8, -0.352100, 4.490300, 0.086040),
(100, 'Male', 54.9, -0.352100, 4.518500, 0.085980),
(101, 'Male', 55.0, -0.352100, 4.546700, 0.085920),
(102, 'Male', 55.1, -0.352100, 4.575000, 0.085860),
(103, 'Male', 55.2, -0.352100, 4.603400, 0.085800),
(104, 'Male', 55.3, -0.352100, 4.631900, 0.085750),
(105, 'Male', 55.4, -0.352100, 4.660500, 0.085690),
(106, 'Male', 55.5, -0.352100, 4.689200, 0.085630),
(107, 'Male', 55.6, -0.352100, 4.718000, 0.085580),
(108, 'Male', 55.7, -0.352100, 4.746900, 0.085520),
(109, 'Male', 55.8, -0.352100, 4.775800, 0.085460),
(110, 'Male', 55.9, -0.352100, 4.804800, 0.085410),
(111, 'Male', 56.0, -0.352100, 4.833800, 0.085350),
(112, 'Male', 56.1, -0.352100, 4.862900, 0.085290),
(113, 'Male', 56.2, -0.352100, 4.892000, 0.085240),
(114, 'Male', 56.3, -0.352100, 4.921200, 0.085180),
(115, 'Male', 56.4, -0.352100, 4.950400, 0.085130),
(116, 'Male', 56.5, -0.352100, 4.979600, 0.085070),
(117, 'Male', 56.6, -0.352100, 5.008800, 0.085020),
(118, 'Male', 56.7, -0.352100, 5.038100, 0.084970),
(119, 'Male', 56.8, -0.352100, 5.067300, 0.084910),
(120, 'Male', 56.9, -0.352100, 5.096600, 0.084860),
(121, 'Male', 57.0, -0.352100, 5.125900, 0.084810),
(122, 'Male', 57.1, -0.352100, 5.155100, 0.084750),
(123, 'Male', 57.2, -0.352100, 5.184400, 0.084700),
(124, 'Male', 57.3, -0.352100, 5.213700, 0.084650),
(125, 'Male', 57.4, -0.352100, 5.242900, 0.084600),
(126, 'Male', 57.5, -0.352100, 5.272100, 0.084550),
(127, 'Male', 57.6, -0.352100, 5.301400, 0.084490),
(128, 'Male', 57.7, -0.352100, 5.330600, 0.084440),
(129, 'Male', 57.8, -0.352100, 5.359800, 0.084390),
(130, 'Male', 57.9, -0.352100, 5.388900, 0.084340),
(131, 'Male', 58.0, -0.352100, 5.418000, 0.084300),
(132, 'Male', 58.1, -0.352100, 5.447100, 0.084250),
(133, 'Male', 58.2, -0.352100, 5.476200, 0.084200),
(134, 'Male', 58.3, -0.352100, 5.505300, 0.084150),
(135, 'Male', 58.4, -0.352100, 5.534300, 0.084100),
(136, 'Male', 58.5, -0.352100, 5.563200, 0.084060),
(137, 'Male', 58.6, -0.352100, 5.592200, 0.084010),
(138, 'Male', 58.7, -0.352100, 5.621000, 0.083970),
(139, 'Male', 58.8, -0.352100, 5.649900, 0.083920),
(140, 'Male', 58.9, -0.352100, 5.678700, 0.083880),
(141, 'Male', 59.0, -0.352100, 5.707400, 0.083830),
(142, 'Male', 59.1, -0.352100, 5.736100, 0.083790),
(143, 'Male', 59.2, -0.352100, 5.764700, 0.083750),
(144, 'Male', 59.3, -0.352100, 5.793300, 0.083700),
(145, 'Male', 59.4, -0.352100, 5.821700, 0.083660),
(146, 'Male', 59.5, -0.352100, 5.850100, 0.083620),
(147, 'Male', 59.6, -0.352100, 5.878400, 0.083580),
(148, 'Male', 59.7, -0.352100, 5.906700, 0.083540),
(149, 'Male', 59.8, -0.352100, 5.934800, 0.083500),
(150, 'Male', 59.9, -0.352100, 5.962800, 0.083460),
(151, 'Male', 60.0, -0.352100, 5.990700, 0.083420),
(152, 'Male', 60.1, -0.352100, 6.018500, 0.083390),
(153, 'Male', 60.2, -0.352100, 6.046100, 0.083350),
(154, 'Male', 60.3, -0.352100, 6.073700, 0.083310),
(155, 'Male', 60.4, -0.352100, 6.101100, 0.083280),
(156, 'Male', 60.5, -0.352100, 6.128400, 0.083240),
(157, 'Male', 60.6, -0.352100, 6.155600, 0.083210),
(158, 'Male', 60.7, -0.352100, 6.182700, 0.083170),
(159, 'Male', 60.8, -0.352100, 6.209600, 0.083140),
(160, 'Male', 60.9, -0.352100, 6.236500, 0.083110),
(161, 'Male', 61.0, -0.352100, 6.263200, 0.083080),
(162, 'Male', 61.1, -0.352100, 6.289900, 0.083040),
(163, 'Male', 61.2, -0.352100, 6.316400, 0.083010),
(164, 'Male', 61.3, -0.352100, 6.342800, 0.082980),
(165, 'Male', 61.4, -0.352100, 6.369200, 0.082950),
(166, 'Male', 61.5, -0.352100, 6.395400, 0.082920),
(167, 'Male', 61.6, -0.352100, 6.421500, 0.082900),
(168, 'Male', 61.7, -0.352100, 6.447500, 0.082870),
(169, 'Male', 61.8, -0.352100, 6.473500, 0.082840),
(170, 'Male', 61.9, -0.352100, 6.499300, 0.082810),
(171, 'Male', 62.0, -0.352100, 6.525100, 0.082790),
(172, 'Male', 62.1, -0.352100, 6.550800, 0.082760),
(173, 'Male', 62.2, -0.352100, 6.576400, 0.082730),
(174, 'Male', 62.3, -0.352100, 6.601900, 0.082710),
(175, 'Male', 62.4, -0.352100, 6.627300, 0.082680),
(176, 'Male', 62.5, -0.352100, 6.652700, 0.082660),
(177, 'Male', 62.6, -0.352100, 6.678000, 0.082640),
(178, 'Male', 62.7, -0.352100, 6.703300, 0.082610),
(179, 'Male', 62.8, -0.352100, 6.728400, 0.082590),
(180, 'Male', 62.9, -0.352100, 6.753500, 0.082570),
(181, 'Male', 63.0, -0.352100, 6.778600, 0.082550),
(182, 'Male', 63.1, -0.352100, 6.803500, 0.082530),
(183, 'Male', 63.2, -0.352100, 6.828500, 0.082510),
(184, 'Male', 63.3, -0.352100, 6.853300, 0.082490),
(185, 'Male', 63.4, -0.352100, 6.878100, 0.082470),
(186, 'Male', 63.5, -0.352100, 6.902800, 0.082450),
(187, 'Male', 63.6, -0.352100, 6.927500, 0.082430),
(188, 'Male', 63.7, -0.352100, 6.952100, 0.082410),
(189, 'Male', 63.8, -0.352100, 6.976600, 0.082400),
(190, 'Male', 63.9, -0.352100, 7.001100, 0.082380),
(191, 'Male', 64.0, -0.352100, 7.025500, 0.082360),
(192, 'Male', 64.1, -0.352100, 7.049900, 0.082350),
(193, 'Male', 64.2, -0.352100, 7.074200, 0.082330),
(194, 'Male', 64.3, -0.352100, 7.098400, 0.082320),
(195, 'Male', 64.4, -0.352100, 7.122600, 0.082300),
(196, 'Male', 64.5, -0.352100, 7.146700, 0.082290),
(197, 'Male', 64.6, -0.352100, 7.170800, 0.082280),
(198, 'Male', 64.7, -0.352100, 7.194800, 0.082270),
(199, 'Male', 64.8, -0.352100, 7.218800, 0.082250),
(200, 'Male', 64.9, -0.352100, 7.242700, 0.082240),
(201, 'Male', 65.0, -0.352100, 7.266600, 0.082230),
(202, 'Male', 65.1, -0.352100, 7.290500, 0.082220),
(203, 'Male', 65.2, -0.352100, 7.314300, 0.082210),
(204, 'Male', 65.3, -0.352100, 7.338000, 0.082200),
(205, 'Male', 65.4, -0.352100, 7.361700, 0.082190),
(206, 'Male', 65.5, -0.352100, 7.385400, 0.082180),
(207, 'Male', 65.6, -0.352100, 7.409100, 0.082180),
(208, 'Male', 65.7, -0.352100, 7.432700, 0.082170),
(209, 'Male', 65.8, -0.352100, 7.456300, 0.082160),
(210, 'Male', 65.9, -0.352100, 7.479900, 0.082160),
(211, 'Male', 66.0, -0.352100, 7.503400, 0.082150),
(212, 'Male', 66.1, -0.352100, 7.526900, 0.082140),
(213, 'Male', 66.2, -0.352100, 7.550400, 0.082140),
(214, 'Male', 66.3, -0.352100, 7.573800, 0.082140),
(215, 'Male', 66.4, -0.352100, 7.597300, 0.082130),
(216, 'Male', 66.5, -0.352100, 7.620600, 0.082130),
(217, 'Male', 66.6, -0.352100, 7.644000, 0.082130),
(218, 'Male', 66.7, -0.352100, 7.667300, 0.082120),
(219, 'Male', 66.8, -0.352100, 7.690600, 0.082120),
(220, 'Male', 66.9, -0.352100, 7.713800, 0.082120),
(221, 'Male', 67.0, -0.352100, 7.737000, 0.082120),
(222, 'Male', 67.1, -0.352100, 7.760200, 0.082120),
(223, 'Male', 67.2, -0.352100, 7.783400, 0.082120),
(224, 'Male', 67.3, -0.352100, 7.806500, 0.082120),
(225, 'Male', 67.4, -0.352100, 7.829600, 0.082120),
(226, 'Male', 67.5, -0.352100, 7.852600, 0.082120),
(227, 'Male', 67.6, -0.352100, 7.875700, 0.082120),
(228, 'Male', 67.7, -0.352100, 7.898600, 0.082130),
(229, 'Male', 67.8, -0.352100, 7.921600, 0.082130),
(230, 'Male', 67.9, -0.352100, 7.944500, 0.082130),
(231, 'Male', 68.0, -0.352100, 7.967400, 0.082140),
(232, 'Male', 68.1, -0.352100, 7.990300, 0.082140),
(233, 'Male', 68.2, -0.352100, 8.013200, 0.082140),
(234, 'Male', 68.3, -0.352100, 8.036000, 0.082150),
(235, 'Male', 68.4, -0.352100, 8.058800, 0.082150),
(236, 'Male', 68.5, -0.352100, 8.081600, 0.082160),
(237, 'Male', 68.6, -0.352100, 8.104400, 0.082170),
(238, 'Male', 68.7, -0.352100, 8.127200, 0.082170),
(239, 'Male', 68.8, -0.352100, 8.150000, 0.082180),
(240, 'Male', 68.9, -0.352100, 8.172700, 0.082190),
(241, 'Male', 69.0, -0.352100, 8.195500, 0.082190),
(242, 'Male', 69.1, -0.352100, 8.218300, 0.082200),
(243, 'Male', 69.2, -0.352100, 8.241000, 0.082210),
(244, 'Male', 69.3, -0.352100, 8.263800, 0.082220),
(245, 'Male', 69.4, -0.352100, 8.286500, 0.082230),
(246, 'Male', 69.5, -0.352100, 8.309200, 0.082240),
(247, 'Male', 69.6, -0.352100, 8.332000, 0.082250),
(248, 'Male', 69.7, -0.352100, 8.354700, 0.082260),
(249, 'Male', 69.8, -0.352100, 8.377400, 0.082270),
(250, 'Male', 69.9, -0.352100, 8.400100, 0.082280),
(251, 'Male', 70.0, -0.352100, 8.422700, 0.082290),
(252, 'Male', 70.1, -0.352100, 8.445400, 0.082300),
(253, 'Male', 70.2, -0.352100, 8.468000, 0.082310),
(254, 'Male', 70.3, -0.352100, 8.490600, 0.082320),
(255, 'Male', 70.4, -0.352100, 8.513200, 0.082330),
(256, 'Male', 70.5, -0.352100, 8.535800, 0.082350),
(257, 'Male', 70.6, -0.352100, 8.558300, 0.082360),
(258, 'Male', 70.7, -0.352100, 8.580800, 0.082370),
(259, 'Male', 70.8, -0.352100, 8.603200, 0.082380),
(260, 'Male', 70.9, -0.352100, 8.625700, 0.082400),
(261, 'Male', 71.0, -0.352100, 8.648000, 0.082410),
(262, 'Male', 71.1, -0.352100, 8.670400, 0.082420),
(263, 'Male', 71.2, -0.352100, 8.692700, 0.082430),
(264, 'Male', 71.3, -0.352100, 8.715000, 0.082450),
(265, 'Male', 71.4, -0.352100, 8.737200, 0.082460),
(266, 'Male', 71.5, -0.352100, 8.759400, 0.082480),
(267, 'Male', 71.6, -0.352100, 8.781500, 0.082490),
(268, 'Male', 71.7, -0.352100, 8.803600, 0.082500),
(269, 'Male', 71.8, -0.352100, 8.825700, 0.082520),
(270, 'Male', 71.9, -0.352100, 8.847700, 0.082530),
(271, 'Male', 72.0, -0.352100, 8.869700, 0.082540),
(272, 'Male', 72.1, -0.352100, 8.891600, 0.082560),
(273, 'Male', 72.2, -0.352100, 8.913500, 0.082570),
(274, 'Male', 72.3, -0.352100, 8.935300, 0.082590),
(275, 'Male', 72.4, -0.352100, 8.957100, 0.082600),
(276, 'Male', 72.5, -0.352100, 8.978800, 0.082620),
(277, 'Male', 72.6, -0.352100, 9.000500, 0.082630),
(278, 'Male', 72.7, -0.352100, 9.022100, 0.082640),
(279, 'Male', 72.8, -0.352100, 9.043600, 0.082660),
(280, 'Male', 72.9, -0.352100, 9.065100, 0.082670),
(281, 'Male', 73.0, -0.352100, 9.086500, 0.082690),
(282, 'Male', 73.1, -0.352100, 9.107900, 0.082700),
(283, 'Male', 73.2, -0.352100, 9.129200, 0.082720),
(284, 'Male', 73.3, -0.352100, 9.150400, 0.082730),
(285, 'Male', 73.4, -0.352100, 9.171600, 0.082740),
(286, 'Male', 73.5, -0.352100, 9.192700, 0.082760),
(287, 'Male', 73.6, -0.352100, 9.213700, 0.082770),
(288, 'Male', 73.7, -0.352100, 9.234700, 0.082780),
(289, 'Male', 73.8, -0.352100, 9.255700, 0.082800),
(290, 'Male', 73.9, -0.352100, 9.276600, 0.082810),
(291, 'Male', 74.0, -0.352100, 9.297400, 0.082830),
(292, 'Male', 74.1, -0.352100, 9.318200, 0.082840),
(293, 'Male', 74.2, -0.352100, 9.339000, 0.082850),
(294, 'Male', 74.3, -0.352100, 9.359700, 0.082870),
(295, 'Male', 74.4, -0.352100, 9.380300, 0.082880),
(296, 'Male', 74.5, -0.352100, 9.401000, 0.082890),
(297, 'Male', 74.6, -0.352100, 9.421500, 0.082900),
(298, 'Male', 74.7, -0.352100, 9.442000, 0.082920),
(299, 'Male', 74.8, -0.352100, 9.462500, 0.082930),
(300, 'Male', 74.9, -0.352100, 9.482900, 0.082940),
(301, 'Male', 75.0, -0.352100, 9.503200, 0.082950),
(302, 'Male', 75.1, -0.352100, 9.523500, 0.082970),
(303, 'Male', 75.2, -0.352100, 9.543800, 0.082980),
(304, 'Male', 75.3, -0.352100, 9.563900, 0.082990),
(305, 'Male', 75.4, -0.352100, 9.584100, 0.083000),
(306, 'Male', 75.5, -0.352100, 9.604100, 0.083010),
(307, 'Male', 75.6, -0.352100, 9.624100, 0.083020),
(308, 'Male', 75.7, -0.352100, 9.644000, 0.083030),
(309, 'Male', 75.8, -0.352100, 9.663900, 0.083050),
(310, 'Male', 75.9, -0.352100, 9.683600, 0.083060),
(311, 'Male', 76.0, -0.352100, 9.703300, 0.083070),
(312, 'Male', 76.1, -0.352100, 9.723000, 0.083070),
(313, 'Male', 76.2, -0.352100, 9.742500, 0.083080),
(314, 'Male', 76.3, -0.352100, 9.762000, 0.083090),
(315, 'Male', 76.4, -0.352100, 9.781400, 0.083100),
(316, 'Male', 76.5, -0.352100, 9.800700, 0.083110),
(317, 'Male', 76.6, -0.352100, 9.820000, 0.083120),
(318, 'Male', 76.7, -0.352100, 9.839200, 0.083120),
(319, 'Male', 76.8, -0.352100, 9.858300, 0.083130),
(320, 'Male', 76.9, -0.352100, 9.877300, 0.083140),
(321, 'Male', 77.0, -0.352100, 9.896300, 0.083140),
(322, 'Male', 77.1, -0.352100, 9.915200, 0.083150),
(323, 'Male', 77.2, -0.352100, 9.934100, 0.083150),
(324, 'Male', 77.3, -0.352100, 9.952800, 0.083160),
(325, 'Male', 77.4, -0.352100, 9.971600, 0.083160),
(326, 'Male', 77.5, -0.352100, 9.990200, 0.083170),
(327, 'Male', 77.6, -0.352100, 10.008800, 0.083170),
(328, 'Male', 77.7, -0.352100, 10.027400, 0.083170),
(329, 'Male', 77.8, -0.352100, 10.045900, 0.083180),
(330, 'Male', 77.9, -0.352100, 10.064300, 0.083180),
(331, 'Male', 78.0, -0.352100, 10.082700, 0.083180),
(332, 'Male', 78.1, -0.352100, 10.101100, 0.083180),
(333, 'Male', 78.2, -0.352100, 10.119400, 0.083180),
(334, 'Male', 78.3, -0.352100, 10.137700, 0.083180),
(335, 'Male', 78.4, -0.352100, 10.155900, 0.083180),
(336, 'Male', 78.5, -0.352100, 10.174100, 0.083180),
(337, 'Male', 78.6, -0.352100, 10.192300, 0.083170),
(338, 'Male', 78.7, -0.352100, 10.210500, 0.083170),
(339, 'Male', 78.8, -0.352100, 10.228600, 0.083170),
(340, 'Male', 78.9, -0.352100, 10.246800, 0.083160),
(341, 'Male', 79.0, -0.352100, 10.264900, 0.083160),
(342, 'Male', 79.1, -0.352100, 10.283100, 0.083150),
(343, 'Male', 79.2, -0.352100, 10.301200, 0.083150),
(344, 'Male', 79.3, -0.352100, 10.319400, 0.083140),
(345, 'Male', 79.4, -0.352100, 10.337600, 0.083130),
(346, 'Male', 79.5, -0.352100, 10.355800, 0.083130),
(347, 'Male', 79.6, -0.352100, 10.374100, 0.083120),
(348, 'Male', 79.7, -0.352100, 10.392300, 0.083110),
(349, 'Male', 79.8, -0.352100, 10.410700, 0.083100),
(350, 'Male', 79.9, -0.352100, 10.429100, 0.083090),
(351, 'Male', 80.0, -0.352100, 10.447500, 0.083080),
(352, 'Male', 80.1, -0.352100, 10.466000, 0.083070),
(353, 'Male', 80.2, -0.352100, 10.484500, 0.083050),
(354, 'Male', 80.3, -0.352100, 10.503100, 0.083040),
(355, 'Male', 80.4, -0.352100, 10.521700, 0.083030),
(356, 'Male', 80.5, -0.352100, 10.540500, 0.083010),
(357, 'Male', 80.6, -0.352100, 10.559200, 0.083000),
(358, 'Male', 80.7, -0.352100, 10.578100, 0.082980),
(359, 'Male', 80.8, -0.352100, 10.597000, 0.082970),
(360, 'Male', 80.9, -0.352100, 10.616100, 0.082950),
(361, 'Male', 81.0, -0.352100, 10.635200, 0.082930),
(362, 'Male', 81.1, -0.352100, 10.654400, 0.082910),
(363, 'Male', 81.2, -0.352100, 10.673700, 0.082900),
(364, 'Male', 81.3, -0.352100, 10.693100, 0.082880),
(365, 'Male', 81.4, -0.352100, 10.712600, 0.082860),
(366, 'Male', 81.5, -0.352100, 10.732200, 0.082840),
(367, 'Male', 81.6, -0.352100, 10.752000, 0.082820),
(368, 'Male', 81.7, -0.352100, 10.771800, 0.082790),
(369, 'Male', 81.8, -0.352100, 10.791800, 0.082770),
(370, 'Male', 81.9, -0.352100, 10.811900, 0.082750),
(371, 'Male', 82.0, -0.352100, 10.832100, 0.082730),
(372, 'Male', 82.1, -0.352100, 10.852400, 0.082700),
(373, 'Male', 82.2, -0.352100, 10.872800, 0.082680),
(374, 'Male', 82.3, -0.352100, 10.893400, 0.082650),
(375, 'Male', 82.4, -0.352100, 10.914200, 0.082630),
(376, 'Male', 82.5, -0.352100, 10.935000, 0.082600),
(377, 'Male', 82.6, -0.352100, 10.956000, 0.082580),
(378, 'Male', 82.7, -0.352100, 10.977200, 0.082550),
(379, 'Male', 82.8, -0.352100, 10.998500, 0.082520),
(380, 'Male', 82.9, -0.352100, 11.019900, 0.082490),
(381, 'Male', 83.0, -0.352100, 11.041500, 0.082460),
(382, 'Male', 83.1, -0.352100, 11.063200, 0.082440),
(383, 'Male', 83.2, -0.352100, 11.085100, 0.082410),
(384, 'Male', 83.3, -0.352100, 11.107100, 0.082380),
(385, 'Male', 83.4, -0.352100, 11.129300, 0.082350),
(386, 'Male', 83.5, -0.352100, 11.151600, 0.082310),
(387, 'Male', 83.6, -0.352100, 11.174000, 0.082280),
(388, 'Male', 83.7, -0.352100, 11.196600, 0.082250),
(389, 'Male', 83.8, -0.352100, 11.219300, 0.082220),
(390, 'Male', 83.9, -0.352100, 11.242200, 0.082190),
(391, 'Male', 84.0, -0.352100, 11.265100, 0.082150),
(392, 'Male', 84.1, -0.352100, 11.288200, 0.082120),
(393, 'Male', 84.2, -0.352100, 11.311400, 0.082090),
(394, 'Male', 84.3, -0.352100, 11.334700, 0.082050),
(395, 'Male', 84.4, -0.352100, 11.358100, 0.082020),
(396, 'Male', 84.5, -0.352100, 11.381700, 0.081980),
(397, 'Male', 84.6, -0.352100, 11.405300, 0.081950),
(398, 'Male', 84.7, -0.352100, 11.429000, 0.081910),
(399, 'Male', 84.8, -0.352100, 11.452900, 0.081880),
(400, 'Male', 84.9, -0.352100, 11.476800, 0.081840),
(401, 'Male', 85.0, -0.352100, 11.500700, 0.081810),
(402, 'Male', 85.1, -0.352100, 11.524800, 0.081770),
(403, 'Male', 85.2, -0.352100, 11.549000, 0.081740),
(404, 'Male', 85.3, -0.352100, 11.573200, 0.081700),
(405, 'Male', 85.4, -0.352100, 11.597500, 0.081660),
(406, 'Male', 85.5, -0.352100, 11.621800, 0.081630),
(407, 'Male', 85.6, -0.352100, 11.646200, 0.081590),
(408, 'Male', 85.7, -0.352100, 11.670700, 0.081560),
(409, 'Male', 85.8, -0.352100, 11.695200, 0.081520),
(410, 'Male', 85.9, -0.352100, 11.719800, 0.081480),
(411, 'Male', 86.0, -0.352100, 11.744400, 0.081450),
(412, 'Male', 86.1, -0.352100, 11.769000, 0.081410),
(413, 'Male', 86.2, -0.352100, 11.793700, 0.081380),
(414, 'Male', 86.3, -0.352100, 11.818400, 0.081340),
(415, 'Male', 86.4, -0.352100, 11.843100, 0.081310),
(416, 'Male', 86.5, -0.352100, 11.867800, 0.081280),
(417, 'Male', 86.6, -0.352100, 11.892600, 0.081240),
(418, 'Male', 86.7, -0.352100, 11.917300, 0.081210),
(419, 'Male', 86.8, -0.352100, 11.942100, 0.081180),
(420, 'Male', 86.9, -0.352100, 11.966800, 0.081140),
(421, 'Male', 87.0, -0.352100, 11.991600, 0.081110),
(422, 'Male', 87.1, -0.352100, 12.016300, 0.081080),
(423, 'Male', 87.2, -0.352100, 12.041100, 0.081050),
(424, 'Male', 87.3, -0.352100, 12.065800, 0.081020),
(425, 'Male', 87.4, -0.352100, 12.090500, 0.080990),
(426, 'Male', 87.5, -0.352100, 12.115200, 0.080960),
(427, 'Male', 87.6, -0.352100, 12.139800, 0.080930),
(428, 'Male', 87.7, -0.352100, 12.164500, 0.080900),
(429, 'Male', 87.8, -0.352100, 12.189100, 0.080870),
(430, 'Male', 87.9, -0.352100, 12.213600, 0.080840),
(431, 'Male', 88.0, -0.352100, 12.238200, 0.080820),
(432, 'Male', 88.1, -0.352100, 12.262700, 0.080790),
(433, 'Male', 88.2, -0.352100, 12.287100, 0.080760),
(434, 'Male', 88.3, -0.352100, 12.311600, 0.080740),
(435, 'Male', 88.4, -0.352100, 12.336000, 0.080710),
(436, 'Male', 88.5, -0.352100, 12.360300, 0.080690),
(437, 'Male', 88.6, -0.352100, 12.384600, 0.080670),
(438, 'Male', 88.7, -0.352100, 12.408900, 0.080640),
(439, 'Male', 88.8, -0.352100, 12.433200, 0.080620),
(440, 'Male', 88.9, -0.352100, 12.457400, 0.080600),
(441, 'Male', 89.0, -0.352100, 12.481500, 0.080580),
(442, 'Male', 89.1, -0.352100, 12.505700, 0.080560),
(443, 'Male', 89.2, -0.352100, 12.529800, 0.080540),
(444, 'Male', 89.3, -0.352100, 12.553800, 0.080520),
(445, 'Male', 89.4, -0.352100, 12.577800, 0.080500),
(446, 'Male', 89.5, -0.352100, 12.601700, 0.080480),
(447, 'Male', 89.6, -0.352100, 12.625700, 0.080470),
(448, 'Male', 89.7, -0.352100, 12.649500, 0.080450),
(449, 'Male', 89.8, -0.352100, 12.673400, 0.080440),
(450, 'Male', 89.9, -0.352100, 12.697200, 0.080420),
(451, 'Male', 90.0, -0.352100, 12.720900, 0.080410),
(452, 'Male', 90.1, -0.352100, 12.744600, 0.080390),
(453, 'Male', 90.2, -0.352100, 12.768300, 0.080380),
(454, 'Male', 90.3, -0.352100, 12.792000, 0.080370),
(455, 'Male', 90.4, -0.352100, 12.815600, 0.080350),
(456, 'Male', 90.5, -0.352100, 12.839200, 0.080340),
(457, 'Male', 90.6, -0.352100, 12.862800, 0.080330),
(458, 'Male', 90.7, -0.352100, 12.886400, 0.080320),
(459, 'Male', 90.8, -0.352100, 12.909900, 0.080310),
(460, 'Male', 90.9, -0.352100, 12.933400, 0.080300),
(461, 'Male', 91.0, -0.352100, 12.956900, 0.080300),
(462, 'Male', 91.1, -0.352100, 12.980400, 0.080290),
(463, 'Male', 91.2, -0.352100, 13.003800, 0.080280),
(464, 'Male', 91.3, -0.352100, 13.027300, 0.080270),
(465, 'Male', 91.4, -0.352100, 13.050700, 0.080270),
(466, 'Male', 91.5, -0.352100, 13.074200, 0.080260),
(467, 'Male', 91.6, -0.352100, 13.097600, 0.080260),
(468, 'Male', 91.7, -0.352100, 13.120900, 0.080250),
(469, 'Male', 91.8, -0.352100, 13.144300, 0.080250),
(470, 'Male', 91.9, -0.352100, 13.167700, 0.080250),
(471, 'Male', 92.0, -0.352100, 13.191000, 0.080250),
(472, 'Male', 92.1, -0.352100, 13.214300, 0.080250),
(473, 'Male', 92.2, -0.352100, 13.237600, 0.080240),
(474, 'Male', 92.3, -0.352100, 13.260900, 0.080240),
(475, 'Male', 92.4, -0.352100, 13.284200, 0.080240),
(476, 'Male', 92.5, -0.352100, 13.307500, 0.080250),
(477, 'Male', 92.6, -0.352100, 13.330800, 0.080250),
(478, 'Male', 92.7, -0.352100, 13.354100, 0.080250),
(479, 'Male', 92.8, -0.352100, 13.377300, 0.080250),
(480, 'Male', 92.9, -0.352100, 13.400600, 0.080260),
(481, 'Male', 93.0, -0.352100, 13.423900, 0.080260),
(482, 'Male', 93.1, -0.352100, 13.447200, 0.080270),
(483, 'Male', 93.2, -0.352100, 13.470500, 0.080270),
(484, 'Male', 93.3, -0.352100, 13.493700, 0.080280),
(485, 'Male', 93.4, -0.352100, 13.517100, 0.080280),
(486, 'Male', 93.5, -0.352100, 13.540400, 0.080290),
(487, 'Male', 93.6, -0.352100, 13.563700, 0.080300),
(488, 'Male', 93.7, -0.352100, 13.587000, 0.080310),
(489, 'Male', 93.8, -0.352100, 13.610400, 0.080320),
(490, 'Male', 93.9, -0.352100, 13.633800, 0.080330),
(491, 'Male', 94.0, -0.352100, 13.657200, 0.080340),
(492, 'Male', 94.1, -0.352100, 13.680600, 0.080350),
(493, 'Male', 94.2, -0.352100, 13.704100, 0.080360),
(494, 'Male', 94.3, -0.352100, 13.727500, 0.080370),
(495, 'Male', 94.4, -0.352100, 13.751000, 0.080380),
(496, 'Male', 94.5, -0.352100, 13.774600, 0.080400),
(497, 'Male', 94.6, -0.352100, 13.798100, 0.080410),
(498, 'Male', 94.7, -0.352100, 13.821700, 0.080430),
(499, 'Male', 94.8, -0.352100, 13.845400, 0.080440),
(500, 'Male', 94.9, -0.352100, 13.869100, 0.080460),
(501, 'Male', 95.0, -0.352100, 13.892800, 0.080470),
(502, 'Male', 95.1, -0.352100, 13.916500, 0.080490),
(503, 'Male', 95.2, -0.352100, 13.940300, 0.080510),
(504, 'Male', 95.3, -0.352100, 13.964200, 0.080520),
(505, 'Male', 95.4, -0.352100, 13.988100, 0.080540),
(506, 'Male', 95.5, -0.352100, 14.012000, 0.080560),
(507, 'Male', 95.6, -0.352100, 14.036000, 0.080580),
(508, 'Male', 95.7, -0.352100, 14.060000, 0.080600),
(509, 'Male', 95.8, -0.352100, 14.084100, 0.080620),
(510, 'Male', 95.9, -0.352100, 14.108300, 0.080640),
(511, 'Male', 96.0, -0.352100, 14.132500, 0.080670),
(512, 'Male', 96.1, -0.352100, 14.156700, 0.080690),
(513, 'Male', 96.2, -0.352100, 14.181100, 0.080710),
(514, 'Male', 96.3, -0.352100, 14.205500, 0.080730),
(515, 'Male', 96.4, -0.352100, 14.229900, 0.080760),
(516, 'Male', 96.5, -0.352100, 14.254400, 0.080780),
(517, 'Male', 96.6, -0.352100, 14.279000, 0.080810),
(518, 'Male', 96.7, -0.352100, 14.303700, 0.080830),
(519, 'Male', 96.8, -0.352100, 14.328400, 0.080860),
(520, 'Male', 96.9, -0.352100, 14.353300, 0.080890),
(521, 'Male', 97.0, -0.352100, 14.378200, 0.080920),
(522, 'Male', 97.1, -0.352100, 14.403100, 0.080940),
(523, 'Male', 97.2, -0.352100, 14.428200, 0.080970),
(524, 'Male', 97.3, -0.352100, 14.453300, 0.081000),
(525, 'Male', 97.4, -0.352100, 14.478500, 0.081030),
(526, 'Male', 97.5, -0.352100, 14.503800, 0.081060),
(527, 'Male', 97.6, -0.352100, 14.529200, 0.081090),
(528, 'Male', 97.7, -0.352100, 14.554700, 0.081120),
(529, 'Male', 97.8, -0.352100, 14.580200, 0.081160),
(530, 'Male', 97.9, -0.352100, 14.605800, 0.081190),
(531, 'Male', 98.0, -0.352100, 14.631600, 0.081220),
(532, 'Male', 98.1, -0.352100, 14.657400, 0.081250),
(533, 'Male', 98.2, -0.352100, 14.683200, 0.081290),
(534, 'Male', 98.3, -0.352100, 14.709200, 0.081320),
(535, 'Male', 98.4, -0.352100, 14.735300, 0.081360),
(536, 'Male', 98.5, -0.352100, 14.761400, 0.081390),
(537, 'Male', 98.6, -0.352100, 14.787700, 0.081430),
(538, 'Male', 98.7, -0.352100, 14.814000, 0.081460),
(539, 'Male', 98.8, -0.352100, 14.840400, 0.081500),
(540, 'Male', 98.9, -0.352100, 14.866900, 0.081540),
(541, 'Male', 99.0, -0.352100, 14.893400, 0.081570),
(542, 'Male', 99.1, -0.352100, 14.920100, 0.081610),
(543, 'Male', 99.2, -0.352100, 14.946800, 0.081650),
(544, 'Male', 99.3, -0.352100, 14.973600, 0.081690),
(545, 'Male', 99.4, -0.352100, 15.000500, 0.081730),
(546, 'Male', 99.5, -0.352100, 15.027500, 0.081770),
(547, 'Male', 99.6, -0.352100, 15.054600, 0.081810),
(548, 'Male', 99.7, -0.352100, 15.081800, 0.081850),
(549, 'Male', 99.8, -0.352100, 15.109000, 0.081890),
(550, 'Male', 99.9, -0.352100, 15.136300, 0.081940),
(551, 'Male', 100.0, -0.352100, 15.163700, 0.081980),
(552, 'Male', 100.1, -0.352100, 15.191200, 0.082020),
(553, 'Male', 100.2, -0.352100, 15.218700, 0.082060),
(554, 'Male', 100.3, -0.352100, 15.246300, 0.082110),
(555, 'Male', 100.4, -0.352100, 15.274000, 0.082150),
(556, 'Male', 100.5, -0.352100, 15.301800, 0.082200),
(557, 'Male', 100.6, -0.352100, 15.329700, 0.082240),
(558, 'Male', 100.7, -0.352100, 15.357600, 0.082290),
(559, 'Male', 100.8, -0.352100, 15.385600, 0.082330),
(560, 'Male', 100.9, -0.352100, 15.413700, 0.082380),
(561, 'Male', 101.0, -0.352100, 15.441900, 0.082430),
(562, 'Male', 101.1, -0.352100, 15.470100, 0.082470),
(563, 'Male', 101.2, -0.352100, 15.498500, 0.082520),
(564, 'Male', 101.3, -0.352100, 15.526800, 0.082570),
(565, 'Male', 101.4, -0.352100, 15.555300, 0.082620),
(566, 'Male', 101.5, -0.352100, 15.583800, 0.082670),
(567, 'Male', 101.6, -0.352100, 15.612500, 0.082720),
(568, 'Male', 101.7, -0.352100, 15.641200, 0.082770),
(569, 'Male', 101.8, -0.352100, 15.669900, 0.082810),
(570, 'Male', 101.9, -0.352100, 15.698700, 0.082870),
(571, 'Male', 102.0, -0.352100, 15.727600, 0.082920),
(572, 'Male', 102.1, -0.352100, 15.756600, 0.082970),
(573, 'Male', 102.2, -0.352100, 15.785700, 0.083020),
(574, 'Male', 102.3, -0.352100, 15.814800, 0.083070),
(575, 'Male', 102.4, -0.352100, 15.844000, 0.083120),
(576, 'Male', 102.5, -0.352100, 15.873200, 0.083170),
(577, 'Male', 102.6, -0.352100, 15.902600, 0.083220),
(578, 'Male', 102.7, -0.352100, 15.932000, 0.083280),
(579, 'Male', 102.8, -0.352100, 15.961500, 0.083330),
(580, 'Male', 102.9, -0.352100, 15.991000, 0.083380),
(581, 'Male', 103.0, -0.352100, 16.020600, 0.083430),
(582, 'Male', 103.1, -0.352100, 16.050300, 0.083490),
(583, 'Male', 103.2, -0.352100, 16.080100, 0.083540),
(584, 'Male', 103.3, -0.352100, 16.109900, 0.083590),
(585, 'Male', 103.4, -0.352100, 16.139800, 0.083650),
(586, 'Male', 103.5, -0.352100, 16.169700, 0.083700),
(587, 'Male', 103.6, -0.352100, 16.199700, 0.083760),
(588, 'Male', 103.7, -0.352100, 16.229800, 0.083810),
(589, 'Male', 103.8, -0.352100, 16.260000, 0.083860),
(590, 'Male', 103.9, -0.352100, 16.290200, 0.083920),
(591, 'Male', 104.0, -0.352100, 16.320400, 0.083970),
(592, 'Male', 104.1, -0.352100, 16.350800, 0.084030),
(593, 'Male', 104.2, -0.352100, 16.381200, 0.084080),
(594, 'Male', 104.3, -0.352100, 16.411700, 0.084140),
(595, 'Male', 104.4, -0.352100, 16.442200, 0.084190),
(596, 'Male', 104.5, -0.352100, 16.472800, 0.084250),
(597, 'Male', 104.6, -0.352100, 16.503500, 0.084310),
(598, 'Male', 104.7, -0.352100, 16.534200, 0.084360),
(599, 'Male', 104.8, -0.352100, 16.565000, 0.084420),
(600, 'Male', 104.9, -0.352100, 16.595900, 0.084470),
(601, 'Male', 105.0, -0.352100, 16.626800, 0.084530),
(602, 'Male', 105.1, -0.352100, 16.657900, 0.084580),
(603, 'Male', 105.2, -0.352100, 16.688900, 0.084640),
(604, 'Male', 105.3, -0.352100, 16.720100, 0.084700),
(605, 'Male', 105.4, -0.352100, 16.751300, 0.084750),
(606, 'Male', 105.5, -0.352100, 16.782600, 0.084810),
(607, 'Male', 105.6, -0.352100, 16.813900, 0.084870),
(608, 'Male', 105.7, -0.352100, 16.845400, 0.084930),
(609, 'Male', 105.8, -0.352100, 16.876900, 0.084980),
(610, 'Male', 105.9, -0.352100, 16.908400, 0.085040),
(611, 'Male', 106.0, -0.352100, 16.940100, 0.085100),
(612, 'Male', 106.1, -0.352100, 16.971800, 0.085160),
(613, 'Male', 106.2, -0.352100, 17.003600, 0.085210),
(614, 'Male', 106.3, -0.352100, 17.035500, 0.085270),
(615, 'Male', 106.4, -0.352100, 17.067400, 0.085330),
(616, 'Male', 106.5, -0.352100, 17.099500, 0.085390),
(617, 'Male', 106.6, -0.352100, 17.131600, 0.085450),
(618, 'Male', 106.7, -0.352100, 17.163700, 0.085510),
(619, 'Male', 106.8, -0.352100, 17.196000, 0.085570),
(620, 'Male', 106.9, -0.352100, 17.228300, 0.085620),
(621, 'Male', 107.0, -0.352100, 17.260700, 0.085680),
(622, 'Male', 107.1, -0.352100, 17.293100, 0.085740),
(623, 'Male', 107.2, -0.352100, 17.325600, 0.085800),
(624, 'Male', 107.3, -0.352100, 17.358200, 0.085860),
(625, 'Male', 107.4, -0.352100, 17.390900, 0.085920),
(626, 'Male', 107.5, -0.352100, 17.423700, 0.085990),
(627, 'Male', 107.6, -0.352100, 17.456500, 0.086050),
(628, 'Male', 107.7, -0.352100, 17.489400, 0.086110),
(629, 'Male', 107.8, -0.352100, 17.522400, 0.086170),
(630, 'Male', 107.9, -0.352100, 17.555400, 0.086230),
(631, 'Male', 108.0, -0.352100, 17.588500, 0.086290),
(632, 'Male', 108.1, -0.352100, 17.621700, 0.086350),
(633, 'Male', 108.2, -0.352100, 17.655000, 0.086410),
(634, 'Male', 108.3, -0.352100, 17.688400, 0.086480),
(635, 'Male', 108.4, -0.352100, 17.721800, 0.086540),
(636, 'Male', 108.5, -0.352100, 17.755300, 0.086600),
(637, 'Male', 108.6, -0.352100, 17.788900, 0.086660),
(638, 'Male', 108.7, -0.352100, 17.822600, 0.086730),
(639, 'Male', 108.8, -0.352100, 17.856400, 0.086790),
(640, 'Male', 108.9, -0.352100, 17.890300, 0.086850),
(641, 'Male', 109.0, -0.352100, 17.924200, 0.086910),
(642, 'Male', 109.1, -0.352100, 17.958300, 0.086980),
(643, 'Male', 109.2, -0.352100, 17.992400, 0.087040),
(644, 'Male', 109.3, -0.352100, 18.026700, 0.087100),
(645, 'Male', 109.4, -0.352100, 18.061000, 0.087170),
(646, 'Male', 109.5, -0.352100, 18.095400, 0.087230),
(647, 'Male', 109.6, -0.352100, 18.129900, 0.087300),
(648, 'Male', 109.7, -0.352100, 18.164500, 0.087360),
(649, 'Male', 109.8, -0.352100, 18.199200, 0.087420),
(650, 'Male', 109.9, -0.352100, 18.234000, 0.087490),
(651, 'Male', 110.0, -0.352100, 18.268900, 0.087550),
(652, 'Female', 45.0, -0.383300, 2.460700, 0.090290),
(653, 'Female', 45.1, -0.383300, 2.477700, 0.090300),
(654, 'Female', 45.2, -0.383300, 2.494700, 0.090300),
(655, 'Female', 45.3, -0.383300, 2.511700, 0.090310),
(656, 'Female', 45.4, -0.383300, 2.528700, 0.090320),
(657, 'Female', 45.5, -0.383300, 2.545700, 0.090330),
(658, 'Female', 45.6, -0.383300, 2.562700, 0.090330),
(659, 'Female', 45.7, -0.383300, 2.579700, 0.090340),
(660, 'Female', 45.8, -0.383300, 2.596700, 0.090350),
(661, 'Female', 45.9, -0.383300, 2.613700, 0.090360),
(662, 'Female', 46.0, -0.383300, 2.630600, 0.090370),
(663, 'Female', 46.1, -0.383300, 2.647600, 0.090370),
(664, 'Female', 46.2, -0.383300, 2.664600, 0.090380),
(665, 'Female', 46.3, -0.383300, 2.681600, 0.090390),
(666, 'Female', 46.4, -0.383300, 2.698600, 0.090400),
(667, 'Female', 46.5, -0.383300, 2.715500, 0.090400),
(668, 'Female', 46.6, -0.383300, 2.732600, 0.090410),
(669, 'Female', 46.7, -0.383300, 2.749600, 0.090420),
(670, 'Female', 46.8, -0.383300, 2.766600, 0.090430),
(671, 'Female', 46.9, -0.383300, 2.783700, 0.090440),
(672, 'Female', 47.0, -0.383300, 2.800700, 0.090440),
(673, 'Female', 47.1, -0.383300, 2.817900, 0.090450),
(674, 'Female', 47.2, -0.383300, 2.835000, 0.090460),
(675, 'Female', 47.3, -0.383300, 2.852200, 0.090470),
(676, 'Female', 47.4, -0.383300, 2.869400, 0.090470),
(677, 'Female', 47.5, -0.383300, 2.886700, 0.090480),
(678, 'Female', 47.6, -0.383300, 2.904100, 0.090490),
(679, 'Female', 47.7, -0.383300, 2.921500, 0.090500),
(680, 'Female', 47.8, -0.383300, 2.939000, 0.090500),
(681, 'Female', 47.9, -0.383300, 2.956500, 0.090510),
(682, 'Female', 48.0, -0.383300, 2.974100, 0.090520),
(683, 'Female', 48.1, -0.383300, 2.991800, 0.090530),
(684, 'Female', 48.2, -0.383300, 3.009600, 0.090540),
(685, 'Female', 48.3, -0.383300, 3.027500, 0.090540),
(686, 'Female', 48.4, -0.383300, 3.045500, 0.090550),
(687, 'Female', 48.5, -0.383300, 3.063600, 0.090560),
(688, 'Female', 48.6, -0.383300, 3.081800, 0.090570),
(689, 'Female', 48.7, -0.383300, 3.100100, 0.090570),
(690, 'Female', 48.8, -0.383300, 3.118600, 0.090580),
(691, 'Female', 48.9, -0.383300, 3.137200, 0.090590),
(692, 'Female', 49.0, -0.383300, 3.156000, 0.090600),
(693, 'Female', 49.1, -0.383300, 3.174900, 0.090610),
(694, 'Female', 49.2, -0.383300, 3.193900, 0.090610),
(695, 'Female', 49.3, -0.383300, 3.213100, 0.090620),
(696, 'Female', 49.4, -0.383300, 3.232500, 0.090630),
(697, 'Female', 49.5, -0.383300, 3.252000, 0.090640),
(698, 'Female', 49.6, -0.383300, 3.271700, 0.090650),
(699, 'Female', 49.7, -0.383300, 3.291500, 0.090650),
(700, 'Female', 49.8, -0.383300, 3.311400, 0.090660),
(701, 'Female', 49.9, -0.383300, 3.331600, 0.090670),
(702, 'Female', 50.0, -0.383300, 3.351800, 0.090680),
(703, 'Female', 50.1, -0.383300, 3.372300, 0.090690),
(704, 'Female', 50.2, -0.383300, 3.392900, 0.090690),
(705, 'Female', 50.3, -0.383300, 3.413600, 0.090700),
(706, 'Female', 50.4, -0.383300, 3.434600, 0.090710),
(707, 'Female', 50.5, -0.383300, 3.455700, 0.090720),
(708, 'Female', 50.6, -0.383300, 3.476900, 0.090730),
(709, 'Female', 50.7, -0.383300, 3.498300, 0.090740),
(710, 'Female', 50.8, -0.383300, 3.519900, 0.090740),
(711, 'Female', 50.9, -0.383300, 3.541700, 0.090750),
(712, 'Female', 51.0, -0.383300, 3.563600, 0.090760),
(713, 'Female', 51.1, -0.383300, 3.585600, 0.090770),
(714, 'Female', 51.2, -0.383300, 3.607800, 0.090780),
(715, 'Female', 51.3, -0.383300, 3.630200, 0.090790),
(716, 'Female', 51.4, -0.383300, 3.652700, 0.090800),
(717, 'Female', 51.5, -0.383300, 3.675400, 0.090800),
(718, 'Female', 51.6, -0.383300, 3.698200, 0.090810),
(719, 'Female', 51.7, -0.383300, 3.721200, 0.090820),
(720, 'Female', 51.8, -0.383300, 3.744400, 0.090830),
(721, 'Female', 51.9, -0.383300, 3.767700, 0.090840),
(722, 'Female', 52.0, -0.383300, 3.791100, 0.090850),
(723, 'Female', 52.1, -0.383300, 3.814700, 0.090860),
(724, 'Female', 52.2, -0.383300, 3.838500, 0.090860),
(725, 'Female', 52.3, -0.383300, 3.862300, 0.090870),
(726, 'Female', 52.4, -0.383300, 3.886300, 0.090880),
(727, 'Female', 52.5, -0.383300, 3.910500, 0.090890),
(728, 'Female', 52.6, -0.383300, 3.934800, 0.090900),
(729, 'Female', 52.7, -0.383300, 3.959200, 0.090910),
(730, 'Female', 52.8, -0.383300, 3.983700, 0.090920),
(731, 'Female', 52.9, -0.383300, 4.008400, 0.090930),
(732, 'Female', 53.0, -0.383300, 4.033200, 0.090930),
(733, 'Female', 53.1, -0.383300, 4.058100, 0.090940),
(734, 'Female', 53.2, -0.383300, 4.083200, 0.090950),
(735, 'Female', 53.3, -0.383300, 4.108400, 0.090960),
(736, 'Female', 53.4, -0.383300, 4.133700, 0.090970),
(737, 'Female', 53.5, -0.383300, 4.159100, 0.090980),
(738, 'Female', 53.6, -0.383300, 4.184600, 0.090990),
(739, 'Female', 53.7, -0.383300, 4.210200, 0.090990),
(740, 'Female', 53.8, -0.383300, 4.235900, 0.091000),
(741, 'Female', 53.9, -0.383300, 4.261700, 0.091010),
(742, 'Female', 54.0, -0.383300, 4.287500, 0.091020),
(743, 'Female', 54.1, -0.383300, 4.313500, 0.091030),
(744, 'Female', 54.2, -0.383300, 4.339500, 0.091040),
(745, 'Female', 54.3, -0.383300, 4.365500, 0.091050),
(746, 'Female', 54.4, -0.383300, 4.391700, 0.091050),
(747, 'Female', 54.5, -0.383300, 4.417900, 0.091060),
(748, 'Female', 54.6, -0.383300, 4.444200, 0.091070),
(749, 'Female', 54.7, -0.383300, 4.470500, 0.091080),
(750, 'Female', 54.8, -0.383300, 4.496900, 0.091090),
(751, 'Female', 54.9, -0.383300, 4.523300, 0.091090),
(752, 'Female', 55.0, -0.383300, 4.549800, 0.091100),
(753, 'Female', 55.1, -0.383300, 4.576300, 0.091110),
(754, 'Female', 55.2, -0.383300, 4.602900, 0.091120),
(755, 'Female', 55.3, -0.383300, 4.629500, 0.091130),
(756, 'Female', 55.4, -0.383300, 4.656100, 0.091130),
(757, 'Female', 55.5, -0.383300, 4.682700, 0.091140),
(758, 'Female', 55.6, -0.383300, 4.709400, 0.091150),
(759, 'Female', 55.7, -0.383300, 4.736100, 0.091160),
(760, 'Female', 55.8, -0.383300, 4.762800, 0.091160),
(761, 'Female', 55.9, -0.383300, 4.789500, 0.091170),
(762, 'Female', 56.0, -0.383300, 4.816200, 0.091180),
(763, 'Female', 56.1, -0.383300, 4.843000, 0.091190),
(764, 'Female', 56.2, -0.383300, 4.869700, 0.091190),
(765, 'Female', 56.3, -0.383300, 4.896400, 0.091200),
(766, 'Female', 56.4, -0.383300, 4.923200, 0.091210),
(767, 'Female', 56.5, -0.383300, 4.950000, 0.091210),
(768, 'Female', 56.6, -0.383300, 4.976700, 0.091220),
(769, 'Female', 56.7, -0.383300, 5.003400, 0.091230),
(770, 'Female', 56.8, -0.383300, 5.030200, 0.091230),
(771, 'Female', 56.9, -0.383300, 5.056900, 0.091240),
(772, 'Female', 57.0, -0.383300, 5.083700, 0.091250),
(773, 'Female', 57.1, -0.383300, 5.110400, 0.091250),
(774, 'Female', 57.2, -0.383300, 5.137100, 0.091260),
(775, 'Female', 57.3, -0.383300, 5.163900, 0.091260),
(776, 'Female', 57.4, -0.383300, 5.190600, 0.091270),
(777, 'Female', 57.5, -0.383300, 5.217300, 0.091280),
(778, 'Female', 57.6, -0.383300, 5.244000, 0.091280),
(779, 'Female', 57.7, -0.383300, 5.270700, 0.091290),
(780, 'Female', 57.8, -0.383300, 5.297400, 0.091290),
(781, 'Female', 57.9, -0.383300, 5.324000, 0.091300),
(782, 'Female', 58.0, -0.383300, 5.350700, 0.091300),
(783, 'Female', 58.1, -0.383300, 5.377300, 0.091310),
(784, 'Female', 58.2, -0.383300, 5.403900, 0.091310),
(785, 'Female', 58.3, -0.383300, 5.430400, 0.091310),
(786, 'Female', 58.4, -0.383300, 5.456900, 0.091320),
(787, 'Female', 58.5, -0.383300, 5.483400, 0.091320),
(788, 'Female', 58.6, -0.383300, 5.509800, 0.091330),
(789, 'Female', 58.7, -0.383300, 5.536200, 0.091330),
(790, 'Female', 58.8, -0.383300, 5.562500, 0.091330),
(791, 'Female', 58.9, -0.383300, 5.588800, 0.091340),
(792, 'Female', 59.0, -0.383300, 5.615100, 0.091340),
(793, 'Female', 59.1, -0.383300, 5.641300, 0.091340),
(794, 'Female', 59.2, -0.383300, 5.667400, 0.091350),
(795, 'Female', 59.3, -0.383300, 5.693500, 0.091350),
(796, 'Female', 59.4, -0.383300, 5.719500, 0.091350),
(797, 'Female', 59.5, -0.383300, 5.745400, 0.091350),
(798, 'Female', 59.6, -0.383300, 5.771300, 0.091360),
(799, 'Female', 59.7, -0.383300, 5.797100, 0.091360),
(800, 'Female', 59.8, -0.383300, 5.822900, 0.091360),
(801, 'Female', 59.9, -0.383300, 5.848500, 0.091360),
(802, 'Female', 60.0, -0.383300, 5.874200, 0.091360),
(803, 'Female', 60.1, -0.383300, 5.899700, 0.091360),
(804, 'Female', 60.2, -0.383300, 5.925200, 0.091370),
(805, 'Female', 60.3, -0.383300, 5.950700, 0.091370),
(806, 'Female', 60.4, -0.383300, 5.976100, 0.091370),
(807, 'Female', 60.5, -0.383300, 6.001400, 0.091370),
(808, 'Female', 60.6, -0.383300, 6.026600, 0.091370),
(809, 'Female', 60.7, -0.383300, 6.051800, 0.091370),
(810, 'Female', 60.8, -0.383300, 6.076900, 0.091370),
(811, 'Female', 60.9, -0.383300, 6.102000, 0.091370),
(812, 'Female', 61.0, -0.383300, 6.127000, 0.091370),
(813, 'Female', 61.1, -0.383300, 6.151900, 0.091370),
(814, 'Female', 61.2, -0.383300, 6.176800, 0.091360),
(815, 'Female', 61.3, -0.383300, 6.201700, 0.091360),
(816, 'Female', 61.4, -0.383300, 6.226400, 0.091360),
(817, 'Female', 61.5, -0.383300, 6.251100, 0.091360),
(818, 'Female', 61.6, -0.383300, 6.275800, 0.091360),
(819, 'Female', 61.7, -0.383300, 6.300400, 0.091360),
(820, 'Female', 61.8, -0.383300, 6.324900, 0.091350),
(821, 'Female', 61.9, -0.383300, 6.349400, 0.091350),
(822, 'Female', 62.0, -0.383300, 6.373800, 0.091350),
(823, 'Female', 62.1, -0.383300, 6.398100, 0.091350),
(824, 'Female', 62.2, -0.383300, 6.422400, 0.091340),
(825, 'Female', 62.3, -0.383300, 6.446600, 0.091340),
(826, 'Female', 62.4, -0.383300, 6.470800, 0.091340),
(827, 'Female', 62.5, -0.383300, 6.494800, 0.091330),
(828, 'Female', 62.6, -0.383300, 6.518900, 0.091330),
(829, 'Female', 62.7, -0.383300, 6.542900, 0.091330),
(830, 'Female', 62.8, -0.383300, 6.566800, 0.091320),
(831, 'Female', 62.9, -0.383300, 6.590600, 0.091320),
(832, 'Female', 63.0, -0.383300, 6.614400, 0.091310),
(833, 'Female', 63.1, -0.383300, 6.638200, 0.091310),
(834, 'Female', 63.2, -0.383300, 6.661900, 0.091300),
(835, 'Female', 63.3, -0.383300, 6.685600, 0.091300),
(836, 'Female', 63.4, -0.383300, 6.709200, 0.091290),
(837, 'Female', 63.5, -0.383300, 6.732800, 0.091290),
(838, 'Female', 63.6, -0.383300, 6.756300, 0.091280),
(839, 'Female', 63.7, -0.383300, 6.779800, 0.091280),
(840, 'Female', 63.8, -0.383300, 6.803300, 0.091270),
(841, 'Female', 63.9, -0.383300, 6.826700, 0.091270),
(842, 'Female', 64.0, -0.383300, 6.850100, 0.091260),
(843, 'Female', 64.1, -0.383300, 6.873400, 0.091250),
(844, 'Female', 64.2, -0.383300, 6.896700, 0.091250),
(845, 'Female', 64.3, -0.383300, 6.919900, 0.091240),
(846, 'Female', 64.4, -0.383300, 6.943100, 0.091230),
(847, 'Female', 64.5, -0.383300, 6.966200, 0.091230),
(848, 'Female', 64.6, -0.383300, 6.989300, 0.091220),
(849, 'Female', 64.7, -0.383300, 7.012400, 0.091210),
(850, 'Female', 64.8, -0.383300, 7.035400, 0.091200),
(851, 'Female', 64.9, -0.383300, 7.058300, 0.091200),
(852, 'Female', 65.0, -0.383300, 7.081200, 0.091190),
(853, 'Female', 65.1, -0.383300, 7.104100, 0.091180),
(854, 'Female', 65.2, -0.383300, 7.126900, 0.091170),
(855, 'Female', 65.3, -0.383300, 7.149700, 0.091160),
(856, 'Female', 65.4, -0.383300, 7.172400, 0.091160),
(857, 'Female', 65.5, -0.383300, 7.195000, 0.091150),
(858, 'Female', 65.6, -0.383300, 7.217700, 0.091140),
(859, 'Female', 65.7, -0.383300, 7.240200, 0.091130),
(860, 'Female', 65.8, -0.383300, 7.262700, 0.091120),
(861, 'Female', 65.9, -0.383300, 7.285200, 0.091110),
(862, 'Female', 66.0, -0.383300, 7.307600, 0.091100),
(863, 'Female', 66.1, -0.383300, 7.330000, 0.091090),
(864, 'Female', 66.2, -0.383300, 7.352300, 0.091090),
(865, 'Female', 66.3, -0.383300, 7.374500, 0.091080),
(866, 'Female', 66.4, -0.383300, 7.396700, 0.091070),
(867, 'Female', 66.5, -0.383300, 7.418900, 0.091060),
(868, 'Female', 66.6, -0.383300, 7.441000, 0.091050),
(869, 'Female', 66.7, -0.383300, 7.463000, 0.091040),
(870, 'Female', 66.8, -0.383300, 7.485000, 0.091030),
(871, 'Female', 66.9, -0.383300, 7.506900, 0.091020),
(872, 'Female', 67.0, -0.383300, 7.528800, 0.091010),
(873, 'Female', 67.1, -0.383300, 7.550700, 0.091000),
(874, 'Female', 67.2, -0.383300, 7.572400, 0.090990),
(875, 'Female', 67.3, -0.383300, 7.594200, 0.090980),
(876, 'Female', 67.4, -0.383300, 7.615800, 0.090970),
(877, 'Female', 67.5, -0.383300, 7.637500, 0.090960),
(878, 'Female', 67.6, -0.383300, 7.659000, 0.090950),
(879, 'Female', 67.7, -0.383300, 7.680600, 0.090940),
(880, 'Female', 67.8, -0.383300, 7.702000, 0.090930),
(881, 'Female', 67.9, -0.383300, 7.723400, 0.090910),
(882, 'Female', 68.0, -0.383300, 7.744800, 0.090900),
(883, 'Female', 68.1, -0.383300, 7.766100, 0.090890),
(884, 'Female', 68.2, -0.383300, 7.787400, 0.090880),
(885, 'Female', 68.3, -0.383300, 7.808600, 0.090870),
(886, 'Female', 68.4, -0.383300, 7.829800, 0.090860),
(887, 'Female', 68.5, -0.383300, 7.850900, 0.090850),
(888, 'Female', 68.6, -0.383300, 7.872000, 0.090840),
(889, 'Female', 68.7, -0.383300, 7.893000, 0.090830),
(890, 'Female', 68.8, -0.383300, 7.914000, 0.090820),
(891, 'Female', 68.9, -0.383300, 7.935000, 0.090800),
(892, 'Female', 69.0, -0.383300, 7.955900, 0.090790),
(893, 'Female', 69.1, -0.383300, 7.976800, 0.090780),
(894, 'Female', 69.2, -0.383300, 7.997600, 0.090770),
(895, 'Female', 69.3, -0.383300, 8.018400, 0.090760),
(896, 'Female', 69.4, -0.383300, 8.039200, 0.090750),
(897, 'Female', 69.5, -0.383300, 8.059900, 0.090740),
(898, 'Female', 69.6, -0.383300, 8.080600, 0.090720),
(899, 'Female', 69.7, -0.383300, 8.101200, 0.090710),
(900, 'Female', 69.8, -0.383300, 8.121800, 0.090700),
(901, 'Female', 69.9, -0.383300, 8.142400, 0.090690),
(902, 'Female', 70.0, -0.383300, 8.163000, 0.090680),
(903, 'Female', 70.1, -0.383300, 8.183500, 0.090670),
(904, 'Female', 70.2, -0.383300, 8.203900, 0.090650),
(905, 'Female', 70.3, -0.383300, 8.224400, 0.090640),
(906, 'Female', 70.4, -0.383300, 8.244800, 0.090630),
(907, 'Female', 70.5, -0.383300, 8.265100, 0.090620),
(908, 'Female', 70.6, -0.383300, 8.285500, 0.090610),
(909, 'Female', 70.7, -0.383300, 8.305800, 0.090590),
(910, 'Female', 70.8, -0.383300, 8.326100, 0.090580),
(911, 'Female', 70.9, -0.383300, 8.346400, 0.090570),
(912, 'Female', 71.0, -0.383300, 8.366600, 0.090560),
(913, 'Female', 71.1, -0.383300, 8.386900, 0.090550),
(914, 'Female', 71.2, -0.383300, 8.407100, 0.090530),
(915, 'Female', 71.3, -0.383300, 8.427300, 0.090520),
(916, 'Female', 71.4, -0.383300, 8.447400, 0.090510),
(917, 'Female', 71.5, -0.383300, 8.467600, 0.090500),
(918, 'Female', 71.6, -0.383300, 8.487700, 0.090480),
(919, 'Female', 71.7, -0.383300, 8.507800, 0.090470),
(920, 'Female', 71.8, -0.383300, 8.527800, 0.090460),
(921, 'Female', 71.9, -0.383300, 8.547900, 0.090450),
(922, 'Female', 72.0, -0.383300, 8.567900, 0.090430),
(923, 'Female', 72.1, -0.383300, 8.587900, 0.090420),
(924, 'Female', 72.2, -0.383300, 8.607800, 0.090410),
(925, 'Female', 72.3, -0.383300, 8.627700, 0.090400),
(926, 'Female', 72.4, -0.383300, 8.647600, 0.090390),
(927, 'Female', 72.5, -0.383300, 8.667400, 0.090370),
(928, 'Female', 72.6, -0.383300, 8.687200, 0.090360),
(929, 'Female', 72.7, -0.383300, 8.707000, 0.090350),
(930, 'Female', 72.8, -0.383300, 8.726700, 0.090340),
(931, 'Female', 72.9, -0.383300, 8.746400, 0.090320),
(932, 'Female', 73.0, -0.383300, 8.766100, 0.090310),
(933, 'Female', 73.1, -0.383300, 8.785700, 0.090300),
(934, 'Female', 73.2, -0.383300, 8.805300, 0.090280),
(935, 'Female', 73.3, -0.383300, 8.824800, 0.090270),
(936, 'Female', 73.4, -0.383300, 8.844300, 0.090260),
(937, 'Female', 73.5, -0.383300, 8.863800, 0.090250),
(938, 'Female', 73.6, -0.383300, 8.883100, 0.090230),
(939, 'Female', 73.7, -0.383300, 8.902500, 0.090220),
(940, 'Female', 73.8, -0.383300, 8.921700, 0.090210),
(941, 'Female', 73.9, -0.383300, 8.941000, 0.090200),
(942, 'Female', 74.0, -0.383300, 8.960100, 0.090180),
(943, 'Female', 74.1, -0.383300, 8.979200, 0.090170),
(944, 'Female', 74.2, -0.383300, 8.998300, 0.090160),
(945, 'Female', 74.3, -0.383300, 9.017300, 0.090140),
(946, 'Female', 74.4, -0.383300, 9.036300, 0.090130),
(947, 'Female', 74.5, -0.383300, 9.055200, 0.090120),
(948, 'Female', 74.6, -0.383300, 9.074000, 0.090110),
(949, 'Female', 74.7, -0.383300, 9.092800, 0.090090),
(950, 'Female', 74.8, -0.383300, 9.111600, 0.090080),
(951, 'Female', 74.9, -0.383300, 9.130300, 0.090070),
(952, 'Female', 75.0, -0.383300, 9.149000, 0.090050),
(953, 'Female', 75.1, -0.383300, 9.167600, 0.090040),
(954, 'Female', 75.2, -0.383300, 9.186200, 0.090030),
(955, 'Female', 75.3, -0.383300, 9.204800, 0.090010),
(956, 'Female', 75.4, -0.383300, 9.223300, 0.090000),
(957, 'Female', 75.5, -0.383300, 9.241800, 0.089990),
(958, 'Female', 75.6, -0.383300, 9.260200, 0.089970),
(959, 'Female', 75.7, -0.383300, 9.278600, 0.089960),
(960, 'Female', 75.8, -0.383300, 9.297000, 0.089950),
(961, 'Female', 75.9, -0.383300, 9.315400, 0.089930),
(962, 'Female', 76.0, -0.383300, 9.333700, 0.089920),
(963, 'Female', 76.1, -0.383300, 9.352000, 0.089910),
(964, 'Female', 76.2, -0.383300, 9.370300, 0.089890),
(965, 'Female', 76.3, -0.383300, 9.388600, 0.089880),
(966, 'Female', 76.4, -0.383300, 9.406900, 0.089870),
(967, 'Female', 76.5, -0.383300, 9.425200, 0.089850),
(968, 'Female', 76.6, -0.383300, 9.443500, 0.089840),
(969, 'Female', 76.7, -0.383300, 9.461700, 0.089830),
(970, 'Female', 76.8, -0.383300, 9.480000, 0.089810),
(971, 'Female', 76.9, -0.383300, 9.498300, 0.089800),
(972, 'Female', 77.0, -0.383300, 9.516600, 0.089790),
(973, 'Female', 77.1, -0.383300, 9.535000, 0.089770),
(974, 'Female', 77.2, -0.383300, 9.553300, 0.089760),
(975, 'Female', 77.3, -0.383300, 9.571700, 0.089750),
(976, 'Female', 77.4, -0.383300, 9.590100, 0.089730),
(977, 'Female', 77.5, -0.383300, 9.608600, 0.089720),
(978, 'Female', 77.6, -0.383300, 9.627100, 0.089710);

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
-- (already defined inline in CREATE TABLE above — PRIMARY KEY + UNIQUE KEY
-- both declared there, so no ALTER TABLE needed here)
--

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
  ADD UNIQUE KEY `device_code` (`device_code`),
  ADD KEY `idx_devices_barangay_id` (`barangay_id`);

--
-- Indexes for table `kiosk_sensor_readings`
--
ALTER TABLE `kiosk_sensor_readings`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_device_time` (`device_code`,`recorded_at`);

--
-- Indexes for table `login_attempts`
--
ALTER TABLE `login_attempts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_login_attempts_identifier_time` (`identifier`,`attempted_at`);

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
  ADD KEY `idx_measurements_wfh_status` (`wfh_status`),
  ADD KEY `idx_measurements_is_flagged` (`is_flagged`);

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
  ADD KEY `fk_nutritionist_events_user` (`nutritionist_id`),
  ADD KEY `idx_nutritionist_events_barangay_id` (`barangay_id`);

--
-- Indexes for table `parents`
--
ALTER TABLE `parents`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`),
  ADD KEY `idx_parents_barangay_id` (`barangay_id`);

--
-- Indexes for table `password_reset_tokens`
--
ALTER TABLE `password_reset_tokens`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_password_reset_token_hash` (`token_hash`),
  ADD KEY `idx_password_reset_account` (`account_type`,`account_id`),
  ADD KEY `idx_password_reset_expires` (`expires_at`);

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
-- Indexes for table `who_weight_for_length`
--
ALTER TABLE `who_weight_for_length`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_who_wfl_sex_height` (`sex`,`height_cm`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `appointments`
--
ALTER TABLE `appointments`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1;

--
-- AUTO_INCREMENT for table `audit_logs`
--
ALTER TABLE `audit_logs`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1;

--
-- AUTO_INCREMENT for table `barangays`
--
ALTER TABLE `barangays`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `children`
--
ALTER TABLE `children`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1;

--
-- AUTO_INCREMENT for table `devices`
--
ALTER TABLE `devices`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1;

--
-- AUTO_INCREMENT for table `kiosk_sensor_readings`
--
ALTER TABLE `kiosk_sensor_readings`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `login_attempts`
--
ALTER TABLE `login_attempts`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1;

--
-- AUTO_INCREMENT for table `measurements`
--
ALTER TABLE `measurements`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1;

--
-- AUTO_INCREMENT for table `measurement_sessions`
--
ALTER TABLE `measurement_sessions`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1;

--
-- AUTO_INCREMENT for table `nutritionist_events`
--
ALTER TABLE `nutritionist_events`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1;

--
-- AUTO_INCREMENT for table `parents`
--
ALTER TABLE `parents`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1;

--
-- AUTO_INCREMENT for table `password_reset_tokens`
--
ALTER TABLE `password_reset_tokens`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1;

--
-- AUTO_INCREMENT for table `permissions`
--
ALTER TABLE `permissions`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=19;

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
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `who_height_for_age`
--
ALTER TABLE `who_height_for_age`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=370;

--
-- AUTO_INCREMENT for table `who_weight_for_age`
--
ALTER TABLE `who_weight_for_age`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=370;

--
-- AUTO_INCREMENT for table `who_weight_for_height`
--
ALTER TABLE `who_weight_for_height`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2372;

--
-- AUTO_INCREMENT for table `who_weight_for_length`
--
ALTER TABLE `who_weight_for_length`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1303;

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
  ADD CONSTRAINT `fk_nutritionist_events_barangay` FOREIGN KEY (`barangay_id`) REFERENCES `barangays` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `parents`
--
ALTER TABLE `parents`
  ADD CONSTRAINT `fk_parents_barangay` FOREIGN KEY (`barangay_id`) REFERENCES `barangays` (`id`) ON DELETE SET NULL;

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

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;


SET FOREIGN_KEY_CHECKS = 1;