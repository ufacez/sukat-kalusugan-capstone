-- Drop unused spot-map fields and landmarks table
-- has_potable_water / has_sanitary_toilet on households are no longer set or read by the app
-- map_landmarks is no longer rendered on the barangay risk map

ALTER TABLE households
  DROP COLUMN has_potable_water,
  DROP COLUMN has_sanitary_toilet;

DROP TABLE IF EXISTS map_landmarks;