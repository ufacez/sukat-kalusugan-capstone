#define TF_LUNA_RX 4  // ESP32 receives from TF-Luna TX
#define TF_LUNA_TX 5   // ESP32 sends to TF-Luna RX

// Calibration settings for the kiosk system
// Height formula: finalHeightCm = (rawDistanceCm + TF_LUNA_OFFSET_CM) * TF_LUNA_SCALE_FACTOR + HEIGHT_OFFSET_CM
const float TF_LUNA_OFFSET_CM = 0.00f;
const float TF_LUNA_SCALE_FACTOR = 1.0000f;
const float HEIGHT_OFFSET_CM = 0.00f;

HardwareSerial TF_Luna(2);

void setup() {
  Serial.begin(115200);

  // TF-Luna UART
  TF_Luna.begin(115200, SERIAL_8N1, TF_LUNA_RX, TF_LUNA_TX);

  Serial.println("TF-Luna UART starting...");
}

void loop() {
  static uint8_t buffer[9];

  if (TF_Luna.available()) {

    // Look for first 0x59
    if (TF_Luna.read() == 0x59) {

      // Look for second 0x59
      if (TF_Luna.read() == 0x59) {

        buffer[0] = 0x59;
        buffer[1] = 0x59;

        // Read remaining 7 bytes
        for (int i = 2; i < 9; i++) {
          buffer[i] = TF_Luna.read();
        }

        // Checksum
        uint16_t checksum = 0;

        for (int i = 0; i < 8; i++) {
          checksum += buffer[i];
        }

        if ((checksum & 0xFF) == buffer[8]) {

          // Distance in cm
          uint16_t rawDistance =
              buffer[2] |
              (buffer[3] << 8);

          float finalHeightCm = (rawDistance + TF_LUNA_OFFSET_CM) * TF_LUNA_SCALE_FACTOR + HEIGHT_OFFSET_CM;

          // Signal strength
          uint16_t strength =
              buffer[4] |
              (buffer[5] << 8);

          // Temperature
          uint16_t tempRaw =
              buffer[6] |
              (buffer[7] << 8);

          float temperature =
              (tempRaw / 8.0) - 256.0;

          Serial.print("Distance raw: ");
          Serial.print(rawDistance);
          Serial.print(" cm");

          Serial.print(" | Height adjusted: ");
          Serial.print(finalHeightCm);
          Serial.print(" cm");

          Serial.print(" | Strength: ");
          Serial.print(strength);

          Serial.print(" | Temperature: ");
          Serial.print(temperature);

          Serial.println(" C");
        }
      }
    }
  }
}