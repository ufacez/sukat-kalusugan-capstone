#include <WiFi.h>
#include <HTTPClient.h>
#include <ArduinoJson.h>
#include <HX711_ADC.h>

// =====================================================
// WIFI
// =====================================================

const char* WIFI_SSID = "La Familia";
const char* WIFI_PASSWORD = "enz0p4o1931";

// =====================================================
// SERVER
// =====================================================

const String SERVER_IP =
  "http://192.168.100.164/sukat-kalusugan-capstone/";

const String DEVICE_ID =
  "ESP32-KIOSK-01";

const String GET_COMMAND_PATH =
  "public_html/api/esp32/get_command.php";

const String SUBMIT_MEASUREMENT_PATH =
  "public_html/api/esp32/submit_measurement.php";

// =====================================================
// FIREBASE
// =====================================================

const String FIREBASE_URL =
  "https://sukatkalusugan-default-rtdb.firebaseio.com";

const String FIREBASE_AUTH = "";

// =====================================================
// HX711
// =====================================================

const int HX711_DOUT = 16;
const int HX711_SCK  = 17;

HX711_ADC LoadCell(
  HX711_DOUT,
  HX711_SCK
);

const float HX711_CAL_FACTOR =
  -20892.50f;

// =====================================================
// TF-LUNA
// =====================================================

#define TF_LUNA_RX 4
#define TF_LUNA_TX 5

HardwareSerial TF_Luna(2);

// =====================================================
// HEIGHT
// =====================================================

// TF-Luna is mounted 6 ft (182.88 cm) above the platform.
const float MOUNTING_HEIGHT_CM =
  182.88f;

const float HEIGHT_OFFSET_CM =
  0.0f;

// =====================================================
// VALID RANGE
// =====================================================

const float MIN_HEIGHT_CM = 0.0f;
const float MAX_HEIGHT_CM = 250.0f;

const float MIN_WEIGHT_KG = 0.1f;
const float MAX_WEIGHT_KG = 300.0f;
// Ignore tare drift and vibration when the platform is empty. The actual
// validation range remains MIN_WEIGHT_KG so very small valid readings are
// still accepted after a person steps on the platform.
const float EMPTY_PLATFORM_THRESHOLD_KG = 1.0f;

// =====================================================
// TIMING
// =====================================================

const unsigned long COMMAND_POLL_INTERVAL = 2000;
const unsigned long FIREBASE_UPDATE_INTERVAL = 500;
const unsigned long SESSION_VALIDATE_INTERVAL = 1500;

// The operator now controls when a measurement finalizes (by clicking
// "Process Measurement" in the kiosk UI), so this is a safety ceiling
// on how long the ESP32 will keep live-sampling without ever seeing a
// PROCESS command, not the length of the sample window itself. Kept
// well under the backend's own MEASUREMENT_SESSION_TIMEOUT_SECONDS
// (180s, see measurement_sessions.php) so the firmware times out first
// and reports a clean local error.
const unsigned long MEASUREMENT_TIMEOUT = 120000;

// How many of the most recent samples to average into the "live"
// weight/height value. A rolling window (not a running average since
// the session began) keeps the live readout responsive to someone
// stepping on/off the platform while waiting for the operator to
// click Process.
const int WEIGHT_SAMPLE_WINDOW = 8;
const int HEIGHT_SAMPLE_WINDOW = 8;

// How many CONSECUTIVE fresh raw samples (not the smoothed average
// above) must agree within the epsilon below before the device
// itself considers weight/height "stable" and reports that to the
// kiosk via Firebase. Computed from the raw sensor, not the rolling
// average, so a slow-moving average can no longer look "stable"
// while the true reading is still settling.
const int STABLE_SAMPLES_REQUIRED = 5;
const float WEIGHT_STABLE_EPSILON_KG = 0.15f;
const float HEIGHT_STABLE_EPSILON_CM = 1.0f;

// =====================================================
// STATE
// =====================================================

bool hx711Ready = false;
bool measuring = false;

long currentSessionId = 0;
long lastSessionId = 0;

unsigned long lastCommandPoll = 0;
unsigned long lastFirebaseUpdate = 0;
unsigned long lastSessionValidation = 0;
unsigned long measurementStartedAt = 0;

// =====================================================
// WIFI
// =====================================================

void connectWiFi() {

  if (WiFi.status() == WL_CONNECTED) {
    return;
  }

  Serial.println();
  Serial.println("================================");
  Serial.println("CONNECTING TO WIFI");
  Serial.println("================================");

  WiFi.mode(WIFI_STA);

  WiFi.begin(
    WIFI_SSID,
    WIFI_PASSWORD
  );

  int attempts = 0;

  while (
    WiFi.status() != WL_CONNECTED &&
    attempts < 40
  ) {

    delay(500);

    Serial.print(".");

    attempts++;
  }

  Serial.println();

  if (WiFi.status() == WL_CONNECTED) {

    Serial.println("WiFi connected");

    Serial.print("ESP32 IP: ");

    Serial.println(
      WiFi.localIP()
    );

  } else {

    Serial.println(
      "WiFi connection FAILED"
    );
  }
}

// =====================================================
// HTTP GET
// =====================================================

String httpGet(
  const String& url,
  int& httpCode
) {

  httpCode = -1;

  if (
    WiFi.status() != WL_CONNECTED
  ) {
    return "";
  }

  HTTPClient http;

  http.begin(url);

  http.setTimeout(5000);

  httpCode =
    http.GET();

  String response = "";

  if (httpCode > 0) {
    response =
      http.getString();
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

  if (
    WiFi.status() != WL_CONNECTED
  ) {
    return "";
  }

  HTTPClient http;

  http.begin(url);

  http.setTimeout(10000);

  http.addHeader(
    "Content-Type",
    "application/x-www-form-urlencoded"
  );

  httpCode =
    http.POST(
      formBody
    );

  String response = "";

  if (httpCode > 0) {
    response =
      http.getString();
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

  if (
    WiFi.status() != WL_CONNECTED
  ) {
    return "";
  }

  HTTPClient http;

  http.begin(url);

  http.setTimeout(10000);

  http.addHeader(
    "Content-Type",
    "application/json"
  );

  httpCode =
    http.PUT(
      jsonBody
    );

  String response = "";

  if (httpCode > 0) {
    response =
      http.getString();
  }

  http.end();

  return response;
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

  unsigned long stabilizingTime =
    2000;

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
    HX711_CAL_FACTOR
  );

  Serial.print(
    "Calibration factor: "
  );

  Serial.println(
    LoadCell.getCalFactor()
  );

  while (
    !LoadCell.update()
  ) {
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

  // TF-Luna streams frames at ~100Hz but this function is only
  // called once per loop tick (~20ms), so several frames can be
  // sitting in the UART buffer by the time we read. Drain ALL of
  // them and keep the LAST valid one so we report the freshest
  // distance instead of a stale, already-superseded frame.

  bool gotFrame = false;

  while (
    TF_Luna.available() >= 9
  ) {

    int first =
      TF_Luna.read();

    if (
      first != 0x59
    ) {
      continue;
    }

    int second =
      TF_Luna.read();

    if (
      second != 0x59
    ) {
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

      int value =
        TF_Luna.read();

      if (
        value < 0
      ) {
        readFailed = true;
        break;
      }

      buffer[i] =
        (uint8_t)value;
    }

    if (
      readFailed
    ) {
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

    // Keep looping — if another frame is already waiting,
    // it's newer than the one we just parsed.
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

  if (
    !readTFLunaDistanceCm(
      distanceCm
    )
  ) {

    return false;
  }

  heightCm =
    MOUNTING_HEIGHT_CM
    - distanceCm
    + HEIGHT_OFFSET_CM;

  Serial.print(
    "TF-Luna distance: "
  );

  Serial.print(
    distanceCm,
    1
  );

  Serial.print(
    " cm | Height: "
  );

  Serial.print(
    heightCm,
    1
  );

  Serial.println(
    " cm"
  );

  return true;
}

// =====================================================
// GET CURRENT COMMAND
// =====================================================

bool getMeasurementCommand(
  long& sessionId,
  String& command,
  bool& shouldMeasure,
  String& status
) {

  String url =
    SERVER_IP +
    GET_COMMAND_PATH +
    "?device_id=" +
    DEVICE_ID;

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

    Serial.print(
      "Command HTTP: "
    );

    Serial.println(
      httpCode
    );

    return false;
  }

  StaticJsonDocument<1024> doc;

  DeserializationError error =
    deserializeJson(
      doc,
      response
    );

  if (
    error
  ) {

    Serial.println(
      "Command JSON parse failed."
    );

    Serial.println(
      response
    );

    return false;
  }

  JsonVariant data;

  if (
    doc["data"].isNull()
  ) {

    data =
      doc.as<JsonVariant>();

  } else {

    data =
      doc["data"];
  }

  sessionId =
    data["session_id"] |
    0;

  command =
    String(
      data["command"] |
      ""
    );

  shouldMeasure =
    data["should_measure"] |
    false;

  status =
    String(
      data["status"] |
      "IDLE"
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
// IMPORTANT SESSION VALIDATION
// =====================================================
//
// The ESP32 must stop publishing Firebase data if
// another/newer SQL session has been created.
//
// This is the main fix for:
//
// Ignoring Firebase session mismatch
// {expected: 3, received: 2}
// =====================================================

bool isCurrentSessionStillValid(
  long sessionId
) {

  if (
    sessionId <= 0
  ) {
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

  if (
    !success
  ) {

    // Do NOT immediately kill the measurement because
    // one HTTP request failed.
    return true;
  }

  if (
    serverSessionId <= 0
  ) {

    Serial.println();
    Serial.println(
      "CURRENT SESSION NO LONGER ACTIVE"
    );

    return false;
  }

  if (
    serverSessionId != sessionId
  ) {

    Serial.println();
    Serial.println(
      "!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!"
    );

    Serial.println(
      "STALE ESP32 SESSION DETECTED"
    );

    Serial.print(
      "ESP session: "
    );

    Serial.println(
      sessionId
    );

    Serial.print(
      "Server session: "
    );

    Serial.println(
      serverSessionId
    );

    Serial.println(
      "Stopping old session."
    );

    Serial.println(
      "!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!"
    );

    return false;
  }

  return true;
}

// =====================================================
// SESSION + PROCESS COMMAND POLL
// =====================================================
//
// Used by the live-sampling loop in runMeasurement(). Combines the
// existing "is this still the current session" check with a check for
// the operator's PROCESS command (set by request_process.php when the
// kiosk UI's "Process Measurement" button is clicked) into a single
// HTTP round trip to get_command.php, instead of two.
//
// stillValid  - false if a newer/different session has taken over and
//               this measurement must stop immediately without
//               submitting anything.
// shouldProcess - true only once the operator has clicked Process for
//               THIS session. Until then the loop keeps sampling and
//               publishing live readings and must NOT submit a final
//               measurement.
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

  if (
    !success
  ) {

    // A single failed HTTP poll should never abort a measurement
    // that's otherwise in progress, and it must never be
    // misread as a PROCESS command.
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
    command.equalsIgnoreCase(
      "PROCESS"
    );

  if (
    shouldProcess
  ) {

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
  bool heightStable = false
) {

  if (
    WiFi.status() != WL_CONNECTED
  ) {
    return false;
  }

  if (
    sessionId <= 0
  ) {
    return false;
  }

  String url =
    FIREBASE_URL +
    "/latest_measurements/" +
    DEVICE_ID +
    ".json";

  if (
    FIREBASE_AUTH.length() > 0
  ) {

    url +=
      "?auth=";

    url +=
      FIREBASE_AUTH;
  }

  StaticJsonDocument<512> doc;

  doc["device_id"] =
    DEVICE_ID;

  doc["session_id"] =
    sessionId;

  doc["status"] =
    status;

  doc["source_type"] =
    "kiosk";

  doc["weight_stable"] =
    weightStable;

  doc["height_stable"] =
    heightStable;

  if (
    heightCm >= 0
  ) {
    doc["height_cm"] =
      heightCm;
  } else {
    doc["height_cm"] =
      nullptr;
  }

  if (
    weightKg >= 0
  ) {
    doc["weight_kg"] =
      weightKg;
  } else {
    doc["weight_kg"] =
      nullptr;
  }

  doc["timestamp"] =
    millis();

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

  Serial.print(
    "Firebase HTTP: "
  );

  Serial.println(
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
  bool skipSessionCheck = false
) {

  if (
    sessionId <= 0
  ) {
    return false;
  }

  // Do not allow a stale ESP session to overwrite
  // the Firebase node belonging to a newer session.
  if (
    !skipSessionCheck &&
    !isCurrentSessionStillValid(
      sessionId
    )
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
    heightStable
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
    SERVER_IP +
    SUBMIT_MEASUREMENT_PATH;

  String payload =
    "device_id=" +
    DEVICE_ID +
    "&session_id=" +
    String(sessionId) +
    "&height_cm=" +
    String(heightCm, 1) +
    "&weight_kg=" +
    String(weightKg, 2);

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

  Serial.print(
    "Payload: "
  );

  Serial.println(
    payload
  );

  int httpCode;

  String response =
    httpPostForm(
      url,
      payload,
      httpCode
    );

  Serial.print(
    "SQL HTTP: "
  );

  Serial.println(
    httpCode
  );

  Serial.print(
    "SQL response: "
  );

  Serial.println(
    response
  );

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

  if (
    error
  ) {

    Serial.println(
      "Invalid JSON from PHP."
    );

    return false;
  }

  bool success =
    responseDoc["success"] |
    false;

  if (
    !success
  ) {

    const char* message =
      responseDoc["message"] |
      "Unknown server error";

    Serial.print(
      "PHP ERROR: "
    );

    Serial.println(
      message
    );

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

  currentSessionId =
    sessionId;

  measurementStartedAt =
    millis();

  lastSessionValidation =
    millis();

  Serial.println();
  Serial.println(
    "################################"
  );

  Serial.println(
    "STARTING MEASUREMENT"
  );

  Serial.print(
    "Session ID: "
  );

  Serial.println(
    sessionId
  );

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
    int i = 3;
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

    Serial.print(
      "Measuring in "
    );

    Serial.print(
      i
    );

    Serial.println(
      "..."
    );

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
  // ROLLING SAMPLE BUFFERS
  // ===================================================
  //
  // Fixed-size circular buffers so the "live" value reflects
  // the most recent WEIGHT_SAMPLE_WINDOW / HEIGHT_SAMPLE_WINDOW
  // readings, not a running average since the loop started.
  // That keeps the live readout responsive if the child steps
  // on/off the platform while waiting for the operator to
  // click Process.

  static float weightBuffer[WEIGHT_SAMPLE_WINDOW];
  int weightBufIndex = 0;
  int weightBufFilled = 0;

  static float heightBuffer[HEIGHT_SAMPLE_WINDOW];
  int heightBufIndex = 0;
  int heightBufFilled = 0;

  // Raw-sample stability tracking. Deliberately separate from the
  // smoothed weightBuffer/heightBuffer averages above: comparing two
  // already-averaged numbers 500ms apart can look "stable" while the
  // true reading is still settling, because averaging itself hides
  // the moment-to-moment movement. Comparing consecutive RAW sensor
  // samples instead means "stable" only becomes true once the actual
  // sensor has stopped changing.
  bool haveLastRawWeight = false;
  float lastRawWeight = 0;
  int weightStableCount = 0;
  bool weightStable = false;

  bool haveLastRawHeight = false;
  float lastRawHeight = 0;
  int heightStableCount = 0;
  bool heightStable = false;

  bool shouldProcess = false;

  while (
    true
  ) {

    // ================================================
    // OVERALL SAFETY TIMEOUT
    // ================================================
    //
    // Only trips if the operator never clicks Process at all.
    // A legitimate wait for the operator does not reset this.

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
    // SESSION VALIDATION + PROCESS COMMAND
    // ================================================

    if (
      millis() -
      lastSessionValidation >=
      SESSION_VALIDATE_INTERVAL
    ) {

      lastSessionValidation =
        millis();

      bool stillValid = true;

      pollSessionState(
        sessionId,
        stillValid,
        shouldProcess
      );

      if (
        !stillValid
      ) {

        Serial.println(
          "Measurement cancelled because a newer session exists."
        );

        measuring = false;

        currentSessionId = 0;

        return;
      }

      if (
        shouldProcess
      ) {

        // Stop sampling right away; final values are computed
        // from whatever is already in the rolling buffers.
        break;
      }
    }

    // ================================================
    // HX711
    // ================================================

    if (
      hx711Ready &&
      LoadCell.update()
    ) {

      // Only reached when the HX711 actually finished a new
      // conversion — prevents re-adding the same stale cached
      // reading into the buffer multiple times per conversion.

      float weight =
         LoadCell.getData();

      Serial.print("HX711 weight: ");
      Serial.print(weight, 3);
      Serial.println(" kg");

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

        // ============================================
        // RAW STABILITY (weight)
        // ============================================

        if (
          haveLastRawWeight &&
          fabs(weight - lastRawWeight) <=
            WEIGHT_STABLE_EPSILON_KG
        ) {
          weightStableCount++;
        } else {
          weightStableCount =
            max(0, weightStableCount - 1);
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

    if (
      getHeightReading(
        height
      )
    ) {

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

        // ============================================
        // RAW STABILITY (height)
        // ============================================

        if (
          haveLastRawHeight &&
          fabs(height - lastRawHeight) <=
            HEIGHT_STABLE_EPSILON_CM
        ) {
          heightStableCount++;
        } else {
          heightStableCount =
            max(0, heightStableCount - 1);
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
    // FIREBASE LIVE
    // ================================================

    if (
      millis() -
      lastFirebaseUpdate >=
      FIREBASE_UPDATE_INTERVAL
    ) {

      lastFirebaseUpdate =
        millis();

      float liveWeight = -1;
      float liveHeight = -1;

      if (
        weightBufFilled > 0
      ) {

        float sum = 0;

        for (
          int i = 0;
          i < weightBufFilled;
          i++
        ) {
          sum += weightBuffer[i];
        }

        liveWeight =
          sum /
          weightBufFilled;
      }

      if (
        heightBufFilled > 0
      ) {

        float sum = 0;

        for (
          int i = 0;
          i < heightBufFilled;
          i++
        ) {
          sum += heightBuffer[i];
        }

        liveHeight =
          sum /
          heightBufFilled;
      }

      safeFirebaseUpdate(
        sessionId,
        "MEASURING",
        liveHeight,
        liveWeight,
        weightStable,
        heightStable,
        true // skipSessionCheck — already validated by pollSessionState() above
      );
    }

    delay(20);
  }

  // ===================================================
  // FINAL VALUES
  // ===================================================
  //
  // Averaged from whatever is currently in the rolling
  // buffers at the moment PROCESS was received.

  float finalWeight = -1;
  float finalHeight = -1;

  if (
    weightBufFilled > 0
  ) {

    float sum = 0;

    for (
      int i = 0;
      i < weightBufFilled;
      i++
    ) {
      sum += weightBuffer[i];
    }

    finalWeight =
      sum /
      weightBufFilled;
  }

  if (
    heightBufFilled > 0
  ) {

    float sum = 0;

    for (
      int i = 0;
      i < heightBufFilled;
      i++
    ) {
      sum += heightBuffer[i];
    }

    finalHeight =
      sum /
      heightBufFilled;
  }

  Serial.println();
  Serial.println(
    "================================"
  );

  Serial.println(
    "MEASUREMENT RESULT"
  );

  Serial.print(
    "Weight: "
  );

  Serial.print(
    finalWeight,
    2
  );

  Serial.println(
    " kg"
  );

  Serial.print(
    "Height: "
  );

  Serial.print(
    finalHeight,
    1
  );

  Serial.println(
    " cm"
  );

  Serial.println(
    "================================"
  );

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
  // SQL
  // ===================================================

  bool sqlSuccess =
    submitFinalMeasurement(
      sessionId,
      finalHeight,
      finalWeight
    );

  if (
    !sqlSuccess
  ) {

    Serial.println(
      "SQL FAILED"
    );

    // Only publish ERROR if this is still
    // the current session.
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

  // ===================================================
  // FIREBASE COMPLETE
  // ===================================================

  safeFirebaseUpdate(
    sessionId,
    "COMPLETE",
    finalHeight,
    finalWeight,
    true,
    true
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
// SETUP
// =====================================================

void setup() {

  Serial.begin(
    115200
  );

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
    MOUNTING_HEIGHT_CM,
    1
  );

  Serial.println(
    " cm"
  );

  // ===================================================
  // WIFI
  // ===================================================

  connectWiFi();

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

  Serial.println(
    "========================================"
  );
}

// =====================================================
// LOOP
// =====================================================

void loop() {

  // ===================================================
  // HX711
  // ===================================================

  if (
    hx711Ready
  ) {

    LoadCell.update();
  }

  // ===================================================
  // WIFI
  // ===================================================

  if (
    WiFi.status() != WL_CONNECTED
  ) {

    connectWiFi();

    delay(1000);

    return;
  }

  // ===================================================
  // MEASURING
  // ===================================================

  if (
    measuring
  ) {

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

    if (
      !success
    ) {

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

      Serial.print(
        "Session ID: "
      );

      Serial.println(
        sessionId
      );

      Serial.println(
        "################################"
      );

      runMeasurement(
        sessionId
      );
    }

    // =================================================
    // ALREADY PROCESSED
    // =================================================

    else if (
      sessionId != 0 &&
      sessionId == lastSessionId
    ) {

      Serial.print(
        "Session "
      );

      Serial.print(
        sessionId
      );

      Serial.println(
        " already processed."
      );
    }
  }

  delay(20);
}