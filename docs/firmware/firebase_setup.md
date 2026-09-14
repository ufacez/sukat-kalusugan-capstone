# Firebase Setup for Live Kiosk Mirror

This project uses Firebase Realtime Database as a live mirror for kiosk results.
**MySQL is the source of truth.** Firebase is a read-only live notification layer written
**only by the PHP backend** (`php > firebase_sync.php`), not by the ESP32.

## Architecture

```
ESP32 (kiosk)          PHP Backend              MySQL            Firebase RTDB
     │                      │                    │                    │
     ├──GET /get_command──►│                    │                    │
     │◄─── command+calibration─┘                 │                    │
     │                      │                    │                    │
     ├──HX711/TF-Luna reads│                    │                    │
     ├──WebSocket ──────────────► kiosk browser  │                    │
     │  (live, 100fps)     │                    │                    │
     │                      │                    │                    │
     └──POST /submit_measurement ───────────────►│ (WHO calc)        │
                                                │                   │
                                                └──PUT /latest_measurements/──► Firebase
```

The ESP32 no longer writes to Firebase directly. This means:
- Firebase credentials are kept server-side only (not baked into ESP32 firmware)
- No open Firebase `.write` rules are needed
- PHP controls all Firebase writes after MySQL save completes

## What to create in Firebase

1. Create a Firebase project.
2. Enable **Realtime Database**.
3. Copy the database URL, for example:
   `https://your-project-default-rtdb.firebaseio.com`
4. Put that URL into [public_html/includes/config.php](../public_html/includes/config.php)
   and into your `.env` file:

```env
FIREBASE_DATABASE_URL=https://your-project-default-rtdb.firebaseio.com
FIREBASE_AUTH_TOKEN=your-token-here   # optional, see below
```

The ESP32 firmware also stores a copy of the Firebase URL in its Preferences
(accessible via the WiFiManager setup portal on boot). You can leave the
ESP32's Firebase URL field empty — PHP is the sole Firebase writer regardless.

## Optional auth token

The PHP backend sends an auth token with the REST write request.
This is optional for development if your database rules allow unauthenticated writes.

In [public_html/includes/config.php](../public_html/includes/config.php) or `.env`:

```env
FIREBASE_AUTH_TOKEN=your-token-here
```

If you leave it blank, the backend writes without `auth=` in the request URL.

## Security rules

Because only the PHP backend writes to Firebase, you can use Firebase Auth
to restrict writes to authenticated requests only.

Example production rules:

```json
{
  "rules": {
    ".read": false,
    ".write": false,
    "latest_measurements": {
      ".read": true
    },
    "device_status": {
      ".read": true
    },
    "live_readings": {
      ".read": true
    }
  }
}
```

Writes are server-side only (PHP secret/service-account bypasses rules),
so `.write: false` breaks nothing. Reads stay scoped-public per mirror
path because the kiosk tablet is a shared unauthenticated device.

## Where data is written

PHP writes the latest successful kiosk measurement to:

`/latest_measurements/{device_id}.json`

The kiosk page reads that record and displays the live result.
The ESP32 does NOT write this path directly.

PHP also mirrors the device's connectivity flag to:

`/device_status/{device_id}.json`

This is written from two PHP endpoints:
- `get_command.php` writes `online: true` every time the ESP32's 2-second
  heartbeat check-in reaches the server.
- `device_ping.php` writes `online: false` the moment MySQL notices the
  heartbeat has gone stale (no check-in for
  `DEVICE_ONLINE_THRESHOLD_SECONDS`, currently 6 seconds — see
  `public_html/includes/api_helpers.php`).

While MEASURING, the ESP32 piggybacks its live sensor snapshot on the
same heartbeat (`live_session_id` / `live_weight` / `live_height` /
`live_*_stable` / `final_ready` / `final_sequence` / `final_weight_kg` /
`final_height_cm` query params — see `getMeasurementCommand()` in the
firmware). PHP validates and mirrors each tick to:

`/live_readings/{device_id}.json`

The HTTPS kiosk streams that node via `EventSource` into the same state
machine the direct WebSocket used — including `final_ready`, which is
what enables the PROCESS button. The ESP32 itself never PUTs to
Firebase (`FIREBASE_URL` is intentionally blank in firmware).

MySQL (`devices.status` / `devices.last_seen_at`) stays the source of
truth; the Firebase node is a live copy for anything that wants to watch
connectivity without querying MySQL directly.

## ESP32 Firebase URL (Preferences)

The ESP32 stores a Firebase URL in its Preferences flash storage.
This is configurable via the WiFiManager setup portal (hold BOOT at boot).

**You can leave this field empty.** With the ESP32 writing no Firebase
data at all, the PHP backend handles everything. The field exists so
a future firmware version can opt back into direct ESP32→Firebase writes
with proper auth if needed.

## What to verify

1. The ESP32 posts one completed measurement to the PHP backend.
2. The PHP backend stores the measurement in MySQL.
3. The backend mirrors the same payload to Firebase.
4. The kiosk UI polls Firebase and shows the live result.
5. Power off the ESP32. Within roughly `DEVICE_ONLINE_THRESHOLD_SECONDS`
   (about 6 seconds with the current default), the kiosk's "Device"
   chip should flip to Offline, `devices.status` in MySQL should read
   `offline`, and `/device_status/ESP32-KIOSK-01.json` in Firebase should
   show `online: false`.
