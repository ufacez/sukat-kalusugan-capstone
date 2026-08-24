-- Add the optional middle name collected by the Nutritionist Children form.
ALTER TABLE children
    ADD COLUMN IF NOT EXISTS middle_name VARCHAR(60) NULL AFTER first_name;