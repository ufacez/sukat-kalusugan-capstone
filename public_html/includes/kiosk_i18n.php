<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| KIOSK i18n
|--------------------------------------------------------------------------
|
| The kiosk is intentionally single-language per page load: the operator
| picks Tagalog or English on the welcome screen, PHP renders every static
| string in that language, and the same dictionary is handed to
| kiosk.js (window.KIOSK_DATA.i18n) so dynamic strings match. Switching
| language reloads the page (safe: welcome is always an idle screen).
|
| Translations live in includes/kiosk_lang/<lang>.php and must share the
| exact same key set so a missing key in one language falls back to the
| other. Keep HTML-bearing values only for keys the template echoes raw.
|
*/

const KIOSK_DEFAULT_LANG = 'tl';
const KIOSK_SUPPORTED_LANGS = ['tl', 'en'];
const KIOSK_LANG_COOKIE = 'kiosk_lang';

/**
 * Resolve the active kiosk language.
 *
 * Priority: ?lang= param (from the toggle link) -> persisted cookie ->
 * default. Falls back to the default for anything unsupported so a
 * tampered query string can never produce a blank UI.
 */
function kiosk_lang(): string
{
    static $lang = null;

    if ($lang !== null) {
        return $lang;
    }

    $requested = strtolower(trim((string)($_GET['lang'] ?? '')));

    if (!in_array($requested, KIOSK_SUPPORTED_LANGS, true)) {
        $requested = strtolower(trim((string)($_COOKIE[KIOSK_LANG_COOKIE] ?? '')));
    }

    if (!in_array($requested, KIOSK_SUPPORTED_LANGS, true)) {
        $requested = KIOSK_DEFAULT_LANG;
    }

    $lang = $requested;

    return $lang;
}

/**
 * Persist the language chosen via the toggle link. Safe to call on every
 * request: only writes when ?lang= is present and valid, and uses the
 * root path so the kiosk URL (with or without ?device=) keeps the choice.
 */
function kiosk_persist_lang(): void
{
    if (!isset($_GET['lang'])) {
        return;
    }

    $requested = strtolower(trim((string)$_GET['lang']));

    if (!in_array($requested, KIOSK_SUPPORTED_LANGS, true)) {
        return;
    }

    setcookie(KIOSK_LANG_COOKIE, $requested, [
        'expires' => time() + 31536000,
        'path' => '/',
        'samesite' => 'Lax',
    ]);
}

/**
 * Full key => string dictionary for a language, memoised per request.
 * Missing keys are backfilled from the default language so the UI never
 * shows a raw key when one language lags the other.
 *
 * @return array<string,string>
 */
function kiosk_translations(string $lang = ''): array
{
    static $cache = [];

    $lang = in_array($lang, KIOSK_SUPPORTED_LANGS, true) ? $lang : kiosk_lang();

    if (isset($cache[$lang])) {
        return $cache[$lang];
    }

    $default = kiosk_load_lang_file(KIOSK_DEFAULT_LANG);
    $selected = ($lang === KIOSK_DEFAULT_LANG) ? $default : kiosk_load_lang_file($lang);

    // Default first so a missing key in the selected language still resolves.
    $cache[$lang] = array_merge($default, $selected);

    return $cache[$lang];
}

/**
 * @return array<string,string>
 */
function kiosk_load_lang_file(string $lang): array
{
    $path = __DIR__ . '/kiosk_lang/' . $lang . '.php';

    if (!is_file($path)) {
        return [];
    }

    $loaded = require $path;

    return is_array($loaded) ? $loaded : [];
}

/**
 * Translate a key, interpolating {placeholders}. Used by kiosk_index.php
 * for static markup. Values containing HTML are echoed raw by the caller;
 * attribute values get kiosk_e()/htmlspecialchars() as usual.
 *
 * @param array<string,string|int|float> $vars
 */
function kiosk_t(string $key, array $vars = []): string
{
    $text = kiosk_translations()[ $key ] ?? $key;

    if ($vars !== []) {
        foreach ($vars as $name => $value) {
            $text = str_replace('{' . $name . '}', (string)$value, $text);
        }
    }

    return $text;
}
