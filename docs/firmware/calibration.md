# HX711 and TF-Luna calibration settings

## HX711 load cell

Use the calibration sketch in `hx711_load_cell_calibration.ino` and record the final value shown in the serial monitor after placing a known mass on the platform.

Recommended formula:

- raw_weight_kg = (hx711_reading / calibration_factor) / 1000.0
- calibrated_weight_kg = raw_weight_kg + weight_offset_kg

Example values:

- calibration_factor = -19964.25
- tare_offset = 0.000
- weight_offset_kg = 0.000

In the ESP32 sketch, this is typically:

```cpp
const float HX711_CAL_FACTOR = -19964.25f;
const float WEIGHT_OFFSET_KG = 0.000f;
float rawWeightKg = (scaleValue / HX711_CAL_FACTOR) / 1000.0f;
float finalWeightKg = rawWeightKg + WEIGHT_OFFSET_KG;
```

## TF-Luna LiDAR height

The TF-Luna returns a distance in centimeters. For a child height reading, apply an offset and optionally a scale factor before saving the value.

Recommended formula:

- raw_distance_cm = tf_luna_distance_cm
- adjusted_height_cm = (raw_distance_cm + tf_luna_offset_cm) * tf_luna_scale_factor + height_offset_cm

Example values:

- tf_luna_offset_cm = 0.00
- tf_luna_scale_factor = 1.0000
- height_offset_cm = 0.00

In the ESP32 sketch, this is typically:

```cpp
const float TF_LUNA_OFFSET_CM = 0.00f;
const float TF_LUNA_SCALE_FACTOR = 1.0000f;
const float HEIGHT_OFFSET_CM = 0.00f;

float rawDistanceCm = distanceCm;
float finalHeightCm = (rawDistanceCm + TF_LUNA_OFFSET_CM) * TF_LUNA_SCALE_FACTOR + HEIGHT_OFFSET_CM;
```

## Database storage

The system stores calibration settings in the `device_sensor_settings` table, and each device also tracks `last_seen_at` for online/offline detection.

Use the migration file `db/20260816_sensor_device_settings_migration.sql` to apply these schema changes.
