// Sukat Kalusugan - TF-Luna LiDAR template for height measurement
// Keep the pin and offset values easy to edit so the kiosk can be recalibrated
// later without changing the control flow.

#include <HardwareSerial.h>

// CONFIG ---------------------------------------------------------------
const int TF_LUNA_RX_PIN = 16;
const int TF_LUNA_TX_PIN = 17;
const long TF_LUNA_BAUD = 115200;
float HEIGHT_OFFSET_CM = 0.0;
const int READ_TIMEOUT_MS = 1000;

HardwareSerial TFSerial(2);

float readHeightCm() {
  unsigned long start = millis();

  while (millis() - start < READ_TIMEOUT_MS) {
    if (TFSerial.available() >= 9) {
      if (TFSerial.read() == 0x59 && TFSerial.read() == 0x59) {
        uint8_t distanceLow = TFSerial.read();
        uint8_t distanceHigh = TFSerial.read();
        float distanceCm = ((distanceHigh << 8) | distanceLow) / 10.0;
        return distanceCm + HEIGHT_OFFSET_CM;
      }
    }
  }

  return -1.0;
}

void setup() {
  Serial.begin(115200);
  TFSerial.begin(TF_LUNA_BAUD, SERIAL_8N1, TF_LUNA_RX_PIN, TF_LUNA_TX_PIN);
  Serial.println("TF-Luna ready");
}

void loop() {
  float heightCm = readHeightCm();

  if (heightCm > 0.0) {
    Serial.print("{\"sensor\":\"tf_luna\",\"height_cm\":");
    Serial.print(heightCm, 1);
    Serial.println("}");
  } else {
    Serial.println("TF-Luna timeout");
  }

  delay(100);
}
