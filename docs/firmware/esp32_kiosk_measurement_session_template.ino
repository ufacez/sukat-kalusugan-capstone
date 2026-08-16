#include <WiFi.h>
#include <HTTPClient.h>
#include <HX711_ADC.h>

// =====================================================
// WIFI / SERVER
// =====================================================

const char* WIFI_SSID = "La Familia";
const char* WIFI_PASSWORD = "enz0p4o1931";

const String SERVER_IP = "http://192.168.100.164:8000";
const String DEVICE_ID = "ESP32-KIOSK-01";


// =====================================================
// HX711
// =====================================================

const int HX711_DOUT = 16;
const int HX711_SCK = 17;

HX711_ADC LoadCell(HX711_DOUT, HX711_SCK);

// Your working calibration value from the calibration sketch.
const float HX711_CAL_FACTOR = -20892.50f;

bool hx711Ready = false;


// =====================================================
// TF-LUNA
// =====================================================

#define TF_LUNA_RX 4
#define TF_LUNA_TX 5

HardwareSerial TF_Luna(2);

// Temporary test mounting height.
const float TF_LUNA_MOUNT_HEIGHT_CM = 40.0f;
const float HEIGHT_OFFSET_CM = 0.0f;


// =====================================================
// SESSION / TIMING
// =====================================================

enum SessionState {
  IDLE,
  START_REQUESTED,
  MEASURING,
  SUBMITTING
};

SessionState sessionState = IDLE;

unsigned long lastCommandPoll = 0;
unsigned long lastSubmitAttempt = 0;
unsigned long measurementStartedAt = 0;

const unsigned long COMMAND_POLL_INTERVAL = 1000;
const unsigned long SUBMIT_RETRY_INTERVAL = 3000;
const unsigned long SESSION_TIMEOUT = 120000;


// =====================================================
// STABILITY CONFIG
// =====================================================

const int WEIGHT_WINDOW_SIZE = 12;
const int HEIGHT_WINDOW_SIZE = 8;

const float WEIGHT_STDDEV_LIMIT = 0.05f;
const float HEIGHT_STDDEV_LIMIT = 0.40f;
const unsigned long STABLE_HOLD_MS = 1500;

float weightWindow[WEIGHT_WINDOW_SIZE];
float heightWindow[HEIGHT_WINDOW_SIZE];
int weightWindowCount = 0;
int heightWindowCount = 0;
int weightWindowIndex = 0;
int heightWindowIndex = 0;
unsigned long stableSince = 0;


// =====================================================
// ACTIVE SESSION DATA
// =====================================================

int currentSessionId = 0;
String currentChildCode = "";
float finalHeightCm = -1.0f;
float finalWeightKg = -1.0f;
String pendingSubmitBody = "";


// =====================================================
// WIFI
// =====================================================

void connectWiFi() {

  Serial.println();
  Serial.println("Connecting to WiFi...");

  WiFi.begin(WIFI_SSID, WIFI_PASSWORD);

  int attempts = 0;

  while (WiFi.status() != WL_CONNECTED && attempts < 40) {

    delay(500);

    Serial.print(".");

    attempts++;
  }

  if (WiFi.status() == WL_CONNECTED) {

    Serial.println();
    Serial.println("WiFi connected");

    Serial.print("ESP32 IP: ");
    Serial.println(WiFi.localIP());

  } else {

    Serial.println();
    Serial.println("WiFi failed");
  }
}


// =====================================================
// HTTP HELPERS
// =====================================================

bool httpGet(String url, String& responseBody) {

  if (WiFi.status() != WL_CONNECTED) {
    return false;
  }

  HTTPClient http;
  http.begin(url.c_str());

  int code = http.GET();

  if (code > 0) {
    responseBody = http.getString();
    http.end();
    return true;
  }

  Serial.print("HTTP GET failed: ");
  Serial.println(code);
  http.end();
  return false;
}

bool httpPostJson(String url, String jsonBody, String& responseBody) {

  if (WiFi.status() != WL_CONNECTED) {
    return false;
  }

  HTTPClient http;

  http.begin(url.c_str());
  http.addHeader("Content-Type", "application/json");

  int code = http.POST(jsonBody);

  if (code > 0) {

    Serial.print("HTTP response: ");
    Serial.println(code);

    responseBody = http.getString();

    Serial.print("Server: ");
    Serial.println(responseBody);

  } else {

    Serial.print("HTTP POST failed: ");
    Serial.println(code);
  }

  http.end();
  return code == 200;
}


// =====================================================
// HX711 SETUP
// =====================================================

void setupHX711() {

  Serial.println();
  Serial.println("Starting HX711...");

  LoadCell.begin();

  unsigned long stabilizingtime = 2000;
  boolean tare = true;

  LoadCell.start(stabilizingtime, tare);

  if (
    LoadCell.getTareTimeoutFlag() ||
    LoadCell.getSignalTimeoutFlag()
  ) {

    Serial.println();
    Serial.println("HX711 ERROR!");
    Serial.println("Check HX711 wiring.");

    hx711Ready = false;
    return;
  }

  LoadCell.setCalFactor(HX711_CAL_FACTOR);
  LoadCell.setSamplesInUse(4);

  Serial.println("HX711 startup complete");

  Serial.print("Calibration factor: ");
  Serial.println(LoadCell.getCalFactor());

  while (!LoadCell.update()) {
    delay(1);
  }

  Serial.println("HX711 ready");
  hx711Ready = true;
}


// =====================================================
// TF-LUNA READ
// =====================================================

float readHeightCm() {

  static uint8_t buffer[9];

  while (TF_Luna.available() >= 9) {

    if (TF_Luna.read() != 0x59) {
      continue;
    }

    if (TF_Luna.read() != 0x59) {
      continue;
    }

    buffer[0] = 0x59;
    buffer[1] = 0x59;

    for (int i = 2; i < 9; i++) {
      buffer[i] = TF_Luna.read();
    }

    uint16_t checksum = 0;

    for (int i = 0; i < 8; i++) {
      checksum += buffer[i];
    }

    if ((checksum & 0xFF) != buffer[8]) {
      Serial.println("TF-Luna checksum error");
      continue;
    }

    uint16_t distanceCm =
      buffer[2] |
      ((uint16_t)buffer[3] << 8);

    float heightCm =
      TF_LUNA_MOUNT_HEIGHT_CM
      - distanceCm
      + HEIGHT_OFFSET_CM;

    Serial.println();
    Serial.println("------------- TF-LUNA -------------");

    Serial.print("Distance: ");
    Serial.print(distanceCm);
    Serial.println(" cm");

    Serial.print("Calculated height: ");
    Serial.print(heightCm, 1);
    Serial.println(" cm");

    Serial.println("-----------------------------------");

    if (heightCm < 0 || heightCm > 250) {
      Serial.println("Height outside test range.");
      return -1.0f;
    }

    return heightCm;
  }

  return -1.0f;
}


// =====================================================
// SIMPLE JSON PARSERS
// =====================================================

String jsonStringValue(const String& json, const String& key) {
  String token = "\"" + key + "\"";
  int keyIndex = json.indexOf(token);
  if (keyIndex < 0) return "";

  int colonIndex = json.indexOf(':', keyIndex + token.length());
  if (colonIndex < 0) return "";

  int firstQuote = json.indexOf('"', colonIndex + 1);
  if (firstQuote < 0) return "";

  int secondQuote = json.indexOf('"', firstQuote + 1);
  if (secondQuote < 0) return "";

  return json.substring(firstQuote + 1, secondQuote);
}

long jsonLongValue(const String& json, const String& key) {
  String token = "\"" + key + "\"";
  int keyIndex = json.indexOf(token);
  if (keyIndex < 0) return 0;

  int colonIndex = json.indexOf(':', keyIndex + token.length());
  if (colonIndex < 0) return 0;

  int start = colonIndex + 1;
  while (start < (int)json.length() && (json[start] == ' ' || json[start] == '"')) {
    start++;
  }

  int end = start;
  while (end < (int)json.length() && (isDigit(json[end]) || json[end] == '-')) {
    end++;
  }

  return json.substring(start, end).toInt();
}

bool jsonBoolValue(const String& json, const String& key) {
  String token = "\"" + key + "\"";
  int keyIndex = json.indexOf(token);
  if (keyIndex < 0) return false;

  int colonIndex = json.indexOf(':', keyIndex + token.length());
  if (colonIndex < 0) return false;

  String tail = json.substring(colonIndex + 1);
  tail.trim();
  return tail.startsWith("true");
}


// =====================================================
// STABILITY HELPERS
// =====================================================

float windowMean(float* values, int count) {
  if (count <= 0) return 0.0f;

  float sum = 0.0f;
  for (int i = 0; i < count; i++) {
    sum += values[i];
  }

  return sum / count;
}

float windowStdDev(float* values, int count) {
  if (count <= 1) return 999.0f;

  float mean = windowMean(values, count);
  float variance = 0.0f;

  for (int i = 0; i < count; i++) {
    float delta = values[i] - mean;
    variance += delta * delta;
  }

  variance /= count;
  return sqrt(variance);
}

void pushWeightSample(float value) {
  weightWindow[weightWindowIndex] = value;
  weightWindowIndex = (weightWindowIndex + 1) % WEIGHT_WINDOW_SIZE;
  if (weightWindowCount < WEIGHT_WINDOW_SIZE) {
    weightWindowCount++;
  }
}

void pushHeightSample(float value) {
  heightWindow[heightWindowIndex] = value;
  heightWindowIndex = (heightWindowIndex + 1) % HEIGHT_WINDOW_SIZE;
  if (heightWindowCount < HEIGHT_WINDOW_SIZE) {
    heightWindowCount++;
  }
}

void resetSamples() {
  weightWindowCount = 0;
  heightWindowCount = 0;
  weightWindowIndex = 0;
  heightWindowIndex = 0;
  stableSince = 0;
  finalHeightCm = -1.0f;
  finalWeightKg = -1.0f;
  pendingSubmitBody = "";
}


// =====================================================
// COMMAND POLL
// =====================================================

bool pollMeasurementCommand() {

  String url =
    SERVER_IP +
    "/api/esp32/get_command.php?device_id=" +
    DEVICE_ID;

  String response;

  if (!httpGet(url, response)) {
    return false;
  }

  String command = jsonStringValue(response, "command");
  String state = jsonStringValue(response, "state");
  long sessionId = jsonLongValue(response, "session_id");
  String childCode = jsonStringValue(response, "child_code");

  if (command == "START" && sessionId > 0) {

    currentSessionId = (int)sessionId;
    currentChildCode = childCode;
    sessionState = MEASURING;
    measurementStartedAt = millis();
    resetSamples();

    Serial.println();
    Serial.println("====================================");
    Serial.println("SESSION STARTED");
    Serial.print("Session ID: ");
    Serial.println(currentSessionId);
    Serial.print("Child Code: ");
    Serial.println(currentChildCode);
    Serial.println("====================================");

    return true;
  }

  if (state == "MEASURING" && sessionId > 0) {
    currentSessionId = (int)sessionId;
    currentChildCode = childCode;
    sessionState = MEASURING;
    return true;
  }

  return false;
}


// =====================================================
// BUILD PAYLOAD / SUBMIT
// =====================================================

void buildSubmitPayload() {

  pendingSubmitBody = "{";
  pendingSubmitBody += "\"device_id\":\"" + DEVICE_ID + "\",";
  pendingSubmitBody += "\"session_id\":" + String(currentSessionId) + ",";
  pendingSubmitBody += "\"height_cm\":" + String(finalHeightCm, 2) + ",";
  pendingSubmitBody += "\"weight_kg\":" + String(finalWeightKg, 2) + ",";
  pendingSubmitBody += "\"source_type\":\"kiosk\"";
  pendingSubmitBody += "}";

  Serial.println();
  Serial.println("========== FINAL PAYLOAD ==========");
  Serial.println(pendingSubmitBody);
  Serial.println("===================================");
}

bool submitFinalMeasurement() {

  String url =
    SERVER_IP +
    "/api/esp32/submit_measurement.php";

  String response;

  if (!httpPostJson(url, pendingSubmitBody, response)) {
    return false;
  }

  if (jsonBoolValue(response, "success")) {

    Serial.println("Measurement submitted successfully");

    currentSessionId = 0;
    currentChildCode = "";
    pendingSubmitBody = "";
    sessionState = IDLE;
    resetSamples();

    return true;
  }

  Serial.println("Server rejected measurement submission");
  return false;
}


// =====================================================
// SETUP
// =====================================================

void setup() {

  Serial.begin(115200);
  delay(1000);

  Serial.println();
  Serial.println("====================================");
  Serial.println("       ESP32 KIOSK SYSTEM");
  Serial.println("       SESSION MEASUREMENT MODE");
  Serial.println("====================================");

  setupHX711();

  TF_Luna.begin(
    115200,
    SERIAL_8N1,
    TF_LUNA_RX,
    TF_LUNA_TX
  );

  Serial.println();
  Serial.println("TF-Luna UART ready");

  connectWiFi();

  Serial.println();
  Serial.println("====================================");
  Serial.println("SYSTEM READY");
  Serial.println("Waiting for START command...");
  Serial.println("====================================");
}


// =====================================================
// LOOP
// =====================================================

void loop() {

  // Keep HX711 running frequently.
  if (hx711Ready) {
    LoadCell.update();
  }

  // Poll the server for the next command only while idle.
  if (
    sessionState == IDLE &&
    millis() - lastCommandPoll >= COMMAND_POLL_INTERVAL
  ) {
    lastCommandPoll = millis();
    pollMeasurementCommand();
  }

  // Read sensors continuously.
  float weightKg = -1.0f;
  float heightCm = -1.0f;

  if (hx711Ready) {
    weightKg = LoadCell.getData();
  }

  heightCm = readHeightCm();

  // Only collect samples when a measurement session is active.
  if (sessionState == MEASURING) {

    if (weightKg > 0.1f && weightKg < 300.0f) {
      pushWeightSample(weightKg);
    }

    if (heightCm > 0.0f && heightCm <= 250.0f) {
      pushHeightSample(heightCm);
    }

    bool enoughSamples =
      weightWindowCount >= WEIGHT_WINDOW_SIZE &&
      heightWindowCount >= HEIGHT_WINDOW_SIZE;

    bool weightStable =
      enoughSamples &&
      windowStdDev(weightWindow, weightWindowCount) <= WEIGHT_STDDEV_LIMIT;

    bool heightStable =
      enoughSamples &&
      windowStdDev(heightWindow, heightWindowCount) <= HEIGHT_STDDEV_LIMIT;

    if (weightStable && heightStable) {
      if (stableSince == 0) {
        stableSince = millis();
      }

      if (millis() - stableSince >= STABLE_HOLD_MS) {
        finalWeightKg = windowMean(weightWindow, weightWindowCount);
        finalHeightCm = TF_LUNA_MOUNT_HEIGHT_CM - windowMean(heightWindow, heightWindowCount) + HEIGHT_OFFSET_CM;

        if (finalHeightCm < 0.0f) {
          finalHeightCm = 0.0f;
        }

        buildSubmitPayload();
        sessionState = SUBMITTING;
        lastSubmitAttempt = 0;
      }
    } else {
      stableSince = 0;
    }

    if (millis() - measurementStartedAt > SESSION_TIMEOUT) {
      Serial.println("Session timeout - returning to IDLE");
      currentSessionId = 0;
      currentChildCode = "";
      resetSamples();
      sessionState = IDLE;
    }
  }

  if (
    sessionState == SUBMITTING &&
    millis() - lastSubmitAttempt >= SUBMIT_RETRY_INTERVAL
  ) {
    lastSubmitAttempt = millis();
    submitFinalMeasurement();
  }

  // Short delay only. HX711.update() must continue frequently.
  delay(10);
}
