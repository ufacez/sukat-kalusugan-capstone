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

// Confirmed working Apache server
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

// YOUR WORKING CALIBRATION VALUE
const float HX711_CAL_FACTOR =
  -20892.50f;


// =====================================================
// TF-LUNA
// =====================================================

#define TF_LUNA_RX 4
#define TF_LUNA_TX 5

HardwareSerial TF_Luna(2);


// =====================================================
// TF-LUNA MOUNTING HEIGHT
// =====================================================
//
// TEMPORARY TEST VALUE.
//
// If sensor is mounted 210 cm above the platform,
// change this to:
//
// const float MOUNTING_HEIGHT_CM = 210.0f;
//
// =====================================================

const float MOUNTING_HEIGHT_CM =
  127.5f;

const float HEIGHT_OFFSET_CM =
  0.0f;


// =====================================================
// VALID MEASUREMENT RANGE
// =====================================================

const float MIN_HEIGHT_CM = 0.0f;
const float MAX_HEIGHT_CM = 250.0f;

const float MIN_WEIGHT_KG = 0.1f;
const float MAX_WEIGHT_KG = 300.0f;


// =====================================================
// TIMING
// =====================================================

const unsigned long COMMAND_POLL_INTERVAL =
  2000;

const unsigned long FIREBASE_UPDATE_INTERVAL =
  500;

const unsigned long MEASUREMENT_TIMEOUT =
  30000;


// =====================================================
// STATE
// =====================================================

bool hx711Ready = false;

bool measuring = false;

long currentSessionId = 0;

long lastSessionId = 0;

unsigned long lastCommandPoll = 0;

unsigned long lastFirebaseUpdate = 0;

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
// HTTP POST JSON
// =====================================================

String httpPostJson(
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
    http.POST(
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
// HTTP POST FORM DATA
// =====================================================
//
// This is used for submit_measurement.php.
//
// We tested this exact format successfully from
// PowerShell:
//
// device_id
// session_id
// height_cm
// weight_kg
//
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
  Serial.println(
    "================================"
  );

  Serial.println(
    "STARTING HX711"
  );

  Serial.println(
    "================================"
  );

  LoadCell.begin();

  unsigned long stabilizingTime =
    2000;

  bool tare =
    true;

  LoadCell.start(
    stabilizingTime,
    tare
  );

  if (
    LoadCell.getTareTimeoutFlag() ||
    LoadCell.getSignalTimeoutFlag()
  ) {

    Serial.println(
      "HX711 ERROR"
    );

    Serial.println(
      "Check DOUT and SCK wiring."
    );

    hx711Ready =
      false;

    return;
  }

  LoadCell.setCalFactor(
    HX711_CAL_FACTOR
  );

  Serial.println(
    "HX711 startup complete"
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

  Serial.println(
    "HX711 ready"
  );

  hx711Ready =
    true;
}


// =====================================================
// UPDATE HX711
// =====================================================

void updateHX711() {

  if (
    hx711Ready
  ) {

    LoadCell.update();
  }
}


// =====================================================
// GET CURRENT WEIGHT
// =====================================================

float getCurrentWeight() {

  if (
    !hx711Ready
  ) {

    return -1.0f;
  }

  LoadCell.update();

  return LoadCell.getData();
}


// =====================================================
// READ TF-LUNA DISTANCE
// =====================================================

bool readTFLunaDistanceCm(
  float& distanceCm
) {

  static uint8_t buffer[9];

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

    buffer[0] =
      0x59;

    buffer[1] =
      0x59;

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

        return false;
      }

      buffer[i] =
        (uint8_t)value;
    }

    // =================================================
    // CHECKSUM
    // =================================================

    uint16_t checksum =
      0;

    for (
      int i = 0;
      i < 8;
      i++
    ) {

      checksum +=
        buffer[i];
    }

    if (
      (checksum & 0xFF) !=
      buffer[8]
    ) {

      continue;
    }

    // =================================================
    // DISTANCE IN MM
    // =================================================

    uint16_t distanceMm =
      buffer[2] |
      ((uint16_t)buffer[3] << 8);

    distanceCm =
      distanceMm / 10.0f;

    return true;
  }

  return false;
}


// =====================================================
// GET HEIGHT
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
// FIREBASE UPDATE
// =====================================================

bool updateFirebase(
  long sessionId,
  const char* status,
  float heightCm,
  float weightKg
) {

  if (
    WiFi.status() != WL_CONNECTED
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

  StaticJsonDocument<512>
    doc;

  doc["device_id"] =
    DEVICE_ID;

  doc["session_id"] =
    sessionId;

  doc["status"] =
    status;

  doc["source_type"] =
    "kiosk";

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

  if (
    httpCode > 0 &&
    response.length() > 0
  ) {

    Serial.print(
      "Firebase response: "
    );

    Serial.println(
      response
    );
  }

  return (
    httpCode >= 200 &&
    httpCode < 300
  );
}


// =====================================================
// SUBMIT FINAL MEASUREMENT TO SQL
// =====================================================
//
// IMPORTANT:
//
// PHP endpoint was tested successfully using:
//
// device_id=ESP32-KIOSK-01
// session_id=1
// height_cm=90
// weight_kg=13
//
// Therefore this function sends FORM DATA instead of JSON.
//
// =====================================================

bool submitFinalMeasurement(
  long sessionId,
  float heightCm,
  float weightKg
) {

  String url =
    SERVER_IP +
    SUBMIT_MEASUREMENT_PATH;

  // ===================================================
  // CREATE FORM BODY
  // ===================================================

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
    "URL: "
  );

  Serial.println(
    url
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

  // ===================================================
  // PARSE PHP RESPONSE
  // ===================================================

  StaticJsonDocument<512>
    responseDoc;

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
// GET COMMAND FROM SERVER
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

  Serial.print(
    "Command URL: "
  );

  Serial.println(
    url
  );

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
      "Unable to get command. HTTP: "
    );

    Serial.println(
      httpCode
    );

    if (
      response.length() > 0
    ) {

      Serial.println(
        response
      );
    }

    return false;
  }

  StaticJsonDocument<1024>
    doc;

  DeserializationError error =
    deserializeJson(
      doc,
      response
    );

  if (
    error
  ) {

    Serial.println(
      "Failed to parse command response:"
    );

    Serial.println(
      response
    );

    return false;
  }

  // =================================================
  // UNWRAP "data"
  // =================================================
  //
  // Fixed ArduinoJson compatibility issue.
  //
  // We no longer use:
  //
  // doc["data"].isNull()
  //   ? doc.as<JsonVariant>()
  //   : doc["data"];
  //
  // because ArduinoJson 7 can produce incompatible
  // types in the ?: operator.
  //
  // =================================================

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

  // =================================================
  // READ SESSION ID
  // =================================================

  sessionId =
    data["session_id"] |
    0;

  // =================================================
  // READ COMMAND
  // =================================================

  command =
    String(
      data["command"] |
      ""
    );

  // =================================================
  // READ SHOULD_MEASURE
  // =================================================

  shouldMeasure =
    data["should_measure"] |
    false;

  // =================================================
  // READ STATUS
  // =================================================

  status =
    String(
      data["status"] |
      "IDLE"
    );

  // =================================================
  // DEBUG
  // =================================================

  Serial.println();
  Serial.println(
    "========== COMMAND =========="
  );

  Serial.print(
    "Status: "
  );

  Serial.println(
    status
  );

  Serial.print(
    "Command: "
  );

  Serial.println(
    command
  );

  Serial.print(
    "Should measure: "
  );

  Serial.println(
    shouldMeasure
      ? "YES"
      : "NO"
  );

  Serial.print(
    "Session ID: "
  );

  Serial.println(
    sessionId
  );

  Serial.println(
    "============================="
  );

  return true;
}


// =====================================================
// RUN MEASUREMENT
// =====================================================

void runMeasurement(
  long sessionId
) {

  measuring =
    true;

  currentSessionId =
    sessionId;

  measurementStartedAt =
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

  // =================================================
  // FIREBASE - MEASURING
  // =================================================

  updateFirebase(
    sessionId,
    "MEASURING",
    -1,
    -1
  );

  // =================================================
  // COUNTDOWN
  // =================================================

  Serial.println();
  Serial.println(
    "Please step on the platform."
  );

  for (
    int i = 3;
    i >= 1;
    i--
  ) {

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

  // =================================================
  // COLLECT DATA
  // =================================================

  Serial.println();
  Serial.println(
    "COLLECTING SENSOR DATA..."
  );

  const int WEIGHT_SAMPLES =
    30;

  const int HEIGHT_SAMPLES =
    20;

  float weightSum =
    0;

  int weightCount =
    0;

  float heightSum =
    0;

  int heightCount =
    0;

  unsigned long startTime =
    millis();

  while (
    millis() - startTime <
    5000
  ) {

    // =================================================
    // HX711
    // =================================================

    LoadCell.update();

    if (
      hx711Ready
    ) {

      float weight =
        LoadCell.getData();

      if (
        weight > MIN_WEIGHT_KG &&
        weight < MAX_WEIGHT_KG
      ) {

        weightSum +=
          weight;

        weightCount++;
      }
    }

    // =================================================
    // TF-LUNA
    // =================================================

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

        heightSum +=
          height;

        heightCount++;
      }
    }

    // =================================================
    // FIREBASE LIVE UPDATE
    // =================================================

    if (
      millis() -
      lastFirebaseUpdate >=
      FIREBASE_UPDATE_INTERVAL
    ) {

      lastFirebaseUpdate =
        millis();

      float liveWeight =
        -1;

      float liveHeight =
        -1;

      if (
        weightCount > 0
      ) {

        liveWeight =
          weightSum /
          weightCount;
      }

      if (
        heightCount > 0
      ) {

        liveHeight =
          heightSum /
          heightCount;
      }

      updateFirebase(
        sessionId,
        "MEASURING",
        liveHeight,
        liveWeight
      );
    }

    delay(20);
  }

  // =================================================
  // CALCULATE FINAL VALUES
  // =================================================

  float finalWeight =
    -1;

  float finalHeight =
    -1;

  if (
    weightCount > 0
  ) {

    finalWeight =
      weightSum /
      weightCount;
  }

  if (
    heightCount > 0
  ) {

    finalHeight =
      heightSum /
      heightCount;
  }

  // =================================================
  // DISPLAY RESULT
  // =================================================

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

  // =================================================
  // VALIDATE
  // =================================================

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

    Serial.println();
    Serial.println(
      "INVALID MEASUREMENT"
    );

    Serial.print(
      "Weight: "
    );

    Serial.println(
      finalWeight
    );

    Serial.print(
      "Height: "
    );

    Serial.println(
      finalHeight
    );

    updateFirebase(
      sessionId,
      "ERROR",
      finalHeight,
      finalWeight
    );

    measuring =
      false;

    currentSessionId =
      0;

    return;
  }

  // =================================================
  // SEND TO SQL
  // =================================================

  bool sqlSuccess =
    submitFinalMeasurement(
      sessionId,
      finalHeight,
      finalWeight
    );

  if (
    !sqlSuccess
  ) {

    Serial.println();
    Serial.println(
      "SQL FAILED"
    );

    Serial.println(
      "Firebase will remain ERROR."
    );

    updateFirebase(
      sessionId,
      "ERROR",
      finalHeight,
      finalWeight
    );

    measuring =
      false;

    return;
  }

  // =================================================
  // SQL SUCCESS
  // =================================================

  Serial.println();
  Serial.println(
    "SQL SAVED SUCCESSFULLY"
  );

  // =================================================
  // FIREBASE COMPLETE
  // =================================================

  updateFirebase(
    sessionId,
    "COMPLETE",
    finalHeight,
    finalWeight
  );

  // =================================================
  // SESSION FINISHED
  // =================================================

  lastSessionId =
    sessionId;

  currentSessionId =
    0;

  measuring =
    false;

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
    "========================================"
  );

  // =================================================
  // HX711
  // =================================================

  setupHX711();

  // =================================================
  // TF-LUNA
  // =================================================

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
    "Temporary mounting height: "
  );

  Serial.print(
    MOUNTING_HEIGHT_CM
  );

  Serial.println(
    " cm"
  );

  // =================================================
  // WIFI
  // =================================================

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

  // =================================================
  // KEEP HX711 ALIVE
  // =================================================

  if (
    hx711Ready
  ) {

    LoadCell.update();
  }

  // =================================================
  // WIFI
  // =================================================

  if (
    WiFi.status() !=
    WL_CONNECTED
  ) {

    connectWiFi();

    delay(1000);

    return;
  }

  // =================================================
  // ONLY POLL COMMAND WHEN NOT MEASURING
  // =================================================

  if (
    !measuring &&
    millis() -
    lastCommandPoll >=
    COMMAND_POLL_INTERVAL
  ) {

    lastCommandPoll =
      millis();

    long sessionId =
      0;

    String command =
      "";

    String status =
      "";

    bool shouldMeasure =
      false;

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
    // START CONDITION
    // =================================================
    //
    // Accept:
    //
    // 1. should_measure == true
    // 2. command == START
    // 3. status == MEASURING
    //
    // But only for a NEW session.
    // =================================================

    bool startRequested =
      shouldMeasure ||
      command.equalsIgnoreCase(
        "START"
      ) ||
      status.equalsIgnoreCase(
        "MEASURING"
      );

    if (
      startRequested &&
      sessionId > 0 &&
      sessionId != lastSessionId &&
      !measuring
    ) {

      Serial.println();
      Serial.println(
        "################################"
      );

      Serial.println(
        "START CONDITION RECEIVED"
      );

      Serial.print(
        "Session ID: "
      );

      Serial.println(
        sessionId
      );

      Serial.print(
        "Status: "
      );

      Serial.println(
        status
      );

      Serial.print(
        "Command: "
      );

      Serial.println(
        command
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

  // =================================================
  // SMALL DELAY
  // =================================================

  delay(20);
}