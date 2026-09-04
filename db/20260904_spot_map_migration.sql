-- Spot Map Feature Migration
-- Adds households, map_landmarks tables and links children to households

-- 1. Households table
CREATE TABLE IF NOT EXISTS households (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  barangay_id INT UNSIGNED NOT NULL,
  local_area_id INT UNSIGNED DEFAULT NULL,
  household_code VARCHAR(30) NOT NULL,
  address VARCHAR(255) DEFAULT NULL,
  lat DECIMAL(10,7) DEFAULT NULL,
  lng DECIMAL(10,7) DEFAULT NULL,
  has_potable_water TINYINT(1) DEFAULT 1,
  has_sanitary_toilet TINYINT(1) DEFAULT 1,
  status ENUM('active','inactive') DEFAULT 'active',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_hh_brgy_code (barangay_id, household_code),
  KEY idx_hh_barangay (barangay_id),
  KEY idx_hh_area (local_area_id),
  FOREIGN KEY (barangay_id) REFERENCES barangays(id) ON DELETE CASCADE,
  FOREIGN KEY (local_area_id) REFERENCES local_areas(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2. Add household_id to children
ALTER TABLE children
  ADD COLUMN household_id INT UNSIGNED DEFAULT NULL AFTER parent_id,
  ADD KEY idx_child_hh (household_id),
  ADD CONSTRAINT fk_child_hh FOREIGN KEY (household_id) REFERENCES households(id) ON DELETE SET NULL;

-- 3. Infrastructure landmarks
CREATE TABLE IF NOT EXISTS map_landmarks (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  barangay_id INT UNSIGNED NOT NULL,
  name VARCHAR(150) NOT NULL,
  landmark_type ENUM('health_center','school','barangay_hall','water_source','toilet','other') NOT NULL,
  lat DECIMAL(10,7) NOT NULL,
  lng DECIMAL(10,7) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_lm_barangay (barangay_id),
  FOREIGN KEY (barangay_id) REFERENCES barangays(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
