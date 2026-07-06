<?php
/**
 * kiosk/index.php
 * The public unauthenticated kiosk wizard page (tablet-facing).
 * JS listens to Firebase RTDB for live measurement updates from the ESP32.
 * Steps: welcome -> select child -> waiting for height -> waiting for weight
 *        -> processing -> results.
 */
