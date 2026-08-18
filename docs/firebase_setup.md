# Firebase Setup for Live Kiosk Mirror

This project uses Firebase Realtime Database as a live mirror for the kiosk result after MySQL saves the measurement.
MySQL remains the source of truth.

## What to create in Firebase

1. Create a Firebase project.
2. Enable **Realtime Database**.
3. Copy the database URL, for example:
   `https://your-project-default-rtdb.firebaseio.com`
4. Put that URL into [public_html/includes/config.php](../public_html/includes/config.php):

```php
define('FIREBASE_DATABASE_URL', 'https://your-project-default-rtdb.firebaseio.com');
```

## Optional auth token

The PHP backend can also send an auth token with the REST write request.
This is optional for development if your database rules are in test mode.

In [public_html/includes/config.php](../public_html/includes/config.php):

```php
define('FIREBASE_AUTH_TOKEN', 'your-token-here');
```

If you leave it blank, the backend will write without `auth=` in the request URL.

## Suggested development rules

For quick testing, you can temporarily use permissive Realtime Database rules.
Do not keep open rules in production.

Example test rules:

```json
{
  "rules": {
    ".read": true,
    ".write": true
  }
}
```

## Where live data is written

The server writes the latest successful kiosk measurement to:

`/latest_measurements/ESP32-KIOSK-01.json`

The kiosk page then reads that record and displays the live result.

The server also mirrors the device's connectivity flag to:

`/device_status/ESP32-KIOSK-01.json`

This is written from two places:
- `get_command.php` writes `online: true` every time the ESP32's 2‑second
  heartbeat check-in reaches the server.
- `device_ping.php` writes `online: false` the moment MySQL notices the
  heartbeat has gone stale (no check-in for
  `DEVICE_ONLINE_THRESHOLD_SECONDS`, currently 6 seconds — see
  `public_html/includes/api_helpers.php`).

MySQL (`devices.status` / `devices.last_seen_at`) stays the source of
truth; this Firebase node is a live copy for anything that wants to watch
connectivity without querying MySQL directly.

## What to verify

1. The ESP32 posts one completed measurement to the PHP backend.
2. The PHP backend stores the measurement in MySQL.
3. The backend mirrors the same payload to Firebase.
4. The kiosk UI polls Firebase and shows the live result.
5. Power off the ESP32. Within roughly `syncSeconds + DEVICE_ONLINE_THRESHOLD_SECONDS`
   (about 3–9 seconds with the current defaults), the kiosk's "Device"
   chip should flip to Offline, `devices.status` in MySQL should read
   `offline`, and `/device_status/ESP32-KIOSK-01.json` in Firebase should
   show `online: false`.

## A note on "instant"

There is no way to get a truly instantaneous (0-second) offline signal
out of HTTP polling — cutting power doesn't send any "goodbye" message,
so the server can only infer the device is gone once it has stayed
silent longer than the timeout above. What this setup gives you is
*near-real-time* detection (a few seconds), which is what the numbers
above are tuned for. Genuinely instant push notification of a power-loss
event is only possible with a persistent connection and a library that
supports Firebase's `onDisconnect()` (or an MQTT broker with a Last Will
and Testament) — the ESP32 firmware here uses plain `HTTPClient` REST
calls, not a persistent Firebase SDK connection, so `onDisconnect()`
isn't available without a firmware/library change. That's a reasonable
future upgrade but is out of scope for this fix.
