-- Migration: 2026-09-04
-- Adds household_id FK to parents table so parents can be linked to households
-- (children.household_id was already added in 20260904_spot_map_migration.sql)

ALTER TABLE parents
    ADD COLUMN household_id INT(10) UNSIGNED NULL AFTER local_area_id,
    ADD KEY idx_parents_household (household_id),
    ADD CONSTRAINT fk_parents_household
        FOREIGN KEY (household_id) REFERENCES households(id) ON DELETE SET NULL;
