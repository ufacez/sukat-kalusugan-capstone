-- ============================================================================
-- Sukat Kalusugan Parent Portal Seed
-- Optional demo parent login for testing the parent portal.
-- Safe for reruns.
-- ============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

INSERT INTO parents (name, email, password_hash, parent_type, phone, address, status)
SELECT
    'Parent User',
    'parent@sukat.local',
    '$2y$10$79K/UkdSI684IKAC/ekCM.irEzm206kvVE6o41d0hbChwNFelra7e',
    'Guardian',
    NULL,
    'All',
    'active'
WHERE NOT EXISTS (
    SELECT 1 FROM parents WHERE email = 'parent@sukat.local'
);

SET FOREIGN_KEY_CHECKS = 1;
