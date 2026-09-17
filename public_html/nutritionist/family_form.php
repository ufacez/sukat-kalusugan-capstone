<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/nutritionist_helpers.php';
require_once __DIR__ . '/../includes/who_calculator.php';

const FAMILY_DEFAULT_PASSWORD = 'PalitanMo@123';

function family_next_child_code(): string
{
    $row = admin_fetch_one(
        'SELECT child_code FROM children ORDER BY id DESC LIMIT 1'
    );

    $lastCode = (string)($row['child_code'] ?? 'CHD-0000');

    if (preg_match('/(\d+)$/', $lastCode, $matches) !== 1) {
        return 'CHD-0001';
    }

    return 'CHD-' . str_pad(
        (string)(((int)$matches[1]) + 1),
        4,
        '0',
        STR_PAD_LEFT
    );
}

function family_slug(string $value): string
{
    $value = strtolower(trim($value));
    $value = (string)preg_replace('/[^a-z0-9]+/', '', $value);

    return $value;
}

/**
 * Auto email: firstname.lastname@sukat.kalusugan, with a trailing number
 * when the base address is already taken (e.g. juan.delacruz2@...).
 */
function family_make_parent_email(string $firstName, string $lastName): string
{
    $first = family_slug($firstName);
    $last = family_slug($lastName);

    if ($first === '') {
        $first = 'parent';
    }
    if ($last === '') {
        $last = 'sukat';
    }

    $base = $first . '.' . $last;
    $email = $base . '@sukat.kalusugan';
    $counter = 1;

    while (admin_email_in_use($email) && $counter < 100) {
        $counter++;
        $email = $base . $counter . '@sukat.kalusugan';
    }

    return $email;
}

$user = nutritionist_require_access();
$parentTypes = ['Father', 'Mother', 'Guardian', 'Grandparent', 'Other'];

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $mode = (($_POST['mode'] ?? 'new') === 'existing') ? 'existing' : 'new';
    $backUrl = '/nutritionist/family_form.php' . ($mode === 'existing' ? '?mode=existing' : '');

    if ($mode === 'existing') {
        nutritionist_require_write('children.create');
    } else {
        nutritionist_require_write('parents.create');
        nutritionist_require_write('children.create');
    }

    /*
     * Collect + normalize child rows. Blank cards (all four fields empty)
     * are skipped so an untouched extra card never blocks the submit.
     */
    $rawChildren = $_POST['children'] ?? [];
    if (!is_array($rawChildren)) {
        $rawChildren = [];
    }

    $childrenInput = [];
    foreach ($rawChildren as $row) {
        if (!is_array($row)) {
            continue;
        }
        $cFirst = trim((string)($row['first_name'] ?? ''));
        $cMiddle = trim((string)($row['middle_name'] ?? ''));
        $cLast = trim((string)($row['last_name'] ?? ''));
        $cBirth = trim((string)($row['birthdate'] ?? ''));

        if ($cFirst === '' && $cMiddle === '' && $cLast === '' && $cBirth === '') {
            continue;
        }

        $childrenInput[] = [
            'first_name' => $cFirst,
            'middle_name' => $cMiddle,
            'last_name' => $cLast,
            'birthdate' => $cBirth,
            'sex' => trim((string)($row['sex'] ?? 'Male')),
            'is_ip' => !empty($row['is_ip']) ? 1 : 0,
            'has_disability' => !empty($row['has_disability']) ? 1 : 0,
        ];
    }

    if ($childrenInput === []) {
        admin_redirect($backUrl, ['notice' => 'Magdagdag ng kahit isang bata.', 'type' => 'error']);
    }

    foreach ($childrenInput as $idx => $child) {
        $n = $idx + 1;

        if (
            !admin_is_valid_name_part($child['first_name'], true)
            || !admin_is_valid_name_part($child['middle_name'], false)
            || !admin_is_valid_name_part($child['last_name'], true)
            || $child['birthdate'] === ''
        ) {
            admin_redirect($backUrl, ['notice' => 'Bata #' . $n . ': kumpletuhin ang first name, surname, at birthdate.', 'type' => 'error']);
        }

        if (!in_array($child['sex'], ['Male', 'Female'], true)) {
            $childrenInput[$idx]['sex'] = 'Male';
        }

        $ageDays = doh_age_in_days($child['birthdate']);
        if ($ageDays === null || $ageDays > 1825) {
            admin_redirect($backUrl, ['notice' => 'Bata #' . $n . ': ang birthdate ay invalid o lampas 5 taong gulang na.', 'type' => 'error']);
        }

        $dup = child_duplicate_identity($child['first_name'], $child['last_name'], $child['birthdate']);
        if ($dup !== null) {
            $code = (string)($dup['child_code'] ?? '');
            admin_redirect($backUrl, ['notice' => 'Bata #' . $n . ' ay registered na' . ($code !== '' ? ' (' . $code . ')' : '') . '. Suriin ang Children list.', 'type' => 'error']);
        }
    }

    // Same-batch duplicates (two identical cards in one submit).
    $seen = [];
    foreach ($childrenInput as $idx => $child) {
        $key = strtolower($child['first_name']) . '|' . strtolower($child['last_name']) . '|' . $child['birthdate'];
        if (isset($seen[$key])) {
            admin_redirect($backUrl, ['notice' => 'Bata #' . ($idx + 1) . ' ay kapareho ng Bata #' . ($seen[$key] + 1) . ' sa form na ito.', 'type' => 'error']);
        }
        $seen[$key] = $idx;
    }

    $isAdmin = ($user['role'] ?? '') === 'admin';

    // New-parent fields are validated only in new mode. In existing mode
    // the parent section is hidden/disabled and these stay at defaults.
    $pFirst = $pMiddle = $pLast = $pEmail = $pPhone = $pAddress = '';
    $pParentType = 'Guardian';
    $pPassword = '';
    $pBarangayId = $pLocalAreaId = $pHouseholdId = null;

    if ($mode === 'new') {
    $pFirst = trim((string)($_POST['first_name'] ?? ''));
    $pMiddle = trim((string)($_POST['middle_name'] ?? ''));
    $pLast = trim((string)($_POST['last_name'] ?? ''));
    $pEmail = trim((string)($_POST['email'] ?? ''));
    $pPhone = trim((string)($_POST['phone'] ?? ''));
    $pAddress = trim((string)($_POST['address'] ?? ''));
    $pParentType = trim((string)($_POST['parent_type'] ?? 'Guardian'));
    $pPassword = (string)($_POST['password'] ?? '');
    $pPasswordConfirm = (string)($_POST['password_confirm'] ?? '');

    if (!$isAdmin) {
        $userBarangayId = $user['barangay_id'] ?? null;
        if ($userBarangayId === null || $userBarangayId === '') {
            admin_redirect($backUrl, ['notice' => 'Your account is not assigned to a barangay. Contact your administrator before adding families.', 'type' => 'error']);
        }
        $pBarangayId = (int)$userBarangayId;
    } else {
        $barangayRaw = trim((string)($_POST['barangay_id'] ?? ''));
        $pBarangayId = $barangayRaw !== '' ? (int)$barangayRaw : null;
        if ($pBarangayId === null || $pBarangayId <= 0) {
            admin_redirect($backUrl, ['notice' => 'Pumili ng barangay para sa magulang.', 'type' => 'error']);
        }
    }

    $localRaw = trim((string)($_POST['local_area_id'] ?? ''));
    $pLocalAreaId = $localRaw !== '' ? (int)$localRaw : null;
    $householdRaw = trim((string)($_POST['household_id'] ?? ''));
    $pHouseholdId = $householdRaw !== '' ? (int)$householdRaw : null;

    if (
        !admin_is_valid_name_part($pFirst, true)
        || !admin_is_valid_name_part($pMiddle, false)
        || !admin_is_valid_name_part($pLast, true)
    ) {
        admin_redirect($backUrl, ['notice' => 'Enter a valid parent first name and surname (letters only).', 'type' => 'error']);
    }

    if (!in_array($pParentType, $parentTypes, true)) {
        $pParentType = 'Guardian';
    }

    if (!admin_is_valid_ph_mobile($pPhone)) {
        admin_redirect($backUrl, ['notice' => 'Enter a valid 11-digit PH mobile number starting with 09.', 'type' => 'error']);
    }
    $pPhone = (string)preg_replace('/[^0-9]/', '', $pPhone);

    // Email: blank = auto-generate; supplied = validate + uniqueness.
    if ($pEmail === '') {
        $pEmail = family_make_parent_email($pFirst, $pLast);
    } else {
        if (!filter_var($pEmail, FILTER_VALIDATE_EMAIL)) {
            admin_redirect($backUrl, ['notice' => 'Enter a valid email address.', 'type' => 'error']);
        }
        if (admin_email_in_use($pEmail)) {
            admin_redirect($backUrl, ['notice' => 'Ang email na ito ay gamit na. Gumamit ng ibang email o iwanang blangko para sa auto email.', 'type' => 'error']);
        }
    }

    // Password: blank + blank = default password; supplied = strong + match.
    if ($pPassword === '' && $pPasswordConfirm === '') {
        $pPassword = FAMILY_DEFAULT_PASSWORD;
        $pPasswordConfirm = FAMILY_DEFAULT_PASSWORD;
    }
    if (!admin_is_strong_password($pPassword)) {
        admin_redirect($backUrl, ['notice' => 'Password must be at least 8 characters and include an uppercase letter, a lowercase letter, a number, and a special character.', 'type' => 'error']);
    }
    if ($pPassword !== $pPasswordConfirm) {
        admin_redirect($backUrl, ['notice' => 'Password and confirm password do not match.', 'type' => 'error']);
    }

    // Local area must belong to the parent barangay (same rule as parent_form).
    if ($pLocalAreaId !== null && $pLocalAreaId > 0) {
        $areaCheck = admin_fetch_one(
            'SELECT id FROM local_areas WHERE id = ? AND barangay_id = ? AND is_active = 1 LIMIT 1',
            'ii',
            [$pLocalAreaId, $pBarangayId]
        );
        if (!$areaCheck) {
            admin_redirect($backUrl, ['notice' => 'Selected Local Area is inactive or does not belong to the assigned Barangay.', 'type' => 'error']);
        }
    } else {
        // Required — unless the barangay has no registered local areas yet,
        // so a nutritionist is never stuck on an unsubmittable form.
        $areaCount = admin_scalar(
            'SELECT COUNT(*) FROM local_areas WHERE barangay_id = ? AND is_active = 1',
            'i',
            [$pBarangayId]
        );
        if ($areaCount > 0) {
            admin_redirect($backUrl, ['notice' => 'Pumili ng local area / purok.', 'type' => 'error']);
        }
        $pLocalAreaId = null;
    }

    // Household must belong to the parent barangay (silent null like parent_form).
    if ($pHouseholdId !== null && $pBarangayId !== null) {
        $householdCheck = admin_fetch_one(
            'SELECT id FROM households WHERE id = ? AND barangay_id = ? AND status = "active" LIMIT 1',
            'ii',
            [$pHouseholdId, $pBarangayId]
        );
        if (!$householdCheck) {
            $pHouseholdId = null;
        }
    }
    } // end new-mode parent validation

    /*
     * Single transaction: parent + all children. Any failure rolls
     * everything back so the web app never keeps a half-saved family.
     * Kiosk endpoints are untouched: new children use the exact same
     * columns/status, so they appear on the next ~3s poll.
     */
    $conn = get_db_connection();
    mysqli_begin_transaction($conn);

    try {
        if ($mode === 'new') {
        $pName = admin_combine_name($pFirst, $pMiddle, $pLast);
        $hash = password_hash($pPassword, PASSWORD_DEFAULT);

        $insParent = mysqli_prepare(
            $conn,
            'INSERT INTO parents (name, email, password_hash, parent_type, phone, address, barangay_id, local_area_id, household_id, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        if ($insParent === false) {
            throw new RuntimeException('Hindi ma-save ang parent record. Subukan muli.');
        }
        $pStatus = 'active';
        mysqli_stmt_bind_param($insParent, 'ssssssiiis', $pName, $pEmail, $hash, $pParentType, $pPhone, $pAddress, $pBarangayId, $pLocalAreaId, $pHouseholdId, $pStatus);
        if (!mysqli_stmt_execute($insParent)) {
            mysqli_stmt_close($insParent);
            throw new RuntimeException('Hindi ma-save ang parent. Maaaring duplicate ang email.');
        }
        mysqli_stmt_close($insParent);

        $parentId = (int)mysqli_insert_id($conn);
        if ($parentId <= 0) {
            throw new RuntimeException('Hindi ma-save ang parent record. Subukan muli.');
        }

        $parentBarangayId = (int)$pBarangayId;
        // NULL (never 0): children.local_area_id has an FK to local_areas,
        // so 0 would fail with errno 1452 when no purok is chosen.
        $parentLocalAreaId = ($pLocalAreaId !== null && $pLocalAreaId > 0) ? (int)$pLocalAreaId : null;
        $parentHouseholdId = ($pHouseholdId !== null && $pHouseholdId > 0) ? (int)$pHouseholdId : null;
        $parentLabel = $pEmail;
        } else {
        // Existing parent: children inherit its barangay / purok / household.
        $existingParentId = (int)($_POST['existing_parent_id'] ?? 0);
        if ($existingParentId <= 0) {
            throw new RuntimeException('Pumili muna ng parent/guardian.');
        }
        $prow = admin_fetch_one(
            'SELECT id, name, email, barangay_id, local_area_id, household_id FROM parents WHERE id = ? LIMIT 1',
            'i',
            [$existingParentId]
        );
        if ($prow === null) {
            throw new RuntimeException('Ang napiling parent ay hindi makita.');
        }
        if (!$isAdmin && (int)($prow['barangay_id'] ?? 0) !== (int)($user['barangay_id'] ?? 0)) {
            throw new RuntimeException('You can only add children to parents under your assigned barangay.');
        }
        $parentId = (int)$prow['id'];
        $parentBarangayId = (int)($prow['barangay_id'] ?? 0);
        if ($parentBarangayId <= 0) {
            throw new RuntimeException('Ang napiling parent ay walang barangay. I-update muna ang parent record.');
        }
        $parentLocalAreaId = isset($prow['local_area_id']) && $prow['local_area_id'] !== null && (int)$prow['local_area_id'] > 0 ? (int)$prow['local_area_id'] : null;
        $parentHouseholdId = isset($prow['household_id']) && $prow['household_id'] !== null ? (int)$prow['household_id'] : null;
        $parentLabel = (string)($prow['name'] ?? $prow['email'] ?? ('#' . $parentId));

        // Inherited spots may have gone inactive — fall back to NULL, never error.
        if ($parentLocalAreaId !== null && $parentLocalAreaId > 0) {
            $areaCheck = admin_fetch_one(
                'SELECT id FROM local_areas WHERE id = ? AND barangay_id = ? AND is_active = 1 LIMIT 1',
                'ii',
                [$parentLocalAreaId, $parentBarangayId]
            );
            if (!$areaCheck) {
                $parentLocalAreaId = null;
            }
        }
        if ($parentHouseholdId !== null && $parentHouseholdId > 0) {
            $householdCheck = admin_fetch_one(
                'SELECT id FROM households WHERE id = ? AND barangay_id = ? AND status = "active" LIMIT 1',
                'ii',
                [$parentHouseholdId, $parentBarangayId]
            );
            if (!$householdCheck) {
                $parentHouseholdId = null;
            }
        }
        }

        // Children inherit barangay / purok / household / address from the parent.
        $savedCodes = [];
        foreach ($childrenInput as $child) {
            $childCode = family_next_child_code();

            $insChild = mysqli_prepare(
                $conn,
                'INSERT INTO children (child_code, first_name, middle_name, last_name, birthdate, sex, barangay_id, local_area_id, is_ip, has_disability, parent_id, household_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            if ($insChild === false) {
                throw new RuntimeException('Hindi ma-save ang bata. Subukan muli.');
            }
            $cFirst = $child['first_name'];
            $cMiddle = $child['middle_name'];
            $cLast = $child['last_name'];
            $cBirth = $child['birthdate'];
            $cSex = $child['sex'];
            $cIp = (int)$child['is_ip'];
            $cDis = (int)$child['has_disability'];
            mysqli_stmt_bind_param($insChild, 'ssssssssiiii', $childCode, $cFirst, $cMiddle, $cLast, $cBirth, $cSex, $parentBarangayId, $parentLocalAreaId, $cIp, $cDis, $parentId, $parentHouseholdId);
            if (!mysqli_stmt_execute($insChild)) {
                mysqli_stmt_close($insChild);
                throw new RuntimeException('Hindi ma-save ang bata (' . $cFirst . ' ' . $cLast . '). Subukan muli.');
            }
            mysqli_stmt_close($insChild);
            $savedCodes[] = $childCode;
        }

        mysqli_commit($conn);

        $actor = current_user();
        if ($mode === 'new') {
            log_action($actor['id'] ?? null, 'CREATE_PARENT', 'info', 'Created parent account ' . $parentLabel . ' via family form');
        }
        log_action($actor['id'] ?? null, 'CREATE_CHILD', 'info', 'Created ' . count($savedCodes) . ' child(ren) via family form (' . implode(', ', $savedCodes) . ')');

        $kidWord = count($savedCodes) === 1 ? '1 child' : count($savedCodes) . ' children';
        admin_redirect('/nutritionist/children.php', ['notice' => 'Family saved: ' . $kidWord . ' added (' . implode(', ', $savedCodes) . '). Lalabas agad sila sa kiosk.']);
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        error_log('[SukatKalusugan] family_form.php: ' . $e->getMessage());
        admin_redirect($backUrl, ['notice' => $e->getMessage(), 'type' => 'error']);
    }
}

/*
 * GET: page data
 */
$mode = (($_GET['mode'] ?? 'new') === 'existing') ? 'existing' : 'new';
$canCreateParent = nutritionist_can_write('parents.create');
$canCreateChild = nutritionist_can_write('children.create');

if (!$canCreateChild) {
    admin_redirect('/nutritionist/parents.php', ['notice' => 'You do not have permission to add children.', 'type' => 'error']);
}
if (!$canCreateParent) {
    $mode = 'existing';
}

$isScopedUser = ($user['role'] ?? '') !== 'admin';
$scopedBarangayId = $isScopedUser ? (int)($user['barangay_id'] ?? 0) : 0;

if ($isScopedUser && $scopedBarangayId <= 0) {
    admin_redirect('/nutritionist/parents.php', ['notice' => 'Your account is not assigned to a barangay. Contact your administrator before adding families.', 'type' => 'error']);
}

$barangays = admin_barangay_options($isScopedUser ? $user : null);

$lockedBarangayName = '';
foreach ($barangays as $b) {
    if ((int)$b['id'] === $scopedBarangayId) {
        $lockedBarangayName = (string)$b['name'];
        break;
    }
}

if ($isScopedUser) {
    $households = admin_fetch_all(
        "SELECT id, household_code, address, barangay_id
           FROM households
          WHERE barangay_id = ? AND status = 'active'
          ORDER BY household_code",
        'i',
        [$scopedBarangayId]
    );
} else {
    $households = admin_fetch_all(
        "SELECT h.id, h.household_code, h.address, h.barangay_id, bg.name AS barangay
           FROM households h
           LEFT JOIN barangays bg ON bg.id = h.barangay_id
          WHERE h.status = 'active'
          ORDER BY bg.name ASC, h.household_code ASC
          LIMIT 300"
    );
}

$today = date('Y-m-d');

$actions = '<a class="admin-btn-secondary" href="'
    . nutritionist_e(app_url('/nutritionist/parents.php'))
    . '">' . admin_action_icon('back') . ' Parents</a>'
    . ' <a class="admin-btn-secondary" href="'
    . nutritionist_e(app_url('/nutritionist/children.php'))
    . '">' . admin_action_icon('back') . ' Children</a>';

nutritionist_layout_start(
    'Add Family',
    'Create a parent account with one or more children in a single form.',
    'parents',
    $actions,
    'Add Family'
);
?>
<style>
.ff-details{border:1px solid var(--admin-border);border-radius:10px;background:var(--admin-surface);margin:8px 0 14px}
.ff-details > summary{cursor:pointer;padding:11px 14px;font-size:13px;font-weight:700;color:var(--admin-text);list-style:none;display:flex;align-items:center;justify-content:space-between;gap:8px}
.ff-details > summary::-webkit-details-marker{display:none}
.ff-details > summary::after{content:'›';color:var(--admin-muted);font-size:18px;line-height:1;transition:transform .15s}
.ff-details[open] > summary::after{transform:rotate(90deg)}
.ff-details > summary:hover{background:var(--admin-surface-alt);border-radius:10px}
.ff-details[open] > summary:hover{border-radius:10px 10px 0 0}
.ff-details-body{padding:4px 14px 14px;border-top:1px solid var(--admin-border)}
.ff-child{border:1px solid var(--admin-border);border-radius:10px;margin-bottom:12px}
.ff-child-head{display:flex;align-items:center;gap:8px;padding:10px 14px;background:var(--admin-surface-alt);border-bottom:1px solid var(--admin-border);border-radius:10px 10px 0 0}
.ff-child-head .t{font-weight:700;font-size:13px;color:var(--admin-text);flex:1;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.ff-child-head .age{font-size:11px;color:var(--admin-muted);flex-shrink:0}
.ff-child-remove{border:1px solid var(--admin-border);background:var(--admin-surface);color:var(--admin-danger,#dc2626);border-radius:7px;font-size:11px;font-weight:700;padding:5px 10px;cursor:pointer;flex-shrink:0}
.ff-child-body{padding:14px;display:flex;flex-direction:column;gap:12px}
.ff-child-body .admin-field-row{flex-wrap:wrap}
.ff-child .ff-details{margin:4px 14px 12px}
.ff-child-body .admin-field{min-width:0}
@media(max-width:560px){.ff-child-body .admin-field-row{flex-direction:column}}
.ff-add-child{width:100%;border:1px dashed var(--admin-border);border-radius:10px;background:transparent;color:var(--admin-primary);font-weight:700;font-size:13px;padding:12px;cursor:pointer}
.ff-add-child:hover{border-color:var(--admin-primary);background:var(--admin-surface-alt)}
.ff-add-child:disabled{opacity:.45;cursor:not-allowed}
.ff-cols{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:14px;align-items:start;max-width:1180px;margin-inline:auto}
@media(max-width:960px){.ff-cols{grid-template-columns:minmax(0,1fr)}}
.ff-cols.is-single{grid-template-columns:minmax(0,1fr);max-width:760px;margin-inline:auto}
.ff-col{border:1px solid var(--admin-border);border-radius:10px;padding:16px;min-width:0;background:var(--admin-surface)}
.ff-col-title{font-size:13px;font-weight:800;color:var(--admin-text);margin:0 0 4px;display:flex;align-items:center;gap:8px}
.ff-col-sub{font-size:11px;color:var(--admin-muted);margin:0 0 14px}
.ff-ico{display:inline-flex;width:18px;height:18px;color:var(--admin-primary);flex-shrink:0}
.ff-ico svg{width:18px;height:18px}
.ff-mode{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px}
.ff-mode-opt{display:inline-flex;align-items:center;gap:8px;border:1px solid var(--admin-border);border-radius:9px;padding:9px 14px;font-size:13px;cursor:pointer;background:var(--admin-surface);color:var(--admin-text)}
.ff-mode-opt:has(input:checked){border-color:var(--admin-primary);background:var(--admin-surface-alt);font-weight:700}
.ff-picked{display:flex;align-items:center;gap:10px;border:1px solid var(--admin-border);border-radius:10px;padding:10px 12px;background:var(--admin-surface-alt);margin-top:10px}
.ff-picked .avatar{width:34px;height:34px;border-radius:50%;background:var(--admin-primary);color:#fff;font-weight:800;font-size:12px;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.parent-picker-btn{width:100%;display:flex;align-items:center;justify-content:space-between;gap:8px;border:1px solid var(--admin-border);border-radius:8px;background:var(--admin-surface);padding:10px 12px;font-size:13px;color:var(--admin-text);cursor:pointer}
.parent-picker-btn-icon{color:var(--admin-muted);font-size:18px}
.parent-picker-modal{max-width:520px}
.parent-picker-search{padding:0 0 10px}
.parent-picker-search input{width:100%;border:1px solid var(--admin-border);border-radius:8px;padding:10px 12px;font-size:13px;background:var(--admin-surface);color:var(--admin-text)}
.parent-picker-list{display:flex;flex-direction:column;gap:8px;max-height:320px;overflow-y:auto}
.parent-picker-item{display:flex;align-items:center;gap:10px;border:1px solid var(--admin-border);border-radius:10px;padding:10px 12px;background:var(--admin-surface);cursor:pointer;text-align:left;width:100%}
.parent-picker-item:hover{border-color:var(--admin-primary)}
.parent-picker-item.is-active{border-color:var(--admin-primary);background:var(--admin-surface-alt)}
.parent-picker-avatar{width:34px;height:34px;border-radius:50%;background:var(--admin-primary);color:#fff;font-size:11px;font-weight:800;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.parent-picker-item-info{display:flex;flex-direction:column;min-width:0}
.parent-picker-item-name{font-weight:700;font-size:13px;color:var(--admin-text)}
.parent-picker-item-meta{font-size:11px;color:var(--admin-muted)}
.parent-picker-empty{padding:24px;text-align:center;color:var(--admin-muted);font-size:12px}
.parent-picker-footer{display:flex;align-items:center;justify-content:space-between;margin-top:10px;font-size:12px;color:var(--admin-muted)}
@media(max-width:960px){.ff-cols{grid-template-columns:minmax(0,1fr)}}
.ff-col{border:1px solid var(--admin-border);border-radius:10px;padding:16px;min-width:0;background:var(--admin-surface)}
.ff-col-title{font-size:13px;font-weight:800;color:var(--admin-text);margin:0 0 4px}
.ff-col-sub{font-size:11px;color:var(--admin-muted);margin:0 0 14px}
.ff-col .admin-field-hint{color:var(--admin-muted);font-size:11px;margin-top:3px;line-height:1.45}
.pw-tools{display:flex;gap:8px;flex-wrap:wrap}
.pw-tools .admin-btn-secondary{padding:6px 12px;font-size:12px}
.ff-col .admin-address-picker{grid-template-columns:minmax(0,1fr)}
.ff-col input:not([type="checkbox"]):not([type="radio"]),.ff-col select,.ff-col textarea,.ff-child input:not([type="checkbox"]):not([type="radio"]),.ff-child select,.ff-add-child,.parent-picker-btn{border-radius:12px}
.pw-wrap{position:relative;display:block}
.pw-wrap input{width:100%;padding-right:42px;box-sizing:border-box}
.pw-eye{position:absolute;right:6px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:var(--admin-muted);padding:6px;display:flex;align-items:center;justify-content:center;border-radius:8px}
.pw-eye:hover{color:var(--admin-text);background:var(--admin-surface-alt)}
.pw-eye svg{width:18px;height:18px}
.ff-section-title{font-size:13px;font-weight:800;color:var(--admin-text);margin:18px 0 4px}
.ff-section-sub{font-size:11px;color:var(--admin-muted);margin:0 0 12px}
</style>

<section class="nutritionist-panel">
    <div class="nutritionist-form-head" style="margin-bottom:16px;">
        <div>
            <h2 class="admin-section-title" style="margin-bottom:2px;">Add Family</h2>
            <p class="admin-section-subtitle">Create a parent account with one or more children. Children inherit the parent's barangay, local area, household, and address, and appear on the kiosk automatically.</p>
        </div>
    </div>

    <?php if ($canCreateParent): ?>
    <div class="ff-mode" role="radiogroup" aria-label="Registration mode">
        <label class="ff-mode-opt"><input type="radio" name="mode" value="new" form="ff-form" <?php echo $mode === 'new' ? 'checked' : ''; ?>> <strong>New Family</strong></label>
        <label class="ff-mode-opt"><input type="radio" name="mode" value="existing" form="ff-form" <?php echo $mode === 'existing' ? 'checked' : ''; ?>> <strong>Existing parent</strong></label>
    </div>
    <?php else: ?>
    <input type="hidden" name="mode" value="existing" form="ff-form">
    <?php endif; ?>

    <form id="ff-form" class="nutritionist-form-grid" method="post" data-validate-form action="<?php echo nutritionist_e(app_url('/nutritionist/family_form.php')); ?>">
        <div class="admin-field-wide admin-flash is-error" data-validate-banner style="display:none;"></div>

        <div class="admin-field-wide" id="ff-existing-card" <?php echo $mode === 'new' ? 'hidden' : ''; ?>>
        <label class="admin-field">
            <span>Parent / Guardian<span class="admin-required">*</span></span>
            <input type="hidden" name="existing_parent_id" id="ff-parent-id-input" value="0">
            <button type="button" class="parent-picker-btn" id="ff-parent-picker-btn">
                <span id="ff-parent-picker-label">-- Select Parent --</span>
                <span class="parent-picker-btn-icon" aria-hidden="true">&#8250;</span>
            </button>
        </label>
        <div class="ff-picked" id="ff-picked-wrap" hidden>
            <span class="avatar" id="ff-picked-avatar">--</span>
            <span><strong id="ff-picked-name"></strong><br><span class="admin-mini" id="ff-picked-meta"></span></span>
        </div>
        </div>

        <div class="admin-field-wide ff-cols" id="ff-cols">
        <div class="ff-col" id="ff-parent-col" <?php echo $mode === 'existing' ? 'hidden' : ''; ?>>
            <h3 class="ff-col-title"><span class="ff-ico"><?php echo admin_sidebar_icon('users'); ?></span> Parent</h3>
            <p class="ff-col-sub">Guardian account. Children inherit the details below.</p>

        <div class="admin-field-wide">
            <div class="admin-field-row">
                <label class="admin-field">
                    <span>First name<span class="admin-required">*</span></span>
                    <input id="ff_p_first" name="first_name" required maxlength="60" data-validate="name" data-label="Parent first name" placeholder="Juan" autocomplete="off">
                    <span class="admin-field-message"></span>
                </label>
                <label class="admin-field">
                    <span>Middle name</span>
                    <input name="middle_name" maxlength="60" data-validate="name" data-label="Parent middle name" placeholder="Reyes" autocomplete="off">
                    <span class="admin-field-message"></span>
                </label>
                <label class="admin-field">
                    <span>Surname<span class="admin-required">*</span></span>
                    <input id="ff_p_last" name="last_name" required maxlength="60" data-validate="name" data-label="Parent surname" placeholder="Dela Cruz" autocomplete="off">
                    <span class="admin-field-message"></span>
                </label>
            </div>
        </div>

        <div class="admin-field-wide">
            <div class="admin-field-row">
                <label class="admin-field">
                    <span>Mobile number<span class="admin-required">*</span></span>
                    <input name="phone" required data-validate="phone-ph" placeholder="09171234567" autocomplete="off">
                    <span class="admin-field-message"></span>
                </label>
                <label class="admin-field">
                    <span>Parent type<span class="admin-required">*</span></span>
                    <select name="parent_type">
                        <?php foreach ($parentTypes as $type): ?>
                            <option value="<?php echo nutritionist_e($type); ?>"><?php echo nutritionist_e($type); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </div>
        </div>

        <?php if ($isScopedUser): ?>
            <input type="hidden" name="barangay_id" value="<?php echo (int)$scopedBarangayId; ?>">
        <?php else: ?>
        <label class="admin-field">
            <span>Assigned barangay<span class="admin-required">*</span></span>
                <select name="barangay_id" id="ff-barangay-select" required>
                    <option value="">-- Select Barangay --</option>
                    <?php foreach ($barangays as $barangay): ?>
                        <option value="<?php echo (int)$barangay['id']; ?>"><?php echo nutritionist_e($barangay['name']); ?></option>
                    <?php endforeach; ?>
                </select>
        </label>
        <?php endif; ?>

        <label class="admin-field">
            <span>Local area / Purok<span class="admin-required">*</span></span>
            <select name="local_area_id" id="ff-local-area-select" required data-scoped-barangay="<?php echo (int)$scopedBarangayId; ?>">
                <option value="">-- Select Local Area --</option>
            </select>
        </label>

        <details class="ff-details admin-field-wide">
            <summary><span class="ff-ico"><?php echo admin_sidebar_icon('key'); ?></span> Optional parent details</summary>
            <div class="ff-details-body nutritionist-form-grid" style="margin:0;">
                <?php if ($isScopedUser): ?>
                <p class="admin-mini admin-field-wide" style="color:var(--admin-muted);margin:0 0 4px;">Barangay: <strong><?php echo nutritionist_e($lockedBarangayName !== '' ? $lockedBarangayName : 'Your assigned barangay'); ?></strong> — locked sa scope mo.</p>
                <?php endif; ?>
                <div class="admin-field-wide">
                    <div class="admin-field-row">
                        <label class="admin-field">
                            <span>Password<span class="admin-required">*</span></span>
                            <span class="pw-wrap">
                                <input id="ff_password" type="password" name="password" required data-validate="password" autocomplete="new-password" value="<?php echo nutritionist_e(FAMILY_DEFAULT_PASSWORD); ?>">
                                <button type="button" class="pw-eye" data-pw-eye="ff_password" aria-label="Show password"><span class="eye-open"><?php echo admin_action_icon('view'); ?></span><span class="eye-off" hidden><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 0 0 1.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.45 10.45 0 0 1 12 4.5c4.756 0 8.773 3.162 10.065 7.498a10.523 10.523 0 0 1-4.293 5.774M6.228 6.228 3 3m3.228 3.228 3.65 3.65m7.894 7.894L21 21m-3.228-3.228-3.65-3.65m0 0a3 3 0 1 0-4.243-4.243m4.242 4.242L9.88 9.88" /></svg></span></button>
                            </span>
                            <span class="admin-field-message"></span>
                        </label>
                        <label class="admin-field">
                            <span>Confirm password<span class="admin-required">*</span></span>
                            <span class="pw-wrap">
                                <input id="ff_password_confirm" type="password" name="password_confirm" required data-validate="confirm-password" data-match="ff_password" autocomplete="new-password" value="<?php echo nutritionist_e(FAMILY_DEFAULT_PASSWORD); ?>">
                                <button type="button" class="pw-eye" data-pw-eye="ff_password_confirm" aria-label="Show password"><span class="eye-open"><?php echo admin_action_icon('view'); ?></span><span class="eye-off" hidden><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 0 0 1.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.45 10.45 0 0 1 12 4.5c4.756 0 8.773 3.162 10.065 7.498a10.523 10.523 0 0 1-4.293 5.774M6.228 6.228 3 3m3.228 3.228 3.65 3.65m7.894 7.894L21 21m-3.228-3.228-3.65-3.65m0 0a3 3 0 1 0-4.243-4.243m4.242 4.242L9.88 9.88" /></svg></span></button>
                            </span>
                            <span class="admin-field-message"></span>
                        </label>
                    </div>
                </div>

                <div class="admin-field-wide">
                    <div class="admin-field-row">
                <label class="admin-field">
                    <span>Email</span>
                    <input id="ff_email" type="email" name="email" data-validate="email" data-label="Email" placeholder="juan.delacruz@sukat.kalusugan" autocomplete="off">
                    <span class="admin-field-message"></span>
                </label>
                <label class="admin-field">
                    <span>Household / Spot</span>
                    <select name="household_id">
                        <option value="">-- None --</option>
                        <?php foreach ($households as $hh): ?>
                            <option value="<?php echo (int)$hh['id']; ?>">
                                <?php
                                echo nutritionist_e($hh['household_code']);
                                if ($isScopedUser) {
                                    if (!empty($hh['address'])) echo ' · ' . nutritionist_e($hh['address']);
                                } elseif (!empty($hh['barangay'])) {
                                    echo ' · ' . nutritionist_e($hh['barangay']);
                                }
                                ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                    </div>
                </div>
                <div class="admin-field-wide">
                    <span style="font-size:0.88rem;font-weight:700;color:var(--admin-text);">Home address</span>
                    <div class="admin-address-picker" data-psgc-picker data-psgc-address-target="ff_address">
                        <label class="admin-field">
                            <span>Province</span>
                            <select data-psgc="province"><option value="">Loading provinces…</option></select>
                        </label>
                        <label class="admin-field">
                            <span>City / Municipality</span>
                            <select data-psgc="city" disabled><option value="">-- Select province first --</option></select>
                        </label>
                        <label class="admin-field">
                            <span>Barangay</span>
                            <select data-psgc="barangay" disabled><option value="">-- Select city/municipality first --</option></select>
                        </label>
                    </div>
                    <label class="admin-field" style="margin-top:10px;">
                        <span>House no. / street</span>
                        <input data-psgc="street" placeholder="143 Purok 6" autocomplete="off">
                    </label>
                    <div class="admin-address-status" data-psgc-status></div>
                    <label class="admin-field" style="margin-top:10px;">
                        <span>Full address</span>
                        <textarea id="ff_address" name="address"></textarea>
                        <span class="admin-field-hint">Auto-filled from the picker above; you can still edit it directly.</span>
                    </label>
                </div>
            </div>
        </details>
        </div>
        <div class="ff-col">
            <h3 class="ff-col-title"><span class="ff-ico"><?php echo admin_sidebar_icon('children'); ?></span> Children</h3>
            <p class="ff-col-sub">Children inherit the parent's barangay, local area, household, and address.</p>
            <div id="ff-children-list"></div>
            <button type="button" class="ff-add-child" id="ff-add-child">Add another child</button>
        </div>
        </div>

        <div class="admin-field admin-field-wide" style="align-content:end;">
            <button class="admin-btn" type="submit"><?php echo admin_action_icon('save'); ?> Save family</button>
            <a class="admin-btn-secondary" href="<?php echo nutritionist_e(app_url('/nutritionist/parents.php')); ?>" style="margin-left:8px;"><?php echo admin_action_icon('cancel'); ?> Cancel</a>
        </div>
    </form>
</section>

<div class="admin-modal-overlay" id="ff-picker-overlay" hidden>
    <div class="admin-modal parent-picker-modal" role="dialog" aria-modal="true" aria-label="Select parent or guardian">
        <div class="admin-modal-head">
            <h3>Select Parent/Guardian</h3>
            <button type="button" class="admin-modal-close" id="ff-picker-close" aria-label="Close">&times;</button>
        </div>
        <div class="parent-picker-search">
            <input type="text" id="ff-picker-search" placeholder="Search by name..." autocomplete="off">
        </div>
        <div class="parent-picker-list" id="ff-picker-list"></div>
        <div class="parent-picker-footer">
            <button type="button" class="admin-btn-secondary parent-picker-page-btn" id="ff-picker-prev">&#8249; Prev</button>
            <span id="ff-picker-page">Page 1</span>
            <button type="button" class="admin-btn-secondary parent-picker-page-btn" id="ff-picker-next">Next &#8250;</button>
        </div>
    </div>
</div>

<template id="ff-child-template">
    <div class="ff-child" data-child-card>
        <div class="ff-child-head">
            <span class="t" data-child-title>Child #1</span>
            <span class="age" data-child-age></span>
            <button type="button" class="ff-child-remove" data-child-remove>Remove</button>
        </div>
        <div class="ff-child-body" style="margin:0;">
            <div class="admin-field-row">
            <label class="admin-field">
                <span>First name<span class="admin-required">*</span></span>
                <input name="children[__IDX__][first_name]" required maxlength="60" data-validate="name" data-label="Child first name" placeholder="Maria" autocomplete="off" data-c="first">
                <span class="admin-field-message"></span>
            </label>
            <label class="admin-field">
                <span>Middle name</span>
                <input name="children[__IDX__][middle_name]" maxlength="60" data-validate="name" data-label="Child middle name" placeholder="Santos" autocomplete="off" data-c="middle">
                <span class="admin-field-message"></span>
            </label>
            </div>
            <label class="admin-field">
                <span>Surname<span class="admin-required">*</span></span>
                <input name="children[__IDX__][last_name]" required maxlength="60" data-validate="name" data-label="Child surname" placeholder="Dela Cruz" autocomplete="off" data-c="last">
                <span class="admin-field-message"></span>
            </label>
            <div class="admin-field-row">
            <label class="admin-field">
                <span>Birthdate<span class="admin-required">*</span></span>
                <input type="date" name="children[__IDX__][birthdate]" required max="__TODAY__" data-c="birth">
                <span class="admin-field-message"></span>
            </label>
            <label class="admin-field">
                <span>Sex<span class="admin-required">*</span></span>
                <select name="children[__IDX__][sex]" required data-c="sex">
                    <option value="Male">Male</option>
                    <option value="Female">Female</option>
                </select>
            </label>
            </div>
        </div>
        <details class="ff-details">
            <summary><span class="ff-ico"><?php echo admin_sidebar_icon('children'); ?></span> Optional child details</summary>
            <div class="ff-details-body">
                <label class="admin-field admin-field-checkbox">
                    <input type="checkbox" name="children[__IDX__][is_ip]" value="1">
                    <span>Belongs to IP (Indigenous Peoples) group</span>
                </label>
                <label class="admin-field admin-field-checkbox" style="margin-top:8px;">
                    <input type="checkbox" name="children[__IDX__][has_disability]" value="1">
                    <span>Has a disability</span>
                </label>
            </div>
        </details>
    </div>
</template>

<script>
(function() {
    var TODAY = '<?php echo $today; ?>';
    var areaApi = '<?php echo app_url("/api/admin/local_areas.php"); ?>';
    var pickerApi = '<?php echo app_url("/api/nutritionist/parents_lookup.php"); ?>';

    var form = document.getElementById('ff-form');

    var list = document.getElementById('ff-children-list');
    var tmpl = document.getElementById('ff-child-template').innerHTML;
    var addBtn = document.getElementById('ff-add-child');
    var childSeq = 0;

    /* ---------- child cards ---------- */
    function renumber() {
        var cards = list.querySelectorAll('[data-child-card]');
        cards.forEach(function(card, i) {
            var n = i + 1;
            var title = card.querySelector('[data-child-title]');
            if (title) {
                var fn = card.querySelector('[data-c="first"]').value.trim();
                var lns = card.querySelectorAll('[data-c="last"]');
                var ln = lns.length > 0 ? lns[lns.length - 1].value.trim() : '';
                title.textContent = (fn !== '' || ln !== '') ? ('Child #' + n + ' · ' + (fn + ' ' + ln).trim()) : ('Child #' + n);
            }
            var rm = card.querySelector('[data-child-remove]');
            if (rm) rm.style.display = cards.length > 1 ? '' : 'none';
        });
    }

    function childAgeText(birthStr) {
        if (!birthStr) return '';
        var b = new Date(birthStr + 'T00:00:00');
        if (isNaN(b.getTime()) || b > new Date()) return '';
        var now = new Date();
        var months = (now.getFullYear() - b.getFullYear()) * 12 + (now.getMonth() - b.getMonth());
        if (now.getDate() < b.getDate()) months--;
        if (months < 0) return '';
        if (months < 24) return months + ' mo';
        var y = Math.floor(months / 12), m = months % 12;
        return y + 'y' + (m > 0 ? ' ' + m + 'm' : '');
    }

    function childOverFive(birthStr) {
        if (!birthStr) return false;
        var b = new Date(birthStr + 'T00:00:00');
        if (isNaN(b.getTime())) return false;
        var days = Math.floor((Date.now() - b.getTime()) / 86400000);
        return days > 1825;
    }

    function addChild() {
        var wrap = document.createElement('div');
        wrap.innerHTML = tmpl.split('__IDX__').join(String(childSeq++)).split('__TODAY__').join(TODAY);
        var card = wrap.firstElementChild;
        list.appendChild(card);
        card.querySelector('[data-child-remove]').addEventListener('click', function() {
            if (list.querySelectorAll('[data-child-card]').length > 1) {
                card.remove();
                renumber();
            }
        });
        card.querySelectorAll('input,select').forEach(function(el) {
            var update = function() {
                var ageEl = card.querySelector('[data-child-age]');
                var bd = card.querySelector('[data-c="birth"]').value;
                if (ageEl) {
                    var over = childOverFive(bd);
                    ageEl.textContent = childAgeText(bd) + (over ? ' — lampas 5yo' : '');
                    ageEl.style.color = over ? 'var(--admin-danger,#dc2626)' : '';
                    ageEl.style.fontWeight = over ? '800' : '';
                }
                renumber();
            };
            el.addEventListener('input', update);
            el.addEventListener('change', update);
        });
        renumber();
    }

    addBtn.addEventListener('click', addChild);
    addChild();

    /* ---------- password eye toggles (one per field) ---------- */
    document.querySelectorAll('[data-pw-eye]').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var input = document.getElementById(btn.getAttribute('data-pw-eye'));
            if (!input) return;
            var show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
            var open = btn.querySelector('.eye-open');
            var off = btn.querySelector('.eye-off');
            if (open) open.hidden = show;
            if (off) off.hidden = !show;
            if (show) input.focus();
        });
    });

    /* ---------- local areas for the parent barangay ---------- */
    var barangaySelect = document.getElementById('ff-barangay-select');
    var areaSelect = document.getElementById('ff-local-area-select');
    var scopedBarangayId = parseInt(areaSelect ? (areaSelect.getAttribute('data-scoped-barangay') || '0') : '0', 10);

    function loadAreas(barangayId) {
        if (!areaSelect) return;
        areaSelect.innerHTML = '<option value="">-- Select Local Area --</option>';
        if (!barangayId || barangayId <= 0) return;
        areaSelect.innerHTML += '<option value="" disabled>Loading...</option>';
        fetch(areaApi + '?barangay_id=' + barangayId)
            .then(function(r) { return r.json(); })
            .then(function(res) {
                areaSelect.innerHTML = '<option value="">-- Select Local Area --</option>';
                if (!res.success || !res.data || res.data.length === 0) {
                    areaSelect.innerHTML += '<option value="" disabled>No local areas registered</option>';
                    return;
                }
                res.data.forEach(function(area) {
                    if (parseInt(area.is_active, 10) !== 1) return;
                    var opt = document.createElement('option');
                    opt.value = area.id;
                    opt.textContent = area.area_type.charAt(0).toUpperCase() + area.area_type.slice(1) + ': ' + area.area_name;
                    areaSelect.appendChild(opt);
                });
            })
            .catch(function() {
                areaSelect.innerHTML = '<option value="">-- Select Local Area --</option><option value="" disabled>Failed to load</option>';
            });
    }
    if (barangaySelect) {
        barangaySelect.addEventListener('change', function() {
            loadAreas(parseInt(barangaySelect.value || '0', 10));
        });
    } else if (scopedBarangayId > 0) {
        loadAreas(scopedBarangayId);
    }

    /* ---------- mode toggle: new family vs existing parent ---------- */
    var modeNew = document.querySelector('input[name="mode"][value="new"]');
    var modeExisting = document.querySelector('input[name="mode"][value="existing"]');
    var parentCol = document.getElementById('ff-parent-col');
    var existingCard = document.getElementById('ff-existing-card');
    var colsWrap = document.getElementById('ff-cols');
    var parentInputs = parentCol ? parentCol.querySelectorAll('input,select,textarea') : [];

    function applyMode() {
        var isNew = !modeExisting || !modeExisting.checked;
        if (parentCol) parentCol.hidden = !isNew;
        if (existingCard) existingCard.hidden = isNew;
        if (colsWrap) colsWrap.classList.toggle('is-single', !isNew);
        parentInputs.forEach(function(el) {
            if (el.dataset.ffReq === undefined) el.dataset.ffReq = el.required ? '1' : '';
            el.disabled = !isNew;
            if ('required' in el) el.required = isNew && el.dataset.ffReq === '1';
        });
        if (!isNew && parentCol) {
            parentCol.querySelectorAll('.admin-field.is-invalid,.admin-field.is-valid').forEach(function(w) {
                w.classList.remove('is-invalid', 'is-valid');
            });
            var banner = form.querySelector('[data-validate-banner]');
            if (banner) banner.style.display = 'none';
        }
    }
    if (modeNew) modeNew.addEventListener('change', applyMode);
    if (modeExisting) modeExisting.addEventListener('change', applyMode);

    /* ---------- existing-parent picker: searchable, 5 per page ---------- */
    var parentInput = document.getElementById('ff-parent-id-input');
    var pickerBtn = document.getElementById('ff-parent-picker-btn');
    var pickerLabel = document.getElementById('ff-parent-picker-label');
    var overlay = document.getElementById('ff-picker-overlay');
    var closeBtn = document.getElementById('ff-picker-close');
    var searchInput = document.getElementById('ff-picker-search');
    var listEl = document.getElementById('ff-picker-list');
    var pageEl = document.getElementById('ff-picker-page');
    var prevBtn = document.getElementById('ff-picker-prev');
    var nextBtn = document.getElementById('ff-picker-next');
    var selectedParent = null;
    var pickerState = { q: '', page: 1, pages: 1 };
    var searchTimer = null;

    function esc(s) {
        var d = document.createElement('div');
        d.textContent = s == null ? '' : String(s);
        return d.innerHTML;
    }
    function initials(name) {
        return (name || '?').split(' ').map(function(w) { return w[0]; }).join('').substring(0, 2).toUpperCase();
    }
    function openPicker() {
        if (!overlay) return;
        pickerState.page = 1;
        renderPicker();
        overlay.removeAttribute('hidden');
        document.body.style.overflow = 'hidden';
        if (searchInput) searchInput.focus();
    }
    function closePicker() {
        if (!overlay) return;
        overlay.setAttribute('hidden', '');
        document.body.style.overflow = '';
    }
    function renderPicker() {
        listEl.innerHTML = '<div class="parent-picker-empty">Loading...</div>';
        var url = pickerApi + '?page=' + pickerState.page + '&page_size=5&q=' + encodeURIComponent(pickerState.q);
        fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
            .then(function(r) { return r.json(); })
            .then(function(res) {
                if (!res || !res.success) throw new Error((res && res.message) || 'Could not load parents.');
                var parents = res.data.parents || [];
                pickerState.page = res.data.page || 1;
                pickerState.pages = res.data.pages || 1;
                pageEl.textContent = 'Page ' + pickerState.page + ' of ' + pickerState.pages + ' (' + (res.data.total || 0) + ')';
                prevBtn.disabled = pickerState.page <= 1;
                nextBtn.disabled = pickerState.page >= pickerState.pages;
                if (parents.length === 0) {
                    listEl.innerHTML = '<div class="parent-picker-empty">No parents found</div>';
                    return;
                }
                listEl.innerHTML = '';
                parents.forEach(function(p) {
                    var item = document.createElement('button');
                    item.type = 'button';
                    item.className = 'parent-picker-item' + ((selectedParent && Number(selectedParent.id) === Number(p.id)) ? ' is-active' : '');
                    item.innerHTML = '<span class="parent-picker-avatar">' + esc(initials(p.name)) + '</span>'
                        + '<span class="parent-picker-item-info">'
                        + '<span class="parent-picker-item-name">' + esc(p.name) + '</span>'
                        + '<span class="parent-picker-item-meta">' + esc([p.parent_type, p.barangay].filter(Boolean).join(' · ')) + '</span>'
                        + '</span>';
                    item.addEventListener('click', function() { selectParent(p); });
                    listEl.appendChild(item);
                });
            })
            .catch(function(err) {
                listEl.innerHTML = '<div class="parent-picker-empty">Could not load parents. Please try again.</div>';
                if (window.AdminToast) AdminToast.error(err.message || 'Could not load parents.');
            });
    }
    function selectParent(p) {
        selectedParent = p;
        parentInput.value = p.id;
        pickerLabel.textContent = p.name;
        document.getElementById('ff-picked-wrap').hidden = false;
        document.getElementById('ff-picked-avatar').textContent = initials(p.name);
        document.getElementById('ff-picked-name').textContent = p.name;
        document.getElementById('ff-picked-meta').textContent = [p.parent_type, p.barangay].filter(Boolean).join(' · ');
        closePicker();
    }
    if (pickerBtn) pickerBtn.addEventListener('click', openPicker);
    if (closeBtn) closeBtn.addEventListener('click', closePicker);
    if (overlay) overlay.addEventListener('click', function(e) { if (e.target === overlay) closePicker(); });
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && overlay && !overlay.hasAttribute('hidden')) closePicker();
    });
    if (searchInput) searchInput.addEventListener('input', function() {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(function() {
            pickerState.q = searchInput.value;
            pickerState.page = 1;
            renderPicker();
        }, 300);
    });
    if (prevBtn) prevBtn.addEventListener('click', function() {
        if (pickerState.page > 1) { pickerState.page--; renderPicker(); }
    });
    if (nextBtn) nextBtn.addEventListener('click', function() {
        if (pickerState.page < pickerState.pages) { pickerState.page++; renderPicker(); }
    });

    /* ---------- submit guard: open accordions hiding invalid fields + existing-mode parent ---------- */
    form.addEventListener('submit', function(e) {
        // Required fields (e.g. password) live inside collapsed accordions.
        // Browsers can't focus into a closed <details>, so the submit looks
        // dead — open them first so errors become visible and fixable.
        form.querySelectorAll('details:not([open])').forEach(function(d) {
            try {
                if (d.querySelector(':invalid')) d.open = true;
            } catch (err) {}
        });
        var isNew = !modeExisting || !modeExisting.checked;
        if (!isNew && parentInput && (!parentInput.value || parseInt(parentInput.value, 10) <= 0)) {
            e.preventDefault();
            if (window.AdminToast) AdminToast.error('Pumili muna ng parent/guardian.');
            openPicker();
        }
    });

    applyMode();
})();
</script>

<?php
nutritionist_layout_end();
