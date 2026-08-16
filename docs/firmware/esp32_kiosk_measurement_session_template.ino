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

const String SERVER_IP = "http://192.168.100.164:8000";

const String DEVICE_ID = "ESP32-KIOSK-01";

const String GET_COMMAND_PATH =
  "/api/esp32/get_command.php";

const String SUBMIT_MEASUREMENT_PATH =
  "/api/esp32/submit_measurement.php";


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
const float HX711_CAL_FACTOR = -20892.50f;


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
// TEMPORARY TEST:
//
// If TF-Luna is 100 cm above the platform:
//
//     MOUNTING_HEIGHT_CM = 100
//
// If you physically hold it 40 cm above the platform:
//
//     MOUNTING_HEIGHT_CM = 40
//
// When the kiosk is finally built:
//
//     MOUNTING_HEIGHT_CM = actual sensor height
//
// Example:
//
// Sensor height = 210 cm
// Distance to head = 75 cm
//
// Height = 210 - 75
// Height = 135 cm
//

const float MOUNTING_HEIGHT_CM = 100.0f;

const float HEIGHT_OFFSET_CM = 0.0f;


// =====================================================
// VALID MEASUREMENT LIMITS
// =====================================================

const float MIN_HEIGHT_CM = 40.0f;
const float MAX_HEIGHT_CM = 140.0f;

const float MIN_WEIGHT_KG = 0.1f;
const float MAX_WEIGHT_KG = 200.0f;


// =====================================================
// TIMING
// =====================================================

const unsigned long COMMAND_POLL_INTERVAL = 2000;

const unsigned long WEIGHT_MEASUREMENT_TIME = 5000;

const unsigned long HEIGHT_MEASUREMENT_TIME = 3000;

unsigned long lastCommandPoll = 0;


// =====================================================
// STATE
// =====================================================

bool hx711Ready = false;

bool measuring = false;

long lastSessionId = 0;


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
    Serial.println(WiFi.localIP());

  } else {

    Serial.println("WiFi connection FAILED");
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

  if (WiFi.status() != WL_CONNECTED) {

    Serial.println("WiFi not connected");

    return "";
  }

  HTTPClient http;

  http.begin(url);

  http.setTimeout(5000);

  httpCode = http.GET();

  String response = "";

  if (httpCode > 0) {

    response = http.getString();

  } else {

    Serial.print("HTTP GET failed: ");
    Serial.println(httpCode);
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

  if (WiFi.status() != WL_CONNECTED) {

    Serial.println("WiFi not connected");

    return "";
  }

  HTTPClient http;

  http.begin(url);

  http.setTimeout(10000);

  http.addHeader(
    "Content-Type",
    "application/json"
  );

  httpCode = http.POST(jsonBody);

  String response = "";

  if (httpCode > 0) {

    response = http.getString();

  } else {

    Serial.print("HTTP POST failed: ");
    Serial.println(httpCode);
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

    Serial.println(
      "Check DOUT and SCK wiring."
    );

    hx711Ready = false;

    return;
  }

  // IMPORTANT:
  // This is your working calibration value.
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

  while (!LoadCell.update()) {

    delay(1);
  }

  Serial.println(
    "HX711 ready"
  );

  hx711Ready = true;
}


// =====================================================
// UPDATE HX711
// =====================================================

void updateHX711() {

  if (!hx711Ready) {
    return;
  }

  LoadCell.update();
}


// =====================================================
// TF-LUNA FRAME READER
// =====================================================
//
// TF-Luna UART frame:
//
// 59 59
// DIST_L
// DIST_H
// STRENGTH_L
// STRENGTH_H
// TEMP_L
// TEMP_H
// CHECKSUM
//
// Distance is millimeters.
//

bool readTFLunaDistanceCm(
  float& distanceCm
) {

  static uint8_t buffer[9];

  while (TF_Luna.available() >= 9) {

    // Find first 0x59
    int firstByte =
      TF_Luna.read();

    if (firstByte != 0x59) {

      continue;
    }

    // Find second 0x59
    int secondByte =
      TF_Luna.read();

    if (secondByte != 0x59) {

      continue;
    }

    buffer[0] = 0x59;
    buffer[1] = 0x59;

    // Read remaining 7 bytes
    for (
      int i = 2;
      i < 9;
      i++
    ) {

      int value =
        TF_Luna.read();

      if (value < 0) {

        return false;
      }

      buffer[i] =
        (uint8_t)value;
    }

    // =================================================
    // CHECKSUM
    // =================================================

    uint16_t checksum = 0;

    for (
      int i = 0;
      i < 8;
      i++
    ) {

      checksum += buffer[i];
    }

    if (
      (checksum & 0xFF) !=
      buffer[8]
    ) {

      continue;
    }

    // =================================================
    // DISTANCE
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

  float distanceCm = 0;

  if (
    !readTFLunaDistanceCm(
      distanceCm
    )
  ) {

    return false;
  }

  // =================================================
  // HEIGHT CALCULATION
  // =================================================

  heightCm =
    MOUNTING_HEIGHT_CM -
    distanceCm +
    HEIGHT_OFFSET_CM;

  // =================================================
  // DEBUG
  // =================================================

  Serial.print(
    "TF-Luna distance: "
  );

  Serial.print(
    distanceCm,
    1
  );

  Serial.print(
    " cm | Calculated height: "
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
// STABLE WEIGHT MEASUREMENT
// =====================================================

float measureWeightStable() {

  if (!hx711Ready) {

    return -1.0f;
  }

  const int MAX_SAMPLES = 100;

  float readings[MAX_SAMPLES];

  int count = 0;

  unsigned long startTime =
    millis();

  while (
    millis() - startTime <
    WEIGHT_MEASUREMENT_TIME
  ) {

    // VERY IMPORTANT:
    // HX711_ADC needs update() continuously.
    LoadCell.update();

    float value =
      LoadCell.getData();

    if (
      value >= -5.0f &&
      value <= MAX_WEIGHT_KG
    ) {

      if (
        count < MAX_SAMPLES
      ) {

        readings[count] =
          value;

        count++;
      }
    }

    delay(20);
  }

  if (count == 0) {

    return -1.0f;
  }

  // =================================================
  // AVERAGE
  // =================================================

  float sum = 0;

  for (
    int i = 0;
    i < count;
    i++
  ) {

    sum += readings[i];
  }

  float average =
    sum / count;

  Serial.print(
    "Weight samples: "
  );

  Serial.println(
    count
  );

  Serial.print(
    "Average weight: "
  );

  Serial.print(
    average,
    2
  );

  Serial.println(
    " kg"
  );

  return average;
}


// =====================================================
// STABLE HEIGHT MEASUREMENT
// =====================================================

float measureHeightStable() {

  const int MAX_SAMPLES = 50;

  float readings[MAX_SAMPLES];

  int count = 0;

  unsigned long startTime =
    millis();

  while (
    millis() - startTime <
    HEIGHT_MEASUREMENT_TIME
  ) {

    float height = 0;

    if (
      getHeightReading(
        height
      )
    ) {

      if (
        height >= 0 &&
        height <= 250
      ) {

        if (
          count < MAX_SAMPLES
        ) {

          readings[count] =
            height;

          count++;
        }
      }
    }

    delay(30);
  }

  if (count == 0) {

    return -1.0f;
  }

  // =================================================
  // AVERAGE
  // =================================================

  float sum = 0;

  for (
    int i = 0;
    i < count;
    i++
  ) {

    sum += readings[i];
  }

  float average =
    sum / count;

  Serial.print(
    "Height samples: "
  );

  Serial.println(
    count
  );

  Serial.print(
    "Average height: "
  );

  Serial.print(
    average,
    1
  );

  Serial.println(
    " cm"
  );

  return average;
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

  String url =
    FIREBASE_URL +
    "/latest_measurements/" +
    DEVICE_ID +
    ".json";

  if (
    FIREBASE_AUTH.length() > 0
  ) {

    url += "?auth=";
    url += FIREBASE_AUTH;
  }

  StaticJsonDocument<768> doc;

  doc["device_id"] =
    DEVICE_ID;

  doc["session_id"] =
    sessionId;

  doc["status"] =
    status;

  doc["source_type"] =
    "kiosk";

  if (heightCm >= 0) {

    doc["height_cm"] =
      heightCm;

  } else {

    doc["height_cm"] =
      nullptr;
  }

  if (weightKg >= 0) {

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

  Serial.println();
  Serial.println(
    "Firebase update:"
  );

  Serial.println(
    body
  );

  int httpCode;

  String response =
    httpPostJson(
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

  Serial.print(
    "Firebase response: "
  );

  Serial.println(
    response
  );

  return (
    httpCode >= 200 &&
    httpCode < 300
  );
}


// =====================================================
// SUBMIT FINAL MEASUREMENT TO SQL
// =====================================================

bool submitFinalMeasurement(
  long sessionId,
  float heightCm,
  float weightKg
) {

  String url =
    SERVER_IP +
    SUBMIT_MEASUREMENT_PATH;

  StaticJsonDocument<256> doc;

  doc["device_id"] =
    DEVICE_ID;

  doc["session_id"] =
    sessionId;

  doc["height_cm"] =
    heightCm;

  doc["weight_kg"] =
    weightKg;

  doc["source_type"] =
    "kiosk";

  String payload;

  serializeJson(
    doc,
    payload
  );

  Serial.println();
  Serial.println(
    "================================"
  );

  Serial.println(
    "SUBMITTING FINAL MEASUREMENT"
  );

  Serial.println(
    "================================"
  );

  Serial.print(
    "POST: "
  );

  Serial.println(
    url
  );

  Serial.print(
    "BODY: "
  );

  Serial.println(
    payload
  );

  int httpCode;

  String response =
    httpPostJson(
      url,
      payload,
      httpCode
    );

  Serial.print(
    "HTTP POST code: "
  );

  Serial.println(
    httpCode
  );

  Serial.print(
    "Response: "
  );

  Serial.println(
    response
  );

  // =================================================
  // CONNECTION ERROR
  // =================================================

  if (httpCode <= 0) {

    Serial.println();
    Serial.println(
      "SQL SUBMISSION FAILED"
    );

    Serial.println(
      "Could not connect to PHP."
    );

    return false;
  }

  // =================================================
  // HTTP ERROR
  // =================================================

  if (httpCode != 200) {

    Serial.println();
    Serial.println(
      "SQL SUBMISSION FAILED"
    );

    Serial.print(
      "HTTP code: "
    );

    Serial.println(
      httpCode
    );

    return false;
  }

  // =================================================
  // PARSE PHP RESPONSE
  // =================================================

  StaticJsonDocument<768> responseDoc;

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
    responseDoc["success"] |
    false;

  if (!success) {

    const char* message =
      responseDoc["message"] |
      "Unknown server error";

    Serial.println();
    Serial.println(
      "================================"
    );

    Serial.println(
      "PHP REJECTED MEASUREMENT"
    );

    Serial.print(
      "Reason: "
    );

    Serial.println(
      message
    );

    Serial.println(
      "================================"
    );

    return false;
  }

  Serial.println();
  Serial.println(
    "================================"
  );

  Serial.println(
    "SQL SUBMISSION SUCCESS"
  );

  Serial.println(
    "================================"
  );

  return true;
}


// =====================================================
// GET MEASUREMENT COMMAND
// =====================================================
//
// IMPORTANT FIX:
//
// Your PHP response is:
//
// {
//   "success": true,
//   "data": {
//      "session_id": 7,
//      "command": "START",
//      "should_measure": true,
//      "status": "MEASURING"
//   }
// }
//
// Therefore we MUST read:
//
// doc["data"]["session_id"]
//
// instead of:
//
// doc["session_id"]
//
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

    Serial.println();
    Serial.println(
      "GET COMMAND FAILED"
    );

    Serial.print(
      "HTTP code: "
    );

    Serial.println(
      httpCode
    );

    Serial.print(
      "Response: "
    );

    Serial.println(
      response
    );

    return false;
  }

  // =================================================
  // PRINT RAW SERVER RESPONSE
  // =================================================

  Serial.println();
  Serial.println(
    "========== SERVER RESPONSE =========="
  );

  Serial.println(
    response
  );

  Serial.println(
    "====================================="
  );


  // =================================================
  // PARSE JSON
  // =================================================

  StaticJsonDocument<1024> doc;

  DeserializationError error =
    deserializeJson(
      doc,
      response
    );

  if (error) {

    Serial.println();
    Serial.println(
      "JSON PARSE ERROR"
    );

    Serial.println(
      error.c_str()
    );

    return false;
  }


  // =================================================
  // GET DATA OBJECT
  // =================================================

  JsonObject data =
    doc["data"].as<JsonObject>();


  if (data.isNull()) {

    Serial.println();
    Serial.println(
      "ERROR: SERVER RESPONSE HAS NO DATA OBJECT"
    );

    return false;
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
    shouldMeasure ?
    "YES" :
    "NO"
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
// RUN ONE MEASUREMENT
// =====================================================

void runMeasurement(
  long sessionId
) {

  measuring = true;

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
  // FIREBASE -> MEASURING
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
  // COLLECT SENSOR DATA
  // =================================================

  Serial.println();
  Serial.println(
    "COLLECTING SENSOR DATA..."
  );


  // Weight
  float weightKg =
    measureWeightStable();


  // Height
  float heightCm =
    measureHeightStable();


  // =================================================
  // RESULTS
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
    weightKg,
    2
  );

  Serial.println(
    " kg"
  );

  Serial.print(
    "Height: "
  );

  Serial.print(
    heightCm,
    1
  );

  Serial.println(
    " cm"
  );

  Serial.println(
    "================================"
  );


  // =================================================
  // VALIDATE WEIGHT
  // =================================================

  bool validWeight =
    weightKg >= MIN_WEIGHT_KG &&
    weightKg <= MAX_WEIGHT_KG;


  // =================================================
  // VALIDATE HEIGHT
  // =================================================

  bool validHeight =
    heightCm >= MIN_HEIGHT_CM &&
    heightCm <= MAX_HEIGHT_CM;


  // =================================================
  // INVALID WEIGHT
  // =================================================

  if (!validWeight) {

    Serial.println();
    Serial.println(
      "INVALID WEIGHT"
    );

    updateFirebase(
      sessionId,
      "ERROR",
      heightCm,
      weightKg
    );

    measuring = false;

    return;
  }


  // =================================================
  // INVALID HEIGHT
  // =================================================

  if (!validHeight) {

    Serial.println();
    Serial.println(
      "INVALID HEIGHT"
    );

    Serial.print(
      "Height received: "
    );

    Serial.print(
      heightCm,
      1
    );

    Serial.println(
      " cm"
    );

    Serial.println(
      "Valid range: 40-140 cm"
    );

    updateFirebase(
      sessionId,
      "ERROR",
      heightCm,
      weightKg
    );

    measuring = false;

    return;
  }


  // =================================================
  // SQL FIRST
  // =================================================

  Serial.println();
  Serial.println(
    "Sending measurement to SQL..."
  );

  bool sqlSuccess =
    submitFinalMeasurement(
      sessionId,
      heightCm,
      weightKg
    );


  // =================================================
  // SQL FAILED
  // =================================================

  if (!sqlSuccess) {

    Serial.println();
    Serial.println(
      "################################"
    );

    Serial.println(
      "SQL FAILED"
    );

    Serial.println(
      "Firebase will NOT be marked COMPLETE."
    );

    Serial.println(
      "################################"
    );


    updateFirebase(
      sessionId,
      "ERROR",
      heightCm,
      weightKg
    );


    measuring = false;

    return;
  }


  // =================================================
  // SQL SUCCESS
  // =================================================

  Serial.println();
  Serial.println(
    "SQL result saved successfully."
  );


  // =================================================
  // FIREBASE COMPLETE
  // =================================================

  bool firebaseSuccess =
    updateFirebase(
      sessionId,
      "COMPLETE",
      heightCm,
      weightKg
    );


  if (firebaseSuccess) {

    Serial.println(
      "Firebase result saved."
    );

  } else {

    Serial.println(
      "WARNING: Firebase update failed."
    );
  }


  // =================================================
  // SAVE SESSION ID
  // =================================================

  lastSessionId =
    sessionId;


  Serial.println();
  Serial.println(
    "################################"
  );

  Serial.println(
    "MEASUREMENT SESSION FINISHED"
  );

  Serial.println(
    "Waiting for next START..."
  );

  Serial.println(
    "################################"
  );


  measuring = false;
}


// =====================================================
// SETUP
// =====================================================

void setup() {

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
    "HX711 + TF-LUNA + FIREBASE + SQL"
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
    MOUNTING_HEIGHT_CM,
    1
  );

  Serial.println(
    " cm"
  );


  // =================================================
  // WIFI
  // =================================================

  connectWiFi();


  // =================================================
  // READY
  // =================================================

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
  // KEEP HX711 UPDATED
  // =================================================

  updateHX711();


  // =================================================
  // WIFI
  // =================================================

  if (
    WiFi.status() != WL_CONNECTED
  ) {

    connectWiFi();

    delay(1000);

    return;
  }


  // =================================================
  // POLL SERVER
  // =================================================

  if (
    !measuring &&
    millis() - lastCommandPoll >=
    COMMAND_POLL_INTERVAL
  ) {

    lastCommandPoll =
      millis();


    long sessionId = 0;

    String command = "";

    String status = "";

    bool shouldMeasure =
      false;


    bool success =
      getMeasurementCommand(
        sessionId,
        command,
        shouldMeasure,
        status
      );


    if (!success) {

      Serial.println(
        "Unable to get command."
      );

      delay(500);

      return;
    }


    // =================================================
    // START CONDITION
    // =================================================
    //
    // Measurement starts ONLY when:
    //
    // should_measure == true
    //
    // OR
    //
    // command == START
    //
    // AND there is a valid session.
    //

    bool startRequested =
      shouldMeasure ||
      command.equalsIgnoreCase(
        "START"
      );


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
        "START COMMAND RECEIVED"
      );

      Serial.print(
        "Session: "
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
    // SAME SESSION
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
  // KEEP HX711 ALIVE
  // =================================================

  updateHX711();


  // =================================================
  // SMALL DELAY
  // =================================================

  delay(20);
}