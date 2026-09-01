#include <WiFi.h>
#include <HTTPClient.h>
#include <ArduinoJson.h>
#include <HX711_ADC.h>
#include <math.h>
#include <WiFiManager.h>
#include <Preferences.h>
#include <AsyncTCP.h>
#include <ESPAsyncWebServer.h>
#include "soc/soc.h"
#include "soc/rtc_cntl_reg.h"

// =====================================================
// WIFI
// =====================================================
//ww
// The kiosk moves between locations (public presentation, then the
// clinic), each with a different WiFi network. Hardcoded WIFI_SSID /
// WIFI_PASSWORD constants used to mean re-flashing the firmware every
// time it moved. WiFiManager replaces that: connectWiFi() below tries
// whatever network was saved last, and if that fails it opens a
// temporary access point (name + password below) so a phone can submit
// the new network's credentials through a page the ESP32 itself hosts
// -- no laptop, no re-flash, no Serial Monitor needed on-site.
//
// SETUP_AP_NAME / SETUP_AP_PASSWORD protect that setup screen itself --
// anyone who doesn't know this password can't join the setup network or
// see/change what real WiFi the kiosk is pointed at. This is NOT the
// venue WiFi password; the venue's real SSID/password get typed into
// the form WiFiManager shows once you're connected to this setup AP.

const char* SETUP_AP_NAME = "SukatKalusugan-Setup";
const char* SETUP_AP_PASSWORD = "sukat2026"; // must be 8+ characters

// Hold this pin LOW (BOOT button on most ESP32 dev boards, GPIO0) for
// 3 seconds right after power-on to force the setup portal open even
// though WiFi is already saved and working. This is what you use when
// you're NOT changing venues -- just flipping the kiosk between the
// live server and localhost, or between two WiFi networks you've
// already used before. Power the board, hold BOOT, wait for the
// "opening setup portal" message on Serial, then connect to
// SukatKalusugan-Setup from your phone like a normal first-time setup.
#define WIFI_RESET_BUTTON_PIN 0
const unsigned long WIFI_RESET_HOLD_MS = 3000;

// How long, after boot has already started, you have to START pressing
// BOOT. This window opens only AFTER the chip has already decided to
// boot normally -- pressing BOOT during the actual power-on/reset
// instant (holding it while plugging in the cable) can instead trip
// the ESP32's built-in flashing mode, which never reaches this code at
// all. So: power it on / press RESET like normal, THEN press BOOT.
const unsigned long WIFI_RESET_WINDOW_MS = 5000;

WiFiManager wifiManager;
WiFiManagerParameter* customServerUrlParam = nullptr;
Preferences preferences;

// =====================================================
// SERVER
// =====================================================
//
// SERVER_IP used to be a hardcoded const -- switching between the live
// Azure server and a local/LAN test server meant editing this file and
// reflashing every time. It's now a mutable value (serverBaseUrl)
// loaded from flash (Preferences) at boot. DEFAULT_SERVER_URL below is
// only the fallback used the very first time the kiosk ever runs, or
// if nothing has been saved yet.
//
// To change it afterwards: open the same setup portal used for WiFi
// (SETUP_AP_NAME above) -- it now shows a "Server Base URL" field next
// to the WiFi form. Type in one of, then tap Save:
//   https://sukatkalusugan.app/                                      (live, Azure + domain + SSL)
//   http://192.168.100.164/sukat-kalusugan-capstone/public_html/    (localhost/LAN, XAMPP)
// No laptop, no re-flash. See WIFI_RESET_BUTTON_PIN above for how to
// open this portal on demand, not just when WiFi is broken.

const String DEFAULT_SERVER_URL =
  "https://sukatkalusugan.app/";

String serverBaseUrl = DEFAULT_SERVER_URL;

const String DEVICE_ID =
  "ESP32-KIOSK-01";

// Sent as an X-Device-Key header on every request to our own server
// (not Firebase, which has its own auth). Without this, the device_id
// string alone -- a guessable, non-secret value -- was the only thing
// standing between the public internet and being able to submit fake
// measurements, now that the server is a public domain instead of a
// LAN-only address. Must match ESP32_DEVICE_KEY in config.php exactly,
// on BOTH the live server and your local XAMPP config.php.
const String DEVICE_KEY =
  "26b247f1b2ffb005e5b65802fd73ca0453b69299e06b2f8816113d0029f2d993";

// These are deliberately just "api/..." now, with NO "sukat-kalusugan-
// capstone/public_html/" baked in. Azure's document root points
// directly at public_html/, so the live URL is just
// http://52.230.96.22/api/esp32/get_command.php.
//
// For local/XAMPP testing later, where the URL is instead
// http://<local-ip>/sukat-kalusugan-capstone/public_html/..., that
// extra "sukat-kalusugan-capstone/public_html/" part goes in the
// Server Base URL field itself (typed into the setup portal), not
// here -- e.g. Server Base URL =
// "http://192.168.100.164/sukat-kalusugan-capstone/public_html/"
// That way these two path constants never need to change, no matter
// which server you point the kiosk at.

const String GET_COMMAND_PATH =
  "api/esp32/get_command.php";

const String SUBMIT_MEASUREMENT_PATH =
  "api/esp32/submit_measurement.php";

// =====================================================
// FIREBASE
// =====================================================

const String FIREBASE_URL =
  "https://sukatkalusugan-default-rtdb.firebaseio.com";

const String FIREBASE_AUTH = "";

// =====================================================
// HX711
// =====================================================

const int HX711_DOUT = 26;
const int HX711_SCK = 25;

HX711_ADC LoadCell(
  HX711_DOUT,
  HX711_SCK
);

// This used to be a hardcoded const, requiring a reflash to change.
// It is now a mutable default: fetchDeviceConfig() overwrites it from
// the admin panel (via get_command.php) at boot and on every command
// poll after that, so it can be recalibrated live. This value is only
// the fallback used before the first successful server contact.
float hx711CalFactor = -20892.50f;

// =====================================================
// TF-LUNA
// =====================================================

#define TF_LUNA_RX 4
#define TF_LUNA_TX 5

HardwareSerial TF_Luna(2);

// =====================================================
// HEIGHT
// =====================================================

// Same as hx711CalFactor above: this is now a mutable default kept live
// in sync with the admin panel's "Mounting height (cm)" field, not a
// fixed constant.
float mountingHeightCm = 182.88f;
const float HEIGHT_OFFSET_CM = 0.0f;

// =====================================================
// VALID RANGE
// =====================================================

const float MIN_HEIGHT_CM = 0.0f;
const float MAX_HEIGHT_CM = 250.0f;

const float MIN_WEIGHT_KG = 0.0f;
const float MAX_WEIGHT_KG = 300.0f;

const float EMPTY_PLATFORM_THRESHOLD_KG = 0.0f;

// =====================================================
// TIMING
// =====================================================

const unsigned long COMMAND_POLL_INTERVAL = 2000;
const unsigned long FIREBASE_UPDATE_INTERVAL = 100;
const unsigned long SESSION_VALIDATE_INTERVAL = 1000;

const unsigned long MEASUREMENT_TIMEOUT = 120000;

// =====================================================
// SAMPLE WINDOWS
// =====================================================

const int WEIGHT_SAMPLE_WINDOW = 8;
const int HEIGHT_SAMPLE_WINDOW = 8;

// =====================================================
// STABILITY
// =====================================================

const int STABLE_SAMPLES_REQUIRED = 8;

const unsigned long FINAL_STABLE_HOLD_MS = 1500;

const float WEIGHT_STABLE_EPSILON_KG = 0.15f;
const float HEIGHT_STABLE_EPSILON_CM = 1.0f;

// =====================================================
// STATE
// =====================================================

bool hx711Ready = false;
bool measuring = false;

bool shouldProcess = false;

bool calMode = false;
bool calWaitingForWeight = false;
float calKnownWeight = 0.0f;

long currentSessionId = 0;
long lastSessionId = 0;

unsigned long lastCommandPoll = 0;
unsigned long lastFirebaseUpdate = 0;
unsigned long lastSessionValidation = 0;
unsigned long measurementStartedAt = 0;
unsigned long measurementSequence = 0;

// =====================================================
// WEBSOCKET
// =====================================================

AsyncWebServer wsServer(80);
AsyncWebSocket ws("/ws");

unsigned long lastWsBroadcast = 0;
const unsigned long WS_BROADCAST_INTERVAL = 10;  // 10ms = 100fps, matches TF-Luna update rate

// =====================================================
// WIFI
// =====================================================

// Used once, at boot (see setup() below). Tries the last-saved network
// first; if that fails (new venue, or nothing saved yet), it opens the
// SETUP_AP_NAME access point and BLOCKS here until someone submits real
// WiFi credentials through the page it hosts, or the portal times out.
// setConfigPortalTimeout() means a failed/skipped setup doesn't hang the
// kiosk forever -- after 3 minutes it gives up and setup() continues
// without WiFi, same as the old "WiFi connection FAILED" fallback did.
void connectWiFiAtBoot(bool forcePortal) {

  // custom_server_url is added to the SAME portal WiFiManager already
  // shows for WiFi credentials, so one screen covers "moved to a new
  // venue" (new WiFi) and "flip live/localhost" (new server URL) at
  // once. Its starting value is whatever is currently saved, so if the
  // portal isn't shown this boot (WiFi just reconnects normally), the
  // saved server URL is left untouched below.
  wifiManager.addParameter(customServerUrlParam);

  wifiManager.setConfigPortalTimeout(180);

  bool connected;

  if (forcePortal) {

    Serial.println();
    Serial.println("================================");
    Serial.println("BOOT BUTTON HELD - OPENING SETUP PORTAL");
    Serial.println("(WiFi + Server URL, on demand)");
    Serial.println("================================");

    // startConfigPortal (unlike autoConnect) opens the setup AP even
    // though WiFi is already saved/working. Existing WiFi credentials
    // are kept as-is unless you overwrite them on the page.
    connected = wifiManager.startConfigPortal(
      SETUP_AP_NAME,
      SETUP_AP_PASSWORD
    );

  } else {

    if (WiFi.status() == WL_CONNECTED) {
      return;
    }

    Serial.println();
    Serial.println("================================");
    Serial.println("CONNECTING TO WIFI (WiFiManager)");
    Serial.println("================================");

    connected = wifiManager.autoConnect(
      SETUP_AP_NAME,
      SETUP_AP_PASSWORD
    );
  }

  if (connected) {

    Serial.println("WiFi connected");

    Serial.print("ESP32 IP: ");
    Serial.println(WiFi.localIP());

  } else {

    Serial.println("WiFi connection FAILED (setup portal timed out or was skipped)");
  }

  // ===================================================
  // SERVER URL - persist if it was changed on the portal
  // ===================================================

  String submittedServerUrl =
    String(customServerUrlParam->getValue());

  submittedServerUrl.trim();

  if (
    submittedServerUrl.length() > 0 &&
    submittedServerUrl != serverBaseUrl
  ) {

    serverBaseUrl = submittedServerUrl;

    preferences.putString("server_url", serverBaseUrl);

    Serial.print("Server URL saved: ");
    Serial.println(serverBaseUrl);
  }
}

// Used from loop() for ordinary reconnects during normal operation (a
// brief drop in signal, router reboot, etc). Deliberately does NOT open
// the setup portal -- doing that mid-operation would stop the kiosk and
// wait on someone with a phone every time WiFi blips. It just retries
// the already-saved network. If the kiosk has genuinely moved to a new
// venue, power-cycle it so connectWiFiAtBoot() runs again and can open
// the setup portal properly.
void connectWiFi() {

  if (WiFi.status() == WL_CONNECTED) {
    return;
  }

  WiFi.reconnect();
}

// =====================================================
// HTTP GET
// =====================================================

String httpGet(
  const String& url,
  int& httpCode
) {

  httpCode = -1;

  if (WiFi.status() != WL_CONNECTED) {
    return "";
  }

  HTTPClient http;

  http.begin(url);
  http.setTimeout(1500);
  http.addHeader("X-Device-Key", DEVICE_KEY);

  httpCode = http.GET();

  String response = "";

  if (httpCode > 0) {
    response = http.getString();
  }

  http.end();

  return response;
}

// =====================================================
// HTTP POST FORM
// =====================================================

String httpPostForm(
  const String& url,
  const String& formBody,
  int& httpCode
) {

  httpCode = -1;

  if (WiFi.status() != WL_CONNECTED) {
    return "";
  }

  HTTPClient http;

  http.begin(url);
  http.setTimeout(3000);

  http.addHeader(
    "Content-Type",
    "application/x-www-form-urlencoded"
  );
  http.addHeader("X-Device-Key", DEVICE_KEY);

  httpCode = http.POST(formBody);

  String response = "";

  if (httpCode > 0) {
    response = http.getString();
  }

  http.end();

  return response;
}

// =====================================================
// HTTP PUT JSON
// =====================================================

String httpPutJson(
  const String& url,
  const String& jsonBody,
  int& httpCode
) {

  httpCode = -1;

  if (WiFi.status() != WL_CONNECTED) {
    return "";
  }

  HTTPClient http;

  http.begin(url);
  http.setTimeout(300);

  http.addHeader(
    "Content-Type",
    "application/json"
  );

  httpCode = http.PUT(jsonBody);

  http.end();

  return "";
}

// =====================================================
// HX711 SETUP
// =====================================================

void setupHX711() {

  Serial.println();
  Serial.println("================================");
  Serial.println("STARTING HX711");
  Serial.println("================================");

  LoadCell.begin();

  unsigned long stabilizingTime = 2000;

  bool tare = true;

  LoadCell.start(
    stabilizingTime,
    tare
  );

  if (
    LoadCell.getTareTimeoutFlag() ||
    LoadCell.getSignalTimeoutFlag()
  ) {

    Serial.println("HX711 ERROR");
    Serial.println("Check DOUT and SCK wiring.");

    hx711Ready = false;

    return;
  }

  LoadCell.setCalFactor(
    hx711CalFactor
  );

  Serial.print("Calibration factor: ");
  Serial.println(LoadCell.getCalFactor());

  unsigned long updateStart = millis();
  while (!LoadCell.update()) {
    if (millis() - updateStart > 3000) {
      Serial.println("HX711 TIMEOUT — update never ready (disconnected?)");
      hx711Ready = false;
      return;
    }
    delay(1);
  }

  Serial.println("HX711 ready");

  hx711Ready = true;
}

// =====================================================
// TF-LUNA
// =====================================================

bool readTFLunaDistanceCm(
  float& distanceCm
) {

  static uint8_t buffer[9];

  bool gotFrame = false;

  while (TF_Luna.available() >= 9) {

    int first = TF_Luna.read();

    if (first != 0x59) {
      continue;
    }

    int second = TF_Luna.read();

    if (second != 0x59) {
      continue;
    }

    buffer[0] = 0x59;
    buffer[1] = 0x59;

    bool readFailed = false;

    for (
      int i = 2;
      i < 9;
      i++
    ) {

      int value = TF_Luna.read();

      if (value < 0) {
        readFailed = true;
        break;
      }

      buffer[i] = (uint8_t)value;
    }

    if (readFailed) {
      break;
    }

    uint16_t checksum = 0;

    for (
      int i = 0;
      i < 8;
      i++
    ) {

      checksum += buffer[i];
    }

    if (
      (checksum & 0xFF) != buffer[8]
    ) {

      continue;
    }

    uint16_t distanceMm =
      buffer[2] |
      ((uint16_t)buffer[3] << 8);

    distanceCm =
      distanceMm / 10.0f;

    gotFrame = true;
  }

  return gotFrame;
}

// =====================================================
// HEIGHT
// =====================================================

bool getHeightReading(
  float& heightCm
) {

  float distanceCm;

  if (!readTFLunaDistanceCm(distanceCm)) {
    return false;
  }

  heightCm =
    mountingHeightCm -
    distanceCm +
    HEIGHT_OFFSET_CM;

  return true;
}

// =====================================================
// GET CURRENT COMMAND
// =====================================================

// =====================================================
// LIVE SENSOR CALIBRATION
// =====================================================
//
// get_command.php now includes a "calibration" object (hx711_calibration_
// factor, mounting_height_cm) sourced from the devices table on every
// single response, whether idle or mid-measurement. This device polls
// that endpoint every COMMAND_POLL_INTERVAL regardless, so calling this
// from getMeasurementCommand() means a value the admin saves in the
// Sensors page reaches the device on its very next poll (worst case
// COMMAND_POLL_INTERVAL, currently 2s) -- no reflash required.
//
// Only acts when a value actually changed, so this is a no-op most polls.

void applyCalibrationFromServer(
  JsonVariant& data
) {

  JsonVariant calibration =
    data["calibration"];

  if (calibration.isNull()) {
    return;
  }

  float serverCalFactor =
    calibration["hx711_calibration_factor"] | hx711CalFactor;

  float serverMountingHeight =
    calibration["mounting_height_cm"] | mountingHeightCm;

  if (
    fabs(serverCalFactor - hx711CalFactor) > 0.0001f
  ) {

    hx711CalFactor = serverCalFactor;

    if (hx711Ready) {
      LoadCell.setCalFactor(hx711CalFactor);
    }

    Serial.print("HX711 calibration factor updated from admin panel: ");
    Serial.println(hx711CalFactor, 4);
  }

  if (
    fabs(serverMountingHeight - mountingHeightCm) > 0.001f
  ) {

    mountingHeightCm = serverMountingHeight;

    Serial.print("Mounting height updated from admin panel: ");
    Serial.print(mountingHeightCm, 2);
    Serial.println(" cm");
  }
}

bool getMeasurementCommand(
  long& sessionId,
  String& command,
  bool& shouldMeasure,
  String& status
) {

  String url =
    serverBaseUrl +
    GET_COMMAND_PATH +
    "?device_id=" +
    DEVICE_ID;

  // Send ESP32's local IP so the server can store it
  // for the kiosk browser's WebSocket direct connection.
  if (WiFi.status() == WL_CONNECTED) {
    url += "&local_ip=" + WiFi.localIP().toString();
  }

  int httpCode;

  String response =
    httpGet(
      url,
      httpCode
    );

  if (
    httpCode != 200 ||
    response.length() == 0
  ) {

    Serial.print("Command HTTP: ");
    Serial.println(httpCode);

    return false;
  }

  StaticJsonDocument<1024> doc;

  DeserializationError error =
    deserializeJson(
      doc,
      response
    );

  if (error) {

    Serial.println("Command JSON parse failed.");
    Serial.println(response);

    return false;
  }

  JsonVariant data;

  if (doc["data"].isNull()) {
    data = doc.as<JsonVariant>();
  } else {
    data = doc["data"];
  }

  applyCalibrationFromServer(data);

  sessionId =
    data["session_id"] | 0;

  command =
    String(
      data["command"] | ""
    );

  shouldMeasure =
    data["should_measure"] | false;

  status =
    String(
      data["status"] | "IDLE"
    );

  Serial.println();
  Serial.println("========== COMMAND ==========");

  Serial.print("Status: ");
  Serial.println(status);

  Serial.print("Command: ");
  Serial.println(command);

  Serial.print("Should measure: ");
  Serial.println(
    shouldMeasure ? "YES" : "NO"
  );

  Serial.print("Session ID: ");
  Serial.println(sessionId);

  Serial.println("=============================");

  return true;
}

// =====================================================
// SESSION VALIDATION
// =====================================================

bool isCurrentSessionStillValid(
  long sessionId
) {

  if (sessionId <= 0) {
    return false;
  }

  long serverSessionId = 0;

  String command = "";
  String status = "";

  bool shouldMeasure = false;

  bool success =
    getMeasurementCommand(
      serverSessionId,
      command,
      shouldMeasure,
      status
    );

  if (!success) {

    return true;
  }

  if (serverSessionId <= 0) {

    Serial.println();
    Serial.println(
      "CURRENT SESSION NO LONGER ACTIVE"
    );

    return false;
  }

  if (serverSessionId != sessionId) {

    Serial.println();
    Serial.println(
      "!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!"
    );

    Serial.println(
      "STALE ESP32 SESSION DETECTED"
    );

    Serial.print("ESP session: ");
    Serial.println(sessionId);

    Serial.print("Server session: ");
    Serial.println(serverSessionId);

    Serial.println("Stopping old session.");

    Serial.println(
      "!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!"
    );

    return false;
  }

  return true;
}

// =====================================================
// SESSION + PROCESS COMMAND
// =====================================================

bool pollSessionState(
  long sessionId,
  bool& stillValid,
  bool& shouldProcess
) {

  long serverSessionId = 0;

  String command = "";
  String status = "";

  bool shouldMeasureUnused = false;

  bool success =
    getMeasurementCommand(
      serverSessionId,
      command,
      shouldMeasureUnused,
      status
    );

  if (!success) {

    stillValid = true;
    shouldProcess = false;

    return false;
  }

  if (
    serverSessionId <= 0 ||
    serverSessionId != sessionId
  ) {

    Serial.println();
    Serial.println(
      "STALE ESP32 SESSION DETECTED DURING SAMPLING"
    );

    stillValid = false;
    shouldProcess = false;

    return true;
  }

  stillValid = true;

  shouldProcess =
    command.equalsIgnoreCase("PROCESS");

  if (shouldProcess) {

    Serial.println();
    Serial.println(
      "################################"
    );

    Serial.println(
      "PROCESS COMMAND RECEIVED"
    );

    Serial.println(
      "################################"
    );
  }

  return true;
}

// =====================================================
// FIREBASE
// =====================================================

bool updateFirebase(
  long sessionId,
  const char* status,
  float heightCm,
  float weightKg,
  bool weightStable = false,
  bool heightStable = false,
  bool finalReady = false,
  float finalHeightCm = -1,
  float finalWeightKg = -1,
  unsigned long finalSequence = 0
) {

  if (WiFi.status() != WL_CONNECTED) {
    return false;
  }

  if (sessionId <= 0) {
    return false;
  }

  String url =
    FIREBASE_URL +
    "/latest_measurements/" +
    DEVICE_ID +
    ".json";

  if (FIREBASE_AUTH.length() > 0) {

    url += "?auth=";
    url += FIREBASE_AUTH;
  }

  StaticJsonDocument<512> doc;

  doc["device_id"] = DEVICE_ID;
  doc["session_id"] = sessionId;
  doc["status"] = status;
  doc["source_type"] = "kiosk";

  doc["weight_stable"] = weightStable;
  doc["height_stable"] = heightStable;

  doc["sequence"] = measurementSequence;

  doc["final_ready"] = finalReady;

  doc["final_sequence"] =
    finalReady ? finalSequence : 0;

  if (
    finalReady &&
    finalHeightCm >= 0 &&
    finalWeightKg >= 0
  ) {

    doc["final_height_cm"] =
      finalHeightCm;

    doc["final_weight_kg"] =
      finalWeightKg;

  } else {

    doc["final_height_cm"] = nullptr;
    doc["final_weight_kg"] = nullptr;
  }

  if (heightCm >= 0) {
    doc["height_cm"] = heightCm;
  } else {
    doc["height_cm"] = nullptr;
  }

  if (weightKg >= 0) {
    doc["weight_kg"] = weightKg;
  } else {
    doc["weight_kg"] = nullptr;
  }

  doc["timestamp"] = millis();

  String body;

  serializeJson(
    doc,
    body
  );

  int httpCode;

  String response =
    httpPutJson(
      url,
      body,
      httpCode
    );

  return (
    httpCode >= 200 &&
    httpCode < 300
  );
}

// =====================================================
// SAFE FIREBASE UPDATE
// =====================================================

bool safeFirebaseUpdate(
  long sessionId,
  const char* status,
  float heightCm,
  float weightKg,
  bool weightStable = false,
  bool heightStable = false,
  bool skipSessionCheck = false,
  bool finalReady = false,
  float finalHeightCm = -1,
  float finalWeightKg = -1,
  unsigned long finalSequence = 0
) {

  if (sessionId <= 0) {
    return false;
  }

  if (
    !skipSessionCheck &&
    !isCurrentSessionStillValid(sessionId)
  ) {

    Serial.println(
      "Firebase update BLOCKED because session is stale."
    );

    measuring = false;
    currentSessionId = 0;

    return false;
  }

  return updateFirebase(
    sessionId,
    status,
    heightCm,
    weightKg,
    weightStable,
    heightStable,
    finalReady,
    finalHeightCm,
    finalWeightKg,
    finalSequence
  );
}

// =====================================================
// SQL SUBMIT
// =====================================================

bool submitFinalMeasurement(
  long sessionId,
  float heightCm,
  float weightKg
) {

  String url =
    serverBaseUrl +
    SUBMIT_MEASUREMENT_PATH;

  String payload =
    "device_id=" +
    DEVICE_ID +
    "&session_id=" +
    String(sessionId) +
    "&weight_kg=" + String(weightKg, 2) +
    "&height_cm=" + String(heightCm, 1);

  Serial.println();
  Serial.println(
    "================================"
  );

  Serial.println(
    "SUBMITTING MEASUREMENT TO SQL"
  );

  Serial.println(
    "================================"
  );

  Serial.print("Payload: ");
  Serial.println(payload);

  int httpCode;

  String response =
    httpPostForm(
      url,
      payload,
      httpCode
    );

  Serial.print("SQL HTTP: ");
  Serial.println(httpCode);

  Serial.print("SQL response: ");
  Serial.println(response);

  if (
    httpCode < 200 ||
    httpCode >= 300
  ) {

    Serial.println(
      "SQL SUBMISSION FAILED"
    );

    return false;
  }

  StaticJsonDocument<512> responseDoc;

  DeserializationError error =
    deserializeJson(
      responseDoc,
      response
    );

  if (error) {

    Serial.println(
      "Invalid JSON from PHP."
    );

    return false;
  }

  bool success =
    responseDoc["success"] | false;

  if (!success) {

    const char* message =
      responseDoc["message"] |
      "Unknown server error";

    Serial.print("PHP ERROR: ");
    Serial.println(message);

    return false;
  }

  Serial.println(
    "SQL SUBMISSION SUCCESS"
  );

  return true;
}

// =====================================================
// RUN MEASUREMENT
// =====================================================

void runMeasurement(
  long sessionId
) {

  measuring = true;
  currentSessionId = sessionId;

  measurementStartedAt = millis();
  lastSessionValidation = millis();

  Serial.println();
  Serial.println(
    "################################"
  );

  Serial.println(
    "STARTING MEASUREMENT"
  );

  Serial.print("Session ID: ");
  Serial.println(sessionId);

  Serial.println(
    "################################"
  );

  // ===================================================
  // INITIAL FIREBASE
  // ===================================================

  if (
    !safeFirebaseUpdate(
      sessionId,
      "MEASURING",
      -1,
      -1
    )
  ) {

    measuring = false;
    currentSessionId = 0;

    return;
  }

  Serial.println();
  Serial.println(
    "Please step on the platform."
  );

  for (
    int i = 1;
    i >= 1;
    i--
  ) {

    if (
      !isCurrentSessionStillValid(
        sessionId
      )
    ) {

      measuring = false;
      currentSessionId = 0;

      return;
    }

    Serial.print("Measuring in ");
    Serial.print(i);
    Serial.println("...");

    delay(1000);
  }

  Serial.println();
  Serial.println(
    "COLLECTING LIVE SENSOR DATA..."
  );

  Serial.println(
    "Waiting for operator to press Process Measurement..."
  );

  // ===================================================
  // ROLLING BUFFERS
  // ===================================================

  static float weightBuffer[WEIGHT_SAMPLE_WINDOW];
  int weightBufIndex = 0;
  int weightBufFilled = 0;

  static float heightBuffer[HEIGHT_SAMPLE_WINDOW];
  int heightBufIndex = 0;
  int heightBufFilled = 0;

  // ===================================================
  // RAW STABILITY
  // ===================================================

  bool haveLastRawWeight = false;
  float lastRawWeight = 0;

  int weightStableCount = 0;
  bool weightStable = false;

  bool haveLastRawHeight = false;
  float lastRawHeight = 0;

  int heightStableCount = 0;
  bool heightStable = false;

  // ===================================================
  // FINAL SNAPSHOT
  // ===================================================

  shouldProcess = false;

  bool finalReady = false;

  unsigned long stableSince = 0;

  float finalWeightSnapshot = -1;
  float finalHeightSnapshot = -1;

  unsigned long finalSequence = 0;

  // ===================================================
  // SENSOR WAITING (per-session)
  // ===================================================
  //
  // Both sensors MUST produce stable readings before a final
  // snapshot is taken. No manual mode fallback — if a sensor
  // never comes online, the measurement times out.

  unsigned long lastHxUpdate = millis();
  unsigned long lastHeightUpdate = millis();

  // ===================================================
  // LIVE MEASUREMENT LOOP
  // ===================================================

  while (true) {

    // ================================================
    // TIMEOUT
    // ================================================

    if (
      millis() -
      measurementStartedAt >
      MEASUREMENT_TIMEOUT
    ) {

      Serial.println();
      Serial.println(
        "MEASUREMENT TIMEOUT: no PROCESS command received."
      );

      safeFirebaseUpdate(
        sessionId,
        "ERROR",
        -1,
        -1
      );

      measuring = false;
      currentSessionId = 0;

      return;
    }

    // ================================================
    // SESSION + PROCESS
    // ================================================
    //
    // If a WebSocket client is connected, the PROCESS
    // command arrives instantly via onWsEvent() above
    // and shouldProcess is already true. We still run
    // the HTTP poll as a fallback when no WS clients
    // are connected (e.g. Firebase-only mode).
    //

    if (
      millis() -
      lastSessionValidation >=
      SESSION_VALIDATE_INTERVAL
    ) {

      lastSessionValidation = millis();

      bool stillValid = true;

      if (!shouldProcess || ws.count() == 0) {
        pollSessionState(
          sessionId,
          stillValid,
          shouldProcess
        );
      }

      // -----------------------------------------------
      // SESSION NO LONGER VALID
      // -----------------------------------------------

      if (!stillValid) {

        Serial.println(
          "Measurement cancelled because a newer session exists."
        );

        measuring = false;
        currentSessionId = 0;

        return;
      }

      // -----------------------------------------------
      // PROCESS COMMAND
      // -----------------------------------------------

      if (shouldProcess) {

        Serial.println();
        Serial.println(
          "################################"
        );

        Serial.println(
          "PROCESS COMMAND RECEIVED"
        );

        Serial.println(
          "FINAL SNAPSHOT WILL NOW BE SUBMITTED"
        );

        Serial.println(
          "################################"
        );

        // ---------------------------------------------
        // FINAL SNAPSHOT MUST EXIST
        // ---------------------------------------------

        if (
          !finalReady ||
          finalSequence == 0
        ) {

          Serial.println(
            "PROCESS RECEIVED BUT FINAL SNAPSHOT IS NOT READY."
          );

          safeFirebaseUpdate(
            sessionId,
            "ERROR",
            -1,
            -1
          );

          measuring = false;
          currentSessionId = 0;

          return;
        }

        // Exit the sampling loop.
        break;
      }
    }

    // ================================================
    // HX711
    // ================================================

    bool gotWeightUpdate =
      hx711Ready &&
      LoadCell.update();

    if (gotWeightUpdate) {
      lastHxUpdate = millis();
    }

    if (gotWeightUpdate) {

      measurementSequence++;

      float weight =
        LoadCell.getData();

      if (
        weight >= EMPTY_PLATFORM_THRESHOLD_KG &&
        weight < MAX_WEIGHT_KG
      ) {

        weightBuffer[weightBufIndex] =
          weight;

        weightBufIndex =
          (weightBufIndex + 1) %
          WEIGHT_SAMPLE_WINDOW;

        if (
          weightBufFilled <
          WEIGHT_SAMPLE_WINDOW
        ) {

          weightBufFilled++;
        }

        // ---------------------------------------------
        // RAW WEIGHT STABILITY
        // ---------------------------------------------

        if (
          haveLastRawWeight &&
          fabs(weight - lastRawWeight) <=
          WEIGHT_STABLE_EPSILON_KG
        ) {

          weightStableCount++;

        } else {

          weightStableCount = 0;
          weightStable = false;
        }

        lastRawWeight = weight;
        haveLastRawWeight = true;

        if (
          weightStableCount >=
          STABLE_SAMPLES_REQUIRED
        ) {

          weightStable = true;
        }
      }
    }

    // ================================================
    // TF-LUNA
    // ================================================

    float height;

    bool gotHeightUpdate =
      getHeightReading(height);

    if (gotHeightUpdate) {
      lastHeightUpdate = millis();
    }

    if (gotHeightUpdate) {

      measurementSequence++;

      if (
        height >= MIN_HEIGHT_CM &&
        height <= MAX_HEIGHT_CM
      ) {

        heightBuffer[heightBufIndex] =
          height;

        heightBufIndex =
          (heightBufIndex + 1) %
          HEIGHT_SAMPLE_WINDOW;

        if (
          heightBufFilled <
          HEIGHT_SAMPLE_WINDOW
        ) {

          heightBufFilled++;
        }

        // ---------------------------------------------
        // RAW HEIGHT STABILITY
        // ---------------------------------------------

        if (
          haveLastRawHeight &&
          fabs(height - lastRawHeight) <=
          HEIGHT_STABLE_EPSILON_CM
        ) {

          heightStableCount++;

        } else {

          heightStableCount = 0;
          heightStable = false;
        }

        lastRawHeight = height;
        haveLastRawHeight = true;

        if (
          heightStableCount >=
          STABLE_SAMPLES_REQUIRED
        ) {

          heightStable = true;
        }
      }
    }

    // ================================================
    // FINAL STABLE SNAPSHOT
    // ================================================

    // Both weight AND height must be stable with data in their
    // buffers before a final snapshot is taken. No manual fallback.

    bool weightSatisfied = weightStable;
    bool heightSatisfied = heightStable;

    if (
      weightSatisfied &&
      heightSatisfied &&
      weightBufFilled > 0 &&
      heightBufFilled > 0
    ) {

      if (stableSince == 0) {
        stableSince = millis();
      }

      if (
        !finalReady &&
        millis() - stableSince >=
        FINAL_STABLE_HOLD_MS
      ) {

        float ws = 0;

        for (
          int i = 0;
          i < weightBufFilled;
          i++
        ) {

          ws += weightBuffer[i];
        }

        finalWeightSnapshot =
          ws / weightBufFilled;

        float hs = 0;

        for (
          int i = 0;
          i < heightBufFilled;
          i++
        ) {

          hs += heightBuffer[i];
        }

        finalHeightSnapshot =
          hs / heightBufFilled;

        finalReady = true;

        finalSequence =
          measurementSequence;

        Serial.println();
        Serial.println(
          "================================"
        );

        Serial.println(
          "FINAL STABLE SNAPSHOT READY"
        );

        Serial.print("Final Weight: ");
        Serial.print(
          finalWeightSnapshot,
          2
        );

        Serial.println(" kg");

        Serial.print("Final Height: ");
        Serial.print(
          finalHeightSnapshot,
          1
        );

        Serial.println(" cm");

        Serial.print("Final Sequence: ");
        Serial.println(finalSequence);

        Serial.println(
          "================================"
        );
      }

    } else {

      stableSince = 0;

      finalReady = false;

      finalWeightSnapshot = -1;
      finalHeightSnapshot = -1;

      finalSequence = 0;
    }

    // ================================================
    // FIREBASE LIVE UPDATE
    // ================================================
    //
    // When WebSocket clients are connected, they receive
    // data at 10ms intervals directly. Firebase is only
    // used as a fallback when no WS clients are present
    // (e.g. remote monitoring).
    //

    if (
      ws.count() == 0 &&
      millis() -
      lastFirebaseUpdate >=
      FIREBASE_UPDATE_INTERVAL
    ) {

      lastFirebaseUpdate =
        millis();

      float liveWeight = haveLastRawWeight ? lastRawWeight : -1;
      float liveHeight = haveLastRawHeight ? lastRawHeight : -1;

      safeFirebaseUpdate(
        sessionId,
        "MEASURING",
        liveHeight,
        liveWeight,
        weightStable,
        heightStable,
        true,
        finalReady,
        finalHeightSnapshot,
        finalWeightSnapshot,
        finalSequence
      );
    }

    // ================================================
    // WEBSOCKET LIVE BROADCAST
    // ================================================

    if (
      millis() -
      lastWsBroadcast >=
      WS_BROADCAST_INTERVAL
    ) {
      lastWsBroadcast = millis();

      broadcastSensorData(
        lastRawWeight,
        lastRawHeight,
        haveLastRawWeight,
        haveLastRawHeight,
        weightStable,
        heightStable,
        finalReady,
        finalSequence,
        finalWeightSnapshot,
        finalHeightSnapshot,
        "MEASURING"
      );
    }

    // ================================================
    // WEBSOCKET CLEANUP (inside measurement loop)
    // ================================================
    //
    // loop() is blocked inside runMeasurement(), so
    // ws.cleanupClients() in loop() never runs.
    // Clean up here to prevent stale client buildup.
    //

    ws.cleanupClients();

  }

  // ===================================================
  // FINAL VALUES
  // ===================================================

  float finalWeight =
    finalWeightSnapshot;

  float finalHeight =
    finalHeightSnapshot;

  if (
    !finalReady ||
    finalSequence == 0
  ) {

    Serial.println(
      "PROCESS BLOCKED: final stable snapshot is not ready."
    );

    safeFirebaseUpdate(
      sessionId,
      "MEASURING",
      -1,
      -1,
      false,
      false,
      true
    );

    measuring = false;
    currentSessionId = 0;

    return;
  }

  // ===================================================
  // VALIDATION
  // ===================================================

  bool validWeight =
    finalWeight >= MIN_WEIGHT_KG &&
    finalWeight <= MAX_WEIGHT_KG;

  bool validHeight =
    finalHeight >= MIN_HEIGHT_CM &&
    finalHeight <= MAX_HEIGHT_CM;

  if (
    !validWeight ||
    !validHeight
  ) {

    Serial.println(
      "INVALID MEASUREMENT"
    );

    safeFirebaseUpdate(
      sessionId,
      "ERROR",
      finalHeight,
      finalWeight
    );

    measuring = false;
    currentSessionId = 0;

    return;
  }

  // ===================================================
  // FINAL SESSION VALIDATION
  // ===================================================

  if (
    !isCurrentSessionStillValid(
      sessionId
    )
  ) {

    Serial.println(
      "FINAL SQL SUBMISSION BLOCKED: stale session."
    );

    measuring = false;
    currentSessionId = 0;

    return;
  }

  // ===================================================
  // SQL SUBMISSION
  // ===================================================

  bool sqlSuccess =
    submitFinalMeasurement(
      sessionId,
      finalHeight,
      finalWeight
    );

  if (!sqlSuccess) {

    Serial.println(
      "SQL FAILED"
    );

    safeFirebaseUpdate(
      sessionId,
      "ERROR",
      finalHeight,
      finalWeight
    );

    measuring = false;
    currentSessionId = 0;

    return;
  }

  // ===================================================
  // COMPLETE
  // ===================================================

  Serial.println();
  Serial.println(
    "SQL SAVED SUCCESSFULLY"
  );

  safeFirebaseUpdate(
    sessionId,
    "COMPLETE",
    finalHeight,
    finalWeight,
    true,
    true,
    true,
    true,
    finalHeight,
    finalWeight,
    finalSequence
  );

  // ===================================================
  // FINISHED
  // ===================================================

  lastSessionId =
    sessionId;

  currentSessionId = 0;
  measuring = false;

  Serial.println();
  Serial.println(
    "################################"
  );

  Serial.println(
    "MEASUREMENT COMPLETE"
  );

  Serial.println(
    "Waiting for next START..."
  );

  Serial.println(
    "################################"
  );
}

// =====================================================
// WEBSOCKET EVENT HANDLER
// =====================================================

void onWsEvent(
  AsyncWebSocket *server,
  AsyncWebSocketClient *client,
  AwsEventType type,
  void *arg,
  uint8_t *data,
  size_t len
) {
  switch (type) {

    case WS_EVT_CONNECT:
      Serial.printf(
        "[WS] Client #%u connected from %s\n",
        client->id(),
        client->remoteIP().toString().c_str()
      );
      break;

    case WS_EVT_DISCONNECT:
      Serial.printf(
        "[WS] Client #%u disconnected\n",
        client->id()
      );
      break;

    case WS_EVT_DATA: {
      AwsFrameInfo *info =
        (AwsFrameInfo*)arg;

      if (
        info->final &&
        info->index == 0 &&
        info->len == len &&
        info->opcode == WS_TEXT
      ) {
        data[len] = 0;

        StaticJsonDocument<128> cmdDoc;
        DeserializationError err =
          deserializeJson(cmdDoc, (char*)data);

        if (!err) {
          String msgType =
            cmdDoc["type"] | "";

          if (msgType == "command") {
            String cmd =
              cmdDoc["command"] | "";

            if (
              cmd == "PROCESS" &&
              measuring
            ) {
              shouldProcess = true;

              Serial.println(
                "[WS] PROCESS command received"
              );
            }
          }
        }
      }

      break;
    }

    case WS_EVT_PONG:
    case WS_EVT_ERROR:
      break;
  }
}

// =====================================================
// WEBSOCKET BROADCAST
// =====================================================
//
// Pushes live sensor data to all connected kiosk browsers.
// Called from inside the runMeasurement() while(true) loop.
// The JSON shape matches the Firebase latest_measurements
// snapshot so the kiosk JS can feed it through the same
// applyFirebaseStatus() state machine.
//

void broadcastSensorData(
  float weight,
  float height,
  bool hasWeight,
  bool hasHeight,
  bool weightStable,
  bool heightStable,
  bool finalReady,
  unsigned long finalSequence,
  float finalWeight,
  float finalHeight,
  const char* status
) {
  if (ws.count() == 0) return;

  StaticJsonDocument<384> doc;

  doc["type"] = "sensor_data";
  doc["status"] = status;
  doc["session_id"] = currentSessionId;

  if (hasWeight && !isnan(weight)) {
    doc["weight_kg"] = weight;
  } else {
    doc["weight_kg"] = nullptr;
  }

  if (hasHeight && !isnan(height)) {
    doc["height_cm"] = height;
  } else {
    doc["height_cm"] = nullptr;
  }

  doc["weight_stable"] = weightStable;
  doc["height_stable"] = heightStable;

  doc["final_ready"] = finalReady;
  doc["final_sequence"] = finalSequence;
  doc["sequence"] = millis();

  if (finalReady) {
    doc["final_weight_kg"] = finalWeight;
    doc["final_height_cm"] = finalHeight;
  } else {
    doc["final_weight_kg"] = nullptr;
    doc["final_height_cm"] = nullptr;
  }

  doc["timestamp"] = millis();

  String json;
  serializeJson(doc, json);
  ws.textAll(json);
}

// =====================================================
// SETUP
// =====================================================

void setup() {

  WRITE_PERI_REG(RTC_CNTL_BROWN_OUT_REG, 0);

  Serial.begin(115200);

  delay(1000);

  Serial.println();
  Serial.println(
    "========================================"
  );

  Serial.println(
    "SUKATKALUSUGAN ESP32 KIOSK"
  );

  Serial.println(
    "HX711 + TF-LUNA"
  );

  Serial.println(
    "SESSION-SAFE FIREBASE VERSION"
  );

  Serial.println(
    "========================================"
  );

  // ===================================================
  // SAVED SETTINGS (WiFi is handled by WiFiManager itself;
  // this loads the server URL we saved ourselves)
  // ===================================================

  preferences.begin("sukat", false);

  serverBaseUrl =
    preferences.getString("server_url", DEFAULT_SERVER_URL);

  Serial.print("Server URL: ");
  Serial.println(serverBaseUrl);

  customServerUrlParam = new WiFiManagerParameter(
    "server_url",
    "Server Base URL (e.g. http://192.168.100.164/sukat-kalusugan-capstone/public_html/)",
    serverBaseUrl.c_str(),
    150
  );

  // ===================================================
  // BOOT BUTTON - press (AFTER power-on) to force the setup portal open
  // ===================================================
  //
  // Lets you change WiFi and/or the server URL any time WiFi is
  // already working (e.g. just switching live <-> localhost, no venue
  // change). Without this, the portal only appears when WiFi fails.
  //
  // IMPORTANT: this deliberately does NOT check the button instantly at
  // power-on. Holding BOOT low during the actual power-on/reset instant
  // is how the ESP32 enters its built-in USB flashing mode -- code
  // never runs in that case, so an instant check here would never work
  // reliably and would sometimes hijack the boot itself. Instead, the
  // chip boots normally first, and only then do we open a few-second
  // window where pressing BOOT is just a normal button read.

  pinMode(WIFI_RESET_BUTTON_PIN, INPUT_PULLUP);

  Serial.println();
  Serial.println("================================");
  Serial.println("Press and HOLD the BOOT button now");
  Serial.println("to open WiFi/Server setup...");
  Serial.println("(you have a few seconds)");
  Serial.println("================================");

  bool forcePortal = false;

  unsigned long windowStart = millis();

  while (millis() - windowStart < WIFI_RESET_WINDOW_MS) {

    if (digitalRead(WIFI_RESET_BUTTON_PIN) == LOW) {

      Serial.println("BOOT detected - keep holding to confirm...");

      unsigned long pressStart = millis();

      while (digitalRead(WIFI_RESET_BUTTON_PIN) == LOW) {

        if (millis() - pressStart > WIFI_RESET_HOLD_MS) {
          forcePortal = true;
          break;
        }

        delay(50);
      }

      break;
    }

    delay(50);
  }

  // ===================================================
  // WIFI
  // ===================================================
  //
  // Connect BEFORE touching either sensor now, so the calibration
  // fetch just below can pull the admin panel's current values before
  // the HX711 is tared/scaled and before the first height reading is
  // taken. If WiFi or the server is unreachable, the hardcoded
  // defaults above are used and the device works exactly as before.
  //
  // This is the boot-time connect: tries the saved network, opens the
  // SukatKalusugan-Setup portal if that fails, or if forcePortal is
  // true because the BOOT button was held (see connectWiFiAtBoot()
  // above). loop() below uses the plain connectWiFi() reconnect instead.

  connectWiFiAtBoot(forcePortal);

  if (WiFi.status() == WL_CONNECTED) {

    Serial.println();
    Serial.println("Fetching sensor calibration from admin panel...");

    long dummySessionId = 0;
    String dummyCommand = "";
    String dummyStatus = "";
    bool dummyShouldMeasure = false;

    getMeasurementCommand(
      dummySessionId,
      dummyCommand,
      dummyShouldMeasure,
      dummyStatus
    );

    // ===================================================
    // WEBSOCKET SERVER
    // ===================================================

    ws.onEvent(onWsEvent);
    wsServer.addHandler(&ws);
    wsServer.begin();

    Serial.println();
    Serial.print("[WS] WebSocket server started on /ws  IP: ");
    Serial.println(WiFi.localIP());
  }

  // ===================================================
  // HX711
  // ===================================================

  setupHX711();

  // ===================================================
  // TF-LUNA
  // ===================================================

  Serial.println();
  Serial.println(
    "Starting TF-Luna..."
  );

  TF_Luna.begin(
    115200,
    SERIAL_8N1,
    TF_LUNA_RX,
    TF_LUNA_TX
  );

  Serial.println(
    "TF-Luna UART ready"
  );

  Serial.print(
    "Mounting height: "
  );

  Serial.print(
    mountingHeightCm,
    1
  );

  Serial.println(
    " cm"
  );

  Serial.println();
  Serial.println(
    "========================================"
  );

  Serial.println(
    "SYSTEM READY"
  );

  Serial.println(
    "Waiting for kiosk START..."
  );

  Serial.println();
  Serial.println("SERIAL CALIBRATION COMMANDS (idle only):");
  Serial.println("  t = Tare (zero the scale)");
  Serial.println("  c = Calibrate with known weight");
  Serial.println("  r = Read current weight");
  Serial.println("  p = Print current calibration factor");
  Serial.println("========================================"
  );
}

// =====================================================
// LOOP
// =====================================================

void loop() {

  // ===================================================
  // HX711
  // ===================================================

  if (hx711Ready) {
    LoadCell.update();
  }

  // ===================================================
  // WEBSOCKET CLEANUP
  // ===================================================

  ws.cleanupClients();

  // ===================================================
  // SERIAL CALIBRATION (idle only)
  // ===================================================

  if (!measuring && Serial.available()) {

    String input = Serial.readStringUntil('\n');
    input.trim();
    input.toLowerCase();

    if (input == "t") {

      if (!hx711Ready) {
        Serial.println("HX711 not ready — cannot tare.");
      } else {
        Serial.println("Taring... remove all weight from platform.");
        LoadCell.tareNoDelay();
        delay(2000);
        Serial.print("Tare done. Offset: ");
        Serial.println(LoadCell.getTareOffset());
      }

    } else if (input == "r") {

      if (!hx711Ready) {
        Serial.println("HX711 not ready.");
      } else {
        LoadCell.update();
        float w = LoadCell.getData();
        Serial.print("Current weight: ");
        Serial.print(w, 3);
        Serial.println(" kg");
      }

    } else if (input == "p") {

      Serial.print("Calibration factor: ");
      Serial.println(hx711CalFactor, 4);

    } else if (input == "c") {

      if (!hx711Ready) {
        Serial.println("HX711 not ready — cannot calibrate.");
      } else {
        Serial.println("=== CALIBRATION MODE ===");
        Serial.println("Make sure platform is EMPTY and stable.");
        Serial.println("Then type the known weight in kg (e.g. 1.0 or 5.0):");
        calMode = true;
        calWaitingForWeight = true;
      }

    } else if (calWaitingForWeight) {

      float knownWeight = input.toFloat();

      if (knownWeight <= 0.0f) {
        Serial.println("Invalid weight. Type a number > 0 (e.g. 1.0):");
        return;
      }

      calKnownWeight = knownWeight;

      Serial.print("Taring with empty platform...");
      LoadCell.tareNoDelay();
      delay(2000);

      Serial.println("Now place your known weight on the platform.");
      Serial.println("Waiting 5 seconds for reading to stabilize...");

      float sum = 0;
      int samples = 0;

      for (int i = 0; i < 50; i++) {
        if (LoadCell.update()) {
          sum += LoadCell.getData();
          samples++;
        }
        delay(100);
      }

      if (samples < 10) {
        Serial.println("Not enough stable readings. Calibration FAILED.");
        Serial.println("Check HX711 wiring and try again.");
        calMode = false;
        calWaitingForWeight = false;
        return;
      }

      float avgRaw = sum / samples;

      float newFactor = avgRaw / calKnownWeight;

      Serial.println();
      Serial.println("=== CALIBRATION RESULT ===");
      Serial.print("Average raw value: ");
      Serial.println(avgRaw, 2);
      Serial.print("Known weight: ");
      Serial.print(calKnownWeight, 2);
      Serial.println(" kg");
      Serial.print("NEW calibration factor: ");
      Serial.println(newFactor, 4);

      hx711CalFactor = newFactor;
      LoadCell.setCalFactor(hx711CalFactor);

      Serial.println();
      Serial.println("Calibration factor UPDATED in memory.");
      Serial.println("To save permanently, update 'HX711 Calibration Factor'");
      Serial.println("in Admin > Sensors > Edit Device.");

      calMode = false;
      calWaitingForWeight = false;
    }
  }

  // ===================================================
  // WIFI
  // ===================================================

  if (WiFi.status() != WL_CONNECTED) {

    connectWiFi();

    delay(1000);

    return;
  }

  // ===================================================
  // MEASURING
  // ===================================================

  if (measuring) {

    if (
      millis() -
      measurementStartedAt >
      MEASUREMENT_TIMEOUT
    ) {

      Serial.println(
        "MEASUREMENT TIMEOUT"
      );

      measuring = false;
      currentSessionId = 0;

      return;
    }

    delay(20);

    return;
  }

  // ===================================================
  // POLL SERVER
  // ===================================================

  if (
    millis() -
    lastCommandPoll >=
    COMMAND_POLL_INTERVAL
  ) {

    lastCommandPoll =
      millis();

    long sessionId = 0;

    String command = "";
    String status = "";

    bool shouldMeasure = false;

    bool success =
      getMeasurementCommand(
        sessionId,
        command,
        shouldMeasure,
        status
      );

    if (!success) {

      delay(200);

      return;
    }

    // =================================================
    // START REQUEST
    // =================================================

    bool startRequested =
      shouldMeasure ||
      command.equalsIgnoreCase("START");

    if (
      startRequested &&
      sessionId > 0 &&
      sessionId != lastSessionId
    ) {

      Serial.println();
      Serial.println(
        "################################"
      );

      Serial.println(
        "NEW MEASUREMENT SESSION"
      );

      Serial.print("Session ID: ");
      Serial.println(sessionId);

      Serial.println(
        "################################"
      );

      runMeasurement(sessionId);
    }

    // =================================================
    // ALREADY PROCESSED
    // =================================================

    else if (
      sessionId != 0 &&
      sessionId == lastSessionId
    ) {

      Serial.print("Session ");
      Serial.print(sessionId);

      Serial.println(
        " already processed."
      );
    }
  }

  delay(20);
}