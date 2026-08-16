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

## What to verify

1. The ESP32 posts one completed measurement to the PHP backend.
2. The PHP backend stores the measurement in MySQL.
3. The backend mirrors the same payload to Firebase.
4. The kiosk UI polls Firebase and shows the live result.
