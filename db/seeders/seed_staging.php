<?php
/**
 * db/seeders/seed_staging.php
 *
 * Idempotent TEST-DATA seeder for Codespaces staging (QA + pen-test).
 * Creates fake staff/parent/child rows only. Safe to re-run.
 *
 * NEVER run against production. NEVER import prod dumps here — staging must
 * never contain real parent/child health data.
 *
 * Usage (inside Codespaces, after tools/codespaces_setup.sh):
 *   php db/seeders/seed_staging.php
 *
 * Test logins (password: Stag1ng!Pass):
 *   staff admin:        staging_admin@test.local
 *   staff nutritionist: staging_nutri@test.local
 *   parent:             staging_parent@test.local
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

require_once __DIR__ . '/../../public_html/includes/db.php';

const STAGING_TEST_PASSWORD = 'Stag1ng!Pass';

$conn = get_db_connection();

function staging_first_id(mysqli $conn, string $sql): ?int
{
    $r = $conn->query($sql);
    if ($r === false) {
        fwrite(STDERR, "QUERY_FAIL: $sql :: {$conn->error}\n");
        exit(1);
    }
    $row = $r->fetch_assoc();
    if ($row === null) {
        return null;
    }
    return (int) reset($row);
}

$roleAdmin = staging_first_id($conn, "SELECT id FROM roles WHERE name='admin' LIMIT 1");
$roleNutri = staging_first_id($conn, "SELECT id FROM roles WHERE name='nutritionist' LIMIT 1");
$brgyId = staging_first_id($conn, "SELECT id FROM barangays LIMIT 1");

if ($roleAdmin === null || $roleNutri === null) {
    fwrite(STDERR, "Missing roles. Import db/schema.sql first.\n");
    exit(1);
}
if ($brgyId === null) {
    fwrite(STDERR, "Missing barangays. Import db/schema.sql first.\n");
    exit(1);
}

$hash = password_hash(STAGING_TEST_PASSWORD, PASSWORD_DEFAULT);

// Cleanup leftovers first so re-runs never duplicate (works with or without
// unique keys — same pattern as the local QA audit setup).
$conn->query("DELETE FROM measurements WHERE child_id IN (SELECT id FROM children WHERE child_code='STAGING-0001')");
$conn->query("DELETE FROM children WHERE child_code='STAGING-0001'");
$conn->query("DELETE FROM users WHERE email IN ('staging_admin@test.local','staging_nutri@test.local')");
$conn->query("DELETE FROM parents WHERE email='staging_parent@test.local'");

$stmt = $conn->prepare(
    "INSERT INTO users (name, email, username, password_hash, phone, role_id, barangay_id, status) VALUES (?,?,?,?,?,?,?, 'active')"
);
$seedUsers = [
    ['STAGING Admin', 'staging_admin@test.local', 'staging_admin', $roleAdmin],
    ['STAGING Nutritionist', 'staging_nutri@test.local', 'staging_nutri', $roleNutri],
];
foreach ($seedUsers as [$name, $email, $uname, $roleId]) {
    $phone = '09170000001';
    $stmt->bind_param('sssssii', $name, $email, $uname, $hash, $phone, $roleId, $brgyId);
    if (!$stmt->execute()) {
        fwrite(STDERR, "USER_FAIL $email :: {$stmt->error}\n");
        exit(1);
    }
    echo "created user=$email\n";
}
$stmt->close();

$stmt = $conn->prepare(
    "INSERT INTO parents (name, email, password_hash, parent_type, phone, address, barangay_id, status) VALUES (?,?,?,?,?,?,?, 'active')"
);
$pname = 'STAGING Parent';
$pemail = 'staging_parent@test.local';
$ptype = 'Mother';
$pphone = '09170000002';
$paddr = 'STAGING address (fake)';
if (!$stmt->bind_param('ssssssi', $pname, $pemail, $hash, $ptype, $pphone, $paddr, $brgyId) || !$stmt->execute()) {
    fwrite(STDERR, "PARENT_FAIL :: {$stmt->error}\n");
    exit(1);
}
echo "created parent=$pemail\n";
$stmt->close();

$parentId = staging_first_id($conn, "SELECT id FROM parents WHERE email='staging_parent@test.local' LIMIT 1");

$stmt = $conn->prepare(
    "INSERT INTO children (child_code, first_name, last_name, birthdate, sex, barangay_id, parent_id, status) VALUES (?,?,?,?,?,?,?, 'active')"
);
$code = 'STAGING-0001';
$fn = 'STAGING';
$ln = 'Child';
$bd = '2023-01-15';
$sex = 'Male';
if (!$stmt->bind_param('sssssii', $code, $fn, $ln, $bd, $sex, $brgyId, $parentId) || !$stmt->execute()) {
    fwrite(STDERR, "CHILD_FAIL :: {$stmt->error}\n");
    exit(1);
}
echo "created child=$code\n";
$stmt->close();

echo "SEED_OK password=" . STAGING_TEST_PASSWORD . " (test accounts only)\n";
