<?php
/**
 * firebase_sync.php
 * Pushes "latest reading" events to Firebase Realtime Database via REST API
 * after a measurement is saved to MySQL. MySQL stays authoritative; this is
 * a notification channel only.
 *
 * Functions to implement:
 *   push_latest_measurement(string $device_id, array $measurement_data): bool
 */
