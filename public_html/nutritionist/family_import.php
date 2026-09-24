<?php

declare(strict_types=1);

/**
 * nutritionist/family_import.php
 *
 * Master-list (.xlsx OPT Plus) bulk import wizard for nutritionists.
 *
 * 3 steps, designed to be usable without training:
 *   1. Upload  — pick barangay (locked to scope) + .xlsx file.
 *   2. Preview — every row mapped to First/Middle/Last with a verdict
 *                (Ready / Already registered / Needs completion / Error).
 *   3. Done    — counts, staged rows, and the announced parent-login
 *                pattern (auto email + shared default password).
 *
 * Only 4 sheet columns are read: mother name, child name, sex, birthdate
 * (header-name mapped, so column order does not matter). Everything else
 * in the sheet (seq, purok, IP, measurements) is ignored on purpose.
 */

require_once __DIR__ . '/../includes/nutritionist_helpers.php';
require_once __DIR__ . '/../includes/who_calculator.php';
require_once __DIR__ . '/../includes/xlsx_lite.php';

const ML_IMPORT_DEFAULT_PASSWORD = 'PalitanMo@123';
const ML_IMPORT_MAX_BYTES = 5 * 1024 * 1024; // 5 MB
const ML_IMPORT_MAX_ROWS = 2000;
const ML_IMPORT_SESSION_KEY = 'ml_import_preview';
const ML_IMPORT_RESULT_KEY = 'ml_import_result';

/* ------------------------------------------------------------------
 * Local helpers (ml_ prefix — single-purpose page functions)
 * ------------------------------------------------------------------ */

function ml_import_slug(string $value): string
{
    $value = strtolower(trim($value));
    $value = (string)preg_replace('/[^a-z0-9]+/', '', $value);

    return $value;
}

/**
 * Auto email: firstname.lastname@sukat.kalusugan, numbered when taken.
 * $used tracks addresses minted earlier in the SAME batch so two mothers
 * with the same name never collide with each other.
 */
function ml_import_make_parent_email(string $firstName, string $lastName, array &$used): string
{
    $first = ml_import_slug($firstName);
    $last = ml_import_slug($lastName);

    if ($first === '') {
        $first = 'parent';
    }
    if ($last === '') {
        $last = 'sukat';
    }

    $base = $first . '.' . $last;
    $email = $base . '@sukat.kalusugan';
    $counter = 1;

    while (isset($used[$email]) || admin_email_in_use($email)) {
        if ($counter >= 200) {
            break;
        }
        $counter++;
        $email = $base . $counter . '@sukat.kalusugan';
    }

    $used[$email] = true;

    return $email;
}

function ml_import_next_child_code(): string
{
    $row = admin_fetch_one('SELECT child_code FROM children ORDER BY id DESC LIMIT 1');
    $lastCode = (string)($row['child_code'] ?? 'CHD-0000');

    if (preg_match('/(\d+)$/', $lastCode, $matches) !== 1) {
        return 'CHD-0001';
    }

    return 'CHD-' . str_pad((string)(((int)$matches[1]) + 1), 4, '0', STR_PAD_LEFT);
}

/** M / Male / F / Female (any case) -> Male|Female, else null. */
function ml_import_normalize_sex(string $raw): ?string
{
    $v = strtoupper(trim($raw));

    if ($v === 'M' || $v === 'MALE') {
        return 'Male';
    }
    if ($v === 'F' || $v === 'FEMALE') {
        return 'Female';
    }

    return null;
}

/**
 * OPT Plus birthdates arrive either as Excel 1900-system serials
 * ("44527", "44527.0") or as typed date strings. Returns
 * ['date' => ?Y-m-d, 'status' => ok|missing|invalid, 'note' => ''].
 */
function ml_import_normalize_dob(string $raw): array
{
    $v = trim($raw);

    if ($v === '') {
        return ['date' => null, 'status' => 'missing', 'note' => 'Walang birthdate.'];
    }

    $today = new DateTimeImmutable('today');

    if (is_numeric($v)) {
        $serial = (int)floor((float)$v);
        if ($serial < 25000 || $serial > 80000) {
            return ['date' => null, 'status' => 'invalid', 'note' => 'Hindi malinaw ang birthdate (' . $v . ').'];
        }
        $date = (new DateTimeImmutable('1899-12-30'))->modify('+' . $serial . ' days');
        if ($date > $today) {
            return ['date' => null, 'status' => 'invalid', 'note' => 'Birthdate ay nasa hinaharap.'];
        }

        return ['date' => $date->format('Y-m-d'), 'status' => 'ok', 'note' => ''];
    }

    foreach (['Y-m-d', 'm/d/Y', 'd-m-Y', 'm-d-Y', 'Y/m/d', 'd/m/Y'] as $fmt) {
        $parsed = DateTimeImmutable::createFromFormat($fmt, $v);
        if ($parsed instanceof DateTimeImmutable && $parsed->format($fmt) === $v) {
            $date = $parsed->setTime(0, 0);
            if ($date > $today) {
                return ['date' => null, 'status' => 'invalid', 'note' => 'Birthdate ay nasa hinaharap.'];
            }

            return ['date' => $date->format('Y-m-d'), 'status' => 'ok', 'note' => ''];
        }
    }

    return ['date' => null, 'status' => 'invalid', 'note' => 'Hindi mabasa ang birthdate (' . $v . ').'];
}

/**
 * Finds the header row (mother + child names in one row) and maps the
 * four columns by header name. Falls back to classic OPT Plus positions
 * (C, D, F, G). Returns null when the sheet is not recognizable.
 */
function ml_import_map_columns(array $sheet): ?array
{
    $limit = min(count($sheet), 12);

    for ($h = 0; $h < $limit; $h++) {
        $cells = array_map(static fn($c): string => strtolower(trim((string)$c)), $sheet[$h]);
        $mother = $child = $sex = $dob = $addr = null;

        foreach ($cells as $idx => $text) {
            if ($text === '') {
                continue;
            }
            // Both name columns contain "name" — that excludes lookalikes
            // like "Child Seq." from claiming the child column.
            if ($mother === null && strpos($text, 'name') !== false && preg_match('/mother|caregiver|guardian/', $text) && strpos($text, 'child') === false) {
                $mother = $idx;
            } elseif ($child === null && strpos($text, 'name') !== false && strpos($text, 'child') !== false && strpos($text, 'seq') === false) {
                $child = $idx;
            } elseif ($sex === null && preg_match('/\bsex\b/', $text)) {
                $sex = $idx;
            } elseif ($dob === null && preg_match('/birth|dob|\bborn\b/', $text)) {
                $dob = $idx;
            } elseif ($addr === null && preg_match('/address|purok|location/', $text)) {
                $addr = $idx;
            }
        }

        if ($mother !== null && $child !== null) {
            return [
                'header' => $h,
                'mother' => $mother,
                'child' => $child,
                'sex' => $sex ?? 5,
                'dob' => $dob ?? 6,
                'address' => $addr ?? 1,
            ];
        }
    }

    return null;
}

/**
 * Barangay guard: the sheet's address/purok column is never stored, but
 * it must not point at a DIFFERENT barangay than the import target.
 * Longest-name match wins so "Bulaon Sur" never passes as "Bulaon".
 *
 * Returns ['status' => ok|unknown|error, 'match' => barangay name or ''].
 * unknown = no recognizable barangay in the cell (blank or purok-only)
 *           — allowed, since the target barangay is explicit.
 */
function ml_import_guard_barangay(string $address, string $targetName, array $names): array
{
    $address = trim($address);

    if ($address === '') {
        return ['status' => 'unknown', 'match' => ''];
    }

    $best = '';
    $bestLen = 0;
    foreach ($names as $name) {
        $name = trim((string)$name);
        if ($name === '') {
            continue;
        }
        $pattern = '/(?<!\pL)' . preg_quote($name, '/') . '(?!\pL)/iu';
        if (preg_match($pattern, $address) && mb_strlen($name) > $bestLen) {
            $best = $name;
            $bestLen = mb_strlen($name);
        }
    }

    if ($best === '') {
        return ['status' => 'unknown', 'match' => ''];
    }

    if (strcasecmp($best, $targetName) === 0) {
        return ['status' => 'ok', 'match' => $best];
    }

    return ['status' => 'error', 'match' => $best];
}

/** True for title rows, hint rows ("(Surname, First Name)"), and blanks. */
function ml_import_is_skippable_row(string $motherRaw, string $childRaw, string $sexRaw): bool
{
    if ($motherRaw === '' && $childRaw === '') {
        return true;
    }
    if (stripos($motherRaw, 'surname') !== false || stripos($childRaw, 'surname') !== false) {
        return true;
    }
    $sexHint = strtoupper(trim($sexRaw));
    if ($sexHint === 'M/F' || $sexHint === 'SEX') {
        return true;
    }

    return false;
}

/**
 * Parses the whole sheet into per-row verdicts:
 * ok | duplicate | staged | error. No database writes here.
 */
function ml_import_parse_sheet(array $sheet, array $map, string $targetBarangay, array $barangayNames): array
{
    $rows = [];

    for ($i = $map['header'] + 1; $i < count($sheet); $i++) {
        $rowNum = $i + 1; // 1-based Excel row number
        $cells = $sheet[$i];
        $motherRaw = trim((string)($cells[$map['mother']] ?? ''));
        $childRaw = trim((string)($cells[$map['child']] ?? ''));
        $sexRaw = trim((string)($cells[$map['sex']] ?? ''));
        $dobRaw = trim((string)($cells[$map['dob']] ?? ''));
        $addrRaw = trim((string)($cells[$map['address']] ?? ''));

        if (ml_import_is_skippable_row($motherRaw, $childRaw, $sexRaw)) {
            continue;
        }

        $entry = [
            'row' => $rowNum,
            'mother_raw' => $motherRaw,
            'child_raw' => $childRaw,
            'sex_raw' => $sexRaw,
            'dob_raw' => $dobRaw,
            'verdict' => 'error',
            'note' => '',
            'hint' => '',
        ];

        // Wrong-barangay rows never enter the import, even when the
        // names are perfect. Unverifiable cells (blank/purok-only) pass.
        $guard = ml_import_guard_barangay($addrRaw, $targetBarangay, $barangayNames);
        if ($guard['status'] === 'error') {
            $entry['note'] = 'Ibang barangay (' . $guard['match'] . ') — hindi isinama.';
            $rows[] = $entry;
            continue;
        }

        $mother = admin_split_surname_first($motherRaw);
        $child = admin_split_surname_first($childRaw);

        if ($childRaw === '' || $child['flag'] === 'empty') {
            $entry['note'] = 'Blanko ang pangalan ng bata.';
            $rows[] = $entry;
            continue;
        }
        if ($child['flag'] === 'invalid') {
            $entry['note'] = 'Hindi mabasa ang pangalan ng bata: ' . $child['note'];
            $rows[] = $entry;
            continue;
        }
        if ($motherRaw === '' || $mother['flag'] === 'empty') {
            $entry['note'] = 'Blanko ang pangalan ng nanay/guardian.';
            $rows[] = $entry;
            continue;
        }
        if ($mother['flag'] === 'invalid') {
            $entry['note'] = 'Hindi mabasa ang pangalan ng nanay: ' . $mother['note'];
            $rows[] = $entry;
            continue;
        }

        $entry['mother'] = $mother;
        $entry['child'] = $child;

        $hints = [];
        if ($mother['flag'] === 'sanitized' || $mother['flag'] === 'no_comma') {
            $hints[] = 'Nanay: ' . $mother['note'];
        }
        if ($child['flag'] === 'sanitized' || $child['flag'] === 'no_comma') {
            $hints[] = 'Bata: ' . $child['note'];
        }
        $entry['hint'] = implode(' ', $hints);

        $sex = $sexRaw === '' ? null : ml_import_normalize_sex($sexRaw);
        $dob = ml_import_normalize_dob($dobRaw);
        $entry['sex'] = $sex;
        $entry['dob'] = $dob['date'];

        if ($dob['status'] !== 'ok' || $sex === null) {
            $reasons = [];
            if ($dob['status'] !== 'ok') {
                $reasons[] = $dob['note'];
            }
            if ($sex === null) {
                $reasons[] = $sexRaw === '' ? 'Walang sex.' : 'Hindi malinaw ang sex (' . $sexRaw . ').';
            }
            $entry['verdict'] = 'staged';
            $entry['note'] = implode(' ', $reasons) . ' Ikukumpleto mamaya.';
            $rows[] = $entry;
            continue;
        }

        $dup = child_duplicate_identity($child['first'], $child['last'], (string)$dob['date']);
        if ($dup !== null) {
            $entry['verdict'] = 'duplicate';
            $entry['note'] = 'Registered na (' . (string)($dup['child_code'] ?? '') . ') — nilaktawan.';
            $rows[] = $entry;
            continue;
        }

        $entry['verdict'] = 'ok';
        $rows[] = $entry;
    }

    return $rows;
}

/* ------------------------------------------------------------------
 * Guards + routing
 * ------------------------------------------------------------------ */

$user = nutritionist_require_access();
$isAdmin = ($user['role'] ?? '') === 'admin';

if (!nutritionist_can_write('parents.create') || !nutritionist_can_write('children.create')) {
    admin_redirect('/nutritionist/children.php', ['notice' => 'You do not have permission to bulk import families.', 'type' => 'error']);
}

if (function_exists('set_time_limit')) {
    @set_time_limit(300);
}

/** Resolve the import barangay: locked to scope for non-admins. */
function ml_import_resolve_barangay(array $user, bool $isAdmin): ?int
{
    if (!$isAdmin) {
        $scoped = (int)($user['barangay_id'] ?? 0);
        return $scoped > 0 ? $scoped : null;
    }

    $picked = (int)($_POST['barangay_id'] ?? $_GET['barangay_id'] ?? 0);

    return $picked > 0 ? $picked : null;
}

/* Template download: same column layout as the OPT Plus master list. */
if (($_GET['action'] ?? '') === 'template') {
    $header = [
        'Child Seq. (ignored)',
        'Address / Purok (barangay check only)',
        'Name of Mother or Caregiver (Surname, First Name)',
        "Child's Full Name (Surname, First Name)",
        'Belongs to IP Group? (ignored)',
        'Sex (M/F)',
        'Date of Birth (YYYY-MM-DD)',
    ];
    $examples = [
        ['', 'Purok 2 Dela Paz Norte', 'Arconado, Isagani', 'Arconado, Jonalky', '', 'M', '2022-05-14'],
        ['', 'Purok 5 Dela Paz Norte', 'Dela Cruz, Maria', 'Dela Cruz, Juan Santos', '', 'F', '2023-01-30'],
    ];

    $tmp = (string)sys_get_temp_dir() . '/ml_template_' . (string)getmypid() . '.xlsx';
    if (!xlsx_lite_write($tmp, $header, $examples, 'Master List')) {
        admin_redirect('/nutritionist/family_import.php', ['notice' => 'Template could not be generated. Try again.', 'type' => 'error']);
    }

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="master_list_template.xlsx"');
    header('Content-Length: ' . (string)filesize($tmp));
    readfile($tmp);
    @unlink($tmp);
    exit;
}

/* Step 2 — preview the uploaded workbook (no writes). */
$preview = null;
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && (($_POST['action'] ?? '') === 'preview')) {
    nutritionist_require_write('parents.create');
    nutritionist_require_write('children.create');

    $barangayId = ml_import_resolve_barangay($user, $isAdmin);
    if ($barangayId === null) {
        admin_redirect('/nutritionist/family_import.php', ['notice' => $isAdmin ? 'Pumili ng barangay.' : 'Your account is not assigned to a barangay. Contact your administrator.', 'type' => 'error']);
    }

    $barangay = admin_fetch_one('SELECT id, name FROM barangays WHERE id = ? LIMIT 1', 'i', [$barangayId]);
    if ($barangay === null) {
        admin_redirect('/nutritionist/family_import.php', ['notice' => 'Selected barangay could not be found.', 'type' => 'error']);
    }

    if (!isset($_FILES['xlsx_file']) || $_FILES['xlsx_file']['error'] !== UPLOAD_ERR_OK) {
        admin_redirect('/nutritionist/family_import.php', ['notice' => 'Pumili ng .xlsx file mula sa inyong master list.', 'type' => 'error']);
    }

    $upload = $_FILES['xlsx_file'];
    if ((int)$upload['size'] > ML_IMPORT_MAX_BYTES) {
        admin_redirect('/nutritionist/family_import.php', ['notice' => 'File is too large (max 5 MB).', 'type' => 'error']);
    }
    if (strtolower((string)pathinfo((string)$upload['name'], PATHINFO_EXTENSION)) !== 'xlsx') {
        admin_redirect('/nutritionist/family_import.php', ['notice' => 'Only .xlsx files are accepted.', 'type' => 'error']);
    }

    try {
        $sheet = xlsx_lite_read_first_sheet((string)$upload['tmp_name']);
    } catch (Throwable $e) {
        error_log('[SukatKalusugan] family_import.php read failed: ' . $e->getMessage());
        admin_redirect('/nutritionist/family_import.php', ['notice' => 'Hindi mabasa ang file bilang Excel workbook. Siguraduhing .xlsx ito.', 'type' => 'error']);
    }

    if (count($sheet) > ML_IMPORT_MAX_ROWS + 20) {
        admin_redirect('/nutritionist/family_import.php', ['notice' => 'Too many rows (max ' . ML_IMPORT_MAX_ROWS . '). Split the file per purok and import in batches.', 'type' => 'error']);
    }

    $map = ml_import_map_columns($sheet);
    if ($map === null) {
        admin_redirect('/nutritionist/family_import.php', ['notice' => 'Hindi mahanap ang header (kailangan ang mother/caregiver at child name columns).', 'type' => 'error']);
    }

    $barangayNames = array_column(
        admin_fetch_all('SELECT name FROM barangays ORDER BY CHAR_LENGTH(name) DESC'),
        'name'
    );
    $rows = ml_import_parse_sheet($sheet, $map, (string)($barangay['name'] ?? ''), $barangayNames);
    if ($rows === []) {
        admin_redirect('/nutritionist/family_import.php', ['notice' => 'Walang mababasang rows sa file na ito.', 'type' => 'error']);
    }

    $_SESSION[ML_IMPORT_SESSION_KEY] = [
        'barangay_id' => $barangayId,
        'barangay_name' => (string)($barangay['name'] ?? ''),
        'filename' => (string)$upload['name'],
        'rows' => $rows,
    ];

    $preview = $_SESSION[ML_IMPORT_SESSION_KEY];
}

/* Step 3 — commit the previewed rows. */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && (($_POST['action'] ?? '') === 'commit')) {
    nutritionist_require_write('parents.create');
    nutritionist_require_write('children.create');

    $payload = $_SESSION[ML_IMPORT_SESSION_KEY] ?? null;
    unset($_SESSION[ML_IMPORT_SESSION_KEY]);

    if (!is_array($payload) || ($payload['rows'] ?? []) === []) {
        admin_redirect('/nutritionist/family_import.php', ['notice' => 'Preview expired. Upload the file again.', 'type' => 'error']);
    }

    $barangayId = (int)($payload['barangay_id'] ?? 0);
    if (!$isAdmin) {
        $scoped = (int)($user['barangay_id'] ?? 0);
        if ($scoped <= 0 || $scoped !== $barangayId) {
            admin_redirect('/nutritionist/family_import.php', ['notice' => 'You can only import into your assigned barangay.', 'type' => 'error']);
        }
    }
    if ($barangayId <= 0) {
        admin_redirect('/nutritionist/family_import.php', ['notice' => 'Preview expired. Upload the file again.', 'type' => 'error']);
    }

    $conn = get_db_connection();
    $hash = password_hash(ML_IMPORT_DEFAULT_PASSWORD, PASSWORD_DEFAULT);
    $usedEmails = [];

    $batchId = 0;
    $insBatch = mysqli_prepare(
        $conn,
        'INSERT INTO import_batches (barangay_id, source_filename, uploaded_by, total_rows) VALUES (?, ?, ?, ?)'
    );
    if ($insBatch !== false) {
        $uploaderId = (int)($user['id'] ?? 0);
        $fileName = (string)($payload['filename'] ?? '');
        $totalRows = count($payload['rows']);
        mysqli_stmt_bind_param($insBatch, 'isii', $barangayId, $fileName, $uploaderId, $totalRows);
        if (mysqli_stmt_execute($insBatch)) {
            $batchId = (int)mysqli_insert_id($conn);
        }
        mysqli_stmt_close($insBatch);
    }
    if ($batchId <= 0) {
        admin_redirect('/nutritionist/family_import.php', ['notice' => 'Import could not start (batch log failed). No records were saved.', 'type' => 'error']);
    }

    /* Group OK rows by mother so siblings share one parent account. */
    $families = [];
    $staged = [];
    $skipped = [];
    $errorRows = [];
    foreach ($payload['rows'] as $row) {
        $verdict = (string)($row['verdict'] ?? 'error');
        if ($verdict === 'ok') {
            $key = strtolower((string)$row['mother']['last'] . '|' . (string)$row['mother']['first']);
            $families[$key][] = $row;
        } elseif ($verdict === 'staged') {
            $staged[] = $row;
        } elseif ($verdict === 'duplicate') {
            $skipped[] = $row;
        } else {
            $errorRows[] = $row;
        }
    }

    $parentCache = []; // mother key -> parent id (this batch)
    $importedParents = 0;
    $importedChildren = 0;
    $graduatedChildren = 0;
    $commitErrors = [];

    foreach ($families as $motherKey => $kids) {
        $firstKid = $kids[0];
        $mFirst = (string)$firstKid['mother']['first'];
        $mMiddle = (string)$firstKid['mother']['middle'];
        $mLast = (string)$firstKid['mother']['last'];
        $mName = admin_combine_name($mFirst, $mMiddle, $mLast);

        /* Reuse a live parent with the same name — never double-mint. */
        $existing = admin_fetch_one(
            "SELECT id FROM parents WHERE barangay_id = ? AND LOWER(name) = LOWER(?) AND status = 'active' LIMIT 1",
            'is',
            [$barangayId, $mName]
        );
        $parentId = $existing !== null ? (int)$existing['id'] : 0;

        mysqli_begin_transaction($conn);
        try {
            if ($parentId <= 0) {
                $email = ml_import_make_parent_email($mFirst, $mLast, $usedEmails);
                $pType = 'Guardian';
                $pStatus = 'active';
                $one = 1;
                $insParent = mysqli_prepare(
                    $conn,
                    "INSERT INTO parents (name, email, password_hash, parent_type, phone, address, barangay_id, local_area_id, household_id, status, needs_completion, must_change_password)
                     VALUES (?, ?, ?, ?, NULL, NULL, ?, NULL, NULL, ?, ?, ?)"
                );
                if ($insParent === false) {
                    throw new RuntimeException('Hindi ma-save ang parent (' . $mName . ').');
                }
                mysqli_stmt_bind_param($insParent, 'ssssisii', $mName, $email, $hash, $pType, $barangayId, $pStatus, $one, $one);
                if (!mysqli_stmt_execute($insParent)) {
                    mysqli_stmt_close($insParent);
                    throw new RuntimeException('Hindi ma-save ang parent (' . $mName . ').');
                }
                mysqli_stmt_close($insParent);
                $parentId = (int)mysqli_insert_id($conn);
                if ($parentId <= 0) {
                    throw new RuntimeException('Hindi ma-save ang parent (' . $mName . ').');
                }
                $importedParents++;
            }
            $parentCache[$motherKey] = $parentId;

            foreach ($kids as $kid) {
                $cFirst = (string)$kid['child']['first'];
                $cMiddle = (string)$kid['child']['middle'] !== '' ? (string)$kid['child']['middle'] : null;
                $cLast = (string)$kid['child']['last'];
                $cBirth = (string)$kid['dob'];
                $cSex = (string)$kid['sex'];

                /* Re-check live: preview may be stale if staff worked meanwhile. */
                if (child_duplicate_identity($cFirst, $cLast, $cBirth) !== null) {
                    $skipped[] = $kid + ['note' => 'Registered na habang nag-iimport — nilaktawan.'];
                    continue;
                }

                $childCode = ml_import_next_child_code();
                $zero = 0;
                $insChild = mysqli_prepare(
                    $conn,
                    'INSERT INTO children (child_code, first_name, middle_name, last_name, birthdate, sex, barangay_id, local_area_id, is_ip, has_disability, parent_id, household_id)
                     VALUES (?, ?, ?, ?, ?, ?, ?, NULL, ?, ?, ?, NULL)'
                );
                if ($insChild === false) {
                    throw new RuntimeException('Hindi ma-save ang bata (' . $cFirst . ' ' . $cLast . ').');
                }
                mysqli_stmt_bind_param($insChild, 'ssssssiiii', $childCode, $cFirst, $cMiddle, $cLast, $cBirth, $cSex, $barangayId, $zero, $zero, $parentId);
                if (!mysqli_stmt_execute($insChild)) {
                    mysqli_stmt_close($insChild);
                    throw new RuntimeException('Hindi ma-save ang bata (' . $cFirst . ' ' . $cLast . ').');
                }
                mysqli_stmt_close($insChild);
                $importedChildren++;

                /* Same rule as the Graduated tab (months >= 60). */
                $age = doh_age($cBirth);
                if (is_array($age) && (int)($age['months'] ?? 0) >= 60) {
                    $graduatedChildren++;
                }
            }

            mysqli_commit($conn);
        } catch (Throwable $e) {
            mysqli_rollback($conn);
            error_log('[SukatKalusugan] family_import.php commit: ' . $e->getMessage());
            $commitErrors[] = 'Row ' . (int)($firstKid['row'] ?? 0) . ' (' . $mName . '): ' . $e->getMessage();
        }
    }

    /* Staged rows wait in the staging table for staff completion. */
    $stagedCount = 0;
    foreach ($staged as $row) {
        $ok = admin_execute(
            'INSERT INTO import_staging_rows (batch_id, row_num, mother_raw, child_raw, sex_raw, dob_raw,
                mother_first, mother_middle, mother_last, child_first, child_middle, child_last,
                sex_norm, dob_norm, reason, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            'iissssssssssssss',
            [
                $batchId,
                (int)($row['row'] ?? 0),
                (string)($row['mother_raw'] ?? ''),
                (string)($row['child_raw'] ?? ''),
                (string)($row['sex_raw'] ?? ''),
                (string)($row['dob_raw'] ?? ''),
                (string)($row['mother']['first'] ?? ''),
                (string)($row['mother']['middle'] ?? ''),
                (string)($row['mother']['last'] ?? ''),
                (string)($row['child']['first'] ?? ''),
                (string)($row['child']['middle'] ?? ''),
                (string)($row['child']['last'] ?? ''),
                $row['sex'] !== null ? (string)$row['sex'] : null,
                $row['dob'] !== null ? (string)$row['dob'] : null,
                mb_substr((string)($row['note'] ?? ''), 0, 250),
                'pending',
            ]
        );
        if ($ok) {
            $stagedCount++;
        }
    }

    admin_execute(
        'UPDATE import_batches SET imported_parents = ?, imported_children = ?, graduated_children = ?, skipped_rows = ?, staged_rows = ? WHERE id = ?',
        'iiiiii',
        [$importedParents, $importedChildren, $graduatedChildren, count($skipped) + count($errorRows) + count($commitErrors), $stagedCount, $batchId]
    );

    $actor = current_user();
    log_action($actor['id'] ?? null, 'BULK_IMPORT_FAMILY', 'info', 'Master-list import batch #' . $batchId . ': ' . $importedParents . ' parent(s), ' . $importedChildren . ' child(ren) into barangay #' . $barangayId);

    $_SESSION[ML_IMPORT_RESULT_KEY] = [
        'batch_id' => $batchId,
        'skipped' => array_slice($skipped, 0, 100),
        'errors' => array_slice(array_merge($errorRows, array_map(static fn(string $m): array => ['row' => 0, 'child_raw' => '', 'mother_raw' => '', 'note' => $m], $commitErrors)), 0, 100),
    ];

    admin_redirect('/nutritionist/family_import.php', ['done' => $batchId]);
}

/* Step 3 (GET) — results for a committed batch. */
$result = null;
$doneBatchId = (int)($_GET['done'] ?? 0);
if ($doneBatchId > 0 && ($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
    $batch = admin_fetch_one('SELECT * FROM import_batches WHERE id = ? LIMIT 1', 'i', [$doneBatchId]);
    if ($batch !== null) {
        if (!$isAdmin && (int)($batch['barangay_id'] ?? 0) !== (int)($user['barangay_id'] ?? 0)) {
            admin_redirect('/nutritionist/children.php', ['notice' => 'Batch not found in your scope.', 'type' => 'error']);
        }
        $result = [
            'batch' => $batch,
            'staged' => admin_fetch_all(
                "SELECT row_num, mother_raw, child_raw, sex_raw, dob_raw, reason FROM import_staging_rows WHERE batch_id = ? AND status = 'pending' ORDER BY row_num LIMIT 200",
                'i',
                [$doneBatchId]
            ),
            'details' => $_SESSION[ML_IMPORT_RESULT_KEY] ?? null,
        ];
        if (is_array($result['details']) && (int)($result['details']['batch_id'] ?? 0) === $doneBatchId) {
            unset($_SESSION[ML_IMPORT_RESULT_KEY]);
        } else {
            $result['details'] = null;
        }
        $preview = null;
        unset($_SESSION[ML_IMPORT_SESSION_KEY]);
    }
}

/* Discard a preview and start over. */
if (($_GET['action'] ?? '') === 'cancel' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
    unset($_SESSION[ML_IMPORT_SESSION_KEY], $_SESSION[ML_IMPORT_RESULT_KEY]);
    admin_redirect('/nutritionist/family_import.php');
}

/* If a preview is still in session (e.g. back button), offer to resume it. */
if ($preview === null && $result === null && ($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && isset($_SESSION[ML_IMPORT_SESSION_KEY])) {
    $preview = $_SESSION[ML_IMPORT_SESSION_KEY];
}

/* ------------------------------------------------------------------
 * Render
 * ------------------------------------------------------------------ */

$isScopedUser = !$isAdmin;
$barangays = admin_barangay_options($isScopedUser ? $user : null);

$counts = ['ok' => 0, 'duplicate' => 0, 'staged' => 0, 'error' => 0];
if (is_array($preview)) {
    foreach (($preview['rows'] ?? []) as $r) {
        $v = (string)($r['verdict'] ?? 'error');
        if (!isset($counts[$v])) {
            $v = 'error';
        }
        $counts[$v]++;
    }
}

$actions = '';

nutritionist_layout_start(
    'Master-list import',
    'Register families from the OPT Plus .xlsx.',
    'import',
    $actions,
    'Master-list import'
);
?>

<style>
.ml-steps{display:flex;gap:8px;margin:0 0 18px;flex-wrap:wrap}
.ml-step{flex:1;min-width:150px;border:1px solid var(--admin-border);border-radius:10px;padding:10px 14px;background:var(--admin-surface);font-size:12px;color:var(--admin-muted)}
.ml-step .n{display:inline-flex;width:22px;height:22px;border-radius:50%;background:var(--admin-surface-alt);border:1px solid var(--admin-border);align-items:center;justify-content:center;font-weight:800;font-size:11px;margin-right:8px;color:var(--admin-text)}
.ml-step.is-active{border-color:var(--admin-primary);color:var(--admin-text)}
.ml-step.is-active .n{background:var(--admin-primary);border-color:var(--admin-primary);color:#fff}
.ml-step.is-done .n{background:var(--admin-primary-soft);border-color:var(--admin-primary);color:var(--admin-primary)}
.ml-step strong{display:block;font-size:13px;margin-bottom:2px}
.ml-cards{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin:0 0 16px}
@media(max-width:860px){.ml-cards{grid-template-columns:repeat(2,minmax(0,1fr))}}
.ml-card{border:1px solid var(--admin-border);border-radius:10px;padding:12px 14px;background:var(--admin-surface)}
.ml-card .v{font-size:24px;font-weight:800;color:var(--admin-text)}
.ml-card .k{font-size:11px;color:var(--admin-muted);font-weight:700;text-transform:uppercase;letter-spacing:.04em}
.ml-card.is-ok .v{color:var(--admin-primary)}
.ml-card.is-warn .v{color:#b45309}
.ml-card.is-err .v{color:var(--admin-danger,#dc2626)}
.ml-table-wrap{overflow-x:auto;border:1px solid var(--admin-border);border-radius:10px}
.ml-table{width:100%;border-collapse:collapse;font-size:12px;min-width:760px}
.ml-table th,.ml-table td{padding:8px 10px;border-bottom:1px solid var(--admin-border);text-align:left;vertical-align:top}
.ml-table thead th{background:var(--admin-surface-alt);font-size:11px;text-transform:uppercase;letter-spacing:.04em;color:var(--admin-muted)}
.ml-guide{width:100%;border-collapse:collapse;font-size:12px;margin:10px 0 0}
.ml-guide td{border:1px solid var(--admin-border);padding:6px 10px}
.ml-guide td:first-child{font-weight:700;white-space:nowrap;background:var(--admin-surface-alt)}
.mon-subtabs{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px}
.mon-subtab{font-size:14px;font-weight:700;padding:9px 18px;border-radius:999px;border:2px solid var(--admin-border);background:var(--admin-surface);color:var(--admin-text);text-decoration:none;transition:all .15s;cursor:pointer}
.mon-subtab:hover{border-color:var(--admin-primary);color:var(--admin-primary)}
.mon-subtab.is-active{background:var(--admin-primary);color:#fff;border-color:var(--admin-primary)}
.mon-subtab.is-active span{opacity:.85;}
</style>

<section class="nutritionist-panel">
    <div class="nutritionist-form-head" style="margin-bottom:14px;">
        <div>
            <h2 class="admin-section-title" style="margin-bottom:2px;">Master-list import</h2>
            <p class="admin-section-subtitle">Names auto-split from <em>Surname, First Name</em>.</p>
        </div>
    </div>

    <?php
    $step = $result !== null ? 3 : ($preview !== null ? 2 : 1);
    ?>
    <div class="ml-steps" aria-label="Import progress">
        <div class="ml-step <?php echo $step === 1 ? 'is-active' : 'is-done'; ?>"><strong><span class="n">1</span>Upload</strong>Pumili ng .xlsx file.</div>
        <div class="ml-step <?php echo $step === 2 ? 'is-active' : ($step > 2 ? 'is-done' : ''); ?>"><strong><span class="n">2</span>Preview</strong>Suriin bago i-save.</div>
        <div class="ml-step <?php echo $step === 3 ? 'is-active' : ''; ?>"><strong><span class="n">3</span>Tapos</strong>Resulta ng import.</div>
    </div>

    <?php if ($result !== null): ?>
        <?php $batch = $result['batch']; ?>
        <div class="ml-cards">
            <div class="ml-card is-ok"><div class="v"><?php echo (int)($batch['imported_children'] ?? 0); ?></div><div class="k">Children saved</div></div>
            <div class="ml-card is-ok"><div class="v"><?php echo (int)($batch['imported_parents'] ?? 0); ?></div><div class="k">Parent accounts</div></div>
            <div class="ml-card"><div class="v"><?php echo (int)($batch['graduated_children'] ?? 0); ?></div><div class="k">Graduated (60+ mo)</div></div>
            <div class="ml-card is-warn"><div class="v"><?php echo (int)($batch['staged_rows'] ?? 0); ?></div><div class="k">Need completion</div></div>
        </div>

        <p class="admin-section-subtitle">Skipped: <strong><?php echo (int)($batch['skipped_rows'] ?? 0); ?></strong></p>

        <?php if ($result['staged'] !== []): ?>
            <h3 class="admin-section-title" style="font-size:14px;margin:16px 0 8px;">Kailangang kumpletuhin (<?php echo count($result['staged']); ?>)</h3>
            <div class="nutritionist-table-wrap">
                <table class="nutritionist-table" data-page-size="10">
                    <thead><tr><th>Row</th><th>Nanay</th><th>Bata</th><th>Sex</th><th>Birthdate</th><th>Bakit?</th></tr></thead>
                    <tbody>
                        <?php foreach ($result['staged'] as $stIndex => $s): ?>
                            <tr<?php echo admin_paged_row_attr($stIndex, 10); ?>>
                                <td><?php echo (int)$s['row_num']; ?></td>
                                <td><?php echo nutritionist_e((string)($s['mother_raw'] ?? '')); ?></td>
                                <td><?php echo nutritionist_e((string)($s['child_raw'] ?? '')); ?></td>
                                <td><?php echo nutritionist_e((string)($s['sex_raw'] ?? '')); ?></td>
                                <td><?php echo nutritionist_e((string)($s['dob_raw'] ?? '')); ?></td>
                                <td><?php echo nutritionist_e((string)($s['reason'] ?? '')); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <?php $details = is_array($result['details']) ? $result['details'] : null; ?>
        <?php $skipRows = $details !== null ? array_merge($details['skipped'] ?? [], $details['errors'] ?? []) : []; ?>
        <?php if ($skipRows !== []): ?>
            <h3 class="admin-section-title" style="font-size:14px;margin:16px 0 8px;">Nilaktawan / errors</h3>
            <div class="nutritionist-table-wrap">
                <table class="nutritionist-table" data-page-size="10">
                    <thead><tr><th>Row</th><th>Nanay</th><th>Bata</th><th>Bakit?</th></tr></thead>
                    <tbody>
                        <?php foreach ($skipRows as $skIndex => $s): ?>
                            <tr<?php echo admin_paged_row_attr($skIndex, 10); ?>>
                                <td><?php echo (int)($s['row'] ?? 0); ?></td>
                                <td><?php echo nutritionist_e((string)($s['mother_raw'] ?? '')); ?></td>
                                <td><?php echo nutritionist_e((string)($s['child_raw'] ?? '')); ?></td>
                                <td><?php echo nutritionist_e((string)($s['note'] ?? '')); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <div class="admin-actions" style="margin-top:16px;">
            <a class="admin-btn" href="<?php echo nutritionist_e(app_url('/nutritionist/children.php')); ?>"><?php echo admin_action_icon('back'); ?> Children list</a>
            <a class="admin-btn-secondary" href="<?php echo nutritionist_e(app_url('/nutritionist/family_import.php')); ?>" style="margin-left:8px;"><?php echo admin_action_icon('add'); ?> Mag-import ulit</a>
        </div>

    <?php elseif ($preview !== null): ?>
        <?php $prows = $preview['rows'] ?? []; ?>
        <div class="ml-cards">
            <div class="ml-card is-ok"><div class="v"><?php echo (int)$counts['ok']; ?></div><div class="k">Ready to save</div></div>
            <div class="ml-card"><div class="v"><?php echo (int)$counts['duplicate']; ?></div><div class="k">Already registered</div></div>
            <div class="ml-card is-warn"><div class="v"><?php echo (int)$counts['staged']; ?></div><div class="k">Need completion</div></div>
            <div class="ml-card is-err"><div class="v"><?php echo (int)$counts['error']; ?></div><div class="k">Errors (skipped)</div></div>
        </div>

        <div class="mon-subtabs" id="ml-verdict-pills">
            <a class="mon-subtab is-active" data-ml-verdict="">All <span>(<?php echo count($prows); ?>)</span></a>
            <a class="mon-subtab" data-ml-verdict="verdict:ok">Ready <span>(<?php echo (int)$counts['ok']; ?>)</span></a>
            <a class="mon-subtab" data-ml-verdict="verdict:duplicate">Registered na <span>(<?php echo (int)$counts['duplicate']; ?>)</span></a>
            <a class="mon-subtab" data-ml-verdict="verdict:staged">Kukumpletuhin <span>(<?php echo (int)$counts['staged']; ?>)</span></a>
            <a class="mon-subtab" data-ml-verdict="verdict:error">Error <span>(<?php echo (int)$counts['error']; ?>)</span></a>
        </div>

        <div class="children-toolbar" style="margin-bottom:18px;">
            <input
                class="admin-search"
                data-admin-filter="#ml-preview-table"
                id="ml-preview-search"
                type="search"
                placeholder="Search preview rows..."
            >
        </div>

        <script>
        (function () {
            var pills = document.querySelectorAll('#ml-verdict-pills [data-ml-verdict]');
            var search = document.getElementById('ml-preview-search');
            if (!pills.length || !search) return;
            pills.forEach(function (pill) {
                pill.addEventListener('click', function (e) {
                    e.preventDefault();
                    pills.forEach(function (p) { p.classList.remove('is-active'); });
                    pill.classList.add('is-active');
                    search.value = pill.getAttribute('data-ml-verdict') || '';
                    search.dispatchEvent(new Event('input', { bubbles: true }));
                });
            });
        })();
        </script>

        <div class="nutritionist-table-wrap" style="margin-bottom:14px;">
            <table class="nutritionist-table" id="ml-preview-table" data-page-size="10">
                <thead><tr><th>Row</th><th>Nanay</th><th>Bata</th><th>Sex</th><th>Birthdate</th><th>Status</th></tr></thead>
                <tbody>
                    <?php foreach ($prows as $pvIndex => $r): ?>
                        <?php
                        $verdict = (string)($r['verdict'] ?? 'error');
                        $pill = match ($verdict) {
                            'ok' => '<span class="admin-pill is-success">Ready</span>',
                            'duplicate' => '<span class="admin-pill is-muted">Registered na</span>',
                            'staged' => '<span class="admin-pill is-warn">Kukumpletuhin</span>',
                            default => '<span class="admin-pill is-danger">Error</span>',
                        };
                        $momParsed = isset($r['mother']) ? nutritionist_e(trim($r['mother']['first'] . ' ' . $r['mother']['middle'] . ' ' . $r['mother']['last'])) : nutritionist_e((string)($r['mother_raw'] ?? '—'));
                        $kidParsed = isset($r['child']) ? nutritionist_e(trim($r['child']['first'] . ' ' . $r['child']['middle'] . ' ' . $r['child']['last'])) : nutritionist_e((string)($r['child_raw'] ?? '—'));
                        ?>
                        <tr<?php echo admin_paged_row_attr($pvIndex, 10); ?>
                            data-filter-text="<?php echo nutritionist_e(strtolower(trim((string)($r['mother_raw'] ?? '') . ' ' . (string)($r['child_raw'] ?? '') . ' verdict:' . (string)($r['verdict'] ?? '') . ' ' . (string)($r['note'] ?? '')))); ?>"
                        >
                            <td><?php echo (int)($r['row'] ?? 0); ?></td>
                            <td><?php echo $momParsed; ?></td>
                            <td><?php echo $kidParsed; ?></td>
                            <td><?php echo $r['sex'] !== null ? nutritionist_e((string)$r['sex']) : '—'; ?></td>
                            <td><?php echo $r['dob'] !== null ? nutritionist_e((string)$r['dob']) : '—'; ?></td>
                            <td><?php echo $pill; ?><br><span class="admin-mini"><?php echo nutritionist_e(trim((string)($r['note'] ?? '') . ' ' . (string)($r['hint'] ?? ''))); ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <form method="post" action="<?php echo nutritionist_e(app_url('/nutritionist/family_import.php')); ?>" data-admin-confirm="Save <?php echo (int)$counts['ok']; ?> new child record(s) into <?php echo nutritionist_e((string)($preview['barangay_name'] ?? '')); ?>?">
            <input type="hidden" name="action" value="commit">
            <button class="admin-btn" type="submit"><?php echo admin_action_icon('save'); ?> Confirm &amp; save <?php echo (int)$counts['ok']; ?> record(s)</button>
            <a class="admin-btn-secondary" href="<?php echo nutritionist_e(app_url('/nutritionist/family_import.php?action=cancel')); ?>" style="margin-left:8px;"><?php echo admin_action_icon('cancel'); ?> Cancel</a>
        </form>

    <?php else: ?>
        <form method="post" enctype="multipart/form-data" action="<?php echo nutritionist_e(app_url('/nutritionist/family_import.php')); ?>" class="nutritionist-form-grid">
            <input type="hidden" name="action" value="preview">

            <?php if (!$isScopedUser): ?>
                <label class="admin-field">
                    <span>Barangay <span class="admin-required">*</span></span>
                    <select name="barangay_id" required>
                        <option value="">-- Select Barangay --</option>
                        <?php foreach ($barangays as $barangay): ?>
                            <option value="<?php echo (int)$barangay['id']; ?>"><?php echo nutritionist_e($barangay['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            <?php endif; ?>

            <div class="admin-field admin-field-wide">
                <span>Master list file (.xlsx, max 5 MB) <span class="admin-required">*</span></span>
                <div class="who-ref-import-actions">
                    <label class="admin-btn-secondary" style="cursor:pointer;margin:0;white-space:nowrap;">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" width="16" height="16"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3"/></svg>
                        Choose .xlsx
                        <input type="file" id="mlFileInput" name="xlsx_file" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required style="display:none;">
                    </label>
                    <span class="who-ref-file-chip" id="mlFileChip" hidden>
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" width="16" height="16"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m0 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Zm3.75 11.625-3 3m0 0-3-3m3 3V10.5"/></svg>
                        <span class="who-ref-file-name" id="mlFileName"></span>
                        <span class="who-ref-file-size" id="mlFileSize"></span>
                        <button type="button" class="who-ref-file-clear" id="mlFileClear" aria-label="Remove selected file">&times;</button>
                    </span>
                    <button class="admin-btn" type="submit" id="mlImportBtn" style="white-space:nowrap;display:none;" disabled>
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" width="16" height="16"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3"/></svg>
                        Import
                    </button>
                </div>
                <span class="admin-field-hint">First sheet only.</span>
            </div>
        </form>

        <table class="ml-guide">
            <tr><td>Binabasa</td><td>Mother/child name · Sex · Birthdate.</td></tr>
            <tr><td>Auto-split</td><td>Surname-first names split automatically. Siblings share one parent account.</td></tr>
            <tr><td>Skip</td><td>Seq, IP, measurements. Address: barangay check lang. 60+ mo: Graduated. Login: auto email + default password.</td></tr>
        </table>

        <script>
        (function () {
            var input = document.getElementById('mlFileInput');
            var chip = document.getElementById('mlFileChip');
            var nameEl = document.getElementById('mlFileName');
            var sizeEl = document.getElementById('mlFileSize');
            var clearBtn = document.getElementById('mlFileClear');
            var importBtn = document.getElementById('mlImportBtn');
            if (!input || !chip || !nameEl || !sizeEl) return;

            function fmtSize(bytes) {
                if (!bytes && bytes !== 0) return '';
                if (bytes < 1024) return bytes + ' B';
                if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' KB';
                return (bytes / 1048576).toFixed(2) + ' MB';
            }

            function setImportVisible(visible) {
                if (!importBtn) return;
                importBtn.style.display = visible ? '' : 'none';
                importBtn.disabled = !visible;
            }

            input.addEventListener('change', function () {
                var file = input.files && input.files[0];
                if (!file) {
                    chip.hidden = true;
                    setImportVisible(false);
                    return;
                }
                var valid = /\.xlsx$/i.test(file.name);
                nameEl.textContent = file.name;
                nameEl.title = file.name;
                sizeEl.textContent = valid ? fmtSize(file.size) : 'must be .xlsx';
                chip.classList.toggle('is-invalid', !valid);
                chip.hidden = false;
                setImportVisible(valid);
            });

            if (clearBtn) {
                clearBtn.addEventListener('click', function () {
                    input.value = '';
                    chip.hidden = true;
                    chip.classList.remove('is-invalid');
                    setImportVisible(false);
                });
            }
        })();
        </script>
    <?php endif; ?>
</section>

<?php

nutritionist_layout_end();
