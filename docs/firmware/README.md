# Firmware templates

These sketches are starter templates for the later hardware migration.

Files:

- `esp32_kiosk_template.ino` - main ESP32 kiosk bridge and HTTP payload sender
- `hx711_load_cell_template.ino` - weight platform template for a 4-cell load platform
- `tf_luna_lidar_template.ino` - height sensor template for TF-Luna LiDAR

Edit the constants at the top of each sketch first:

- Wi-Fi SSID and password
- `DEVICE_ID`
- API base URL and endpoint path
- HX711 calibration factor and tare
- TF-Luna serial pins and offset

Suggested API targets in this project:

- `public_html/api/esp32/device_ping.php`
- `public_html/api/esp32/submit_measurement.php`
