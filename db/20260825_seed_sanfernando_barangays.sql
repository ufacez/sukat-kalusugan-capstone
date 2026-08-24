-- ============================================================================
-- Seed the official 35 barangays of City of San Fernando, Pampanga
-- ============================================================================
-- The `barangays` table (see 20260818_barangays_migration.sql) only ever
-- gets populated from whatever barangay text was already sitting in
-- children/users/nutritionist_events records, so a fresh install can end
-- up with just one or two rows. This adds the full official list (same
-- spelling used by public_html/api/admin/csfp_barangays.php) so the
-- Barangay Risk Map has every barangay to plot, even before any child
-- records reference them.
--
-- Safe to re-run: uses INSERT IGNORE against the existing UNIQUE KEY on
-- barangays.name, so it never creates duplicates or touches rows that
-- already exist (including ones with a different city_municipality value).
-- ============================================================================

SET NAMES utf8mb4;

INSERT IGNORE INTO barangays (name, city_municipality, status) VALUES
('Alasas', 'City of San Fernando, Pampanga', 'active'),
('Baliti', 'City of San Fernando, Pampanga', 'active'),
('Bulaon', 'City of San Fernando, Pampanga', 'active'),
('Calulut', 'City of San Fernando, Pampanga', 'active'),
('Del Carmen', 'City of San Fernando, Pampanga', 'active'),
('Del Pilar', 'City of San Fernando, Pampanga', 'active'),
('Del Rosario', 'City of San Fernando, Pampanga', 'active'),
('Dela Paz Norte', 'City of San Fernando, Pampanga', 'active'),
('Dela Paz Sur', 'City of San Fernando, Pampanga', 'active'),
('Dolores', 'City of San Fernando, Pampanga', 'active'),
('Juliana', 'City of San Fernando, Pampanga', 'active'),
('Lara', 'City of San Fernando, Pampanga', 'active'),
('Lourdes', 'City of San Fernando, Pampanga', 'active'),
('Magliman', 'City of San Fernando, Pampanga', 'active'),
('Maimpis', 'City of San Fernando, Pampanga', 'active'),
('Malino', 'City of San Fernando, Pampanga', 'active'),
('Malpitic', 'City of San Fernando, Pampanga', 'active'),
('Pandaras', 'City of San Fernando, Pampanga', 'active'),
('Panipuan', 'City of San Fernando, Pampanga', 'active'),
('Pulung Bulu', 'City of San Fernando, Pampanga', 'active'),
('Quebiauan', 'City of San Fernando, Pampanga', 'active'),
('Saguin', 'City of San Fernando, Pampanga', 'active'),
('San Agustin', 'City of San Fernando, Pampanga', 'active'),
('San Felipe', 'City of San Fernando, Pampanga', 'active'),
('San Isidro', 'City of San Fernando, Pampanga', 'active'),
('San Jose', 'City of San Fernando, Pampanga', 'active'),
('San Juan', 'City of San Fernando, Pampanga', 'active'),
('San Nicolas', 'City of San Fernando, Pampanga', 'active'),
('San Pedro', 'City of San Fernando, Pampanga', 'active'),
('Santa Lucia', 'City of San Fernando, Pampanga', 'active'),
('Santa Teresita', 'City of San Fernando, Pampanga', 'active'),
('Santo Niño', 'City of San Fernando, Pampanga', 'active'),
('Santo Rosario (Pob.)', 'City of San Fernando, Pampanga', 'active'),
('Sindalan', 'City of San Fernando, Pampanga', 'active'),
('Telabastagan', 'City of San Fernando, Pampanga', 'active');
