<?php
/**
 * who_calculator.php
 * Procedural WHO LMS z-score calculations, reading from the
 * who_weight_for_age / who_height_for_age / who_weight_for_height tables.
 *
 * Functions to implement:
 *   calculate_waz(float $weight_kg, int $age_months, string $sex): float
 *   calculate_haz(float $height_cm, int $age_months, string $sex): float
 *   calculate_whz(float $weight_kg, float $height_cm, string $sex): float
 *   classify_nutritional_status(float $waz, float $haz, float $whz): string
 */
