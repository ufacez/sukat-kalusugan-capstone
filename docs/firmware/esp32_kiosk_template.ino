// Sukat Kalusugan - ESP32 kiosk bridge template
// Replace the values in CONFIG first, then connect the sensor-specific read
// functions from the load cell and TF-Luna sketches later.

#include <WiFi.h>
#include <HTTPClient.h>

// CONFIG ---------------------------------------------------------------
const char* WIFI_SSID = "YOUR_WIFI_SSID";
const char* WIFI_PASSWORD = "YOUR_WIFI_PASSWORD";
const char* DEVICE_ID = "ESP32-KIOSK-01";
const char* API_BASE_URL = "http://192.168.1.10/sukat-kalusugan-capstone/public_html/api/esp32";
const unsigned long PING_INTERVAL_MS = 15000;

// SENSOR HOOKS --------------------------------------------------------
// Replace these with real sensor reads once the separate sketches are ready.
float readHeightCm() {
  return 0.0;
}

float readWeightKg() {
  return 0.0;
}

String makePingPayload(bool connected) {
  String payload = "{";
  payload += "\"device_code\":\"" + String(DEVICE_ID) + "\",";
  payload += "\"status\":\"" + String(connected ? "active" : "offline") + "\",";
  payload += "\"rssi\":" + String(WiFi.RSSI()) + ",";
  payload += "\"ip_address\":\"" + WiFi.localIP().toString() + "\"";
  payload += "}";
  return payload;
}

String makeMeasurementPayload(float heightCm, float weightKg) {
  String payload = "{";
  payload += "\"device_code\":\"" + String(DEVICE_ID) + "\",";
  payload += "\"child_id\":null,";
  payload += "\"height_cm\":" + String(heightCm, 1) + ",";
  payload += "\"weight_kg\":" + String(weightKg, 2) + ",";
  payload += "\"age_months\":null,";
  payload += "\"source_type\":\"kiosk\"";
  payload += "}";
  return payload;
}

void postJson(const String& url, const String& payload) {
  if (WiFi.status() != WL_CONNECTED) {
    Serial.println("Wi-Fi not connected.");
    return;
  }

  HTTPClient http;
  http.begin(url);
  http.addHeader("Content-Type", "application/json");
  int statusCode = http.POST(payload);
  Serial.printf("POST %s -> %d\n", url.c_str(), statusCode);
  Serial.println(http.getString());
  http.end();
}

void connectWiFi() {
  WiFi.mode(WIFI_STA);
  WiFi.begin(WIFI_SSID, WIFI_PASSWORD);
  Serial.printf("Connecting to %s", WIFI_SSID);

  while (WiFi.status() != WL_CONNECTED) {
    delay(500);
    Serial.print('.');
  }

  Serial.println();
  Serial.print("Connected, IP: ");
  Serial.println(WiFi.localIP());
}

unsigned long lastPingAt = 0;

void setup() {
  Serial.begin(115200);
  delay(500);
  connectWiFi();
}

void loop() {
  if (WiFi.status() != WL_CONNECTED) {
    connectWiFi();
  }

  unsigned long now = millis();
  if (now - lastPingAt >= PING_INTERVAL_MS) {
    lastPingAt = now;
    postJson(String(API_BASE_URL) + "/device_ping.php", makePingPayload(true));
  }

  // Replace the next block with a real trigger from the kiosk session.
  float heightCm = readHeightCm();
  float weightKg = readWeightKg();

  if (heightCm > 0.0f && weightKg > 0.0f) {
    postJson(String(API_BASE_URL) + "/submit_measurement.php", makeMeasurementPayload(heightCm, weightKg));
    delay(2000);
  }

  delay(200);
}
