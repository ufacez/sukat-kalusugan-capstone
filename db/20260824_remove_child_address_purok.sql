-- Sukat Kalusugan
-- Remove redundant child location fields.
-- Child location is inherited from the selected parent's barangay.

ALTER TABLE children
    DROP COLUMN address,
    DROP COLUMN purok;
