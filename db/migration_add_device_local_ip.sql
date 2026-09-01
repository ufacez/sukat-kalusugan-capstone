-- Migration: Add local_ip column to devices table
-- Stores the ESP32's LAN IP so the kiosk can connect via WebSocket.
ALTER TABLE `devices` ADD COLUMN `local_ip` VARCHAR(45) DEFAULT NULL AFTER `status`;
