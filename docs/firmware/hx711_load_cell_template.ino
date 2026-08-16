// Sukat Kalusugan - HX711 load cell template for a 4-cell platform
// Edit the CONFIG section first. This is intentionally simple so you can swap
// calibration values and pins later without rewriting the whole sketch.

#include <HX711.h>

// CONFIG ---------------------------------------------------------------
const int HX711_DOUT_PIN = 4;
const int HX711_SCK_PIN = 5;
float CALIBRATION_FACTOR = 2280.5;
float TARE_OFFSET = 0.0;
const int SAMPLE_COUNT = 10;

HX711 scale;

void tareScale() {
  scale.tare();
  TARE_OFFSET = scale.get_offset();
}

float readRawAverage() {
  float total = 0.0;

  for (int i = 0; i < SAMPLE_COUNT; i++) {
    total += scale.get_units(1);
    delay(20);
  }

  return total / SAMPLE_COUNT;
}

float readWeightKg() {
  float grams = readRawAverage() * 1000.0;
  float adjusted = (grams / CALIBRATION_FACTOR) - TARE_OFFSET;
  return adjusted / 1000.0;
}

void setup() {
  Serial.begin(115200);
  scale.begin(HX711_DOUT_PIN, HX711_SCK_PIN);
  scale.set_scale(CALIBRATION_FACTOR);
  tareScale();
  Serial.println("HX711 ready");
}

void loop() {
  if (scale.is_ready()) {
    float weightKg = readWeightKg();
    Serial.print("{\"sensor\":\"hx711\",\"weight_kg\":");
    Serial.print(weightKg, 2);
    Serial.println("}");
  } else {
    Serial.println("HX711 not ready");
  }

  delay(500);
}
